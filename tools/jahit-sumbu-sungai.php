<?php
declare(strict_types=1);

/**
 * Jahit 72 rantai sumbu sungai (garis-sungai-label.geojson) jadi polilin utuh.
 *
 *   php -d memory_limit=1024M tools/jahit-sumbu-sungai.php            # uji kering
 *   php -d memory_limit=1024M tools/jahit-sumbu-sungai.php --terapkan
 *   php -d memory_limit=1024M tools/jahit-sumbu-sungai.php --celah=8 --rinci
 *
 *   --celah=METER      jembatani ujung berhadapan searah sampai ini (bawaan 15)
 *   --rapat=METER      ikut sambung ujung yang jaraknya <= ini tanpa syarat arah
 *                      (bawaan 1; di lapis air ujung saluran bisa bertemu tegak
 *                      lurus dengan selisih hampir berimpit)
 *   --simpul=METER     satukan ujung bebas yang berkerumun (pertemuan sungai)
 *                      sampai radius ini tanpa syarat arah (bawaan 10; 0 mematikan)
 *   --rinci            cetak semua polilin hasil
 *   --masuk=a,b        berkas bahan (tanpa .geojson) yang digabung sebelum
 *                      dijahit; bawaan garis-sungai-label
 *   --keluaran=BERKAS  nama berkas (tanpa .geojson), bawaan garis-sungai-jahit
 *   --terapkan         tulis berkas keluaran; tanpa ini tidak ada yang ditulis
 *
 * Bahan. tools/garis-sungai-label.php menurunkan sumbu sungai dari label di
 * celah persil: 72 rantai, 5.693,2 m. Diukur dengan klaster 0,35 m, TIDAK ADA
 * dua rantai yang berbagi simpul — 144 ujung semuanya berderajat 1 dan
 * jaringannya 72 komponen. Jadi jahitan mustahil hanya dengan menempelkan
 * ujung yang sudah bersimpul; sebagian celah harus dijembatani garis lurus.
 *
 * Baris dijahit UTUH, bukan dipecah per ruas: satu baris bahan tetap satu
 * potong, jadi tidak ada verteks yang dibuang dan panjangnya tidak menyusut.
 * Aturannya mengikuti tools/jahit-sumbu-blok.php dan app/sumbu-rapi.php:
 *
 *  1. SIMPUL. Ujung rantai yang berjarak <= 0,35 m satu simpul. Rantai yang
 *     kedua ujungnya jadi simpul sama adalah cincin dan ditulis apa adanya.
 *  2. SAMBUNGAN. Di simpul derajat 2 kedua rantai selalu disambung. Di simpul
 *     bercabang dua rantai dipasangkan bila belokan lanjutannya <= 40 derajat;
 *     pasangan paling lurus didahulukan dan satu rantai hanya dipakai sekali
 *     per simpul, jadi cabang lain tetap terpisah. Polilin gabungan memakai
 *     titik simpul milik rantai pertama, jadi tidak ada koordinat baru.
 *  3. CELAH. Dua ujung bebas (derajat 1) pada baris berbeda disambung bila
 *     arah sambungannya menerus di kedua ujung (<= 40 derajat) dan jaraknya
 *     <= --celah. Ujung yang jaraknya <= --rapat disambung tanpa syarat arah.
 *     Pasangan terpendek didahulukan, satu ujung sekali. Jembatan memakai dua
 *     ujung itu sebagai titik; panjangnya dicatat di `jembatan_m` tiap baris.
 *  4. SIMPUL PERTEMUAN. Ujung bebas yang tersisa dan berkerumun <= --simpul
 *     satu sama lain (single-linkage, sudut bebas) disatukan lewat pohon
 *     rentang minimum: tiap ruas jembatan menyambung dua ujung, jadi tiga
 *     ujung di satu pertemuan tetap terhubung tanpa mengarang titik baru.
 *     Ini yang menutup celah di pertemuan/belokan sungai yang sudutnya
 *     melebihi 40 derajat; tanpa aturan ini 30 ujung berjarak 2-15 m tetap
 *     terlihat putus. Jembatannya dicatat di `simpul_m`.
 *
 * Ambang 15 m + rapat 1 m adalah ambang lapis air yang dipakai
 * tools/rapikan-garis-jalan.php --lapis=perairan --celah=15 --rapat=1. Ujung
 * yang lebih jauh dibiarkan dua garis: pada jarak itu dua ujung biasanya bukan
 * satu alur yang putus, melainkan dua sungai berbeda, dan menjembataninya
 * berarti mengarang ruas melintasi persil.
 *
 * Verifikasi bawaan: setiap titik keluaran harus pernah menjadi titik bahan,
 * panjang polilin = jumlah panjang rantai + jembatan (selisih hanya dari titik
 * simpul yang dilewati), dan sisa ujung bebas diukur per pita jarak (<=2 m,
 * 2-10 m, 10-50 m, >50 m) supaya sisanya terlihat tanpa diubah diam-diam.
 *
 * Berkas bahan tidak diubah; keluaran adalah lapis baru di folder yang sama.
 */

require_once __DIR__ . '/lib-impor.php';

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_MASUK = 'garis-sungai-label';
const JUDUL = 'GARIS SUMBU SUNGAI JAHIT';
const SIMPUL_M = 0.35;
const BELOK_MAKS = 40.0;
const SEL_SIMPUL = 1.0;

