<?php
declare(strict_types=1);

/**
 * Menurunkan transformasi DXF (koordinat meter proyek lokal) -> WGS84 (lon, lat)
 * dengan mencocokkan label NIB pada layer "NIB HM" terhadap label_coordinates
 * yang sudah ada di bidang_tanah.geojson.
 */

$root = dirname(__DIR__);
$dxf  = $root . '/files/master peta/MASTER.dxf';

function teksDxf(string $path, array $layerWanted): array
{
    $h = fopen($path, 'r');
    if ($h === false) {
        throw new RuntimeException('Gagal membuka ' . $path);
    }
    $ent = null; $lay = null; $val = null; $x = null; $y = null;
    $out = [];
    $hadapi = static function () use (&$ent, &$lay, &$val, &$x, &$y, &$out, $layerWanted): void {
        if ($ent === 'TEXT' && in_array($lay, $layerWanted, true) && $val !== null && $x !== null && $y !== null) {
            $k = strtoupper(trim($val));
            if ($k !== '') {
                $out[] = [$k, (float) $x, (float) $y, $lay];
            }
        }
        $ent = null; $lay = null; $val = null; $x = null; $y = null;
    };
    $prev = null;
    while (($line = fgets($h)) !== false) {
        $c = rtrim($line);
        if ($c === '') {
            continue;
        }
        if ($prev === null) {
            if (preg_match('/^\s*\d+$/', $c)) {
                $prev = (int) $c;
            }
            continue;
        }
        $v = trim($c);
        switch ($prev) {
            case 0: $hadapi(); $ent = $v; break;
            case 8: $lay = $v; break;
            case 1: $val = $v; break;
            case 10: if ($x === null) { $x = $v; } break;
            case 20: if ($y === null) { $y = $v; } break;
        }
        $prev = null;
    }
    $hadapi();
    fclose($h);

    return $out;
}

$nibList = teksDxf($dxf, ['NIB HM']);
$nib = [];
foreach ($nibList as [$k, $e, $n]) {
    $nib[$k] ??= [$e, $n];
}
echo 'Label NIB HM unik di DXF: ' . count($nib) . "\n";

$geo = json_decode((string) file_get_contents($root . '/bidang_tanah.geojson'), true);
$pairs = [];
foreach ($geo['features'] as $f) {
    $nb = strtoupper(trim((string) ($f['properties']['label_nib'] ?? '')));
    $lc = $f['properties']['label_coordinates'] ?? null;
    if ($nb === '' || !is_array($lc)) {
        continue;
    }
    if (!isset($nib[$nb]) && isset($nib['M.' . $nb])) {
        $nb = 'M.' . $nb;
    }
    if (!isset($nib[$nb])) {
        continue;
    }
    $pairs[] = [$nib[$nb][0], $nib[$nb][1], (float) $lc[0], (float) $lc[1]];
}
echo 'Pasangan cocok: ' . count($pairs) . "\n";
if (count($pairs) < 4) {
    exit("Tidak cukup pasangan untuk fitting.\n");
}

/** Basis: [1, E, N, E*N, E^2, N^2] dipotong sampai derajat yang diminta. */
function basis(float $E, float $N, int $derajat): array
{
    $b = [1.0];
    if ($derajat >= 1) {
        $b[] = $E; $b[] = $N;
    }
    if ($derajat >= 2) {
        $b[] = $E * $N; $b[] = $E * $E; $b[] = $N * $N;
    }
    return $b;
}

function leastSquares(array $rows, array $ys): array
{
    $k = count($rows[0]);
    $AtA = array_fill(0, $k, array_fill(0, $k, 0.0));
    $Atb = array_fill(0, $k, 0.0);
    foreach ($rows as $i => $row) {
        for ($r = 0; $r < $k; $r++) {
            $Atb[$r] += $row[$r] * $ys[$i];
            for ($s = 0; $s < $k; $s++) {
                $AtA[$r][$s] += $row[$r] * $row[$s];
            }
        }
    }
    for ($c = 0; $c < $k; $c++) {
        $piv = $c;
        for ($r = $c + 1; $r < $k; $r++) {
            if (abs($AtA[$r][$c]) > abs($AtA[$piv][$c])) {
                $piv = $r;
            }
        }
        if (abs($AtA[$piv][$c]) < 1e-18) {
            throw new RuntimeException("Singular pada kolom $c");
        }
        if ($piv !== $c) {
            [$AtA[$c], $AtA[$piv]] = [$AtA[$piv], $AtA[$c]];
            [$Atb[$c], $Atb[$piv]] = [$Atb[$piv], $Atb[$c]];
        }
        for ($r = $c + 1; $r < $k; $r++) {
            $f = $AtA[$r][$c] / $AtA[$c][$c];
            for ($s = $c; $s < $k; $s++) {
                $AtA[$r][$s] -= $f * $AtA[$c][$s];
            }
            $Atb[$r] -= $f * $Atb[$c];
        }
    }
    $x = array_fill(0, $k, 0.0);
    for ($r = $k - 1; $r >= 0; $r--) {
        $s = $Atb[$r];
        for ($c = $r + 1; $c < $k; $c++) {
            $s -= $AtA[$r][$c] * $x[$c];
        }
        $x[$r] = $s / $AtA[$r][$r];
    }
    return $x;
}

foreach ([1, 2] as $derajat) {
    $rows = [];
    foreach ($pairs as $p) {
        $rows[] = basis($p[0], $p[1], $derajat);
    }
    foreach (['lon' => 2, 'lat' => 3] as $nama => $idx) {
        $coef = leastSquares($rows, array_column($pairs, $idx));
        $rms = 0.0; $maks = 0.0;
        foreach ($pairs as $i => $p) {
            $pred = 0.0;
            foreach ($coef as $j => $c) {
                $pred += $c * $rows[$i][$j];
            }
            $d = $pred - $p[$idx];
            $rms += $d * $d;
            $maks = max($maks, abs($d));
        }
        printf(
            "derajat %d  %s: koef=%s  rms=%.11f  maks=%.11f\n",
            $derajat,
            $nama,
            implode(', ', array_map(static fn($v) => sprintf('%.13g', $v), $coef)),
            sqrt($rms / count($pairs)),
            $maks
        );
    }
}
