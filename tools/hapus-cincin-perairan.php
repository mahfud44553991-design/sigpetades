<?php
/**
 * Buang cincin (garis tertutup) dari lapis garis-perairan yang membentuk bidang,
 * sisakan garis terbuka yang menyusur/menumpang alur sungai.
 *
 * Aturan: baris LineString yang ujung pangkalnya berimpit (<= 0,35 m) dan luas
 * sistem tertutupnya >= 20 m2 dianggap bidang. Lingkaran mikro hasil jahitan
 * (luas ~0, keliling < 1 m) dipertahankan karena hanya berfungsi menyambung
 * beberapa ujung garis di satu simpul, bukan membentuk bidang.
 *
 * Luas dihitung dengan shoelace setelah koordinat digeser ke titik pertama
 * (koordinat UTM ~2,6e5 x 7,1e5 membuat shoelace mentah kehilangan presisi).
 *
 * Pemakaian:
 *   php tools/hapus-cincin-perairan.php [--ambang=20] [--keluar=pratinjau.geojson]
 *   php tools/hapus-cincin-perairan.php --terapkan
 */
require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const DIR_LAPIS = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/';
const KUNCI_META = 'GARIS BATAS PERAIRAN 020100';
const TOL_SIMPUL = 0.35;

$opts = getopt('', ['ambang::', 'keluar::', 'berkas::', 'terapkan']);
$ambang = (float) ($opts['ambang'] ?? 20.0);
$berkas = (string) ($opts['berkas'] ?? DIR_LAPIS . 'garis-perairan.geojson');
$keluar = (string) ($opts['keluar'] ?? '');
$terapkan = isset($opts['terapkan']);

$geojson = json_decode((string) file_get_contents($berkas), true);
if (!is_array($geojson) || !isset($geojson['features'])) {
    fwrite(STDERR, "berkas tidak terbaca: $berkas\n");
    exit(1);
}

function luasTertutup(array $t): float
{
    $n = count($t);
    if ($n < 4) return 0.0;
    $ox = $t[0][0]; $oy = $t[0][1]; $s = 0.0;
    for ($i = 0; $i < $n - 1; $i++) {
        $x1 = $t[$i][0] - $ox;     $y1 = $t[$i][1] - $oy;
        $x2 = $t[$i + 1][0] - $ox; $y2 = $t[$i + 1][1] - $oy;
        $s += $x1 * $y2 - $x2 * $y1;
    }
    return abs($s) / 2.0;
}

function panjangGaris(array $t): float
{
    $s = 0.0;
    for ($i = 0; $i < count($t) - 1; $i++) {
        $s += hypot($t[$i + 1][0] - $t[$i][0], $t[$i + 1][1] - $t[$i][1]);
    }
    return $s;
}

function tertutup(array $t): bool
{
    $n = count($t);
    return $n >= 2 && hypot($t[0][0] - $t[$n - 1][0], $t[0][1] - $t[$n - 1][1]) <= TOL_SIMPUL;
}

/** Komponen dan ujung bebas; cincin tertutup tidak pernah jadi ujung bebas. */
function graf(array $fitur): array
{
    $node = []; $kisi = []; $derajat = [];
    $ambil = function (array $p) use (&$node, &$kisi): int {
        $x = (int) floor($p[0]); $y = (int) floor($p[1]);
        for ($gx = $x - 1; $gx <= $x + 1; $gx++) for ($gy = $y - 1; $gy <= $y + 1; $gy++) {
            foreach ($kisi["$gx:$gy"] ?? [] as $id) {
                if (hypot($node[$id][0] - $p[0], $node[$id][1] - $p[1]) <= TOL_SIMPUL) return $id;
            }
        }
        $id = count($node); $node[$id] = $p; $kisi["$x:$y"][] = $id; return $id;
    };
    $induk = [];
    $cari = function (int $a) use (&$induk): int {
        while ($induk[$a] !== $a) { $induk[$a] = $induk[$induk[$a]]; $a = $induk[$a]; }
        return $a;
    };
    foreach ($fitur as $ft) {
        $t = $ft['geometry']['coordinates'];
        $a = $ambil($t[0]); $b = $ambil($t[count($t) - 1]);
        if (!isset($induk[$a])) $induk[$a] = $a;
        if (!isset($induk[$b])) $induk[$b] = $b;
        $ra = $cari($a); $rb = $cari($b);
        if ($ra !== $rb) $induk[$ra] = $rb;
        if ($a !== $b) {
            $derajat[$a] = ($derajat[$a] ?? 0) + 1;
            $derajat[$b] = ($derajat[$b] ?? 0) + 1;
        }
    }
    $komp = [];
    foreach (array_keys($induk) as $n) $komp[$cari($n)] = true;
    $ujung = 0;
    foreach ($derajat as $d) if ($d === 1) $ujung++;
    return ['komponen' => count($komp), 'ujung_bebas' => $ujung];
}

$simpan = []; $buang = []; $mikro = 0;
foreach ($geojson['features'] as $ft) {
    $t = $ft['geometry']['coordinates'] ?? [];
    if (($ft['geometry']['type'] ?? '') !== 'LineString' || count($t) < 2) { $simpan[] = $ft; continue; }
    if (!tertutup($t)) { $simpan[] = $ft; continue; }
    $luas = luasTertutup($t);
    if ($luas >= $ambang) {
        $ft['_luas'] = $luas;
        $ft['_panjang'] = panjangGaris($t);
        $buang[] = $ft;
    } else { $simpan[] = $ft; $mikro++; }
}