function galat(string $pesan): never
{
    fwrite(STDERR, 'GAGAL: ' . $pesan . PHP_EOL);
    exit(1);
}

function angkaNum(float $n, int $desimal = 0): string
{
    return number_format($n, $desimal, ',', '.');
}

function jarak(float $ax, float $ay, float $bx, float $by): float
{
    return hypot($bx - $ax, $by - $ay);
}

function arah(array $dari, array $ke): array
{
    $dx = $ke[0] - $dari[0];
    $dy = $ke[1] - $dari[1];
    $panjang = hypot($dx, $dy);

    return $panjang <= 0.0 ? [0.0, 0.0] : [$dx / $panjang, $dy / $panjang];
}

function belokan(array $masuk, array $keluar): float
{
    $dot = max(-1.0, min(1.0, $masuk[0] * $keluar[0] + $masuk[1] * $keluar[1]));

    return rad2deg(acos($dot));
}

/** Perpotongan sejati dua ruas; bersentuhan di ujung tidak dihitung. */
function memotong(array $a, array $b, array $c, array $d): bool
{
    $o = static fn(array $p, array $q, array $r): float =>
        ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);
    $o1 = $o($a, $b, $c);
    $o2 = $o($a, $b, $d);
    $o3 = $o($c, $d, $a);
    $o4 = $o($c, $d, $b);

    return (($o1 > 0.0 && $o2 < 0.0) || ($o1 < 0.0 && $o2 > 0.0))
        && (($o3 > 0.0 && $o4 < 0.0) || ($o3 < 0.0 && $o4 > 0.0));
}

function panjangTitik(array $titik): float
{
    $jml = 0.0;
    for ($i = 1, $n = count($titik); $i < $n; $i++) {
        $jml += jarak($titik[$i - 1][0], $titik[$i - 1][1], $titik[$i][0], $titik[$i][1]);
    }

    return $jml;
}

$berkasKeluar = 'garis-sungai-jahit';
$berkasMasuk = [BERKAS_MASUK];
$celahMaks = 15.0;
$rapatMaks = 1.0;
$simpulMaks = 10.0;
$terapkan = false;
$rinci = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--terapkan') {
        $terapkan = true;
    } elseif ($arg === '--rinci') {
        $rinci = true;
    } elseif (str_starts_with($arg, '--celah=')) {
        $isi = trim(substr($arg, 8));
        if (!preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $isi)) {
            galat('--celah harus angka meter, contoh --celah=15');
        }
        $celahMaks = (float) str_replace(',', '.', $isi);
    } elseif (str_starts_with($arg, '--rapat=')) {
        $isi = trim(substr($arg, 8));
        if (!preg_match('/^\d{1,2}([.,]\d{1,2})?$/', $isi)) {
            galat('--rapat harus angka meter, contoh --rapat=1');
        }
        $rapatMaks = (float) str_replace(',', '.', $isi);
    } elseif (str_starts_with($arg, '--simpul=')) {
        $isi = trim(substr($arg, 9));
        if (!preg_match('/^\d{1,2}([.,]\d{1,2})?$/', $isi)) {
            galat('--simpul harus angka meter, contoh --simpul=10');
        }
        $simpulMaks = (float) str_replace(',', '.', $isi);
    } elseif (str_starts_with($arg, '--masuk=')) {
        $isi = trim(substr($arg, 8));
        if ($isi === '' || !preg_match('/^[a-z0-9-]+(,[a-z0-9-]+)*$/', $isi)) {
            galat('--masuk harus daftar nama berkas tanpa .geojson, dipisah koma');
        }
        $berkasMasuk = explode(',', $isi);
    } elseif (str_starts_with($arg, '--keluaran=')) {
        $berkasKeluar = trim(substr($arg, 11));
        if ($berkasKeluar === '' || !preg_match('/^[a-z0-9-]+$/', $berkasKeluar)) {
            galat('--keluaran harus huruf kecil, angka, dan tanda hubung');
        }
    } else {
        galat('argumen tidak dikenal: ' . $arg);
    }
}
if ($celahMaks <= 0.0 && $rapatMaks <= 0.0) {
    galat('--celah dan --rapat keduanya nol: tidak ada jembatan yang boleh dibuat');
}

// ---------------------------------------------------------------------------
// Bahan
// ---------------------------------------------------------------------------

