<?php
declare(strict_types=1);

/**
 * Garis tepi Jalan dari SEMUA sumbu jalan.
 *
 *   php -d memory_limit=1024M tools/garis-jalan-dari-sumbu.php            # uji kering
 *   php -d memory_limit=1024M tools/garis-jalan-dari-sumbu.php --terapkan
 *
 *   --lebar=M          lebar koridor ronde pertama, bawaan 8,0 m
 *   --lebar-yatim=M    lebar koridor ronde kedua untuk titik sampel yang belum
 *                      berpasangan, bawaan 10,0 m; 0 = mati
 *   --langkah=M        jarak titik sampel, bawaan 2,0 m
 *   --rinci            cetak 25 sumbu dengan cakupan sisi terlemah
 *   --tanya=KODE       cetak rincian klaim dan terbitan satu sumbu
 *
 * Masukan: `garis-jalan-sumbu.geojson` (1.560 sumbu turunan label, termasuk 67
 * sumbu yatim) dan `garis-sumbu-manual.geojson` (130 sumbu alat "Penanda
 * sumbu"), jadi 1.690 sumbu. Keluaran: `garis-jalan-dari-sumbu.geojson`, satu
 * berkas BARU; `garis-jalan-tepi.geojson` tidak disentuh supaya alat label bisa
 * dijalankan ulang tanpa menghapus hasil alat ini, dan sebaliknya.
 *
 * Aturan sisi, sama dengan tahap 2 `tools/garis-jalan-label.php`: tiap titik
 * sampel sumbu (tiap LANGKAH meter) dicari segmen 020100 terdekat di kiri dan
 * kanan yang sejajar (beda arah <= 25 derajat), berjarak antara 0,25 m dan
 * lebar koridor, yang titik tengah koridornya TIDAK berada di dalam persil
 * (kalau di dalam, segmen itu sisi seberang sebidang tanah, bukan sisi jalan)
 * dan TIDAK berada di antara dua tepi air sejajar (selokan/sungai). Segmen
 * terpilih dipotong menurut untaian bersambung pada fitur 020100 asalnya, jadi
 * tepi keluaran menempel persis pada garis sisi bidang yang dilewati sumbu,
 * bukan garis sejajar karangan. Ujung untaian dipangkas ke proyeksi sampel lalu
 * diperpanjang setengah LANGKAH, sebab wilayah antara sampel terujung dan titik
 * tengah ke sampel tetangga tetap diwakili sisi itu; potongan di bawah 1 m
 * dibuang. Klaim dicatat per sumbu dan sisi, jadi sumbu yang bersebelahan atau
 * berimpit tetap punya untaian sendiri walau memakai segmen sisi yang sama.
 * Baris per sumbu+sisi kemudian disambung menurut posisinya di sepanjang sumbu:
 * ujung yang berjarak <= LANGKAH disatukan menjadi satu rantai dengan garis
 * penghubung lurus, sedangkan celah lebih besar dibiarkan putus karena berarti
 * sisi jalan memang tidak ditemukan di situ (mulut persimpangan, saluran air).
 *
 * Persil untuk uji koridor dibaca dari tabel `bidang` (status <> 'dihapus'),
 * hanya baca. Lapis air dibaca dari `garis-selokan.geojson` dan
 * `garis-perairan.geojson`; kalau keduanya dibangkitkan ulang, jalankan alat
 * ini sesudahnya.
 */

require_once __DIR__ . '/lib-impor.php';

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_SUMBER = '020100';
const BERKAS_SUMBU = ['garis-jalan-sumbu', 'garis-sumbu-manual'];
const BERKAS_KELUAR = 'garis-jalan-dari-sumbu';
const BERKAS_TEPI_LAMA = 'garis-jalan-tepi';
const BERKAS_AIR = ['garis-selokan', 'garis-perairan'];

const LANGKAH_BAWAAN = 2.0;
const LEBAR_BAWAAN = 8.0;
const LEBAR_YATIM_BAWAAN = 10.0;
const LEBAR_MIN = 0.25;
const SUDUT_PASANG = 25.0;
const MIN_TEPI = 1.0;
const SEL = 10.0;
const SEL_KISI = 5.0;
const RAGU_AIR = 3.5;
const SUDUT_AIR = 25.0;

function galat(string $pesan): never
{
    fwrite(STDERR, 'galat: ' . $pesan . PHP_EOL);
    exit(1);
}

function angkaNum(float $n, int $desimal = 0): string
{
    return number_format($n, $desimal, ',', '.');
}

function bedaArah(float $a, float $b): float
{
    $beda = abs(fmod($a - $b, 180.0));
    return $beda > 90.0 ? 180.0 - $beda : $beda;
}

/** Segmen 020100 berarah: [x1, y1, x2, y2, arah, kunci fitur, indeks segmen]. */
function tambahSegmen(array &$kisi, array &$segmen, array $titik, string $kunci, float $sel): void
{
    for ($i = 0; $i + 1 < count($titik); $i++) {
        $x1 = (float) $titik[$i][0];
        $y1 = (float) $titik[$i][1];
        $x2 = (float) $titik[$i + 1][0];
        $y2 = (float) $titik[$i + 1][1];
        if (hypot($x2 - $x1, $y2 - $y1) <= 0.0) {
            continue;
        }
        $id = count($segmen);
        $segmen[$id] = [$x1, $y1, $x2, $y2, rad2deg(atan2($y2 - $y1, $x2 - $x1)), $kunci, $i];
        $xMin = (int) floor(min($x1, $x2) / $sel);
        $xMax = (int) floor(max($x1, $x2) / $sel);
        $yMin = (int) floor(min($y1, $y2) / $sel);
        $yMax = (int) floor(max($y1, $y2) / $sel);
        for ($gx = $xMin; $gx <= $xMax; $gx++) {
            for ($gy = $yMin; $gy <= $yMax; $gy++) {
                $kisi[$gx . ':' . $gy][$id] = true;
            }
        }
    }
}

