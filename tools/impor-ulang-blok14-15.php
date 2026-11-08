<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

/**
 * Urutkan titik polygon secara angular dari centroid untuk hilangkan self-intersection
 */
function fixSelfIntersection(array $coords): array {
    $cx = 0; $cy = 0;
    foreach ($coords as $p) {
        $cx += $p[0];
        $cy += $p[1];
    }
    $cx /= count($coords);
    $cy /= count($coords);

    usort($coords, function ($a, $b) use ($cx, $cy) {
        $angleA = atan2($a[1] - $cy, $a[0] - $cx);
        $angleB = atan2($b[1] - $cy, $b[0] - $cx);
        return $angleA <=> $angleB;
    });

    return $coords;
}

function coordsToWkt(array $coords, float $buffer = 0.0): string {
    if ($buffer > 0) {
        $centroid = [0, 0];
        foreach ($coords as $p) {
            $centroid[0] += $p[0];
            $centroid[1] += $p[1];
        }
        $centroid[0] /= count($coords);
        $centroid[1] /= count($coords);

        $coords = array_map(function ($p) use ($centroid, $buffer) {
            $dx = $p[0] - $centroid[0];
            $dy = $p[1] - $centroid[1];
            $dist = sqrt($dx * $dx + $dy * $dy);
            if ($dist == 0) return $p;
            return [
                $p[0] + ($dx / $dist) * $buffer,
                $p[1] + ($dy / $dist) * $buffer
            ];
        }, $coords);
    }

    $coords[] = $coords[0];
    return 'MULTIPOLYGON(((' . implode(',', array_map(function ($p) {
        return $p[0] . ' ' . $p[1];
    }, $coords)) . ')))';
}

// Baca GeoJSON asli
$geojson = file_get_contents('C:/Users/User/Downloads/blok-semua-20261107.geojson');
$data = json_decode($geojson, true);

echo "Total features: " . count($data['features']) . "\n\n";

foreach ($data['features'] as $feature) {
    $kode = $feature['properties']['kode'] ?? '';
    if (!in_array($kode, ['BLOK 14', 'BLOK 15'])) continue;

    echo "=== Memproses $kode ===\n";
    $geom = $feature['geometry'];

    // Extract semua koordinat dari MultiPolygon
    $allCoords = [];
    if ($geom['type'] === 'MultiPolygon') {
        foreach ($geom['coordinates'] as $polygon) {
            foreach ($polygon as $ring) {
                foreach ($ring as $point) {
                    $allCoords[] = [(float)$point[0], (float)$point[1]];
                }
            }
        }
    }

    echo "Titik asli: " . count($allCoords) . "\n";

    // Hapus duplikat
    $allCoords = array_unique($allCoords, SORT_REGULAR);
    echo "Setelah hapus duplikat: " . count($allCoords) . "\n";

    // Urutkan angular untuk hilangkan self-intersection
    $fixedCoords = fixSelfIntersection($allCoords);
    echo "Setelah angular sort: " . count($fixedCoords) . "\n";

    // Konversi ke WKT dengan buffer kecil
    $wkt = coordsToWkt($fixedCoords, 0.00005);

    // Update database
    db_exec('UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = ?', [$wkt, $kode]);
    echo "$kode diperbarui.\n\n";
}

echo "Selesai. Geometri BLOK 14 dan BLOK 15 dirapikan dari GeoJSON asli.\n";
