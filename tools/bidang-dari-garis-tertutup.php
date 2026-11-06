<?php
declare(strict_types=1);

/**
 * Konversi garis tertutup (closed LineString) dari layer 020100 menjadi bidang baru.
 * 
 * Usage:
 *   php tools/bidang-dari-garis-tertutup.php --lihat
 *   php tools/bidang-dari-garis-tertutup.php --terapkan
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/lib-impor.php';

$terapkan = in_array('--terapkan', $argv, true);
$lihat = in_array('--lihat', $argv, true) || !$terapkan;

if (!$terapkan && !$lihat) {
    fwrite(STDERR, "Gunakan --lihat atau --terapkan\n");
    exit(1);
}

// Baca GeoJSON layer 020100
$file = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/020100.geojson';
if (!is_file($file)) {
    fwrite(STDERR, "File 020100.geojson tidak ditemukan: $file\n");
    exit(1);
}

$data = json_decode(file_get_contents($file), true);
if (!$data || !isset($data['features'])) {
    fwrite(STDERR, "File GeoJSON tidak valid\n");
    exit(1);
}

echo "Membaca layer 020100...\n";

// Kumpulkan garis tertutup
$garisTertutup = [];
foreach ($data['features'] as $feature) {
    $geom = $feature['geometry'] ?? null;
    if (!$geom || ($geom['type'] ?? '') !== 'LineString') {
        continue;
    }
    
    $coords = $geom['coordinates'] ?? [];
    if (count($coords) < 4) {
        continue;
    }
    
    // Cek apakah garis tertutup (titik pertama = titik terakhir)
    if ($coords[0] === $coords[count($coords) - 1]) {
        $garisTertutup[] = $feature;
    }
}

echo "Garis tertutup ditemukan: " . count($garisTertutup) . "\n\n";

if (count($garisTertutup) === 0) {
    echo "Tidak ada garis tertutup yang bisa dikonversi.\n";
    exit(0);
}

// Konversi ke polygon dan siapkan data untuk insert
$bidangBaru = [];
foreach ($garisTertutup as $index => $feature) {
    $coords = $feature['geometry']['coordinates'];
    
    // Transformasi koordinat DXF ke WGS84
    $coordsWGS84 = [];
    foreach ($coords as $coord) {
        $wgs = dxfKeWgs84($coord[0], $coord[1]);
        $coordsWGS84[] = $wgs;
    }
    
    // Buat WKT Polygon dari LineString tertutup
    $wktPoints = [];
    foreach ($coordsWGS84 as $p) {
        $wktPoints[] = $p[0] . ' ' . $p[1];
    }
    $wktPolygon = 'POLYGON((' . implode(',', $wktPoints) . '))';
    
    // Hitung luas dan centroid
    $pdo = db();
    $stmt = $pdo->prepare("SELECT ST_Area(ST_GeomFromText(?, 4326)), ST_AsText(ST_Centroid(ST_GeomFromText(?, 4326)))");
    $stmt->execute([$wktPolygon, $wktPolygon]);
    $row = $stmt->fetch();
    
    $luasM2 = $row[0] ?? 0;
    $centroidWKT = $row[1] ?? '';
    $pusatLon = null;
    $pusatLat = null;
    
    if (preg_match('/POINT\s*\(\s*(-?[0-9.]+)\s+(-?[0-9.]+)/i', $centroidWKT, $matches)) {
        $pusatLon = (float) $matches[1];
        $pusatLat = (float) $matches[2];
    }
    
    $bidangBaru[] = [
        'kode' => 'Garis-' . str_pad((string)($index + 1), 5, '0', STR_PAD_LEFT),
        'tipe' => 'lain',
        'status' => 'perlu_verifikasi',
        'sumber' => '020100-garis',
        'luas_m2' => round($luasM2, 2),
        'wkt' => $wktPolygon,
        'pusat_lon' => $pusatLon,
        'pusat_lat' => $pusatLat,
    ];
}

echo "Bidang baru yang akan dibuat: " . count($bidangBaru) . "\n";
echo "Total luas: " . number_format(array_sum(array_column($bidangBaru, 'luas_m2')), 2) . " m²\n\n";

if ($lihat) {
    echo "=== Preview 10 bidang pertama ===\n";
    foreach (array_slice($bidangBaru, 0, 10) as $i => $b) {
        echo ($i + 1) . ". Kode: {$b['kode']}, Luas: {$b['luas_m2']} m², Tipe: {$b['tipe']}, Status: {$b['status']}\n";
    }
    echo "\nGunakan --terapkan untuk menyimpan ke database.\n";
    exit(0);
}

// Terapkan: insert ke database
echo "Menyimpan ke database...\n";

$pdo = db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("
        INSERT INTO bidang (kode, tipe, status, sumber, luas_m2, geom, pusat_lon, pusat_lat, dibuat_pada, diubah_pada)
        VALUES (?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, NOW(), NOW())
    ");
    
    $berhasil = 0;
    $gagal = 0;
    
    foreach ($bidangBaru as $b) {
        try {
            $stmt->execute([
                $b['kode'],
                $b['tipe'],
                $b['status'],
                $b['sumber'],
                $b['luas_m2'],
                $b['wkt'],
                $b['pusat_lon'],
                $b['pusat_lat'],
            ]);
            $berhasil++;
        } catch (Exception $e) {
            $gagal++;
            if ($gagal <= 5) {
                echo "  Gagal insert {$b['kode']}: " . $e->getMessage() . "\n";
            }
        }
    }
    
    $pdo->commit();
    
    echo "\n=== Selesai ===\n";
    echo "Berhasil: $berhasil bidang\n";
    echo "Gagal: $gagal bidang\n";
    echo "Total: " . ($berhasil + $gagal) . " bidang\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