/** Titik sampel sebuah garis setiap `$langkah` meter, dengan arah segmennya. */
function sampel(array $titik, float $langkah): array
{
    $keluar = [];
    for ($i = 0; $i + 1 < count($titik); $i++) {
        $dx = (float) $titik[$i + 1][0] - (float) $titik[$i][0];
        $dy = (float) $titik[$i + 1][1] - (float) $titik[$i][1];
        $panjang = hypot($dx, $dy);
        if ($panjang <= 0.0) {
            continue;
        }
        $arah = rad2deg(atan2($dy, $dx));
        $n = max(1, (int) floor($panjang / $langkah));
        for ($j = 0; $j < $n; $j++) {
            $t = $j / $n;
            $keluar[] = [(float) $titik[$i][0] + $t * $dx, (float) $titik[$i][1] + $t * $dy, $arah];
        }
    }
    return $keluar === [] ? [[(float) $titik[0][0], (float) $titik[0][1], 0.0]] : $keluar;
}

function panjang(array $titik): float
{
    $jumlah = 0.0;
    for ($i = 1; $i < count($titik); $i++) {
        $jumlah += hypot((float) $titik[$i][0] - (float) $titik[$i - 1][0],
            (float) $titik[$i][1] - (float) $titik[$i - 1][1]);
    }
    return $jumlah;
}

/** Titik pada segmen ke-$i di parameter $t (0..1). */
function titikPada(array $titik, int $i, float $t): array
{
    $x1 = (float) $titik[$i][0];
    $y1 = (float) $titik[$i][1];
    $x2 = (float) $titik[$i + 1][0];
    $y2 = (float) $titik[$i + 1][1];
    return [$x1 + $t * ($x2 - $x1), $y1 + $t * ($y2 - $y1)];
}

/** Posisi titik di sepanjang sumbu (jarak lengkung dari awal, meter). */
function posisiSumbu(array $titik, array $kum, float $x, float $y): float
{
    $terbaik = INF;
    $pos = 0.0;
    for ($i = 0; $i + 1 < count($titik); $i++) {
        $x1 = (float) $titik[$i][0];
        $y1 = (float) $titik[$i][1];
        $dx = (float) $titik[$i + 1][0] - $x1;
        $dy = (float) $titik[$i + 1][1] - $y1;
        $pan = $dx * $dx + $dy * $dy;
        $t = $pan > 0.0 ? max(0.0, min(1.0, (($x - $x1) * $dx + ($y - $y1) * $dy) / $pan)) : 0.0;
        $d = hypot($x - ($x1 + $t * $dx), $y - ($y1 + $t * $dy));
        if ($d < $terbaik) {
            $terbaik = $d;
            $pos = (float) $kum[$i] + $t * hypot($dx, $dy);
        }
    }
    return $pos;
}

/** Buang titik yang berturut-turut berjarak < 1 cm. */
function rapatkan(array $titik): array
{
    $keluar = [];
    foreach ($titik as $p) {
        if ($keluar !== [] && hypot($p[0] - $keluar[count($keluar) - 1][0],
            $p[1] - $keluar[count($keluar) - 1][1]) < 0.01) {
            continue;
        }
        $keluar[] = $p;
    }
    return $keluar;
}

/** Masukkan cincin poligon ke kisi berdasarkan kotak batasnya. */
function tambahPoligon(array &$kisi, array &$poligon, array &$besar, array $ring, float $sel,
    int $maksSel = 900): void
{
    if (count($ring) < 4) {
        return;
    }
    $xs = array_column($ring, 0);
    $ys = array_column($ring, 1);
    $gxMin = (int) floor((float) min($xs) / $sel);
    $gxMax = (int) floor((float) max($xs) / $sel);
    $gyMin = (int) floor((float) min($ys) / $sel);
    $gyMax = (int) floor((float) max($ys) / $sel);
    if (($gxMax - $gxMin + 1) * ($gyMax - $gyMin + 1) > $maksSel) {
        $besar[] = $ring;
        return;
    }
    $id = count($poligon);
    $poligon[$id] = $ring;
    for ($gx = $gxMin; $gx <= $gxMax; $gx++) {
        for ($gy = $gyMin; $gy <= $gyMax; $gy++) {
            $kisi[$gx . ':' . $gy][$id] = true;
        }
    }
}

/** Uji ray casting: apakah titik berada di dalam cincin. */
function dalamRing(float $x, float $y, array $ring): bool
{
    $dalam = false;
    for ($i = 0, $k = count($ring) - 1; $i < count($ring); $k = $i++) {
        $xi = (float) $ring[$i][0];
        $yi = (float) $ring[$i][1];
        $xk = (float) $ring[$k][0];
        $yk = (float) $ring[$k][1];
        if ((($yi > $y) !== ($yk > $y)) && ($x < ($xk - $xi) * ($y - $yi) / ($yk - $yi) + $xi)) {
            $dalam = !$dalam;
        }
    }
    return $dalam;
}

/** Apakah titik jatuh di dalam salah satu persil? */
function diPoligon(float $x, float $y, array $kisi, array $poligon, array $besar): bool
{
    foreach (array_keys($kisi[(int) floor($x / SEL_KISI) . ':' . (int) floor($y / SEL_KISI)] ?? []) as $id) {
        if (dalamRing($x, $y, $poligon[$id])) {
            return true;
        }
    }
    foreach ($besar as $ring) {
        if (dalamRing($x, $y, $ring)) {
            return true;
        }
    }
    return false;
}

/** Tepi air terbit sebagai segmen berarah, id berkas di kolom keenam. */
function bacaTepiAir(): array
{
    $kisi = [];
    $segmen = [];
    $fitur = 0;
    foreach (BERKAS_AIR as $berkas) {
        $path = DIR_LAPISAN . '/' . $berkas . '.geojson';
        if (!is_file($path)) {
            galat('berkas tepi air tidak ada: ' . $berkas . '.geojson');
        }
        foreach (geojsonBaca($path) as $f) {
            $g = $f['geometry'] ?? null;
            if (($g['type'] ?? '') !== 'LineString') {
                continue;
            }
            $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
                (array) ($g['coordinates'] ?? []));
            if (count($titik) < 2) {
                continue;
            }
            $fitur++;
            tambahSegmen($kisi, $segmen, $titik, 'air:' . $fitur, SEL);
        }
    }
    return [$kisi, $segmen, $fitur];
}

