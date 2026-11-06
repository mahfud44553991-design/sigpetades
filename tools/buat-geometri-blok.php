<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$blok_kode = $argv[1] ?? 'BLOK 3';

$baris = db_all(
    'SELECT ST_AsText(geom) AS wkt FROM bidang WHERE blok_id = (SELECT id FROM blok WHERE kode = ?) AND tipe = \'tanah\'',
    [$blok_kode]
);

if (!$baris) {
    echo "Tidak ada bidang untuk $blok_kode\n";
    exit(1);
}

$kumpulan = [];
foreach ($baris as $b) {
    if (preg_match('/POLYGON\s*\(\(([^)]+)\)\)/', $b['wkt'], $m)) {
        $titik = explode(',', $m[1]);
        foreach ($titik as $t) {
            $t = trim($t);
            if ($t && !in_array($t, $kumpulan)) {
                $kumpulan[] = $t;
            }
        }
    }
}

echo "Mengumpulkan " . count($kumpulan) . " titik unik dari " . count($baris) . " bidang\n";

function convexHull(array $points): array {
    $pts = array_map(function ($p) {
        [$x, $y] = explode(' ', $p);
        return [(float) $x, (float) $y];
    }, $points);

    $pts = array_unique($pts, SORT_REGULAR);
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

$hull = convexHull($kumpulan);
$hull[] = $hull[0];

$wkt = 'MULTIPOLYGON(((' . implode(',', array_map(function ($p) {
    return $p[0] . ' ' . $p[1];
}, $hull)) . ')))';

echo "Convex hull: " . count($hull) . " titik\n";
echo "WKT: $wkt\n";

db_exec(
    'UPDATE blok SET geom = ST_GeomFromText(?) WHERE kode = ?',
    [$wkt, $blok_kode]
);

echo "Geometri $blok_kode diperbarui.\n";
