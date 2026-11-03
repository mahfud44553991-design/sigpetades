<?php
declare(strict_types=1);

/**
 * Buat bidang dari garis 020100 yang beririsan dengan Batas desa (20 PTP).
 *
 *   php tools/bidang-dari-batas-desa.php --lihat
 *   php tools/bidang-dari-batas-desa.php --terapkan
 *
 * Alur:
 *   1. Baca 20 titik PTP dari BATAS DESA layer
 *   2. Transformasi ke WGS84
 *   3. Buat convex hull dari titik-titik tersebut (poligon batas desa)
 *   4. Cari bidang 020100 (perlu_verifikasi) yang beririsan dengan hull
 *   5. Tandai sebagai 'aktif' (dari 'perlu_verifikasi')
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/lib-impor.php';

$terapkan = in_array('--terapkan', $argv, true);
$lihat = in_array('--lihat', $argv, true) || !$terapkan;

if (!$terapkan && !$lihat) {
    fwrite(STDERR, "Gunakan --lihat atau --terapkan\n");
    exit(1);
}

// 1. Baca titik PTP dari BATAS DESA
$file = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/batas-desa.geojson';
if (!is_file($file)) {
    fwrite(STDERR, "File BATAS DESA tidak ditemukan: $file\n");
    exit(1);
}

$data = json_decode(file_get_contents($file), true);
$titikPTP = [];
foreach ($data['features'] as $f) {
    if (($f['properties']['label'] ?? '') === 'PTP') {
        $coord = $f['geometry']['coordinates'];
        $titikPTP[] = $coord;
    }
}

echo "Titik PTP ditemukan: " . count($titikPTP) . "\n";

if (count($titikPTP) < 3) {
    fwrite(STDERR, "Minimal 3 titik PTP diperlukan untuk membuat poligon\n");
    exit(1);
}

// 2. Transformasi ke WGS84 dan buat convex hull
$titikWGS84 = [];
foreach ($titikPTP as $t) {
    $wgs = dxfKeWgs84($t[0], $t[1]);
    $titikWGS84[] = $wgs;
}

// Convex hull sederhana: urutkan titik berdasarkan sudut dari centroid
$centroidX = array_sum(array_column($titikWGS84, 0)) / count($titikWGS84);
$centroidY = array_sum(array_column($titikWGS84, 1)) / count($titikWGS84);

usort($titikWGS84, function($a, $b) use ($centroidX, $centroidY) {
    $angleA = atan2($a[1] - $centroidY, $a[0] - $centroidX);
    $angleB = atan2($b[1] - $centroidY, $b[0] - $centroidX);
    return $angleA <=> $angleB;
});

// Buat WKT polygon dari titik-titik yang sudah diurutkan
$titikWKT = [];
foreach ($titikWGS84 as $t) {
    $titikWKT[] = $t[0] . ' ' . $t[1];
}
// Tutup poligon (titik pertama = titik terakhir)
$titikWKT[] = $titikWKT[0];
$wktHull = 'POLYGON((' . implode(',', $titikWKT) . '))';

echo "Convex hull dibuat dari " . count($titikPTP) . " titik PTP (sudah transformasi ke WGS84)\n";

// 3. Cari bidang 020100 yang beririsan dengan hull
$query = "
    SELECT id, kode, tipe, luas_m2, status, sumber,
           ST_AsText(geom) as geom_wkt
    FROM bidang
    WHERE sumber = '020100'
      AND status = 'perlu_verifikasi'
      AND ST_Intersects(geom, ST_GeomFromText(?, 4326))
    ORDER BY luas_m2 DESC
";

$stmt = db_query($query, [$wktHull]);
$bidangIrisan = $stmt->fetchAll();

echo "Bidang 020100 (perlu_verifikasi) yang beririsan dengan Batas desa: " . count($bidangIrisan) . "\n\n";

if (count($bidangIrisan) === 0) {
    echo "Tidak ada bidang yang beririsan.\n";
    exit(0);
}

// Tampilkan ringkasan
$totalLuas = 0;
foreach ($bidangIrisan as $b) {
    $totalLuas += (float) $b['luas_m2'];
    if ($lihat) {
        echo sprintf(
            "  %-20s  tipe=%-5s  luas=%10.2f m2  status=%s\n",
            $b['kode'],
            $b['tipe'],
            $b['luas_m2'],
            $b['status']
        );
    }
}

echo "\nTotal luas: " . number_format($totalLuas, 2) . " m2\n";

if ($terapkan) {
    echo "\nMenandai " . count($bidangIrisan) . " bidang sebagai 'aktif'...\n";
    
    $ids = array_column($bidangIrisan, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $updateStmt = db_query(
        "UPDATE bidang SET status = 'aktif' WHERE id IN ($placeholders) AND status = 'perlu_verifikasi'",
        $ids
    );
    
    echo "Bidang diperbarui: " . $updateStmt->rowCount() . "\n";
    
    // Verifikasi
    $verifStmt = db_query(
        "SELECT COUNT(*) as jumlah FROM bidang WHERE sumber = '020100' AND status = 'aktif'"
    );
    $verif = $verifStmt->fetch();
    echo "Total bidang 020100 aktif sekarang: " . $verif['jumlah'] . "\n";
} else {
    echo "\nGunakan --terapkan untuk menandai bidang sebagai 'aktif'.\n";
}
