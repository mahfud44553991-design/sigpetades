<?php
declare(strict_types=1);

$geojson = file_get_contents('C:/Users/User/Downloads/blok-semua-20261107.geojson');
$data = json_decode($geojson, true);

echo "Total features: " . count($data['features']) . "\n\n";

foreach ($data['features'] as $feature) {
    $kode = $feature['properties']['kode'] ?? '';
    if (in_array($kode, ['BLOK 14', 'BLOK 15'])) {
        echo "=== $kode ===\n";
        $geom = $feature['geometry'];
        echo "Tipe: " . $geom['type'] . "\n";
        
        if ($geom['type'] === 'MultiPolygon') {
            $totalPoints = 0;
            foreach ($geom['coordinates'] as $polygonIdx => $polygon) {
                foreach ($polygon as $ringIdx => $ring) {
                    $totalPoints += count($ring);
                    echo "Polygon $polygonIdx, Ring $ringIdx: " . count($ring) . " titik\n";
                }
            }
            echo "Total titik: $totalPoints\n";
        }
        echo "\n";
    }
}
