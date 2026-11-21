<?php
declare(strict_types=1);

/**
 * Sumbu sungai baru dari dua tepi perairan yang berhadapan.
 *
 *   php -d memory_limit=1024M tools/sumbu-dari-dua-tepi.php            # uji kering
 *   php -d memory_limit=1024M tools/sumbu-dari-dua-tepi.php --terapkan
 *
 *   --lebar=M          lebar koridor maksimum saat penelusuran; bawaan 20,0 m
 *   --lebar-min=M      lebar koridor minimum; bawaan 3,0 m
 *   --seed-lebar=M     lebar maksimum dua tepi penyeed; bawaan 20,0 m
 *   --seed-jarak=M     jarak minimum penyeed dari sumbu yang sudah tersaji;
 *                      bawaan 10,0 m
 *   --langkah=N        jumlah langkah penelusuran per arah; bawaan 300
 *                      (satu langkah 2,0 m, jadi tiap arah <= 600 m)
 *   --keluar=NAMA      nama berkas keluaran tanpa .geojson; bawaan
 *                      garis-sungai-dua-tepi
 *   --tanya=X,Y        cetak jejak penelusuran penyeed terdekat (<= 1 m)
 *   --cetak            cetak FeatureCollection hasil ke stdout (tanpa menulis)
 *   --rinci            cetak 40 rantai terpanjang
 *
 * Latar. Sumbu sungai yang tersaji (garis-sungai-jahit.geojson) diturunkan
 * dari LABEL sungai, jadi bentang tanpa label tidak pernah punya sumbu walau
 * kedua tepinya sudah tergambar. Ukur 21 Nov 2026: 1.290 sampel titik tengah
 * dua tepi perairan berhadapan selebar 3-20 m, 436 sampel (33,8 %) berjarak
 * lebih dari 10 m dari sumbu tersaji; dipadatkan 10 m tinggal 137 titik
 * (lebar median 8,1 m) di sekitar 20 kerumunan.
 *
 * Bahan. `garis-perairan.geojson` (143 rantai tepi alur, 15.010,9 m) sebagai
 * tepi penyeed sekaligus tepi penelusuran; cincin persil dari tabel `bidang`
 * (dibaca saja) hanya untuk uji "titik tengah masuk persil";
 * `garis-sungai-jahit.geojson` sebagai sumbu tersaji yang harus dihindari;
 * `batas-perairan.geojson` hanya untuk memberi nama label pada rantai keluaran.
 *
 * Penyeed. Tiap 5 m sepanjang tiap rantai tepi perairan dicari satu segmen
 * lain yang sejajar (<= 25 derajat), berhadapan (tegak lurus), dan berjarak
 * 3-20 m; yang terdekat dipakai. Titik tengah kedua titik nyata itu menjadi
 * penyeed bila tidak jatuh di dalam persil dan lebih dari --seed-jarak dari
 * sumbu tersaji. Penyeed dipadatkan satu per sel 10 m, lalu diproses dari yang
 * terjauh dari sumbu tersaji.
 *
 * Penelusuran. Tiap langkah 2,0 m dicari pasangan tepi perairan kiri-kanan
 * dengan arah <= 25 derajat, lebar 3-20 m, kedua sisi setimbang, titik tengah
 * tidak masuk persil. Hanya tepi perairan yang dipakai: kalau penelusuran
 * dibiarkan memakai tepi persil/020100, ia keluar dari alur dan menyusuri
 * sambungan sawah yang lurus (itu yang terjadi pada uji pertama 21 Nov).
 * Rantai berhenti bila tidak ada pasangan tepi perairan, titik tengah bergeser
 * lebih dari 3,5 m, titik tengah masuk persil (lewat 2 m dari penyeed), atau
 * menyentuh rantai yang sudah diterima (<= 1,2 m). Penyeed yang sudah berada
 * <= 10 m dari rantai diterima dilewati; rantai baru yang >= 60 % sampelnya
 * <= 3 m dari rantai lama dibuang sebagai kembar. Langkah yang kembali ke
 * titik <= 0,6 m yang baru dilalui menghentikan penelusuran, dan rantai yang
 * panjangnya lebih dari 10x sebaran kotaknya dibuang sebagai melipat: tanpa
 * dua aturan itu uji 21 Nov melahirkan rantai 304 m yang bolak-balik di satu
 * titik selebar 1 m.
 *
 * Keluaran satu berkas: `garis-sungai-dua-tepi.geojson` (meter DXF lokal), satu
 * LineString per rantai >= 5 m, properti `kode` (SUNGAI-DUATEPI-####),
 * `cara` (dua-tepi-alur), `panjang_m`, `lebar_m`, `lebar_min_m`,
 * `jumlah_bagian`, `jumlah_tepi`, `tepi_asal`, `label`, `jumlah_label`,
 * `jarak_sumbu_lama_m`, dan `penyeed` (x,y). Metadata mendapat entri
 * SUMBU SUNGAI DARI DUA TEPI. Berkas ini adalah BAHAN tambahan: lapis tersaji
 * tetap garis-sungai-jahit.geojson yang dijahit ulang dari label + dua tepi.
 *
 * Uji kering adalah bawaan; --terapkan menulis berkas keluaran (berkas lama
 * dicadangkan lebih dulu ke _lama/cadangan-garis-sungai-dua-tepi-<stempel>/)
 * dan memperbarui metadata.json. Tidak ada satu baris pun yang ditulis ke
 * basis data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_BANK = 'garis-perairan';
const BERKAS_SUMBU = 'garis-sungai-jahit';
const BERKAS_LABEL = 'batas-perairan';
const BERKAS_KELUAR = 'garis-sungai-dua-tepi';
const JUDUL_KELUAR = 'SUMBU SUNGAI DARI DUA TEPI';

/** Kisi dan sampel, sama dengan alat label jalan/sungai supaya angka bisa dibandingkan. */
const SEL = 25.0;
const SEL_KISI = 5.0;
const LANGKAH = 2.0;
const SUDUT = 25.0;
const LEBAR_MIN = 3.0;
const LEBAR_MAKS = 60.0;
const SEED_LEBAR = 20.0;
const SEED_JARAK = 10.0;
const SEED_LANGKAH = 5.0;
const SEED_TEGAK = 0.7;
const TIMBANG = 0.35;
const SEED_SEL = 10.0;
const MAJU = 3.5;
const BIDANG_TOL = 2.0;
const PANGKAS = 1.2;
const LANGKAH_MAKS = 300;
const MIN_PANJANG = 5.0;
const KEMBAR_SAMPEL = 3.0;
const KEMBAR_BAGI = 0.6;
const LABEL_RADIUS = 15.0;
/** Kotak desa; label di luar kotak (koordinat rusak) dilewati. */
const KOTAK = [200000.0, 700000.0, 300000.0, 900000.0];

