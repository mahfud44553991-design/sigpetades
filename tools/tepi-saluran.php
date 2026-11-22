<?php
declare(strict_types=1);

/**
 * Garis tepi saluran dari semua garis saluran yang sudah terbentuk.
 *
 *   php -d memory_limit=1024M tools/tepi-saluran.php            # uji kering
 *   php -d memory_limit=1024M tools/tepi-saluran.php --terapkan
 *
 *   --lebar=M      jarak maksimum garis saluran ke tebing lawan, bawaan 4,0 m
 *   --lebar-min=M  jarak minimum, bawaan 0,4 m
 *   --sudut=D      beda arah maksimum tebing terhadap garis saluran, bawaan 25 derajat
 *   --geser=M      toleransi s mundur sebelum potongan diputus, bawaan 1,0 m
 *   --min=M        panjang minimum satu baris tebing, bawaan 1,0 m
 *   --tanpa-persil matikan uji titik tengah di luar poligon bidang
 *   --keluar=NAMA  nama berkas keluaran tanpa .geojson, bawaan garis-tepi-saluran
 *   --rinci        cetak rincian tiap baris
 *   --terapkan     tulis berkas keluaran dan perbarui metadata.json
 *
 * Latar. Lapis Saluran memuat 689 garis (50.798,5 m) yang lahir dari garis
 * terbuka dan cincin 020100 berlabel SALURAN/IRIGASI. Pada DXF asal saluran
 * sempit digambar sebagai SATU garis; hanya sebagian yang punya garis sejajar
 * kedua. Ukur 22 Nov 2026: 114 dari 689 baris sudah berpasangan di dalam
 * berkasnya sendiri, 445 punya garis sejajar nyata 0,4-8 m (median 2,0 m) di
 * 020100 penuh, dan 244 tidak punya pasangan sama sekali. Perintah "buatkan
 * garis tepi semua saluran" karena itu dijawab dengan memasangkan tiap garis
 * saluran ke tebing lawan yang MEMANG ADA di 020100, bukan dengan menggeser
 * garis saluran sejauh setengah lebar.
 *
 * Bahan. Acuan = `garis-selokan.geojson` (689 garis, 50.798,5 m). Kandidat
 * tebing = seluruh `020100.geojson`: 13.681 LineString dan cincin 2.626
 * Polygon, semuanya verteks asli. Tiap SEGMEN kandidat dipasangkan ke garis
 * saluran TERDEKAT di titik tengahnya; segmen dipakai bila jaraknya
 * --lebar-min sampai --lebar, beda arahnya <= --sudut, sisinya (kiri/kanan
 * terhadap garis saluran) tetap, titik tengah antara segmen dan proyeksinya di
 * garis saluran TIDAK berada di dalam poligon bidang (kalau di dalam, kandidat
 * itu sisi seberang sebidang tanah, bukan tebing saluran), dan s sepanjang
 * garis saluran tidak berbalik lebih dari --geser. Poligon bidang dibaca dari
 * tabel `bidang` (status <> 'dihapus'), hanya baca.
 *
 * Kenapa per segmen kandidat, bukan per sampel garis saluran. Satu baris
 * keluaran = rentetan segmen 020100 yang bersambung pada garis saluran dan sisi
 * yang sama. Semua verteks keluaran adalah verteks kandidat asli; tidak ada
 * titik proyeksi, tidak ada titik karangan, dan tidak ada jembatan antarrantai.
 * Segmen yang jaraknya < --lebar-min (garis saluran itu sendiri dan kembarannya)
 * otomatis terbuang, jadi tidak perlu daftar indeks sumber.
 *
 * Keluaran satu berkas: `garis-tepi-saluran.geojson` (meter DXF lokal).
 *   - `cara=acuan`: 689 garis saluran disalin apa adanya sebagai tebing pertama;
 *   - `cara=pasangan`: tebing lawan hasil klaim segmen 020100.
 * Properti: `kode` (TEPI-SALURAN-####), `cara`, `saluran` (kode garis saluran
 * asal), `sisi` (acuan/kiri/kanan), `panjang_m`, `jarak_rata_m`/`jarak_min_m`/
 * `jarak_maks_m` (pasangan), `bahan` (dxf_handle asal), `titik`, `segmen`.
 * Baris pasangan yang memotong diri sendiri dibelah di titik potong nyata dan
 * lekuk tertutupnya dibuang; berkas tidak ditulis bila pemeriksaan akhir
 * menemukan cincin, sisi berbalik, silang diri, atau segmen menyimpang.
 * Metadata mendapat entri GARIS TEPI SALURAN.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_ACUAN = 'garis-selokan';
const BERKAS_KANDIDAT = '020100';
const BERKAS_KELUAR = 'garis-tepi-saluran';
const JUDUL_KELUAR = 'GARIS TEPI SALURAN';

const LEBAR = 4.0;
const LEBAR_MIN = 0.4;
const SUDUT = 25.0;
const GESER = 1.0;
const MIN_PANJANG = 1.0;
const SEL_KISI = 5.0;
const SEL_SUMBU = 10.0;

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
// Bahan: garis saluran (acuan), kandidat tebing 020100, poligon bidang
// ---------------------------------------------------------------------------

$acuan = [];
foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_ACUAN . '.geojson') as $f) {
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
    $kode = (string) ($f['properties']['kode'] ?? 'SALURAN-' . count($acuan));
    $acuan[$kode] = [
        'kode' => $kode,
        'titik' => $titik,
        's' => $s,
        'panjang' => $s[count($s) - 1],
        'label' => (string) ($f['properties']['label'] ?? ''),
    ];
}
if ($acuan === []) {
    fwrite(STDERR, "galat: garis saluran kosong\n");
    exit(1);
}

$kandidat = [];
$jumlahGaris = 0;
$jumlahCincin = 0;
$jumlahSegmenKandidat = 0;
foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_KANDIDAT . '.geojson') as $i => $f) {
    $g = $f['geometry'] ?? [];
    $jenis = (string) ($g['type'] ?? '');
    $handle = (string) ($f['properties']['dxf_handle'] ?? ('#' . $i));
    $ring = [];
    if ($jenis === 'LineString') {
        $ring = [(array) $g['coordinates']];
        $jumlahGaris++;
    } elseif ($jenis === 'Polygon') {
        $ring = (array) $g['coordinates'];
        $jumlahCincin += count($ring);
    } elseif ($jenis === 'MultiPolygon') {
        foreach ((array) $g['coordinates'] as $poligon) {
            foreach ((array) $poligon as $r) {
                $ring[] = $r;
                $jumlahCincin++;
            }
        }
    } else {
        continue;
    }
    foreach ($ring as $r => $koordinat) {
        $titik = [];
        foreach ((array) $koordinat as $p) {
            if (is_array($p) && count($p) >= 2) {
                $titik[] = [(float) $p[0], (float) $p[1]];
            }
        }
        if (count($titik) < 2) {
            continue;
        }
        $kandidat[] = [
            'nama' => $handle . (count($ring) > 1 ? '-R' . $r : ''),
            'titik' => $titik,
            'panjang' => panjangTitik($titik),
        ];
        $jumlahSegmenKandidat += count($titik) - 1;
    }
}
if ($kandidat === []) {
    fwrite(STDERR, "galat: kandidat tebing kosong\n");
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
            $ringDxf = array_map(
                static fn (array $p): array => wgs84KeDxf((float) $p[0], (float) $p[1]),
                $ring
            );
            tambahPoligon($kisiPersil, $ringDxf, SEL_KISI);
        }
    }
}

/* Indeks ruas garis saluran per sel 10 m, diperluas sejauh --lebar. */
$kisiSumbu = [];
$ruasSumbu = [];
foreach ($acuan as $kode => $a) {
    $p = $a['titik'];
    for ($j = 1; $j < count($p); $j++) {
        $id = count($ruasSumbu);
        $ruasSumbu[$id] = [$kode, $j - 1,
            $p[$j - 1][0], $p[$j - 1][1], $p[$j][0], $p[$j][1]];
        $xMin = min($p[$j - 1][0], $p[$j][0]) - $lebar;
        $xMaks = max($p[$j - 1][0], $p[$j][0]) + $lebar;
        $yMin = min($p[$j - 1][1], $p[$j][1]) - $lebar;
        $yMaks = max($p[$j - 1][1], $p[$j][1]) + $lebar;
        for ($x = (int) floor($xMin / SEL_SUMBU); $x <= (int) floor($xMaks / SEL_SUMBU); $x++) {
            for ($y = (int) floor($yMin / SEL_SUMBU); $y <= (int) floor($yMaks / SEL_SUMBU); $y++) {
                $kisiSumbu[$x . ':' . $y][] = $id;
            }
        }
    }
}

