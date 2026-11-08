<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

// Get BLOK 14 and BLOK 15 geometries
$blok14 = db_one('SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 14\'');
$blok15 = db_one('SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 15\'');

echo "=== BLOK 14 ===\n";
echo "WKT length: " . strlen($blok14['wkt']) . "\n";

// Extract coordinates
preg_match_all('/([\d.]+) (-[\d.]+)/', $blok14['wkt'], $matches14);
$coords14 = [];
for ($i = 0; $i < count($matches14[1]); $i++) {
    $coords14[] = [(float)$matches14[1][$i], (float)$matches14[2][$i]];
}
echo "Jumlah titik: " . count($coords14) . "\n";

// Check for self-intersections by looking at coordinate patterns
echo "\nAnalisis pola koordinat BLOK 14:\n";
$lon_values = array_column($coords14, 0);
$lat_values = array_column($coords14, 1);

echo "Longitude range: " . min($lon_values) . " - " . max($lon_values) . "\n";
echo "Latitude range: " . min($lat_values) . " - " . max($lat_values) . "\n";

// Look for backtracking (where the polygon goes back on itself)
echo "\nCek backtracking (perubahan arah drastis):\n";
for ($i = 2; $i < count($coords14); $i++) {
    $dx1 = $coords14[$i-1][0] - $coords14[$i-2][0];
    $dy1 = $coords14[$i-1][1] - $coords14[$i-2][1];
    $dx2 = $coords14[$i][0] - $coords14[$i-1][0];
    $dy2 = $coords14[$i][1] - $coords14[$i-1][1];
    
    // Check if direction changes significantly
    $dot = $dx1 * $dx2 + $dy1 * $dy2;
    $mag1 = sqrt($dx1*$dx1 + $dy1*$dy1);
    $mag2 = sqrt($dx2*$dx2 + $dy2*$dy2);
    
    if ($mag1 > 0 && $mag2 > 0) {
        $cos_angle = $dot / ($mag1 * $mag2);
        if ($cos_angle < -0.5) { // More than 120 degree turn
            echo "Titik $i: perubahan arah drastis di (" . $coords14[$i][0] . ", " . $coords14[$i][1] . ")\n";
        }
    }
}

echo "\n=== BLOK 15 ===\n";
echo "WKT length: " . strlen($blok15['wkt']) . "\n";

preg_match_all('/([\d.]+) (-[\d.]+)/', $blok15['wkt'], $matches15);
$coords15 = [];
for ($i = 0; $i < count($matches15[1]); $i++) {
    $coords15[] = [(float)$matches15[1][$i], (float)$matches15[2][$i]];
}
echo "Jumlah titik: " . count($coords15) . "\n";

$lon_values15 = array_column($coords15, 0);
$lat_values15 = array_column($coords15, 1);

echo "Longitude range: " . min($lon_values15) . " - " . max($lon_values15) . "\n";
echo "Latitude range: " . min($lat_values15) . " - " . max($lat_values15) . "\n";

echo "\nCek backtracking BLOK 15:\n";
for ($i = 2; $i < count($coords15); $i++) {
    $dx1 = $coords15[$i-1][0] - $coords15[$i-2][0];
    $dy1 = $coords15[$i-1][1] - $coords15[$i-2][1];
    $dx2 = $coords15[$i][0] - $coords15[$i-1][0];
    $dy2 = $coords15[$i][1] - $coords15[$i-1][1];
    
    $dot = $dx1 * $dx2 + $dy1 * $dy2;
    $mag1 = sqrt($dx1*$dx1 + $dy1*$dy1);
    $mag2 = sqrt($dx2*$dx2 + $dy2*$dy2);
    
    if ($mag1 > 0 && $mag2 > 0) {
        $cos_angle = $dot / ($mag1 * $mag2);
        if ($cos_angle < -0.5) {
            echo "Titik $i: perubahan arah drastis di (" . $coords15[$i][0] . ", " . $coords15[$i][1] . ")\n";
        }
    }
}
