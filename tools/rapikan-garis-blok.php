<?php
declare(strict_types=1);

/**
 * Rapikan garis luar blok yang terbentuk dari gabungan bidang (batas perkiraan).
 *
 * Garis hasil "kira" mengikuti tepi tiap bidang, jadi tampak bergerigi, terdiri
 * dari puluhan bagian yang saling menempel, dan kadang cincinnya memotong diri
 * sendiri. Alat ini:
 *
 *   1. melarutkan (dissolve) semua bagian dengan ST_Union berpasangan,
 *   2. membuang lubang kecil (bawaan ≤500 m²) yang hanya celah antar-bidang,
 *   3. menghaluskan tiap cincin dengan Douglas–Peucker dalam satuan meter,
 *      diulang dengan toleransi separuh bila hasilnya memotong diri sendiri,
 *   4. menyambung bagian yang berdekatan (jembatan selebar 1,5 m, bawaan sampai
 *      30 m) supaya blok menjadi satu cakupan; pasangan yang di antaranya ada
 *      jalan OSM selebar 3 m atau lebih atau wilayah blok lain tidak disambung,
 *      jadi blok tetap terpisah di tempat yang memang terpotong jalan,
 *   5. memotong bagian yang menumpang garis blok lain (bila ada), supaya
 *      penghalusan dan jembatan tidak membuat dua blok bertumpang tindih.
 *
 * Mode --satukan memaksa satu bagian: langkah 4 menyambung semua bagian tanpa
 * uji jalan, tanpa uji wilayah blok, dan tanpa batas jarak, lalu langkah 5
 * dilewati supaya koridor tidak ikut terpotong. Akibatnya blok bisa bertumpang
 * dengan tetangga; itu disengaja dan dilaporkan, bukan kerusakan.
 *
 * Uji kering adalah bawaan; tidak ada data yang ditulis tanpa --terapkan, dan
 * --terapkan selalu membuat cadangan basis data penuh lebih dulu.
 *
 *   php tools/rapikan-garis-blok.php 3
 *   php tools/rapikan-garis-blok.php 3 --terapkan
 *   php tools/rapikan-garis-blok.php 3 7 --toleransi=1.5 --lubang-maks=500
 *   php tools/rapikan-garis-blok.php 3 --paksa        (lewati batas selisih luas 5%)
 *   php tools/rapikan-garis-blok.php 3 --biarkan-tumpang
 *                                                    (jangan potong bagian yang menumpang blok lain)
 *   php tools/rapikan-garis-blok.php 3 --rapat=30 --abaikan-jalan
 *                                                    (sambung bagian sampai 30 m tanpa uji jalan)
 *   php tools/rapikan-garis-blok.php 3 --satukan      (paksa 1 bagian: sambung semua
 *                                                    bagian tanpa batas jarak, abaikan jalan
 *                                                    dan wilayah blok lain, tanpa potong tumpang)
 *   php tools/rapikan-garis-blok.php 3 --rinci       (tampilkan keputusan tiap sambungan)
 *   php tools/rapikan-garis-blok.php 3 --keluaran=cek.geojson
 *                                                    (tulis calon hasil untuk dilihat di peta)
 *   php tools/rapikan-garis-blok.php 3 --dari-bidang --satukan
 *                                                    (bangun ulang dari seluruh bidang anggota)
 */

require_once __DIR__ . '/lib-impor.php';
require_once __DIR__ . '/../app/lib-pengaturan.php';

const RAPIKAN_TOLERANSI_M = 1.5;
const RAPIKAN_LUBANG_MAKS_M2 = 500.0;
const RAPIKAN_SELISIH_LUAS_MAKS = 0.05;
const RAPIKAN_RAPAT_M = 30.0;
const RAPIKAN_JEMBATAN_SETENGAH_M = 0.75;
const RAPIKAN_JALAN_LEBAR_M = 3.0;
const RAPIKAN_SATUKAN_RAPAT_M = 5000.0;

$argv = $_SERVER['argv'] ?? [];
$terapkan = in_array('--terapkan', $argv, true);
$paksa = in_array('--paksa', $argv, true);
$biarkanTumpang = in_array('--biarkan-tumpang', $argv, true);
$abaikanJalan = in_array('--abaikan-jalan', $argv, true);
$satukan = in_array('--satukan', $argv, true);
$dariBidang = in_array('--dari-bidang', $argv, true);
$rinci = in_array('--rinci', $argv, true);
$toleransi = RAPIKAN_TOLERANSI_M;
$lubangMaks = RAPIKAN_LUBANG_MAKS_M2;
$rapat = RAPIKAN_RAPAT_M;
$keluaran = null;
$nomor = [];
foreach (array_slice($argv, 1) as $argumen) {
    if (preg_match('/^--toleransi=([0-9.]+)$/', $argumen, $cocok)) {
        $toleransi = (float) $cocok[1];
        continue;
    }
    if (preg_match('/^--lubang-maks=([0-9.]+)$/', $argumen, $cocok)) {
        $lubangMaks = (float) $cocok[1];
        continue;
    }
    if (preg_match('/^--rapat=([0-9.]+)$/', $argumen, $cocok)) {
        $rapat = (float) $cocok[1];
        continue;
    }
    if (str_starts_with($argumen, '--keluaran=')) {
        $keluaran = substr($argumen, strlen('--keluaran='));
        continue;
    }
    if (str_starts_with($argumen, '--')) {
        continue;
    }
    if (!preg_match('/^\d+$/', $argumen)) {
        fwrite(STDERR, "Nomor blok harus angka: {$argumen}\n");
        exit(1);
    }
    $nomor[] = (int) $argumen;
}

