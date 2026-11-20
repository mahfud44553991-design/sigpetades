<?php
/**
 * Pangkas ujung garis perairan yang menggantung (tidak lagi menyentuh garis lain)
 * sampai titik sentuh terakhirnya, mis. sesudah cincin bidangnya dibuang.
 *
 * Aturan: ujung yang jaraknya ke ruas garis LAIN > toleransi dianggap menggantung.
 * Dari ujung itu ditelusuri masuk sepanjang garis (langkah 0,25 m) sampai titik
 * pertama yang menyentuh garis lain (<= toleransi); bagian sebelum titik itu
 * dibuang. Bila sepanjang garis tidak ada sentuhan, baris dihapus.
 *
 * Pemakaian:
 *   php tools/pangkas-ujung-menggantung.php [--tol=0.35] [--keluar=pratinjau.geojson]
 *   php tools/pangkas-ujung-menggantung.php --terapkan
 */
require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const DIR_LAPIS = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/';
const KUNCI_META = 'GARIS BATAS PERAIRAN 020100';
const SEL = 25.0;

$opts = getopt('', ['tol::', 'langkah::', 'berkas::', 'keluar::', 'terapkan']);
$tol = (float) ($opts['tol'] ?? 0.35);
$langkah = (float) ($opts['langkah'] ?? 0.25);
$berkas = (string) ($opts['berkas'] ?? DIR_LAPIS . 'garis-perairan.geojson');
$keluar = (string) ($opts['keluar'] ?? '');
$terapkan = isset($opts['terapkan']);

$geojson = json_decode((string) file_get_contents($berkas), true);
if (!is_array($geojson) || !isset($geojson['features'])) {
    fwrite(STDERR, "berkas tidak terbaca: $berkas\n");
    exit(1);
}

function jarakTitikRuas(array $p, array $a, array $b): float
{
    $vx = $b[0] - $a[0]; $vy = $b[1] - $a[1];
    $wx = $p[0] - $a[0]; $wy = $p[1] - $a[1];
    $L2 = $vx * $vx + $vy * $vy;
    $t = $L2 > 0 ? max(0.0, min(1.0, ($wx * $vx + $wy * $vy) / $L2)) : 0.0;
    return hypot($p[0] - ($a[0] + $t * $vx), $p[1] - ($a[1] + $t * $vy));
}

function panjangGaris(array $t): float
{
    $s = 0.0;
    for ($i = 0; $i < count($t) - 1; $i++) {
        $s += hypot($t[$i + 1][0] - $t[$i][0], $t[$i + 1][1] - $t[$i][1]);
    }
    return $s;
}

/** Kisi ruas: kunci sel -> daftar [indeks fitur, a, b]. */
function bikinKisi(array $fitur): array
{
    $kisi = [];
    foreach ($fitur as $j => $f) {
        $t = $f['geometry']['coordinates'];
        for ($i = 0; $i < count($t) - 1; $i++) {
            $a = $t[$i]; $b = $t[$i + 1];
            $x0 = (int) floor(min($a[0], $b[0]) / SEL); $x1 = (int) floor(max($a[0], $b[0]) / SEL);
            $y0 = (int) floor(min($a[1], $b[1]) / SEL); $y1 = (int) floor(max($a[1], $b[1]) / SEL);
            for ($x = $x0; $x <= $x1; $x++) for ($y = $y0; $y <= $y1; $y++) {
                $kisi["$x:$y"][] = [$j, $a, $b];
            }
        }
    }
    return $kisi;
}

function jarakKeLain(array $p, int $jSelf, array $kisi, float $tol): float
{
    $x = (int) floor($p[0] / SEL); $y = (int) floor($p[1] / SEL);
    $d = INF;
    for ($gx = $x - 1; $gx <= $x + 1; $gx++) for ($gy = $y - 1; $gy <= $y + 1; $gy++) {
        foreach ($kisi["$gx:$gy"] ?? [] as [$j, $a, $b]) {
            if ($j === $jSelf) continue;
            $dd = jarakTitikRuas($p, $a, $b);
            if ($dd < $d) { $d = $dd; if ($d <= $tol) return $d; }
        }
    }
    return $d;
}

