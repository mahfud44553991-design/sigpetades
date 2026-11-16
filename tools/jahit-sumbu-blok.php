<?php
declare(strict_types=1);

/**
 * Jahit semua sumbu jalan satu blok jadi polilin utuh.
 *
 *   php -d memory_limit=1024M tools/jahit-sumbu-blok.php --blok=2
 *   php -d memory_limit=1024M tools/jahit-sumbu-blok.php --blok=2 --terapkan
 *
 *   --blok=N                   nomor blok (nama tabel blok "BLOK N"), wajib
 *   --terapkan                 tulis lapis keluaran
 *   --rinci                    cetak semua polilin hasil
 *   --celah=M                   jembatani ujung polilin yang berhadapan searah
 *                              sampai M meter (bawaan 0 = tidak dijembatani)
 *   --keluaran=BERKAS          nama berkas (tanpa .geojson), bawaan
 *                              garis-sumbu-jahit-blok-N
 *
 * Bahan: garis-jalan-sumbu.geojson (1.495 baris, koordinat meter DXF lokal).
 * Tiap baris dimiliki satu blok lewat suara terbanyak verteksnya: verteks
 * diberi blok persil terdekat (<= 10 m), lalu blok yang paling sering menang.
 * Seri dimenangkan blok di verteks tengah.
 *
 * Aturan jahit, sama dengan tools/jahit-tepi-bidang.php:
 *
 *  1. SIMPUL. Verteks yang berjarak <= 0,35 m dihitung satu simpul. Tidak ada
 *     koordinat yang digeser; polilin gabungan memakai titik simpul milik ruas
 *     pertama.
 *  2. SAMBUNGAN. Di simpul derajat 2 kedua ruas selalu disambung. Di simpul
 *     bercabang dua ruas dipasangkan bila belokan lanjutannya <= 40 derajat;
 *     pasangan paling lurus didahulukan dan satu ruas hanya dipakai sekali per
 *     simpul, jadi cabang lain tetap terpisah.
 *  3. KEMBAR. Ruas yang simpulnya sama dan titik tengahnya berimpit (<= 0,5 m)
 *     hanya disimpan sekali.
 *  4. CELAH (bila --celah=M). Dua ujung derajat 1 yang berhadapan sampai M
 *     meter disambung bila arah sambungannya menerus di kedua ujung (<= 40
 *     derajat). Jembatan memakai dua ujung itu sebagai titik, jadi tetap tidak
 *     ada koordinat baru.
 *
 * Verifikasi bawaan: setiap titik keluaran harus pernah menjadi titik bahan,
 * dan panjang polilin = jumlah panjang ruas (selisih hanya dari penutupan
 * cincin).
 */

require_once __DIR__ . '/lib-impor.php';

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_MASUK = 'garis-jalan-sumbu';
const JUDUL_AWAL = 'GARIS SUMBU JAHIT BLOK ';

const SIMPUL_M = 0.35;
const BELOK_MAKS = 40.0;
const KEMBAR_JARAK = 0.5;
const SEL_BIDANG = 10.0;
const SEL_SIMPUL = 1.0;

function galat(string $pesan): never
{
    fwrite(STDERR, 'galat: ' . $pesan . PHP_EOL);
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

function proyeksi(float $px, float $py, float $ax, float $ay, float $bx, float $by): array
{
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $p2 = $dx * $dx + $dy * $dy;
    if ($p2 <= 0.0) {
        return [jarak($px, $py, $ax, $ay), 0.0];
    }
    $t = max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $p2));

    return [hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy)), $t];
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

function cincinDariGeojson(array $geometri): array
{
    $bagian = match ((string) ($geometri['type'] ?? '')) {
        'MultiPolygon' => (array) ($geometri['coordinates'] ?? []),
        'Polygon' => [(array) ($geometri['coordinates'] ?? [])],
        default => [],
    };
    $keluar = [];
    foreach ($bagian as $pol) {
        $ring = array_map(
            static fn(array $p): array => wgs84KeDxf((float) $p[0], (float) $p[1]),
            (array) ($pol[0] ?? [])
        );
        if (count($ring) >= 4) {
            $keluar[] = $ring;
        }
    }

    return $keluar;
}

