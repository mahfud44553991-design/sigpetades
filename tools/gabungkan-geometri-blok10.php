<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$blok_kode = 'BLOK 10';

// Get current geometry
$blok = db_one(
    'SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = ?',
    [$blok_kode]
);

if (!$blok || !$blok['wkt']) {
    echo "Geometri $blok_kode tidak ditemukan\n";
    exit(1);
}

echo "Geometri asli: " . $blok['wkt'] . "\n\n";

// Parse MultiPolygon coordinates
$wkt = $blok['wkt'];
preg_match_all('/\(\(([\d\.\s,\-]+)\)\)/', $wkt, $matches);

$all_points = [];
foreach ($matches[1] as $ring_str) {
    $points = explode(',', trim($ring_str));
    foreach ($points as $p) {
        $coords = explode(' ', trim($p));
        if (count($coords) === 2) {
            $all_points[] = [(float) $coords[0], (float) $coords[1]];
        }
    }
}

echo "Total titik dari semua bagian: " . count($all_points) . "\n";

// Convex hull of all points
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

$hull = convexHull($all_points);

// Add small buffer
$centroid = [0, 0];
foreach ($hull as $p) {
    $centroid[0] += $p[0];
    $centroid[1] += $p[1];
}
$centroid[0] /= count($hull);
$centroid[1] /= count($hull);

$buffer = 0.0001;
$hullBuffered = array_map(function ($p) use ($centroid, $buffer) {
    $dx = $p[0] - $centroid[0];
    $dy = $p[1] - $centroid[1];
    $dist = sqrt($dx * $dx + $dy * $dy);
    if ($dist == 0) return $p;
    $newLon = $p[0] + ($dx / $dist) * $buffer;
    $newLat = $p[1] + ($dy / $dist) * $buffer;
    return [$newLon, $newLat];
}, $hull);

$hullBuffered[] = $hullBuffered[0];

$wkt_baru = 'MULTIPOLYGON(((' . implode(',', array_map(function ($p) {
    return $p[0] . ' ' . $p[1];
}, $hullBuffered)) . ')))';

echo "Geometri baru (1 bagian): " . count($hullBuffered) . " titik\n";
echo "WKT: " . $wkt_baru . "\n\n";

db_exec(
    'UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = ?',
    [$wkt_baru, $blok_kode]
);

echo "✓ $blok_kode diperbarui menjadi 1 geometri\n";