/** Titik tengah koridor berada di antara dua tepi air sejajar dengan arah ini? */
function diKoridorAir(array $kisi, array $segmen, float $px, float $py, float $arah): bool
{
    $batas = (int) ceil(RAGU_AIR / SEL);
    $gx = (int) floor($px / SEL);
    $gy = (int) floor($py / SEL);
    $a = deg2rad($arah);
    $nx = -sin($a);
    $ny = cos($a);
    $sisi = [];
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                $s = $segmen[$id];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                if ($pan <= 0.0 || bedaArah($arah, $s[4]) > SUDUT_AIR) {
                    continue;
                }
                $t = max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan));
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $jarak = hypot($px - $fx, $py - $fy);
                if ($jarak <= 0.05 || $jarak > RAGU_AIR) {
                    continue;
                }
                $belok = ($px - $fx) * $nx + ($py - $fy) * $ny;
                $lagu = $belok > 0.0 ? 'kiri' : 'kanan';
                $sisi[$lagu][(string) $s[5]] = true;
            }
        }
    }
    foreach (array_keys($sisi['kiri'] ?? []) as $kiri) {
        foreach (array_keys($sisi['kanan'] ?? []) as $kanan) {
            if ($kiri !== $kanan) {
                return true;
            }
        }
    }
    return false;
}

/** Cari segmen 020100 terdekat di kiri dan kanan titik sampel. */
function cariSisi(array $kisi, array $segmen, float $px, float $py, float $arah, float $radius,
    array $kisiPoligon, array $poligon, array $poligonBesar, array $kisiAir, array $segmenAir,
    int &$tolakPoligon, int &$tolakAir): array
{
    $batas = (int) ceil($radius / SEL);
    $gx = (int) floor($px / SEL);
    $gy = (int) floor($py / SEL);
    $a = deg2rad($arah);
    $nx = -sin($a);
    $ny = cos($a);
    $kiri = null;
    $kanan = null;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                $s = $segmen[$id];
                if (bedaArah($arah, $s[4]) > SUDUT_PASANG) {
                    continue;
                }
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                $t = $pan > 0.0 ? max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan)) : 0.0;
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $jarak = hypot($px - $fx, $py - $fy);
                if ($jarak < LEBAR_MIN || $jarak > $radius) {
                    continue;
                }
                $mx = ($px + $fx) / 2;
                $my = ($py + $fy) / 2;
                if (diPoligon($mx, $my, $kisiPoligon, $poligon, $poligonBesar)) {
                    $tolakPoligon++;
                    continue;
                }
                if (diKoridorAir($kisiAir, $segmenAir, $mx, $my, $arah)) {
                    $tolakAir++;
                    continue;
                }
                $belok = ($px - $fx) * $nx + ($py - $fy) * $ny;
                if ($belok > 0.0) {
                    if ($kiri === null || $jarak < $kiri['jarak']) {
                        $kiri = ['jarak' => $jarak, 'id' => (int) $id, 't' => $t];
                    }
                } else {
                    if ($kanan === null || $jarak < $kanan['jarak']) {
                        $kanan = ['jarak' => $jarak, 'id' => (int) $id, 't' => $t];
                    }
                }
            }
        }
    }
    return [$kiri, $kanan];
}

/** Poligon/MultiPolygon GeoJSON basis data (WGS84) ke cincin meter DXF. */
function cincinDariGeojson(array $geometri): array
{
    $tipe = (string) ($geometri['type'] ?? '');
    if (!isset($geometri['coordinates'])) {
        return [];
    }
    $bagian = match ($tipe) {
        'MultiPolygon' => (array) $geometri['coordinates'],
        'Polygon' => [(array) $geometri['coordinates']],
        default => [],
    };
    $keluar = [];
    foreach ($bagian as $poligonBagian) {
        $ring = array_map(
            static fn(array $p): array => wgs84KeDxf((float) $p[0], (float) $p[1]),
            (array) ($poligonBagian[0] ?? [])
        );
        if (count($ring) >= 4) {
            $keluar[] = $ring;
        }
    }
    return $keluar;
}

function tulisBerkas(string $berkas, array $daftar): void
{
    $naskah = json_encode(['type' => 'FeatureCollection', 'features' => $daftar],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($naskah === false) {
        galat('gagal menyusun JSON ' . $berkas);
    }
    $tujuan = DIR_LAPISAN . '/' . $berkas . '.geojson';
    $sementara = tempnam(sys_get_temp_dir(), 'tepi-sumbu-');
    if ($sementara === false || file_put_contents($sementara, $naskah) === false) {
        galat('gagal menulis sementara ' . $berkas . '.geojson');
    }
    if (!rename($sementara, $tujuan)) {
        @unlink($sementara);
        galat('gagal memindahkan ' . $berkas . '.geojson');
    }
    printf("ditulis: %s (%.1f KB)\n", $tujuan, strlen($naskah) / 1024);
}

// ---------------------------------------------------------------------------
// Flag
// ---------------------------------------------------------------------------

$terapkan = false;
$lebar = LEBAR_BAWAAN;
$lebarYatim = LEBAR_YATIM_BAWAAN;
$langkah = LANGKAH_BAWAAN;
$rinci = false;
$tanya = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--terapkan') {
        $terapkan = true;
    } elseif ($arg === '--rinci') {
        $rinci = true;
    } elseif (str_starts_with($arg, '--tanya=')) {
        $tanya = substr($arg, 8);
    } elseif (str_starts_with($arg, '--lebar=')) {
        $lebar = (float) str_replace(',', '.', substr($arg, 8));
    } elseif (str_starts_with($arg, '--lebar-yatim=')) {
        $lebarYatim = (float) str_replace(',', '.', substr($arg, 14));
    } elseif (str_starts_with($arg, '--langkah=')) {
        $langkah = (float) str_replace(',', '.', substr($arg, 10));
    } else {
        galat('flag tidak dikenal: ' . $arg);
    }
}
if ($lebar < 1.0 || $lebar > 15.0) {
    galat('--lebar harus antara 1 dan 15 meter');
}
if ($lebarYatim !== 0.0 && ($lebarYatim < $lebar || $lebarYatim > 15.0)) {
    galat('--lebar-yatim harus 0 (mati) atau antara --lebar dan 15 meter');
}
if ($langkah < 0.5 || $langkah > 5.0) {
    galat('--langkah harus antara 0,5 dan 5 meter');
}

