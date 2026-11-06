<?php
declare(strict_types=1);

/**
 * Buat bidang dari convex hull cluster garis terbuka
 * 
 * Algoritma:
 * 1. Kumpulkan semua endpoint dari garis terbuka
 * 2. Cluster endpoint yang berdekatan (DBSCAN sederhana)
 * 3. Buat convex hull untuk setiap cluster
 * 4. Hitung luas dan filter > 10.000 m²
 * 5. Insert sebagai bidang baru
 * 
 * Usage:
 *   php tools/convex-hull-cluster.php --lihat
 *   php tools/convex-hull-cluster.php --terapkan
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
    
    // Transformasi ke WGS84
    $startWGS = dxfKeWgs84($start[0], $start[1]);
    $endWGS = dxfKeWgs84($end[0], $end[1]);
    
    $endpoints[] = $startWGS;
    $endpoints[] = $endWGS;
}

echo "Total endpoint: " . count($endpoints) . "\n\n";

// Parameter clustering
$clusterRadius = 50; // meter - radius sangat kecil untuk cluster lokal
$minPoints = 15; // minimum endpoint dalam satu cluster

echo "Clustering endpoint (radius: {$clusterRadius}m, min points: {$minPoints})...\n";

// DBSCAN sederhana
function distanceMeters($p1, $p2) {
    // Aproksimasi jarak dalam meter menggunakan Euclidean distance pada koordinat WGS84
    // 1 degree ≈ 111km di ekuator
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

// Buat convex hull untuk setiap cluster
echo "Membuat convex hull...\n";

function convexHull($points) {
    if (count($points) < 3) return $points;
    
    // Cari titik paling bawah (y minimum)
    $minIdx = 0;
    for ($i = 1; $i < count($points); $i++) {
        if ($points[$i][1] < $points[$minIdx][1] || 
            ($points[$i][1] == $points[$minIdx][1] && $points[$i][0] < $points[$minIdx][0])) {
            $minIdx = $i;
        }
    }
    
    // Swap titik pertama dengan titik paling bawah
    [$points[0], $points[$minIdx]] = [$points[$minIdx], $points[0]];
    $pivot = $points[0];
    
    // Urutkan berdasarkan sudut terhadap pivot
    usort($points, function($a, $b) use ($pivot) {
        $angleA = atan2($a[1] - $pivot[1], $a[0] - $pivot[0]);
        $angleB = atan2($b[1] - $pivot[1], $b[0] - $pivot[0]);
        return $angleA <=> $angleB;
    });
    
    // Graham scan
    $hull = [$points[0], $points[1]];
    
    for ($i = 2; $i < count($points); $i++) {
        while (count($hull) > 1) {
            $top = count($hull) - 1;
            $cross = ($hull[$top][0] - $hull[$top-1][0]) * ($points[$i][1] - $hull[$top-1][1]) -
                     ($hull[$top][1] - $hull[$top-1][1]) * ($points[$i][0] - $hull[$top-1][0]);
            if ($cross <= 0) {
                array_pop($hull);
            } else {
                break;
            }
        }
        $hull[] = $points[$i];
    }
    
    return $hull;
}

$polygons = [];
foreach ($clusters as $clusterIdx => $cluster) {
    $hull = convexHull($cluster);
    
    if (count($hull) < 3) continue;
    
    // Pastikan ring tertutup
    if ($hull[0] !== end($hull)) {
        $hull[] = $hull[0];
    }
    
    // Buat WKT
    $wktPoints = [];
    foreach ($hull as $p) {
        $wktPoints[] = $p[0] . ' ' . $p[1];
    }
    $wktPolygon = 'POLYGON((' . implode(',', $wktPoints) . '))';
    
    // Hitung luas
    $pdo = db();
    $stmt = $pdo->prepare("SELECT ST_Area(ST_GeomFromText(?, 4326))");
    $stmt->execute([$wktPolygon]);
    $luasM2 = $stmt->fetchColumn();
    
    if ($luasM2 > 10000) {
        $polygons[] = [
            'wkt' => $wktPolygon,
            'luas' => $luasM2,
            'cluster_idx' => $clusterIdx,
            'points' => count($cluster),
        ];
    } else {
        // Debug: tampilkan cluster dengan luas < 10.000 m²
        echo "  Cluster #$clusterIdx: " . count($cluster) . " points, luas = " . number_format($luasM2, 2) . " m²\n";
    }
}

echo "Poligon dengan luas > 10.000 m²: " . count($polygons) . "\n\n";

if (count($polygons) === 0) {
    echo "Tidak ada poligon yang memenuhi kriteria luas > 10.000 m².\n";
    
    // Tampilkan info cluster terbesar
    echo "\n=== Info cluster ===\n";
    foreach (array_slice($clusters, 0, 5) as $i => $cluster) {
        echo "Cluster " . ($i + 1) . ": " . count($cluster) . " endpoint\n";
    }
    
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

$pdo = db();
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
