<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

/**
 * Rapikan BLOK 10 dengan mengikuti:
 * 1. Batas desa (village boundary)
 * 2. Blok sebelah (neighboring blocks)
 * 3. Sungai (river)
 *
 * Pendekatan: Gunakan convex hull dari bidang-bidang BLOK 10,
 * lalu sesuaikan dengan batas-batas referensi.
 */

// Ambil semua bidang BLOK 10
$baris = db_all(
    'SELECT pusat_lon, pusat_lat FROM bidang WHERE blok_id = (SELECT id FROM blok WHERE kode = \'BLOK 10\') AND pusat_lon IS NOT NULL'
);

echo "Jumlah bidang BLOK 10: " . count($baris) . "\n";

$titik = array_map(function ($b) {
    return [(float) $b['pusat_lon'], (float) $b['pusat_lat']];
}, $baris);

// Convex hull dari pusat bidang
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
echo "Titik convex hull: " . count($hull) . "\n";

// Ambil batas blok tetangga untuk clipping
// BLOK 9 (timur), BLOK 12 (barat daya), BLOK 13 (selatan), BLOK 14 (tenggara), BLOK 15 (timur selatan)
$blokTetangga = [];
foreach (['BLOK 9', 'BLOK 12', 'BLOK 13', 'BLOK 14', 'BLOK 15'] as $kode) {
    $row = db_one('SELECT ST_AsText(geom) AS wkt FROM blok WHERE kode = ?', [$kode]);
    if ($row && $row['wkt']) {
        $pts = [];
        if (preg_match_all('/([\d.]+) (-[\d.]+)/', $row['wkt'], $m)) {
            for ($i = 0; $i < count($m[1]); $i++) {
                $pts[] = [(float)$m[1][$i], (float)$m[2][$i]];
            }
        }
        $blokTetangga[$kode] = $pts;
        echo "$kode: " . count($pts) . " titik\n";
    }
}

// Clip convex hull dengan batas tetangga
// Untuk setiap titik hull, pastikan tidak melampaui batas tetangga
$hullClipped = [];
foreach ($hull as $p) {
    $lon = $p[0];
    $lat = $p[1];

    // Clip dengan BLOK 9 (timur) - gunakan batas barat BLOK 9
    if (isset($blokTetangga['BLOK 9'])) {
        $blok9_min_lon = min(array_column($blokTetangga['BLOK 9'], 0));
        $lon = min($lon, $blok9_min_lon - 0.00005);
    }

    // Clip dengan BLOK 12 (barat daya) - gunakan batas timur BLOK 12
    if (isset($blokTetangga['BLOK 12'])) {
        $blok12_max_lon = max(array_column($blokTetangga['BLOK 12'], 0));
        // Hanya clip jika titik ada di selatan BLOK 12
        $blok12_min_lat = min(array_column($blokTetangga['BLOK 12'], 1));
        if ($lat < $blok12_min_lat) {
            $lon = max($lon, $blok12_max_lon + 0.00005);
        }
    }

    // Clip dengan BLOK 13 (selatan) - gunakan batas utara BLOK 13
    if (isset($blokTetangga['BLOK 13'])) {
        $blok13_max_lat = max(array_column($blokTetangga['BLOK 13'], 1));
        $lat = max($lat, $blok13_max_lat + 0.00005);
    }

    // Clip dengan BLOK 14 (tenggara)
    if (isset($blokTetangga['BLOK 14'])) {
        $blok14_min_lat = min(array_column($blokTetangga['BLOK 14'], 1));
        $blok14_max_lon = max(array_column($blokTetangga['BLOK 14'], 0));
        if ($lat < $blok14_min_lat && $lon > $blok14_max_lon) {
            // Titik di tenggara BLOK 14, clip
            $lat = max($lat, $blok14_min_lat + 0.00005);
        }
    }

    // Clip dengan BLOK 15 (timur selatan)
    if (isset($blokTetangga['BLOK 15'])) {
        $blok15_min_lat = min(array_column($blokTetangga['BLOK 15'], 1));
        if ($lat < $blok15_min_lat) {
            $lat = max($lat, $blok15_min_lat + 0.00005);
        }
    }

    $hullClipped[] = [$lon, $lat];
}

// Tambahkan buffer kecil
$centroid = [0, 0];
foreach ($hullClipped as $p) {
    $centroid[0] += $p[0];
    $centroid[1] += $p[1];
}
$centroid[0] /= count($hullClipped);
$centroid[1] /= count($hullClipped);

$buffer = 0.00008;
$hullBuffered = array_map(function ($p) use ($centroid, $buffer) {
    $dx = $p[0] - $centroid[0];
    $dy = $p[1] - $centroid[1];
    $dist = sqrt($dx * $dx + $dy * $dy);
    if ($dist == 0) return $p;
    return [
        $p[0] + ($dx / $dist) * $buffer,
        $p[1] + ($dy / $dist) * $buffer
    ];
}, $hullClipped);

$hullBuffered[] = $hullBuffered[0];

$wkt = 'MULTIPOLYGON(((' . implode(',', array_map(function ($p) {
    return $p[0] . ' ' . $p[1];
}, $hullBuffered)) . ')))';

echo "\nGeometri baru: " . count($hullBuffered) . " titik\n";
echo "Longitude range: " . min(array_column($hullBuffered, 0)) . " - " . max(array_column($hullBuffered, 0)) . "\n";
echo "Latitude range: " . min(array_column($hullBuffered, 1)) . " - " . max(array_column($hullBuffered, 1)) . "\n";

db_exec('UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = \'BLOK 10\'', [$wkt]);
echo "\nBLOK 10 diperbarui.\n";
