<?php
declare(strict_types=1);

/**
 * Garis tepi sungai dari SEMUA sumbu sungai yang sudah terbentuk.
 *
 *   php -d memory_limit=1024M tools/tepi-sungai-dari-sumbu.php           # uji kering
 *   php -d memory_limit=1024M tools/tepi-sungai-dari-sumbu.php --terapkan
 *
 *   --lebar=M      jarak maksimum sumbu ke tepi, bawaan 12,0 m
 *   --lebar-min=M  jarak minimum sumbu ke tepi, bawaan 0,4 m
 *   --sudut=D      beda arah maksimum tepi terhadap sumbu, bawaan 25 derajat
 *   --langkah=M    jarak titik sampel sumbu, bawaan 2,0 m
 *   --tanpa-persil matikan uji titik tengah di luar poligon bidang
 *   --keluar=NAMA  nama berkas keluaran tanpa .geojson, bawaan
 *                  garis-tepi-sungai-dari-sumbu
 *   --rinci        cetak rincian tiap sumbu
 *   --terapkan     tulis berkas keluaran dan perbarui metadata.json
 *
 * Latar. Sumbu sungai (garis-sungai-jahit.geojson, 27 polilin, 9.559,9 m)
 * belum punya daftar tepi sendiri: lapis Sungai memuat SEMUA tepi perairan
 * (143 rantai), termasuk alur yang tidak punya sumbu. Alat ini menjawab
 * pertanyaan sebaliknya dari tools/sumbu-dari-dua-tepi.php: dari sumbu yang
 * sudah jadi, tepi mana yang menyertainya.
 *
 * Bahan. Tepi diambil dari `garis-perairan.geojson` (143 rantai tepi alur hasil
 * tools/tepi-sungai.php, 15.010,9 m). Tiap LANGKAH meter sepanjang sumbu dicari
 * satu segmen tepi terdekat di kiri dan di kanan yang sejajar (beda arah <=
 * --sudut), berjarak --lebar-min sampai --lebar, dan titik tengah sumbu-tepi
 * TIDAK berada di dalam poligon bidang (kalau di dalam, tepi itu sisi seberang
 * sebidang tanah, bukan tepi alur). Poligon bidang dibaca dari tabel `bidang`
 * (status <> 'dihapus'), hanya baca.
 *
 * Keluaran menempel persis pada tepi asli: segmen yang diklaim dipotong menurut
 * klaim yang bersambung di sepanjang sumbu, jadi tiap baris memakai verteks
 * rantai tepi dan dua titik proyeksi sampel ujungnya. Tidak ada titik karangan
 * dan tidak ada jembatan antarrantai: tepi yang putus di mulut anak sungai
 * tetap putus. Baris di bawah --lebar-min 1,0 m dibuang. Satu sumbu bisa
 * menghasilkan beberapa baris per sisi, dan tepi yang sama bisa diklaim dua
 * sumbu bila keduanya memang menyusuri tepi itu.
 *
 * Keluaran satu berkas: `garis-tepi-sungai-dari-sumbu.geojson` (meter DXF
 * lokal), LineString dengan properti `kode` (TEPI-SUMBU-####), `cara`
 * (tepi-dari-sumbu), `sumbu` (kode sumbu asal), `sisi` (kiri/kanan),
 * `panjang_m`, `jarak_rata_m`, `jarak_min_m`, `jarak_maks_m`, `bahan` (kode
 * rantai tepi asal), dan `sampel` (jumlah sampel klaim). Metadata mendapat
 * entri GARIS TEPI SUNGAI DARI SUMBU.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_SUMBU = 'garis-sungai-jahit';
const BERKAS_BANK = 'garis-perairan';
const BERKAS_KELUAR = 'garis-tepi-sungai-dari-sumbu';
const JUDUL_KELUAR = 'GARIS TEPI SUNGAI DARI SUMBU';

const LANGKAH = 2.0;
const LEBAR = 12.0;
const LEBAR_MIN = 0.4;
const SUDUT = 25.0;
const MIN_PANJANG = 1.0;
const LANJUT_SEGMEN = 2;
const SEL = 25.0;
const SEL_KISI = 5.0;

$opts = getopt('', ['lebar::', 'lebar-min::', 'sudut::', 'langkah::', 'tanpa-persil',
    'keluar:', 'rinci', 'terapkan']);
$lebar = (float) ($opts['lebar'] ?? LEBAR);
$lebarMin = (float) ($opts['lebar-min'] ?? LEBAR_MIN);
$sudut = (float) ($opts['sudut'] ?? SUDUT);
$langkah = (float) ($opts['langkah'] ?? LANGKAH);
$tanpaPersil = isset($opts['tanpa-persil']);
$keluar = (string) ($opts['keluar'] ?? BERKAS_KELUAR);
$rinci = isset($opts['rinci']);
$terapkan = isset($opts['terapkan']);

if ($lebar <= $lebarMin || $lebarMin < 0 || $langkah <= 0 || $sudut <= 0 || $sudut > 90) {
    fwrite(STDERR, "galat: ambang tidak masuk akal\n");
    exit(1);
}

printf("mode: %s · koridor %s-%s m · sudut %s derajat · langkah %s m · persil %s\n",
    $terapkan ? 'TERAPKAN' : 'uji kering', angkaNum($lebarMin, 1), angkaNum($lebar, 1),
    angkaNum($sudut, 0), angkaNum($langkah, 1), $tanpaPersil ? 'tidak diuji' : 'diuji');

function angkaNum(float $n, int $desimal = 0): string
{
    return number_format($n, $desimal, ',', '.');
}

function panjangTitik(array $t): float
{
    $s = 0.0;
    for ($i = 1; $i < count($t); $i++) {
        $s += hypot($t[$i][0] - $t[$i - 1][0], $t[$i][1] - $t[$i - 1][1]);
    }
    return $s;
}

function bedaArah(float $a, float $b): float
{
    $beda = abs(fmod($a - $b, 180.0));
    return $beda > 90.0 ? 180.0 - $beda : $beda;
}

/** Proyeksi titik ke ruas: [jarak, qx, qy, t]. */
function proyeksi(array $p, array $s): array
{
    $vx = $s[2] - $s[0];
    $vy = $s[3] - $s[1];
    $wx = $p[0] - $s[0];
    $wy = $p[1] - $s[1];
    $L2 = $vx * $vx + $vy * $vy;
    $t = $L2 > 0 ? max(0.0, min(1.0, ($wx * $vx + $wy * $vy) / $L2)) : 0.0;
    $qx = $s[0] + $t * $vx;
    $qy = $s[1] + $t * $vy;
    return [hypot($p[0] - $qx, $p[1] - $qy), $qx, $qy, $t];
}