$terapkan = in_array('--terapkan', $argv, true);
$rinci = false;
$cetak = false;
$lebar = SEED_LEBAR;
$lebarMin = LEBAR_MIN;
$seedLebar = SEED_LEBAR;
$seedJarak = SEED_JARAK;
$langkahMaks = LANGKAH_MAKS;
$keluar = BERKAS_KELUAR;
$tanya = null;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--terapkan') {
        $terapkan = true;
    } elseif ($a === '--rinci') {
        $rinci = true;
    } elseif ($a === '--cetak') {
        $cetak = true;
    } elseif (str_starts_with($a, '--tanya=')) {
        $bagian = explode(',', substr($a, 8));
        if (count($bagian) !== 2 || !is_numeric($bagian[0]) || !is_numeric($bagian[1])) {
            galat('--tanya harus berbentuk X,Y angka');
        }
        $tanya = [(float) $bagian[0], (float) $bagian[1]];
    } elseif (str_starts_with($a, '--lebar-min=')) {
        $lebarMin = angkaFlag(substr($a, 12), '--lebar-min');
    } elseif (str_starts_with($a, '--lebar=')) {
        $lebar = angkaFlag(substr($a, 8), '--lebar');
    } elseif (str_starts_with($a, '--seed-lebar=')) {
        $seedLebar = angkaFlag(substr($a, 13), '--seed-lebar');
    } elseif (str_starts_with($a, '--seed-jarak=')) {
        $seedJarak = angkaFlag(substr($a, 13), '--seed-jarak');
    } elseif (str_starts_with($a, '--langkah=')) {
        $langkahMaks = (int) angkaFlag(substr($a, 10), '--langkah');
    } elseif (str_starts_with($a, '--keluar=')) {
        $keluar = substr($a, 9);
    } else {
        galat('argumen tidak dikenal: ' . $a);
    }
}
if ($lebar <= $lebarMin || $lebarMin < 1.0) {
    galat('--lebar harus lebih besar dari --lebar-min, dan --lebar-min >= 1');
}
if ($seedLebar < $lebarMin || $seedLebar > LEBAR_MAKS) {
    galat('--seed-lebar harus di antara --lebar-min dan ' . angkaNum(LEBAR_MAKS, 0) . ' m');
}
if ($lebar > LEBAR_MAKS) {
    galat('--lebar maksimum ' . angkaNum(LEBAR_MAKS, 0) . ' m');
}
if ($langkahMaks < 4) {
    galat('--langkah harus >= 4');
}

function galat(string $pesan): never
{
    fwrite(STDERR, 'GAGAL: ' . $pesan . PHP_EOL);
    exit(1);
}

function angkaNum(float $n, int $desimal = 0): string
{
    return number_format($n, $desimal, ',', '.');
}

function angkaFlag(string $nilai, string $nama): float
{
    $bersih = str_replace(',', '.', trim($nilai));
    if ($bersih === '' || !is_numeric($bersih)) {
        galat($nama . ' harus angka: ' . $nilai);
    }
    return (float) $bersih;
}

function bedaArah(float $a, float $b): float
{
    $beda = abs(fmod($a - $b, 180.0));
    return $beda > 90.0 ? 180.0 - $beda : $beda;
}

function tambahSegmen(array &$kisi, array &$segmen, array $titik, float $sel, mixed ...$tambahan): void
{
    for ($i = 0; $i + 1 < count($titik); $i++) {
        $id = count($segmen);
        $x1 = (float) $titik[$i][0];
        $y1 = (float) $titik[$i][1];
        $x2 = (float) $titik[$i + 1][0];
        $y2 = (float) $titik[$i + 1][1];
        $segmen[$id] = [$x1, $y1, $x2, $y2, rad2deg(atan2($y2 - $y1, $x2 - $x1))];
        foreach ($tambahan as $t) {
            $segmen[$id][] = $t;
        }
        for ($gx = (int) floor(min($x1, $x2) / $sel); $gx <= (int) floor(max($x1, $x2) / $sel); $gx++) {
            for ($gy = (int) floor(min($y1, $y2) / $sel); $gy <= (int) floor(max($y1, $y2) / $sel); $gy++) {
                $kisi[$gx . ':' . $gy][$id] = true;
            }
        }
    }
}