$baris = [];
$panjangMasuk = 0.0;
$dilewati = 0;
$dilihat = [];
$kunciBahan = [];
$segmenBahan = [];
foreach ($berkasMasuk as $namaMasuk) {
    $bahan = geojsonBaca(DIR_LAPISAN . '/' . $namaMasuk . '.geojson');
    if ($bahan === []) {
        galat('bahan kosong: ' . $namaMasuk . '.geojson');
    }
    $sebelum = count($baris);
    foreach ($bahan as $f) {
        $g = $f['geometry'] ?? [];
        if (($g['type'] ?? '') !== 'LineString') {
            $dilewati++;
            continue;
        }
        $koor = [];
        foreach (($g['coordinates'] ?? []) as $p) {
            if (!is_array($p) || count($p) < 2) {
                continue 2;
            }
            $koor[] = [(float) $p[0], (float) $p[1]];
        }
        if (count($koor) < 2) {
            $dilewati++;
            continue;
        }
        $pro = is_array($f['properties'] ?? null) ? $f['properties'] : [];
        $kode = (string) ($pro['kode'] ?? '');
        if ($kode !== '' && isset($dilihat[$kode])) {
            galat('kode ganda lintas bahan: ' . $kode);
        }
        $dilihat[$kode] = true;
        $panjang = panjangTitik($koor);
        $panjangMasuk += $panjang;
        // Ruas bahan garis manual bisa lebih dari 5 m, jadi ruas itu harus
        // tetap sah di pemeriksaan "ruas panjang hanya jembatan".
        for ($i = 1, $m = count($koor); $i < $m; $i++) {
            $a = sprintf('%.6f:%.6f', $koor[$i - 1][0], $koor[$i - 1][1]);
            $b = sprintf('%.6f:%.6f', $koor[$i][0], $koor[$i][1]);
            $kunciBahan[$a . '|' . $b] = true;
            $kunciBahan[$b . '|' . $a] = true;
            $segmenBahan[] = [$koor[$i - 1][0], $koor[$i - 1][1], $koor[$i][0], $koor[$i][1]];
        }
        $label = $pro['label'] ?? '';
        if (is_array($label)) {
            $label = implode(', ', array_map('strval', $label));
        }
        $baris[] = [
            'kode' => $kode,
            'label' => (string) $label,
            'cara' => (string) ($pro['cara'] ?? ''),
            'titik' => $koor,
            'panjang' => $panjang,
        ];
    }
    printf("bahan %s: %d rantai\n", $namaMasuk, count($baris) - $sebelum);
}
if ($baris === []) {
    galat('tidak ada baris garis yang sah');
}
printf("bahan gabungan: %d rantai · %s m · %d baris dilewati\n", count($baris),
    angkaNum($panjangMasuk, 1), $dilewati);

// ---------------------------------------------------------------------------
// 1. Simpul ujung rantai
// ---------------------------------------------------------------------------

$simpulTitik = [];
$simpulKisi = [];
$simpul = static function (float $x, float $y) use (&$simpulTitik, &$simpulKisi): int
{
    $gx = (int) floor($x / SEL_SIMPUL);
    $gy = (int) floor($y / SEL_SIMPUL);
    for ($cx = $gx - 1; $cx <= $gx + 1; $cx++) {
        for ($cy = $gy - 1; $cy <= $gy + 1; $cy++) {
            foreach ($simpulKisi[$cx . ':' . $cy] ?? [] as $id) {
                [$sx, $sy] = $simpulTitik[$id];
                if (jarak($x, $y, $sx, $sy) <= SIMPUL_M) {
                    return $id;
                }
            }
        }
    }
    $id = count($simpulTitik);
    $simpulTitik[$id] = [$x, $y];
    $simpulKisi[$gx . ':' . $gy][] = $id;

    return $id;
};

// Ruas = satu baris bahan utuh. ujung[0] = pangkal, ujung[1] = ekor.
$ruas = [];
$cincin = 0;
foreach ($baris as $i => $b) {
    $titik = $b['titik'];
    $n = count($titik);
    $pa = $titik[0];
    $pb = $titik[$n - 1];
    $ia = $simpul($pa[0], $pa[1]);
    $ib = $simpul($pb[0], $pb[1]);
    if ($ia === $ib) {
        $cincin++;

        continue;
    }
    // Arah keluar di tiap ujung: dari verteks terdekat menuju ujung.
    $keluarA = arah($titik[1], $pa);
    $keluarB = arah($titik[$n - 2], $pb);
    $ruas[] = ['a' => $ia, 'b' => $ib, 'pa' => $pa, 'pb' => $pb,
        'keluar' => [$keluarA, $keluarB], 'kode' => $b['kode'], 'label' => $b['label'],
        'titik' => $titik, 'baris' => $i, 'jembatan' => false, 'jembatan_m' => 0.0];
}
printf("ruas: %d baris · simpul ujung: %d · cincin: %d\n", count($ruas), count($simpulTitik),
    $cincin);

// ---------------------------------------------------------------------------
// 2. Pasangan paling lurus di tiap simpul
// ---------------------------------------------------------------------------

$keluar = [];
foreach ($ruas as $id => $r) {
    $keluar[$r['a']][$id] = [-$r['keluar'][0][0], -$r['keluar'][0][1]];
    $keluar[$r['b']][$id] = [-$r['keluar'][1][0], -$r['keluar'][1][1]];
}

$pasangan = [];
$jumlahPasangan = 0;
$simpulBercabang = 0;
foreach ($keluar as $simpulId => $daftar) {
    if (count($daftar) < 2) {
        continue;
    }
    if (count($daftar) > 2) {
        $simpulBercabang++;
    }
    $ids = array_keys($daftar);
    $kandidat = [];
    $simpulDua = count($daftar) === 2;
    for ($i = 0, $m = count($ids); $i < $m; $i++) {
        for ($j = $i + 1; $j < $m; $j++) {
            $v1 = $daftar[$ids[$i]];
            $v2 = $daftar[$ids[$j]];
            $belok = belokan([-$v1[0], -$v1[1]], $v2);
            if (!$simpulDua && $belok > BELOK_MAKS) {
                continue;
            }
            $kandidat[] = [$belok, $ids[$i], $ids[$j]];
        }
    }
    if ($kandidat === []) {
        continue;
    }
    usort($kandidat, static fn(array $x, array $y): int => $x[0] <=> $y[0]);
    foreach ($kandidat as [, $a, $b]) {
        if (isset($pasangan[$simpulId][$a]) || isset($pasangan[$simpulId][$b])) {
            continue;
        }
        $pasangan[$simpulId][$a] = $b;
        $pasangan[$simpulId][$b] = $a;
        $jumlahPasangan++;
    }
}
printf("sambungan simpul: %d pasangan · simpul bercabang %d\n", $jumlahPasangan,
    $simpulBercabang);

