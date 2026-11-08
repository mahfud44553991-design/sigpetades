<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

function convexHull(array $points): array {
    $pts = array_unique($points, SORT_REGULAR);
    $n = count($pts);
    if ($n < 3) return $pts;

    usort($pts, function ($a, $b) {
        return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
    });

    $cross = function ($o, $a, $b) {
        return ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
    };

    $lower = [];
    foreach ($pts as $p) {
        while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) {
            array_pop($lower);
        }
        $lower[] = $p;
    }

    $upper = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $p = $pts[$i];
        while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) {
            array_pop($upper);
        }
        $upper[] = $p;
    }

    array_pop($lower);
    array_pop($upper);
    return array_merge($lower, $upper);
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

    $coords[] = $coords[0]; // Close the polygon
    return 'MULTIPOLYGON(((' . implode(',', array_map(function ($p) {
        return $p[0] . ' ' . $p[1];
    }, $coords)) . ')))';
}

// Process BLOK 14
echo "=== Memproses BLOK 14 ===\n";
$blok14 = db_one('SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 14\'');
preg_match_all('/([\d.]+) (-[\d.]+)/', $blok14['wkt'], $matches);
$coords14 = [];
for ($i = 0; $i < count($matches[1]); $i++) {
    $coords14[] = [(float)$matches[1][$i], (float)$matches[2][$i]];
}

echo "Titik asli: " . count($coords14) . "\n";
$hull14 = convexHull($coords14);
echo "Titik convex hull: " . count($hull14) . "\n";

$wkt14 = coordsToWkt($hull14, 0.0001);
db_exec('UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = \'BLOK 14\'', [$wkt14]);
echo "BLOK 14 diperbarui.\n\n";

// Process BLOK 15
echo "=== Memproses BLOK 15 ===\n";
$blok15 = db_one('SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 15\'');
preg_match_all('/([\d.]+) (-[\d.]+)/', $blok15['wkt'], $matches);
$coords15 = [];
for ($i = 0; $i < count($matches[1]); $i++) {
    $coords15[] = [(float)$matches[1][$i], (float)$matches[2][$i]];
}

echo "Titik asli: " . count($coords15) . "\n";
$hull15 = convexHull($coords15);
echo "Titik convex hull: " . count($hull15) . "\n";

$wkt15 = coordsToWkt($hull15, 0.0001);
db_exec('UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = \'BLOK 15\'', [$wkt15]);
echo "BLOK 15 diperbarui.\n\n";

echo "Selesai. Geometri BLOK 14 dan BLOK 15 sudah dirapikan.\n";