$nomorBlok = null;
$berkasKeluar = null;
$celahMaks = 0.0;
$terapkan = false;
$rinci = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--terapkan') {
        $terapkan = true;
    } elseif ($arg === '--rinci') {
        $rinci = true;
    } elseif (str_starts_with($arg, '--celah=')) {
        $isi = trim(substr($arg, 8));
        if (!preg_match('/^\d{1,2}([.,]\d{1,2})?$/', $isi)) {
            galat('--celah harus angka meter, contoh --celah=3');
        }
        $celahMaks = (float) str_replace(',', '.', $isi);
    } elseif (str_starts_with($arg, '--blok=')) {
        $isi = trim(substr($arg, 7));
        if (!preg_match('/^\d{1,3}$/', $isi)) {
            galat('--blok harus nomor blok, contoh --blok=2');
        }
        $nomorBlok = (int) $isi;
    } elseif (str_starts_with($arg, '--keluaran=')) {
        $berkasKeluar = trim(substr($arg, 11));
        if ($berkasKeluar === '' || !preg_match('/^[a-z0-9-]+$/', $berkasKeluar)) {
            galat('--keluaran harus huruf kecil, angka, dan tanda hubung');
        }
    } else {
        galat('argumen tidak dikenal: ' . $arg);
    }
}
if ($nomorBlok === null) {
    galat('--blok wajib diisi');
}
$berkasKeluar ??= 'garis-sumbu-jahit-blok-' . $nomorBlok;

// ---------------------------------------------------------------------------
// Bahan
// ---------------------------------------------------------------------------

$sumbu = geojsonBaca(DIR_LAPISAN . '/' . BERKAS_MASUK . '.geojson');
if ($sumbu === []) {
    galat('bahan sumbu kosong: ' . BERKAS_MASUK . '.geojson');
}

$idBlok = 0;
$namaBlok = [];
foreach (db_all('SELECT id, nama FROM blok ORDER BY id') as $b) {
    $namaBlok[(int) $b['id']] = (string) $b['nama'];
    if ($b['nama'] === 'BLOK ' . $nomorBlok) {
        $idBlok = (int) $b['id'];
    }
}
if ($idBlok === 0) {
    galat('tidak ada blok bernama BLOK ' . $nomorBlok);
}

