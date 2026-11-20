<?php
/**
 * Garis tepi sungai: rantai panjang dari lapis garis-perairan.
 *
 * Alat ini TIDAK mengubah lapis Sungai. Ia membaca garis-perairan.geojson lalu
 * menyambung baris-barisnya menjadi rantai menurut kesinambungan simpul dan arah,
 * dan menulis berkas baru garis-tepi-sungai.geojson. Geometri tidak digeser:
 * titik yang menempel di tengah ruas garis lain disisipkan sebagai verteks
 * (kolinear, tidak mengubah bentuk) supaya bisa dipakai sebagai simpul sambung.
 *
 * Aturan sambung:
 *  - simpul = ujung garis yang berjarak <= toleransi satu sama lain, atau ujung
 *    yang menempel pada ruas garis lain (titik proyeksinya disisipkan ke garis
 *    itu, lalu kedua simpul digabung);
 *  - dari satu simpul, rantai melanjutkan ke garis yang arahnya paling lurus
 *    dengan arah datang (belokan <= --sudut); kalau tidak ada, rantai berhenti;
 *  - garis tertutup menjadi rantai sendiri; rantai < 1 m (lingkaran mikro
 *    jahitan) dibuang karena tidak tampak.
 *
 * --sudut bawaan 90 derajat: cukup untuk menyusuri belokan sungai, tetapi tidak
 * menerima putar balik, yang di simpang tiga bisa membelokkan rantai ke cabang
 * lain. Nilai lebih besar menyatukan lebih banyak (120 -> 103 rantai) dengan
 * risiko itu.
 *
 * Pemakaian:
 *   php tools/tepi-sungai.php [--sudut=90] [--keluar=pratinjau.geojson]
 *   php tools/tepi-sungai.php --terapkan
 */
require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const DIR_LAPIS = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/';
const KUNCI_MASUK = 'GARIS BATAS PERAIRAN 020100';
const KUNCI_KELUAR = 'GARIS TEPI SUNGAI 020100';
const BERKAS_KELUAR = 'garis-tepi-sungai.geojson';
const SEL = 10.0;
const MIN_RANTAI = 1.0;

$opts = getopt('', ['tol::', 'sudut::', 'berkas::', 'keluar::', 'terapkan']);
$tol = (float) ($opts['tol'] ?? 0.35);
$sudut = (float) ($opts['sudut'] ?? 90.0);
$berkas = (string) ($opts['berkas'] ?? DIR_LAPIS . 'garis-perairan.geojson');
$keluar = (string) ($opts['keluar'] ?? '');
$terapkan = isset($opts['terapkan']);

$geojson = json_decode((string) file_get_contents($berkas), true);
if (!is_array($geojson) || !isset($geojson['features'])) {
    fwrite(STDERR, "berkas tidak terbaca: $berkas\n");
    exit(1);
}

function panjangGaris(array $t): float
{
    $s = 0.0;
    for ($i = 0; $i < count($t) - 1; $i++) {
        $s += hypot($t[$i + 1][0] - $t[$i][0], $t[$i + 1][1] - $t[$i][1]);
    }
    return $s;
}

/** Proyeksi titik ke ruas: [jarak, titik_proyeksi, parameter 0..1]. */
function proyeksiRuas(array $p, array $a, array $b): array
{
    $vx = $b[0] - $a[0]; $vy = $b[1] - $a[1];
    $wx = $p[0] - $a[0]; $wy = $p[1] - $a[1];
    $L2 = $vx * $vx + $vy * $vy;
    $t = $L2 > 0 ? max(0.0, min(1.0, ($wx * $vx + $wy * $vy) / $L2)) : 0.0;
    $q = [$a[0] + $t * $vx, $a[1] + $t * $vy];
    return [hypot($p[0] - $q[0], $p[1] - $q[1]), $q, $t];
}

