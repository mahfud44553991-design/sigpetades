<?php
declare(strict_types=1);

/**
 * Garis tepi sungai dari SEMUA sumbu sungai yang sudah terbentuk.
 *
 *   php -d memory_limit=1024M tools/tepi-sungai-dari-sumbu.php           # uji kering
 *   php -d memory_limit=1024M tools/tepi-sungai-dari-sumbu.php --terapkan
 *
 *   --lebar=M      jarak maksimum sumbu ke tepi, bawaan 15,0 m
 *   --lebar-min=M  jarak minimum sumbu ke tepi, bawaan 0,4 m
 *   --sudut=D      beda arah maksimum tepi terhadap sumbu, bawaan 30 derajat
 *   --geser=M      toleransi s mundur sebelum potongan diputus, bawaan 1,0 m
 *   --min=M        panjang minimum satu baris tepi, bawaan 1,0 m
 *   --tanpa-persil matikan uji titik tengah di luar poligon bidang
 *   --keluar=NAMA  nama berkas keluaran tanpa .geojson, bawaan
 *                  garis-tepi-sungai-dari-sumbu
 *   --rinci        cetak rincian tiap baris
 *   --terapkan     tulis berkas keluaran dan perbarui metadata.json
 *
 * Latar. Sumbu sungai (garis-sungai-jahit.geojson, 27 polilin, 9.559,9 m)
 * belum punya daftar tepi sendiri: lapis Sungai memuat SEMUA tepi perairan
 * (143 rantai), termasuk alur yang tidak punya sumbu. Alat ini menjawab
 * pertanyaan sebaliknya dari tools/sumbu-dari-dua-tepi.php: dari sumbu yang
 * sudah jadi, tepi mana yang menyertainya.
 *
 * Bahan. Tepi diambil dari `garis-perairan.geojson` (143 rantai tepi alur hasil
 * tools/tepi-sungai.php, 15.010,9 m). Tiap SEGMEN rantai tepi dipasangkan ke
 * sumbu TERDEKAT di titik tengahnya; segmen dipakai bila jaraknya --lebar-min
 * sampai --lebar, beda arahnya <= --sudut, sisinya (kiri/kanan) tetap terhadap
 * sumbu itu, dan titik tengah antara segmen tepi dan proyeksinya di sumbu TIDAK
 * berada di dalam poligon bidang (kalau di dalam, tepi itu sisi seberang
 * sebidang tanah, bukan tepi alur). Poligon bidang dibaca dari tabel `bidang`
 * (status <> 'dihapus'), hanya baca.
 *
 * Kenapa per segmen tepi, bukan per sampel sumbu. Versi lama menyampel sumbu tiap
 * 2 m lalu mencari tepi terdekat, kemudian menempelkan titik proyeksi sampel di
 * ujung potongan. Akibatnya ujung baris tidak berada di verteks tepi, potongan
 * kehilangan ruas di antara sampel, dan baris memanjang melewati ruas tepi yang
 * arahnya menyimpang. Versi ini memakai ruas tepi yang memang ada: satu baris =
 * rentetan segmen tepi yang bersambung pada sumbu dan sisi yang sama, dengan
 * posisi s (jarak sepanjang sumbu) tidak berbalik arah. Semua verteks keluaran
 * adalah verteks rantai tepi asli; tidak ada titik proyeksi, tidak ada titik
 * karangan, dan tidak ada jembatan antarrantai.
 *
 * Kerapian. Baris tidak bisa menjadi cincin karena s tidak boleh berbalik; bila
 * satu baris memotong dirinya sendiri, lekuk tertutupnya dibuang sehingga yang
 * tinggal dua potongan terbuka. Baris di bawah --min dibuang, dan berkas tidak
 * ditulis bila pemeriksaan akhir menemukan cincin, sisi berbalik, perpotongan
 * diri, atau segmen menyimpang lebih dari --sudut.
 *
 * Keluaran satu berkas: `garis-tepi-sungai-dari-sumbu.geojson` (meter DXF
 * lokal), LineString dengan properti `kode` (TEPI-SUMBU-####), `cara`
 * (tepi-dari-sumbu), `sumbu` (kode sumbu asal), `sisi` (kiri/kanan),
 * `panjang_m`, `jarak_rata_m`, `jarak_min_m`, `jarak_maks_m`, `bahan` (kode
 * rantai tepi asal), `titik`, dan `segmen`. Metadata mendapat entri
 * GARIS TEPI SUNGAI DARI SUMBU.
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

const LEBAR = 15.0;
const LEBAR_MIN = 0.4;
const SUDUT = 30.0;
const GESER = 1.0;
const MIN_PANJANG = 1.0;
const SEL_KISI = 5.0;

$opts = getopt('', ['lebar::', 'lebar-min::', 'sudut::', 'geser::', 'min::', 'tanpa-persil',
    'keluar:', 'rinci', 'terapkan']);
$lebar = (float) ($opts['lebar'] ?? LEBAR);
$lebarMin = (float) ($opts['lebar-min'] ?? LEBAR_MIN);
$sudut = (float) ($opts['sudut'] ?? SUDUT);
$geser = (float) ($opts['geser'] ?? GESER);
$minPanjang = (float) ($opts['min'] ?? MIN_PANJANG);
$tanpaPersil = isset($opts['tanpa-persil']);
$keluar = (string) ($opts['keluar'] ?? BERKAS_KELUAR);
$rinci = isset($opts['rinci']);
$terapkan = isset($opts['terapkan']);

if ($lebar <= $lebarMin || $lebarMin < 0 || $sudut <= 0 || $sudut > 90
    || $geser < 0 || $minPanjang <= 0) {
    fwrite(STDERR, "galat: ambang tidak masuk akal\n");
    exit(1);
}

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

/** Titik potong dua ruas terbuka; null bila sejajar, berimpit, atau di ujung. */
function persilangan(array $a, array $b, array $c, array $d): ?array
{
    $rx = $b[0] - $a[0];
    $ry = $b[1] - $a[1];
    $sx = $d[0] - $c[0];
    $sy = $d[1] - $c[1];
    $penyebut = $rx * $sy - $ry * $sx;
    if (abs($penyebut) < 1e-12) {
        return null;
    }
    $t = (($c[0] - $a[0]) * $sy - ($c[1] - $a[1]) * $sx) / $penyebut;
    $u = (($c[0] - $a[0]) * $ry - ($c[1] - $a[1]) * $rx) / $penyebut;
    if ($t <= 1e-9 || $t >= 1.0 - 1e-9 || $u <= 1e-9 || $u >= 1.0 - 1e-9) {
        return null;
    }
    return [$a[0] + $t * $rx, $a[1] + $t * $ry];
}