$kisiBidang = [];
$segBidang = [];
foreach (db_query("SELECT blok_id, ST_AsGeoJSON(geom) AS geojson FROM bidang
    WHERE status <> 'dihapus' AND geom IS NOT NULL")->fetchAll() as $baris) {
    $geo = json_decode((string) $baris['geojson'], true);
    if (!is_array($geo)) {
        continue;
    }
    $blokId = (int) ($baris['blok_id'] ?? 0);
    foreach (cincinDariGeojson($geo) as $ring) {
        for ($i = 1, $n = count($ring); $i < $n; $i++) {
            [$ax, $ay] = $ring[$i - 1];
            [$bx, $by] = $ring[$i];
            $id = count($segBidang);
            $segBidang[$id] = [(float) $ax, (float) $ay, (float) $bx, (float) $by, $blokId];
            $gx0 = (int) floor(min($ax, $bx) / SEL_BIDANG);
            $gx1 = (int) floor(max($ax, $bx) / SEL_BIDANG);
            $gy0 = (int) floor(min($ay, $by) / SEL_BIDANG);
            $gy1 = (int) floor(max($ay, $by) / SEL_BIDANG);
            for ($gx = $gx0; $gx <= $gx1; $gx++) {
                for ($gy = $gy0; $gy <= $gy1; $gy++) {
                    $kisiBidang[$gx . ':' . $gy][] = $id;
                }
            }
        }
    }
}
if ($segBidang === []) {
    galat('tidak ada segmen persil hidup');
}

/** Blok persil terdekat satu titik; 0 bila tidak ada dalam 10 m. */
$blokTitik = static function (float $x, float $y) use ($kisiBidang, $segBidang): int
{
    $gx = (int) floor($x / SEL_BIDANG);
    $gy = (int) floor($y / SEL_BIDANG);
    $terbaik = INF;
    $blok = 0;
    for ($cx = $gx - 1; $cx <= $gx + 1; $cx++) {
        for ($cy = $gy - 1; $cy <= $gy + 1; $cy++) {
            foreach ($kisiBidang[$cx . ':' . $cy] ?? [] as $id) {
                [$d] = proyeksi($x, $y, $segBidang[$id][0], $segBidang[$id][1], $segBidang[$id][2],
                    $segBidang[$id][3]);
                if ($d < $terbaik) {
                    $terbaik = $d;
                    $blok = $segBidang[$id][4];
                }
            }
        }
    }

    return $terbaik <= SEL_BIDANG ? $blok : 0;
};

// ---------------------------------------------------------------------------
// 1. Blok tiap baris sumbu: suara terbanyak verteks
// ---------------------------------------------------------------------------

$suaraBlok = [];
foreach ($sumbu as $i => $f) {
    $g = $f['geometry'] ?? [];
    if (($g['type'] ?? '') !== 'LineString') {
        continue;
    }
    $koor = $g['coordinates'];
    $n = count($koor);
    if ($n < 2) {
        continue;
    }
    $suara = [];
    $jumlahSuara = 0;
    foreach ($koor as $p) {
        $b = $blokTitik((float) $p[0], (float) $p[1]);
        if ($b === 0) {
            continue;
        }
        $suara[$b] = ($suara[$b] ?? 0) + 1;
        $jumlahSuara++;
    }
    if ($suara === []) {
        continue;
    }
    $terbanyak = max($suara);
    $tengah = $koor[intdiv($n, 2)];
    $blokTengah = $blokTitik((float) $tengah[0], (float) $tengah[1]);
    $seri = array_keys($suara, $terbanyak, true);
    $terbaik = in_array($blokTengah, $seri, true) ? $blokTengah : $seri[0];
    $suaraBlok[$i] = ['blok' => $terbaik, 'suara' => $terbanyak, 'jumlah' => $jumlahSuara,
        'tengah' => $blokTengah];
}

$perBlok = [];
$panjangPerBlok = [];
foreach ($suaraBlok as $i => $d) {
    $g = $sumbu[$i]['geometry']['coordinates'];
    $panjang = 0.0;
    for ($k = 1, $n = count($g); $k < $n; $k++) {
        $panjang += jarak((float) $g[$k - 1][0], (float) $g[$k - 1][1], (float) $g[$k][0],
            (float) $g[$k][1]);
    }
    $perBlok[$d['blok']] = ($perBlok[$d['blok']] ?? 0) + 1;
    $panjangPerBlok[$d['blok']] = ($panjangPerBlok[$d['blok']] ?? 0.0) + $panjang;
}
ksort($perBlok);
printf("sumbu: %d baris · blok %d\n", count($suaraBlok), count($perBlok));
foreach ($perBlok as $b => $jml) {
    printf("  %-10s %3d baris · %8s m%s\n", $namaBlok[$b] ?? ('?' . $b), $jml,
        angkaNum($panjangPerBlok[$b], 1), $b === $idBlok ? '  <- dipilih' : '');
}

$terpilih = [];
$tanpaBlok = 0;
$kecilSuara = 0;
foreach ($suaraBlok as $i => $d) {
    if ($d['blok'] !== $idBlok) {
        continue;
    }
    if ($d['tengah'] !== $idBlok) {
        $tanpaBlok++;
    }
    if ($d['jumlah'] > 0 && $d['suara'] / $d['jumlah'] < 0.7) {
        $kecilSuara++;
    }
    $terpilih[] = $i;
}
if ($terpilih === []) {
    galat('tidak ada sumbu berblok BLOK ' . $nomorBlok);
}
printf("dipilih: %d baris (%d dengan blok terbanyak tapi titik tengah di blok lain · %d dengan suara < 70 %%)\n",
    count($terpilih), $tanpaBlok, $kecilSuara);

// ---------------------------------------------------------------------------
// 2. Simpul dan ruas
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

$ruas = [];
foreach ($terpilih as $i) {
    $f = $sumbu[$i];
    $g = $f['geometry'] ?? [];
    if (($g['type'] ?? '') !== 'LineString') {
        continue;
    }
    $kode = (string) ($f['properties']['kode'] ?? '?');
    $label = (string) ($f['properties']['label'] ?? '');
    $koor = $g['coordinates'];
    for ($k = 1, $n = count($koor); $k < $n; $k++) {
        $ax = (float) $koor[$k - 1][0];
        $ay = (float) $koor[$k - 1][1];
        $bx = (float) $koor[$k][0];
        $by = (float) $koor[$k][1];
        if (jarak($ax, $ay, $bx, $by) <= 0.0) {
            continue;
        }
        $ia = $simpul($ax, $ay);
        $ib = $simpul($bx, $by);
        if ($ia === $ib) {
            continue;
        }
        $ruas[] = ['a' => $ia, 'b' => $ib, 'pa' => [$ax, $ay], 'pb' => [$bx, $by],
            'kode' => $kode, 'label' => $label];
    }
}
printf("ruas: %d (simpul %d)\n", count($ruas), count($simpulTitik));

$perPasangan = [];
$ruasBersih = [];
$buangPasangan = 0;
foreach ($ruas as $r) {
    $kunci = min($r['a'], $r['b']) . ':' . max($r['a'], $r['b']);
    if (isset($perPasangan[$kunci])) {
        $idx = $perPasangan[$kunci];
        $lain = $ruasBersih[$idx];
        $mx1 = ($r['pa'][0] + $r['pb'][0]) / 2;
        $my1 = ($r['pa'][1] + $r['pb'][1]) / 2;
        $mx2 = ($lain['pa'][0] + $lain['pb'][0]) / 2;
        $my2 = ($lain['pa'][1] + $lain['pb'][1]) / 2;
        if (jarak($mx1, $my1, $mx2, $my2) <= KEMBAR_JARAK) {
            $buangPasangan++;

            continue;
        }
    }
    $perPasangan[$kunci] = count($ruasBersih);
    $ruasBersih[] = $r;
}
$ruas = array_values($ruasBersih);
printf("ruas kembar sesama dibuang: %d · ruas tinggal %d\n", $buangPasangan, count($ruas));

// ---------------------------------------------------------------------------
// 3. Pasangan paling lurus di tiap simpul, lalu telusuri jadi polilin
// ---------------------------------------------------------------------------

$keluar = [];
foreach ($ruas as $id => $r) {
    $keluar[$r['a']][$id] = arah($r['pa'], $r['pb']);
    $keluar[$r['b']][$id] = arah($r['pb'], $r['pa']);
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
printf("sambungan: %d pasangan · simpul bercabang %d\n", $jumlahPasangan, $simpulBercabang);

// ---------------------------------------------------------------------------
// 3b. Jembatan celah: ujung ruas yang tidak dapat pasangan
// ---------------------------------------------------------------------------

$jumlahCelah = 0;
$panjangCelah = 0.0;
if ($celahMaks > 0.0) {
    $bebas = [];
    foreach ($ruas as $id => $r) {
        foreach ([[$r['a'], $r['pa'], $r['pb']], [$r['b'], $r['pb'], $r['pa']]] as [$s, $p, $lain]) {
            if (isset($pasangan[$s][$id])) {
                continue;
            }
            $bebas[] = ['simpul' => $s, 'ruas' => $id, 'p' => $p, 'keluar' => arah($p, $lain)];
        }
    }
    $kandidat = [];
    for ($i = 0, $m = count($bebas); $i < $m; $i++) {
        for ($j = $i + 1; $j < $m; $j++) {
            if ($bebas[$i]['ruas'] === $bebas[$j]['ruas']
                || $bebas[$i]['simpul'] === $bebas[$j]['simpul']) {
                continue;
            }
            $d = jarak($bebas[$i]['p'][0], $bebas[$i]['p'][1], $bebas[$j]['p'][0],
                $bebas[$j]['p'][1]);
            if ($d <= SIMPUL_M || $d > $celahMaks) {
                continue;
            }
            $u = arah($bebas[$i]['p'], $bebas[$j]['p']);
            $masuk = [-$bebas[$i]['keluar'][0], -$bebas[$i]['keluar'][1]];
            if (belokan($masuk, $u) > BELOK_MAKS) {
                continue;
            }
            if (belokan($u, $bebas[$j]['keluar']) > BELOK_MAKS) {
                continue;
            }
            $kandidat[] = [$d, $i, $j];
        }
    }
    usort($kandidat, static fn(array $x, array $y): int => $x[0] <=> $y[0]);
    $dipakaiUjung = [];
    foreach ($kandidat as [$d, $i, $j]) {
        if (isset($dipakaiUjung[$i]) || isset($dipakaiUjung[$j])) {
            continue;
        }
        $dipakaiUjung[$i] = true;
        $dipakaiUjung[$j] = true;
        $idJembatan = count($ruas);
        $ruas[] = ['a' => $bebas[$i]['simpul'], 'b' => $bebas[$j]['simpul'],
            'pa' => $bebas[$i]['p'], 'pb' => $bebas[$j]['p'], 'kode' => 'CELAH', 'label' => ''];
        $pasangan[$bebas[$i]['simpul']][$bebas[$i]['ruas']] = $idJembatan;
        $pasangan[$bebas[$i]['simpul']][$idJembatan] = $bebas[$i]['ruas'];
        $pasangan[$bebas[$j]['simpul']][$bebas[$j]['ruas']] = $idJembatan;
        $pasangan[$bebas[$j]['simpul']][$idJembatan] = $bebas[$j]['ruas'];
        $jumlahCelah++;
        $panjangCelah += $d;
    }
    printf("celah <= %s m: %d jembatan · %s m · %d ujung tanpa pasangan\n",
        angkaNum($celahMaks, 1), $jumlahCelah, angkaNum($panjangCelah, 1), count($bebas));
}

$terpakai = [];
$polilin = [];
foreach ($ruas as $idAwal => $rAwal) {
    if (isset($terpakai[$idAwal])) {
        continue;
    }
    $terpakai[$idAwal] = true;
    $titik = [$rAwal['pa'], $rAwal['pb']];
    $kodeSumbu = [$rAwal['kode'] => true];
    $label = [$rAwal['label'] => true];
    $jumlahRuas = 1;
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
            $ujungBaru = $dariSimpul ? $lanjut['pb'] : $lanjut['pa'];
            $terpakai[$idLanjut] = true;
            $jumlahRuas++;
            $kodeSumbu[$lanjut['kode']] = true;
            $label[$lanjut['label']] = true;
            if ($maju) {
                $titik[] = $ujungBaru;
                $simpulAkhir = $simpulBaru;
            } else {
                array_unshift($titik, $ujungBaru);
                $simpulAwal = $simpulBaru;
            }
            $simpulKini = $simpulBaru;
            $idKini = $idLanjut;
        }
    }

    $tertutup = $simpulAwal === $simpulAkhir && count($titik) > 2;
    if ($tertutup) {
        $titik[count($titik) - 1] = $titik[0];
    }

    $panjang = 0.0;
    for ($i = 1, $n = count($titik); $i < $n; $i++) {
        $panjang += jarak($titik[$i - 1][0], $titik[$i - 1][1], $titik[$i][0], $titik[$i][1]);
    }
    $polilin[] = ['titik' => $titik, 'sumbu' => array_keys($kodeSumbu),
        'label' => array_values(array_filter(array_keys($label), static fn(string $s): bool => $s !== '')),
        'ruas' => $jumlahRuas, 'panjang' => $panjang, 'tertutup' => $tertutup];
}