/** Arah keluar dari ujung `end` menuju titik di dalam garis (sample sampai 5 m). */
function arahDi(array $c, int $end, float $jarak = 5.0): array
{
    $n = count($c);
    $awal = $end === 0 ? $c[0] : $c[$n - 1];
    $langkah = $end === 0 ? 1 : -1;
    $i = $end === 0 ? 1 : $n - 2;
    while ($i >= 0 && $i < $n) {
        if (hypot($c[$i][0] - $awal[0], $c[$i][1] - $awal[1]) >= $jarak) break;
        $i += $langkah;
    }
    $i = max(0, min($n - 1, $i));
    $L = hypot($c[$i][0] - $awal[0], $c[$i][1] - $awal[1]);
    if ($L <= 1e-9) return [1.0, 0.0];
    return [($c[$i][0] - $awal[0]) / $L, ($c[$i][1] - $awal[1]) / $L];
}

/** Sambung dua potongan; titik simpul yang berimpit tidak diduplikasi. */
function gabung(array $a, array $b): array
{
    if ($a === []) return $b;
    if ($b === []) return $a;
    $akhir = $a[count($a) - 1];
    $awal = $b[0];
    $lewati = hypot($akhir[0] - $awal[0], $akhir[1] - $awal[1]) <= 0.01 ? 1 : 0;
    return array_merge($a, array_slice($b, $lewati));
}

$fitur = [];
$dilewati = 0;
foreach ($geojson['features'] as $f) {
    $t = $f['geometry']['coordinates'] ?? null;
    if (($f['geometry']['type'] ?? '') !== 'LineString' || !is_array($t) || count($t) < 2) {
        $dilewati++;
        continue;
    }
    $fitur[] = [
        'kode' => (string) ($f['properties']['kode'] ?? ''),
        'cara' => (string) ($f['properties']['cara'] ?? '?'),
        'coords' => array_values($t),
    ];
}
$n = count($fitur);

// Kisi ruas untuk mencari tempat ujung menempel di tengah garis lain.
$kisi = [];
foreach ($fitur as $j => $f) {
    $t = $f['coords'];
    for ($i = 0; $i + 1 < count($t); $i++) {
        $a = $t[$i]; $b = $t[$i + 1];
        $x0 = (int) floor(min($a[0], $b[0]) / SEL); $x1 = (int) floor(max($a[0], $b[0]) / SEL);
        $y0 = (int) floor(min($a[1], $b[1]) / SEL); $y1 = (int) floor(max($a[1], $b[1]) / SEL);
        for ($x = $x0; $x <= $x1; $x++) {
            for ($y = $y0; $y <= $y1; $y++) {
                $kisi["$x:$y"][] = [$j, $i];
            }
        }
    }
}

// Cari sisipan verteks: ujung fitur yang menempel pada badan fitur lain.
$sisip = [];       // fitur j => [segIdx => [t => titik]]
$menempel = 0;
foreach ($fitur as $j => $f) {
    $t = $f['coords'];
    $ujung = [$t[0], $t[count($t) - 1]];
    foreach ($ujung as $p) {
        $gx = (int) floor($p[0] / SEL); $gy = (int) floor($p[1] / SEL);
        $terbaik = null; $jarak = $tol;
        for ($x = $gx - 1; $x <= $gx + 1; $x++) {
            for ($y = $gy - 1; $y <= $gy + 1; $y++) {
                foreach ($kisi["$x:$y"] ?? [] as [$k, $m]) {
                    if ($k === $j) continue;
                    $c = $fitur[$k]['coords'];
                    [$d, $q, $u] = proyeksiRuas($p, $c[$m], $c[$m + 1]);
                    if ($d <= $jarak && $u > 1e-6 && $u < 1 - 1e-6) {
                        $jarak = $d; $terbaik = [$k, $m, $u, $q];
                    }
                }
            }
        }
        if ($terbaik !== null) {
            [$k, $m, $u, $q] = $terbaik;
            $sisip[$k][$m][(string) $u] = [$u, $q];
            $menempel++;
        }
    }
}