/** Cincin poligon dari geometri Polygon/MultiPolygon. */
function cincinDariGeometri(array $g): array
{
    $keluar = [];
    if (($g['type'] ?? '') === 'Polygon') {
        foreach ((array) ($g['coordinates'] ?? []) as $ring) {
            $ring = (array) $ring;
            if (count($ring) >= 4) {
                $keluar[] = $ring;
            }
        }
    } elseif (($g['type'] ?? '') === 'MultiPolygon') {
        foreach ((array) ($g['coordinates'] ?? []) as $poligon) {
            foreach ((array) $poligon as $ring) {
                $ring = (array) $ring;
                if (count($ring) >= 4) {
                    $keluar[] = $ring;
                }
            }
        }
    }
    return $keluar;
}

function tambahPoligon(array &$kisi, array $ring, float $sel): void
{
    $xMin = PHP_FLOAT_MAX;
    $xMaks = -PHP_FLOAT_MAX;
    $yMin = PHP_FLOAT_MAX;
    $yMaks = -PHP_FLOAT_MAX;
    foreach ($ring as $p) {
        $x = (float) $p[0];
        $y = (float) $p[1];
        $xMin = min($xMin, $x);
        $xMaks = max($xMaks, $x);
        $yMin = min($yMin, $y);
        $yMaks = max($yMaks, $y);
    }
    $id = count($kisi['_ring'] ?? []);
    $kisi['_ring'][$id] = $ring;
    for ($x = (int) floor($xMin / $sel); $x <= (int) floor($xMaks / $sel); $x++) {
        for ($y = (int) floor($yMin / $sel); $y <= (int) floor($yMaks / $sel); $y++) {
            $kisi[$x . ':' . $y][] = $id;
        }
    }
}

