<?php
declare(strict_types=1);

/**
 * Titik tengah bidang HM: satu titik per fitur lapis BIDANG HM.
 *
 * Alat ini tidak mengubah bidang-hm.geojson. Ia membaca lapis itu dan menulis
 * berkas baru titik-tengah-bidang-hm.geojson berisi satu titik untuk tiap
 * fitur:
 *
 *  - poligon tertutup (1.494 bidang): titik berat bidang (centroid luas),
 *    bukan rata-rata titik sudut, supaya bidang berbentuk L atau cekung tetap
 *    dapat titik di tengah badannya. Bila titik beratnya jatuh di luar ring
 *    (bentuk cekung), dicari titik dalam lewat garis pindai mendatar pada
 *    beberapa ketinggian kotak batas: pertengahan rentang dalam yang terlebar;
 *    jalan terakhir kotak batas;
 *  - garis terbuka (2 fitur): titik tengah menurut panjang garis.
 *
 * Koordinat lapis berada dalam meter DXF lokal, jadi luas dan titik berat
 * dihitung datar (shoelace), tanpa proyeksi.
 *
 * Pemakaian:
 *   php tools/titik-tengah-bidang-hm.php
 *   php tools/titik-tengah-bidang-hm.php --keluar=pratinjau.geojson
 *   php tools/titik-tengah-bidang-hm.php --terapkan
 */
require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const DIR_LAPIS = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/';
const BERKAS_MASUK = 'bidang-hm.geojson';
const BERKAS_KELUAR = 'titik-tengah-bidang-hm.geojson';
const KUNCI_META = 'TITIK TENGAH BIDANG HM';
const SUMBER = 'BIDANG HM';

$opts = getopt('', ['berkas::', 'keluar::', 'terapkan']);
$berkasMasuk = (string) ($opts['berkas'] ?? DIR_LAPIS . BERKAS_MASUK);
$keluar = (string) ($opts['keluar'] ?? '');
$terapkan = isset($opts['terapkan']);

$geojson = json_decode((string) @file_get_contents($berkasMasuk), true);
if (!is_array($geojson) || !is_array($geojson['features'] ?? null)) {
    fwrite(STDERR, "berkas tidak terbaca: $berkasMasuk\n");
    exit(1);
}

/** Koordinat satu cincin sebagai pasangan float. */
function cincin(array $r): array
{
    $hasil = [];
    foreach ($r as $p) {
        if (is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
            $hasil[] = [(float) $p[0], (float) $p[1]];
        }
    }
    return $hasil;
}

/** Luas bertanda ring (shoelace); positif bila berlawanan arah jarum jam. */
function luas_bertanda(array $r): float
{
    $n = count($r);
    $s = 0.0;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $s += $r[$j][0] * $r[$i][1] - $r[$i][0] * $r[$j][1];
    }
    return $s / 2.0;
}

/** Titik berat ring; ring tanpa luas memakai rata-rata titik sudut. */
function titik_berat(array $r): array
{
    $n = count($r);
    $a = 0.0;
    $px = 0.0;
    $py = 0.0;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $silang = $r[$j][0] * $r[$i][1] - $r[$i][0] * $r[$j][1];
        $a += $silang;
        $px += ($r[$j][0] + $r[$i][0]) * $silang;
        $py += ($r[$j][1] + $r[$i][1]) * $silang;
    }
    $a /= 2.0;
    if (abs($a) < 1e-6) {
        $px = array_sum(array_column($r, 0)) / $n;
        $py = array_sum(array_column($r, 1)) / $n;
        return [$px, $py];
    }
    return [$px / (6.0 * $a), $py / (6.0 * $a)];
}

function titik_di_ring(array $r, float $x, float $y): bool
{
    $dalam = false;
    for ($i = 0, $j = count($r) - 1; $i < count($r); $j = $i++) {
        $xi = $r[$i][0];
        $yi = $r[$i][1];
        $xj = $r[$j][0];
        $yj = $r[$j][1];
        if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
            $dalam = !$dalam;
        }
    }
    return $dalam;
}

/** Paritas seluruh ring: cincin dalam (lubang) ikut mengurangi. */
function titik_di_poligon(array $rings, float $x, float $y): bool
{
    $dalam = false;
    foreach ($rings as $r) {
        if (titik_di_ring($r, $x, $y)) {
            $dalam = !$dalam;
        }
    }
    return $dalam;
}