if ($nomor === []) {
    fwrite(STDERR, "Sebutkan nomor blok. Contoh: php tools/rapikan-garis-blok.php 3 --terapkan\n");
    exit(1);
}
if ($toleransi <= 0.0) {
    fwrite(STDERR, "Toleransi harus lebih dari 0 meter.\n");
    exit(1);
}
if ($satukan) {
    // Semua penghalang sengaja dimatikan supaya hasilnya pasti satu bagian.
    $abaikanJalan = true;
    $biarkanTumpang = true;
    $rapat = max($rapat, RAPIKAN_SATUKAN_RAPAT_M);
}

set_time_limit(0);
ini_set('memory_limit', '1024M');

// ---------------------------------------------------------------------------
// Pembantu geometri (lon/lat, jarak dihitung dalam meter)
// ---------------------------------------------------------------------------

function rapiAngka(float $v): string
{
    return number_format($v, 10, '.', '');
}

/** @return array{0:float,1:float} faktor lon/lat ke meter pada lintang tertentu */
function rapiSkala(array $poligon): array
{
    $jumlah = 0.0;
    $n = 0;
    foreach ($poligon as $satuPoligon) {
        foreach ($satuPoligon as $cincin) {
            foreach ($cincin as $titik) {
                $jumlah += (float) $titik[1];
                $n++;
            }
        }
    }
    $lintang = $n > 0 ? $jumlah / $n : -7.08;

    return [111320.0 * cos(deg2rad($lintang)), 111320.0];
}

/** @return array{0:float,1:float} */
function rapiMeter(array $titik, array $skala): array
{
    return [(float) $titik[0] * $skala[0], (float) $titik[1] * $skala[1]];
}

function rapiRingBersih(array $cincin): array
{
    $hasil = [];
    foreach ($cincin as $titik) {
        $titik = [(float) $titik[0], (float) $titik[1]];
        if ($hasil !== [] && $hasil[count($hasil) - 1] === $titik) {
            continue;
        }
        $hasil[] = $titik;
    }
    $n = count($hasil);
    if ($n > 2 && $hasil[0] === $hasil[$n - 1]) {
        array_pop($hasil);
    }

    return $hasil;
}

function rapiRingTutup(array $cincin): array
{
    $cincin = rapiRingBersih($cincin);
    if ($cincin === []) {
        return [];
    }
    $cincin[] = $cincin[0];

    return $cincin;
}

function rapiLuasRing(array $cincin, array $skala): float
{
    $cincin = rapiRingBersih($cincin);
    $n = count($cincin);
    if ($n < 3) {
        return 0.0;
    }
    $jumlah = 0.0;
    for ($i = 0; $i < $n; $i++) {
        [$x1, $y1] = rapiMeter($cincin[$i], $skala);
        [$x2, $y2] = rapiMeter($cincin[($i + 1) % $n], $skala);
        $jumlah += $x1 * $y2 - $x2 * $y1;
    }

    return abs($jumlah) / 2.0;
}

function rapiJarakTitikGaris(float $px, float $py, float $ax, float $ay, float $bx, float $by): float
{
    $dx = $bx - $ax;
    $dy = $by - $ay;
    if ($dx === 0.0 && $dy === 0.0) {
        return hypot($px - $ax, $py - $ay);
    }
    $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / ($dx * $dx + $dy * $dy);
    $t = max(0.0, min(1.0, $t));

    return hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy));
}

function rapiSederhanakanRing(array $cincin, float $tolMeter, array $skala): array
{
    $titik = rapiRingBersih($cincin);
    $n = count($titik);
    if ($n <= 4) {
        return rapiRingTutup($titik);
    }

    $jaga = array_fill(0, $n, false);
    $jaga[0] = $jaga[$n - 1] = true;
    $tumpuk = [[0, $n - 1]];
    while ($tumpuk !== []) {
        [$a, $b] = array_pop($tumpuk);
        if ($b - $a < 2) {
            continue;
        }
        [$ax, $ay] = rapiMeter($titik[$a], $skala);
        [$bx, $by] = rapiMeter($titik[$b], $skala);
        $maks = -1.0;
        $pilih = -1;
        for ($i = $a + 1; $i < $b; $i++) {
            [$px, $py] = rapiMeter($titik[$i], $skala);
            $jarak = rapiJarakTitikGaris($px, $py, $ax, $ay, $bx, $by);
            if ($jarak > $maks) {
                $maks = $jarak;
                $pilih = $i;
            }
        }
        if ($pilih !== -1 && $maks > $tolMeter) {
            $jaga[$pilih] = true;
            $tumpuk[] = [$a, $pilih];
            $tumpuk[] = [$pilih, $b];
        }
    }

    $hasil = [];
    for ($i = 0; $i < $n; $i++) {
        if ($jaga[$i]) {
            $hasil[] = $titik[$i];
        }
    }
    if (count($hasil) < 3) {
        return rapiRingTutup($titik);
    }

    return rapiRingTutup($hasil);
}

function rapiArah(array $a, array $b, array $c): float
{
    return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
}

/**
 * Hanya perpotongan sungguhan (dua sisi saling melewati). Titik singgung antar
 * sudut tidak dihitung: cincin hasil gabungan bidang memang sering menempel di
 * satu sudut, dan gejala itu tidak merusak tampilan seperti cincin terbelit.
 */
function rapiSegmenPotong(array $p1, array $p2, array $q1, array $q2): bool
{
    $o1 = rapiArah($p1, $p2, $q1);
    $o2 = rapiArah($p1, $p2, $q2);
    $o3 = rapiArah($q1, $q2, $p1);
    $o4 = rapiArah($q1, $q2, $p2);

    return (($o1 > 0 && $o2 < 0) || ($o1 < 0 && $o2 > 0))
        && (($o3 > 0 && $o4 < 0) || ($o3 < 0 && $o4 > 0));
}