/** Telusuri dari ujung ke dalam sampai titik pertama yang menyentuh garis lain.
 *  Kembalikan [titik_potong, sisa_titik] atau null bila tak pernah menyentuh. */
function pangkasDariUjung(array $t, bool $dariAwal, int $jSelf, array $kisi, float $tol, float $langkah): ?array
{
    if (!$dariAwal) $t = array_reverse($t);
    $n = count($t);
    for ($i = 0; $i < $n - 1; $i++) {
        $a = $t[$i]; $b = $t[$i + 1];
        $L = hypot($b[0] - $a[0], $b[1] - $a[1]);
        if ($L <= 1e-9) continue;
        $bagi = max(1, (int) ceil($L / $langkah));
        for ($k = 0; $k < $bagi; $k++) {
            $u = $k / $bagi;
            $p = [$a[0] + $u * ($b[0] - $a[0]), $a[1] + $u * ($b[1] - $a[1])];
            if (jarakKeLain($p, $jSelf, $kisi, $tol) <= $tol) {
                $sisa = array_merge([$p], array_slice($t, $i + 1));
                return [$p, $dariAwal ? $sisa : array_reverse($sisa)];
            }
        }
    }
    return null;
}

$fitur = $geojson['features'];
$kisi = bikinKisi($fitur);
$tolak = []; $kode = []; $baru = []; $hilang = 0; $dipangkas = 0;
$panjangBuang = 0.0; $perCara = [];

foreach ($fitur as $j => $f) {
    $t = $f['geometry']['coordinates'];
    $n = count($t);
    if (($f['geometry']['type'] ?? '') !== 'LineString' || $n < 2) { $baru[] = $f; continue; }
    $cara = (string) ($f['properties']['cara'] ?? '?');
    $L0 = panjangGaris($t);
    $t = $t;
    $ubah = false;
    for ($putaran = 0; $putaran < 3; $putaran++) {
        $dipangkasPutaran = false;
        foreach ([true, false] as $dariAwal) {
            $n = count($t);
            if ($n < 2) break;
            $p = $dariAwal ? $t[0] : $t[$n - 1];
            if (jarakKeLain($p, $j, $kisi, $tol) <= $tol) continue;
            $hasil = pangkasDariUjung($t, $dariAwal, $j, $kisi, $tol, $langkah);
            if ($hasil === null) {
                $t = [];
                $ubah = true; $dipangkasPutaran = true;
                break;
            }
            $t = $hasil[1];
            $ubah = true; $dipangkasPutaran = true;
            if (count($t) < 2) { $t = []; break; }
        }
        if (!$dipangkasPutaran) break;
        if ($t === []) break;
    }
    $L1 = $t === [] ? 0.0 : panjangGaris($t);
    // Hanya baris yang tergerus ujungnya yang boleh hilang; baris pendek yang
    // memang tidak menggantung (lingkaran mikro jahitan < 1 m) tetap disimpan.
    if ($ubah && $L1 < 1.0) {
        $hilang++; $ubah = true;
        $panjangBuang += $L0;
        $perCara[$cara] = ($perCara[$cara] ?? 0) + 1;
        $tolak[] = (string) ($f['properties']['kode'] ?? '');
        continue;
    }
    if ($ubah) {
        $dipangkas++;
        $panjangBuang += $L0 - $L1;
        $perCara[$cara] = ($perCara[$cara] ?? 0) + 1;
        $g = $f; $g['geometry'] = ['type' => 'LineString', 'coordinates' => array_values($t)];
        $g['properties']['dipangkas_m'] = round($L0 - $L1, 2);
        $baru[] = $g;
    } else {
        $baru[] = $f;
    }
}

$sebelumPanjang = 0.0;
foreach ($fitur as $f) $sebelumPanjang += panjangGaris($f['geometry']['coordinates']);
$sesudahPanjang = 0.0;
foreach ($baru as $f) $sesudahPanjang += panjangGaris($f['geometry']['coordinates']);

