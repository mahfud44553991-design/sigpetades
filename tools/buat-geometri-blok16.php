<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$blok_kode = 'BLOK 16';

// Get field centers for BLOK 16
$baris = db_all(
    'SELECT pusat_lon, pusat_lat FROM bidang WHERE blok_id = (SELECT id FROM blok WHERE kode = ?) AND tipe = \'tanah\' AND pusat_lon IS NOT NULL',
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
$blok15 = db_one(
    'SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 15\''
);
$blok7 = db_one(
    'SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 7\''
);

// Extract eastern boundary of BLOK 15
$blok15_pts = [];
if (preg_match_all('/([\d.]+ -[\d.]+)/', $blok15['wkt'], $m)) {
    foreach ($m[1] as $p) {
        [$x, $y] = explode(' ', $p);
        $blok15_pts[] = [(float) $x, (float) $y];
    }
}
$blok15_east = max(array_column($blok15_pts, 0));
echo "BLOK 15 eastern boundary: $blok15_east\n";

// Extract southern boundary of BLOK 7 (BLOK 16 is south of BLOK 7)
$blok7_pts = [];
if (preg_match_all('/([\d.]+ -[\d.]+)/', $blok7['wkt'], $m)) {
    foreach ($m[1] as $p) {
        [$x, $y] = explode(' ', $p);
        $blok7_pts[] = [(float) $x, (float) $y];
    }
}
$blok7_south = min(array_column($blok7_pts, 1));
echo "BLOK 7 southern boundary: $blok7_south\n";

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
$hullClipped = array_map(function ($p) use ($blok15_east, $blok7_south) {
    // Clip longitude to be east of BLOK 15
    $lon = max($blok15_east + 0.0001, $p[0]);
    // Clip latitude to be south of BLOK 7
    $lat = min($blok7_south - 0.0001, $p[1]);
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
$hullBuffered = array_map(function ($p) use ($centroid, $buffer, $blok15_east, $blok7_south) {
    $dx = $p[0] - $centroid[0];
    $dy = $p[1] - $centroid[1];
    $dist = sqrt($dx * $dx + $dy * $dy);
    if ($dist == 0) return $p;
    $newLon = $p[0] + ($dx / $dist) * $buffer;
    $newLat = $p[1] + ($dy / $dist) * $buffer;
    $newLon = max($blok15_east + 0.00005, $newLon);
    $newLat = min($blok7_south - 0.00005, $newLat);
    return [$newLon, $newLat];
}, $hullClipped);

$hullBuffered[] = $hullBuffered[0];

$wkt = 'MULTIPOLYGON(((' . implode(',', array_map(function ($p) {
    return $p[0] . ' ' . $p[1];
}, $hullBuffered)) . ')))';

echo "Geometri clipped dengan buffer: " . count($hullBuffered) . " titik\n";
echo "Batas barat: " . min(array_column($hullBuffered, 0)) . " (BLOK 15: $blok15_east)\n";
echo "Batas utara: " . max(array_column($hullBuffered, 1)) . " (BLOK 7: $blok7_south)\n";

db_exec(
    'UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = ?',
    [$wkt, $blok_kode]
);

echo "Geometri $blok_kode diperbarui.\n";