function rapiRingMemotongSendiri(array $cincin): bool
{
    $titik = rapiRingBersih($cincin);
    $n = count($titik);
    if ($n < 4) {
        return false;
    }
    for ($i = 0; $i < $n; $i++) {
        $a1 = $titik[$i];
        $a2 = $titik[($i + 1) % $n];
        for ($j = $i + 1; $j < $n; $j++) {
            if ($j === $i + 1 || ($i === 0 && $j === $n - 1)) {
                continue;
            }
            $b1 = $titik[$j];
            $b2 = $titik[($j + 1) % $n];
            if (rapiSegmenPotong($a1, $a2, $b1, $b2)) {
                return true;
            }
        }
    }

    return false;
}

/** Sederhanakan; turunkan toleransi separuh bila hasilnya memotong diri sendiri. */
function rapiSederhanakanAman(array $cincin, float $tolMeter, array $skala, int &$tingkat): array
{
    $t = $tolMeter;
    for ($i = 0; $i < 3; $i++) {
        $hasil = rapiSederhanakanRing($cincin, $t, $skala);
        if (!rapiRingMemotongSendiri($hasil)) {
            $tingkat = $i;
            return $hasil;
        }
        $t /= 2.0;
    }
    $tingkat = -1;

    return rapiRingTutup($cincin);
}

function rapiPoligonWkt(array $cincin): string
{
    $bagian = [];
    foreach ($cincin as $satu) {
        $satu = rapiRingTutup($satu);
        $bagian[] = '(' . implode(',', array_map(
            static fn(array $t): string => rapiAngka($t[0]) . ' ' . rapiAngka($t[1]),
            $satu
        )) . ')';
    }

    return 'POLYGON(' . implode(',', $bagian) . ')';
}

/** Tiap poligon sudah membawa kurung badannya dari rapiPoligonWkt(). */
function rapiMultiWkt(array $poligon): string
{
    $bagian = [];
    foreach ($poligon as $cincin) {
        $bagian[] = substr(rapiPoligonWkt($cincin), strlen('POLYGON'));
    }

    return 'MULTIPOLYGON(' . implode(',', $bagian) . ')';
}

function rapiStatistik(array $poligon, array $skala): array
{
    $bagian = 0;
    $titik = 0;
    $lubang = 0;
    $rusak = 0;
    $luas = 0.0;
    foreach ($poligon as $cincin) {
        $bagian++;
        foreach ($cincin as $i => $satu) {
            $titik += count(rapiRingBersih($satu));
            $satuLuas = rapiLuasRing($satu, $skala);
            if ($i === 0) {
                $luas += $satuLuas;
            } else {
                $lubang++;
                $luas -= $satuLuas;
            }
            if (rapiRingMemotongSendiri($satu)) {
                $rusak++;
            }
        }
    }

    return ['bagian' => $bagian, 'titik' => $titik, 'lubang' => $lubang, 'rusak' => $rusak, 'luas' => $luas];
}

/** @return array<int, array<int, array>> daftar poligon, tiap poligon daftar cincin */
function rapiPoligonDariGeoJson(array $geometri): array
{
    if (($geometri['type'] ?? '') === 'Polygon') {
        return [$geometri['coordinates']];
    }
    if (($geometri['type'] ?? '') === 'MultiPolygon') {
        return $geometri['coordinates'];
    }

    throw new RuntimeException('Geometri blok bukan Polygon/MultiPolygon: ' . ($geometri['type'] ?? 'kosong'));
}

/** Larutkan bagian yang berimpit/bertumpang dengan ST_Union berpasangan. */
function rapiLarutkan(array $poligon): array
{
    if (count($poligon) <= 1) {
        return $poligon;
    }

    $akum = rapiPoligonWkt($poligon[0]);
    foreach (array_slice($poligon, 1) as $satu) {
        $akum = (string) db_value(
            'SELECT ST_AsText(ST_Union(ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326)))',
            [$akum, rapiPoligonWkt($satu)]
        );
        if ($akum === '') {
            throw new RuntimeException('ST_Union mengembalikan geometri kosong.');
        }
    }

    $geo = json_decode((string) db_value(
        'SELECT ST_AsGeoJSON(ST_GeomFromText(?, 4326))',
        [$akum]
    ), true);
    if (!is_array($geo)) {
        throw new RuntimeException('ST_AsGeoJSON gagal membaca hasil union.');
    }

    return rapiPoligonDariGeoJson($geo);
}

function rapiLuasPoligon(array $poligon, array $skala): float
{
    $luas = 0.0;
    foreach ($poligon as $cincin) {
        foreach ($cincin as $i => $satu) {
            $luas += ($i === 0 ? 1.0 : -1.0) * rapiLuasRing($satu, $skala);
        }
    }

    return $luas;
}

/** Kurangi bagian yang menumpang blok lain; kembalikan poligon baru dan luas yang dipotong. */
function rapiPotongTumpang(array $poligon, int $id, array $skala): array
{
    $wkt = rapiMultiWkt($poligon);
    $sebelum = rapiLuasPoligon($poligon, $skala);
    foreach (db_query('SELECT ST_AsText(geom) AS w FROM blok WHERE id <> ? AND geom IS NOT NULL', [$id])->fetchAll() as $lain) {
        $wkt = (string) db_value(
            'SELECT ST_AsText(ST_Difference(ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326)))',
            [$wkt, (string) $lain['w']]
        );
        if ($wkt === '') {
            return [$poligon, 0.0];
        }
    }

    $geo = json_decode((string) db_value('SELECT ST_AsGeoJSON(ST_GeomFromText(?, 4326))', [$wkt]), true);
    $hasil = rapiPoligonDariGeoJson((array) $geo);

    return [$hasil, max(0.0, $sebelum - rapiLuasPoligon($hasil, $skala))];
}

