<?php
declare(strict_types=1);

/**
 * Garis sungai (sumbu alur) dari label perairan, mengikuti celah persil.
 *
 *   php -d memory_limit=1024M tools/garis-sungai-label.php            # uji kering
 *   php -d memory_limit=1024M tools/garis-sungai-label.php --terapkan
 *
 *   --lebar=M          lebar celah maksimum di kedua sisi titik alur; bawaan
 *                      60,0 m. Celah sungai di desa ini jauh lebih lebar dari
 *                      koridor jalan: ukur 21 Nov pada 112 label, jarak tepi
 *                      persil kiri-kanan median 23 m, p90 41 m.
 *   --lebar-min=M      lebar celah minimum; bawaan 3,0 m
 *   --benih=M          radius pencarian dua tepi penyeed di sekitar teks label;
 *                      bawaan 30,0 m
 *   --tengah=M         jarak titik tengah penyeed ke teks label; bawaan 15,0 m
 *   --dari=M           jarak teks label ke ruas penghubung dua tepi penyeed;
 *                      bawaan 5,0 m
 *   --langkah=N        jumlah langkah penelusuran per arah; bawaan 300
 *                      (satu langkah 2,0 m, jadi tiap arah <= 600 m)
 *   --keluar=NAMA      nama berkas keluaran tanpa .geojson; bawaan
 *                      garis-sungai-label
 *   --rinci            cetak 40 rantai terpanjang
 *
 * Latar. Lapis Sungai yang terbit (garis-perairan.geojson, 143 rantai) adalah
 * TEPI alur: garis kiri dan kanan hasil tools/tepi-sungai.php. Belum ada garis
 * tengah alur yang menyusuri celah persil. Alat ini menurunkan sumbu itu dari
 * LABEL sungai: tiap teks SUNGAI/KALI/BENGKONG di batas-perairan.geojson
 * menjadi benih, lalu alur ditelusuri dua arah di tengah celah antara tepi
 * persil (dan garis/cincin 020100 bila persil tidak mengapit), dengan syarat
 * tiap titik keluaran adalah titik tengah dua titik nyata yang berhadapan.
 * Tidak ada titik karangan dan tidak ada satu baris pun yang ditulis ke basis
 * data.
 *
 * Bahan.
 *  - `batas-perairan.geojson`: 279 titik teks; 112 di antaranya berkelas
 *    sungai (pola SUNGAI|KALI|BENGKONG), dua teks JALAN di berkas yang sama
 *    tidak dipakai.
 *  - `020100.geojson`: 13.681 garis terbuka dan 2.626 cincin poligon; garis
 *    terbuka dan cincin luar jadi KANDIDAT tepi (bukan permukaan).
 *  - tabel `bidang` (status <> 'dihapus', dibaca saja): cincin persil jadi
 *    kandidat tepi utama dan poligonnya jadi uji "titik alur masuk bidang".
 *    Ukur 21 Nov: 4.329 persil bergeometri.
 *
 * Penyeed. Dari teks label dikumpulkan titik terdekat pada tiap kandidat
 * tepi yang berbeda dalam radius --benih. Dua kandidat membentuk penyeed bila
 * lebarnya masuk --lebar-min..--lebar, arahnya sejajar (<= 25 derajat), titik
 * tengahnya <= --tengah dari teks, teks <= --dari dari ruas penghubung, ruas
 * penghubung cukup tegak lurus terhadap tepi (komponen sejajar <= 0,574), dan
 * titik tengahnya tidak jatuh di dalam persil (kecuali <= 2 m dari teks).
 * Penyeed terpilih = yang titik tengahnya paling dekat ke teks.
 *
 * Penelusuran. Langkah pertama memakai pasangan penyeed apa adanya. Tiap
 * langkah 2,0 m berikutnya: cari pasangan tepi kiri-kanan dengan arah <= 25
 * derajat, tidak boleh kandidat yang sama, lebar masih di dalam batas, kedua
 * sisi sejajar dan kurang lebih setimbang, titik tengah tidak masuk persil;
 * di antara pasangan sah dipilih yang titik tengahnya paling dekat ke titik
 * duga, dan tepi yang sedang dipakai didahulukan supaya alur tidak melompat
 * ke koridor lain. Arah langkah dirata-ratakan dari arah dua tepi. Rantai
 * berhenti bila tidak ada pasangan, titik tengah bergeser lebih dari 3,5 m,
 * titik tengah masuk persil (lewat 2 m dari teks), atau menyentuh rantai alur
 * yang sudah diterima (<= 1,2 m).
 *
 * Saringan kembar. Rantai baru dibuang bila >= 60 % titik sampelnya (tiap 2 m)
 * berjarak <= 3 m dari rantai yang sudah diterima; label yang sudah berada
 * <= 10 m dari rantai lama juga tidak ditelusuri ulang. Ini yang mencegah dua
 * label berdekatan pada satu alur menulis dua garis sejajar.
 *
 * Keluaran satu berkas: `garis-sungai-label.geojson` (meter DXF lokal, sama
 * dengan lapis Sungai), satu LineString per rantai >= 5 m, properti `kode`
 * (SUNGAI-LABEL-####), `panjang_m`, `lebar_m`, `lebar_min_m`, `jumlah_bagian`,
 * `label` (teks yang paling banyak muncul <= 15 m dari rantai), `jumlah_label`,
 * `tepi_asal` (jumlah titik singgung pada persil/020100), dan `cara`
 * (koridor-persil). Metadata mendapat entri GARIS SUNGAI DARI LABEL.
 *
 * Uji kering adalah bawaan; --terapkan menulis berkas keluaran (berkas lama
 * dicadangkan lebih dulu ke _lama/cadangan-garis-sungai-label-<stempel>/) dan
 * memperbarui metadata.json.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_SUMBER = '020100';
const BERKAS_LABEL = 'batas-perairan';
const BERKAS_KELUAR = 'garis-sungai-label';
const JUDUL_KELUAR = 'GARIS SUNGAI DARI LABEL';

/** Kisi dan sampel, sama dengan alat label jalan supaya angka bisa dibandingkan. */
const SEL = 25.0;
const SEL_KISI = 5.0;
const LANGKAH = 2.0;
const SUDUT = 25.0;
const LEBAR_MIN = 3.0;
const LEBAR_MAKS = 60.0;
const BENIH = 30.0;
const TENGAH = 15.0;
const DARI = 5.0;
const MAJU = 3.5;
const BIDANG_TOL = 2.0;
const PANGKAS = 1.2;
const LANGKAH_MAKS = 300;
const MIN_PANJANG = 5.0;
const DEKAT_LABEL = 10.0;
const KEMBAR_SAMPEL = 3.0;
const KEMBAR_BAGI = 0.6;
const LABEL_RADIUS = 15.0;
/** Kotak desa; label di luar kotak (koordinat rusak) dilewati. */
const KOTAK = [200000.0, 700000.0, 300000.0, 900000.0];