function jarakSegmen(array $kisi, array $segmen, float $px, float $py, float $radius, float $sel): ?array
{
    $batas = (int) ceil($radius / $sel);
    $gx = (int) floor($px / $sel);
    $gy = (int) floor($py / $sel);
    $terbaik = INF;
    $hasil = null;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                $s = $segmen[$id];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                $t = $pan > 0.0 ? max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan)) : 0.0;
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $d = hypot($px - $fx, $py - $fy);
                if ($d < $terbaik) {
                    $terbaik = $d;
                    $hasil = [$d, $fx, $fy, (float) $s[4], $id];
                }
            }
        }
    }
    return $terbaik <= $radius ? $hasil : null;
}

function sampel(array $titik): array
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
        $n = max(1, (int) floor($panjang / LANGKAH));
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

function diPoligon(float $x, float $y, array $kisi, array $poligon, array $besar): bool
{
    $gx = (int) floor($x / SEL_KISI);
    $gy = (int) floor($y / SEL_KISI);
    foreach (array_keys($kisi[$gx . ':' . $gy] ?? []) as $id) {
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

/** Cincin luar sebuah Polygon/MultiPolygon GeoJSON, diturunkan ke meter DXF. */
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

/** Kelas teks label; hanya sungai yang dipakai untuk memberi nama keluaran. */
function kelasSungai(string $teks): bool
{
    return preg_match('/SUNGAI|KALI|BENGKONG/u', mb_strtoupper($teks)) === 1;
}

printf("mode: %s · koridor %s-%s m · penyeed %s-%s m (> %s m dari sumbu) · langkah %s x %s m\n",
    $terapkan ? 'TERAPKAN' : 'uji kering', angkaNum($lebarMin, 1), angkaNum($lebar, 1),
    angkaNum($lebarMin, 1), angkaNum($seedLebar, 1), angkaNum($seedJarak, 1),
    angkaNum((float) $langkahMaks), angkaNum(LANGKAH, 1));

// ---------------------------------------------------------------------------
// Bahan: permukaan persil, label, bank, dan sumbu tersaji
// ---------------------------------------------------------------------------

$kandidat = [];
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
printf("persil dari basis data (dibaca saja): %d cincin · kisi permukaan %d petak\n",
    $jumlahPersil, count($kisiPoligon));

$label = [];
$dilewati = 0;
foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_LABEL . '.geojson') as $f) {
    $teks = trim((string) ($f['properties']['label'] ?? ''));
    $g = $f['geometry'] ?? [];
    if (($g['type'] ?? '') !== 'Point' || !kelasSungai($teks)) {
        continue;
    }
    $x = (float) $g['coordinates'][0];
    $y = (float) $g['coordinates'][1];
    if ($x < KOTAK[0] || $x > KOTAK[2] || $y < KOTAK[1] || $y > KOTAK[3]) {
        $dilewati++;
        continue;
    }
    $label[] = [$teks, $x, $y];
}
printf("label sungai (hanya untuk nama): %d titik%s\n", count($label),
    $dilewati > 0 ? ', ' . $dilewati . ' di luar kotak desa dilewati' : '');

$bank = [];
$bankKisi = [];
$bankSeg = [];
$bankPanjang = 0.0;
foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_BANK . '.geojson') as $i => $f) {
    $g = $f['geometry'] ?? [];
    $bagian = match ((string) ($g['type'] ?? '')) {
        'LineString' => [(array) ($g['coordinates'] ?? [])],
        'MultiLineString' => (array) ($g['coordinates'] ?? []),
        default => [],
    };
    foreach ($bagian as $j => $c) {
        if (count($c) < 2) {
            continue;
        }
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) $c);
        $kunci = 'B:' . (int) $i . ':' . (int) $j;
        $bank[$kunci] = ['titik' => $titik];
        $kandidat[$kunci] = ['titik' => $titik, 'asal' => 'perairan'];
        tambahSegmen($bankKisi, $bankSeg, $titik, SEL_KISI, $kunci);
        $bankPanjang += panjang($titik);
    }
}
printf("bank perairan: %d rantai · %s m · %d segmen\n", count($bank),
    angkaNum($bankPanjang, 1), count($bankSeg));
if ($bankSeg === []) {
    galat('garis-perairan.geojson kosong: tidak ada tepi penyeed');
}

$kisiAlur = [];
$segmenAlur = [];
$kisiLama = [];
$segmenLama = [];
$sumbuLama = 0;
$sumbuLamaPanjang = 0.0;
foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_SUMBU . '.geojson') as $f) {
    $g = $f['geometry'] ?? [];
    if (($g['type'] ?? '') !== 'LineString' || count((array) ($g['coordinates'] ?? [])) < 2) {
        continue;
    }
    $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
        (array) $g['coordinates']);
    tambahSegmen($kisiAlur, $segmenAlur, $titik, SEL_KISI);
    tambahSegmen($kisiLama, $segmenLama, $titik, SEL_KISI);
    $sumbuLama++;
    $sumbuLamaPanjang += panjang($titik);
}
printf("sumbu tersaji yang dihindari: %d rantai · %s m\n", $sumbuLama,
    angkaNum($sumbuLamaPanjang, 1));
if ($sumbuLama === 0) {
    galat('garis-sungai-jahit.geojson kosong: tidak ada sumbu pembanding');
}

// ---------------------------------------------------------------------------
// Penyeed dan penelusuran
// ---------------------------------------------------------------------------

$searah = static function (float $h, float $acuan): float {
    return $h + 180.0 * round(($acuan - $h) / 180.0);
};
$rataArah = static function (float $h1, float $h2, float $acuan) use ($searah): float {
    return rad2deg(atan2(
        sin(deg2rad($searah($h1, $acuan))) + sin(deg2rad($searah($h2, $acuan))),
        cos(deg2rad($searah($h1, $acuan))) + cos(deg2rad($searah($h2, $acuan)))));
};