// ---------------------------------------------------------------------------
// 3. Jembatan celah: ujung bebas yang tidak dapat pasangan
// ---------------------------------------------------------------------------

$jumlahCelah = 0;
$panjangCelah = 0.0;
$celahTerpanjang = 0.0;
$celahRapat = 0;
$ditolakMemotong = 0;
if ($celahMaks > 0.0 || $rapatMaks > 0.0) {
    $bebas = [];
    foreach ($ruas as $id => $r) {
        foreach ([0, 1] as $e) {
            $s = $e === 0 ? $r['a'] : $r['b'];
            if (isset($pasangan[$s][$id])) {
                continue;
            }
            $bebas[] = ['simpul' => $s, 'ruas' => $id, 'p' => $e === 0 ? $r['pa'] : $r['pb'],
                'baris' => $r['baris'], 'keluar' => $r['keluar'][$e]];
        }
    }
    $batas = max($celahMaks, $rapatMaks);
    $kandidat = [];
    for ($i = 0, $m = count($bebas); $i < $m; $i++) {
        for ($j = $i + 1; $j < $m; $j++) {
            if ($bebas[$i]['ruas'] === $bebas[$j]['ruas']
                || $bebas[$i]['simpul'] === $bebas[$j]['simpul']) {
                continue;
            }
            $d = jarak($bebas[$i]['p'][0], $bebas[$i]['p'][1], $bebas[$j]['p'][0],
                $bebas[$j]['p'][1]);
            if ($d <= SIMPUL_M || $d > $batas) {
                continue;
            }
            $u = arah($bebas[$i]['p'], $bebas[$j]['p']);
            // Di ujung A jembatan harus searah arah keluar A; di ujung B jembatan
            // masuk berlawanan dengan arah keluar B (B melanjutkan searah jembatan).
            $lurus = belokan($bebas[$i]['keluar'], $u) <= BELOK_MAKS
                && belokan([-$u[0], -$u[1]], $bebas[$j]['keluar']) <= BELOK_MAKS;
            $rapat = $d <= $rapatMaks;
            if (!$lurus && !$rapat) {
                continue;
            }
            // Jembatan yang memotong badan salah satu rantai yang hendak
            // disambung ditolak: keduanya sudah bertemu lewat lintasan lain dan
            // jembatan lurusnya hanya membuat sliver.
            $memotong = false;
            foreach ([$bebas[$i]['baris'], $bebas[$j]['baris']] as $barisKena) {
                $t = $baris[$barisKena]['titik'];
                for ($k = 1, $nt = count($t); $k < $nt; $k++) {
                    if (memotong($bebas[$i]['p'], $bebas[$j]['p'], $t[$k - 1], $t[$k])) {
                        $memotong = true;
                        break 2;
                    }
                }
            }
            if ($memotong) {
                $ditolakMemotong++;

                continue;
            }
            $kandidat[] = [$d, $i, $j, $rapat ? 0 : 1];
        }
    }
    usort($kandidat, static fn(array $x, array $y): int => $x[0] <=> $y[0]);
    $dipakaiUjung = [];
    foreach ($kandidat as [$d, $i, $j, $berarah]) {
        if (isset($dipakaiUjung[$i]) || isset($dipakaiUjung[$j])) {
            continue;
        }
        $dipakaiUjung[$i] = true;
        $dipakaiUjung[$j] = true;
        $idJembatan = count($ruas);
        $ruas[] = ['a' => $bebas[$i]['simpul'], 'b' => $bebas[$j]['simpul'],
            'pa' => $bebas[$i]['p'], 'pb' => $bebas[$j]['p'],
            'keluar' => [arah($bebas[$j]['p'], $bebas[$i]['p']),
                arah($bebas[$i]['p'], $bebas[$j]['p'])],
            'kode' => 'CELAH', 'label' => '', 'titik' => null, 'jembatan' => true,
            'jembatan_m' => $d];
        $pasangan[$bebas[$i]['simpul']][$bebas[$i]['ruas']] = $idJembatan;
        $pasangan[$bebas[$i]['simpul']][$idJembatan] = $bebas[$i]['ruas'];
        $pasangan[$bebas[$j]['simpul']][$bebas[$j]['ruas']] = $idJembatan;
        $pasangan[$bebas[$j]['simpul']][$idJembatan] = $bebas[$j]['ruas'];
        $jumlahCelah++;
        $panjangCelah += $d;
        $celahTerpanjang = max($celahTerpanjang, $d);
        if ($berarah === 0) {
            $celahRapat++;
        }
    }
    printf("celah: %d jembatan (%d rapat, sisanya searah) · %s m · terpanjang %s m · %d ditolak memotong rantai\n",
        $jumlahCelah, $celahRapat, angkaNum($panjangCelah, 1), angkaNum($celahTerpanjang, 1),
        $ditolakMemotong);
}

// ---------------------------------------------------------------------------
// 3b. Simpul pertemuan: ujung bebas yang berkerumun <= --simpul
// ---------------------------------------------------------------------------