printf("mode: %s · koridor %s m%s · langkah sampel %s m · keluaran %s.geojson\n",
    $terapkan ? 'TERAPKAN' : 'uji kering', angkaNum($lebar, 1),
    $lebarYatim === 0.0 ? '' : ' + yatim ' . angkaNum($lebarYatim, 1),
    angkaNum($langkah, 1), BERKAS_KELUAR);

// ---------------------------------------------------------------------------
// Sumbu
// ---------------------------------------------------------------------------

$sumbu = [];
foreach (BERKAS_SUMBU as $berkas) {
    $path = DIR_LAPISAN . '/' . $berkas . '.geojson';
    if (!is_file($path)) {
        galat('berkas sumbu tidak ada: ' . $berkas . '.geojson');
    }
    foreach (geojsonBaca($path) as $f) {
        $g = $f['geometry'] ?? null;
        if (($g['type'] ?? '') !== 'LineString') {
            continue;
        }
        $p = $f['properties'] ?? [];
        $kelas = $berkas === 'garis-sumbu-manual'
            ? 'manual'
            : ((($p['cara'] ?? '') === 'sumbu') ? 'turunan' : 'yatim');
        $sumbu[] = [
            'kode' => (string) ($p['kode'] ?? ('SUMBU-' . count($sumbu))),
            'kelas' => $kelas,
            'titik' => array_map(static fn(array $q): array => [(float) $q[0], (float) $q[1]],
                (array) $g['coordinates']),
        ];
    }
}
$jumlahKelas = [];
foreach ($sumbu as $f) {
    $jumlahKelas[$f['kelas']] = ($jumlahKelas[$f['kelas']] ?? 0) + 1;
}
printf("sumbu: %d (%s)\n", count($sumbu), implode(', ', array_map(
    static fn(string $k, int $n): string => $k . ' ' . $n,
    array_keys($jumlahKelas), array_values($jumlahKelas))));

// ---------------------------------------------------------------------------
// Bahan 020100: garis terbuka + cincin poligon, disimpan berurut per fitur
// ---------------------------------------------------------------------------

$bahan = [];
$segmen = [];
$kisiBahan = [];
foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_SUMBER . '.geojson') as $indeks => $f) {
    $g = $f['geometry'] ?? null;
    if (!is_array($g)) {
        continue;
    }
    if (($g['type'] ?? '') === 'Polygon') {
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) ($g['coordinates'][0] ?? []));
        if (count($titik) < 4) {
            continue;
        }
        $kunci = 'P:' . (int) $indeks;
        $bahan[$kunci] = ['titik' => $titik, 'asal' => 'cincin', 'ring' => true];
    } elseif (($g['type'] ?? '') === 'LineString') {
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) $g['coordinates']);
        if (count($titik) < 2) {
            continue;
        }
        $kunci = 'G:' . (int) $indeks;
        $bahan[$kunci] = ['titik' => $titik, 'asal' => 'garis', 'ring' => false];
    } else {
        continue;
    }
    tambahSegmen($kisiBahan, $segmen, $titik, $kunci, SEL);
}
printf("020100: %d fitur, %d segmen\n", count($bahan), count($segmen));

// ---------------------------------------------------------------------------
// Persil (hanya baca) dan tepi air
// ---------------------------------------------------------------------------

$kisiPoligon = [];
$poligon = [];
$poligonBesar = [];
$jumlahPersil = 0;
foreach ((array) db_all('SELECT id, ST_AsGeoJSON(geom) AS geojson FROM bidang '
    . "WHERE status <> 'dihapus' AND geom IS NOT NULL ORDER BY id") as $baris) {
    $geometri = json_decode((string) $baris['geojson'], true);
    if (!is_array($geometri)) {
        continue;
    }
    foreach (cincinDariGeojson($geometri) as $ring) {
        $jumlahPersil++;
        tambahPoligon($kisiPoligon, $poligon, $poligonBesar, $ring, SEL_KISI);
    }
}
printf("persil (baca saja): %d cincin\n", $jumlahPersil);

[$kisiAir, $segmenAir, $jumlahAir] = bacaTepiAir();
printf("tepi air: %d garis\n", $jumlahAir);

// ---------------------------------------------------------------------------
// Klaim segmen oleh sumbu
// ---------------------------------------------------------------------------

/**
 * @var array<string, array<string, array<string, array<int, array{jarak:float, tmin:float, tmax:float}>>>> $pakai
 *      kode sumbu -> sisi -> kunci fitur 020100 -> indeks segmen -> rentang proyeksi
 */