/**
 * Buang lekuk tertutup dari baris yang memotong dirinya sendiri.
 * Titik potong adalah titik nyata perpotongan dua ruas tepi, bukan titik karangan.
 * @return array{0: list<array>, 1: float} potongan terbuka + panjang yang dibuang
 */
function buangSilang(array $p, int $putaran = 0): array
{
    $n = count($p);
    if ($n < 4 || $putaran >= 8) {
        return [[$p], 0.0];
    }
    for ($i = 1; $i < $n - 1; $i++) {
        for ($j = $i + 2; $j < $n; $j++) {
            $x = persilangan($p[$i - 1], $p[$i], $p[$j - 1], $p[$j]);
            if ($x === null) {
                continue;
            }
            $kiri = array_slice($p, 0, $i);
            $kiri[] = $x;
            $kanan = [$x];
            for ($k = $j; $k < $n; $k++) {
                $kanan[] = $p[$k];
            }
            $keluar = [];
            $hilang = max(0.0, panjangTitik($p) - panjangTitik($kiri) - panjangTitik($kanan));
            foreach ([$kiri, $kanan] as $bagian) {
                [$potongan, $buang] = buangSilang($bagian, $putaran + 1);
                foreach ($potongan as $potong) {
                    $keluar[] = $potong;
                }
                $hilang += $buang;
            }
            return [$keluar, $hilang];
        }
    }
    return [[$p], 0.0];
}

