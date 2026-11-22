<?php
declare(strict_types=1);

/**
 * Rapikan garis tepi sungai agar sejajar dengan sumbunya dan tidak membentuk cincin.
 *
 *   php -d memory_limit=1024M tools/rapikan-tepi-sungai.php            # uji kering
 *   php -d memory_limit=1024M tools/rapikan-tepi-sungai.php --terapkan
 *
 *   --sudut=25     beda arah maksimum segmen tepi terhadap sumbu, bawaan 25 derajat
 *   --min=1,0      panjang baris minimum sesudah dipotong, bawaan 1,0 m
 *   --geser=0,25   toleransi titik yang mundur di sepanjang sumbu, bawaan 0,25 m
 *   --berkas=NAMA  berkas masukan tanpa .geojson, bawaan garis-tepi-sungai-dari-sumbu
 *   --keluar=NAMA  berkas keluaran tanpa .geojson; bawaan sama dengan masukan
 *   --rinci        cetak tiap baris keluaran
 *   --terapkan     tulis berkas keluaran dan perbarui metadata.json
 *
 * Bahan: `garis-tepi-sungai-dari-sumbu.geojson` (hasil
 * tools/tepi-sungai-dari-sumbu.php) dan `garis-sungai-jahit.geojson` sebagai
 * sumbu acuan. Tiap verteks tepi diproyeksikan ke sumbu asalnya sehingga
 * didapat jarak, posisi s sepanjang sumbu, dan sisi (kiri/kanan).
 *
 * Catatan 22 Nov 2026: alat penurun kini memasangkan tiap segmen tepi ke sumbu
 * (bukan menyampel sumbu), jadi keluarannya sudah bebas cincin, silang diri, dan
 * segmen menyimpang sejak lahir. Alat ini tidak lagi dipakai dalam rangkaian;
 * menjalankannya dengan --sudut=25 hanya akan memecah baris yang sah dan
 * menurunkan cakupan. Ia tinggal sebagai catatan riwayat README §3.115.
 *
 * Tiap segmen tepi hanya dipertahankan bila keempat syarat ini benar sekaligus:
 *  - kedua ujungnya di sisi yang sama dengan klaim barisnya (tidak menyeberang
 *    sumbu, yang membuat tepi kiri berubah jadi tepi kanan di ujung alur);
 *  - posisi s tidak mundur lebih dari --geser (segmen yang berbalik arah di
 *    sepanjang sumbu — ekor cincin di sekitar kolam atau ujung buntu — dibuang,
 *    sehingga tidak ada baris yang kembali mendekati awalnya);
 *  - beda arah segmen terhadap arah sumbu di posisi itu <= --sudut;
 *  - jaraknya ke sumbu antara 0,4 dan 12 m, sama dengan koridor alat penurun.
 *
 * Segmen yang lolos dikelompokkan menjadi baris menurut kesinambungannya; di
 * setiap titik putus baris lama berakhir dan baris baru mulai. Titik keluaran
 * seluruhnya verteks asli berkas masukan (tidak ada titik karangan, tidak ada
 * jembatan), jadi tidak mungkin ada baris yang menutup jadi cincin: cincin
 * memerlukan s mundur, dan itu justru syarat putus. Baris < --min dibuang.
 * Properti `kode` dinomori ulang TEPI-SUMBU-####; `dari` menyimpan kode baris
 * asal, `dipotong_m` panjang yang hilang dari baris asal.
 *
 * Keluaran ditulis ke berkas masukan (atau --keluar) sesudah berkas lama dan
 * metadata dicadangkan ke _lama/cadangan-<nama>-<stempel>/. Metadata diperbarui:
 * kunci GARIS TEPI SUNGAI DARI SUMBU mendapat blok `rapi`. Tidak ada tulisan ke
 * basis data.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_SUMBU = 'garis-sungai-jahit';
const BERKAS_MASUK = 'garis-tepi-sungai-dari-sumbu';
const JUDUL_META = 'GARIS TEPI SUNGAI DARI SUMBU';
const JARAK_MIN = 0.4;
const JARAK_MAKS = 12.0;
const SUDUT = 25.0;
const MIN_PANJANG = 1.0;
const GESER = 0.25;

$opts = getopt('', ['sudut::', 'min::', 'geser::', 'berkas::', 'keluar::', 'rinci', 'terapkan']);
$sudut = (float) ($opts['sudut'] ?? SUDUT);
$min = (float) ($opts['min'] ?? MIN_PANJANG);
$geser = (float) ($opts['geser'] ?? GESER);
$berkasMasuk = DIR_LAPISAN . '/' . (string) ($opts['berkas'] ?? BERKAS_MASUK) . '.geojson';
$namaKeluar = (string) ($opts['keluar'] ?? ($opts['berkas'] ?? BERKAS_MASUK));
$rinci = isset($opts['rinci']);
$terapkan = isset($opts['terapkan']);

if ($sudut <= 0 || $sudut > 90 || $min <= 0 || $geser < 0) {
    fwrite(STDERR, "galat: ambang tidak masuk akal\n");
    exit(1);
}

printf("mode: %s · sudut %s derajat · min %s m · geser %s m · berkas %s\n",
    $terapkan ? 'TERAPKAN' : 'uji kering', angkaNum($sudut, 0), angkaNum($min, 1),
    angkaNum($geser, 2), basename($berkasMasuk));

function angkaNum(float $n, int $desimal = 0): string
{
    return number_format($n, $desimal, ',', '.');
}

function panjangTitik(array $t): float
{
    $s = 0.0;
    for ($i = 1; $i < count($t); $i++) {
        $s += hypot($t[$i][0] - $t[$i - 1][0], $t[$i][1] - $t[$i - 1][1]);
    }
    return $s;
}

function bedaArah(float $a, float $b): float
{
    $beda = abs(fmod($a - $b, 180.0));
    return $beda > 90.0 ? 180.0 - $beda : $beda;
}

/** Proyeksi titik ke ruas: [jarak, t]. */
function proyeksiRuas(array $p, array $a, array $b): array
{
    $vx = $b[0] - $a[0];
    $vy = $b[1] - $a[1];
    $wx = $p[0] - $a[0];
    $wy = $p[1] - $a[1];
    $L2 = $vx * $vx + $vy * $vy;
    $t = $L2 > 0 ? max(0.0, min(1.0, ($wx * $vx + $wy * $vy) / $L2)) : 0.0;
    return [hypot($p[0] - ($a[0] + $t * $vx), $p[1] - ($a[1] + $t * $vy)), $t];
}