function rapiAngkaTeks(float $v, int $desimal): string
{
    return number_format($v, $desimal, ',', '.');
}

// ---------------------------------------------------------------------------
// Penyambungan bagian yang berdekatan
// ---------------------------------------------------------------------------

/** @return array{0:float,1:float,2:float} jarak dan titik terdekat pada sisi */
function rapiTitikSisi(float $px, float $py, float $ax, float $ay, float $bx, float $by): array
{
    $dx = $bx - $ax;
    $dy = $by - $ay;
    if ($dx === 0.0 && $dy === 0.0) {
        return [hypot($px - $ax, $py - $ay), $ax, $ay];
    }
    $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / ($dx * $dx + $dy * $dy);
    $t = max(0.0, min(1.0, $t));
    $cx = $ax + $t * $dx;
    $cy = $ay + $t * $dy;

    return [hypot($px - $cx, $py - $cy), $cx, $cy];
}

/**
 * Titik terdekat antara dua cincin luar, dalam meter.
 * Bagian yang sudah terlarut tidak saling menempel, jadi cukup uji
 * titik-ke-sisi dua arah.
 *
 * @return array{0:float,1:array{0:float,1:float},2:array{0:float,1:float}}
 */
function rapiTerdekat(array $a, array $b, array $skala): array
{
    $ma = array_map(static fn(array $t): array => rapiMeter($t, $skala), rapiRingBersih($a));
    $mb = array_map(static fn(array $t): array => rapiMeter($t, $skala), rapiRingBersih($b));
    $na = count($ma);
    $nb = count($mb);
    $terbaik = INF;
    $titikA = $ma[0];
    $titikB = $mb[0];
    for ($i = 0; $i < $na; $i++) {
        $a1 = $ma[$i];
        $a2 = $ma[($i + 1) % $na];
        for ($j = 0; $j < $nb; $j++) {
            $b1 = $mb[$j];
            $b2 = $mb[($j + 1) % $nb];
            foreach ([[$a1, $b1], [$a1, $b2], [$a2, $b1], [$a2, $b2]] as [$p, $q]) {
                $d = hypot($p[0] - $q[0], $p[1] - $q[1]);
                if ($d < $terbaik) {
                    $terbaik = $d;
                    $titikA = $p;
                    $titikB = $q;
                }
            }
            [$d, $cx, $cy] = rapiTitikSisi($a1[0], $a1[1], $b1[0], $b1[1], $b2[0], $b2[1]);
            if ($d < $terbaik) {
                $terbaik = $d;
                $titikA = $a1;
                $titikB = [$cx, $cy];
            }
            [$d, $cx, $cy] = rapiTitikSisi($b1[0], $b1[1], $a1[0], $a1[1], $a2[0], $a2[1]);
            if ($d < $terbaik) {
                $terbaik = $d;
                $titikA = [$cx, $cy];
                $titikB = $b1;
            }
        }
    }

    return [
        $terbaik,
        [$titikA[0] / $skala[0], $titikA[1] / $skala[1]],
        [$titikB[0] / $skala[0], $titikB[1] / $skala[1]],
    ];
}

/** @return array<int, array{0:int,1:int,2:float}> sisi pohon merentang minimum */
function rapiMst(array $jarak, int $n): array
{
    $masuk = array_fill(0, $n, false);
    $masuk[0] = true;
    $sisi = [];
    for ($k = 1; $k < $n; $k++) {
        $pilih = -1;
        $dari = -1;
        $maks = INF;
        for ($i = 0; $i < $n; $i++) {
            if (!$masuk[$i]) {
                continue;
            }
            for ($j = 0; $j < $n; $j++) {
                if ($masuk[$j]) {
                    continue;
                }
                $d = $jarak[$i][$j] ?? INF;
                if ($d < $maks) {
                    $maks = $d;
                    $pilih = $j;
                    $dari = $i;
                }
            }
        }
        if ($pilih === -1) {
            break;
        }
        $masuk[$pilih] = true;
        $sisi[] = [$dari, $pilih, $maks];
    }

    return $sisi;
}

/** @return array<int, array<int, array{0:float,1:float}>> tiap ruas jalan, daftar titik */
function rapiJalanTitik(string $wkt): ?array
{
    if (preg_match('/^LINESTRING\s*\((.*)\)$/i', trim($wkt), $cocok) !== 1) {
        return null;
    }
    $titik = [];
    foreach (explode(',', $cocok[1]) as $pasang) {
        $angka = preg_split('/\s+/', trim($pasang));
        if (count($angka) < 2) {
            continue;
        }
        $titik[] = [(float) $angka[0], (float) $angka[1]];
    }

    return count($titik) >= 2 ? $titik : null;
}

/**
 * Ruas jalan yang benar-benar melintasi jembatan (bukan sekadar menyentuh
 * ujungnya), atau null. Hanya perpotongan melintang yang dihitung, sama seperti
 * uji memotong diri pada cincin.
 *
 * @return array{kelas:string,lebar:float}|null
 */