$pakai = [];
$cakupan = [];
$tolakPoligon = 0;
$tolakAir = 0;
$jumlahSampel = 0;
$rondeYatim = 0;
foreach ($sumbu as $f) {
    $sampel = sampel($f['titik'], $langkah);
    $kiri = 0;
    $kanan = 0;
    foreach ($sampel as [$x, $y, $arah]) {
        $jumlahSampel++;
        $pilih = cariSisi($kisiBahan, $segmen, $x, $y, $arah, $lebar,
            $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir, $tolakPoligon, $tolakAir);
        if ($lebarYatim > 0.0 && ($pilih[0] === null || $pilih[1] === null)) {
            [$ulangKiri, $ulangKanan] = cariSisi($kisiBahan, $segmen, $x, $y, $arah, $lebarYatim,
                $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir, $tolakPoligon, $tolakAir);
            if ($pilih[0] === null && $ulangKiri !== null) {
                $pilih[0] = $ulangKiri;
                $rondeYatim++;
            }
            if ($pilih[1] === null && $ulangKanan !== null) {
                $pilih[1] = $ulangKanan;
                $rondeYatim++;
            }
        }
        foreach ([['kiri', $pilih[0]], ['kanan', $pilih[1]]] as [$sisi, $hasil]) {
            if ($hasil === null) {
                continue;
            }
            if ($sisi === 'kiri') {
                $kiri++;
            } else {
                $kanan++;
            }
            $s = $segmen[$hasil['id']];
            $kunci = (string) $s[5];
            $indeks = (int) $s[6];
            $lama = $pakai[$f['kode']][$sisi][$kunci][$indeks] ?? null;
            if ($lama === null) {
                $pakai[$f['kode']][$sisi][$kunci][$indeks] = ['jarak' => $hasil['jarak'],
                    'tmin' => $hasil['t'], 'tmax' => $hasil['t'], 'n' => 1];
            } else {
                $pakai[$f['kode']][$sisi][$kunci][$indeks] = [
                    'jarak' => min($lama['jarak'], $hasil['jarak']),
                    'tmin' => min($lama['tmin'], $hasil['t']),
                    'tmax' => max($lama['tmax'], $hasil['t']),
                    'n' => $lama['n'] + 1,
                ];
            }
        }
    }
    $n = count($sampel);
    $cakupan[$f['kode']] = [
        'kelas' => $f['kelas'],
        'panjang' => panjang($f['titik']),
        'kiri' => $n > 0 ? $kiri / $n : 0.0,
        'kanan' => $n > 0 ? $kanan / $n : 0.0,
    ];
}

$fiturKlaim = [];
foreach ($pakai as $perSisi) {
    foreach ($perSisi as $perFitur) {
        foreach (array_keys($perFitur) as $kunci) {
            $fiturKlaim[$kunci] = true;
        }
    }
}
printf("klaim: %d titik sampel · %d fitur 020100 terpakai · ronde yatim menambah %d pasangan"
    . " · tolak persil %d · tolak air %d\n", $jumlahSampel, count($fiturKlaim), $rondeYatim,
    $tolakPoligon, $tolakAir);

$jumlahKlaim = 0;
$panjangKlaim = 0.0;
foreach ($pakai as $perSisi) {
    foreach ($perSisi as $perFitur) {
        foreach ($perFitur as $kunci => $klaim) {
            $titik = $bahan[$kunci]['titik'];
            foreach ($klaim as $i => $c) {
                $jumlahKlaim++;
                $panjangKlaim += hypot((float) $titik[$i + 1][0] - $titik[$i][0],
                    (float) $titik[$i + 1][1] - $titik[$i][1]);
            }
        }
    }
}
printf("klaim tersimpan: %d segmen · %s m\n", $jumlahKlaim, angkaNum($panjangKlaim, 1));

// ---------------------------------------------------------------------------
// Potong klaim menjadi untaian bersambung per fitur 020100
// ---------------------------------------------------------------------------

/**
 * Untaian segmen bersambung pada satu fitur 020100; cincin yang terpotong di
 * awal dan akhir digabung kembali menjadi satu untaian melingkar.
 *
 * @return list<array{0:int,1:int,2?:bool}> [awal, akhir, lewat]
 */
function untaian(array $klaim, bool $ring, int $jumlahSegmen): array
{
    ksort($klaim);
    $keluar = [];
    $awal = null;
    $akhir = null;
    foreach ($klaim as $i => $c) {
        if ($awal === null) {
            $awal = $akhir = $i;
            continue;
        }
        if ($i === $akhir + 1) {
            $akhir = $i;
            continue;
        }
        $keluar[] = [$awal, $akhir];
        $awal = $akhir = $i;
    }
    if ($awal !== null) {
        $keluar[] = [$awal, $akhir];
    }
    if ($ring && count($keluar) > 1) {
        $pertama = $keluar[0];
        $terakhir = $keluar[count($keluar) - 1];
        if ($pertama[0] === 0 && $terakhir[1] === $jumlahSegmen - 1) {
            array_pop($keluar);
            array_shift($keluar);
            array_unshift($keluar, [$terakhir[0], $pertama[1], true]);
        }
    }
    return $keluar;
}

/**
 * Potong satu untaian klaim menjadi polyline, dipangkas ke proyeksi sampel di ujung.
 * Ujung diperpanjang setengah langkah sampel: wilayah antara sampel terujung dan
 * titik tengah ke sampel tetangga tetap diwakili sisi itu.
 */
function potongUntaian(array $titik, array $klaim, array $run, int $jumlahSegmen,
    float $langkah): array
{
    [$a, $b] = $run;
    $lewat = $run[2] ?? false;
    $panjangA = hypot((float) $titik[$a + 1][0] - $titik[$a][0],
        (float) $titik[$a + 1][1] - $titik[$a][1]);
    $panjangB = hypot((float) $titik[$b + 1][0] - $titik[$b][0],
        (float) $titik[$b + 1][1] - $titik[$b][1]);
    $tAwal = $panjangA > 0.0
        ? max(0.0, $klaim[$a]['tmin'] - 0.5 * $langkah / $panjangA)
        : $klaim[$a]['tmin'];
    $tAkhir = $panjangB > 0.0
        ? min(1.0, $klaim[$b]['tmax'] + 0.5 * $langkah / $panjangB)
        : $klaim[$b]['tmax'];
    $potong = [titikPada($titik, $a, $tAwal)];
    if ($lewat) {
        for ($v = $a + 1; $v <= $jumlahSegmen; $v++) {
            $potong[] = $titik[$v];
        }
        for ($v = 1; $v <= $b; $v++) {
            $potong[] = $titik[$v];
        }
    } else {
        for ($v = $a + 1; $v <= $b; $v++) {
            $potong[] = $titik[$v];
        }
    }
    $potong[] = titikPada($titik, $b, $tAkhir);
    return rapatkan($potong);
}