// Terapkan sisipan per fitur (urut parameter supaya verteks tetap berurutan).
foreach ($sisip as $k => $perSeg) {
    ksort($perSeg);
    $baru = [];
    $t = $fitur[$k]['coords'];
    for ($i = 0; $i + 1 < count($t); $i++) {
        $baru[] = $t[$i];
        if (isset($perSeg[$i])) {
            $daftar = $perSeg[$i];
            uasort($daftar, static fn ($a, $b) => $a[0] <=> $b[0]);
            foreach ($daftar as [, $q]) {
                $baru[] = $q;
            }
        }
    }
    $baru[] = $t[count($t) - 1];
    $fitur[$k]['coords'] = $baru;
}

// Simpul: gabungkan SEMUA verteks (bukan hanya ujung) yang berjarak <=
// toleransi, supaya ujung yang menempel di tengah ruas garis lain ikut menyatu
// lewat verteks sisipan. Kisi toleransi menjaga jumlah pembanding tetap kecil.
$semua = [];
$mulai = [];
foreach ($fitur as $j => $f) {
    $mulai[$j] = count($semua);
    foreach ($f['coords'] as $p) $semua[] = $p;
}
$selSimpul = max($tol, 0.5);
$kisiTitik = [];
foreach ($semua as $idx => $p) {
    $kisiTitik[(int) floor($p[0] / $selSimpul) . ':' . (int) floor($p[1] / $selSimpul)][] = $idx;
}
$induk = range(0, count($semua) - 1);
$cari = static function (int $a) use (&$induk): int {
    while ($induk[$a] !== $a) { $induk[$a] = $induk[$induk[$a]]; $a = $induk[$a]; }
    return $a;
};
$gabungSimpul = static function (int $a, int $b) use (&$induk, $cari): void {
    $ra = $cari($a); $rb = $cari($b);
    if ($ra !== $rb) $induk[$rb] = $ra;
};
foreach ($kisiTitik as $kunci => $daftar) {
    [$cx, $cy] = array_map('intval', explode(':', $kunci));
    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dy = -1; $dy <= 1; $dy++) {
            foreach ($kisiTitik[($cx + $dx) . ':' . ($cy + $dy)] ?? [] as $b) {
                foreach ($daftar as $a) {
                    if ($a < $b
                        && hypot($semua[$b][0] - $semua[$a][0], $semua[$b][1] - $semua[$a][1]) <= $tol) {
                        $gabungSimpul($a, $b);
                    }
                }
            }
        }
    }
}

$simpul = [];
foreach ($fitur as $j => $f) {
    $awal = $mulai[$j];
    $simpul[$j] = [$cari($awal), $cari($awal + count($f['coords']) - 1)];
}
$tetangga = [];
foreach ($simpul as $j => [$a, $b]) {
    $tetangga[$a][] = [$j, 0];
    if ($b !== $a) $tetangga[$b][] = [$j, 1];
}

// Komponen graf masuk sebagai pembanding: dua fitur satu komponen bila
// berbagi simpul (langsung atau lewat verteks sisipan).
$indukFitur = range(0, $n - 1);
$cariFitur = static function (int $a) use (&$indukFitur): int {
    while ($indukFitur[$a] !== $a) { $indukFitur[$a] = $indukFitur[$indukFitur[$a]]; $a = $indukFitur[$a]; }
    return $a;
};
foreach ($tetangga as $daftar) {
    $pertama = $daftar[0][0];
    foreach ($daftar as [$j]) {
        $ra = $cariFitur($pertama); $rb = $cariFitur($j);
        if ($ra !== $rb) $indukFitur[$rb] = $ra;
    }
}
$akarFitur = [];
for ($i = 0; $i < $n; $i++) $akarFitur[$cariFitur($i)] = true;
$komponenMasuk = count($akarFitur);

