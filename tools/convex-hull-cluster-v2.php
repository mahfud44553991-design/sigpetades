<?php
declare(strict_types=1);

/**
 * Buat bidang dari convex hull cluster garis terbuka (menggunakan MariaDB ST_ConvexHull)
 * 
 * Usage:
 *   php tools/convex-hull-cluster-v2.php --lihat
 *   php tools/convex-hull-cluster-v2.php --terapkan
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/lib-impor.php';

$terapkan = in_array('--terapkan', $argv, true);
$lihat = in_array('--lihat', $argv, true) || !$terapkan;

if (!$terapkan && !$lihat) {
    fwrite(STDERR, "Gunakan --lihat atau --terapkan\n");
    exit(1);
}

// Baca GeoJSON
$file = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/020100.geojson';
$data = json_decode(file_get_contents($file), true);

echo "Membaca layer 020100...\n";

// Kumpulkan semua endpoint dari garis terbuka
$endpoints = [];
foreach ($data['features'] as $feature) {
    $geom = $feature['geometry'] ?? null;
    if (!$geom || ($geom['type'] ?? '') !== 'LineString') continue;
    
    $coords = $geom['coordinates'] ?? [];
    if (count($coords) < 2) continue;
    
    // Ambil endpoint awal dan akhir
    $start = $coords[0];
    $end = $coords[count($coords) - 1];
    
    // Transformasi ke to WGS84
    $startWGS = dxfKeWgs84($start[0], $start[1]);
    $endWGS = dxfKeWgs84($end[0], $end[1]);
    
    $endpoints[] = $startWGS;
    $endpoints[] = $endWGS;
}

echo "Total endpoint: " . count($endpoints) . "\n\n";

// Parameter clustering
$clusterRadius = 50; // meter
$minPoints = 15;

echo "Clustering endpoint (radius: {$clusterRadius}m, min points: {$minPoints})...\n";

// DBSCAN sederhana
function distanceMeters($p1, $p2) {
    $dx = ($p2[0] - $p1[0]) * 111000 * cos(deg2rad(($p1[1] + $p2[1]) / 2));
    $dy = ($p2[1] - $p1[1]) * 111000;
    return sqrt($dx * $dx + $dy * $dy);
}

$clusters = [];
$visited = array_fill(0, count($endpoints), false);

for ($i = 0; $i < count($endpoints); $i++) {
    if ($visited[$i]) continue;
    
    $visited[$i] = true;
    $cluster = [$endpoints[$i]];
    $queue = [$i];
    
    while (!empty($queue)) {
        $current = array_shift($queue);
        
        for ($j = 0; $j < count($endpoints); $j++) {
            if ($visited[$j]) continue;
            
            $dist = distanceMeters($endpoints[$current], $endpoints[$j]);
            if ($dist < $clusterRadius) {
                $visited[$j] = true;
                $cluster[] = $endpoints[$j];
                $queue[] = $j;
            }
        }
    }
    
    if (count($cluster) >= $minPoints) {
        $clusters[] = $cluster;
    }
}

echo "Cluster ditemukan: " . count($clusters) . "\n\n";

if (count($clusters) === 0) {
    echo "Tidak ada cluster yang memenuhi kriteria.\n";
    exit(0);
}

// Buat convex hull menggunakan MariaDB
echo "Membuat convex hull dengan MariaDB ST_ConvexHull...\n";

$pdo = db();
$polygons = [];

foreach ($clusters as $clusterIdx => $cluster) {
    // Skip cluster yang terlalu besar (kemungkinan seluruh area)
    if (count($cluster) > 1000) {
        echo "  Cluster #$clusterIdx: " . count($cluster) . " points - SKIP (terlalu besar)\n";
        continue;
    }
    
    // Buat MULTIPOINT WKT
    $wktPoints = [];
    foreach ($cluster as $p) {
        $wktPoints[] = $p[0] . ' ' . $p[1];
    }
    $wktMultiPoint = 'MULTIPOINT(' . implode(',', $wktPoints) . ')';
    
    // Gunakan ST_ConvexHull
    $stmt = $pdo->prepare("SELECT ST_AsText(ST_ConvexHull(ST_GeomFromText(?, 4326))), ST_Area(ST_ConvexHull(ST_GeomFromText(?, 4326)))");
    $stmt->execute([$wktMultiPoint, $wktMultiPoint]);
    $row = $stmt->fetch();
    
    if (!$row || $row[0] === null) {
        echo "  Cluster #$clusterIdx: " . count($cluster) . " points - SKIP (convex hull gagal)\n";
        continue;
    }
    
    $hullWKT = $row[0];
    $luasM2 = $row[1] ?? 0;
    
    if ($luasM2 > 10000) {
        $polygons[] = [
            'wkt' => $hullWKT,
            'luas' => $luasM2,
            'cluster_idx' => $clusterIdx,
            'points' => count($cluster),
        ];
    } else {
        echo "  Cluster #$clusterIdx: " . count($cluster) . " points, luas = " . number_format($luasM2, 2) . " m²\n";
    }
}

echo "\nPoligon dengan luas > 10.000 m²: " . count($polygons) . "\n\n";

if (count($polygons) === 0) {
    echo "Tidak ada poligon yang memenuhi kriteria luas > 10.000 m².\n";
    exit(0);
}

// Tampilkan preview
if ($lihat) {
    echo "=== Preview convex hull polygons ===\n";
    foreach ($polygons as $i => $p) {
        echo ($i + 1) . ". Cluster #{$p['cluster_idx']}, Points: {$p['points']}, Luas: " . number_format($p['luas'], 2) . " m²\n";
    }
    echo "\nTotal: " . count($polygons) . " poligon\n";
    echo "Total luas: " . number_format(array_sum(array_column($polygons, 'luas')), 2) . " m²\n";
    echo "\nGunakan --terapkan untuk menyimpan ke database.\n";
    exit(0);
}

// Terapkan: insert ke database
echo "Menyimpan ke database...\n";

$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare("
        INSERT INTO bidang (kode, tipe, status, sumber, luas_m2, geom, dibuat_pada, diubah_pada)
        VALUES (?, 'lain', 'perlu_verifikasi', '020100-hull', ?, ST_GeomFromText(?, 4326), NOW(), NOW())
    ");
    
    $berhasil = 0;
    foreach ($polygons as $i => $p) {
        $kode = 'Hull-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);
        $stmt->execute([$kode, round($p['luas'], 2), $p['wkt']]);
        $berhasil++;
    }
    
    $pdo->commit();
    
    echo "\n=== Selesai ===\n";
    echo "Berhasil: $berhasil bidang\n";
    echo "Total luas: " . number_format(array_sum(array_column($polygons, 'luas')), 2) . " m²\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