/** Tempelkan tiap verteks ke sumbu: [jarak, s, sisi]. */
function tempelKeSumbu(array $c, array $sumbu): array
{
    $geser = [];
    $s = 0.0;
    for ($i = 1; $i < count($sumbu); $i++) {
        $geser[$i] = $s;
        $s += hypot($sumbu[$i][0] - $sumbu[$i - 1][0], $sumbu[$i][1] - $sumbu[$i - 1][1]);
    }
    $keluar = [];
    foreach ($c as $p) {
        $terbaik = INF;
        $sBest = 0.0;
        $sisi = 0;
        for ($i = 1; $i < count($sumbu); $i++) {
            $a = $sumbu[$i - 1];
            $b = $sumbu[$i];
            [$jarak, $t] = proyeksiRuas($p, $a, $b);
            if ($jarak < $terbaik) {
                $terbaik = $jarak;
                $sBest = $geser[$i] + $t * hypot($b[0] - $a[0], $b[1] - $a[1]);
                $silang = ($b[0] - $a[0]) * ($p[1] - $a[1]) - ($b[1] - $a[1]) * ($p[0] - $a[0]);
                $sisi = $silang >= 0 ? 1 : -1;
            }
        }
        $keluar[] = [$terbaik, $sBest, $sisi];
    }
    return $keluar;
}

/** Arah sumbu pada posisi s. */
function arahSumbu(array $sumbu, float $s): float
{
    $sisa = $s;
    $n = count($sumbu);
    for ($i = 1; $i < $n; $i++) {
        $L = hypot($sumbu[$i][0] - $sumbu[$i - 1][0], $sumbu[$i][1] - $sumbu[$i - 1][1]);
        if ($sisa <= $L || $i === $n - 1) {
            return rad2deg(atan2($sumbu[$i][1] - $sumbu[$i - 1][1], $sumbu[$i][0] - $sumbu[$i - 1][0]));
        }
        $sisa -= $L;
    }
    return 0.0;
}

// ---------------------------------------------------------------------------
// Bahan
// ---------------------------------------------------------------------------