$jumlahSimpul = 0;
$panjangSimpul = 0.0;
$simpulTerpanjang = 0.0;
$simpulDitolak = 0;
if ($simpulMaks > 0.0) {
    $bebas2 = [];
    foreach ($ruas as $id => $r) {
        foreach ([0, 1] as $e) {
            $s = $e === 0 ? $r['a'] : $r['b'];
            if (isset($pasangan[$s][$id])) {
                continue;
            }
            $bebas2[] = ['simpul' => $s, 'ruas' => $id, 'p' => $e === 0 ? $r['pa'] : $r['pb'],
                'baris' => $r['baris']];
        }
    }
    $m2 = count($bebas2);
    $induk2 = range(0, max(0, $m2 - 1));
    $cari2 = static function (int $a) use (&$cari2, &$induk2): int {
        return $induk2[$a] === $a ? $a : ($induk2[$a] = $cari2($induk2[$a]));
    };
    $gabung2 = static function (int $a, int $b) use (&$cari2, &$induk2): void {
        $ra = $cari2($a);
        $rb = $cari2($b);
        if ($ra !== $rb) {
            $induk2[$rb] = $ra;
        }
    };
    for ($i = 0; $i < $m2; $i++) {
        for ($j = $i + 1; $j < $m2; $j++) {
            if (jarak($bebas2[$i]['p'][0], $bebas2[$i]['p'][1],
                $bebas2[$j]['p'][0], $bebas2[$j]['p'][1]) <= $simpulMaks) {
                $gabung2($i, $j);
            }
        }
    }
    $klaster = [];
    for ($i = 0; $i < $m2; $i++) {
        $klaster[$cari2($i)][] = $i;
    }
    /** Sambung dua ujung dengan ruas jembatan simpul; tolak bila memotong badan keduanya. */
    $tambahSimpul = static function (int $i, int $j, float $d) use (&$ruas, &$pasangan,
        &$jumlahSimpul, &$panjangSimpul, &$simpulTerpanjang, &$simpulDitolak, $bebas2, $baris): bool {
        $memotong = false;
        foreach ([$bebas2[$i]['baris'], $bebas2[$j]['baris']] as $barisKena) {
            $t = $baris[$barisKena]['titik'];
            for ($k = 1, $nt = count($t); $k < $nt; $k++) {
                if (memotong($bebas2[$i]['p'], $bebas2[$j]['p'], $t[$k - 1], $t[$k])) {
                    $memotong = true;
                    break 2;
                }
            }
        }
        if ($memotong) {
            $simpulDitolak++;

            return false;
        }
        $idSimpul = count($ruas);
        $ruas[] = ['a' => $bebas2[$i]['simpul'], 'b' => $bebas2[$j]['simpul'],
            'pa' => $bebas2[$i]['p'], 'pb' => $bebas2[$j]['p'],
            'keluar' => [arah($bebas2[$j]['p'], $bebas2[$i]['p']),
                arah($bebas2[$i]['p'], $bebas2[$j]['p'])],
            'kode' => 'SIMPUL', 'label' => '', 'titik' => null, 'jembatan' => true,
            'jenis' => 'simpul', 'jembatan_m' => $d, 'baris' => -1];
        $pasangan[$bebas2[$i]['simpul']][$bebas2[$i]['ruas']] = $idSimpul;
        $pasangan[$bebas2[$i]['simpul']][$idSimpul] = $bebas2[$i]['ruas'];
        $pasangan[$bebas2[$j]['simpul']][$bebas2[$j]['ruas']] = $idSimpul;
        $pasangan[$bebas2[$j]['simpul']][$idSimpul] = $bebas2[$j]['ruas'];
        $jumlahSimpul++;
        $panjangSimpul += $d;
        $simpulTerpanjang = max($simpulTerpanjang, $d);

        return true;
    };
    foreach ($klaster as $anggota) {
        if (count($anggota) < 2) {
            continue;
        }
        // Cincin: hanya dua ujung milik satu rantai panjang, mis. 0004 (291 m,
        // ujung 4,2 m). Rantai pendek yang ujungnya berdekatan bukan cincin.
        if (count($anggota) === 2
            && $bebas2[$anggota[0]]['ruas'] === $bebas2[$anggota[1]]['ruas']) {
            $idR = $bebas2[$anggota[0]]['ruas'];
            $d = jarak($bebas2[$anggota[0]]['p'][0], $bebas2[$anggota[0]]['p'][1],
                $bebas2[$anggota[1]]['p'][0], $bebas2[$anggota[1]]['p'][1]);
            if ($d > SIMPUL_M && panjangTitik($ruas[$idR]['titik']) >= 10.0 * $d) {
                $tambahSimpul($anggota[0], $anggota[1], $d);
            }

            continue;
        }
        // Pohon rentang minimum: tiap jembatan menyambung dua ujung.
        $dalam = [$anggota[0] => true];
        $sisa = array_slice($anggota, 1);
        while ($sisa !== []) {
            $terbaik = null;
            foreach (array_keys($dalam) as $a) {
                foreach ($sisa as $bi => $b) {
                    if ($bebas2[$a]['ruas'] === $bebas2[$b]['ruas']) {
                        continue;
                    }
                    $d = jarak($bebas2[$a]['p'][0], $bebas2[$a]['p'][1],
                        $bebas2[$b]['p'][0], $bebas2[$b]['p'][1]);
                    if ($d <= SIMPUL_M || $d > $simpulMaks) {
                        continue;
                    }
                    if ($terbaik === null || $d < $terbaik[0]) {
                        $terbaik = [$d, $a, $b, $bi];
                    }
                }
            }
            if ($terbaik === null) {
                break;
            }
            [$d, $a, $b, $bi] = $terbaik;
            $tambahSimpul($a, $b, $d);
            $dalam[$b] = true;
            unset($sisa[$bi]);
        }
    }
    printf("simpul pertemuan <= %s m: %d jembatan · %s m · terpanjang %s m · %d ditolak memotong\n",
        angkaNum($simpulMaks, 1), $jumlahSimpul, angkaNum($panjangSimpul, 1),
        angkaNum($simpulTerpanjang, 1), $simpulDitolak);
}