$panjangKeluaran = array_sum(array_column($polilin, 'panjang'));
$jumlahCincin = count(array_filter($polilin, static fn(array $p): bool => $p['tertutup']));
printf("polilin: %d · %s m · ruas tergabung %d · cincin %d\n", count($polilin),
    angkaNum($panjangKeluaran, 1), array_sum(array_column($polilin, 'ruas')), $jumlahCincin);

// ---------------------------------------------------------------------------
// Verifikasi
// ---------------------------------------------------------------------------

$titikBahan = [];
foreach ($ruas as $r) {
    foreach ([$r['pa'], $r['pb']] as $p) {
        $titikBahan[sprintf('%.3f:%.3f', $p[0], $p[1])] = true;
    }
}
$baru = 0;
foreach ($polilin as $p) {
    foreach ($p['titik'] as $t) {
        if (!isset($titikBahan[sprintf('%.3f:%.3f', $t[0], $t[1])])) {
            $baru++;
        }
    }
}
printf("verifikasi: titik keluaran %d · titik yang tidak ada di bahan %d\n",
    array_sum(array_map(static fn(array $p): int => count($p['titik']), $polilin)), $baru);
if ($baru > 0) {
    galat('ada titik keluaran yang bukan titik bahan');
}

$panjangRuas = 0.0;
foreach ($ruas as $r) {
    $panjangRuas += jarak($r['pa'][0], $r['pa'][1], $r['pb'][0], $r['pb'][1]);
}
printf("verifikasi: panjang ruas %s m · panjang polilin %s m · beda %s m (penutupan cincin)\n",
    angkaNum($panjangRuas, 1), angkaNum($panjangKeluaran, 1),
    angkaNum($panjangKeluaran - $panjangRuas, 1));