// hitung ulang ujung menggantung sesudahnya
$kisiBaru = bikinKisi($baru);
$gantungSesudah = [];
foreach ($baru as $j => $f) {
    $t = $f['geometry']['coordinates'];
    foreach ([0, count($t) - 1] as $i) {
        if (jarakKeLain($t[$i], $j, $kisiBaru, $tol) > $tol) $gantungSesudah[] = $f['properties']['kode'] . ':' . ($i ? 'b' : 'a');
    }
}

printf("berkas            : %s\n", $berkas);
printf("toleransi sentuh  : %.2f m · langkah telusur %.2f m\n", $tol, $langkah);
printf("sebelum           : %d baris · %.2f m\n", count($fitur), $sebelumPanjang);
printf("dipangkas         : %d baris · %.2f m dibuang\n", $dipangkas, $panjangBuang);
printf("dihapus (tanpa sentuhan): %d baris\n", $hilang);
printf("per cara          : %s\n", json_encode($perCara, JSON_UNESCAPED_UNICODE));
printf("sesudah           : %d baris · %.2f m\n", count($baru), $sesudahPanjang);
printf("ujung menggantung sesudah: %d\n", count($gantungSesudah));
if ($gantungSesudah) echo "  " . implode(', ', array_slice($gantungSesudah, 0, 12)) . "\n";

if ($keluar !== '') {
    file_put_contents($keluar, json_encode(['type' => 'FeatureCollection', 'features' => $baru], JSON_UNESCAPED_SLASHES));
    printf("pratinjau         : %s\n", $keluar);
}

if (!$terapkan) {
    echo "uji kering; tambahkan --terapkan untuk menulis berkas hidup.\n";
    exit(0);
}

$stempel = date('Ymd-His');
$cadang = __DIR__ . '/../_lama/cadangan-pangkas-gantung-' . date('Ymd') . '/';
if (!is_dir($cadang)) mkdir($cadang, 0777, true);
copy($berkas, $cadang . 'garis-perairan-' . $stempel . '.geojson');
$metaPath = dirname($berkas) . '/metadata.json';
if (is_file($metaPath)) copy($metaPath, $cadang . 'metadata-' . $stempel . '.json');

file_put_contents($berkas, json_encode(['type' => 'FeatureCollection', 'features' => $baru], JSON_UNESCAPED_SLASHES));

if (is_file($metaPath)) {
    $meta = json_decode((string) file_get_contents($metaPath), true);
    if (isset($meta['layers'][KUNCI_META])) {
        $blok = &$meta['layers'][KUNCI_META];
        $caraBaru = []; $asalBaru = [];
        foreach ($baru as $f) {
            $p = $f['properties'];
            $caraBaru[$p['cara'] ?? '?'] = ($caraBaru[$p['cara'] ?? '?'] ?? 0) + 1;
            $asalBaru[$p['asal'] ?? '?'] = ($asalBaru[$p['asal'] ?? '?'] ?? 0) + 1;
        }
        $blok['features'] = count($baru);
        $blok['types'] = ['line' => count($baru)];
        $blok['panjang_m'] = round($sesudahPanjang, 2);
        $blok['cara'] = $caraBaru;
        $blok['asal'] = $asalBaru;
        $blok['pangkas_gantung'] = [
            'alat' => 'tools/pangkas-ujung-menggantung.php',
            'pada' => date('c'),
            'aturan' => 'ujung yang jaraknya ke ruas garis lain > toleransi ditelusuri masuk sampai titik sentuh pertama; bagian sebelum titik itu dibuang, baris tanpa sentuhan dihapus',
            'toleransi_m' => $tol,
            'langkah_m' => $langkah,
            'dipangkas' => $dipangkas,
            'dihapus' => $hilang,
            'panjang_dibuang_m' => round($panjangBuang, 2),
            'per_cara' => $perCara,
            'sebelum' => ['baris' => count($fitur), 'panjang_m' => round($sebelumPanjang, 2)],
            'sesudah' => ['baris' => count($baru), 'panjang_m' => round($sesudahPanjang, 2),
                'ujung_menggantung' => count($gantungSesudah)],
            'kode_dihapus' => $tolak,
        ];
        unset($blok);
        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
printf("cadangan          : %s\n", $cadang);
printf("ditulis           : %d baris · %.2f m\n", count($baru), $sesudahPanjang);