/** Rapatkan verteks berimpit berurutan. */
function rapatkan(array $p): array
{
    $keluar = [$p[0]];
    for ($i = 1; $i < count($p); $i++) {
        $lalu = $keluar[count($keluar) - 1];
        if (hypot($p[$i][0] - $lalu[0], $p[$i][1] - $lalu[1]) > 1e-9) {
            $keluar[] = $p[$i];
        }
    }
    return $keluar;
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
// Bahan: sumbu, rantai tepi, dan poligon bidang
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
    $s = [0.0];
    for ($i = 1; $i < count($titik); $i++) {
        $s[$i] = $s[$i - 1] + hypot($titik[$i][0] - $titik[$i - 1][0],
            $titik[$i][1] - $titik[$i - 1][1]);
    }
    $sumbu[(string) ($f['properties']['kode'] ?? '')] = [
        'titik' => $titik,
        's' => $s,
        'panjang' => $s[count($s) - 1],
    ];
}
if ($sumbu === []) {
    fwrite(STDERR, "galat: sumbu kosong\n");
    exit(1);
}

$bank = [];
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
    $bank[] = [
        'kode' => (string) ($f['properties']['kode'] ?? 'TEPI-' . count($bank)),
        'titik' => $titik,
        'panjang' => panjangTitik($titik),
    ];
}
if ($bank === []) {
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
}

$panjangSumbu = array_sum(array_column($sumbu, 'panjang'));
$panjangBank = array_sum(array_column($bank, 'panjang'));
$jumlahSegmen = 0;
foreach ($bank as $b) {
    $jumlahSegmen += count($b['titik']) - 1;
}

printf("mode: %s · koridor %s-%s m · sudut %s derajat · geser %s m · min %s m · persil %s\n",
    $terapkan ? 'TERAPKAN' : 'uji kering', angkaNum($lebarMin, 1), angkaNum($lebar, 1),
    angkaNum($sudut, 0), angkaNum($geser, 1), angkaNum($minPanjang, 1),
    $tanpaPersil ? 'tidak diuji' : 'diuji');
if (!$tanpaPersil) {
    printf("persil dari basis data (dibaca saja): %d cincin\n", $jumlahPersil);
}
printf("sumbu: %d polilin, %s m · tepi: %d rantai, %s m, %d segmen\n",
    count($sumbu), angkaNum($panjangSumbu, 1), count($bank), angkaNum($panjangBank, 1),
    $jumlahSegmen);

// ---------------------------------------------------------------------------
// Pasangkan tiap segmen tepi ke sumbu terdekat, lalu susun potongan bersambung
// ---------------------------------------------------------------------------

/** Sumbu terdekat: [jarak, kode, s, arah, sisi, qx, qy]. */
$dekatSumbu = static function (float $x, float $y) use ($sumbu): array {
    $terbaik = [INF, '', 0.0, 0.0, 1, 0.0, 0.0];
    foreach ($sumbu as $kode => $axis) {
        $p = $axis['titik'];
        for ($j = 1; $j < count($p); $j++) {
            $ruas = [$p[$j - 1][0], $p[$j - 1][1], $p[$j][0], $p[$j][1]];
            [$d, $qx, $qy, $t] = proyeksi([$x, $y], $ruas);
            if ($d >= $terbaik[0]) {
                continue;
            }
            $ax = $p[$j][0] - $p[$j - 1][0];
            $ay = $p[$j][1] - $p[$j - 1][1];
            $sisi = $ax * ($y - $p[$j - 1][1]) - $ay * ($x - $p[$j - 1][0]) > 0 ? 1 : -1;
            $terbaik = [$d, $kode, $axis['s'][$j - 1] + $t * hypot($ax, $ay),
                rad2deg(atan2($ay, $ax)), $sisi, $qx, $qy];
        }
    }
    return $terbaik;
};

$daftar = [];
$stat = [];
$tolak = ['jauh' => 0.0, 'dekat' => 0.0, 'sudut' => 0.0, 'persil' => 0.0, 'mundur' => 0.0,
    'pindah' => 0.0];
$dibuangPanjang = 0.0;
$dibuangBaris = 0;
$dibuangSilang = 0.0;
$potonganSilang = 0;
$terpakai = 0.0;
$dekatSumbuPanjang = 0.0;
$rapatSumbuPanjang = 0.0;

