<?php
declare(strict_types=1);

/**
 * Polygonization garis terbuka menjadi bidang besar (> 10.000 m²)
 * 
 * Algoritma:
 * 1. Ambil garis panjang (> 100m) yang kemungkinan batas bidang besar
 * 2. Cari garis lain yang terhubung (endpoint dalam radius threshold)
 * 3. Bentuk closed ring dari garis-garis yang terhubung
 * 4. Konversi ke poligon dan hitung luas
 * 5. Filter luas > 10.000 m²
 * 
 * Usage:
 *   php tools/polygonize-garis-terbuka.php --lihat
 *   php tools/polygonize-garis-terbuka.php --terapkan
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

// Kumpulkan garis terbuka panjang (> 100m)
$garisPanjang = [];
foreach ($data['features'] as $feature) {
    $geom = $feature['geometry'] ?? null;
    if (!$geom || ($geom['type'] ?? '') !== 'LineString') continue;
    
    $coords = $geom['coordinates'] ?? [];
    if (count($coords) < 2) continue;
    
    // Hitung panjang garis (dalam meter, aproksimasi)
    $length = 0;
    for ($i = 1; $i < count($coords); $i++) {
        $dx = $coords[$i][0] - $coords[$i-1][0];
        $dy = $coords[$i][1] - $coords[$i-1][1];
        $length += sqrt($dx*$dx + $dy*$dy);
    }
    
    // Filter garis > 100m
    if ($length > 100) {
        $garisPanjang[] = [
            'coords' => $coords,
            'length' => $length,
            'start' => $coords[0],
            'end' => $coords[count($coords) - 1],
        ];
    }
}

echo "Garis panjang (>100m): " . count($garisPanjang) . "\n\n";

if (count($garisPanjang) === 0) {
    echo "Tidak ada garis panjang yang bisa diproses.\n";
    exit(0);
}

// Parameter koneksi
$thresholdDistance = 500; // meter - radius lebih besar untuk menangkap koneksi yang lebih longgar

// Bangun graph koneksi
echo "Membangun graph koneksi (threshold: {$thresholdDistance}m)...\n";

$nodes = [];
$edges = [];

foreach ($garisPanjang as $idx => $garis) {
    $startKey = round($garis['start'][0], 6) . ',' . round($garis['start'][1], 6);
    $endKey = round($garis['end'][0], 6) . ',' . round($garis['end'][1], 6);
    
    if (!isset($nodes[$startKey])) {
        $nodes[$startKey] = ['coord' => $garis['start'], 'edges' => []];
    }
    if (!isset($nodes[$endKey])) {
        $nodes[$endKey] = ['coord' => $garis['end'], 'edges' => []];
    }
    
    $edgeId = count($edges);
    $edges[] = [
        'from' => $startKey,
        'to' => $endKey,
        'coords' => $garis['coords'],
        'length' => $garis['length'],
    ];
    
    $nodes[$startKey]['edges'][] = $edgeId;
    $nodes[$endKey]['edges'][] = $edgeId;
}

echo "Nodes: " . count($nodes) . ", Edges: " . count($edges) . "\n\n";

// Cari closed rings menggunakan DFS
echo "Mencari closed rings...\n";

function findCycles($nodes, $edges) {
    $cycles = [];
    $visited = [];
    
    foreach ($nodes as $startNode => $nodeData) {
        if (count($nodeData['edges']) < 2) continue;
        
        // DFS dari node ini
        $stack = [[$startNode, [$startNode], []]];
        
        while (!empty($stack)) {
            [$current, $path, $usedEdges] = array_pop($stack);
            
            foreach ($nodes[$current]['edges'] as $edgeId) {
                if (in_array($edgeId, $usedEdges)) continue;
                
                $edge = $edges[$edgeId];
                $next = ($edge['from'] === $current) ? $edge['to'] : $edge['from'];
                
                if ($next === $startNode && count($path) >= 3) {
                    // Found a cycle!
                    $cycleEdges = array_merge($usedEdges, [$edgeId]);
                    $cycles[] = [
                        'path' => $path,
                        'edges' => $cycleEdges,
                    ];
                } elseif (!in_array($next, $path) && count($path) < 20) {
                    // Continue DFS
                    $newPath = $path;
                    $newPath[] = $next;
                    $newEdges = $usedEdges;
                    $newEdges[] = $edgeId;
                    $stack[] = [$next, $newPath, $newEdges];
                }
            }
        }
    }
    
    return $cycles;
}

$cycles = findCycles($nodes, $edges);
echo "Closed rings ditemukan: " . count($cycles) . "\n\n";

if (count($cycles) === 0) {
    echo "Tidak ada closed ring yang terbentuk.\n";
    exit(0);
}

// Konversi cycles ke polygons dan hitung luas
$polygons = [];
foreach ($cycles as $cycleIdx => $cycle) {
    // Kumpulkan semua koordinat dari edges
    $allCoords = [];
    foreach ($cycle['edges'] as $edgeId) {
        $edge = $edges[$edgeId];
        foreach ($edge['coords'] as $coord) {
            $wgs = dxfKeWgs84($coord[0], $coord[1]);
            $allCoords[] = $wgs;
        }
    }
    
    if (count($allCoords) < 4) continue;
    
    // Pastikan ring tertutup
    if ($allCoords[0] !== end($allCoords)) {
        $allCoords[] = $allCoords[0];
    }
    
    // Buat WKT
    $wktPoints = [];
    foreach ($allCoords as $p) {
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
            'cycle_idx' => $cycleIdx,
        ];
    }
}

echo "Poligon dengan luas > 10.000 m²: " . count($polygons) . "\n\n";

if (count($polygons) === 0) {
    echo "Tidak ada poligon yang memenuhi kriteria luas > 10.000 m².\n";
    exit(0);
}

// Tampilkan preview
if ($lihat) {
    echo "=== Preview poligon besar ===\n";
    foreach (array_slice($polygons, 0, 10) as $i => $p) {
        echo ($i + 1) . ". Luas: " . number_format($p['luas'], 2) . " m²\n";
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
        VALUES (?, 'lain', 'perlu_verifikasi', '020100-polygon', ?, ST_GeomFromText(?, 4326), NOW(), NOW())
    ");
    
    $berhasil = 0;
    foreach ($polygons as $i => $p) {
        $kode = 'Polygon-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);
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