$masuk = json_decode((string) @file_get_contents($berkasMasuk), true);
if (!is_array($masuk) || !isset($masuk['features'])) {
    fwrite(STDERR, "galat: berkas tepi tidak terbaca: $berkasMasuk\n");
    exit(1);
}
$sumbu = [];
foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_SUMBU . '.geojson') as $f) {
    $g = $f['geometry'] ?? [];
    if (($g['type'] ?? '') !== 'LineString') {
        continue;
    }
    $titik = [];
    foreach (($g['coordinates'] ?? []) as $p) {
        if (is_array($p) && count($p) >= 2) {
            $titik[] = [(float) $p[0], (float) $p[1]];
        }
    }
    if (count($titik) >= 2) {
        $sumbu[(string) ($f['properties']['kode'] ?? '')] = $titik;
    }
}
printf("masukan: %d baris · sumbu: %d polilin\n", count($masuk['features']), count($sumbu));

// ---------------------------------------------------------------------------
// Potong tiap baris menurut syarat sejajar + sisi + arah s
// ---------------------------------------------------------------------------

$daftar = [];
$asalPanjang = [];
$sebelumPanjang = 0.0;
$dibuang = 0.0;
$dibuangBaris = 0;
foreach ($masuk['features'] as $f) {
    $g = $f['geometry'] ?? [];
    $c = [];
    foreach (($g['coordinates'] ?? []) as $p) {
        if (is_array($p) && count($p) >= 2) {
            $c[] = [(float) $p[0], (float) $p[1]];
        }
    }
    if (count($c) < 2) {
        continue;
    }
    $pr = $f['properties'] ?? [];
    $kodeAsal = (string) ($pr['kode'] ?? '');
    $kodeSumbu = (string) ($pr['sumbu'] ?? '');
    $klaim = (($pr['sisi'] ?? 'kiri') === 'kiri') ? 1 : -1;
    $panjangAsal = panjangTitik($c);
    $asalPanjang[$kodeAsal] = $panjangAsal;
    $sebelumPanjang += $panjangAsal;
    if (!isset($sumbu[$kodeSumbu])) {
        fwrite(STDERR, "galat: sumbu $kodeSumbu tidak ada untuk $kodeAsal\n");
        exit(1);
    }
    $ax = $sumbu[$kodeSumbu];
    $info = tempelKeSumbu($c, $ax);

    $potong = static function (int $a, int $b) use (&$daftar, &$dibuang, &$dibuangBaris,
        $c, $info, $pr, $kodeAsal, $ax, $min, $sudut, $klaim): void {
        $bagian = array_slice($c, $a, $b - $a + 1);
        $panjang = panjangTitik($bagian);
        if ($panjang < $min) {
            $dibuang += $panjang;
            $dibuangBaris++;
            return;
        }
        $jarak = 0.0;
        $jarakMin = INF;
        $jarakMaks = 0.0;
        for ($i = $a; $i <= $b; $i++) {
            $jarak += $info[$i][0];
            $jarakMin = min($jarakMin, $info[$i][0]);
            $jarakMaks = max($jarakMaks, $info[$i][0]);
        }
        $n = $b - $a + 1;
        $daftar[] = [
            'type' => 'Feature',
            'geometry' => ['type' => 'LineString', 'coordinates' => $bagian],
            'properties' => [
                'kode' => '',
                'cara' => 'tepi-dari-sumbu',
                'sumbu' => (string) ($pr['sumbu'] ?? ''),
                'sisi' => (string) ($pr['sisi'] ?? ''),
                'panjang_m' => round($panjang, 2),
                'jarak_rata_m' => round($jarak / $n, 2),
                'jarak_min_m' => round($jarakMin, 2),
                'jarak_maks_m' => round($jarakMaks, 2),
                'bahan' => (string) ($pr['bahan'] ?? ''),
                'titik' => $n,
                'dari' => $kodeAsal,
                'dipotong_m' => 0.0,
                'rapi' => ['sudut_derajat' => $sudut, 'min_m' => $min],
            ],
        ];
    };

    $mulai = null;
    for ($i = 1; $i < count($c); $i++) {
        $arahSeg = rad2deg(atan2($c[$i][1] - $c[$i - 1][1], $c[$i][0] - $c[$i - 1][0]));
        $dev = bedaArah($arahSeg, arahSumbu($ax, $info[$i][1]));
        $ok = $info[$i][2] === $klaim
            && $info[$i - 1][2] === $klaim
            && $info[$i][1] >= $info[$i - 1][1] - $geser
            && $dev <= $sudut
            && $info[$i][0] >= JARAK_MIN && $info[$i][0] <= JARAK_MAKS;
        if ($ok) {
            if ($mulai === null) {
                $mulai = $i - 1;
            }
        } else {
            if ($mulai !== null) {
                $potong($mulai, $i - 1);
            }
            $mulai = null;
        }
    }
    if ($mulai !== null) {
        $potong($mulai, count($c) - 1);
    }
}