/** Dua tepi perairan kiri/kanan terdekat pada satu titik dan arah; tepi yang sedang dipakai didahulukan. */
$pasang = static function (float $px, float $py, float $ax, float $ay, ?array $acuan = null)
    use ($bankKisi, $bankSeg, $lebar, $lebarMin, $kisiPoligon, $poligon, $poligonBesar): ?array {
    $nx = -$ay;
    $ny = $ax;
    $arah = rad2deg(atan2($ay, $ax));
    $batas = (int) ceil($lebar / SEL_KISI);
    $gx = (int) floor($px / SEL_KISI);
    $gy = (int) floor($py / SEL_KISI);
    $sisi = ['kiri' => [], 'kanan' => []];
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($bankKisi[$ix . ':' . $iy] ?? []) as $sid) {
                $s = $bankSeg[$sid];
                if (bedaArah($arah, (float) $s[4]) > SUDUT) {
                    continue;
                }
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                $t = $pan > 0.0
                    ? max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan)) : 0.0;
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $d = hypot($px - $fx, $py - $fy);
                if ($d > $lebar) {
                    continue;
                }
                $lagu = (($px - $fx) * $nx + ($py - $fy) * $ny) > 0.0 ? 'kiri' : 'kanan';
                $sisi[$lagu][] = ['d' => $d, 'x' => $fx, 'y' => $fy, 'arah' => (float) $s[4],
                    'kunci' => (string) $s[5]];
            }
        }
    }
    foreach (['kiri', 'kanan'] as $lagu) {
        usort($sisi[$lagu], static fn(array $a, array $b): int => $a['d'] <=> $b['d']);
        $sisi[$lagu] = array_slice($sisi[$lagu], 0, 24);
    }
    $kini = [];
    $lanjut = [];
    foreach ($sisi['kiri'] as $k) {
        foreach ($sisi['kanan'] as $n) {
            if ($k['kunci'] === $n['kunci']) {
                continue;
            }
            $w = hypot($k['x'] - $n['x'], $k['y'] - $n['y']);
            if ($w < $lebarMin || $w > $lebar) {
                continue;
            }
            if (abs($k['d'] - $n['d']) > TIMBANG * $w) {
                continue;
            }
            if (bedaArah($k['arah'], $n['arah']) > SUDUT) {
                continue;
            }
            $mx = ($k['x'] + $n['x']) / 2;
            $my = ($k['y'] + $n['y']) / 2;
            $butir = [$mx, $my, $k, $n];
            $kini[] = [$butir, hypot($mx - $px, $my - $py)];
            if ($acuan !== null && (in_array($k['kunci'], $acuan, true)
                || in_array($n['kunci'], $acuan, true))) {
                $lanjut[] = [$butir, hypot($mx - $px, $my - $py)];
            }
        }
    }
    $urut = static fn(array $a, array $b): int => $a[1] <=> $b[1];
    usort($kini, $urut);
    usort($lanjut, $urut);
    foreach ([$lanjut, $kini] as $daftar) {
        foreach ($daftar as [$butir]) {
            [$mx, $my] = $butir;
            if (!diPoligon($mx, $my, $kisiPoligon, $poligon, $poligonBesar)) {
                return [$butir[2], $butir[3]];
            }
        }
    }
    return null;
};

// Penyeed: titik tengah dua tepi perairan yang berhadapan, di luar koridor bersumbu.
$jarakAlur = static function (float $x, float $y) use ($segmenAlur): float {
    $terbaik = INF;
    foreach ($segmenAlur as $s) {
        $dx = $s[2] - $s[0];
        $dy = $s[3] - $s[1];
        $pan = $dx * $dx + $dy * $dy;
        $t = $pan > 0.0 ? max(0.0, min(1.0, (($x - $s[0]) * $dx + ($y - $s[1]) * $dy) / $pan)) : 0.0;
        $d = hypot($x - ($s[0] + $t * $dx), $y - ($s[1] + $t * $dy));
        $terbaik = min($terbaik, $d);
    }
    return $terbaik;
};
$seedKasar = [];
$sampelBank = 0;
foreach ($bank as $kunciBank => $b) {
    $titik = $b['titik'];
    for ($i = 1; $i < count($titik); $i++) {
        $x1 = (float) $titik[$i - 1][0];
        $y1 = (float) $titik[$i - 1][1];
        $x2 = (float) $titik[$i][0];
        $y2 = (float) $titik[$i][1];
        $panjangRuas = hypot($x2 - $x1, $y2 - $y1);
        if ($panjangRuas <= 0.0) {
            continue;
        }
        $ux = ($x2 - $x1) / $panjangRuas;
        $uy = ($y2 - $y1) / $panjangRuas;
        $arahBank = rad2deg(atan2($uy, $ux));
        $nx = -$uy;
        $ny = $ux;
        $n = max(1, (int) round($panjangRuas / SEED_LANGKAH));
        for ($s = 0; $s < $n; $s++) {
            $t = $s / $n;
            $px = $x1 + $t * ($x2 - $x1);
            $py = $y1 + $t * ($y2 - $y1);
            $sampelBank++;
            $terbaik = null;
            $batas = (int) ceil($seedLebar / SEL_KISI);
            $gx = (int) floor($px / SEL_KISI);
            $gy = (int) floor($py / SEL_KISI);
            for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
                for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
                    foreach (array_keys($bankKisi[$ix . ':' . $iy] ?? []) as $sid) {
                        $ss = $bankSeg[$sid];
                        if ((string) $ss[5] === $kunciBank) {
                            continue;
                        }
                        if (bedaArah($arahBank, (float) $ss[4]) > SUDUT) {
                            continue;
                        }
                        $dx = $ss[2] - $ss[0];
                        $dy = $ss[3] - $ss[1];
                        $pan = $dx * $dx + $dy * $dy;
                        $tt = $pan > 0.0
                            ? max(0.0, min(1.0, (($px - $ss[0]) * $dx + ($py - $ss[1]) * $dy) / $pan))
                            : 0.0;
                        $fx = $ss[0] + $tt * $dx;
                        $fy = $ss[1] + $tt * $dy;
                        $d = hypot($px - $fx, $py - $fy);
                        if ($d < $lebarMin || $d > $seedLebar) {
                            continue;
                        }
                        $dot = ($fx - $px) * $nx + ($fy - $py) * $ny;
                        if (abs($dot) / max($d, 1e-9) < SEED_TEGAK) {
                            continue;
                        }
                        if ($terbaik === null || $d < $terbaik['d']) {
                            $terbaik = ['d' => $d, 'x' => $fx, 'y' => $fy, 'arah' => (float) $ss[4],
                                'kunci' => (string) $ss[5]];
                        }
                    }
                }
            }
            if ($terbaik === null) {
                continue;
            }
            $w = hypot($terbaik['x'] - $px, $terbaik['y'] - $py);
            $mx = ($px + $terbaik['x']) / 2;
            $my = ($py + $terbaik['y']) / 2;
            if (diPoligon($mx, $my, $kisiPoligon, $poligon, $poligonBesar)) {
                continue;
            }
            $dekatSumbu = jarakSegmen($kisiAlur, $segmenAlur, $mx, $my, $seedJarak * 3.0, SEL_KISI);
            if ($dekatSumbu !== null && $dekatSumbu[0] <= $seedJarak) {
                continue;
            }
            $dAlur = $dekatSumbu !== null ? $dekatSumbu[0] : $jarakAlur($mx, $my);
            $seedKasar[] = ['x' => $mx, 'y' => $my,
                'arah' => $rataArah($arahBank, $terbaik['arah'], $arahBank),
                'pasangan' => [['x' => $px, 'y' => $py, 'arah' => $arahBank, 'kunci' => $kunciBank],
                    ['x' => $terbaik['x'], 'y' => $terbaik['y'], 'arah' => $terbaik['arah'],
                        'kunci' => $terbaik['kunci']]],
                'd' => $dAlur, 'w' => $w,
                'bank' => $kunciBank];
        }
    }
}