$terapkan = in_array('--terapkan', $argv, true);
$rinci = false;
$lebar = LEBAR_MAKS;
$lebarMin = LEBAR_MIN;
$benih = BENIH;
$tengah = TENGAH;
$dari = DARI;
$langkahMaks = LANGKAH_MAKS;
$keluar = BERKAS_KELUAR;
$tanya = null;
$tembus = false;
$gabung = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--terapkan') {
        $terapkan = true;
    } elseif ($a === '--rinci') {
        $rinci = true;
    } elseif ($a === '--tembus') {
        $tembus = true;
    } elseif ($a === '--gabung') {
        $gabung = true;
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
    } elseif (str_starts_with($a, '--benih=')) {
        $benih = angkaFlag(substr($a, 8), '--benih');
    } elseif (str_starts_with($a, '--tengah=')) {
        $tengah = angkaFlag(substr($a, 9), '--tengah');
    } elseif (str_starts_with($a, '--dari=')) {
        $dari = angkaFlag(substr($a, 7), '--dari');
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
if ($benih < $lebar / 2) {
    galat('--benih harus paling sedikit setengah --lebar supaya tepi terjangkau');
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

/** Panjang bagian rantai yang titik sampelnya jatuh di dalam poligon persil. */
function panjangDiPoligon(array $titik, array $kisi, array $poligon, array $besar): float
{
    $jumlah = 0.0;
    for ($i = 0; $i + 1 < count($titik); $i++) {
        $dx = (float) $titik[$i + 1][0] - (float) $titik[$i][0];
        $dy = (float) $titik[$i + 1][1] - (float) $titik[$i][1];
        $panjang = hypot($dx, $dy);
        if ($panjang <= 0.0) {
            continue;
        }
        $n = max(1, (int) floor($panjang / LANGKAH));
        for ($j = 0; $j < $n; $j++) {
            $t = $j / $n;
            if (diPoligon((float) $titik[$i][0] + $t * $dx, (float) $titik[$i][1] + $t * $dy,
                $kisi, $poligon, $besar)) {
                $jumlah += $panjang / $n;
            }
        }
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

/** Kelas teks label; hanya sungai yang dipakai alat ini. */
function kelasSungai(string $teks): bool
{
    return preg_match('/SUNGAI|KALI|BENGKONG/u', mb_strtoupper($teks)) === 1;
}

printf("mode: %s · celah %s-%s m · benih %s m (tengah %s m, dari %s m) · langkah %s x %s m\n",
    $terapkan ? 'TERAPKAN' : 'uji kering', angkaNum($lebarMin, 1), angkaNum($lebar, 1),
    angkaNum($benih, 1), angkaNum($tengah, 1), angkaNum($dari, 1),
    angkaNum((float) $langkahMaks), angkaNum(LANGKAH, 1));

// ---------------------------------------------------------------------------
// Bahan: tepi kandidat, permukaan persil, dan label
// ---------------------------------------------------------------------------

$kandidat = [];
$kisi = [];
$segmen = [];
$kisiPoligon = [];
$poligon = [];
$poligonBesar = [];
$jumlahGaris020100 = 0;
$jumlahCincin020100 = 0;

foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_SUMBER . '.geojson') as $i => $f) {
    $g = $f['geometry'] ?? null;
    $tipe = (string) ($g['type'] ?? '');
    if ($tipe === 'LineString' && count((array) ($g['coordinates'] ?? [])) >= 2) {
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) $g['coordinates']);
        $kunci = 'G:' . (int) $i;
        $kandidat[$kunci] = ['titik' => $titik, 'asal' => '020100'];
        tambahSegmen($kisi, $segmen, $titik, SEL_KISI, $kunci);
        $jumlahGaris020100++;
    } elseif ($tipe === 'Polygon') {
        $ring = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) ($g['coordinates'][0] ?? []));
        if (count($ring) < 4) {
            continue;
        }
        $kunci = 'C:' . (int) $i;
        $kandidat[$kunci] = ['titik' => $ring, 'asal' => '020100'];
        tambahSegmen($kisi, $segmen, $ring, SEL_KISI, $kunci);
        $jumlahCincin020100++;
    }
}
printf("020100: %d garis terbuka dan %d cincin luar jadi kandidat tepi\n",
    $jumlahGaris020100, $jumlahCincin020100);