function dalamRing(float $x, float $y, array $ring): bool
{
    $n = count($ring);
    $dalam = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float) $ring[$i][0];
        $yi = (float) $ring[$i][1];
        $xj = (float) $ring[$j][0];
        $yj = (float) $ring[$j][1];
        if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
            $dalam = !$dalam;
        }
    }
    return $dalam;
}

function diPoligon(float $x, float $y, array $kisi, float $sel): bool
{
    $kunci = (int) floor($x / $sel) . ':' . (int) floor($y / $sel);
    foreach (($kisi[$kunci] ?? []) as $id) {
        if (dalamRing($x, $y, $kisi['_ring'][$id])) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Bahan: sumbu tersaji, rantai tepi perairan, dan poligon bidang
// ---------------------------------------------------------------------------

$sumbu = [];
foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_SUMBU . '.geojson') as $f) {
    $g = $f['geometry'] ?? [];
    if (($g['type'] ?? '') !== 'LineString') {
        continue;
    }
    $titik = [];
    foreach (($g['coordinates'] ?? []) as $p) {
        if (is_array($p) && count($p) >= 2) {
            $titik[] = [(float) $p[0], (float) $p[1]];
        }
    }
    if (count($titik) < 2) {
        continue;
    }
    $sumbu[] = [
        'kode' => (string) ($f['properties']['kode'] ?? ''),
        'titik' => $titik,
        'panjang' => panjangTitik($titik),
    ];
}
if ($sumbu === []) {
    fwrite(STDERR, "galat: sumbu kosong\n");
    exit(1);
}

$bank = [];
$segmen = [];
$kisiBank = [];
foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_BANK . '.geojson') as $f) {
    $g = $f['geometry'] ?? [];
    if (($g['type'] ?? '') !== 'LineString') {
        continue;
    }
    $titik = [];
    foreach (($g['coordinates'] ?? []) as $p) {
        if (is_array($p) && count($p) >= 2) {
            $titik[] = [(float) $p[0], (float) $p[1]];
        }
    }
    if (count($titik) < 2) {
        continue;
    }
    $id = count($bank);
    $bank[$id] = [
        'kode' => (string) ($f['properties']['kode'] ?? 'TEPI-' . $id),
        'titik' => $titik,
    ];
    for ($i = 1; $i < count($titik); $i++) {
        $x1 = $titik[$i - 1][0];
        $y1 = $titik[$i - 1][1];
        $x2 = $titik[$i][0];
        $y2 = $titik[$i][1];
        if (hypot($x2 - $x1, $y2 - $y1) <= 0.0) {
            continue;
        }
        $nama = count($segmen);
        $segmen[$nama] = [$x1, $y1, $x2, $y2, $id, $i - 1,
            rad2deg(atan2($y2 - $y1, $x2 - $x1))];
        for ($x = (int) floor(min($x1, $x2) / SEL_KISI); $x <= (int) floor(max($x1, $x2) / SEL_KISI); $x++) {
            for ($y = (int) floor(min($y1, $y2) / SEL_KISI); $y <= (int) floor(max($y1, $y2) / SEL_KISI); $y++) {
                $kisiBank[$x . ':' . $y][] = $nama;
            }
        }
    }
}
if ($segmen === []) {
    fwrite(STDERR, "galat: rantai tepi kosong\n");
    exit(1);
}