function kotak_batas(array $rings): array
{
    $x0 = INF;
    $y0 = INF;
    $x1 = -INF;
    $y1 = -INF;
    foreach ($rings as $r) {
        foreach ($r as $p) {
            $x0 = min($x0, $p[0]);
            $y0 = min($y0, $p[1]);
            $x1 = max($x1, $p[0]);
            $y1 = max($y1, $p[1]);
        }
    }
    return [$x0, $y0, $x1, $y1];
}

/** Pertengahan rentang dalam terlebar pada garis mendatar y. */
function tengah_pindaian(array $rings, float $y): ?array
{
    $xs = [];
    foreach ($rings as $r) {
        $n = count($r);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $r[$i][0];
            $yi = $r[$i][1];
            $xj = $r[$j][0];
            $yj = $r[$j][1];
            if (($yi > $y) !== ($yj > $y)) {
                $xs[] = $xi + ($y - $yi) * ($xj - $xi) / ($yj - $yi);
            }
        }
    }
    sort($xs);
    $terbaik = null;
    $lebar = 0.0;
    for ($i = 0; $i + 1 < count($xs); $i += 2) {
        $w = $xs[$i + 1] - $xs[$i];
        if ($w > $lebar) {
            $lebar = $w;
            $terbaik = [($xs[$i] + $xs[$i + 1]) / 2.0, $y];
        }
    }
    return $terbaik;
}

/**
 * Titik di dalam badan poligon, dicoba berurutan: titik berat, pertengahan
 * kotak batas, lalu garis pindai pada beberapa ketinggian.
 * @return array{0: array{0: float, 1: float}, 1: string}
 */
function titik_dalam(array $rings): array
{
    [$x0, $y0, $x1, $y1] = kotak_batas($rings);
    $calon = titik_berat($rings[0]);
    if (titik_di_poligon($rings, $calon[0], $calon[1])) {
        return [$calon, 'titik-berat'];
    }
    $tengah = [($x0 + $x1) / 2.0, ($y0 + $y1) / 2.0];
    if (titik_di_poligon($rings, $tengah[0], $tengah[1])) {
        return [$tengah, 'kotak'];
    }
    $tinggi = $y1 - $y0;
    foreach ([.5, .35, .65, .2, .8, .1, .9, .05, .95] as $bagian) {
        $y = $y0 + $tinggi * $bagian;
        $c = tengah_pindaian($rings, $y);
        if ($c !== null && titik_di_poligon($rings, $c[0], $c[1])) {
            return [$c, 'garis-scan'];
        }
    }
    return [$tengah, 'kotak'];
}

/** Titik tengah garis menurut panjang kumulatif. */
function tengah_garis(array $c): array
{
    $total = 0.0;
    for ($i = 0; $i + 1 < count($c); $i++) {
        $total += hypot($c[$i + 1][0] - $c[$i][0], $c[$i + 1][1] - $c[$i][1]);
    }
    $target = $total / 2.0;
    $jalan = 0.0;
    for ($i = 0; $i + 1 < count($c); $i++) {
        $panjang = hypot($c[$i + 1][0] - $c[$i][0], $c[$i + 1][1] - $c[$i][1]);
        if ($panjang > 0 && $jalan + $panjang >= $target) {
            $t = ($target - $jalan) / $panjang;
            return [
                $c[$i][0] + $t * ($c[$i + 1][0] - $c[$i][0]),
                $c[$i][1] + $t * ($c[$i + 1][1] - $c[$i][1]),
            ];
        }
        $jalan += $panjang;
    }
    return $c[0];
}

$fitur = [];
$cara = [];
$nPoligon = 0;
$nGaris = 0;
$nLain = 0;
$luasTotal = 0.0;
$luar = 0;