if (abs($panjangKeluaran - $panjangRuas) > $jumlahCincin * SIMPUL_M + 0.5) {
    galat('panjang polilin tidak sama dengan jumlah panjang ruas');
}

if ($rinci) {
    $urut = $polilin;
    usort($urut, static fn(array $a, array $b): int => $b['panjang'] <=> $a['panjang']);
    echo "\npolilin hasil:\n";
    foreach ($urut as $i => $p) {
        printf("  %3d. %8s m · %3d ruas · %s%s\n", $i + 1, angkaNum($p['panjang'], 1),
            $p['ruas'], implode(',', $p['sumbu']), $p['tertutup'] ? ' · cincin' : '');
    }
}

// Sisa celah: jarak ujung polilin ke ujung polilin lain.
$ujung = [];
foreach ($polilin as $i => $p) {
    if ($p['tertutup']) {
        continue;
    }
    $ujung[] = [$i, $p['titik'][0]];
    $ujung[] = [$i, $p['titik'][count($p['titik']) - 1]];
}
$pita = ['0,35-1 m' => 0, '1-3 m' => 0, '3-10 m' => 0, '>10 m' => 0];
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
    if ($terdekat <= 1.0) {
        $pita['0,35-1 m']++;
    } elseif ($terdekat <= 3.0) {
        $pita['1-3 m']++;
    } elseif ($terdekat <= 10.0) {
        $pita['3-10 m']++;
    } else {
        $pita['>10 m']++;
    }
}
echo "\nsisa celah antar ujung polilin:";
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
            'kode' => sprintf('JAHIT-SUMBU-%04d', $i + 1),
            'blok' => $namaBlok[$idBlok],
            'blok_id' => $idBlok,
            'sumbu' => $p['sumbu'],
            'label' => $p['label'],
            'jumlah_ruas' => $p['ruas'],
            'jumlah_titik' => count($p['titik']),
            'tertutup' => $p['tertutup'],
            'panjang_m' => round($p['panjang'], 2),
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $p['titik']],
    ];
}
$naskah = json_encode(['type' => 'FeatureCollection', 'features' => $fitur],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

// ---------------------------------------------------------------------------
// Metadata
// ---------------------------------------------------------------------------

$judul = JUDUL_AWAL . $nomorBlok;
$berkasMeta = DIR_LAPISAN . '/metadata.json';
$meta = is_file($berkasMeta) ? (array) json_decode((string) file_get_contents($berkasMeta), true) : [];
if ($meta === []) {
    galat('metadata.json tidak dapat dibaca');
}
$meta['layers'][$judul] = [
    'features' => count($fitur),
    'types' => ['line' => count($fitur)],
    'file' => $berkasKeluar . '.geojson',
    'panjang_m' => (int) round($panjangKeluaran),
    'derived_from' => 'garis-jalan-sumbu.geojson, baris berblok ' . $namaBlok[$idBlok]
        . ' (suara terbanyak verteks), dijahit jadi polilin',
    'ambang' => ['simpul_m' => SIMPUL_M, 'belok_derajat' => BELOK_MAKS,
        'kembar_jarak_m' => KEMBAR_JARAK, 'celah_maks_m' => $celahMaks],
    'penyambungan' => ['sumbu_masuk' => count($terpilih), 'ruas_masuk' => count($ruas),
        'pasangan' => $jumlahPasangan, 'polilin' => count($polilin),
        'kembar_ruas_dibuang' => $buangPasangan, 'celah_dijembatani' => $jumlahCelah,
        'celah_panjang_m' => round($panjangCelah, 1)],
    'derived_at' => date('c'),
];
$meta['notes'][] = sprintf(
    'GARIS SUMBU JAHIT ' . $namaBlok[$idBlok] . ': %d polilin (%s m) dari %d baris sumbu dan %d ruas;'
    . ' simpul %s m, di simpul derajat 2 selalu disambung, di simpul bercabang pasangan paling lurus'
    . ' dengan belokan <= %.0f derajat; %d celah ujung dijahit (<= %s m); tidak ada koordinat yang'
    . ' digeser.',
    count($polilin), angkaNum($panjangKeluaran, 1), count($terpilih), count($ruas),
    angkaNum(SIMPUL_M, 2), BELOK_MAKS, $jumlahCelah, angkaNum($celahMaks, 1)
);
if (file_put_contents($berkasMeta, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
    galat('gagal menulis metadata.json');
}
echo "metadata.json diperbarui.\n";