/** Garis saluran terdekat: [jarak, kode, s, arah, sisi, qx, qy]. */
$dekatSumbu = static function (float $x, float $y) use ($kisiSumbu, $ruasSumbu, $acuan): array {
    $terbaik = [INF, '', 0.0, 0.0, 1, 0.0, 0.0];
    $kunci = (int) floor($x / SEL_SUMBU) . ':' . (int) floor($y / SEL_SUMBU);
    foreach (($kisiSumbu[$kunci] ?? []) as $id) {
        [$kode, $j0, $x1, $y1, $x2, $y2] = $ruasSumbu[$id];
        [$d, $qx, $qy, $t] = proyeksi([$x, $y], [$x1, $y1, $x2, $y2]);
        if ($d >= $terbaik[0]) {
            continue;
        }
        $ax = $x2 - $x1;
        $ay = $y2 - $y1;
        $sisi = $ax * ($y - $y1) - $ay * ($x - $x1) > 0 ? 1 : -1;
        $a = $acuan[$kode];
        $terbaik = [$d, $kode, $a['s'][$j0] + $t * hypot($ax, $ay),
            rad2deg(atan2($ay, $ax)), $sisi, $qx, $qy];
    }
    return $terbaik;
};

$panjangAcuan = array_sum(array_column($acuan, 'panjang'));
printf("mode: %s · koridor %s-%s m · sudut %s derajat · geser %s m · min %s m · persil %s\n",
    $terapkan ? 'TERAPKAN' : 'uji kering', angkaNum($lebarMin, 1), angkaNum($lebar, 1),
    angkaNum($sudut, 0), angkaNum($geser, 1), angkaNum($minPanjang, 1),
    $tanpaPersil ? 'tidak diuji' : 'diuji');