$kisiPersil = [];
$jumlahPersil = 0;
if (!$tanpaPersil) {
    foreach ((array) db_all('SELECT id, ST_AsGeoJSON(geom) AS geojson FROM bidang '
        . "WHERE status <> 'dihapus' AND geom IS NOT NULL ORDER BY id") as $baris) {
        $geometri = json_decode((string) $baris['geojson'], true);
        if (!is_array($geometri)) {
            continue;
        }
        foreach (cincinDariGeometri($geometri) as $ring) {
            $jumlahPersil++;
            tambahPoligon($kisiPersil, $ring, SEL_KISI);
        }
    }
    printf("persil dari basis data (dibaca saja): %d cincin\n", $jumlahPersil);
}

printf("sumbu: %d polilin, %s m · tepi: %d rantai, %s m, %d segmen\n",
    count($sumbu), angkaNum(array_sum(array_column($sumbu, 'panjang')), 1),
    count($bank), angkaNum(array_sum(array_map(static fn ($b) => panjangTitik($b['titik']), $bank)), 1),
    count($segmen));

// ---------------------------------------------------------------------------
// Klaim tepi kiri-kanan per sampel sumbu, lalu potong menurut klaim bersambung
// ---------------------------------------------------------------------------

/** Cari segmen tepi terdekat di sisi `sisi` (+1 kiri, -1 kanan). */
$cariKlaim = static function (float $px, float $py, float $dx, float $dy, int $sisi)
use ($segmen, $kisiBank, $kisiPersil, $lebar, $lebarMin, $sudut, $tanpaPersil, $langkah): ?array {
    $radius = $lebar + SEL_KISI;
    $pxMin = (int) floor(($px - $radius) / SEL_KISI);
    $pxMaks = (int) floor(($px + $radius) / SEL_KISI);
    $pyMin = (int) floor(($py - $radius) / SEL_KISI);
    $pyMaks = (int) floor(($py + $radius) / SEL_KISI);
    $terbaik = null;
    $jarakTerbaik = INF;
    $dilihat = [];
    for ($x = $pxMin; $x <= $pxMaks; $x++) {
        for ($y = $pyMin; $y <= $pyMaks; $y++) {
            foreach (($kisiBank[$x . ':' . $y] ?? []) as $id) {
                if (isset($dilihat[$id])) {
                    continue;
                }
                $dilihat[$id] = true;
                $s = $segmen[$id];
                [$jarak, $qx, $qy, $t] = proyeksi([$px, $py], $s);
                if ($jarak < $lebarMin || $jarak > $lebar || $jarak >= $jarakTerbaik) {
                    continue;
                }
                if (bedaArah((float) $s[6], rad2deg(atan2($dy, $dx))) > $sudut) {
                    continue;
                }
                $silang = $dx * ($qy - $py) - $dy * ($qx - $px);
                if (($silang > 0 ? 1 : -1) !== $sisi || abs($silang) < 1e-9) {
                    continue;
                }
                if (!$tanpaPersil && diPoligon(($px + $qx) / 2, ($py + $qy) / 2, $kisiPersil, SEL_KISI)) {
                    continue;
                }
                $jarakTerbaik = $jarak;
                $terbaik = ['seg' => $id, 'fid' => $s[4], 'idx' => $s[5], 't' => $t,
                    'qx' => $qx, 'qy' => $qy, 'jarak' => $jarak];
            }
        }
    }
    return $terbaik;
};

/**
 * Potong rantai tepi dari klaim awal (segA,tA) ke klaim akhir (segB,tB).
 * Arah potong mengikuti urutan indeks segmen pada rantai yang sama.
 */
$potong = static function (array $b, array $awal, array $akhir, array $qAwal, array $qAkhir): array {
    $titik = $b['titik'];
    $segA = $awal['idx'];
    $segB = $akhir['idx'];
    if ($segA === $segB) {
        return [$qAwal, $qAkhir];
    }
    $keluar = [$qAwal];
    if ($segA < $segB) {
        for ($i = $segA + 1; $i <= $segB; $i++) {
            $keluar[] = $titik[$i];
        }
    } else {
        for ($i = $segA; $i >= $segB + 1; $i--) {
            $keluar[] = $titik[$i];
        }
    }
    $keluar[] = $qAkhir;
    $rapat = [$keluar[0]];
    foreach (array_slice($keluar, 1) as $p) {
        $lalu = $rapat[count($rapat) - 1];
        if (hypot($p[0] - $lalu[0], $p[1] - $lalu[1]) > 1e-9) {
            $rapat[] = $p;
        }
    }
    return $rapat;
};