$fiturKeluar = [];
$pendekBuang = 0;
$pendekPanjang = 0.0;
$kodeUrut = 0;
$statSumbu = [];
foreach ($pakai as $kodeSumbu => $perSisi) {
    foreach ($perSisi as $sisi => $perFitur) {
        foreach ($perFitur as $kunci => $klaim) {
            $data = $bahan[$kunci];
            $titik = $data['titik'];
            $jumlahSegmen = count($titik) - 1;
            foreach (untaian($klaim, (bool) $data['ring'], $jumlahSegmen) as $run) {
                $potong = potongUntaian($titik, $klaim, $run, $jumlahSegmen, $langkah);
                $p = panjang($potong);
                $statSumbu[$kodeSumbu]['run'] = ($statSumbu[$kodeSumbu]['run'] ?? 0) + 1;
                if ($p < MIN_TEPI) {
                    $pendekBuang++;
                    $pendekPanjang += $p;
                    $statSumbu[$kodeSumbu]['buang'] = ($statSumbu[$kodeSumbu]['buang'] ?? 0) + 1;
                    continue;
                }
                $statSumbu[$kodeSumbu]['baris'] = ($statSumbu[$kodeSumbu]['baris'] ?? 0) + 1;
                $kodeUrut++;
                $fiturKeluar[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'kode' => sprintf('TEPI-S%04d', $kodeUrut),
                        'sumbu' => $kodeSumbu,
                        'sisi' => $sisi,
                        'sumber' => BERKAS_SUMBER,
                        'asal' => $data['asal'],
                        'cara' => 'dari-sumbu',
                        'panjang_m' => round($p, 2),
                        'jumlah_titik' => count($potong),
                        'label' => 'JALAN',
                    ],
                    'geometry' => ['type' => 'LineString', 'coordinates' => $potong],
                ];
            }
        }
    }
}
$barisTerbit = count($fiturKeluar);
$meterTerbit = array_sum(array_map(static fn(array $f): float => $f['properties']['panjang_m'],
    $fiturKeluar));
printf("untaian: %d baris terbit · %s m · %d potong < %s m dibuang (%s m)\n",
    $barisTerbit, angkaNum($meterTerbit, 1),
    $pendekBuang, angkaNum(MIN_TEPI, 1), angkaNum($pendekPanjang, 1));

// ---------------------------------------------------------------------------
// Sambung baris per sumbu+sisi menjadi rantai menerus
// ---------------------------------------------------------------------------

$dasarSumbu = [];
foreach ($sumbu as $f) {
    $kum = [0.0];
    for ($i = 1; $i < count($f['titik']); $i++) {
        $kum[$i] = $kum[$i - 1] + hypot((float) $f['titik'][$i][0] - (float) $f['titik'][$i - 1][0],
            (float) $f['titik'][$i][1] - (float) $f['titik'][$i - 1][1]);
    }
    $dasarSumbu[$f['kode']] = ['titik' => $f['titik'], 'kum' => $kum];
}

$perGrup = [];
foreach ($fiturKeluar as $i => $f) {
    $kode = (string) $f['properties']['sumbu'];
    $dasar = $dasarSumbu[$kode];
    $c = $f['geometry']['coordinates'];
    $s0 = posisiSumbu($dasar['titik'], $dasar['kum'], (float) $c[0][0], (float) $c[0][1]);
    $s1 = posisiSumbu($dasar['titik'], $dasar['kum'],
        (float) $c[count($c) - 1][0], (float) $c[count($c) - 1][1]);
    $perGrup[$kode . '|' . $f['properties']['sisi']][] = ['i' => $i, 's' => ($s0 + $s1) / 2];
}

$sambungPasang = 0;
$sambungPanjang = 0.0;
$putusPasang = 0;
$putusPanjang = 0.0;
$rantai = [];
$rantaiGrup = [];
foreach ($perGrup as $kunciGrup => $anggota) {
    usort($anggota, static fn(array $x, array $y): int => $x['s'] <=> $y['s']);
    $jalur = null;
    $asal = null;
    $potongan = 0;
    $sambungM = 0.0;
    foreach ($anggota as $a) {
        $f = $fiturKeluar[$a['i']];
        $c = $f['geometry']['coordinates'];
        if ($jalur === null) {
            $jalur = $c;
            $asal = $f;
            $potongan = 1;
            continue;
        }
        $ujung = $jalur[count($jalur) - 1];
        $awalRuas = $c[0];
        $akhirRuas = $c[count($c) - 1];
        $keUjungAwal = hypot((float) $ujung[0] - (float) $awalRuas[0],
            (float) $ujung[1] - (float) $awalRuas[1]);
        $keUjungAkhir = hypot((float) $ujung[0] - (float) $akhirRuas[0],
            (float) $ujung[1] - (float) $akhirRuas[1]);
        $gap = min($keUjungAwal, $keUjungAkhir);
        if ($gap <= $langkah) {
            $sambungPasang++;
            $sambungPanjang += $gap;
            $sambungM += $gap;
            $potongan++;
            if ($keUjungAkhir < $keUjungAwal) {
                $c = array_reverse($c);
            }
            if ($gap <= 0.01) {
                array_shift($c);
            }
            foreach ($c as $p) {
                $jalur[] = $p;
            }
            continue;
        }
        $pangkal = $jalur[0];
        $kePangkalAwal = hypot((float) $pangkal[0] - (float) $awalRuas[0],
            (float) $pangkal[1] - (float) $awalRuas[1]);
        $kePangkalAkhir = hypot((float) $pangkal[0] - (float) $akhirRuas[0],
            (float) $pangkal[1] - (float) $akhirRuas[1]);
        $gapPangkal = min($kePangkalAwal, $kePangkalAkhir);
        if ($gapPangkal <= $langkah) {
            $sambungPasang++;
            $sambungPanjang += $gapPangkal;
            $sambungM += $gapPangkal;
            $potongan++;
            if ($kePangkalAwal <= $kePangkalAkhir) {
                $c = array_reverse($c);
            }
            if ($gapPangkal <= 0.01) {
                array_pop($c);
            }
            $jalur = array_merge($c, $jalur);
            continue;
        }
        $putusPasang++;
        $putusPanjang += min($gap, $gapPangkal);
        $rantai[] = [$jalur, $asal, $potongan, $sambungM];
        $rantaiGrup[$kunciGrup] = ($rantaiGrup[$kunciGrup] ?? 0) + 1;
        $jalur = $c;
        $asal = $f;
        $potongan = 1;
        $sambungM = 0.0;
    }
    if ($jalur !== null) {
        $rantai[] = [$jalur, $asal, $potongan, $sambungM];
        $rantaiGrup[$kunciGrup] = ($rantaiGrup[$kunciGrup] ?? 0) + 1;
    }
}