$jumlahPersil = 0;
foreach ((array) db_all('SELECT id, ST_AsGeoJSON(geom) AS geojson FROM bidang '
    . "WHERE status <> 'dihapus' AND geom IS NOT NULL ORDER BY id") as $baris) {
    $geometri = json_decode((string) $baris['geojson'], true);
    if (!is_array($geometri)) {
        continue;
    }
    $urut = 0;
    foreach (cincinDariGeojson($geometri) as $ring) {
        $jumlahPersil++;
        $kunci = 'P:' . (int) $baris['id'] . ':' . $urut;
        $kandidat[$kunci] = ['titik' => $ring, 'asal' => 'persil'];
        tambahSegmen($kisi, $segmen, $ring, SEL_KISI, $kunci);
        tambahPoligon($kisiPoligon, $poligon, $poligonBesar, $ring, SEL_KISI);
        $urut++;
    }
}
printf("persil dari basis data (dibaca saja): %d cincin · %d segmen tepi · kisi permukaan %d petak\n",
    $jumlahPersil, count($segmen), count($kisiPoligon));

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
    $label[] = [$teks, $x, $y, 'perairan'];
}
printf("label sungai: %d titik dipakai%s\n", count($label),
    $dilewati > 0 ? ', ' . $dilewati . ' di luar kotak desa dilewati' : '');