foreach ($bank as $b) {
    $p = $b['titik'];
    $n = count($p);
    $mulai = null;
    $kunci = null;
    $sLalu = null;
    $arahS = 0;
    $runPanjang = 0.0;
    $runJumlah = 0.0;
    $runMin = INF;
    $runMaks = 0.0;
    $runSumbu = '';
    $runSisi = '';

    $tutup = static function (int $awal, int $akhir) use (&$daftar, &$dibuangPanjang,
        &$dibuangBaris, &$terpakai, $p, $b, &$runSumbu, &$runSisi, &$runPanjang, &$runJumlah,
        &$runMin, &$runMaks, $minPanjang): void {
        $bagian = rapatkan(array_slice($p, $awal, $akhir - $awal + 1));
        if (count($bagian) < 2) {
            return;
        }
        $panjang = panjangTitik($bagian);
        if ($panjang < $minPanjang) {
            $dibuangPanjang += $panjang;
            $dibuangBaris++;
            return;
        }
        $daftar[] = [
            'titik' => $bagian,
            'sumbu' => $runSumbu,
            'sisi' => $runSisi,
            'bahan' => $b['kode'],
            'panjang' => $panjang,
            'jarakJumlah' => $runJumlah,
            'jarakPanjang' => $runPanjang,
            'jarakMin' => $runMin === INF ? 0.0 : $runMin,
            'jarakMaks' => $runMaks,
        ];
        $terpakai += $panjang;
    };

    for ($i = 1; $i < $n; $i++) {
        $len = hypot($p[$i][0] - $p[$i - 1][0], $p[$i][1] - $p[$i - 1][1]);
        if ($len <= 0.0) {
            continue;
        }
        $mx = ($p[$i][0] + $p[$i - 1][0]) / 2;
        $my = ($p[$i][1] + $p[$i - 1][1]) / 2;
        [$d, $kodeSumbu, $s, $arahSumbu, $sisi, $qx, $qy] = $dekatSumbu($mx, $my);
        if ($d > $lebar) {
            $sebab = 'jauh';
        } elseif ($d < $lebarMin) {
            $rapatSumbuPanjang += $len;
            $sebab = 'dekat';
        } else {
            $dekatSumbuPanjang += $len;
            $sebab = '';
            $arahSegmen = rad2deg(atan2($p[$i][1] - $p[$i - 1][1], $p[$i][0] - $p[$i - 1][0]));
            if (bedaArah($arahSegmen, $arahSumbu) > $sudut) {
                $sebab = 'sudut';
            } elseif (!$tanpaPersil
                && diPoligon(($mx + $qx) / 2, ($my + $qy) / 2, $kisiPersil, SEL_KISI)) {
                $sebab = 'persil';
            }
        }
        $namaSisi = $sisi > 0 ? 'kiri' : 'kanan';
        $kunciSegmen = $kodeSumbu . ':' . $namaSisi;
        if ($sebab === '' && $mulai !== null) {
            if ($kunciSegmen !== $kunci) {
                $sebab = 'pindah';
            } else {
                $ds = $s - $sLalu;
                if (abs($ds) > $geser) {
                    $tanda = $ds > 0 ? 1 : -1;
                    if ($arahS !== 0 && $tanda !== $arahS) {
                        $sebab = 'mundur';
                    }
                }
            }
        }
        if ($sebab !== '') {
            $tolak[$sebab] += $len;
            if ($mulai !== null) {
                $tutup($mulai, $i - 1);
            }
            $mulai = null;
            $kunci = null;
            $sLalu = null;
            $arahS = 0;
            continue;
        }
        if ($mulai === null) {
            $mulai = $i - 1;
            $kunci = $kunciSegmen;
            $sLalu = $s;
            $arahS = 0;
            $runPanjang = 0.0;
            $runJumlah = 0.0;
            $runMin = INF;
            $runMaks = 0.0;
                    $runSumbu = $kodeSumbu;
            $runSisi = $namaSisi;
        } else {
            $ds = $s - $sLalu;
            if (abs($ds) > $geser && $arahS === 0) {
                $arahS = $ds > 0 ? 1 : -1;
            }
            $sLalu = $s;
        }
        $runPanjang += $len;
        $runJumlah += $d * $len;
        $runMin = min($runMin, $d);
        $runMaks = max($runMaks, $d);
    }
    if ($mulai !== null) {
        $tutup($mulai, $n - 1);
    }
}