if (!$tanpaPersil) {
    printf("persil dari basis data (dibaca saja): %d cincin\n", $jumlahPersil);
}
printf("acuan: %d garis saluran, %s m · kandidat 020100: %d garis + %d cincin = %d rantai, %d segmen\n",
    count($acuan), angkaNum($panjangAcuan, 1), $jumlahGaris, $jumlahCincin,
    count($kandidat), $jumlahSegmenKandidat);

// ---------------------------------------------------------------------------
// Pasangkan tiap segmen kandidat ke garis saluran terdekat, susun potongan
// ---------------------------------------------------------------------------

$daftar = [];
$tolak = ['jauh' => 0.0, 'dekat' => 0.0, 'sudut' => 0.0, 'persil' => 0.0, 'mundur' => 0.0,
    'pindah' => 0.0];
$dibuangPanjang = 0.0;
$dibuangBaris = 0;
$dibuangSilang = 0.0;
$potonganSilang = 0;
$dekatSumbuPanjang = 0.0;
$rapatSumbuPanjang = 0.0;

foreach ($kandidat as $b) {
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
        &$dibuangBaris, $p, $b, &$runSumbu, &$runSisi, &$runPanjang, &$runJumlah,
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
            'bahan' => $b['nama'],
            'panjang' => $panjang,
            'jarakJumlah' => $runJumlah,
            'jarakPanjang' => $runPanjang,
            'jarakMin' => $runMin === INF ? 0.0 : $runMin,
            'jarakMaks' => $runMaks,
        ];
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

// ---------------------------------------------------------------------------
// Verifikasi baris pasangan: jarak, sisi, arah, cincin, silang diri
// ---------------------------------------------------------------------------

/** Jarak titik ke seluruh polilin acuan: [jarak, arah, sisi]. */
$dekatAcuan = static function (float $x, float $y, array $a): array {
    $p = $a['titik'];
    $terbaik = [INF, 0.0, 1];
    for ($j = 1; $j < count($p); $j++) {
        [$d] = proyeksi([$x, $y], [$p[$j - 1][0], $p[$j - 1][1], $p[$j][0], $p[$j][1]]);
        if ($d < $terbaik[0]) {
            $ax = $p[$j][0] - $p[$j - 1][0];
            $ay = $p[$j][1] - $p[$j - 1][1];
            $terbaik = [$d, rad2deg(atan2($ay, $ax)),
                $ax * ($y - $p[$j - 1][1]) - $ay * ($x - $p[$j - 1][0]) > 0 ? 1 : -1];
        }
    }
    return $terbaik;
};