$daftar = [];
$stat = [];
$runsDibuang = 0;
$klaimTotal = 0;

foreach ($sumbu as $axis) {
    $kodeSumbu = $axis['kode'];
    $stat[$kodeSumbu] = ['panjang' => $axis['panjang'], 'sisi' => [], 'klaim' => 0];

    foreach (['kiri' => 1, 'kanan' => -1] as $namaSisi => $sisi) {
        $aktif = null;
        $tutup = static function (?array $aktif) use (&$daftar, &$stat, &$runsDibuang,
            $kodeSumbu, $namaSisi, $bank, $potong): void {
            if ($aktif === null) {
                return;
            }
            $panjang = $aktif['jarak'];
            if ($panjang < MIN_PANJANG) {
                $runsDibuang++;
                return;
            }
            $b = $bank[$aktif['fid']];
            $titik = $potong($b, $aktif['awal'], $aktif['akhir'], $aktif['qAwal'], $aktif['qAkhir']);
            $panjang = panjangTitik($titik);
            if ($panjang < MIN_PANJANG) {
                $runsDibuang++;
                return;
            }
            $daftar[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => $titik],
                'properties' => [
                    'kode' => '',
                    'cara' => 'tepi-dari-sumbu',
                    'sumbu' => $kodeSumbu,
                    'sisi' => $namaSisi,
                    'panjang_m' => round($panjang, 2),
                    'jarak_rata_m' => round($aktif['jarak'] / max(1, $aktif['klaim']), 2),
                    'jarak_min_m' => round($aktif['jarakMin'], 2),
                    'jarak_maks_m' => round($aktif['jarakMaks'], 2),
                    'bahan' => $b['kode'],
                    'sampel' => $aktif['klaim'],
                ],
            ];
            $stat[$kodeSumbu]['sisi'][$namaSisi] = ($stat[$kodeSumbu]['sisi'][$namaSisi] ?? 0.0) + $panjang;
        };

        $mulai = static function (array $klaim, int $nomorSampel): array {
            return [
                'fid' => $klaim['fid'],
                'awal' => $klaim,
                'akhir' => $klaim,
                'qAwal' => [$klaim['qx'], $klaim['qy']],
                'qAkhir' => [$klaim['qx'], $klaim['qy']],
                'jarak' => 0.0,
                'jarakMin' => $klaim['jarak'],
                'jarakMaks' => $klaim['jarak'],
                'klaim' => 1,
                'nomor' => $nomorSampel,
                'arah' => 0,
            ];
        };

        $nomor = 0;
        foreach ($axis['titik'] as $i => $awalTitik) {
            if ($i === 0) {
                continue;
            }
            $sebelum = $axis['titik'][$i - 1];
            $dx = $awalTitik[0] - $sebelum[0];
            $dy = $awalTitik[1] - $sebelum[1];
            $L = hypot($dx, $dy);
            if ($L <= 0.0) {
                continue;
            }
            $jml = max(1, (int) ceil($L / $langkah));
            for ($j = 0; $j < $jml; $j++) {
                $nomor++;
                $t = ($j + 0.5) / $jml;
                $px = $sebelum[0] + $dx * $t;
                $py = $sebelum[1] + $dy * $t;
                $klaim = $cariKlaim($px, $py, $dx, $dy, $sisi);
                if ($klaim === null) {
                    $tutup($aktif);
                    $aktif = null;
                    continue;
                }
                $klaimTotal++;
                $stat[$kodeSumbu]['klaim']++;
                if ($aktif === null) {
                    $aktif = $mulai($klaim, $nomor);
                    continue;
                }
                $bedaSeg = $klaim['idx'] - $aktif['akhir']['idx'];
                $lanjut = $klaim['fid'] === $aktif['fid']
                    && abs($bedaSeg) <= LANJUT_SEGMEN
                    && $nomor - $aktif['nomor'] <= 1;
                if ($lanjut && $bedaSeg !== 0) {
                    $arah = $bedaSeg > 0 ? 1 : -1;
                    if ($aktif['arah'] !== 0 && $aktif['arah'] !== $arah) {
                        $lanjut = false;
                    } else {
                        $aktif['arah'] = $arah;
                    }
                }
                if (!$lanjut) {
                    $tutup($aktif);
                    $aktif = $mulai($klaim, $nomor);
                    continue;
                }
                $aktif['akhir'] = $klaim;
                $aktif['qAkhir'] = [$klaim['qx'], $klaim['qy']];
                $aktif['jarak'] += $klaim['jarak'];
                $aktif['jarakMin'] = min($aktif['jarakMin'], $klaim['jarak']);
                $aktif['jarakMaks'] = max($aktif['jarakMaks'], $klaim['jarak']);
                $aktif['klaim']++;
                $aktif['nomor'] = $nomor;
            }
        }
        $tutup($aktif);
    }
}