// ---------------------------------------------------------------------------
// Penyeed dan penelusuran
// ---------------------------------------------------------------------------

/** Dua tepi kiri/kanan terdekat pada satu titik dan arah; tepi yang sedang dipakai didahulukan. */
$pasang = static function (float $px, float $py, float $ax, float $ay, ?array $acuan = null)
    use ($kisi, $segmen, $kandidat, $lebar, $lebarMin, $kisiPoligon, $poligon, $poligonBesar, $tembus): ?array {
    $nx = -$ay;
    $ny = $ax;
    $arah = rad2deg(atan2($ay, $ax));
    $batas = (int) ceil($lebar / SEL_KISI);
    $gx = (int) floor($px / SEL_KISI);
    $gy = (int) floor($py / SEL_KISI);
    $sisi = ['kiri' => [], 'kanan' => []];
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $sid) {
                $s = $segmen[$sid];
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
            if (abs($k['d'] - $n['d']) > 0.35 * $w) {
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
            [$mx, $my, $k, $n] = $butir;
            if ($tembus || !diPoligon($mx, $my, $kisiPoligon, $poligon, $poligonBesar)) {
                return [$k, $n];
            }
        }
    }
    return null;
};

$searah = static function (float $h, float $acuan): float {
    return $h + 180.0 * round(($acuan - $h) / 180.0);
};
$rataArah = static function (float $h1, float $h2, float $acuan) use ($searah): float {
    return rad2deg(atan2(
        sin(deg2rad($searah($h1, $acuan))) + sin(deg2rad($searah($h2, $acuan))),
        cos(deg2rad($searah($h1, $acuan))) + cos(deg2rad($searah($h2, $acuan)))));
};

$kisiAlur = [];
$segmenAlur = [];
$rantai = [];
$sisa = [];
$kembar = 0;
$kembarPanjang = 0.0;
$kandidatKembar = 0;

if ($tengah < DARI) {
    galat('--tengah harus >= --dari');
}

$lama = 0;
if ($gabung) {
    $berkasLama = DIR_LAPISAN . '/' . $keluar . '.geojson';
    if (!is_file($berkasLama)) {
        galat('--gabung butuh berkas yang sudah ada: ' . $berkasLama);
    }
    foreach (geojsonBaca($berkasLama) as $f) {
        $g = $f['geometry'] ?? [];
        if (($g['type'] ?? '') !== 'LineString' || count($g['coordinates'] ?? []) < 2) {
            continue;
        }
        $titik = array_map(static fn(array $tp): array => [(float) $tp[0], (float) $tp[1]],
            $g['coordinates']);
        $pr = $f['properties'] ?? [];
        $rantai[] = [
            'titik' => $titik,
            'panjang' => (float) ($pr['panjang_m'] ?? panjang($titik)),
            'lebar' => (float) ($pr['lebar_m'] ?? 0.0),
            'lebar_min' => (float) ($pr['lebar_min_m'] ?? 0.0),
            'tepi' => (int) ($pr['jumlah_tepi'] ?? 0),
            'asal' => (array) ($pr['tepi_asal'] ?? []),
            'label' => (string) ($pr['label'] ?? ''),
            'tembus' => ($pr['cara'] ?? '') === 'koridor-tembus-bidang',
            'tembus_m' => (float) ($pr['tembus_bidang_m'] ?? 0.0),
        ];
        tambahSegmen($kisiAlur, $segmenAlur, $titik, SEL_KISI);
        $lama++;
    }
    printf("gabung: %d rantai lama dimuat dari %s\n", $lama, $keluar . '.geojson');
}

