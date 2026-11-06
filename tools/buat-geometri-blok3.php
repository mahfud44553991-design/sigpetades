<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$blok_kode = 'BLOK 3';

// Get field centers for BLOK 3
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
$blok2 = db_one(
    'SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 2\''
);
$blok4 = db_one(
    'SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = \'BLOK 4\''
);

// Extract eastern boundary of BLOK 2
$blok2_pts = [];
if (preg_match_all('/([\d.]+ -[\d.]+)/', $blok2['wkt'], $m)) {
    foreach ($m[1] as $p) {
        [$x, $y] = explode(' ', $p);
        $blok2_pts[] = [(float) $x, (float) $y];
    }
}
$blok2_east = max(array_column($blok2_pts, 0));
echo "BLOK 2 eastern boundary: $blok2_east\n";

// Extract western boundary of BLOK 4
$blok4_pts = [];
if (preg_match_all('/([\d.]+ -[\d.]+)/', $blok4['wkt'], $m)) {
    foreach ($m[1] as $p) {
        [$x, $y] = explode(' ', $p);
        $blok4_pts[] = [(float) $x, (float) $y];
    }
}
$blok4_west = min(array_column($blok4_pts, 0));
echo "BLOK 4 western boundary: $blok4_west\n";

// Define village boundary (north) and village road (south)
// Based on field extent: lat -7.0810 (south) to -7.0768 (north)
// Village boundary (north): approximately -7.0755
// Village road (south): approximately -7.0820
$batas_utara = -7.0755;  // Batas desa di utara
$batas_selatan = -7.0820; // Jalan desa di selatan

echo "Batas utara (batas desa): $batas_utara\n";
echo "Batas selatan (jalan desa): $batas_selatan\n";

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

// Clip hull to boundaries
$hullClipped = array_map(function ($p) use ($blok2_east, $blok4_west, $batas_utara, $batas_selatan) {
    // Clip longitude to be between BLOK 2 and BLOK 4 boundaries
    $lon = max($blok2_east + 0.0001, min($blok4_west - 0.0001, $p[0]));
    // Clip latitude to be between village boundary (north) and village road (south)
    $lat = max($batas_selatan + 0.0001, min($batas_utara - 0.0001, $p[1]));
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
$hullBuffered = array_map(function ($p) use ($centroid, $buffer, $blok2_east, $blok4_west, $batas_utara, $batas_selatan) {
    $dx = $p[0] - $centroid[0];
    $dy = $p[1] - $centroid[1];
    $dist = sqrt($dx * $dx + $dy * $dy);
    if ($dist == 0) return $p;
    $newLon = $p[0] + ($dx / $dist) * $buffer;
    $newLat = $p[1] + ($dy / $dist) * $buffer;
    // Ensure we don't cross boundaries
    $newLon = max($blok2_east + 0.00005, min($blok4_west - 0.00005, $newLon));
    $newLat = max($batas_selatan + 0.00005, min($batas_utara - 0.00005, $newLat));
    return [$newLon, $newLat];
}, $hullClipped);

$hullBuffered[] = $hullBuffered[0];

$wkt = 'MULTIPOLYGON(((' . implode(',', array_map(function ($p) {
    return $p[0] . ' ' . $p[1];
}, $hullBuffered)) . ')))';

echo "Geometri clipped dengan buffer: " . count($hullBuffered) . " titik\n";
echo "Batas barat: " . min(array_column($hullBuffered, 0)) . " (BLOK 2: $blok2_east)\n";
echo "Batas timur: " . max(array_column($hullBuffered, 0)) . " (BLOK 4: $blok4_west)\n";
echo "Batas utara: " . max(array_column($hullBuffered, 1)) . " (batas desa: $batas_utara)\n";
echo "Batas selatan: " . min(array_column($hullBuffered, 1)) . " (jalan desa: $batas_selatan)\n";

db_exec(
    'UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = ?',
    [$wkt, $blok_kode]
);

echo "Geometri $blok_kode diperbarui.\n";