function rapiJalanMemotong(array $jalan, array $p1, array $p2): ?array
{
    $x1 = min($p1[0], $p2[0]);
    $x2 = max($p1[0], $p2[0]);
    $y1 = min($p1[1], $p2[1]);
    $y2 = max($p1[1], $p2[1]);
    foreach ($jalan as $ruas) {
        $titik = $ruas['titik'];
        $n = count($titik);
        for ($i = 0; $i < $n - 1; $i++) {
            $q1 = $titik[$i];
            $q2 = $titik[$i + 1];
            if (max($q1[0], $q2[0]) < $x1 || min($q1[0], $q2[0]) > $x2
                || max($q1[1], $q2[1]) < $y1 || min($q1[1], $q2[1]) > $y2) {
                continue;
            }
            if (rapiSegmenPotong($p1, $p2, $q1, $q2)) {
                return $ruas;
            }
        }
    }

    return null;
}

/**
 * Pita persegi selebar 1,5 m dari $pa ke $pb, dilebihkan 0,3 m di kedua ujung
 * supaya benar-benar menempel ke dua bagian. Dibuat di PHP, bukan ST_Buffer,
 * supaya hasilnya hanya 4 titik (tanpa ujung bulat yang menambah puluhan titik).
 */
function rapiPita(array $pa, array $pb, array $skala): string
{
    $a = rapiMeter($pa, $skala);
    $b = rapiMeter($pb, $skala);
    $dx = $b[0] - $a[0];
    $dy = $b[1] - $a[1];
    $panjang = hypot($dx, $dy);
    if ($panjang === 0.0) {
        // Dua bagian bisa hampir bersentuhan sehingga titik terdekatnya sama;
        // pita kosong akan memutus sambungan, jadi pakai potongan pendek mendatar.
        $ux = 1.0;
        $uy = 0.0;
    } else {
        $ux = $dx / $panjang;
        $uy = $dy / $panjang;
    }
    $px = -$uy * RAPIKAN_JEMBATAN_SETENGAH_M;
    $py = $ux * RAPIKAN_JEMBATAN_SETENGAH_M;
    $lebih = 0.3;
    $a0 = [$a[0] - $ux * $lebih, $a[1] - $uy * $lebih];
    $b0 = [$b[0] + $ux * $lebih, $b[1] + $uy * $lebih];
    $sudut = [
        [$a0[0] + $px, $a0[1] + $py],
        [$b0[0] + $px, $b0[1] + $py],
        [$b0[0] - $px, $b0[1] - $py],
        [$a0[0] - $px, $a0[1] - $py],
    ];
    $lonlat = array_map(
        static fn(array $t): array => [$t[0] / $skala[0], $t[1] / $skala[1]],
        $sudut
    );
    $lonlat[] = $lonlat[0];

    return 'POLYGON((' . implode(',', array_map(
        static fn(array $t): string => rapiAngka($t[0]) . ' ' . rapiAngka($t[1]),
        $lonlat
    )) . '))';
}

/**
 * Sambungkan bagian yang jaraknya ≤ $maksMeter dengan jembatan selebar 1,5 m.
 * Pasangan yang di antaranya ada garis jalan OSM atau wilayah blok lain dilewati.
 *
 * @return array{0: array<int, array<int, array>>, 1: array{jembatan:int,jalan:int,blok:int,jauh:int}}
 */