$terpakai = array_fill(0, $n, false);
$tertutup = [];
foreach ($simpul as $j => [$a, $b]) {
    $tertutup[$j] = $a === $b;
}

/** Pilih garis lanjutan paling lurus dari simpul; null bila tidak ada. */
function lanjut(int $node, array $masuk, array $simpul, array $tetangga, array $fitur,
                array &$terpakai, float $sudut): ?array
{
    $batas = cos(deg2rad($sudut));
    $pilih = null; $dotTerbaik = $batas;
    foreach ($tetangga[$node] ?? [] as [$j, $e]) {
        if ($terpakai[$j]) continue;
        $keluar = arahDi($fitur[$j]['coords'], $e);
        $dot = $masuk[0] * $keluar[0] + $masuk[1] * $keluar[1];
        if ($dot >= $dotTerbaik) { $dotTerbaik = $dot; $pilih = [$j, $e]; }
    }
    if ($pilih === null) return null;
    [$j, $e] = $pilih;
    $terpakai[$j] = true;
    $c = $fitur[$j]['coords'];
    $oriented = $e === 0 ? $c : array_reverse($c);
    $keluarNode = $e === 0 ? $simpul[$j][1] : $simpul[$j][0];
    $arah = arahDi($oriented, 1);
    return [$j, $oriented, $keluarNode, [-$arah[0], -$arah[1]]];
}

$rantai = [];
$mikro = 0;
foreach ($fitur as $j => $f) {
    if ($terpakai[$j]) continue;
    $terpakai[$j] = true;
    if ($tertutup[$j]) {
        if (panjangGaris($f['coords']) < MIN_RANTAI) { $mikro++; continue; }
        $rantai[] = ['coords' => $f['coords'], 'sumber' => [$j]];
        continue;
    }
    $coords = $f['coords'];
    $sumber = [$j];
    $awal = arahDi($f['coords'], 0);
    $akhir = arahDi($f['coords'], 1);
    $node = $simpul[$j][1];
    $masuk = [-$akhir[0], -$akhir[1]];
    while (true) {
        $r = lanjut($node, $masuk, $simpul, $tetangga, $fitur, $terpakai, $sudut);
        if ($r === null) break;
        [$k, $oriented, $node, $masuk] = $r;
        $coords = gabung($coords, $oriented);
        $sumber[] = $k;
        if ($tertutup[$k]) break;
    }
    $node = $simpul[$j][0];
    $masuk = [-$awal[0], -$awal[1]];
    $belakang = [];
    while (true) {
        $r = lanjut($node, $masuk, $simpul, $tetangga, $fitur, $terpakai, $sudut);
        if ($r === null) break;
        [$k, $oriented, $node, $masuk] = $r;
        $belakang[] = array_reverse($oriented);
        $sumber[] = $k;
        if ($tertutup[$k]) break;
    }
    // Potongan belakang dikumpulkan dari simpul awal keluar; menempelkannya
    // berurutan (bukan dibalik) membuat setiap potong berakhir di simpul
    // sambung potongan sebelumnya.
    foreach ($belakang as $potong) {
        $coords = gabung($potong, $coords);
    }
    $L = panjangGaris($coords);
    if ($L < MIN_RANTAI) { $mikro++; continue; }
    $rantai[] = ['coords' => $coords, 'sumber' => $sumber];
}

$panjang = [];
foreach ($rantai as $r) $panjang[] = panjangGaris($r['coords']);
array_multisort($panjang, SORT_DESC, $rantai);

$fiturBaru = [];
foreach ($rantai as $i => $r) {
    $kodeSumber = array_map(static fn ($k) => $fitur[$k]['kode'], $r['sumber']);
    $fiturBaru[] = [
        'type' => 'Feature',
        'properties' => [
            'kode' => sprintf('TEPI-SUNGAI-%04d', $i + 1),
            'cara' => 'rantai-tepi',
            'sumber' => 'garis-perairan',
            'bagian' => count($r['sumber']),
            'panjang_m' => round($panjang[$i], 2),
            'garis_sumber' => $kodeSumber,
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $r['coords']],
    ];
}