// Nomori ulang dan hitung panjang hilang per baris asal.
$hilang = [];
$panjangKeluar = 0.0;
$titikTotal = 0;
foreach ($daftar as $i => $f) {
    $daftar[$i]['properties']['kode'] = sprintf('TEPI-SUMBU-%04d', $i + 1);
    $panjangKeluar += (float) $f['properties']['panjang_m'];
    $titikTotal += (int) $f['properties']['titik'];
    $hilang[$f['properties']['dari']] = ($hilang[$f['properties']['dari']] ?? 0.0)
        + (float) $f['properties']['panjang_m'];
}
foreach ($daftar as $i => $f) {
    $asal = (string) $f['properties']['dari'];
    $sisa = ($asalPanjang[$asal] ?? 0.0) - ($hilang[$asal] ?? 0.0);
    $daftar[$i]['properties']['dipotong_m'] = round(max(0.0, $sisa), 2);
}

$dibuang = $sebelumPanjang - $panjangKeluar;
printf("hasil: %d baris · %s m · %d titik; dibuang total %s m (%d potongan <%s m)\n",
    count($daftar), angkaNum($panjangKeluar, 1), $titikTotal, angkaNum($dibuang, 1),
    $dibuangBaris, angkaNum($min, 1));

// ---------------------------------------------------------------------------
// Periksa: cincin, silang diri, pindah sisi, dan segmen tak sejajar
// ---------------------------------------------------------------------------

$cincin = 0;
$silang = 0;
$pindah = 0;
$takSejajar = 0.0;
$panjangPerBaris = [];
foreach ($daftar as $f) {
    $c = $f['geometry']['coordinates'];
    $n = count($c);
    $panjangPerBaris[] = (float) $f['properties']['panjang_m'];
    if ($n >= 3 && hypot($c[0][0] - $c[$n - 1][0], $c[0][1] - $c[$n - 1][1]) <= 1.0) {
        $cincin++;
    }
    $ax = $sumbu[$f['properties']['sumbu']];
    $info = tempelKeSumbu($c, $ax);
    $klaim = $f['properties']['sisi'] === 'kiri' ? 1 : -1;
    foreach ($info as $q) {
        if ($q[2] !== $klaim) {
            $pindah++;
            break;
        }
    }
    for ($i = 1; $i < $n; $i++) {
        $dev = bedaArah(rad2deg(atan2($c[$i][1] - $c[$i - 1][1], $c[$i][0] - $c[$i - 1][0])),
            arahSumbu($ax, $info[$i][1]));
        if ($dev > $sudut + 1e-9) {
            $takSejajar += hypot($c[$i][0] - $c[$i - 1][0], $c[$i][1] - $c[$i - 1][1]);
        }
        for ($j = $i + 2; $j < $n; $j++) {
            if ($i === 1 && $j === $n - 1) {
                continue;
            }
            $d1 = ($c[$i][0] - $c[$i - 1][0]) * ($c[$j - 1][1] - $c[$i - 1][1])
                - ($c[$i][1] - $c[$i - 1][1]) * ($c[$j - 1][0] - $c[$i - 1][0]);
            $d2 = ($c[$i][0] - $c[$i - 1][0]) * ($c[$j][1] - $c[$i - 1][1])
                - ($c[$i][1] - $c[$i - 1][1]) * ($c[$j][0] - $c[$i - 1][0]);
            $d3 = ($c[$j][0] - $c[$j - 1][0]) * ($c[$i - 1][1] - $c[$j - 1][1])
                - ($c[$j][1] - $c[$j - 1][1]) * ($c[$i - 1][0] - $c[$j - 1][0]);
            $d4 = ($c[$j][0] - $c[$j - 1][0]) * ($c[$i][1] - $c[$j - 1][1])
                - ($c[$j][1] - $c[$j - 1][1]) * ($c[$i][0] - $c[$j - 1][0]);
            if ((($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0))
                && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0))) {
                $silang++;
            }
        }
    }
}
printf("periksa: cincin %d · pindah sisi %d · silang diri %d · segmen >%s derajat %s m\n",
    $cincin, $pindah, $silang, angkaNum($sudut, 0), angkaNum($takSejajar, 1));
sort($panjangPerBaris);
printf("panjang baris: terpendek %s m · median %s m · terpanjang %s m\n",
    angkaNum($panjangPerBaris[0] ?? 0, 1),
    angkaNum($panjangPerBaris[intdiv(count($panjangPerBaris), 2)] ?? 0, 1),
    angkaNum($panjangPerBaris[count($panjangPerBaris) - 1] ?? 0, 1));

