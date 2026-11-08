<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

// Get BLOK 14 geometry
$blok14 = db_one('SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 14\'');

// Extract coordinates
preg_match_all('/([\d.]+) (-[\d.]+)/', $blok14['wkt'], $matches);
$coords = [];
for ($i = 0; $i < count($matches[1]); $i++) {
    $coords[] = [(float)$matches[1][$i], (float)$matches[2][$i]];
}

echo "BLOK 14 - " . count($coords) . " titik\n\n";

// Show coordinates around point 87 (where the drastic change happens)
echo "Koordinat sekitar titik 85-90:\n";
for ($i = 84; $i <= 91 && $i < count($coords); $i++) {
    echo "Titik $i: (" . $coords[$i][0] . ", " . $coords[$i][1] . ")\n";
}

echo "\n\nAnalisis arah pergerakan:\n";
for ($i = 1; $i < count($coords); $i++) {
    $dx = $coords[$i][0] - $coords[$i-1][0];
    $dy = $coords[$i][1] - $coords[$i-1][1];
    $direction = '';
    if (abs($dx) > abs($dy)) {
        $direction = $dx > 0 ? 'Timur' : 'Barat';
    } else {
        $direction = $dy > 0 ? 'Utara' : 'Selatan';
    }
    if ($i >= 84 && $i <= 91) {
        echo "Titik " . ($i-1) . " -> $i: $direction (dx=$dx, dy=$dy)\n";
    }
}

// Check if polygon crosses itself by looking at the overall path
echo "\n\nPath analisis (setiap 20 titik):\n";
for ($i = 0; $i < count($coords); $i += 20) {
    echo "Titik $i: (" . $coords[$i][0] . ", " . $coords[$i][1] . ")\n";
}