// Potong baris yang memotong dirinya sendiri; lekuk tertutupnya dibuang.
$akhir = [];
foreach ($daftar as $row) {
    [$potong, $hilang] = buangSilang($row['titik']);
    $dibuangSilang += $hilang;
    foreach ($potong as $bagian) {
        $bagian = rapatkan($bagian);
        if (count($bagian) < 2) {
            continue;
        }
        $panjang = panjangTitik($bagian);
        if ($panjang < $minPanjang) {
            $dibuangPanjang += $panjang;
            $dibuangBaris++;
            continue;
        }
        if (count($potong) > 1) {
            $potonganSilang++;
        }
        $salin = $row;
        $salin['titik'] = $bagian;
        $salin['panjang'] = $panjang;
        $akhir[] = $salin;
    }
}
$terpakai = array_sum(array_column($akhir, 'panjang'));

/** Jarak titik ke seluruh polilin sumbu. */
$jarakKeSumbu = static function (float $x, float $y, array $p): float {
    $terbaik = INF;
    for ($j = 1; $j < count($p); $j++) {
        [$d] = proyeksi([$x, $y], [$p[$j - 1][0], $p[$j - 1][1], $p[$j][0], $p[$j][1]]);
        $terbaik = min($terbaik, $d);
    }
    return $terbaik;
};

$cincin = 0;
$silang = 0;
$sisiBerbalik = 0;
$menyimpang = 0;
$panjangTotal = 0.0;
$titikTotal = 0;
$perSumbu = [];
$perSisi = ['kiri' => [0, 0.0], 'kanan' => [0, 0.0]];
$fitur = [];
foreach ($akhir as $row) {
    $p = $row['titik'];
    $kode = $row['sumbu'];
    $axis = $sumbu[$kode] ?? null;
    if ($axis === null) {
        continue;
    }
    $n = count($p);
    if (hypot($p[0][0] - $p[$n - 1][0], $p[0][1] - $p[$n - 1][1]) <= 1.0) {
        $cincin++;
    }
    $jarakJumlah = 0.0;
    $panjangJumlah = 0.0;
    $jarakMin = INF;
    $jarakMaks = 0.0;
    for ($i = 1; $i < $n; $i++) {
        $len = hypot($p[$i][0] - $p[$i - 1][0], $p[$i][1] - $p[$i - 1][1]);
        if ($len <= 0.0) {
            continue;
        }
        $mx = ($p[$i][0] + $p[$i - 1][0]) / 2;
        $my = ($p[$i][1] + $p[$i - 1][1]) / 2;
        $d = $jarakKeSumbu($mx, $my, $axis['titik']);
        $jarakJumlah += $d * $len;
        $panjangJumlah += $len;
        $jarakMin = min($jarakMin, $d);
        $jarakMaks = max($jarakMaks, $d);
        $arahSegmen = rad2deg(atan2($p[$i][1] - $p[$i - 1][1], $p[$i][0] - $p[$i - 1][0]));
        $arahSumbu = 0.0;
        $sisi = 1;
        $terbaik = INF;
        for ($j = 1; $j < count($axis['titik']); $j++) {
            $ap = $axis['titik'];
            [$dd] = proyeksi([$mx, $my],
                [$ap[$j - 1][0], $ap[$j - 1][1], $ap[$j][0], $ap[$j][1]]);
            if ($dd < $terbaik) {
                $terbaik = $dd;
                $ax = $ap[$j][0] - $ap[$j - 1][0];
                $ay = $ap[$j][1] - $ap[$j - 1][1];
                $sisi = $ax * ($my - $ap[$j - 1][1]) - $ay * ($mx - $ap[$j - 1][0]) > 0 ? 1 : -1;
                $arahSumbu = rad2deg(atan2($ay, $ax));
            }
        }
        if (($sisi > 0 ? 'kiri' : 'kanan') !== $row['sisi']) {
            $sisiBerbalik++;
        }
        if (bedaArah($arahSegmen, $arahSumbu) > $sudut) {
            $menyimpang++;
        }
    }
    for ($i = 1; $i < $n; $i++) {
        for ($j = $i + 2; $j < $n; $j++) {
            if (persilangan($p[$i - 1], $p[$i], $p[$j - 1], $p[$j]) !== null) {
                $silang++;
            }
        }
    }
    $fitur[] = [
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => $p],
        'properties' => [
            'kode' => '',
            'cara' => 'tepi-dari-sumbu',
            'sumbu' => $kode,
            'sisi' => $row['sisi'],
            'panjang_m' => round($row['panjang'], 2),
            'jarak_rata_m' => round($panjangJumlah > 0 ? $jarakJumlah / $panjangJumlah : 0, 2),
            'jarak_min_m' => round($jarakMin === INF ? 0 : $jarakMin, 2),
            'jarak_maks_m' => round($jarakMaks, 2),
            'bahan' => $row['bahan'],
            'titik' => $n,
            'segmen' => $n - 1,
        ],
    ];
    $panjangTotal += $row['panjang'];
    $titikTotal += $n;
    $perSumbu[$kode] = ($perSumbu[$kode] ?? 0.0) + $row['panjang'];
    $perSisi[$row['sisi']][0]++;
    $perSisi[$row['sisi']][1] += $row['panjang'];
    $stat[$kode]['sisi'][$row['sisi']] = ($stat[$kode]['sisi'][$row['sisi']] ?? 0.0)
        + $row['panjang'];
}
foreach ($fitur as $i => $f) {
    $fitur[$i]['properties']['kode'] = sprintf('TEPI-SUMBU-%04d', $i + 1);
}
foreach ($sumbu as $kode => $axis) {
    $stat[$kode]['panjang'] = $axis['panjang'];
    $stat[$kode]['sisi'] = $stat[$kode]['sisi'] ?? [];
}