$cincin = 0;
$silang = 0;
$sisiBerbalik = 0;
$menyimpang = 0;
$pasangan = [];
$perSumbu = [];
$perSisi = ['kiri' => [0, 0.0], 'kanan' => [0, 0.0]];
foreach ($akhir as $row) {
    $p = $row['titik'];
    $kode = $row['sumbu'];
    $axis = $acuan[$kode] ?? null;
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
        [$d, $arahSumbu, $sisi] = $dekatAcuan($mx, $my, $axis);
        $jarakJumlah += $d * $len;
        $panjangJumlah += $len;
        $jarakMin = min($jarakMin, $d);
        $jarakMaks = max($jarakMaks, $d);
        $arahSegmen = rad2deg(atan2($p[$i][1] - $p[$i - 1][1], $p[$i][0] - $p[$i - 1][0]));
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
    $pasangan[] = [
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => $p],
        'properties' => [
            'kode' => '',
            'cara' => 'pasangan',
            'saluran' => $kode,
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
    $perSumbu[$kode] = ($perSumbu[$kode] ?? 0.0) + $row['panjang'];
    $perSisi[$row['sisi']][0]++;
    $perSisi[$row['sisi']][1] += $row['panjang'];
}

// ---------------------------------------------------------------------------
// Susun keluaran: tiap garis saluran (acuan) lalu tebing lawannya
// ---------------------------------------------------------------------------

$pasanganPer = [];
foreach ($pasangan as $f) {
    $pasanganPer[$f['properties']['saluran']][] = $f;
}
$fitur = [];
$panjangPasangan = 0.0;
$titikPasangan = 0;
foreach ($acuan as $kode => $a) {
    $p = $a['titik'];
    $fitur[] = [
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => $p],
        'properties' => [
            'kode' => '',
            'cara' => 'acuan',
            'saluran' => $kode,
            'sisi' => 'acuan',
            'panjang_m' => round($a['panjang'], 2),
            'label' => $a['label'],
            'titik' => count($p),
            'segmen' => count($p) - 1,
        ],
    ];
    foreach (($pasanganPer[$kode] ?? []) as $f) {
        $fitur[] = $f;
        $panjangPasangan += (float) $f['properties']['panjang_m'];
        $titikPasangan += (int) $f['properties']['titik'];
    }
}
foreach ($fitur as $i => $f) {
    $fitur[$i]['properties']['kode'] = sprintf('TEPI-SALURAN-%04d', $i + 1);
}

$berpasangan = 0;
foreach ($acuan as $kode => $a) {
    if (($perSumbu[$kode] ?? 0) > 0) {
        $berpasangan++;
    }
}
$lebarRata = [];
foreach ($pasangan as $f) {
    $lebarRata[] = (float) $f['properties']['jarak_rata_m'];
}
sort($lebarRata);
$lebarMedian = $lebarRata === [] ? 0.0 : $lebarRata[(int) floor(count($lebarRata) / 2)];

printf("hasil: %d baris (acuan %d + pasangan %d) · acuan %s m + pasangan %s m = %s m · %d titik pasangan\n",
    count($fitur), count($acuan), count($pasangan), angkaNum($panjangAcuan, 1),
    angkaNum($panjangPasangan, 1), angkaNum($panjangAcuan + $panjangPasangan, 1), $titikPasangan);
printf("saluran bertebing lawan: %d dari %d (satu tebing %d) · lebar rata-rata median %s m\n",
    $berpasangan, count($acuan), count($acuan) - $berpasangan, angkaNum($lebarMedian, 2));
printf("pasangan per sisi: kiri %d baris/%s m · kanan %d baris/%s m\n",
    $perSisi['kiri'][0], angkaNum($perSisi['kiri'][1], 1),
    $perSisi['kanan'][0], angkaNum($perSisi['kanan'][1], 1));
printf("kandidat dalam koridor: %s m · terpakai %s m dari %s m (%.1f%%)\n",
    angkaNum($dekatSumbuPanjang, 1), angkaNum($terpakai, 1), angkaNum($panjangAcuan, 1),
    100 * $terpakai / max(0.001, $panjangAcuan));
printf("sisa koridor: %s m (sudut %s · persil %s · mundur %s · pindah %s)\n",
    angkaNum(max(0.0, $dekatSumbuPanjang - $terpakai), 1), angkaNum($tolak['sudut'], 1),
    angkaNum($tolak['persil'], 1), angkaNum($tolak['mundur'], 1), angkaNum($tolak['pindah'], 1));
