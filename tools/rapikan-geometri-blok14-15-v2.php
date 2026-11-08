<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

/**
 * Perbaiki polygon dengan mengurutkan titik secara angular dari centroid
 * untuk menghilangkan self-intersection
 */
function fixSelfIntersection(array $coords): array {
    // Hitung centroid
    $cx = 0; $cy = 0;
    foreach ($coords as $p) {
        $cx += $p[0];
        $cy += $p[1];
    }
    $cx /= count($coords);
    $cy /= count($coords);

    // Urutkan titik berdasarkan sudut dari centroid
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

// Proses BLOK 14
echo "=== Memproses BLOK 14 ===\n";
$blok14 = db_one('SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 14\'');
preg_match_all('/([\d.]+) (-[\d.]+)/', $blok14['wkt'], $matches);
$coords14 = [];
for ($i = 0; $i < count($matches[1]); $i++) {
    $coords14[] = [(float)$matches[1][$i], (float)$matches[2][$i]];
}

echo "Titik sebelum: " . count($coords14) . "\n";

// Hapus duplikat
$coords14 = array_unique($coords14, SORT_REGULAR);
echo "Titik setelah hapus duplikat: " . count($coords14) . "\n";

// Urutkan angular untuk hilangkan self-intersection
$coords14Fixed = fixSelfIntersection($coords14);
echo "Titik setelah fix: " . count($coords14Fixed) . "\n";

$wkt14 = coordsToWkt($coords14Fixed, 0.00005);
db_exec('UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = \'BLOK 14\'', [$wkt14]);
echo "BLOK 14 diperbarui dengan " . count($coords14Fixed) . " titik\n\n";

// Proses BLOK 15
echo "=== Memproses BLOK 15 ===\n";
$blok15 = db_one('SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 15\'');
preg_match_all('/([\d.]+) (-[\d.]+)/', $blok15['wkt'], $matches);
$coords15 = [];
for ($i = 0; $i < count($matches[1]); $i++) {
    $coords15[] = [(float)$matches[1][$i], (float)$matches[2][$i]];
}

echo "Titik sebelum: " . count($coords15) . "\n";

$coords15 = array_unique($coords15, SORT_REGULAR);
echo "Titik setelah hapus duplikat: " . count($coords15) . "\n";

$coords15Fixed = fixSelfIntersection($coords15);
echo "Titik setelah fix: " . count($coords15Fixed) . "\n";

$wkt15 = coordsToWkt($coords15Fixed, 0.00005);
db_exec('UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = \'BLOK 15\'', [$wkt15]);
echo "BLOK 15 diperbarui dengan " . count($coords15Fixed) . " titik\n\n";

echo "Selesai. Geometri dirapikan dengan angular sorting.\n";