$lewati = $gabung ? LABEL_RADIUS : DEKAT_LABEL;
$diproses = 0;
foreach ($label as [$teks, $x, $y]) {
    if (jarakSegmen($kisiAlur, $segmenAlur, $x, $y, $lewati, SEL_KISI) !== null) {
        continue;
    }
    $diproses++;
    $jejak = $tanya !== null && hypot($x - $tanya[0], $y - $tanya[1]) <= 1.0;
    if ($jejak) {
        printf("TANYA %s (%.1f, %.1f)\n", $teks, $x, $y);
    }

    // Benih: titik terdekat per kandidat tepi di sekitar teks.
    $dekat = [];
    $batas = (int) ceil($benih / SEL_KISI);
    $gx = (int) floor($x / SEL_KISI);
    $gy = (int) floor($y / SEL_KISI);
    $segKandidat = [];
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $sid) {
                $s = $segmen[$sid];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                $t = $pan > 0.0
                    ? max(0.0, min(1.0, (($x - $s[0]) * $dx + ($y - $s[1]) * $dy) / $pan)) : 0.0;
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $d = hypot($x - $fx, $y - $fy);
                if ($d > $benih) {
                    continue;
                }
                $kid = (string) $s[5];
                if (!isset($dekat[$kid]) || $d < $dekat[$kid]['d']) {
                    $dekat[$kid] = ['d' => $d, 'u' => [$fx, $fy, (float) $s[4], $kid]];
                }
            }
        }
    }

    $seed = null;
    $alasan = 0;
    if ($jejak) {
        foreach ($dekat as $kid => $a) {
            printf("  tepi %-18s d=%6.1f arah=%6.1f titik=(%.1f, %.1f)\n", $kid, $a['d'],
                $a['u'][2], $a['u'][0], $a['u'][1]);
        }
        if ($dekat === []) {
            echo "  tidak ada tepi kandidat dalam radius benih\n";
        }
    }
    foreach ($dekat as $a) {
        foreach ($dekat as $b) {
            if ($a['u'][3] === $b['u'][3]) {
                continue;
            }
            $alasan = max($alasan, 1);
            [$x1, $y1, $h1] = $a['u'];
            [$x2, $y2, $h2] = $b['u'];
            $w = hypot($x2 - $x1, $y2 - $y1);
            if ($w < $lebarMin) {
                continue;
            }
            if ($w > $lebar || bedaArah($h1, $h2) > SUDUT) {
                $alasan = max($alasan, 2);
                if ($jejak) {
                    printf("  %s<->%s lebar %.1f, sudut %.0f: ditolak (celah/arah)\n",
                        $a['u'][3], $b['u'][3], $w, bedaArah($h1, $h2));
                }
                continue;
            }
            $mx = ($x1 + $x2) / 2;
            $my = ($y1 + $y2) / 2;
            $dr = hypot($mx - $x, $my - $y);
            if ($dr > $tengah) {
                $alasan = max($alasan, 3);
                if ($jejak) {
                    printf("  %s<->%s lebar %.1f: tengah %.1f m dari teks\n", $a['u'][3], $b['u'][3],
                        $w, $dr);
                }
                continue;
            }
            $ruas = $w * $w;
            $t = $ruas > 0.0
                ? max(0.0, min(1.0, (($x - $x1) * ($x2 - $x1) + ($y - $y1) * ($y2 - $y1)) / $ruas)) : 0.0;
            if (hypot($x - ($x1 + $t * ($x2 - $x1)), $y - ($y1 + $t * ($y2 - $y1))) > $dari) {
                $alasan = max($alasan, 4);
                if ($jejak) {
                    printf("  %s<->%s lebar %.1f: teks %.1f m dari ruas\n", $a['u'][3], $b['u'][3],
                        $w, hypot($x - ($x1 + $t * ($x2 - $x1)), $y - ($y1 + $t * ($y2 - $y1))));
                }
                continue;
            }
            $ah = $rataArah($h1, $h2, $h1);
            $ux = ($x2 - $x1) / $w;
            $uy = ($y2 - $y1) / $w;
            if (abs($ux * cos(deg2rad($ah)) + $uy * sin(deg2rad($ah))) > 0.574) {
                $alasan = max($alasan, 5);
                if ($jejak) {
                    printf("  %s<->%s lebar %.1f: ruas tidak tegak lurus\n", $a['u'][3], $b['u'][3],
                        $w);
                }
                continue;
            }
            if (!$tembus && $dr > BIDANG_TOL
                && diPoligon($mx, $my, $kisiPoligon, $poligon, $poligonBesar)) {
                $alasan = max($alasan, 6);
                if ($jejak) {
                    printf("  %s<->%s lebar %.1f: tengah masuk persil\n", $a['u'][3], $b['u'][3],
                        $w);
                }
                continue;
            }
            if ($seed === null || $dr < $seed['dari']) {
                $seed = ['x' => $mx, 'y' => $my, 'dari' => $dr, 'arah' => $ah,
                    'pasangan' => [['x' => $x1, 'y' => $y1, 'arah' => $h1, 'kunci' => $a['u'][3]],
                        ['x' => $x2, 'y' => $y2, 'arah' => $h2, 'kunci' => $b['u'][3]]]];
            }
        }
    }
    if ($seed === null) {
        $kunciAlasan = [0 => 'satu tepi saja', 1 => 'tepi berimpit', 2 => 'celah lebar atau tak sejajar',
            3 => 'tengah celah jauh dari teks', 4 => 'teks jauh dari ruas', 5 => 'teks bukan di tengah',
            6 => 'tengah celah masuk persil'][$alasan];
        $sisa[] = [$teks, $x, $y, 'tanpa penyeed: ' . $kunciAlasan];
        continue;
    }
    if ($jejak) {
        printf("  penyeed: tengah (%.1f, %.1f) %.1f m dari teks, arah %.1f, lebar %.1f, %s/%s\n",
            $seed['x'], $seed['y'], $seed['dari'], $seed['arah'],
            hypot($seed['pasangan'][1]['x'] - $seed['pasangan'][0]['x'],
                $seed['pasangan'][1]['y'] - $seed['pasangan'][0]['y']),
            $seed['pasangan'][0]['kunci'], $seed['pasangan'][1]['kunci']);
    }

    $kunciPakai = [];
    $wSum = 0.0;
    $wN = 0;
    $wMin = INF;
    $berhenti = [];

    $jalan = static function (array $mulai, float $arahAwal, array $pasanganAwal) use ($pasang, $rataArah,
        $kisiPoligon, $poligon, $poligonBesar, $kisiAlur, $segmenAlur, $x, $y, &$kunciPakai, &$wSum, &$wN,
        &$wMin, &$berhenti, $langkahMaks, $jejak, $tembus): array {
        $p = $mulai;
        $terakhir = $mulai;
        $arahKini = $arahAwal;
        $titik = [];
        $pas = $pasanganAwal;
        $acuan = [$pasanganAwal[0]['kunci'], $pasanganAwal[1]['kunci']];
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
            if (!$tembus && hypot($mx - $x, $my - $y) > BIDANG_TOL
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

    $maju = $jalan([$seed['x'], $seed['y']], $seed['arah'], $seed['pasangan']);
    $mundur = $jalan([$seed['x'], $seed['y']], $seed['arah'] + 180.0, $seed['pasangan']);
    $bersih = [];
    foreach (array_merge(array_reverse($mundur), [[$seed['x'], $seed['y']]], $maju) as $tp) {
        if ($bersih === [] || hypot($tp[0] - $bersih[count($bersih) - 1][0],
            $tp[1] - $bersih[count($bersih) - 1][1]) >= 0.05) {
            $bersih[] = $tp;
        }
    }
    $p = panjang($bersih);
    $tembusM = $tembus
        ? panjangDiPoligon($bersih, $kisiPoligon, $poligon, $poligonBesar) : 0.0;
    if ($jejak) {
        printf("  maju %d titik, mundur %d titik, bersih %d titik, panjang %.2f m\n",
            count($maju), count($mundur), count($bersih), $p);
    }
    if ($p < MIN_PANJANG) {
        $sebab = [];
        foreach ($berhenti as $alasanBerhenti => $kali) {
            $sebab[] = $alasanBerhenti . ' ' . $kali;
        }
        $sisa[] = [$teks, $x, $y, 'rantai ' . angkaNum($p, 2) . ' m ('
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
        $kandidatKembar++;
        continue;
    }

    $asal = ['persil' => 0, '020100' => 0];
    foreach (array_keys($kunciPakai) as $kunci) {
        $asal[$kandidat[$kunci]['asal']] = ($asal[$kandidat[$kunci]['asal']] ?? 0) + 1;
    }
    $rantai[] = ['titik' => $bersih, 'panjang' => $p, 'lebar' => $wN > 0 ? $wSum / $wN : 0.0,
        'lebar_min' => $wMin === INF ? 0.0 : $wMin, 'tepi' => count($kunciPakai),
        'asal' => $asal, 'label' => $teks, 'tembus' => $tembus, 'tembus_m' => $tembusM];
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

$kisiOutput = [];
$segmenOutput = [];
foreach ($rantai as $i => $r) {
    tambahSegmen($kisiOutput, $segmenOutput, $r['titik'], SEL_KISI, $i);
}

$terjangkau = 0;
$sisaAkhir = [];
foreach ($label as [$teks, $x, $y]) {
    if (jarakSegmen($kisiOutput, $segmenOutput, $x, $y, LABEL_RADIUS, SEL_KISI) !== null) {
        $terjangkau++;
    } else {
        $sisaAkhir[] = [$teks, $x, $y];
    }
}

$daftar = [];
$panjangTotal = 0.0;
$wTotal = 0.0;
$wMinSemua = INF;
foreach ($rantai as $i => $r) {
    $nama = [];
    foreach ($labelDekat($r['titik'][0][0], $r['titik'][0][1]) as $li) {
        $nama[] = $labelDaftar[$li][0];
    }
    foreach ($labelDekat($r['titik'][count($r['titik']) - 1][0], $r['titik'][count($r['titik']) - 1][1]) as $li) {
        $nama[] = $labelDaftar[$li][0];
    }
    // label di sepanjang rantai (sampel 15 m) supaya nama tengah ikut terhitung
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
    if ($lewat !== []) {
        arsort($lewat);
        $nama = [array_key_first($lewat)];
        $jumlahLabel = array_sum($lewat);
    } else {
        $jumlahLabel = 0;
        $nama = [$r['label']];
    }
    $panjangTotal += $r['panjang'];
    $wTotal += $r['lebar'] * $r['panjang'];
    $wMinSemua = min($wMinSemua, $r['lebar_min']);
    $daftar[] = [
        'type' => 'Feature',
        'properties' => [
            'kode' => sprintf('SUNGAI-LABEL-%04d', count($daftar) + 1),
            'sumber' => 'label-sungai',
            'cara' => $r['tembus'] ? 'koridor-tembus-bidang' : 'koridor-persil',
            'panjang_m' => round($r['panjang'], 2),
            'lebar_m' => round($r['lebar'], 2),
            'lebar_min_m' => round($r['lebar_min'], 2),
            'jumlah_bagian' => count($r['titik']),
            'jumlah_tepi' => $r['tepi'],
            'tepi_asal' => $r['asal'],
            'label' => $nama[0] ?? $r['label'],
            'jumlah_label' => $jumlahLabel,
            'tembus_bidang_m' => round($r['tembus_m'], 1),
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $r['titik']],
    ];
}

usort($daftar, static fn(array $a, array $b): int =>
    $b['properties']['panjang_m'] <=> $a['properties']['panjang_m']);
foreach ($daftar as $i => &$f) {
    $f['properties']['kode'] = sprintf('SUNGAI-LABEL-%04d', $i + 1);
}
unset($f);

$wRata = $panjangTotal > 0.0 ? $wTotal / $panjangTotal : 0.0;
$tembusN = 0;
$tembusPanjang = 0.0;
$tembusLewat = 0.0;
foreach ($daftar as $f) {
    if (($f['properties']['cara'] ?? '') === 'koridor-tembus-bidang') {
        $tembusN++;
        $tembusPanjang += (float) $f['properties']['panjang_m'];
        $tembusLewat += (float) $f['properties']['tembus_bidang_m'];
    }
}
printf("rantai: %d · panjang %s m · lebar rata-rata %s m (minimum %s m)\n",
    count($daftar), angkaNum($panjangTotal, 1), angkaNum($wRata, 1),
    $wMinSemua === INF ? '-' : angkaNum($wMinSemua, 1));
if ($gabung) {
    printf("label yatim yang ditelusuri ulang: %d\n", $diproses);
}
if ($tembusN > 0) {
    printf("lewat bidang (tembus): %d rantai · %s m · %s m di atas persil\n", $tembusN,
        angkaNum($tembusPanjang, 1), angkaNum($tembusLewat, 1));
}
printf("label terjangkau <= %s m: %d/%d · kembar dibuang: %d (%s m)\n",
    angkaNum(LABEL_RADIUS, 1), $terjangkau, count($label), $kembar, angkaNum($kembarPanjang, 1));
if ($sisaAkhir !== []) {
    printf("label tersisa: %d\n", count($sisaAkhir));
    foreach ($sisaAkhir as [$teks, $x, $y]) {
        $sebab = 'jauh dari rantai';
        foreach ($sisa as $s) {
            if ($s[0] === $teks && abs($s[1] - $x) < 0.5 && abs($s[2] - $y) < 0.5) {
                $sebab = $s[3];
                break;
            }
        }
        printf("  - %-12s (%.0f, %.0f): %s\n", $teks, $x, $y, $sebab);
    }
}
if ($rinci) {
    echo "--- 40 rantai terpanjang ---\n";
    foreach (array_slice($daftar, 0, 40) as $f) {
        $p = $f['properties'];
        printf("%-16s %8s m  lebar %6s m  %-12s %s%s\n", $p['kode'], angkaNum((float) $p['panjang_m'], 1),
            angkaNum((float) $p['lebar_m'], 1), $p['label'],
            json_encode($p['tepi_asal']),
            ($p['tembus_bidang_m'] ?? 0) > 0 ? '  [tembus ' . angkaNum((float) $p['tembus_bidang_m'], 1)
                . ' m]' : '');
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
    'derived_from' => 'titik tengah dua tepi berhadapan pada celah persil (tabel bidang) dan garis/cincin'
        . ' 020100, ditelusuri dari tiap label SUNGAI/KALI/BENGKONG di batas-perairan.geojson;'
        . ' tidak ada titik karangan dan tidak ada tulisan ke basis data',
    'ambang' => ['lebar_min_m' => $lebarMin, 'lebar_maks_m' => $lebar,
        'benih_m' => $benih, 'seed_tengah_m' => $tengah, 'seed_dari_m' => $dari,
        'sudut_derajat' => SUDUT, 'langkah_m' => LANGKAH, 'langkah_maks' => $langkahMaks,
        'maju_m' => MAJU, 'pangkas_m' => PANGKAS,
        'min_panjang_m' => MIN_PANJANG, 'dekat_label_m' => DEKAT_LABEL,
        'kembar_sampel_m' => KEMBAR_SAMPEL, 'kembar_bagi' => KEMBAR_BAGI],
    'label' => count($label),
    'label_terjangkau_m' => LABEL_RADIUS,
    'label_terjangkau' => $terjangkau,
    'kembar_dibuang' => $kembar,
    'kembar_dibuang_panjang_m' => round($kembarPanjang, 1),
    'tembus' => ['aturan' => $tembus ? 'aktif' : 'mati', 'rantai_lama_dimuat' => $lama,
        'rantai' => $tembusN, 'panjang_m' => round($tembusPanjang, 1),
        'lewat_bidang_m' => round($tembusLewat, 1)],
    'permukaan_bidang' => ['sumber' => 'tabel bidang (status <> dihapus)', 'cincin' => $jumlahPersil],
    'asal_tepi' => ['020100_garis' => $jumlahGaris020100, '020100_cincin' => $jumlahCincin020100,
        'persil' => $jumlahPersil],
];
$meta['derived_at'] = date('c');
if (file_put_contents($berkasMeta, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_PRETTY_PRINT)) === false) {
    galat('gagal menulis metadata.json');
}
echo 'ditulis: files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . $keluar . ".geojson\n";
echo "metadata.json diperbarui: " . JUDUL_KELUAR . "\n";