$selSeed = [];
foreach ($seedKasar as $s) {
    $kunciSel = (int) round($s['x'] / SEED_SEL) . ':' . (int) round($s['y'] / SEED_SEL);
    if (!isset($selSeed[$kunciSel]) || $s['d'] > $selSeed[$kunciSel]['d']) {
        $selSeed[$kunciSel] = $s;
    }
}
$seedDaftar = array_values($selSeed);
usort($seedDaftar, static fn(array $a, array $b): int => $b['d'] <=> $a['d']);
printf("penyeed dua tepi: %d sampel bank · %d titik mentah · %d sel 10 m (terjauh %s m dari sumbu)\n",
    $sampelBank, count($seedKasar), count($seedDaftar),
    $seedDaftar === [] ? '-' : angkaNum($seedDaftar[0]['d'], 1));

$rantai = [];
$sisa = [];
$kembar = 0;
$kembarPanjang = 0.0;
$diproses = 0;
$dilewatiDekat = 0;
$berhentiSemua = [];

foreach ($seedDaftar as $seed) {
    if (jarakSegmen($kisiAlur, $segmenAlur, $seed['x'], $seed['y'], $seedJarak, SEL_KISI) !== null) {
        $dilewatiDekat++;
        continue;
    }
    $diproses++;
    $jejak = $tanya !== null && hypot($seed['x'] - $tanya[0], $seed['y'] - $tanya[1]) <= 1.0;
    if ($jejak) {
        printf("TANYA penyeed (%.1f, %.1f) arah %.1f · lebar %.1f · %s/%s · %s m dari sumbu\n",
            $seed['x'], $seed['y'], $seed['arah'], $seed['w'],
            $seed['pasangan'][0]['kunci'], $seed['pasangan'][1]['kunci'], angkaNum($seed['d'], 1));
    }

    // Acuan = dua rantai bank penyeed; penelusuran tetap di antara tepi perairan itu.
    $acuanAwal = [$seed['pasangan'][0]['kunci'], $seed['pasangan'][1]['kunci']];

    $kunciPakai = [];
    $wSum = 0.0;
    $wN = 0;
    $wMin = INF;
    $berhenti = [];
    $ox = $seed['x'];
    $oy = $seed['y'];

    $jalan = static function (array $mulai, float $arahAwal, array $pasanganAwal, ?array $acuanAwal,
        float $ox, float $oy) use ($pasang, $rataArah, $kisiPoligon, $poligon, $poligonBesar, $kisiAlur,
        $segmenAlur, &$kunciPakai, &$wSum, &$wN, &$wMin, &$berhenti, $langkahMaks, $jejak): array {
        $p = $mulai;
        $terakhir = $mulai;
        $arahKini = $arahAwal;
        $titik = [];
        $pas = $pasanganAwal;
        $acuan = $acuanAwal ?? [$pasanganAwal[0]['kunci'], $pasanganAwal[1]['kunci']];
        for ($i = 0; $i < $langkahMaks; $i++) {
            if ($i > 0) {
                $pas = $pasang($p[0], $p[1], cos(deg2rad($arahKini)), sin(deg2rad($arahKini)), $acuan);
            }
            if ($pas === null) {
                $berhenti['tanpa sisi'] = ($berhenti['tanpa sisi'] ?? 0) + 1;
                if ($jejak) {
                    printf("  langkah %d di (%.1f, %.1f) arah %.1f: tanpa sisi\n", $i, $p[0], $p[1],
                        $arahKini);
                }
                break;
            }
            [$k, $n] = $pas;
            $acuan = [$k['kunci'], $n['kunci']];
            $mx = ($k['x'] + $n['x']) / 2;
            $my = ($k['y'] + $n['y']) / 2;
            if ($i > 0) {
                $balik = false;
                for ($b = max(0, count($titik) - 2); $b < count($titik); $b++) {
                    if (hypot($mx - $titik[$b][0], $my - $titik[$b][1]) < 0.6) {
                        $balik = true;
                        break;
                    }
                }
                if ($balik) {
                    $berhenti['bolak-balik'] = ($berhenti['bolak-balik'] ?? 0) + 1;
                    if ($jejak) {
                        printf("  langkah %d di (%.1f, %.1f): kembali ke titik lama\n", $i, $mx, $my);
                    }
                    break;
                }
            }
            if ($i > 0 && hypot($mx - $terakhir[0], $my - $terakhir[1]) < 0.05) {
                $berhenti['tak maju'] = ($berhenti['tak maju'] ?? 0) + 1;
                if ($jejak) {
                    printf("  langkah %d di (%.1f, %.1f): tak maju (tetap di %.1f, %.1f)\n", $i,
                        $p[0], $p[1], $mx, $my);
                }
                break;
            }
            if (hypot($mx - $p[0], $my - $p[1]) > MAJU) {
                $berhenti['arah buntu'] = ($berhenti['arah buntu'] ?? 0) + 1;
                if ($jejak) {
                    printf("  langkah %d di (%.1f, %.1f): tengah bergeser %.1f m ke (%.1f, %.1f)\n",
                        $i, $p[0], $p[1], hypot($mx - $p[0], $my - $p[1]), $mx, $my);
                }
                break;
            }
            if (hypot($mx - $ox, $my - $oy) > BIDANG_TOL
                && diPoligon($mx, $my, $kisiPoligon, $poligon, $poligonBesar)) {
                $berhenti['tutup bidang'] = ($berhenti['tutup bidang'] ?? 0) + 1;
                if ($jejak) {
                    printf("  langkah %d di (%.1f, %.1f): tengah masuk persil\n", $i, $mx, $my);
                }
                break;
            }
            if (jarakSegmen($kisiAlur, $segmenAlur, $mx, $my, PANGKAS, SEL_KISI) !== null) {
                $berhenti['menempel rantai'] = ($berhenti['menempel rantai'] ?? 0) + 1;
                if ($jejak) {
                    printf("  langkah %d di (%.1f, %.1f): menempel rantai lain\n", $i, $mx, $my);
                }
                break;
            }
            $titik[] = [$mx, $my];
            $terakhir = [$mx, $my];
            $kunciPakai[$k['kunci']] = true;
            $kunciPakai[$n['kunci']] = true;
            $wSum += hypot($k['x'] - $n['x'], $k['y'] - $n['y']);
            $wMin = min($wMin, hypot($k['x'] - $n['x'], $k['y'] - $n['y']));
            $wN++;
            $arahKini = $rataArah($k['arah'], $n['arah'], $arahKini);
            $p = [$mx + cos(deg2rad($arahKini)) * LANGKAH, $my + sin(deg2rad($arahKini)) * LANGKAH];
        }
        return $titik;
    };

    $maju = $jalan([$seed['x'], $seed['y']], $seed['arah'], $seed['pasangan'], $acuanAwal, $ox, $oy);
    $mundur = $jalan([$seed['x'], $seed['y']], $seed['arah'] + 180.0, $seed['pasangan'],
        $acuanAwal, $ox, $oy);
    $bersih = [];
    foreach (array_merge(array_reverse($mundur), [[$seed['x'], $seed['y']]], $maju) as $tp) {
        if ($bersih === [] || hypot($tp[0] - $bersih[count($bersih) - 1][0],
            $tp[1] - $bersih[count($bersih) - 1][1]) >= 0.05) {
            $bersih[] = $tp;
        }
    }
    $p = panjang($bersih);
    foreach ($berhenti as $sebab => $kali) {
        $berhentiSemua[$sebab] = ($berhentiSemua[$sebab] ?? 0) + $kali;
    }
    if ($jejak) {
        printf("  maju %d titik, mundur %d titik, bersih %d titik, panjang %.2f m\n",
            count($maju), count($mundur), count($bersih), $p);
    }
    if ($p >= MIN_PANJANG && count($bersih) > 1) {
        $xb = array_column($bersih, 0);
        $yb = array_column($bersih, 1);
        $diag = hypot(max($xb) - min($xb), max($yb) - min($yb));
        if ($p > 20.0 && $diag < 0.1 * $p) {
            $sisa[] = [$seed['x'], $seed['y'], 'melipat ' . angkaNum($p, 2) . ' m (sebaran '
                . angkaNum($diag, 1) . ' m)'];
            continue;
        }
    }
    if ($p < MIN_PANJANG) {
        $sebab = [];
        foreach ($berhenti as $alasanBerhenti => $kali) {
            $sebab[] = $alasanBerhenti . ' ' . $kali;
        }
        $sisa[] = [$seed['x'], $seed['y'], 'rantai ' . angkaNum($p, 2) . ' m ('
            . ($sebab === [] ? 'langkah habis' : implode(', ', $sebab)) . ')'];
        continue;
    }

    // Kembar: rantai baru yang hampir seluruhnya menempel rantai lama dibuang.
    $sampelBaru = sampel($bersih);
    $kena = 0;
    foreach ($sampelBaru as [$sx, $sy]) {
        if (jarakSegmen($kisiAlur, $segmenAlur, $sx, $sy, KEMBAR_SAMPEL, SEL_KISI) !== null) {
            $kena++;
        }
    }
    if ($sampelBaru !== [] && $kena / count($sampelBaru) >= KEMBAR_BAGI) {
        $kembar++;
        $kembarPanjang += $p;
        continue;
    }

    $asal = ['perairan' => 0];
    foreach (array_keys($kunciPakai) as $kunci) {
        $asal[$kandidat[$kunci]['asal']] = ($asal[$kandidat[$kunci]['asal']] ?? 0) + 1;
    }
    // Seberapa dekat rantai ini menyusur sumbu lama: <= 3 m (menyambung),
    // 3-20 m (sejajar, harus dicurigai kembar), > 20 m (bentang benar-benar baru).
    $dLama = ['dekat' => 0, 'sejajar' => 0, 'jauh' => 0];
    foreach ($sampelBaru as [$sx, $sy]) {
        $cocok = jarakSegmen($kisiLama, $segmenLama, $sx, $sy, 30.0, SEL_KISI);
        $d = $cocok === null ? 30.0 : $cocok[0];
        if ($d <= 3.0) {
            $dLama['dekat']++;
        } elseif ($d <= 20.0) {
            $dLama['sejajar']++;
        } else {
            $dLama['jauh']++;
        }
    }
    $rantai[] = ['titik' => $bersih, 'panjang' => $p, 'lebar' => $wN > 0 ? $wSum / $wN : 0.0,
        'lebar_min' => $wMin === INF ? 0.0 : $wMin, 'tepi' => count($kunciPakai),
        'asal' => $asal, 'jarak_sumbu' => $seed['d'], 'lebar_seed' => $seed['w'],
        'penyeed' => [$seed['x'], $seed['y']], 'd_lama' => $dLama];
    tambahSegmen($kisiAlur, $segmenAlur, $bersih, SEL_KISI);
}

