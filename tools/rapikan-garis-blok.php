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
 *   4. memotong bagian yang menumpang garis blok lain (bila ada), supaya
 *      penghalusan tidak membuat dua blok bertumpang tindih.
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
 *   php tools/rapikan-garis-blok.php 3 --keluaran=cek.geojson
 *                                                    (tulis calon hasil untuk dilihat di peta)
 */

require_once __DIR__ . '/lib-impor.php';
require_once __DIR__ . '/../app/lib-pengaturan.php';

const RAPIKAN_TOLERANSI_M = 1.5;
const RAPIKAN_LUBANG_MAKS_M2 = 500.0;
const RAPIKAN_SELISIH_LUAS_MAKS = 0.05;

$argv = $_SERVER['argv'] ?? [];
$terapkan = in_array('--terapkan', $argv, true);
$paksa = in_array('--paksa', $argv, true);
$biarkanTumpang = in_array('--biarkan-tumpang', $argv, true);
$toleransi = RAPIKAN_TOLERANSI_M;
$lubangMaks = RAPIKAN_LUBANG_MAKS_M2;
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
// Kerja per blok
// ---------------------------------------------------------------------------

$rencana = [];
foreach ($nomor as $satuNomor) {
    $baris = db_query('SELECT id, kode, nomor, nama, ST_AsGeoJSON(geom) AS geojson FROM blok WHERE nomor = ?', [$satuNomor])->fetch();
    if (!is_array($baris)) {
        fwrite(STDERR, "Blok nomor {$satuNomor} tidak ditemukan.\n");
        exit(1);
    }
    $geo = json_decode((string) $baris['geojson'], true);
    if (!is_array($geo)) {
        fwrite(STDERR, "Geometri blok {$baris['kode']} kosong.\n");
        exit(1);
    }
    $poligon = rapiPoligonDariGeoJson($geo);
    $skala = rapiSkala($poligon);
    $sebelum = rapiStatistik($poligon, $skala);

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
    $selisih = $sebelum['luas'] > 0 ? ($sesudah['luas'] - $sebelum['luas']) / $sebelum['luas'] : 0.0;
    // Penghalusan ulang pada garis yang sudah halus hanya menggerus sedikit titik
    // dan membuat irisan baru ke tetangga; anggap blok itu sudah rapi.
    $rapi = $sesudah['bagian'] === $sebelum['bagian']
        && $lubangDibuang === 0
        && $sesudah['titik'] >= $sebelum['titik'] * 0.98
        && abs($selisih) < 0.0005;

    $rencana[] = [
        'baris' => $baris,
        'skala' => $skala,
        'sebelum' => $sebelum,
        'sesudah' => $sesudah,
        'geo_lama' => $geo,
        'hasil' => $hasil,
        'wkt' => $wkt,
        'lubang_dibuang' => $lubangDibuang,
        'tingkat' => $tingkatTerburuk,
        'selisih' => $selisih,
        'tumpang' => $tumpang,
        'rapi' => $rapi && !$paksa,
    ];
}

echo "Toleransi {$toleransi} m, lubang dibuang bila ≤ {$lubangMaks} m².\n\n";
foreach ($rencana as $r) {
    $b = $r['baris'];
    $s = $r['sebelum'];
    $t = $r['sesudah'];
    if ($r['rapi']) {
        printf(
            "BLOK %d (%s, id %d) sudah rapi: %d bagian, %d titik — tidak ada yang diubah.\n\n",
            (int) $b['nomor'],
            (string) ($b['nama'] ?: $b['kode']),
            (int) $b['id'],
            $s['bagian'],
            $s['titik']
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
        'opsi' => ['toleransi_m' => $toleransi, 'lubang_maks_m2' => $lubangMaks, 'tumpang_dipotong_m2' => $r['tumpang']],
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