foreach ($geojson['features'] as $i => $f) {
    $g = is_array($f['geometry'] ?? null) ? $f['geometry'] : [];
    $tipe = (string) ($g['type'] ?? '');
    $pusat = null;
    $jalan = '';

    if ($tipe === 'Polygon') {
        $rings = [];
        foreach ((array) ($g['coordinates'] ?? []) as $r) {
            $c = cincin((array) $r);
            if (count($c) >= 3) {
                $rings[] = $c;
            }
        }
        if ($rings === []) {
            $nLain++;
            continue;
        }
        [$pusat, $jalan] = titik_dalam($rings);
        $luas = abs(luas_bertanda($rings[0]));
        $luasTotal += $luas;
        $nPoligon++;
        if (!titik_di_poligon($rings, $pusat[0], $pusat[1])) {
            $luar++;
        }
    } elseif ($tipe === 'LineString') {
        $c = cincin((array) ($g['coordinates'] ?? []));
        if (count($c) < 2) {
            $nLain++;
            continue;
        }
        $pusat = tengah_garis($c);
        $jalan = 'tengah-garis';
        $nGaris++;
    } elseif ($tipe === 'MultiPolygon') {
        $rings = [];
        foreach ((array) ($g['coordinates'] ?? []) as $poligon) {
            $c = cincin((array) ($poligon[0] ?? []));
            if (count($c) >= 3) {
                $rings[] = $c;
            }
        }
        if ($rings === []) {
            $nLain++;
            continue;
        }
        [$pusat, $jalan] = titik_dalam($rings);
        $luas = abs(luas_bertanda($rings[0]));
        $luasTotal += $luas;
        $nPoligon++;
        if (!titik_di_poligon($rings, $pusat[0], $pusat[1])) {
            $luar++;
        }
    } else {
        $nLain++;
        continue;
    }

    $cara[$jalan] = ($cara[$jalan] ?? 0) + 1;
    $properti = [
        'i' => $i,
        'sumber' => SUMBER,
        'jenis' => $tipe,
        'terbuka' => $tipe === 'LineString',
        'pusat' => $jalan,
    ];
    if ($tipe === 'Polygon' || $tipe === 'MultiPolygon') {
        $properti['luas_m2'] = round($luas, 2);
    }
    $fitur[] = [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [round($pusat[0], 3), round($pusat[1], 3)],
        ],
        'properties' => $properti,
    ];
}

ksort($cara);
printf("berkas sumber     : %s\n", $berkasMasuk);
printf("fitur sumber      : %d (poligon %d, garis %d, lain %d)\n",
    count($geojson['features']), $nPoligon, $nGaris, $nLain);
printf("titik ditulis     : %d\n", count($fitur));
$bagian = [];
foreach ($cara as $nama => $n) {
    $bagian[] = "$nama $n";
}
printf("cara titik        : %s\n", implode(', ', $bagian));
printf("luas poligon      : %.2f m2\n", $luasTotal);
printf("titik di luar ring: %d\n", $luar);

if ($keluar !== '') {
    file_put_contents($keluar, json_encode(['type' => 'FeatureCollection', 'features' => $fitur],
        JSON_UNESCAPED_SLASHES));
    printf("pratinjau         : %s\n", $keluar);
}

if (!$terapkan) {
    echo "uji kering; tambahkan --terapkan untuk menulis berkas baru.\n";
    exit(0);
}

$tujuan = DIR_LAPIS . BERKAS_KELUAR;
$metaPath = DIR_LAPIS . 'metadata.json';
$stempel = date('Ymd-His');
$cadang = __DIR__ . '/../_lama/cadangan-titik-tengah-bidang-' . date('Ymd') . '/';
if (!is_dir($cadang)) {
    mkdir($cadang, 0777, true);
}
if (is_file($tujuan)) {
    copy($tujuan, $cadang . 'titik-tengah-bidang-hm-' . $stempel . '.geojson');
}
if (is_file($metaPath)) {
    copy($metaPath, $cadang . 'metadata-' . $stempel . '.json');
}

file_put_contents($tujuan, json_encode(['type' => 'FeatureCollection', 'features' => $fitur],
    JSON_UNESCAPED_SLASHES));

$meta = json_decode((string) @file_get_contents($metaPath), true);
if (!is_array($meta)) {
    fwrite(STDERR, "metadata.json tidak dapat dibaca: $metaPath\n");
    exit(1);
}
$meta['layers'][KUNCI_META] = [
    'features' => count($fitur),
    'types' => ['point' => count($fitur)],
    'asal' => [SUMBER => count($fitur)],
    'cara' => $cara,
    'titik_tengah_bidang' => [
        'alat' => 'tools/titik-tengah-bidang-hm.php',
        'pada' => date('c'),
        'aturan' => 'satu titik per fitur: poligon memakai titik berat badan (fallback garis pindai kotak batas, lalu pertengahan kotak batas); garis terbuka memakai titik tengah menurut panjang',
        'sumber_berkas' => BERKAS_MASUK,
        'sebelum' => [
            'fitur' => count($geojson['features']),
            'poligon' => $nPoligon,
            'garis' => $nGaris,
            'lain' => $nLain,
            'luas_poligon_m2' => round($luasTotal, 2),
        ],
        'sesudah' => ['titik' => count($fitur), 'di_luar_ring' => $luar],
    ],
];
if (file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    fwrite(STDERR, "gagal menulis metadata.json\n");
    exit(1);
}

printf("cadangan          : %s\n", $cadang);
printf("ditulis           : %s · %d titik\n", $tujuan, count($fitur));