// ---------------------------------------------------------------------------
// Atribusi label dan laporan
// ---------------------------------------------------------------------------

$labelKisi = [];
$labelDaftar = [];
foreach ($label as $i => $l) {
    $labelDaftar[$i] = $l;
    $labelKisi[(int) floor($l[1] / SEL) . ':' . (int) floor($l[2] / SEL)][$i] = true;
}
$labelDekat = static function (float $px, float $py) use ($labelKisi, $labelDaftar): array {
    $keluar = [];
    $gx = (int) floor($px / SEL);
    $gy = (int) floor($py / SEL);
    for ($ix = $gx - 1; $ix <= $gx + 1; $ix++) {
        for ($iy = $gy - 1; $iy <= $gy + 1; $iy++) {
            foreach (array_keys($labelKisi[$ix . ':' . $iy] ?? []) as $i) {
                $keluar[] = $i;
            }
        }
    }
    return $keluar;
};

$daftar = [];
$panjangTotal = 0.0;
$wTotal = 0.0;
$wMinSemua = INF;
foreach ($rantai as $r) {
    $lewat = [];
    $langkahLbl = max(1, (int) floor($r['panjang'] / 15.0));
    for ($j = 0; $j <= $langkahLbl; $j++) {
        $idx = min(count($r['titik']) - 1, (int) round($j / max(1, $langkahLbl) * (count($r['titik']) - 1)));
        $tp = $r['titik'][$idx];
        foreach ($labelDekat($tp[0], $tp[1]) as $li) {
            $d = hypot($labelDaftar[$li][1] - $tp[0], $labelDaftar[$li][2] - $tp[1]);
            if ($d <= LABEL_RADIUS) {
                $lewat[$labelDaftar[$li][0]] = ($lewat[$labelDaftar[$li][0]] ?? 0) + 1;
            }
        }
    }
    $nama = '';
    $jumlahLabel = 0;
    if ($lewat !== []) {
        arsort($lewat);
        $nama = (string) array_key_first($lewat);
        $jumlahLabel = array_sum($lewat);
    }
    $panjangTotal += $r['panjang'];
    $wTotal += $r['lebar'] * $r['panjang'];
    $wMinSemua = min($wMinSemua, $r['lebar_min']);
    $daftar[] = [
        'type' => 'Feature',
        'properties' => [
            'kode' => 'SUNGAI-DUATEPI-0001',
            'sumber' => 'dua-tepi',
            'cara' => 'dua-tepi-alur',
            'panjang_m' => round($r['panjang'], 2),
            'lebar_m' => round($r['lebar'], 2),
            'lebar_min_m' => round($r['lebar_min'], 2),
            'lebar_seed_m' => round($r['lebar_seed'], 2),
            'jumlah_bagian' => count($r['titik']),
            'jumlah_tepi' => $r['tepi'],
            'tepi_asal' => $r['asal'],
            'label' => $nama,
            'jumlah_label' => $jumlahLabel,
            'jarak_sumbu_lama_m' => round($r['jarak_sumbu'], 1),
            'sampel_sumbu_lama' => $r['d_lama'],
            'penyeed' => [round($r['penyeed'][0], 1), round($r['penyeed'][1], 1)],
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $r['titik']],
    ];
}