// ---------------------------------------------------------------------------
// 4. Telusuri jadi polilin
// ---------------------------------------------------------------------------

/** Titik ruas menurut arah jalan: dari simpul `dari`. */
$jalur = static function (array $r, int $dari): array
{
    if ($r['titik'] === null) {
        return $dari === $r['a'] ? [$r['pa'], $r['pb']] : [$r['pb'], $r['pa']];
    }

    return $dari === $r['a'] ? $r['titik'] : array_reverse($r['titik']);
};

$terpakai = [];
$polilin = [];
foreach ($ruas as $idAwal => $rAwal) {
    if (isset($terpakai[$idAwal])) {
        continue;
    }
    $terpakai[$idAwal] = true;
    $titik = $jalur($rAwal, $rAwal['a']);
    $kodeRantai = [$rAwal['kode'] => true];
    $label = [$rAwal['label'] => true];
    $jumlahRuas = 1;
    $jumlahJembatan = 0;
    $panjangJembatan = 0.0;
    $jumlahTitikSimpul = 0;
    $panjangTitikSimpul = 0.0;
    $simpulAwal = $rAwal['a'];
    $simpulAkhir = $rAwal['b'];

    foreach ([true, false] as $maju) {
        $simpulKini = $maju ? $simpulAkhir : $simpulAwal;
        $idKini = $idAwal;
        while (($idLanjut = $pasangan[$simpulKini][$idKini] ?? null) !== null) {
            if (isset($terpakai[$idLanjut])) {
                break;
            }
            $lanjut = $ruas[$idLanjut];
            $dariSimpul = $lanjut['a'] === $simpulKini;
            if (!$dariSimpul && $lanjut['b'] !== $simpulKini) {
                break;
            }
            $simpulBaru = $dariSimpul ? $lanjut['b'] : $lanjut['a'];
            // Maju: potong dimulai di simpul sambung. Mundur: potong berakhir di
            // simpul sambung, karena ia ditempelkan di depan polilin.
            $potong = $jalur($lanjut, $maju ? $simpulKini : $simpulBaru);
            // Titik simpul rantai pertama yang dipakai; ujung rantai berikutnya
            // yang berjarak <= 0,35 m dilewati supaya tidak jadi verteks ganda.
            if ($maju) {
                $akhir = $titik[count($titik) - 1];
                if (jarak($akhir[0], $akhir[1], $potong[0][0], $potong[0][1]) <= SIMPUL_M) {
                    array_shift($potong);
                }
            } else {
                $awalTitik = $titik[0];
                $akhirPotong = $potong[count($potong) - 1];
                if (jarak($akhirPotong[0], $akhirPotong[1], $awalTitik[0], $awalTitik[1])
                    <= SIMPUL_M) {
                    array_pop($potong);
                }
            }
            $terpakai[$idLanjut] = true;
            $jumlahRuas++;
            $kodeRantai[$lanjut['kode']] = true;
            $label[$lanjut['label']] = true;
            if ($lanjut['jembatan']) {
                if (($lanjut['jenis'] ?? '') === 'simpul') {
                    $jumlahTitikSimpul++;
                    $panjangTitikSimpul += $lanjut['jembatan_m'];
                } else {
                    $jumlahJembatan++;
                    $panjangJembatan += $lanjut['jembatan_m'];
                }
            }
            if ($maju) {
                $titik = array_merge($titik, $potong);
                $simpulAkhir = $simpulBaru;
            } else {
                $titik = array_merge($potong, $titik);
                $simpulAwal = $simpulBaru;
            }
            $simpulKini = $simpulBaru;
            $idKini = $idLanjut;
        }
    }

    $polilin[] = [
        'titik' => $titik,
        'rantai' => array_values(array_filter(array_keys($kodeRantai),
            static fn(string $s): bool => $s !== '' && $s !== 'CELAH' && $s !== 'SIMPUL')),
        'label' => array_values(array_filter(array_keys($label),
            static fn(string $s): bool => $s !== '')),
        'ruas' => $jumlahRuas,
        'jembatan' => $jumlahJembatan,
        'jembatan_m' => $panjangJembatan,
        'simpul' => $jumlahTitikSimpul,
        'simpul_m' => $panjangTitikSimpul,
        'panjang' => panjangTitik($titik),
    ];
}

// Cincin ditambahkan apa adanya, tidak ikut ditelusuri.
foreach ($baris as $b) {
    $pa = $b['titik'][0];
    $pb = $b['titik'][count($b['titik']) - 1];
    if (jarak($pa[0], $pa[1], $pb[0], $pb[1]) > SIMPUL_M) {
        continue;
    }
    $polilin[] = ['titik' => $b['titik'], 'rantai' => [$b['kode']], 'label' => [$b['label']],
        'ruas' => 1, 'jembatan' => 0, 'jembatan_m' => 0.0, 'simpul' => 0, 'simpul_m' => 0.0,
        'panjang' => $b['panjang']];
}