printf("di luar koridor: %s m · menempel garis saluran < %s m: %s m\n",
    angkaNum($tolak['jauh'], 1), angkaNum($lebarMin, 1), angkaNum($rapatSumbuPanjang, 1));
printf("rapi: cincin %d · silang diri %d · sisi berbalik %d · segmen menyimpang >%s derajat %d\n",
    $cincin, $silang, $sisiBerbalik, angkaNum($sudut, 0), $menyimpang);
printf("dibuang: %d potongan pendek (%s m) · lekuk silang %s m (%d baris terbelah)\n",
    $dibuangBaris, angkaNum($dibuangPanjang, 1), angkaNum($dibuangSilang, 1), $potonganSilang);

if ($rinci) {
    foreach ($fitur as $f) {
        $pr = $f['properties'];
        if ($pr['cara'] === 'acuan') {
            printf("%s  %-22s acuan    %7s m · %3d titik\n", $pr['kode'], $pr['saluran'],
                angkaNum((float) $pr['panjang_m'], 1), $pr['titik']);
            continue;
        }
        printf("%s  %-22s %-6s %7s m · %3d titik · jarak %s m · %s\n", $pr['kode'],
            $pr['saluran'], $pr['sisi'], angkaNum((float) $pr['panjang_m'], 1),
            $pr['titik'], angkaNum((float) $pr['jarak_rata_m'], 2), $pr['bahan']);
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
if (!is_dir($cadang) && !mkdir($cadang, 0775, true) && !is_dir($cadang)) {
    fwrite(STDERR, "galat: gagal membuat folder cadangan metadata\n");
    exit(1);
}
copy($berkasMeta, $cadang . '/metadata-sebelum-' . $stempel . '.json');
$meta['layers'][JUDUL_KELUAR] = [
    'features' => count($fitur),
    'types' => ['line' => count($fitur)],
    'file' => $keluar . '.geojson',
    'panjang_m' => (int) round($panjangAcuan + $panjangPasangan),
    'panjang_acuan_m' => round($panjangAcuan, 1),
    'panjang_pasangan_m' => round($panjangPasangan, 1),
    'derived_from' => 'garis-selokan.geojson (' . count($acuan) . ' garis/' . angkaNum($panjangAcuan, 1)
        . ' m) dipasangkan per segmen ke tebing nyata 020100.geojson (' . count($kandidat)
        . ' rantai); tiap garis saluran disalin sebagai tebing acuan, tebing lawan diklaim dari segmen'
        . ' 020100 yang jaraknya ' . angkaNum($lebarMin, 1) . '-' . angkaNum($lebar, 1)
        . ' m, arahnya <= ' . angkaNum($sudut, 0) . ' derajat, sisinya tetap, dan koridornya di luar'
        . ' poligon bidang; semua verteks keluaran = verteks bahan, tanpa offset karangan',
    'ambang' => ['jarak_min_m' => $lebarMin, 'jarak_maks_m' => $lebar,
        'sudut_derajat' => $sudut, 'geser_m' => $geser, 'min_panjang_m' => $minPanjang,
        'uji_persil' => !$tanpaPersil],
    'acuan' => ['file' => BERKAS_ACUAN . '.geojson', 'baris' => count($acuan),
        'panjang_m' => round($panjangAcuan, 1), 'berpasangan' => $berpasangan,
        'satu_tebing' => count($acuan) - $berpasangan],
    'pasangan' => ['baris' => count($pasangan), 'panjang_m' => round($panjangPasangan, 1),
        'titik' => $titikPasangan, 'kiri' => $perSisi['kiri'][0],
        'kanan' => $perSisi['kanan'][0], 'lebar_median_m' => round($lebarMedian, 2),
        'pasangan_per_baris' => $pasangan === [] ? 0
            : round(count($pasangan) / max(1, $berpasangan), 2)],
    'bahan' => ['file' => BERKAS_KANDIDAT . '.geojson', 'garis' => $jumlahGaris,
        'cincin' => $jumlahCincin, 'rantai' => count($kandidat),
        'segmen' => $jumlahSegmenKandidat, 'terpakai_m' => round($terpakai, 1),
        'dalam_koridor_m' => round($dekatSumbuPanjang, 1),
        'rapat_garis_m' => round($rapatSumbuPanjang, 1),
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
if (is_dir($cadang)) {
    echo 'cadangan metadata: _lama/' . basename($cadang) . "\n";
}