$fiturKeluar = [];
$kodeUrut = 0;
foreach ($rantai as [$jalur, $asal, $potongan, $sambungM]) {
    $jalur = rapatkan($jalur);
    $kodeUrut++;
    $fiturKeluar[] = [
        'type' => 'Feature',
        'properties' => [
            'kode' => sprintf('TEPI-S%04d', $kodeUrut),
            'sumbu' => $asal['properties']['sumbu'],
            'sisi' => $asal['properties']['sisi'],
            'sumber' => $asal['properties']['sumber'],
            'asal' => $asal['properties']['asal'],
            'cara' => 'dari-sumbu',
            'panjang_m' => round(panjang($jalur), 2),
            'jumlah_titik' => count($jalur),
            'potongan' => $potongan,
            'sambung_m' => round($sambungM, 2),
            'label' => 'JALAN',
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $jalur],
    ];
}
usort($fiturKeluar, static fn(array $x, array $y): int => [$x['properties']['sumbu'],
    $x['properties']['sisi']] <=> [$y['properties']['sumbu'], $y['properties']['sisi']]);
$kodeUrut = 0;
foreach ($fiturKeluar as &$f) {
    $kodeUrut++;
    $f['properties']['kode'] = sprintf('TEPI-S%04d', $kodeUrut);
}
unset($f);
printf("sambung: %d rantai dari %d baris · %d sambungan lurus %s m (ambang %s m)"
    . " · %d pasangan putus > ambang (%s m)\n",
    count($fiturKeluar), $barisTerbit, $sambungPasang, angkaNum($sambungPanjang, 1),
    angkaNum($langkah, 1), $putusPasang, angkaNum($putusPanjang, 1));
printf("grup sumbu+sisi: %d · utuh satu rantai: %d · masih terputus: %d\n",
    count($rantaiGrup), count(array_filter($rantaiGrup, static fn(int $n): bool => $n === 1)),
    count(array_filter($rantaiGrup, static fn(int $n): bool => $n > 1)));

$sumbuBerbaris = [];
foreach ($fiturKeluar as $f) {
    $sumbuBerbaris[$f['properties']['sumbu']] = true;
}
printf("sumbu berbaris: %d dari %d\n", count($sumbuBerbaris), count($sumbu));
$tanpaBaris = [];
foreach ($cakupan as $kode => $c) {
    if (!isset($sumbuBerbaris[$kode]) && ($c['kiri'] + $c['kanan']) > 0.0) {
        $tanpaBaris[] = sprintf('%s %s m cakupan %.0f%%/%.0f%%', $kode,
            angkaNum($c['panjang'], 1), $c['kiri'] * 100, $c['kanan'] * 100);
    }
}
printf("sumbu tanpa baris padahal ada sisi ditemukan: %d\n", count($tanpaBaris));
foreach (array_slice($tanpaBaris, 0, 10) as $baris) {
    echo '  ' . $baris . PHP_EOL;
}