$hitung = function (array $fitur): array {
    $cara = []; $asal = []; $panjang = 0.0;
    foreach ($fitur as $ft) {
        $p = $ft['properties'];
        $cara[$p['cara'] ?? '?'] = ($cara[$p['cara'] ?? '?'] ?? 0) + 1;
        $asal[$p['asal'] ?? '?'] = ($asal[$p['asal'] ?? '?'] ?? 0) + 1;
        $panjang += panjangGaris($ft['geometry']['coordinates']);
    }
    return ['cara' => $cara, 'asal' => $asal, 'panjang' => $panjang];
};
$caraBuang = []; $asalBuang = []; $panjangBuang = 0.0; $luasBuang = 0.0;
foreach ($buang as $ft) {
    $p = $ft['properties'];
    $caraBuang[$p['cara'] ?? '?'] = ($caraBuang[$p['cara'] ?? '?'] ?? 0) + 1;
    $asalBuang[$p['asal'] ?? '?'] = ($asalBuang[$p['asal'] ?? '?'] ?? 0) + 1;
    $panjangBuang += $ft['_panjang'];
    $luasBuang += $ft['_luas'];
}
$sebelum = $hitung($geojson['features']);
$sesudah = $hitung($simpan);
$gSebelum = graf($geojson['features']);
$gSesudah = graf($simpan);

printf("berkas        : %s\n", $berkas);
printf("ambang luas   : >= %.1f m2 (cincin lebih kecil dianggap lingkaran jahitan)\n", $ambang);
printf("sebelum       : %d baris · %.2f m · komponen %d · ujung bebas %d\n",
    count($geojson['features']), $sebelum['panjang'], $gSebelum['komponen'], $gSebelum['ujung_bebas']);
printf("cincin dibuang: %d baris · %.2f m · luas %.0f m2\n", count($buang), $panjangBuang, $luasBuang);
printf("  per cara    : %s\n", json_encode($caraBuang, JSON_UNESCAPED_UNICODE));
printf("  per asal    : %s\n", json_encode($asalBuang, JSON_UNESCAPED_UNICODE));
printf("sesudah       : %d baris · %.2f m · komponen %d · ujung bebas %d\n",
    count($simpan), $sesudah['panjang'], $gSesudah['komponen'], $gSesudah['ujung_bebas']);
printf("  per cara    : %s\n", json_encode($sesudah['cara'], JSON_UNESCAPED_UNICODE));
printf("  per asal    : %s\n", json_encode($sesudah['asal'], JSON_UNESCAPED_UNICODE));
printf("lingkaran mikro dipertahankan: %d\n", $mikro);

if ($keluar !== '') {
    $fit = array_map(static function (array $ft): array {
        unset($ft['_luas'], $ft['_panjang']);
        return $ft;
    }, $simpan);
    file_put_contents($keluar, json_encode(['type' => 'FeatureCollection', 'features' => $fit], JSON_UNESCAPED_SLASHES));
    printf("pratinjau     : %s\n", $keluar);
}

if (!$terapkan) {
    echo "uji kering; tambahkan --terapkan untuk menulis berkas hidup.\n";
    exit(0);
}

$stempel = date('Ymd-His');
$cadang = __DIR__ . '/../_lama/cadangan-buang-cincin-' . date('Ymd') . '/';
if (!is_dir($cadang)) mkdir($cadang, 0777, true);
copy($berkas, $cadang . 'garis-perairan-' . $stempel . '.geojson');
$metaPath = dirname($berkas) . '/metadata.json';
if (is_file($metaPath)) copy($metaPath, $cadang . 'metadata-' . $stempel . '.json');

$tulis = [];
foreach ($simpan as $ft) { unset($ft['_luas'], $ft['_panjang']); $tulis[] = $ft; }
file_put_contents($berkas, json_encode(['type' => 'FeatureCollection', 'features' => $tulis], JSON_UNESCAPED_SLASHES));

if (is_file($metaPath)) {
    $meta = json_decode((string) file_get_contents($metaPath), true);
    if (isset($meta['layers'][KUNCI_META])) {
        $blok = &$meta['layers'][KUNCI_META];
        $blok['features'] = count($tulis);
        $blok['types'] = ['line' => count($tulis)];
        $blok['panjang_m'] = round($sesudah['panjang'], 2);
        $blok['asal'] = $sesudah['asal'];
        $blok['cara'] = $sesudah['cara'];
        $blok['buang_cincin'] = [
            'alat' => 'tools/hapus-cincin-perairan.php',
            'pada' => date('c'),
            'aturan' => 'baris tertutup (ujung pangkal berimpit <= 0,35 m) dengan luas >= ambang dianggap bidang dan dibuang; lingkaran mikro hasil jahitan (luas ~0) dipertahankan sebagai simpul sambungan',
            'ambang_luas_m2' => $ambang,
            'jumlah' => count($buang),
            'panjang_m' => round($panjangBuang, 2),
            'luas_m2' => round($luasBuang, 2),
            'per_cara' => $caraBuang,
            'per_asal' => $asalBuang,
            'lingkaran_mikro_dipertahankan' => $mikro,
            'sebelum' => ['baris' => count($geojson['features']), 'panjang_m' => round($sebelum['panjang'], 2),
                'komponen' => $gSebelum['komponen'], 'ujung_bebas' => $gSebelum['ujung_bebas']],
            'sesudah' => ['baris' => count($tulis), 'panjang_m' => round($sesudah['panjang'], 2),
                'komponen' => $gSesudah['komponen'], 'ujung_bebas' => $gSesudah['ujung_bebas']],
            'daftar_kode' => array_map(static fn(array $ft): string => (string) ($ft['properties']['kode'] ?? ''), $buang),
        ];
        unset($blok);
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
printf("cadangan      : %s\n", $cadang);
printf("ditulis       : %d baris · %.2f m\n", count($tulis), $sesudah['panjang']);