$panjangTotal = 0.0;
$titikTotal = 0;
$perSumbu = [];
$perSisi = ['kiri' => [0, 0.0], 'kanan' => [0, 0.0]];
foreach ($daftar as $i => $f) {
    $daftar[$i]['properties']['kode'] = sprintf('TEPI-SUMBU-%04d', $i + 1);
    $panjangTotal += (float) $f['properties']['panjang_m'];
    $titikTotal += count($f['geometry']['coordinates']);
    $perSumbu[$f['properties']['sumbu']] = ($perSumbu[$f['properties']['sumbu']] ?? 0.0)
        + (float) $f['properties']['panjang_m'];
    $perSisi[$f['properties']['sisi']][0]++;
    $perSisi[$f['properties']['sisi']][1] += (float) $f['properties']['panjang_m'];
}

printf("hasil: %d baris tepi, %s m, %d titik (kiri %d baris/%s m, kanan %d baris/%s m), %d potongan pendek dibuang\n",
    count($daftar), angkaNum($panjangTotal, 1), $titikTotal,
    $perSisi['kiri'][0], angkaNum($perSisi['kiri'][1], 1),
    $perSisi['kanan'][0], angkaNum($perSisi['kanan'][1], 1), $runsDibuang);

$sumbuBertepi = 0;
$sumbuDuaSisi = 0;
foreach ($stat as $kode => $s) {
    if (($perSumbu[$kode] ?? 0) > 0) {
        $sumbuBertepi++;
    }
    if (($s['sisi']['kiri'] ?? 0) > 0 && ($s['sisi']['kanan'] ?? 0) > 0) {
        $sumbuDuaSisi++;
    }
}
printf("sumbu bertepi: %d dari %d (%d bertepi dua sisi)\n", $sumbuBertepi, count($sumbu), $sumbuDuaSisi);

$cakupan = [];
foreach ($stat as $kode => $s) {
    $dua = 0.0;
    if (($s['sisi']['kiri'] ?? 0) > 0 && ($s['sisi']['kanan'] ?? 0) > 0) {
        $dua = min($s['sisi']['kiri'], $s['sisi']['kanan']);
    }
    $cakupan[$kode] = $s['panjang'] > 0 ? 100 * $dua / $s['panjang'] : 0.0;
}
$urut = $cakupan;
arsort($urut);
foreach (array_slice($urut, 0, 25, true) as $kode => $persen) {
    printf("%-18s %8s m  tepi kiri %6s m  kanan %6s m  dua sisi setara %4.1f%%\n",
        $kode, angkaNum($stat[$kode]['panjang'], 1),
        angkaNum($stat[$kode]['sisi']['kiri'] ?? 0, 1),
        angkaNum($stat[$kode]['sisi']['kanan'] ?? 0, 1), $persen);
}
if ($rinci) {
    foreach ($daftar as $f) {
        printf("%s  %-18s %-5s %7s m  %s\n", $f['properties']['kode'], $f['properties']['sumbu'],
            $f['properties']['sisi'], angkaNum((float) $f['properties']['panjang_m'], 1),
            $f['properties']['bahan']);
    }
}