if ($rinci) {
    foreach ($daftar as $f) {
        printf("%s  %-18s %-5s %7s m  dari %s\n", $f['properties']['kode'], $f['properties']['sumbu'],
            $f['properties']['sisi'], angkaNum((float) $f['properties']['panjang_m'], 1),
            $f['properties']['dari']);
    }
}

if ($cincin > 0 || $silang > 0 || $pindah > 0 || $takSejajar > 0.001) {
    fwrite(STDERR, "galat: hasil belum rapi; tidak ditulis\n");
    exit(1);
}
if (!$terapkan) {
    echo "uji kering: tidak ada berkas yang ditulis. Tambahkan --terapkan untuk menulis.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Tulis berkas dan metadata
// ---------------------------------------------------------------------------

$berkasKeluar = DIR_LAPISAN . '/' . $namaKeluar . '.geojson';
$stempel = date('Ymd-His');
$cadang = __DIR__ . '/../_lama/cadangan-' . $namaKeluar . '-' . $stempel;
if (is_file($berkasKeluar)) {
    if (!is_dir($cadang) && !mkdir($cadang, 0775, true) && !is_dir($cadang)) {
        fwrite(STDERR, "galat: gagal membuat folder cadangan\n");
        exit(1);
    }
    if (!copy($berkasKeluar, $cadang . '/' . basename($berkasKeluar))) {
        fwrite(STDERR, "galat: gagal mencadangkan berkas lama\n");
        exit(1);
    }
    echo 'cadangan berkas lama: _lama/' . basename($cadang) . "\n";
}
$naskah = json_encode(['type' => 'FeatureCollection', 'features' => $daftar],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($naskah === false || file_put_contents($berkasKeluar, $naskah) === false) {
    fwrite(STDERR, "galat: gagal menulis berkas keluaran\n");
    exit(1);
}

$berkasMeta = DIR_LAPISAN . '/metadata.json';
$meta = is_file($berkasMeta) ? (array) json_decode((string) file_get_contents($berkasMeta), true) : [];
if ($meta === []) {
    fwrite(STDERR, "galat: metadata.json tidak dapat dibaca\n");
    exit(1);
}
if (isset($cadang) && is_dir($cadang)) {
    copy($berkasMeta, $cadang . '/metadata-sebelum-' . $stempel . '.json');
}
if (!isset($meta['layers'][JUDUL_META])) {
    fwrite(STDERR, "galat: entri metadata " . JUDUL_META . " tidak ada\n");
    exit(1);
}
$jumlahSebelum = count($masuk['features']);
$meta['layers'][JUDUL_META]['features'] = count($daftar);
$meta['layers'][JUDUL_META]['types'] = ['line' => count($daftar)];
$meta['layers'][JUDUL_META]['file'] = $namaKeluar . '.geojson';
$meta['layers'][JUDUL_META]['panjang_m'] = (int) round($panjangKeluar);
$meta['layers'][JUDUL_META]['rapi'] = [
    'alat' => 'tools/rapikan-tepi-sungai.php',
    'sudut_derajat' => $sudut,
    'min_panjang_m' => $min,
    'geser_m' => $geser,
    'sebelum' => ['fitur' => $jumlahSebelum, 'panjang_m' => round($sebelumPanjang, 1)],
    'sesudah' => ['fitur' => count($daftar), 'panjang_m' => round($panjangKeluar, 1),
        'titik' => $titikTotal],
    'dibuang_m' => round($sebelumPanjang - $panjangKeluar, 1),
    'dibuang_potongan' => $dibuangBaris,
    'periksa' => ['cincin' => 0, 'pindah_sisi' => 0, 'silang_diri' => 0,
        'segmen_tak_sejajar_m' => 0.0],
    'catatan' => 'hanya verteks asli; baris putus di segmen tak sejajar, pindah sisi,'
        . ' atau posisi yang mundur di sepanjang sumbu; cincin tidak mungkin terbentuk',
];
$meta['derived_at'] = date('c');
if (file_put_contents($berkasMeta, json_encode($meta, JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) {
    fwrite(STDERR, "galat: gagal menulis metadata.json\n");
    exit(1);
}
echo 'ditulis: files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . $namaKeluar . ".geojson\n";
echo "metadata.json diperbarui: " . JUDUL_META . " (blok rapi)\n";