$sebelumPanjang = 0.0;
foreach ($fitur as $f) $sebelumPanjang += panjangGaris($f['coords']);
$sesudahPanjang = array_sum($panjang);
$median = $panjang === [] ? 0.0 : $panjang[(int) floor((count($panjang) - 1) / 2)];

printf("berkas masuk      : %s\n", $berkas);
printf("toleransi simpul  : %.2f m · sudut lanjut %.0f derajat\n", $tol, $sudut);
printf("sebelum           : %d baris · %.2f m · %d komponen\n", $n, $sebelumPanjang, $komponenMasuk);
printf("sisipan simpul    : %d ujung menempel di tengah ruas lain\n", $menempel);
printf("sesudah           : %d rantai · %.2f m\n", count($fiturBaru), $sesudahPanjang);
printf("rantai terpanjang : %.2f m · median %.2f m · bagian terbanyak %d\n",
    $panjang[0] ?? 0.0, $median,
    $fiturBaru === [] ? 0 : max(array_column(array_column($fiturBaru, 'properties'), 'bagian')));
printf("rantai mikro dibuang (< %.1f m): %d\n", MIN_RANTAI, $mikro);
printf("baris bukan garis : %d\n", $dilewati);

if ($keluar !== '') {
    file_put_contents($keluar, json_encode(['type' => 'FeatureCollection', 'features' => $fiturBaru],
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
$cadang = __DIR__ . '/../_lama/cadangan-tepi-sungai-' . date('Ymd') . '/';
if (!is_dir($cadang)) mkdir($cadang, 0777, true);
if (is_file($tujuan)) copy($tujuan, $cadang . 'garis-tepi-sungai-' . $stempel . '.geojson');
if (is_file($metaPath)) copy($metaPath, $cadang . 'metadata-' . $stempel . '.json');

file_put_contents($tujuan, json_encode(['type' => 'FeatureCollection', 'features' => $fiturBaru],
    JSON_UNESCAPED_SLASHES));

$meta = json_decode((string) file_get_contents($metaPath), true);
if (is_array($meta)) {
    $meta['layers'][KUNCI_KELUAR] = [
        'features' => count($fiturBaru),
        'types' => ['line' => count($fiturBaru)],
        'panjang_m' => round($sesudahPanjang, 2),
        'cara' => ['rantai-tepi' => count($fiturBaru)],
        'asal' => ['garis-perairan' => count($fiturBaru)],
        'tepi_sungai' => [
            'alat' => 'tools/tepi-sungai.php',
            'pada' => date('c'),
            'aturan' => 'baris garis-perairan disambung menjadi rantai menurut simpul (ujung berjarak <= toleransi, termasuk ujung yang menempel di tengah ruas garis lain) dan arah paling lurus; geometri tidak digeser, verteks sisipan kolinear',
            'toleransi_simpul_m' => $tol,
            'sudut_lanjut_derajat' => $sudut,
            'sumber_berkas' => 'garis-perairan.geojson',
            'sebelum' => ['baris' => $n, 'panjang_m' => round($sebelumPanjang, 2), 'komponen' => $komponenMasuk],
            'sesudah' => [
                'baris' => count($fiturBaru),
                'panjang_m' => round($sesudahPanjang, 2),
                'komponen' => count($fiturBaru),
                'terpanjang_m' => round($panjang[0] ?? 0.0, 2),
                'median_m' => round($median, 2),
            ],
            'sisipan_simpul' => $menempel,
            'rantai_mikro_dibuang' => $mikro,
        ],
    ];
    file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

printf("cadangan          : %s\n", $cadang);
printf("ditulis           : %s · %d rantai · %.2f m\n", $tujuan, count($fiturBaru), $sesudahPanjang);