printf("hasil: %d baris · %s m · %d titik (kiri %d baris/%s m, kanan %d baris/%s m)\n",
    count($fitur), angkaNum($panjangTotal, 1), $titikTotal,
    $perSisi['kiri'][0], angkaNum($perSisi['kiri'][1], 1),
    $perSisi['kanan'][0], angkaNum($perSisi['kanan'][1], 1));
printf("bahan terpakai: %s m dari %s m (%.1f%%)\n", angkaNum($terpakai, 1),
    angkaNum($panjangBank, 1), 100 * $terpakai / max(0.001, $panjangBank));
printf("koridor: %s m di dalam %s-%s m · sisa dalam koridor %s m (sudut %s · persil %s"
    . " · mundur %s · pindah %s)\n",
    angkaNum($dekatSumbuPanjang, 1), angkaNum($lebarMin, 1), angkaNum($lebar, 1),
    angkaNum(max(0.0, $dekatSumbuPanjang - $terpakai), 1),
    angkaNum($tolak['sudut'], 1), angkaNum($tolak['persil'], 1),
    angkaNum($tolak['mundur'], 1), angkaNum($tolak['pindah'], 1));
printf("di luar koridor: %s m (lebih dari %s m dari sumbu) · menempel sumbu < %s m: %s m\n",
    angkaNum($tolak['jauh'], 1), angkaNum($lebar, 1), angkaNum($lebarMin, 1),
    angkaNum($rapatSumbuPanjang, 1));
printf("rapi: cincin %d · silang diri %d · sisi berbalik %d · segmen menyimpang >%s derajat %d\n",
    $cincin, $silang, $sisiBerbalik, angkaNum($sudut, 0), $menyimpang);
printf("dibuang: %d potongan pendek (%s m) · lekuk silang %s m (%d baris terbelah)\n",
    $dibuangBaris, angkaNum($dibuangPanjang, 1), angkaNum($dibuangSilang, 1), $potonganSilang);