function rapiRapatkan(array $poligon, array $skala, float $maksMeter, string $wktBlok, int $id, bool $abaikanJalan, bool $rinci = false, bool $abaikanBlok = false): array
{
    $n = count($poligon);
    $hitung = ['jembatan' => 0, 'jalan' => 0, 'blok' => 0, 'jauh' => 0];
    if ($n <= 1 || $maksMeter <= 0.0) {
        return [$poligon, $hitung];
    }

    $jalan = [];
    $berkas = SIP_ROOT . '/files/master peta/jalan_osm.json';
    if (!$abaikanJalan && is_file($berkas)) {
        foreach (jalanDekat($berkas, $wktBlok, 80.0) as $ruas) {
            // hanya jalan selebar 3 m atau lebih yang memisahkan blok;
            // jalur setapak/gang (1,6-3 m) dan sepeda tidak dihitung
            if ((float) $ruas['setengah_m'] * 2.0 < RAPIKAN_JALAN_LEBAR_M) {
                continue;
            }
            $titik = rapiJalanTitik((string) $ruas['wkt']);
            if ($titik !== null) {
                $jalan[] = [
                    'titik' => $titik,
                    'kelas' => (string) $ruas['kelas'],
                    'lebar' => (float) $ruas['setengah_m'] * 2.0,
                ];
            }
        }
    }

    $jarak = [];
    $titik = [];
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            [$d, $pa, $pb] = rapiTerdekat($poligon[$i][0], $poligon[$j][0], $skala);
            $jarak[$i][$j] = $jarak[$j][$i] = $d;
            $titik[$i . '-' . $j] = [$pa, $pb];
        }
    }

    $wkt = [];
    foreach ($poligon as $satu) {
        $wkt[] = rapiPoligonWkt($satu);
    }
    $pita = [];
    foreach (rapiMst($jarak, $n) as [$a, $b, $d]) {
        if ($d > $maksMeter) {
            $hitung['jauh']++;
            if ($rinci) {
                printf("    #%d-#%d %5.1f m: terlalu jauh\n", $a, $b, $d);
            }
            continue;
        }
        [$pa, $pb] = $titik[min($a, $b) . '-' . max($a, $b)];
        $garis = 'LINESTRING(' . rapiAngka($pa[0]) . ' ' . rapiAngka($pa[1]) . ','
            . rapiAngka($pb[0]) . ' ' . rapiAngka($pb[1]) . ')';
        if ($rinci) {
            printf("    #%d-#%d %.7f,%.7f -> %.7f,%.7f\n", $a, $b, $pa[0], $pa[1], $pb[0], $pb[1]);
        }
        $kenaJalan = $jalan === [] ? null : rapiJalanMemotong($jalan, $pa, $pb);
        if ($kenaJalan !== null) {
            $hitung['jalan']++;
            if ($rinci) {
                printf(
                    "    #%d-#%d %5.1f m: dilewati (jalan %s %.1f m)\n",
                    $a,
                    $b,
                    $d,
                    $kenaJalan['kelas'],
                    $kenaJalan['lebar']
                );
            }
            continue;
        }
        // Jembatan hanya dilewati bila garisnya benar-benar masuk wilayah blok
        // lain, bukan sekadar menyentuh sudut batas di ujungnya.
        if (!$abaikanBlok) {
            $lintas = db_query(
                'SELECT kode, ST_Length(ST_Intersection(geom, ST_GeomFromText(?, 4326))) * 111320 AS m
                   FROM blok
                  WHERE id <> ? AND geom IS NOT NULL
                    AND ST_Intersects(geom, ST_GeomFromText(?, 4326))
                  ORDER BY m DESC LIMIT 1',
                [$garis, $id, $garis]
            )->fetch();
            $masuk = is_array($lintas) ? (float) $lintas['m'] : 0.0;
            if ($masuk > max(0.5, $d * 0.5)) {
                $hitung['blok']++;
                if ($rinci) {
                    printf(
                        "    #%d-#%d %5.1f m: dilewati (%.1f m di wilayah %s)\n",
                        $a,
                        $b,
                        $d,
                        $masuk,
                        (string) $lintas['kode']
                    );
                }
                continue;
            }
        }
        $pita[] = rapiPita($pa, $pb, $skala);
        $hitung['jembatan']++;
        if ($rinci) {
            printf("    #%d-#%d %5.1f m: disambung\n", $a, $b, $d);
        }
    }

    if ($pita === []) {
        return [$poligon, $hitung];
    }
    if ($rinci) {
        $uji = wktAmbilPoligon(wktGabung(array_merge($wkt, $pita)));
        printf(
            "    gabung: %d bagian dari %d poligon + %d pita\n",
            (int) db_value('SELECT ST_NumGeometries(ST_GeomFromText(?, 4326))', [$uji]),
            count($wkt),
            count($pita)
        );
        foreach ($pita as $k => $p) {
            $kena = [];
            foreach ($wkt as $j => $w) {
                $luas = (float) db_value(
                    'SELECT ST_Area(ST_Intersection(ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326)))',
                    [$p, $w]
                );
                if ($luas > 0.0) {
                    $kena[] = '#' . $j;
                }
            }
            printf("    pita %d menimpa %s\n", $k, $kena === [] ? 'tidak ada' : implode(',', $kena));
        }
    }

    $gabung = wktAmbilPoligon(wktGabung(array_merge($wkt, $pita)));
    $geo = json_decode((string) db_value(
        'SELECT ST_AsGeoJSON(ST_GeomFromText(?, 4326))',
        [$gabung]
    ), true);
    if (!is_array($geo)) {
        throw new RuntimeException('Hasil penyambungan bagian blok tidak terbaca.');
    }

    return [rapiPoligonDariGeoJson($geo), $hitung];
}

// ---------------------------------------------------------------------------
// Kerja per blok
// ---------------------------------------------------------------------------