usort($polilin, static fn(array $a, array $b): int => $b['panjang'] <=> $a['panjang']);

$panjangKeluaran = array_sum(array_column($polilin, 'panjang'));
$jumlahSimpulKeluaran = array_sum(array_column($polilin, 'simpul'));
printf("polilin: %d · %s m · rantai tergabung %d · jembatan celah %d · jembatan simpul %d\n",
    count($polilin), angkaNum($panjangKeluaran, 1), array_sum(array_column($polilin, 'ruas')),
    $jumlahCelah, $jumlahSimpulKeluaran);

// ---------------------------------------------------------------------------
// Verifikasi
// ---------------------------------------------------------------------------

$titikBahan = [];
foreach ($baris as $b) {
    foreach ($b['titik'] as $t) {
        $titikBahan[sprintf('%.6f:%.6f', $t[0], $t[1])] = true;
    }
}
$baru = 0;
$jumlahTitik = 0;
foreach ($polilin as $p) {
    foreach ($p['titik'] as $t) {
        $jumlahTitik++;
        if (!isset($titikBahan[sprintf('%.6f:%.6f', $t[0], $t[1])])) {
            $baru++;
        }
    }
}
printf("verifikasi: titik keluaran %d · titik yang tidak ada di bahan %d\n", $jumlahTitik, $baru);
if ($baru > 0) {
    galat('ada titik keluaran yang bukan titik bahan');
}

$selisih = abs($panjangKeluaran - ($panjangMasuk + $panjangCelah + $panjangSimpul));
// Tiap sambungan membuang satu ujung yang berjarak <= SIMPUL_M dari ujung
// rantai sebelumnya, jadi selisih panjang paling banyak sebanyak itu.
// Jembatan (celah maupun simpul) menempel persis di ujung rantai, jadi
// sambungan yang bisa membuang panjang hanya sambungan rantai ke rantai.
$jumlahSambung = max(0, array_sum(array_column($polilin, 'ruas')) - count($polilin)
    - $jumlahCelah - $jumlahSimpul);
printf("verifikasi: bahan+jembatan %s m · keluaran %s m · beda %s m\n",
    angkaNum($panjangMasuk + $panjangCelah + $panjangSimpul, 1), angkaNum($panjangKeluaran, 1),
    angkaNum($panjangKeluaran - ($panjangMasuk + $panjangCelah + $panjangSimpul), 1));
if ($selisih > $jumlahSambung * SIMPUL_M + 0.5) {
    galat('panjang keluaran menyimpang dari bahan ditambah jembatan');
}

// Satu-satunya ruas keluaran yang boleh lebih dari 5 m adalah jembatan atau
// ruas bahan itu sendiri (garis manual digambar dengan ruas panjang); ruas
// panjang lain membuktikan ada garis karangan selain jembatan yang dihitung.
$kunciJembatan = [];
foreach ($ruas as $r) {
    if (!$r['jembatan']) {
        continue;
    }
    $a = sprintf('%.6f:%.6f', $r['pa'][0], $r['pa'][1]);
    $b = sprintf('%.6f:%.6f', $r['pb'][0], $r['pb'][1]);
    $kunciJembatan[$a . '|' . $b] = true;
    $kunciJembatan[$b . '|' . $a] = true;
}
$panjangRuasPanjang = 0;
$jumlahRuasPanjang = 0;
$ruasAsing = 0;
foreach ($polilin as $p) {
    for ($i = 1, $m = count($p['titik']); $i < $m; $i++) {
        [$ax, $ay] = $p['titik'][$i - 1];
        [$bx, $by] = $p['titik'][$i];
        $d = jarak($ax, $ay, $bx, $by);
        if ($d <= 5.0) {
            continue;
        }
        $jumlahRuasPanjang++;
        $panjangRuasPanjang += $d;
        $kunci = sprintf('%.6f:%.6f|%.6f:%.6f', $ax, $ay, $bx, $by);
        if (!isset($kunciJembatan[$kunci]) && !isset($kunciBahan[$kunci])) {
            // Simpul yang disatukan bisa menggeser ujung rantai sampai SIMPUL_M,
            // jadi ruas bahan dicocokkan ulang dengan toleransi itu.
            $dariBahan = false;
            foreach ($segmenBahan as $s) {
                if ((jarak($ax, $ay, $s[0], $s[1]) <= SIMPUL_M && jarak($bx, $by, $s[2], $s[3]) <= SIMPUL_M)
                    || (jarak($ax, $ay, $s[2], $s[3]) <= SIMPUL_M && jarak($bx, $by, $s[0], $s[1]) <= SIMPUL_M)) {
                    $dariBahan = true;
                    break;
                }
            }
            if (!$dariBahan) {
                $ruasAsing++;
            }
        }
    }
}
printf("verifikasi: ruas > 5 m %d · %s m · bukan jembatan %d\n", $jumlahRuasPanjang,
    angkaNum($panjangRuasPanjang, 1), $ruasAsing);
if ($ruasAsing > 0) {
    galat('ada ruas panjang yang bukan jembatan');
}