if (!$terapkan) {
    echo "uji kering: tidak ada berkas yang ditulis. Tambahkan --terapkan untuk menulis.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Tulis berkas dan metadata
// ---------------------------------------------------------------------------

$berkasKeluar = DIR_LAPISAN . '/' . $keluar . '.geojson';
$stempel = date('Ymd-His');
$cadang = __DIR__ . '/../_lama/cadangan-' . $keluar . '-' . $stempel;
if (is_file($berkasKeluar)) {
    if (!is_dir($cadang) && !mkdir($cadang, 0775, true) && !is_dir($cadang)) {
        fwrite(STDERR, "galat: gagal membuat folder cadangan\n");
        exit(1);
    }
    if (!copy($berkasKeluar, $cadang . '/' . basename($berkasKeluar))) {
        fwrite(STDERR, "galat: gagal mencadangkan berkas lama\n");
        exit(1);
    }
    echo 'cadangan berkas lama: _lama/' . basename($cadang) . "\n";
}

$naskah = json_encode(['type' => 'FeatureCollection', 'features' => $daftar],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($naskah === false || file_put_contents($berkasKeluar, $naskah) === false) {
    fwrite(STDERR, "galat: gagal menulis berkas keluaran\n");
    exit(1);
}

$berkasMeta = DIR_LAPISAN . '/metadata.json';
$meta = is_file($berkasMeta) ? (array) json_decode((string) file_get_contents($berkasMeta), true) : [];
if ($meta === []) {
    fwrite(STDERR, "galat: metadata.json tidak dapat dibaca\n");
    exit(1);
}
if (isset($cadang) && is_dir($cadang)) {
    copy($berkasMeta, $cadang . '/metadata-sebelum-' . $stempel . '.json');
}
$sumbuPanjang = array_sum(array_column($sumbu, 'panjang'));
$meta['layers'][JUDUL_KELUAR] = [
    'features' => count($daftar),
    'types' => ['line' => count($daftar)],
    'file' => $keluar . '.geojson',
    'panjang_m' => (int) round($panjangTotal),
    'derived_from' => 'tepi nyata garis-perairan.geojson (143 rantai/15.010,9 m) yang menyertai'
        . ' garis-sungai-jahit.geojson (27 polilin), dipotong menurut klaim sampel sumbu;'
        . ' tanpa titik karangan, tanpa jembatan antarrantai, dan tanpa tulisan ke basis data',
    'ambang' => ['jarak_min_m' => $lebarMin, 'jarak_maks_m' => $lebar,
        'sudut_derajat' => $sudut, 'langkah_m' => $langkah, 'min_panjang_m' => MIN_PANJANG,
        'lanjut_segmen' => LANJUT_SEGMEN, 'uji_persil' => !$tanpaPersil],
    'sumbu' => ['file' => BERKAS_SUMBU . '.geojson', 'polilin' => count($sumbu),
        'panjang_m' => round($sumbuPanjang, 1), 'bertepi' => $sumbuBertepi,
        'dua_sisi' => $sumbuDuaSisi],
    'bahan' => ['file' => BERKAS_BANK . '.geojson', 'rantai' => count($bank),
        'panjang_m' => round(array_sum(array_map(static fn ($b) => panjangTitik($b['titik']), $bank)), 1)],
    'permukaan_bidang' => ['sumber' => 'tabel bidang (status <> dihapus)', 'cincin' => $jumlahPersil],
];
$meta['derived_at'] = date('c');
if (file_put_contents($berkasMeta, json_encode($meta, JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) {
    fwrite(STDERR, "galat: gagal menulis metadata.json\n");
    exit(1);
}
echo 'ditulis: files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . $keluar . ".geojson\n";
echo "metadata.json diperbarui: " . JUDUL_KELUAR . "\n";
