<?php
declare(strict_types=1);

/**
 * Rapikan lapis GARIS TEPI SALURAN: buang tebing kembar dan jahit potongan.
 *
 *   php -d memory_limit=1024M tools/rapikan-tepi-saluran.php             # uji kering
 *   php -d memory_limit=1024M tools/rapikan-tepi-saluran.php --terapkan
 *
 *   --toleransi=M  jarak ujung yang dianggap berimpit, bawaan 0,05 m
 *   --celah=M      jarak maksimum dua ujung bebas untuk dijembatani, bawaan 1,0 m
 *   --belok=D      beda arah maksimum jembatan celah, bawaan 40 derajat
 *   --rinci        cetak rincian tiap baris
 *   --terapkan     tulis berkas dan perbarui metadata.json
 *
 * Latar. `tools/tepi-saluran.php` mengklaim segmen 020100 per saluran tanpa
 * memeriksa apakah segmen yang sama sudah diklaim baris lain pada saluran atau
 * sisi yang sama. Hasil 22 Nov 2026: 1.061 baris (689 acuan + 372 pasangan)
 * memuat 156 baris geometri kembar (3.237,6 m) dan 372 potongan pasangan di
 * 111 grup saluran+sisi (1-31 potongan per grup).
 *
 * Yang alat ini kerjakan, semuanya topologi:
 *   1. baris dengan deret titik sama (maju atau balik, dibulatkan 3 desimal)
 *      dianggap satu tebing; yang pertama dipertahankan, sisanya dibuang;
 *   2. potongan pasangan pada saluran dan sisi yang sama dijahit bila ujungnya
 *      berimpit (<= --toleransi), lalu bila jaraknya <= --celah dan arah
 *      sambungannya menerus (<= --belok derajat); jembatan celah memakai dua
 *      ujung itu sebagai titik, jadi tidak ada koordinat karangan;
 *   3. baris acuan tidak dijahit ke pasangan (sisi berbeda) dan tidak diubah
 *      geometrinya; cincin (pangkal = ujung) juga tidak dijahit;
 *   4. sambungan yang membuat cincin baru, atau jembatan yang memotong salah
 *      satu rantai, ditolak.
 *
 * Verifikasi sebelum menulis: setiap titik hasil harus pernah menjadi titik
 * bahan (dibulatkan 3 desimal), tidak ada geometri kembar, jumlah cincin tidak
 * bertambah, tidak ada silang diri, dan panjang total sama dengan bahan unik
 * ditambah panjang jembatan. `kode` dinomori ulang TEPI-SALURAN-#### menurut
 * urutan berkas; `jumlah_bagian`/`sumber_kode` menyimpan asal sambungan.
 * Berkas lama dan metadata dicadangkan ke _lama/cadangan-tepi-saluran-rapi-*.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

date_default_timezone_set('Asia/Jakarta');

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS = 'garis-tepi-saluran';
const JUDUL = 'GARIS TEPI SALURAN';

$terapkan = in_array('--terapkan', $argv, true);
$rinci = in_array('--rinci', $argv, true);
$toleransi = 0.05;
$celah = 1.0;
$belok = 40.0;
foreach ($argv as $arg) {
    if (preg_match('/^--toleransi=([0-9.]+)$/', $arg, $m)) {
        $toleransi = (float) $m[1];
    } elseif (preg_match('/^--celah=([0-9.]+)$/', $arg, $m)) {
        $celah = (float) $m[1];
    } elseif (preg_match('/^--belok=([0-9.]+)$/', $arg, $m)) {
        $belok = (float) $m[1];
    }
}

function angkaNum(float $v, int $desimal = 1): string
{
    return number_format($v, $desimal, ',', '.');
}

function titikKunci(array $p): string
{
    return sprintf('%.3f,%.3f', $p[0], $p[1]);
}

function deretKunci(array $pts): string
{
    $maju = '';
    foreach ($pts as $p) {
        $maju .= titikKunci($p) . ';';
    }
    $balik = '';
    for ($i = count($pts) - 1; $i >= 0; $i--) {
        $balik .= titikKunci($pts[$i]) . ';';
    }
    return $maju < $balik ? $maju : $balik;
}

function panjangBaris(array $pts): float
{
    $L = 0.0;
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        $L += hypot($pts[$i][0] - $pts[$i - 1][0], $pts[$i][1] - $pts[$i - 1][1]);
    }
    return $L;
}

function arahUjung(array $pts, bool $ujung, int $jendela = 4): array
{
    $n = count($pts);
    if ($n < 2) {
        return [0.0, 0.0];
    }
    if ($ujung) {
        $a = $pts[$n - 1];
        $b = $pts[max(0, $n - $jendela)];
    } else {
        $a = $pts[0];
        $b = $pts[min($n - 1, $jendela - 1)];
    }
    $dx = $a[0] - $b[0];
    $dy = $a[1] - $b[1];
    $L = hypot($dx, $dy);
    return $L > 0 ? [$dx / $L, $dy / $L] : [0.0, 0.0];
}

function sudutArah(array $u, array $v): float
{
    $d = max(-1.0, min(1.0, $u[0] * $v[0] + $u[1] * $v[1]));
    return rad2deg(acos($d));
}

function berbaling(array $a, array $b, array $c): float
{
    return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
}

function silangRuas(array $a, array $b, array $c, array $d, float $eps = 1e-9): bool
{
    $d1 = berbaling($c, $d, $a);
    $d2 = berbaling($c, $d, $b);
    $d3 = berbaling($a, $b, $c);
    $d4 = berbaling($a, $b, $d);
    if ((($d1 > $eps && $d2 < -$eps) || ($d1 < -$eps && $d2 > $eps))
        && (($d3 > $eps && $d4 < -$eps) || ($d3 < -$eps && $d4 > $eps))) {
        return true;
    }
    return false;
}

function silangDiri(array $pts): bool
{
    $n = count($pts);
    if ($n < 4) {
        return false;
    }
    for ($i = 0; $i < $n - 1; $i++) {
        for ($j = $i + 2; $j < $n - 1; $j++) {
            if ($i === 0 && $j === $n - 2) {
                continue;
            }
            if (silangRuas($pts[$i], $pts[$i + 1], $pts[$j], $pts[$j + 1])) {
                return true;
            }
        }
    }
    return false;
}

function jembatanMemotong(array $a, array $b): bool
{
    // Ruas dari ujung a ke pangkal b tidak boleh memotong rantai a atau b selain di ujungnya.
    $pa = $a[count($a) - 1];
    $pb = $b[0];
    for ($i = 0; $i < count($a) - 2; $i++) {
        if (silangRuas($pa, $pb, $a[$i], $a[$i + 1])) {
            return true;
        }
    }
    for ($i = 1; $i < count($b) - 1; $i++) {
        if (silangRuas($pa, $pb, $b[$i], $b[$i + 1])) {
            return true;
        }
    }
    return false;
}

function cincin(array $pts, float $eps = 1e-6): bool
{
    $n = count($pts);
    return $n > 2 && hypot($pts[0][0] - $pts[$n - 1][0], $pts[0][1] - $pts[$n - 1][1]) <= $eps;
}

// ---------------------------------------------------------------------------
// Baca bahan
// ---------------------------------------------------------------------------

$berkas = DIR_LAPISAN . '/' . BERKAS . '.geojson';
$fc = is_file($berkas) ? json_decode((string) file_get_contents($berkas), true) : null;
if (!is_array($fc) || !isset($fc['features']) || !is_array($fc['features'])) {
    fwrite(STDERR, "galat: berkas bahan tidak dapat dibaca\n");
    exit(1);
}

$rows = [];
$verteksGanda = 0;
foreach ($fc['features'] as $ft) {
    $g = $ft['geometry'] ?? [];
    $p = (array) ($ft['properties'] ?? []);
    if (($g['type'] ?? '') === 'LineString') {
        $bagian = [$g['coordinates']];
    } elseif (($g['type'] ?? '') === 'MultiLineString') {
        $bagian = $g['coordinates'];
    } else {
        continue;
    }
    foreach ($bagian as $co) {
        $pts = [];
        foreach ($co as $c) {
            $pts[] = [(float) $c[0], (float) $c[1]];
        }
        if (count($pts) < 2) {
            continue;
        }
        // Verteks kembar berurutan dibuang agar panjang dan segmen konsisten.
        $rapi = [$pts[0]];
        for ($i = 1, $n = count($pts); $i < $n; $i++) {
            $q = $rapi[count($rapi) - 1];
            if (hypot($pts[$i][0] - $q[0], $pts[$i][1] - $q[1]) > 1e-9) {
                $rapi[] = $pts[$i];
            }
        }
        if (count($rapi) < 2) {
            continue;
        }
        $verteksGanda += count($pts) - count($rapi);
        $p['panjang_m'] = round(panjangBaris($rapi), 2);
        $p['titik'] = count($rapi);
        $p['segmen'] = count($rapi) - 1;
        $rows[] = [
            'no' => count($rows),
            'cara' => (string) ($p['cara'] ?? ''),
            'saluran' => (string) ($p['saluran'] ?? ''),
            'sisi' => (string) ($p['sisi'] ?? ''),
            'pts' => $rapi,
            'props' => $p,
        ];
    }
}
if ($rows === []) {
    fwrite(STDERR, "galat: tidak ada baris garis\n");
    exit(1);
}

$panjangAwal = 0.0;
$titikAwal = 0;
$cacahCara = [];
$titikBahan = [];
foreach ($rows as $r) {
    $panjangAwal += panjangBaris($r['pts']);
    $titikAwal += count($r['pts']);
    $cacahCara[$r['cara']] = ($cacahCara[$r['cara']] ?? 0) + 1;
    foreach ($r['pts'] as $q) {
        $titikBahan[titikKunci($q)] = true;
    }
}

// ---------------------------------------------------------------------------
// 1. Buang tebing kembar (deret titik sama, maju atau balik)
// ---------------------------------------------------------------------------

$kembarDibuang = 0;
$kembarPanjang = 0.0;
$kembarPerCara = [];
$unik = [];
$kunciAda = [];
foreach ($rows as $r) {
    $k = deretKunci($r['pts']);
    if (isset($kunciAda[$k])) {
        $kembarDibuang++;
        $kembarPanjang += panjangBaris($r['pts']);
        $kembarPerCara[$r['cara']] = ($kembarPerCara[$r['cara']] ?? 0) + 1;
        continue;
    }
    $kunciAda[$k] = true;
    $unik[] = $r;
}
$panjangUnik = 0.0;
foreach ($unik as $r) {
    $panjangUnik += panjangBaris($r['pts']);
}

// ---------------------------------------------------------------------------
// 2. Jahit potongan pasangan per saluran + sisi
// ---------------------------------------------------------------------------

$grup = [];
foreach ($unik as $i => $r) {
    if ($r['cara'] !== 'pasangan') {
        continue;
    }
    $grup[$r['saluran'] . '|' . $r['sisi']][] = $i;
}

$rantai = [];
foreach ($unik as $i => $r) {
    $rantai[$i] = [
        'induk' => $i,
        'pts' => $r['pts'],
        'src' => [$i],
        'pasangan' => $r['cara'] === 'pasangan',
        'gabung' => 0,
        'jembatan' => 0,
        'jembatan_m' => 0.0,
    ];
}
$hidup = [];
foreach ($rantai as $i => $r) {
    $hidup[$i] = true;
}

$sambunganKembar = 0;
$sambunganCelah = 0;
$jembatanJumlah = 0;
$jembatanPanjang = 0.0;
$tolakCincin = 0;
$tolakPotong = 0;
$tolakSilang = 0;

$cobaJahit = static function (int $ia, int $ib, float $jarak, bool $cekArah) use (
    &$rantai, &$hidup, $belok
): ?array {
    if (!$hidup[$ia] || !$hidup[$ib] || $ia === $ib) {
        return null;
    }
    $A0 = $rantai[$ia]['pts'];
    $B0 = $rantai[$ib]['pts'];
    if (cincin($A0) || cincin($B0)) {
        return null;
    }
    $ea = $jarak <= 0.05 ? 0.05 : $jarak;
    $kombi = [[true, false], [true, true], [false, false], [false, true]];
    foreach ($kombi as [$ua, $ub]) {
        $pa = $ua ? $A0[count($A0) - 1] : $A0[0];
        $pb = $ub ? $B0[count($B0) - 1] : $B0[0];
        if (hypot($pa[0] - $pb[0], $pa[1] - $pb[1]) > $ea + 1e-9) {
            continue;
        }
        $A = $ua ? $A0 : array_reverse($A0);
        $B = $ub ? array_reverse($B0) : $B0;
        if ($cekArah) {
            $sa = arahUjung($A, true);
            $sb = arahUjung($B, false);
            if (sudutArah($sa, $sb) > $belok) {
                continue;
            }
        }
        $d = hypot($A[count($A) - 1][0] - $B[0][0], $A[count($A) - 1][1] - $B[0][1]);
        $gab = $A;
        if ($d <= 1e-6) {
            for ($i = 1, $n = count($B); $i < $n; $i++) {
                $gab[] = $B[$i];
            }
        } else {
            if (jembatanMemotong($A, $B)) {
                return ['tolak' => 'potong'];
            }
            foreach ($B as $q) {
                $gab[] = $q;
            }
        }
        if (cincin($gab)) {
            return ['tolak' => 'cincin'];
        }
        if (silangDiri($gab)) {
            return ['tolak' => 'silang'];
        }
        return ['gab' => $gab, 'd' => $d];
    }
    return null;
};

foreach ($grup as $anggota) {
    $aktif = [];
    foreach ($anggota as $i) {
        $aktif[$i] = true;
    }
    // Ujung berimpit dahulu, tanpa syarat arah.
    $berubah = true;
    while ($berubah) {
        $berubah = false;
        $ids = array_keys($aktif);
        $n = count($ids);
        for ($a = 0; $a < $n && !$berubah; $a++) {
            for ($b = $a + 1; $b < $n && !$berubah; $b++) {
                $ia = $ids[$a];
                $ib = $ids[$b];
                $R = $cobaJahit($ia, $ib, 0.05, false);
                if ($R === null) {
                    continue;
                }
                if (isset($R['tolak'])) {
                    if ($R['tolak'] === 'cincin') {
                        $tolakCincin++;
                    } elseif ($R['tolak'] === 'silang') {
                        $tolakSilang++;
                    } else {
                        $tolakPotong++;
                    }
                    continue;
                }
                $rantai[$ia]['pts'] = $R['gab'];
                $rantai[$ia]['src'] = array_merge($rantai[$ia]['src'], $rantai[$ib]['src']);
                $rantai[$ia]['gabung'] += $rantai[$ib]['gabung'] + 1;
                $hidup[$ib] = false;
                unset($aktif[$ib]);
                $sambunganKembar++;
                $berubah = true;
            }
        }
    }
    // Lalu celah kecil yang arahnya menerus.
    if ($celah > 0.05) {
        $berubah = true;
        while ($berubah) {
            $berubah = false;
            $ids = array_keys($aktif);
            $n = count($ids);
            for ($a = 0; $a < $n && !$berubah; $a++) {
                for ($b = $a + 1; $b < $n && !$berubah; $b++) {
                    $ia = $ids[$a];
                    $ib = $ids[$b];
                    $R = $cobaJahit($ia, $ib, $celah, true);
                    if ($R === null) {
                        continue;
                    }
                    if (isset($R['tolak'])) {
                        if ($R['tolak'] === 'cincin') {
                            $tolakCincin++;
                        } elseif ($R['tolak'] === 'silang') {
                            $tolakSilang++;
                        } else {
                            $tolakPotong++;
                        }
                        continue;
                    }
                    if ($R['d'] <= 1e-6) {
                        continue;
                    }
                    $rantai[$ia]['pts'] = $R['gab'];
                    $rantai[$ia]['src'] = array_merge($rantai[$ia]['src'], $rantai[$ib]['src']);
                    $rantai[$ia]['gabung'] += $rantai[$ib]['gabung'] + 1;
                    $rantai[$ia]['jembatan'] += $rantai[$ib]['jembatan'] + 1;
                    $rantai[$ia]['jembatan_m'] += $rantai[$ib]['jembatan_m'] + $R['d'];
                    $hidup[$ib] = false;
                    unset($aktif[$ib]);
                    $sambunganCelah++;
                    $jembatanJumlah++;
                    $jembatanPanjang += $R['d'];
                    $berubah = true;
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 3. Hasil: susun ulang urutan, gabungkan properti, nomori ulang kode
// ---------------------------------------------------------------------------

$hasil = [];
$panjangHasil = 0.0;
$panjangAcuanHasil = 0.0;
$titikAcuanHasil = 0;
$titikHasil = 0;
$cacahHasil = ['acuan' => 0, 'pasangan' => 0];
$perSisiHasil = ['kiri' => [0, 0.0], 'kanan' => [0, 0.0]];
$jarakRata = [];
$ringHasil = 0;
$silangHasil = 0;
$kunciHasil = [];
$geometriKembar = 0;
$saluranBerpasangan = [];
$lebarPasangan = [];

foreach ($unik as $i => $r) {
    if (!$hidup[$i]) {
        continue;
    }
    $R = $rantai[$i];
    $pts = $R['pts'];
    $pn = panjangBaris($pts);
    $panjangHasil += $pn;
    $titikHasil += count($pts);
    $k = deretKunci($pts);
    if (isset($kunciHasil[$k])) {
        $geometriKembar++;
    }
    $kunciHasil[$k] = true;
    if (cincin($pts)) {
        $ringHasil++;
    }
    if (silangDiri($pts)) {
        $silangHasil++;
    }

    $src = [];
    foreach ($R['src'] as $j) {
        $src[] = $unik[$j];
    }
    $pertama = $src[0];

    if ($pertama['cara'] === 'acuan') {
        $props = $pertama['props'];
        $props['panjang_m'] = round($pn, 2);
        $props['titik'] = count($pts);
        $props['segmen'] = count($pts) - 1;
        $cacahHasil['acuan']++;
        $panjangAcuanHasil += $pn;
        $titikAcuanHasil += count($pts);
    } else {
        $bobot = 0.0;
        $jumlah = 0.0;
        $min = INF;
        $maks = -INF;
        $bahan = [];
        $kodeSumber = [];
        foreach ($src as $s) {
            $sp = $s['props'];
            $len = (float) ($sp['panjang_m'] ?? 0.0);
            $jumlah += (float) ($sp['jarak_rata_m'] ?? 0.0) * $len;
            $bobot += $len;
            $min = min($min, (float) ($sp['jarak_min_m'] ?? INF));
            $maks = max($maks, (float) ($sp['jarak_maks_m'] ?? -INF));
            if (($sp['bahan'] ?? '') !== '') {
                $bahan[(string) $sp['bahan']] = true;
            }
            $kodeSumber[] = (string) ($s['props']['kode'] ?? '');
        }
        $props = [
            'kode' => '',
            'cara' => 'pasangan',
            'saluran' => $pertama['saluran'],
            'sisi' => $pertama['sisi'],
            'panjang_m' => round($pn, 2),
            'jarak_rata_m' => $bobot > 0 ? round($jumlah / $bobot, 2) : 0.0,
            'jarak_min_m' => is_finite($min) ? round($min, 2) : 0.0,
            'jarak_maks_m' => is_finite($maks) ? round($maks, 2) : 0.0,
            'bahan' => implode(',', array_keys($bahan)),
            'titik' => count($pts),
            'segmen' => count($pts) - 1,
            'jumlah_bagian' => count($src),
            'sumber_kode' => implode(',', $kodeSumber),
        ];
        if ($R['jembatan'] > 0) {
            $props['jembatan_celah'] = $R['jembatan'];
        }
        $cacahHasil['pasangan']++;
        $sisi = $pertama['sisi'];
        if (!isset($perSisiHasil[$sisi])) {
            $perSisiHasil[$sisi] = [0, 0.0];
        }
        $perSisiHasil[$sisi][0]++;
        $perSisiHasil[$sisi][1] += $pn;
        $saluranBerpasangan[$pertama['saluran']] = true;
        $lebarPasangan[] = (float) $props['jarak_rata_m'];
    }

    $hasil[] = [
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => $pts],
        'properties' => $props,
    ];
}

foreach ($hasil as $i => $f) {
    $hasil[$i]['properties']['kode'] = sprintf('TEPI-SALURAN-%04d', $i + 1);
}
sort($lebarPasangan);
$lebarMedian = $lebarPasangan === [] ? 0.0 : $lebarPasangan[(int) floor(count($lebarPasangan) / 2)];

// ---------------------------------------------------------------------------
// 4. Verifikasi
// ---------------------------------------------------------------------------

$galat = [];
if ($geometriKembar > 0) {
    $galat[] = "geometri kembar tersisa $geometriKembar";
}
if ($silangHasil > 0) {
    $galat[] = "silang diri $silangHasil baris";
}
$ringBahan = 0;
foreach ($unik as $r) {
    if (cincin($r['pts'])) {
        $ringBahan++;
    }
}
if ($ringHasil !== $ringBahan) {
    $galat[] = "cincin berubah: bahan $ringBahan, hasil $ringHasil";
}
$titikAsing = 0;
foreach ($hasil as $f) {
    foreach ($f['geometry']['coordinates'] as $q) {
        if (!isset($titikBahan[titikKunci($q)])) {
            $titikAsing++;
        }
    }
}
if ($titikAsing > 0) {
    $galat[] = "titik di luar bahan $titikAsing";
}
$selisih = abs($panjangHasil - ($panjangUnik + $jembatanPanjang));
if ($selisih > 0.01) {
    $galat[] = 'panjang tidak seimbang: hasil ' . angkaNum($panjangHasil, 2)
        . ' m vs bahan unik + jembatan ' . angkaNum($panjangUnik + $jembatanPanjang, 2) . ' m';
}

printf("bahan              : %d baris, %s m (acuan %d + pasangan %d)\n",
    count($rows), angkaNum($panjangAwal, 1), $cacahCara['acuan'] ?? 0, $cacahCara['pasangan'] ?? 0);
printf("kembar dibuang     : %d baris, %s m (acuan %d + pasangan %d) · verteks ganda %d\n",
    $kembarDibuang, angkaNum($kembarPanjang, 1),
    $kembarPerCara['acuan'] ?? 0, $kembarPerCara['pasangan'] ?? 0, $verteksGanda);
printf("sambungan          : %d ujung berimpit + %d jembatan celah (%s m) · ditolak: cincin %d · silang %d · potong %d\n",
    $sambunganKembar, $jembatanJumlah, angkaNum($jembatanPanjang, 1),
    $tolakCincin, $tolakSilang, $tolakPotong);
printf("hasil              : %d baris, %s m (acuan %d + pasangan %d) · %d titik\n",
    count($hasil), angkaNum($panjangHasil, 1), $cacahHasil['acuan'], $cacahHasil['pasangan'], $titikHasil);
printf("pasangan per sisi  : kiri %d baris/%s m · kanan %d baris/%s m · lebar median %s m\n",
    $perSisiHasil['kiri'][0], angkaNum($perSisiHasil['kiri'][1], 1),
    $perSisiHasil['kanan'][0], angkaNum($perSisiHasil['kanan'][1], 1), angkaNum($lebarMedian, 2));
printf("saluran bertebing lawan: %d · rapi: cincin %d, silang diri %d, kembar %d, titik asing %d\n",
    count($saluranBerpasangan), $ringHasil, $silangHasil, $geometriKembar, $titikAsing);

if ($rinci) {
    foreach ($hasil as $f) {
        $pr = $f['properties'];
        if ($pr['cara'] === 'acuan') {
            printf("%s  %-22s acuan    %7s m · %3d titik\n", $pr['kode'], $pr['saluran'],
                angkaNum((float) $pr['panjang_m'], 1), $pr['titik']);
            continue;
        }
        printf("%s  %-22s %-6s %7s m · %3d titik · %d bagian · jarak %s m\n", $pr['kode'],
            $pr['saluran'], $pr['sisi'], angkaNum((float) $pr['panjang_m'], 1),
            $pr['titik'], $pr['jumlah_bagian'], angkaNum((float) $pr['jarak_rata_m'], 2));
    }
}

if ($galat !== []) {
    fwrite(STDERR, "galat: " . implode(' · ', $galat) . "; berkas tidak ditulis\n");
    exit(1);
}

if (!$terapkan) {
    echo "uji kering: tidak ada berkas yang ditulis. Tambahkan --terapkan untuk menulis.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// 5. Tulis berkas dan metadata
// ---------------------------------------------------------------------------

$stempel = date('Ymd-His');
$cadang = __DIR__ . '/../_lama/cadangan-tepi-saluran-rapi-' . $stempel;
if (is_file($berkas)) {
    if (!is_dir($cadang) && !mkdir($cadang, 0775, true) && !is_dir($cadang)) {
        fwrite(STDERR, "galat: gagal membuat folder cadangan\n");
        exit(1);
    }
    if (!copy($berkas, $cadang . '/' . basename($berkas))) {
        fwrite(STDERR, "galat: gagal mencadangkan berkas lama\n");
        exit(1);
    }
    echo 'cadangan berkas lama: _lama/' . basename($cadang) . "\n";
}

$naskah = json_encode(['type' => 'FeatureCollection', 'features' => $hasil],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($naskah === false || file_put_contents($berkas, $naskah) === false) {
    fwrite(STDERR, "galat: gagal menulis berkas\n");
    exit(1);
}

$berkasMeta = DIR_LAPISAN . '/metadata.json';
$meta = is_file($berkasMeta) ? (array) json_decode((string) file_get_contents($berkasMeta), true) : [];
if ($meta === [] || !isset($meta['layers'][JUDUL])) {
    fwrite(STDERR, "galat: metadata.json tidak memuat " . JUDUL . "\n");
    exit(1);
}
if (!is_dir($cadang) && !mkdir($cadang, 0775, true) && !is_dir($cadang)) {
    fwrite(STDERR, "galat: gagal membuat folder cadangan metadata\n");
    exit(1);
}
copy($berkasMeta, $cadang . '/metadata-sebelum-' . $stempel . '.json');

$lama = $meta['layers'][JUDUL];
$lama['features'] = count($hasil);
$lama['types'] = ['line' => count($hasil)];
$lama['file'] = BERKAS . '.geojson';
$lama['panjang_m'] = (int) round($panjangHasil);
$lama['panjang_acuan_m'] = round($panjangAcuanHasil, 1);
$lama['panjang_pasangan_m'] = round($panjangHasil - $panjangAcuanHasil, 1);
if (isset($lama['acuan'])) {
    $lama['acuan']['baris'] = $cacahHasil['acuan'];
    $lama['acuan']['panjang_m'] = $lama['panjang_acuan_m'];
    $lama['acuan']['berpasangan'] = count($saluranBerpasangan);
    $lama['acuan']['satu_tebing'] = $cacahHasil['acuan'] - count($saluranBerpasangan);
}
if (isset($lama['pasangan'])) {
    $lama['pasangan']['baris'] = $cacahHasil['pasangan'];
    $lama['pasangan']['panjang_m'] = $lama['panjang_pasangan_m'];
    $lama['pasangan']['titik'] = $titikHasil - $titikAcuanHasil;
    $lama['pasangan']['kiri'] = $perSisiHasil['kiri'][0];
    $lama['pasangan']['kanan'] = $perSisiHasil['kanan'][0];
    $lama['pasangan']['lebar_median_m'] = round($lebarMedian, 2);
    $lama['pasangan']['pasangan_per_baris'] = count($saluranBerpasangan) > 0
        ? round($cacahHasil['pasangan'] / count($saluranBerpasangan), 2) : 0;
}
$lama['dirapikan'] = [
    'aturan' => 'tebing kembar (deret titik sama) dibuang; potongan pasangan pada saluran+sisi '
        . 'yang sama dijahit bila ujungnya berimpit atau celahnya <= celah_m dan arahnya menerus; '
        . 'jembatan memakai ujung bahan, tanpa koordinat karangan',
    'toleransi_m' => $toleransi,
    'celah_m' => $celah,
    'belok_derajat' => $belok,
    'baris_sebelum' => count($rows),
    'panjang_sebelum_m' => round($panjangAwal, 1),
    'kembar_dibuang' => $kembarDibuang,
    'kembar_dibuang_m' => round($kembarPanjang, 1),
    'verteks_ganda_dibuang' => $verteksGanda,
    'sambungan_ujung' => $sambunganKembar,
    'jembatan_celah' => $jembatanJumlah,
    'jembatan_m' => round($jembatanPanjang, 1),
    'baris_sesudah' => count($hasil),
    'panjang_sesudah_m' => round($panjangHasil, 1),
    'diterapkan' => $stempel,
];
$meta['layers'][JUDUL] = $lama;
$meta['derived_at'] = date('c');
if (file_put_contents($berkasMeta, json_encode($meta, JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) {
    fwrite(STDERR, "galat: gagal menulis metadata.json\n");
    exit(1);
}
echo 'ditulis: files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . BERKAS . ".geojson\n";
echo "metadata.json diperbarui: " . JUDUL . "\n";
echo 'cadangan: _lama/' . basename($cadang) . "\n";