if ($rinci) {
    echo "\npolilin hasil:\n";
    foreach ($polilin as $i => $p) {
        $catatan = [];
        if ($p['jembatan'] > 0) {
            $catatan[] = 'celah ' . angkaNum($p['jembatan_m'], 1) . ' m';
        }
        if ($p['simpul'] > 0) {
            $catatan[] = 'simpul ' . angkaNum($p['simpul_m'], 1) . ' m';
        }
        printf("  %3d. %8s m · %3d rantai · %s%s\n", $i + 1, angkaNum($p['panjang'], 1),
            count($p['rantai']), implode(',', $p['rantai']),
            $catatan === [] ? '' : ' · ' . implode(' · ', $catatan));
    }
}

// Sisa celah: jarak ujung polilin ke ujung polilin lain.
$ujung = [];
foreach ($polilin as $i => $p) {
    $a = $p['titik'][0];
    $b = $p['titik'][count($p['titik']) - 1];
    if (jarak($a[0], $a[1], $b[0], $b[1]) <= SIMPUL_M) {
        continue;
    }
    $ujung[] = [$i, $a];
    $ujung[] = [$i, $b];
}
$pita = ['<=2 m' => 0, '2-10 m' => 0, '10-50 m' => 0, '>50 m' => 0];
$pasanganUjung = [];
foreach ($ujung as $a => [$ia, $pa]) {
    $terdekat = INF;
    $kepada = null;
    foreach ($ujung as $b => [$ib, $pb]) {
        if ($ia === $ib) {
            continue;
        }
        $d = jarak($pa[0], $pa[1], $pb[0], $pb[1]);
        if ($d < $terdekat) {
            $terdekat = $d;
            $kepada = $b;
        }
    }
    if ($kepada === null) {
        continue;
    }
    $kunci = min($a, $kepada) . ':' . max($a, $kepada);
    if (isset($pasanganUjung[$kunci])) {
        continue;
    }
    $pasanganUjung[$kunci] = true;
    if ($terdekat <= 2.0) {
        $pita['<=2 m']++;
    } elseif ($terdekat <= 10.0) {
        $pita['2-10 m']++;
    } elseif ($terdekat <= 50.0) {
        $pita['10-50 m']++;
    } else {
        $pita['>50 m']++;
    }
}
printf("\nsisa celah antar ujung polilin: %d ujung bebas ·", count($ujung));
foreach ($pita as $k => $jml) {
    echo ' ' . $k . '=' . $jml;
}
echo "\n";

// ---------------------------------------------------------------------------
// Tulis
// ---------------------------------------------------------------------------

if (!$terapkan) {
    echo "\nuji kering: tidak ada berkas yang ditulis; pakai --terapkan.\n";
    exit(0);
}

$fitur = [];
foreach ($polilin as $i => $p) {
    $fitur[] = [
        'type' => 'Feature',
        'properties' => [
            'kode' => sprintf('SUNGAI-JAHIT-%04d', $i + 1),
            'sumber' => 'jahit',
            'cara' => 'jahit-label',
            'label' => $p['label'],
            'rantai' => $p['rantai'],
            'jumlah_bagian' => count($p['rantai']),
            'jumlah_ruas' => $p['ruas'],
            'jumlah_titik' => count($p['titik']),
            'jembatan' => $p['jembatan'],
            'jembatan_m' => round($p['jembatan_m'], 2),
            'simpul' => $p['simpul'],
            'simpul_m' => round($p['simpul_m'], 2),
            'panjang_m' => round($p['panjang'], 2),
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $p['titik']],
    ];
}
$naskah = json_encode(['type' => 'FeatureCollection', 'features' => $fitur,
    'metadata' => [
        'judul' => JUDUL,
        'alat' => 'tools/jahit-sumbu-sungai.php',
        'sumber' => implode(' + ', array_map(static fn(string $b): string => $b . '.geojson',
            $berkasMasuk)),
        'dibuat' => date('c'),
        'ambang' => ['simpul_m' => SIMPUL_M, 'belok_derajat' => BELOK_MAKS,
            'celah_m' => $celahMaks, 'rapat_m' => $rapatMaks, 'simpul_pertemuan_m' => $simpulMaks],
        'sebelum' => ['rantai' => count($baris), 'panjang_m' => round($panjangMasuk, 2)],
        'sesudah' => ['polilin' => count($fitur), 'panjang_m' => round($panjangKeluaran, 2),
            'jembatan' => $jumlahCelah, 'jembatan_m' => round($panjangCelah, 2),
            'simpul' => $jumlahSimpul, 'simpul_m' => round($panjangSimpul, 2)],
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
if ($naskah === false) {
    galat('gagal menyusun JSON keluaran');
}
$berkas = DIR_LAPISAN . '/' . $berkasKeluar . '.geojson';
if (file_put_contents($berkas, $naskah) === false) {
    galat('gagal menulis ' . $berkas);
}
printf("\n%s: %d fitur · %s m · ditulis\n", basename($berkas), count($fitur),
    angkaNum($panjangKeluaran, 1));

$cek = geojsonBaca($berkas);
$salah = 0;
foreach ($cek as $f) {
    if (($f['geometry']['type'] ?? '') !== 'LineString'
        || count($f['geometry']['coordinates'] ?? []) < 2) {
        $salah++;
    }
}
printf("validasi baca ulang %s: %d fitur · bukan LineString sah %d\n", basename($berkas),
    count($cek), $salah);