if ($tanya !== null) {
    if (!isset($cakupan[$tanya])) {
        galat('sumbu tidak ada: ' . $tanya);
    }
    $c = $cakupan[$tanya];
    printf("\n== %s (%s) %s m · cakupan kiri %.0f%% kanan %.0f%% · untaian %d baris %d dibuang\n",
        $tanya, $c['kelas'], angkaNum($c['panjang'], 1), $c['kiri'] * 100, $c['kanan'] * 100,
        $statSumbu[$tanya]['baris'] ?? 0, $statSumbu[$tanya]['buang'] ?? 0);
    foreach (($pakai[$tanya] ?? []) as $sisi => $perFitur) {
        $jumlahSegmenSisi = 0;
        $jumlahSampelSisi = 0;
        $panjangSisi = 0.0;
        foreach ($perFitur as $kunci => $klaim) {
            $titik = $bahan[$kunci]['titik'];
            foreach ($klaim as $i => $x) {
                $jumlahSegmenSisi++;
                $jumlahSampelSisi += $x['n'];
                $panjangSisi += hypot((float) $titik[$i + 1][0] - $titik[$i][0],
                    (float) $titik[$i + 1][1] - $titik[$i][1]);
            }
        }
        printf("  %s: %d fitur · %d segmen · %s m klaim · %d sampel\n",
            $sisi, count($perFitur), $jumlahSegmenSisi, angkaNum($panjangSisi, 1), $jumlahSampelSisi);
        if ($rinci) {
            foreach ($perFitur as $kunci => $klaim) {
                $data = $bahan[$kunci];
                $titik = $data['titik'];
                $jumlahSegmen = count($titik) - 1;
                ksort($klaim);
                $potongan = [];
                foreach ($klaim as $i => $x) {
                    $potongan[] = sprintf('%d[%.2f-%.2f n%d %sm]', $i, $x['tmin'], $x['tmax'],
                        $x['n'], angkaNum(hypot((float) $titik[$i + 1][0] - $titik[$i][0],
                            (float) $titik[$i + 1][1] - $titik[$i][1]), 1));
                }
                $hasil = [];
                foreach (untaian($klaim, (bool) $data['ring'], $jumlahSegmen) as $run) {
                    $p = panjang(potongUntaian($titik, $klaim, $run, $jumlahSegmen, $langkah));
                    $hasil[] = sprintf('%d..%d%s %sm%s', $run[0], $run[1],
                        ($run[2] ?? false) ? ' lewat' : '', angkaNum($p, 1),
                        $p < MIN_TEPI ? ' BUANG' : '');
                }
                printf("    %s %s %d segmen: %s\n", $kunci, $data['asal'], $jumlahSegmen,
                    implode(' ', $potongan));
                printf("      -> %s\n", implode(' · ', $hasil));
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Laporan cakupan per kelas
// ---------------------------------------------------------------------------

$lapor = [];
foreach ($cakupan as $kode => $c) {
    $lapor[$c['kelas']]['sumbu'] = ($lapor[$c['kelas']]['sumbu'] ?? 0) + 1;
    $lapor[$c['kelas']]['panjang'] = ($lapor[$c['kelas']]['panjang'] ?? 0.0) + $c['panjang'];
    $dua = $c['kiri'] >= 0.5 && $c['kanan'] >= 0.5;
    $satu = !$dua && ($c['kiri'] > 0.0 || $c['kanan'] > 0.0);
    $nol = !$dua && !$satu;
    $lapor[$c['kelas']]['dua'] = ($lapor[$c['kelas']]['dua'] ?? 0) + ($dua ? 1 : 0);
    $lapor[$c['kelas']]['satu'] = ($lapor[$c['kelas']]['satu'] ?? 0) + ($satu ? 1 : 0);
    $lapor[$c['kelas']]['nol'] = ($lapor[$c['kelas']]['nol'] ?? 0) + ($nol ? 1 : 0);
}
foreach ($lapor as $kelas => $c) {
    printf("cakupan %-7s: %d sumbu / %s m · dua sisi >=50%%: %d · satu sisi: %d · tanpa sisi: %d\n",
        $kelas, $c['sumbu'], angkaNum($c['panjang'], 1), $c['dua'], $c['satu'], $c['nol']);
}

$semuaJarak = [];
foreach ($pakai as $perSisi) {
    foreach ($perSisi as $perFitur) {
        foreach ($perFitur as $klaim) {
            foreach ($klaim as $c) {
                $semuaJarak[] = $c['jarak'];
            }
        }
    }
}
sort($semuaJarak);
if ($semuaJarak !== []) {
    printf("jarak klaim: median %s m · maksimum %s m\n",
        angkaNum($semuaJarak[(int) floor(count($semuaJarak) / 2)], 2),
        angkaNum($semuaJarak[count($semuaJarak) - 1], 2));
}

if ($rinci) {
    uasort($cakupan, static fn(array $a, array $b): int => ($a['kiri'] + $a['kanan']) <=> ($b['kiri'] + $b['kanan']));
    echo "25 sumbu cakupan terlemah (kiri / kanan):\n";
    $n = 0;
    foreach ($cakupan as $kode => $c) {
        if ($n++ >= 25) {
            break;
        }
        printf("  %-12s %-7s %s m · %.0f%% / %.0f%%\n", $kode, $c['kelas'],
            angkaNum($c['panjang'], 1), $c['kiri'] * 100, $c['kanan'] * 100);
    }
}

// ---------------------------------------------------------------------------
// Banding dengan tepi terbit lama
// ---------------------------------------------------------------------------

$pathLama = DIR_LAPISAN . '/' . BERKAS_TEPI_LAMA . '.geojson';
if (is_file($pathLama)) {
    $kisiBaru = [];
    $segmenBaru = [];
    foreach ($fiturKeluar as $f) {
        tambahSegmen($kisiBaru, $segmenBaru, $f['geometry']['coordinates'], 'baru', SEL);
    }
    $cocok = 0;
    $jumlahTitik = 0;
    $lamaPanjang = 0.0;
    foreach (geojsonBaca($pathLama) as $f) {
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) ($f['geometry']['coordinates'] ?? []));
        if (count($titik) < 2) {
            continue;
        }
        $lamaPanjang += panjang($titik);
        foreach (sampel($titik, $langkah) as [$x, $y]) {
            $jumlahTitik++;
            $batas = 1;
            $kena = false;
            $gx = (int) floor($x / SEL);
            $gy = (int) floor($y / SEL);
            for ($ix = $gx - $batas; $ix <= $gx + $batas && !$kena; $ix++) {
                for ($iy = $gy - $batas; $iy <= $gy + $batas && !$kena; $iy++) {
                    foreach (array_keys($kisiBaru[$ix . ':' . $iy] ?? []) as $id) {
                        $s = $segmenBaru[$id];
                        $dx = $s[2] - $s[0];
                        $dy = $s[3] - $s[1];
                        $pan = $dx * $dx + $dy * $dy;
                        $t = $pan > 0.0 ? max(0.0, min(1.0, (($x - $s[0]) * $dx + ($y - $s[1]) * $dy) / $pan)) : 0.0;
                        if (hypot($x - ($s[0] + $t * $dx), $y - ($s[1] + $t * $dy)) <= 0.5) {
                            $kena = true;
                            break;
                        }
                    }
                }
            }
            if ($kena) {
                $cocok++;
            }
        }
    }
    printf("banding tepi lama: %d baris / %s m · titik lama yang punya pasangan baru <=0,5 m: %.1f%%\n",
        count(geojsonBaca($pathLama)), angkaNum($lamaPanjang, 1),
        $jumlahTitik > 0 ? $cocok * 100 / $jumlahTitik : 0.0);
}

// ---------------------------------------------------------------------------
// Tulis
// ---------------------------------------------------------------------------

if ($terapkan) {
    tulisBerkas(BERKAS_KELUAR, $fiturKeluar);
} else {
    echo 'uji kering: berkas tidak ditulis; tambahkan --terapkan.' . PHP_EOL;
}