$rencana = [];
foreach ($nomor as $satuNomor) {
    $baris = db_query('SELECT id, kode, nomor, nama, ST_AsGeoJSON(geom) AS geojson FROM blok WHERE nomor = ?', [$satuNomor])->fetch();
    if (!is_array($baris)) {
        fwrite(STDERR, "Blok nomor {$satuNomor} tidak ditemukan.\n");
        exit(1);
    }
    $geoLama = json_decode((string) $baris['geojson'], true);
    if (!is_array($geoLama)) {
        fwrite(STDERR, "Geometri blok {$baris['kode']} kosong.\n");
        exit(1);
    }
    $poligonLama = rapiPoligonDariGeoJson($geoLama);
    $skala = rapiSkala($poligonLama);
    $sebelum = rapiStatistik($poligonLama, $skala);
    $poligon = $poligonLama;
    if ($dariBidang) {
        $bidang = db_query(
            'SELECT ST_AsGeoJSON(geom) AS geojson FROM bidang WHERE blok_id = ? AND geom IS NOT NULL',
            [(int) $baris['id']]
        )->fetchAll();
        $poligonBidang = [];
        foreach ($bidang as $barisBidang) {
            $geoBidang = json_decode((string) $barisBidang['geojson'], true);
            if (!is_array($geoBidang)) {
                throw new RuntimeException("Geometri bidang anggota {$baris['kode']} tidak terbaca.");
            }
            array_push($poligonBidang, ...rapiPoligonDariGeoJson($geoBidang));
        }
        if ($poligonBidang === []) {
            throw new RuntimeException("Tidak ada geometri bidang anggota untuk {$baris['kode']}.");
        }
        $poligon = rapiLarutkan($poligonBidang);
    }

    $larut = rapiLarutkan($poligon);
    $hasil = [];
    $lubangDibuang = 0;
    $tingkatTerburuk = 0;
    $luasBaru = 0.0;
    foreach ($larut as $cincin) {
        $cincin = array_values($cincin);
        if ($cincin === []) {
            continue;
        }
        $dasar = 0;
        $cincinBaru = [rapiSederhanakanAman($cincin[0], $toleransi, $skala, $dasar)];
        $tingkatTerburuk = max($tingkatTerburuk, $dasar);
        $luasBaru += rapiLuasRing($cincinBaru[0], $skala);
        for ($i = 1; $i < count($cincin); $i++) {
            if (rapiLuasRing($cincin[$i], $skala) <= $lubangMaks) {
                $lubangDibuang++;
                continue;
            }
            $cincinBaru[] = rapiSederhanakanAman($cincin[$i], $toleransi, $skala, $dasar);
            $tingkatTerburuk = max($tingkatTerburuk, $dasar);
            $luasBaru -= rapiLuasRing($cincinBaru[count($cincinBaru) - 1], $skala);
        }
        $hasil[] = $cincinBaru;
    }
    $tumpang = 0.0;
    $rapatStat = ['jembatan' => 0, 'jalan' => 0, 'blok' => 0, 'jauh' => 0];
    if (count($hasil) > 1) {
        [$hasil, $rapatStat] = rapiRapatkan($hasil, $skala, $rapat, rapiMultiWkt($poligon), (int) $baris['id'], $abaikanJalan, $rinci, $satukan);
    }
    if (!$biarkanTumpang) {
        [$hasil, $tumpang] = rapiPotongTumpang($hasil, (int) $baris['id'], $skala);
    }
    $sesudah = rapiStatistik($hasil, $skala);
    $wkt = rapiMultiWkt($hasil);
    // Uji WKT di basis data sekarang, supaya kesalahan bentuk tidak baru ketahuan
    // setelah cadangan dibuat dan baris mulai ditulis.
    $uji = db_value('SELECT ST_AsText(ST_GeomFromText(?, 4326))', [$wkt]);
    if (!is_string($uji) || $uji === '') {
        fwrite(STDERR, "WKT blok {$baris['kode']} ditolak basis data.\n");
        exit(1);
    }
    if ($satukan && $sesudah['bagian'] > 1) {
        fwrite(
            STDERR,
            "Blok {$baris['kode']} tetap {$sesudah['bagian']} bagian walau dipaksa; perubahan dibatalkan.\n"
        );
        exit(1);
    }
    $selisih = $sebelum['luas'] > 0 ? ($sesudah['luas'] - $sebelum['luas']) / $sebelum['luas'] : 0.0;
    // Bentuk yang sama persis dengan hasil rapikan terakhir berarti blok ini sudah
    // dikerjakan; pemotongan tumpang meninggalkan titik rapat di tepi bersama, dan
    // penghalusan ulang justru menggerusnya lagi.
    $jejakCocok = false;
    $jejakTerakhir = null;
    $berkasJejak = glob(SIP_ROOT . '/storage/rapikan-blok-' . (int) $baris['id'] . '-*.json');
    if (is_array($berkasJejak) && $berkasJejak !== []) {
        rsort($berkasJejak);
        $isiJejak = json_decode((string) @file_get_contents($berkasJejak[0]), true);
        $wktJejak = (string) ($isiJejak['sesudah']['wkt'] ?? '');
        if ($wktJejak !== '') {
            $jejakCocok = (int) db_value(
                'SELECT ST_Equals(geom, ST_GeomFromText(?, 4326)) FROM blok WHERE id = ?',
                [$wktJejak, (int) $baris['id']]
            ) === 1;
        }
        $jejakTerakhir = basename($berkasJejak[0]);
    }
    // Penghalusan ulang pada garis yang sudah halus hanya menggerus sedikit titik
    // dan membuat irisan baru ke tetangga; anggap blok itu sudah rapi.
    $rapi = $jejakCocok
        || ($sesudah['bagian'] === $sebelum['bagian']
            && $lubangDibuang === 0
            && $rapatStat['jembatan'] === 0
            && $sesudah['titik'] >= $sebelum['titik'] * 0.98
            && abs($selisih) < 0.0005);
    if ($dariBidang) {
        $rapi = false;
    }
    if ($satukan && $sebelum['bagian'] > 1) {
        $rapi = false;
    }

    $rencana[] = [
        'baris' => $baris,
        'skala' => $skala,
        'sebelum' => $sebelum,
        'sesudah' => $sesudah,
        'geo_lama' => $geoLama,
        'hasil' => $hasil,
        'wkt' => $wkt,
        'lubang_dibuang' => $lubangDibuang,
        'tingkat' => $tingkatTerburuk,
        'selisih' => $selisih,
        'tumpang' => $tumpang,
        'rapat' => $rapatStat,
        'jejak' => $jejakTerakhir,
        'rapi' => $rapi && !$paksa,
    ];
}

echo "Toleransi {$toleransi} m, lubang dibuang bila ≤ {$lubangMaks} m², "
    . 'jembatan sampai ' . rapiAngkaTeks($rapat, 0) . " m.\n";
