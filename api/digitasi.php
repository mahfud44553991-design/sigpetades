<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
wajib_masuk();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sukses' => false, 'pesan' => 'Method tidak diizinkan'], 405);
}

if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['sukses' => false, 'pesan' => 'Token CSRF tidak valid. Muat ulang halaman.'], 403);
}

$kode = trim($_POST['kode'] ?? '');
$nib = trim($_POST['nib'] ?? '') ?: null;
$jenis_hak_id = (int) ($_POST['jenis_hak_id'] ?? 0);
$luas_m2 = $_POST['luas_m2'] !== '' ? (float) $_POST['luas_m2'] : null;
$blok_id = $_POST['blok_id'] !== '' ? (int) $_POST['blok_id'] : null;
$dusun_id = $_POST['dusun_id'] !== '' ? (int) $_POST['dusun_id'] : null;
$catatan = trim($_POST['catatan'] ?? '') ?: null;
$geojson = $_POST['geojson'] ?? '';

if ($kode === '') {
    json_response(['sukses' => false, 'pesan' => 'Kode bidang wajib diisi']);
}

if ($jenis_hak_id === 0) {
    json_response(['sukses' => false, 'pesan' => 'Jenis hak wajib dipilih']);
}

if ($geojson === '') {
    json_response(['sukses' => false, 'pesan' => 'Polygon belum dibuat (minimal 3 titik)']);
}

// Validasi GeoJSON
$geojson_data = json_decode($geojson, true);
if (!is_array($geojson_data) || ($geojson_data['type'] ?? '') !== 'Polygon') {
    json_response(['sukses' => false, 'pesan' => 'Format GeoJSON tidak valid']);
}

$coordinates = $geojson_data['coordinates'][0] ?? [];
if (count($coordinates) < 4) {
    json_response(['sukses' => false, 'pesan' => 'Polygon harus memiliki minimal 3 titik (4 dengan penutup)']);
}

// Konversi GeoJSON ke WKT
$points = [];
foreach ($coordinates as $coord) {
    $lon = (float) $coord[0];
    $lat = (float) $coord[1];
    $points[] = "$lon $lat";
}
$wkt = 'POLYGON((' . implode(',', $points) . '))';

// Hitung luas jika belum ada
if ($luas_m2 === null) {
    $luas_m2 = hitung_luas_dari_wkt($wkt);
}

// Hitung pusat polygon
$pusat = hitung_pusat_dari_wkt($wkt);

// Blok kosong: pakai blok resmi terkecil yang memuat titik pusat bidang
$blok_otomatis = false;
if ($blok_id === null) {
    $blok = db_one(
        'SELECT id, kode, nama
           FROM blok
          WHERE geom IS NOT NULL
            AND batas_perkiraan = 0
            AND ST_Contains(geom, ST_Centroid(ST_GeomFromText(?, 4326)))
          ORDER BY ST_Area(geom) ASC
          LIMIT 1',
        [$wkt]
    );
    if ($blok !== null) {
        $blok_id = (int) $blok['id'];
        $blok_otomatis = true;
    }
}

// Cek kode duplikat
$cek = db()->prepare("SELECT id FROM bidang WHERE kode = ?");
$cek->execute([$kode]);
if ($cek->fetch()) {
    json_response(['sukses' => false, 'pesan' => 'Kode bidang sudah ada: ' . $kode]);
}

// Simpan
try {
    $sql = "INSERT INTO bidang (kode, tipe, nib, luas_m2, blok_id, dusun_id, status, sumber, catatan, geom, pusat_lon, pusat_lat)
            VALUES (?, 'tanah', ?, ?, ?, ?, 'aktif', 'digitasi', ?, ST_GeomFromText(?, 4326), ?, ?)";

    $stmt = db()->prepare($sql);
    $stmt->execute([
        $kode,
        $nib,
        $luas_m2,
        $blok_id,
        $dusun_id,
        $catatan,
        $wkt,
        $pusat['lon'],
        $pusat['lat']
    ]);

    $id = (int) db()->lastInsertId();

    // Simpan ke tabel hak
    $hakSql = "INSERT INTO hak (bidang_id, jenis_hak_id, catatan) VALUES (?, ?, ?)";
    $hakStmt = db()->prepare($hakSql);
    $hakStmt->execute([$id, $jenis_hak_id, 'Dibuat via digitasi manual']);

    $keterangan = "Bidang baru dibuat via digitasi: $kode";
    if ($blok_id !== null && $blok_otomatis) {
        $keterangan .= ' (blok otomatis dari titik pusat)';
    }
    audit('buat', 'bidang', (string) $id, $keterangan);

    json_response([
        'sukses' => true,
        'id' => $id,
        'kode' => $kode,
        'blok_id' => $blok_id,
        'blok' => $blok_id !== null ? db_value('SELECT kode FROM blok WHERE id = ?', [$blok_id]) : null,
        'pesan' => 'Bidang berhasil disimpan'
    ]);
} catch (PDOException $e) {
    json_response(['sukses' => false, 'pesan' => 'Database error: ' . $e->getMessage()], 500);
}

function hitung_luas_dari_wkt(string $wkt): float {
    // Extract coordinates from WKT
    preg_match('/POLYGON\(\((.+)\)\)/', $wkt, $matches);
    if (!isset($matches[1])) return 0.0;

    $points = [];
    $pairs = explode(',', $matches[1]);
    foreach ($pairs as $pair) {
        $coords = explode(' ', trim($pair));
        if (count($coords) >= 2) {
            $points[] = [(float) $coords[0], (float) $coords[1]];
        }
    }

    // Shoelace formula untuk luas dalam derajat
    $n = count($points);
    if ($n < 3) return 0.0;

    $luas_derajat = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $luas_derajat += $points[$i][0] * $points[$j][1];
        $luas_derajat -= $points[$j][0] * $points[$i][1];
    }
    $luas_derajat = abs($luas_derajat) / 2.0;

    // Konversi derajat persegi ke meter persegi (approximasi di khatulistiwa)
    // 1 derajat ≈ 111,320 m
    $luas_m2 = $luas_derajat * pow(111320, 2);

    return round($luas_m2, 2);
}

function hitung_pusat_dari_wkt(string $wkt): array {
    preg_match('/POLYGON\(\((.+)\)\)/', $wkt, $matches);
    if (!isset($matches[1])) return ['lon' => 0, 'lat' => 0];

    $points = [];
    $pairs = explode(',', $matches[1]);
    foreach ($pairs as $pair) {
        $coords = explode(' ', trim($pair));
        if (count($coords) >= 2) {
            $points[] = [(float) $coords[0], (float) $coords[1]];
        }
    }

    $n = count($points);
    if ($n === 0) return ['lon' => 0, 'lat' => 0];

    $sum_lon = 0.0;
    $sum_lat = 0.0;
    foreach ($points as $p) {
        $sum_lon += $p[0];
        $sum_lat += $p[1];
    }

    return [
        'lon' => round($sum_lon / $n, 7),
        'lat' => round($sum_lat / $n, 7)
    ];
}