usort($daftar, static fn(array $a, array $b): int =>
    $b['properties']['panjang_m'] <=> $a['properties']['panjang_m']);
foreach ($daftar as $i => &$f) {
    $f['properties']['kode'] = sprintf('SUNGAI-DUATEPI-%04d', $i + 1);
}
unset($f);

$wRata = $panjangTotal > 0.0 ? $wTotal / $panjangTotal : 0.0;
printf("rantai baru: %d · panjang %s m · lebar rata-rata %s m (minimum %s m)\n",
    count($daftar), angkaNum($panjangTotal, 1), angkaNum($wRata, 1),
    $wMinSemua === INF ? '-' : angkaNum($wMinSemua, 1));
printf("penyeed: %d diproses · %d dilewati (<= %s m dari rantai) · kembar dibuang %d (%s m)\n",
    $diproses, $dilewatiDekat, angkaNum($seedJarak, 1), $kembar, angkaNum($kembarPanjang, 1));
if ($berhentiSemua !== []) {
    arsort($berhentiSemua);
    $sebab = [];
    foreach ($berhentiSemua as $kunci => $kali) {
        $sebab[] = $kunci . ' ' . $kali;
    }
    printf("sebab berhenti: %s\n", implode(', ', $sebab));
}
if ($sisa !== []) {
    printf("penyeed gagal pendek: %d\n", count($sisa));
    foreach (array_slice($sisa, 0, 10) as [$sx, $sy, $sebab]) {
        printf("  - (%.0f, %.0f): %s\n", $sx, $sy, $sebab);
    }
}
if ($rinci) {
    echo "--- 40 rantai terpanjang ---\n";
    foreach (array_slice($daftar, 0, 40) as $f) {
        $pr = $f['properties'];
        $dl = $pr['sampel_sumbu_lama'];
        $n = max(1, $dl['dekat'] + $dl['sejajar'] + $dl['jauh']);
        printf("%-18s %8s m  lebar %5s m (seed %5s m)  %-10s dari sumbu %5s m  sumbu<=3:%3.0f%% 3-20:%3.0f%% >20:%3.0f%%  %s\n",
            $pr['kode'], angkaNum((float) $pr['panjang_m'], 1),
            angkaNum((float) $pr['lebar_m'], 1), angkaNum((float) $pr['lebar_seed_m'], 1),
            $pr['label'] === '' ? '-' : $pr['label'],
            angkaNum((float) $pr['jarak_sumbu_lama_m'], 1),
            100 * $dl['dekat'] / $n, 100 * $dl['sejajar'] / $n, 100 * $dl['jauh'] / $n,
            json_encode($pr['tepi_asal']));
    }
}