if ($satukan) {
    echo "Mode satukan: uji jalan dan uji wilayah blok dimatikan, tumpang tidak dipotong.\n";
}
if ($dariBidang) {
    echo "Sumber geometri: union seluruh geometri bidang yang terdaftar pada blok; status perkiraan tidak diubah.\n";
}
echo "\n";
foreach ($rencana as $r) {
    $b = $r['baris'];
    $s = $r['sebelum'];
    $t = $r['sesudah'];
    if ($r['rapi']) {
        printf(
            "BLOK %d (%s, id %d) sudah rapi: %d bagian, %d titik — tidak ada yang diubah%s.\n\n",
            (int) $b['nomor'],
            (string) ($b['nama'] ?: $b['kode']),
            (int) $b['id'],
            $s['bagian'],
            $s['titik'],
            $r['jejak'] !== null ? ' (sama dengan ' . $r['jejak'] . ')' : ''
        );
        continue;
    }
    printf(
        "BLOK %d (%s, id %d)\n"
        . "  bagian  %d -> %d\n"
        . "  titik   %d -> %d\n"
        . "  lubang  %d -> %d (dibuang %d)\n"
        . "  ring memotong diri  %d -> %d\n"
        . "  luas    %s m² -> %s m² (%+.2f%%)\n",
        (int) $b['nomor'],
        (string) ($b['nama'] ?: $b['kode']),
        (int) $b['id'],
        $s['bagian'],
        $t['bagian'],
        $s['titik'],
        $t['titik'],
        $s['lubang'],
        $t['lubang'],
        $r['lubang_dibuang'],
        $s['rusak'],
        $t['rusak'],
        rapiAngkaTeks($s['luas'], 1),
        rapiAngkaTeks($t['luas'], 1),
        $r['selisih'] * 100
    );
    if ($r['tingkat'] > 0) {
        echo "  catatan: sebagian cincin diturunkan toleransinya agar tidak memotong diri sendiri.\n";
    }
    if ($r['rapat']['jembatan'] > 0 || $r['rapat']['jalan'] > 0
        || $r['rapat']['blok'] > 0 || $r['rapat']['jauh'] > 0) {
        printf(
            "  jembatan %d disambung; dilewati %d garis jalan, %d wilayah blok lain, %d terlalu jauh\n",
            $r['rapat']['jembatan'],
            $r['rapat']['jalan'],
            $r['rapat']['blok'],
            $r['rapat']['jauh']
        );
    }
    if ($r['tumpang'] > 0.01) {
        echo '  irisan blok lain ' . rapiAngkaTeks($r['tumpang'], 1) . " m² dipotong\n";
    }
    if ($t['bagian'] === 0 || $t['luas'] < 1.0) {
        fwrite(STDERR, "  Dibatalkan: hasilnya kosong atau berluas nol.\n");
        exit(1);
    }
    if (abs($r['selisih']) > RAPIKAN_SELISIH_LUAS_MAKS && !$paksa) {
        fwrite(STDERR, "  Dibatalkan: luas berubah lebih dari 5%. Pakai --paksa bila memang disengaja.\n");
        exit(1);
    }
    echo "\n";
}

if ($keluaran !== null) {
    $fitur = [];
    foreach ($rencana as $r) {
        $fitur[] = [
            'type' => 'Feature',
            'properties' => ['blok' => (int) $r['baris']['nomor'], 'kode' => $r['baris']['kode']],
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => $r['hasil']],
        ];
    }
    $tulis = file_put_contents(
        $keluaran,
        json_encode(['type' => 'FeatureCollection', 'features' => $fitur], JSON_UNESCAPED_SLASHES)
    );
    if ($tulis === false) {
        fwrite(STDERR, "Gagal menulis {$keluaran}.\n");
        exit(1);
    }
    echo "Calon hasil ditulis ke {$keluaran}\n";
}

if (!$terapkan) {
    echo "MODE UJI KERING: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Terapkan: cadangan penuh dulu, baru tulis
// ---------------------------------------------------------------------------

$kerjakan = array_values(array_filter($rencana, static fn(array $r): bool => !$r['rapi']));
if ($kerjakan === []) {
    echo "Semua blok sudah rapi; tidak ada yang ditulis.\n";
    exit(0);
}

$merek = 'sebelum-rapikan-blok-' . implode('-', $nomor);
$cadangan = cadangan_buat($merek);
echo 'Cadangan: ' . $cadangan['nama'] . ' (' . rapiAngkaTeks($cadangan['byte'], 0) . " byte)\n\n";

foreach ($kerjakan as $r) {
    $b = $r['baris'];
    $jejak = SIP_ROOT . '/storage/rapikan-blok-' . (int) $b['id'] . '-' . date('Ymd-His') . '.json';
    $tulis = file_put_contents($jejak, json_encode([
        'blok' => ['id' => (int) $b['id'], 'kode' => $b['kode'], 'nomor' => (int) $b['nomor']],
        'sebelum' => ['statistik' => $r['sebelum'], 'geojson' => $r['geo_lama']],
        'sesudah' => ['statistik' => $r['sesudah'], 'wkt' => $r['wkt']],
        'opsi' => [
            'toleransi_m' => $toleransi,
            'lubang_maks_m2' => $lubangMaks,
            'rapat_m' => $rapat,
            'satukan' => $satukan,
            'dari_bidang' => $dariBidang,
            'jembatan' => $r['rapat']['jembatan'],
            'dilewati_jalan' => $r['rapat']['jalan'],
            'dilewati_blok' => $r['rapat']['blok'],
            'terlalu_jauh' => $r['rapat']['jauh'],
            'tumpang_dipotong_m2' => $r['tumpang'],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($tulis === false) {
        fwrite(STDERR, "Gagal menulis jejak {$jejak}; perubahan dibatalkan.\n");
        exit(1);
    }

    db_exec('UPDATE blok SET geom = ST_GeomFromText(?, 4326) WHERE id = ?', [$r['wkt'], (int) $b['id']]);

    $baca = json_decode((string) db_value('SELECT ST_AsGeoJSON(geom) FROM blok WHERE id = ?', [(int) $b['id']]), true);
    $cek = rapiStatistik(rapiPoligonDariGeoJson($baca), $r['skala']);
    printf(
        "BLOK %d terpasang: %d bagian, %d titik, %d lubang, luas %s m². Jejak: %s\n",
        (int) $b['nomor'],
        $cek['bagian'],
        $cek['titik'],
        $cek['lubang'],
        rapiAngkaTeks($cek['luas'], 1),
        basename($jejak)
    );
}

echo "\nSelesai. Periksa peta: blok yang dirapikan pada peta.php, lalu centang \"Batas blok\".\n";
