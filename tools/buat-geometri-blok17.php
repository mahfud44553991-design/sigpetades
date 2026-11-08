<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$blok_kode = 'BLOK 17';

// Get field centers for BLOK 17
$baris = db_all(
    'SELECT pusat_lon, pusat_lat FROM bidang WHERE blok_id = (SELECT id FROM blok WHERE kode = ?) AND pusat_lon IS NOT NULL',
    [$blok_kode]
);

if (!$baris) {
    echo "Tidak ada bidang untuk $blok_kode\n";
    exit(1);
}

echo "Mengumpulkan " . count($baris) . " titik pusat bidang\n";

$titik = array_map(function ($b) {
    return [(float) $b['pusat_lon'], (float) $b['pusat_lat']];
}, $baris);

// Get neighboring block boundaries
$blok13 = db_one(
    'SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 13\''
);
$blok14 = db_one(
    'SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 14\''
);

// Extract southern boundary of BLOK 13 (BLOK 17 is south of BLOK 13)
$blok13_pts = [];
if (preg_match_all('/([\d.]+ -[\d.]+)/', $blok13['wkt'], $m)) {
    foreach ($m[1] as $p) {
        [$x, $y] = explode(' ', $p);
        $blok13_pts[] = [(float) $x, (float) $y];
    }
}
$blok13_south = min(array_column($blok13_pts, 1));
echo "BLOK 13 southern boundary: $blok13_south\n";

// Extract western boundary of BLOK 14 (BLOK 17 is west of BLOK 14)
$blok14_pts = [];
if (preg_match_all('/([\d.]+ -[\d.]+)/', $blok14['wkt'], $m)) {
    foreach ($m[1] as $p) {
        [$x, $y] = explode(' ', $p);
        $blok14_pts[] = [(float) $x, (float) $y];
    }
}
$blok14_west = min(array_column($blok14_pts, 0));
echo "BLOK 14 western boundary: $blok14_west\n";

// Convex hull of field centers
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

$hull = convexHull($titik);

// Clip hull to neighboring block boundaries
$hullClipped = array_map(function ($p) use ($blok13_south, $blok14_west) {
    // Clip latitude to be south of BLOK 13
    $lat = min($blok13_south - 0.0001, $p[1]);
    // Clip longitude to be west of BLOK 14
    $lon = min($blok14_west - 0.0001, $p[0]);
    return [$lon, $lat];
}, $hull);

// Add small buffer
$centroid = [0, 0];
foreach ($hullClipped as $p) {
    $centroid[0] += $p[0];
    $centroid[1] += $p[1];
}
$centroid[0] /= count($hullClipped);
$centroid[1] /= count($hullClipped);

$buffer = 0.0003;
$hullBuffered = array_map(function ($p) use ($centroid, $buffer, $blok13_south, $blok14_west) {
    $dx = $p[0] - $centroid[0];
    $dy = $p[1] - $centroid[1];
    $dist = sqrt($dx * $dx + $dy * $dy);
    if ($dist == 0) return $p;
    $newLon = $p[0] + ($dx / $dist) * $buffer;
    $newLat = $p[1] + ($dy / $dist) * $buffer;
    $newLon = min($blok14_west - 0.00005, $newLon);
    $newLat = min($blok13_south - 0.00005, $newLat);
    return [$newLon, $newLat];
}, $hullClipped);

$hullBuffered[] = $hullBuffered[0];

$wkt = 'MULTIPOLYGON(((' . implode(',', array_map(function ($p) {
    return $p[0] . ' ' . $p[1];
}, $hullBuffered)) . ')))';

echo "Geometri clipped dengan buffer: " . count($hullBuffered) . " titik\n";
echo "Batas timur: " . max(array_column($hullBuffered, 0)) . " (BLOK 14: $blok14_west)\n";
echo "Batas utara: " . max(array_column($hullBuffered, 1)) . " (BLOK 13: $blok13_south)\n";

db_exec(
    'UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = ?',
    [$wkt, $blok_kode]
);

echo "Geometri $blok_kode diperbarui.\n";
