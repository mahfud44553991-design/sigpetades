<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
wajib_masuk();

header('Content-Type: application/json');

$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lon = isset($_GET['lon']) ? (float) $_GET['lon'] : null;
$radius = isset($_GET['radius']) ? (float) $_GET['radius'] : 1000; // Default 1km
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

if ($lat === null || $lon === null) {
    json_response(['sukses' => false, 'pesan' => 'Parameter lat dan lon wajib diisi']);
}

if ($lat < -90 || $lat > 90) {
    json_response(['sukses' => false, 'pesan' => 'Latitude harus antara -90 dan 90']);
}

if ($lon < -180 || $lon > 180) {
    json_response(['sukses' => false, 'pesan' => 'Longitude harus antara -180 dan 180']);
}

if ($radius <= 0 || $radius > 50000) {
    json_response(['sukses' => false, 'pesan' => 'Radius harus antara 0 dan 50000 meter']);
}

if ($limit <= 0 || $limit > 100) {
    $limit = 20;
}

try {
    // Gunakan ST_Distance_Sphere untuk hitung jarak dalam meter
    // Saringan status mengikuti konvensi BIDANG_JOIN/bidang_filter(): bidang
    // berstatus perlu_verifikasi tetap boleh dipakai sebagai referensi digitasi.
    $sql = "SELECT b.id, b.kode, b.nib, b.luas_m2, b.status, b.blok_id, b.dusun_id,
                   bk.nama AS blok_nama, d.nama AS dusun_nama,
                   h.pemegang_nama,
                   ST_Distance_Sphere(
                       ST_GeomFromText(CONCAT('POINT(', b.pusat_lon, ' ', b.pusat_lat, ')'), 4326),
                       ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326)
                   ) AS jarak_meter
            FROM bidang b
            LEFT JOIN blok bk ON b.blok_id = bk.id
            LEFT JOIN dusun d ON b.dusun_id = d.id
            LEFT JOIN hak h ON b.id = h.bidang_id
            WHERE b.pusat_lon IS NOT NULL 
              AND b.pusat_lat IS NOT NULL
              AND b.status NOT IN ('dihapus', 'duplikat')
              AND ST_Distance_Sphere(
                  ST_GeomFromText(CONCAT('POINT(', b.pusat_lon, ' ', b.pusat_lat, ')'), 4326),
                  ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326)
              ) <= ?
            ORDER BY jarak_meter ASC
            LIMIT ?";

    $stmt = db()->prepare($sql);
    $stmt->execute([$lon, $lat, $lon, $lat, $radius, $limit]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_response([
        'sukses' => true,
        'data' => array_map(function($b) {
            return [
                'id' => $b['id'],
                'kode' => $b['kode'],
                'nib' => $b['nib'],
                'luas_m2' => (float)($b['luas_m2'] ?? 0),
                'status' => $b['status'],
                'blok_id' => $b['blok_id'],
                'dusun_id' => $b['dusun_id'],
                'blok_nama' => $b['blok_nama'] ?? '',
                'dusun_nama' => $b['dusun_nama'] ?? '',
                'pemegang_nama' => $b['pemegang_nama'] ?? '',
                'jarak_meter' => round((float)$b['jarak_meter'], 2),
                'label' => $b['kode'] . ' | ' . number_format((float)($b['luas_m2'] ?? 0)) . ' m² | ' . 
                          round((float)$b['jarak_meter'], 0) . 'm' .
                          ($b['blok_nama'] ? ' | ' . $b['blok_nama'] : '') .
                          ($b['pemegang_nama'] ? ' | ' . $b['pemegang_nama'] : '') .
                          ($b['status'] === 'aktif' ? '' : ' | ' . str_replace('_', ' ', $b['status']))
            ];
        }, $results),
        'jumlah' => count($results),
        'center' => ['lat' => $lat, 'lon' => $lon],
        'radius' => $radius
    ]);
} catch (PDOException $e) {
    json_response(['sukses' => false, 'pesan' => 'Database error: ' . $e->getMessage()], 500);
}