$sumbuBertepi = 0;
$sumbuDuaSisi = 0;
$cakupan = [];
foreach ($stat as $kode => $s) {
    $dua = 0.0;
    if (($s['sisi']['kiri'] ?? 0) > 0 && ($s['sisi']['kanan'] ?? 0) > 0) {
        $dua = min($s['sisi']['kiri'], $s['sisi']['kanan']);
    }
    if (($perSumbu[$kode] ?? 0) > 0) {
        $sumbuBertepi++;
    }
    if ($dua > 0) {
        $sumbuDuaSisi++;
    }
    $cakupan[$kode] = $s['panjang'] > 0 ? 100 * $dua / $s['panjang'] : 0.0;
}
printf("sumbu bertepi: %d dari %d (%d bertepi dua sisi)\n", $sumbuBertepi, count($sumbu),
    $sumbuDuaSisi);

$urut = $cakupan;
arsort($urut);
foreach (array_slice($urut, 0, 25, true) as $kode => $persen) {
    printf("%-18s %8s m  tepi kiri %6s m  kanan %6s m  dua sisi setara %4.1f%%\n",
        $kode, angkaNum($stat[$kode]['panjang'], 1),
        angkaNum($stat[$kode]['sisi']['kiri'] ?? 0, 1),
        angkaNum($stat[$kode]['sisi']['kanan'] ?? 0, 1), $persen);
}
if ($rinci) {
    foreach ($fitur as $f) {
        printf("%s  %-18s %-5s %7s m · %2d titik · %s\n", $f['properties']['kode'],
            $f['properties']['sumbu'], $f['properties']['sisi'],
            angkaNum((float) $f['properties']['panjang_m'], 1),
            $f['properties']['titik'], $f['properties']['bahan']);
    }
}

if ($cincin > 0 || $silang > 0 || $sisiBerbalik > 0 || $menyimpang > 0) {
    fwrite(STDERR, "galat: pemeriksaan kerapian tidak bersih; berkas tidak ditulis\n");
    exit(1);
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

$naskah = json_encode(['type' => 'FeatureCollection', 'features' => $fitur],
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
$meta['layers'][JUDUL_KELUAR] = [
    'features' => count($fitur),
    'types' => ['line' => count($fitur)],
    'file' => $keluar . '.geojson',
    'panjang_m' => (int) round($panjangTotal),
    'derived_from' => 'tepi nyata garis-perairan.geojson (' . count($bank) . ' rantai/'
        . angkaNum($panjangBank, 1) . ' m) yang dipasangkan per segmen ke garis-sungai-jahit.geojson ('
        . count($sumbu) . ' polilin); semua verteks keluaran = verteks tepi asli, tanpa titik karangan,'
        . ' tanpa jembatan antarrantai, dan tanpa tulisan ke basis data',
    'ambang' => ['jarak_min_m' => $lebarMin, 'jarak_maks_m' => $lebar,
        'sudut_derajat' => $sudut, 'geser_m' => $geser, 'min_panjang_m' => $minPanjang,
        'uji_persil' => !$tanpaPersil],
    'sumbu' => ['file' => BERKAS_SUMBU . '.geojson', 'polilin' => count($sumbu),
        'panjang_m' => round($panjangSumbu, 1), 'bertepi' => $sumbuBertepi,
        'dua_sisi' => $sumbuDuaSisi],
    'bahan' => ['file' => BERKAS_BANK . '.geojson', 'rantai' => count($bank),
        'panjang_m' => round($panjangBank, 1), 'terpakai_m' => round($terpakai, 1),
        'persen' => round(100 * $terpakai / max(0.001, $panjangBank), 1),
        'dalam_koridor_m' => round($dekatSumbuPanjang, 1),
        'rapat_sumbu_m' => round($rapatSumbuPanjang, 1),
        'luar_koridor_m' => round($tolak['jauh'], 1),
        'tolak' => array_map(static fn (float $v): float => round($v, 1), $tolak)],
    'rapi' => ['cincin' => $cincin, 'silang_diri' => $silang, 'sisi_berbalik' => $sisiBerbalik,
        'menyimpang' => $menyimpang, 'lekuk_silang_dibuang_m' => round($dibuangSilang, 1),
        'potongan_pendek_dibuang_m' => round($dibuangPanjang, 1)],
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