if ($cetak) {
    echo json_encode(['type' => 'FeatureCollection', 'features' => $daftar],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
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
        galat('gagal membuat folder cadangan ' . $cadang);
    }
    if (!copy($berkasKeluar, $cadang . '/' . basename($berkasKeluar))) {
        galat('gagal mencadangkan berkas lama');
    }
    echo 'cadangan berkas lama: _lama/' . basename($cadang) . "\n";
}

$naskah = json_encode(['type' => 'FeatureCollection', 'features' => $daftar],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($naskah === false || file_put_contents($berkasKeluar, $naskah) === false) {
    galat('gagal menulis ' . $berkasKeluar);
}

$berkasMeta = DIR_LAPISAN . '/metadata.json';
$meta = is_file($berkasMeta) ? (array) json_decode((string) file_get_contents($berkasMeta), true) : [];
if ($meta === []) {
    galat('metadata.json tidak dapat dibaca: ' . $berkasMeta);
}
if (isset($cadang) && is_dir($cadang)) {
    copy($berkasMeta, $cadang . '/metadata-sebelum-' . $stempel . '.json');
}
$meta['layers'][JUDUL_KELUAR] = [
    'features' => count($daftar),
    'types' => ['line' => count($daftar)],
    'file' => $keluar . '.geojson',
    'panjang_m' => (int) round($panjangTotal),
    'derived_from' => 'titik tengah dua tepi perairan berhadapan (garis-perairan.geojson) yang lebih'
        . ' dari ' . angkaNum($seedJarak, 0) . ' m dari sumbu tersaji, ditelusuri dengan koridor'
        . ' kiri-kanan dua tepi perairan saja (tanpa garis persil maupun 020100); titik tengah'
        . ' benih wajib di luar poligon bidang; bahan tambahan untuk jahit ulang'
        . ' garis-sungai-jahit.geojson; tidak ada titik karangan dan tidak ada tulisan ke basis data',
    'ambang' => ['lebar_min_m' => $lebarMin, 'lebar_koridor_m' => $lebar,
        'seed_lebar_m' => $seedLebar, 'seed_jarak_sumbu_m' => $seedJarak,
        'seed_langkah_m' => SEED_LANGKAH, 'seed_tegak' => SEED_TEGAK,
        'timbang_koridor' => TIMBANG, 'sudut_derajat' => SUDUT, 'langkah_m' => LANGKAH,
        'langkah_maks' => $langkahMaks, 'maju_m' => MAJU, 'pangkas_m' => PANGKAS,
        'min_panjang_m' => MIN_PANJANG, 'kembar_sampel_m' => KEMBAR_SAMPEL,
        'kembar_bagi' => KEMBAR_BAGI],
    'bank' => ['file' => BERKAS_BANK . '.geojson', 'panjang_m' => round($bankPanjang, 1),
        'sampel' => $sampelBank],
    'sumbu_lama' => ['file' => BERKAS_SUMBU . '.geojson', 'rantai' => $sumbuLama,
        'panjang_m' => round($sumbuLamaPanjang, 1)],
    'penyeed' => ['mentah' => count($seedKasar), 'sel' => count($seedDaftar),
        'diproses' => $diproses, 'dilewati_dekat_m' => $dilewatiDekat],
    'kembar_dibuang' => $kembar,
    'kembar_dibuang_panjang_m' => round($kembarPanjang, 1),
    'permukaan_bidang' => ['sumber' => 'tabel bidang (status <> dihapus)', 'cincin' => $jumlahPersil],
];
$meta['derived_at'] = date('c');
if (file_put_contents($berkasMeta, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_PRETTY_PRINT)) === false) {
    galat('gagal menulis metadata.json');
}
echo 'ditulis: files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . $keluar . ".geojson\n";
echo "metadata.json diperbarui: " . JUDUL_KELUAR . "\n";
