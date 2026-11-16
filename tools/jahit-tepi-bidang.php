<?php
declare(strict_types=1);

/**
 * Jahit garis tepi jalan dengan tepi bidang, per blok.
 *
 *   php -d memory_limit=1024M tools/jahit-tepi-bidang.php              # uji kering
 *   php -d memory_limit=1024M tools/jahit-tepi-bidang.php --terapkan   # tulis lapis
 *
 *   --rinci                    cetak 30 polilin terpanjang
 *   --keluaran=BERKAS          nama berkas keluaran (tanpa .geojson), bawaan
 *                              garis-tepi-jalan-bidang
 *
 * Bahan: garis-jalan-tepi.geojson (1.951 baris) dan cincin luar persil tabel
 * `bidang` (status <> 'dihapus'), diturunkan ke meter DXF lokal dengan
 * wgs84KeDxf(). Keluaran satu lapis baru; berkas tepi dan sumbu tidak diubah.
 *
 * Aturan, mengikuti cara tools/rapikan-garis-jalan.php (§3.79):
 *
 *  1. KEMBAR. Segmen tepi bidang yang sudah tergambar sebagai tepi jalan
 *     dibuang: tiap segmen cincin disampel 2 m dan dibuang bila >= 80 % sampel
 *     berjarak <= 0,5 m dari segmen tepi jalan. Tanpa ini lapis hasil memuat
 *     dua garis yang sama (tepi jalan menempel 80,7 % panjangnya pada tepi
 *     bidang), jadi bentangan gandanya tidak terbaca.
 *  2. SIMPUL. Ujung/verteks yang berjarak <= 0,35 m dihitung satu simpul,
 *     sama dengan TOLERANSI_TEPI di §3.79. Tidak ada koordinat yang digeser:
 *     tiap ruas tetap memakai titik aslinya, dan polilin gabungan memakai
 *     titik simpul milik ruas pertama.
 *  3. SAMBUNGAN. Di simpul derajat 2 kedua ruas selalu disambung (sudut apa
 *     pun: itu lanjutan, bukan persimpangan). Di simpul bercabang dua ruas
 *     dipasangkan bila belokan lanjutannya <= 40 derajat; pasangan paling
 *     lurus didahulukan dan satu ruas hanya dipakai sekali per simpul, jadi
 *     cabang lain tetap terpisah. Ujung tepi jalan yang jatuh di tengah segmen
 *     bidang dipotong lebih dulu (POTONG_M) memakai ujung tepi itu sendiri
 *     sebagai titik potong, supaya jalan dan tepi bidang bersimpul sama.
 *  4. BLOK. Tepi jalan diberi blok persil terdekat titik tengahnya; segmen
 *     bidang mewarisi blok persilnya. Penyambungan hanya di dalam satu blok,
 *     jadi tiap hasil bisa dibaca per blok lewat properti `blok`.
 *
 * Verifikasi bawaan: setiap titik keluaran harus pernah menjadi titik bahan
 * (tidak ada koordinat baru), dan panjang keluaran = jumlah panjang ruas yang
 * dipertahankan.
 */

require_once __DIR__ . '/lib-impor.php';

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_MASUK = 'garis-jalan-tepi';
const JUDUL = 'GARIS TEPI JALAN + TEPI BIDANG PER BLOK';

const KEMBAR_JARAK = 0.5;
const KEMBAR_BAGI = 0.8;
const SIMPUL_M = 0.35;
const POTONG_M = 0.10;
const BELOK_MAKS = 40.0;
const LANGKAH = 2.0;
const SEL_TEPI = 5.0;
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

/** Jarak titik ke segmen, sekaligus parameter proyeksi 0..1. */
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

function cincinDariGeojson(array $geometri): array
{
    $tipe = (string) ($geometri['type'] ?? '');
    $bagian = match ($tipe) {
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

$terapkan = false;
$rinci = false;
$berkasKeluar = 'garis-tepi-jalan-bidang';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--terapkan') {
        $terapkan = true;
    } elseif ($arg === '--rinci') {
        $rinci = true;
    } elseif (str_starts_with($arg, '--keluaran=')) {
        $berkasKeluar = trim(substr($arg, 11));
        if ($berkasKeluar === '' || !preg_match('/^[a-z0-9-]+$/', $berkasKeluar)) {
            galat('--keluaran harus huruf kecil, angka, dan tanda hubung');
        }
    } else {
        galat('argumen tidak dikenal: ' . $arg);
    }
}

// ---------------------------------------------------------------------------
// Bahan
// ---------------------------------------------------------------------------

$tepi = geojsonBaca(DIR_LAPISAN . '/' . BERKAS_MASUK . '.geojson');
if ($tepi === []) {
    galat('bahan tepi kosong: ' . BERKAS_MASUK . '.geojson');
}

$namaBlok = [];
foreach (db_all('SELECT id, nama FROM blok ORDER BY id') as $b) {
    $namaBlok[(int) $b['id']] = (string) $b['nama'];
}

$persil = [];
$cincinGagal = 0;
$tanpaCincin = 0;
foreach (db_query("SELECT id, blok_id, ST_AsGeoJSON(geom) AS geojson FROM bidang
    WHERE status <> 'dihapus' AND geom IS NOT NULL ORDER BY id")->fetchAll() as $baris) {
    $geo = json_decode((string) $baris['geojson'], true);
    if (!is_array($geo)) {
        $cincinGagal++;

        continue;
    }
    $blokId = (int) ($baris['blok_id'] ?? 0);
    $cincin = cincinDariGeojson($geo);
    if ($cincin === []) {
        $tanpaCincin++;

        continue;
    }
    foreach ($cincin as $ring) {
        $persil[] = ['id' => (int) $baris['id'], 'blok' => $blokId, 'titik' => $ring];
    }
}
if ($persil === []) {
    galat('tidak ada cincin persil hidup');
}
printf("bahan: tepi %d baris · persil %d cincin (%d tanpa cincin, %d gagal baca) · blok %d\n",
    count($tepi), count($persil), $tanpaCincin, $cincinGagal, count($namaBlok));

// ---------------------------------------------------------------------------
// Kisi segmen tepi (untuk uji kembar) dan segmen bidang (untuk blok tepi)
// ---------------------------------------------------------------------------

$kisiTepi = [];
$segTepi = [];
foreach ($tepi as $f) {
    $g = $f['geometry'] ?? [];
    if (($g['type'] ?? '') !== 'LineString') {
        continue;
    }
    $koordinat = $g['coordinates'];
    $kode = (string) ($f['properties']['kode'] ?? '?');
    for ($i = 1, $n = count($koordinat); $i < $n; $i++) {
        $ax = (float) $koordinat[$i - 1][0];
        $ay = (float) $koordinat[$i - 1][1];
        $bx = (float) $koordinat[$i][0];
        $by = (float) $koordinat[$i][1];
        $id = count($segTepi);
        $segTepi[$id] = [$ax, $ay, $bx, $by, $kode];
        $gx0 = (int) floor(min($ax, $bx) / SEL_TEPI);
        $gx1 = (int) floor(max($ax, $bx) / SEL_TEPI);
        $gy0 = (int) floor(min($ay, $by) / SEL_TEPI);
        $gy1 = (int) floor(max($ay, $by) / SEL_TEPI);
        for ($gx = $gx0; $gx <= $gx1; $gx++) {
            for ($gy = $gy0; $gy <= $gy1; $gy++) {
                $kisiTepi[$gx . ':' . $gy][] = $id;
            }
        }
    }
}
printf("segmen tepi jalan: %d\n", count($segTepi));

$kisiBidang = [];
$segBidang = [];
foreach ($persil as $p) {
    $titik = $p['titik'];
    for ($i = 1, $n = count($titik); $i < $n; $i++) {
        $ax = (float) $titik[$i - 1][0];
        $ay = (float) $titik[$i - 1][1];
        $bx = (float) $titik[$i][0];
        $by = (float) $titik[$i][1];
        $id = count($segBidang);
        $segBidang[$id] = [$ax, $ay, $bx, $by, $p['blok'], $p['id']];
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
printf("segmen tepi bidang: %d\n", count($segBidang));

/** id segmen di sekitar satu titik pada sebuah kisi. */
function sekitar(array $kisi, float $px, float $py, float $sel, int $cincin = 1): array
{
    $gx = (int) floor($px / $sel);
    $gy = (int) floor($py / $sel);
    $keluar = [];
    for ($x = $gx - $cincin; $x <= $gx + $cincin; $x++) {
        for ($y = $gy - $cincin; $y <= $gy + $cincin; $y++) {
            foreach ($kisi[$x . ':' . $y] ?? [] as $id) {
                $keluar[$id] = true;
            }
        }
    }

    return array_keys($keluar);
}

// ---------------------------------------------------------------------------
// 1. Kembar: segmen bidang yang sudah tergambar sebagai tepi jalan
// ---------------------------------------------------------------------------

$bidangDibuang = 0;
$panjangBidangDibuang = 0.0;
$panjangBidangSimpan = 0.0;
$segBidangSimpan = [];
foreach ($segBidang as $id => [$ax, $ay, $bx, $by, $blok, $idPersil]) {
    $panjang = jarak($ax, $ay, $bx, $by);
    if ($panjang <= 0.0) {
        continue;
    }
    $bagian = max(1, (int) ceil($panjang / LANGKAH));
    $tertutup = 0;
    for ($j = 0; $j < $bagian; $j++) {
        $t = ($j + 0.5) / $bagian;
        $px = $ax + $t * ($bx - $ax);
        $py = $ay + $t * ($by - $ay);
        $kunci = null;
        foreach (sekitar($kisiTepi, $px, $py, SEL_TEPI) as $idTepi) {
            [$d] = proyeksi($px, $py, $segTepi[$idTepi][0], $segTepi[$idTepi][1],
                $segTepi[$idTepi][2], $segTepi[$idTepi][3]);
            if ($d <= KEMBAR_JARAK) {
                $kunci = true;
                break;
            }
        }
        if ($kunci) {
            $tertutup++;
        }
    }
    if ($tertutup / $bagian >= KEMBAR_BAGI) {
        $bidangDibuang++;
        $panjangBidangDibuang += $panjang;
    } else {
        $segBidangSimpan[$id] = [$ax, $ay, $bx, $by, $blok, $idPersil];
        $panjangBidangSimpan += $panjang;
    }
}
printf("kembar: %d segmen bidang dibuang (%s m) · %d disimpan (%s m)\n", $bidangDibuang,
    angkaNum($panjangBidangDibuang, 1), count($segBidangSimpan), angkaNum($panjangBidangSimpan, 1));

// ---------------------------------------------------------------------------
// 2. Potong segmen bidang di ujung tepi jalan
// ---------------------------------------------------------------------------

$potong = [];
$jumlahPotong = 0;
$jumlahPotongBuang = 0;
foreach ($tepi as $f) {
    $g = $f['geometry'] ?? [];
    if (($g['type'] ?? '') !== 'LineString') {
        continue;
    }
    $koor = $g['coordinates'];
    $n = count($koor);
    if ($n < 2) {
        continue;
    }
    $ujung = [[(float) $koor[0][0], (float) $koor[0][1]]];
    $akhir = [(float) $koor[$n - 1][0], (float) $koor[$n - 1][1]];
    if (jarak($ujung[0][0], $ujung[0][1], $akhir[0], $akhir[1]) > 0.0) {
        $ujung[] = $akhir;
    }
    foreach ($ujung as [$ux, $uy]) {
        $kenaSimpan = false;
        $kenaBuang = false;
        foreach (sekitar($kisiBidang, $ux, $uy, SEL_BIDANG) as $id) {
            [$d, $t] = proyeksi($ux, $uy, $segBidang[$id][0], $segBidang[$id][1],
                $segBidang[$id][2], $segBidang[$id][3]);
            if ($d > POTONG_M || $t <= 0.02 || $t >= 0.98) {
                continue;
            }
            if (isset($segBidangSimpan[$id])) {
                $potong[$id][] = ['t' => $t, 'p' => [$ux, $uy]];
                $jumlahPotong++;
                $kenaSimpan = true;
            } else {
                $kenaBuang = true;
            }
        }
        if ($kenaBuang && !$kenaSimpan) {
            $jumlahPotongBuang++;
        }
    }
}
foreach ($potong as &$daftarPotong) {
    usort($daftarPotong, static fn(array $a, array $b): int => $a['t'] <=> $b['t']);
}
unset($daftarPotong);
printf("potong: %d ujung tepi di atas %d segmen bidang · %d ujung menempel segmen kembar yang dibuang\n",
    $jumlahPotong, count($potong), $jumlahPotongBuang);

// ---------------------------------------------------------------------------
// 3. Simpul 0,35 m
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

/** Blok persil terdekat satu titik; 0 bila tidak ada dalam 10 m. */
$blokTitik = static function (float $x, float $y) use ($kisiBidang, $segBidang): int
{
    $terbaik = INF;
    $blok = 0;
    foreach (sekitar($kisiBidang, $x, $y, SEL_BIDANG) as $id) {
        [$d] = proyeksi($x, $y, $segBidang[$id][0], $segBidang[$id][1], $segBidang[$id][2],
            $segBidang[$id][3]);
        if ($d < $terbaik) {
            $terbaik = $d;
            $blok = $segBidang[$id][4];
        }
    }

    return $blok;
};

// ---------------------------------------------------------------------------
// 4. Graf ruas
// ---------------------------------------------------------------------------

$ruas = [];

// Tepi jalan: satu ruas per titik-berurutan; blok dari persil terdekat titik tengah.
foreach ($tepi as $f) {
    $g = $f['geometry'] ?? [];
    if (($g['type'] ?? '') !== 'LineString') {
        continue;
    }
    $koordinat = $g['coordinates'];
    $kode = (string) ($f['properties']['kode'] ?? '?');
    $tengah = [(float) $koordinat[(int) floor(count($koordinat) / 2)][0],
        (float) $koordinat[(int) floor(count($koordinat) / 2)][1]];
    $blok = $blokTitik($tengah[0], $tengah[1]);
    for ($i = 1, $n = count($koordinat); $i < $n; $i++) {
        $ax = (float) $koordinat[$i - 1][0];
        $ay = (float) $koordinat[$i - 1][1];
        $bx = (float) $koordinat[$i][0];
        $by = (float) $koordinat[$i][1];
        if (jarak($ax, $ay, $bx, $by) <= 0.0) {
            continue;
        }
        $ia = $simpul($ax, $ay);
        $ib = $simpul($bx, $by);
        if ($ia === $ib) {
            continue;
        }
        $ruas[] = ['a' => $ia, 'b' => $ib,
            'pa' => [$ax, $ay], 'pb' => [$bx, $by], 'blok' => $blok, 'sumber' => 'jalan',
            'tepi' => [$kode], 'persil' => 0];
    }
}

// Tepi bidang yang tidak kembar, dipotong di ujung tepi jalan.
foreach ($segBidangSimpan as $idSeg => [$ax, $ay, $bx, $by, $blok, $idPersil]) {
    $urut = [[$ax, $ay]];
    foreach ($potong[$idSeg] ?? [] as $titikPotong) {
        $urut[] = $titikPotong['p'];
    }
    $urut[] = [$bx, $by];
    for ($i = 1, $n = count($urut); $i < $n; $i++) {
        [$qx, $qy] = $urut[$i - 1];
        [$rx, $ry] = $urut[$i];
        if (jarak($qx, $qy, $rx, $ry) <= 0.0) {
            continue;
        }
        $ia = $simpul($qx, $qy);
        $ib = $simpul($rx, $ry);
        if ($ia === $ib) {
            continue;
        }
        $ruas[] = ['a' => $ia, 'b' => $ib,
            'pa' => [$qx, $qy], 'pb' => [$rx, $ry], 'blok' => $blok, 'sumber' => 'bidang',
            'tepi' => [], 'persil' => $idPersil];
    }
}
printf("ruas: %d (simpul %d)\n", count($ruas), count($simpulTitik));

// Ruas kembar sesama (simpul sama, bentuk berimpit): simpan yang berasal dari tepi jalan.
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
            if ($r['sumber'] === 'jalan' && $lain['sumber'] !== 'jalan') {
                $ruasBersih[$idx] = $r;
            }

            continue;
        }
    }
    $perPasangan[$kunci] = count($ruasBersih);
    $ruasBersih[] = $r;
}
$ruas = array_values($ruasBersih);
printf("ruas kembar sesama dibuang: %d · ruas tinggal %d\n", $buangPasangan, count($ruas));

// ---------------------------------------------------------------------------
// 5. Pasangan paling lurus di tiap simpul, lalu telusuri jadi polilin
// ---------------------------------------------------------------------------

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

// Arah keluar tiap ruas di kedua ujungnya.
$keluar = [];
foreach ($ruas as $id => $r) {
    $keluar[$r['a']][$id] = arah($r['pa'], $r['pb']);
    $keluar[$r['b']][$id] = arah($r['pb'], $r['pa']);
}

$pasangan = [];
$pasanganJalan = 0;
$pasanganCampur = 0;
$pasanganBidang = 0;
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
            $a = $ids[$i];
            $b = $ids[$j];
            if ($ruas[$a]['blok'] !== $ruas[$b]['blok']) {
                continue;
            }
            $v1 = $daftar[$a];
            $v2 = $daftar[$b];
            $belok = belokan([-$v1[0], -$v1[1]], $v2);
            if (!$simpulDua && $belok > BELOK_MAKS) {
                continue;
            }
            $kandidat[] = [$belok, $a, $b];
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
        if ($ruas[$a]['sumber'] === 'jalan' && $ruas[$b]['sumber'] === 'jalan') {
            $pasanganJalan++;
        } elseif ($ruas[$a]['sumber'] === 'bidang' && $ruas[$b]['sumber'] === 'bidang') {
            $pasanganBidang++;
        } else {
            $pasanganCampur++;
        }
    }
}
printf("sambungan: %d pasangan (jalan-jalan %d · jalan-bidang %d · bidang-bidang %d) · simpul bercabang %d\n",
    $pasanganJalan + $pasanganCampur + $pasanganBidang, $pasanganJalan, $pasanganCampur,
    $pasanganBidang, $simpulBercabang);

$terpakai = [];
$polilin = [];
foreach ($ruas as $idAwal => $rAwal) {
    if (isset($terpakai[$idAwal])) {
        continue;
    }
    $terpakai[$idAwal] = true;
    $titik = [$rAwal['pa'], $rAwal['pb']];
    $kodeTepi = $rAwal['tepi'];
    $sumber = [$rAwal['sumber'] => true];
    $blok = $rAwal['blok'];
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
            $blok = $blok ?: $lanjut['blok'];
            $kodeTepi = array_merge($kodeTepi, $lanjut['tepi']);
            $sumber[$lanjut['sumber']] = true;
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

    // Cincin: kedua ujung polilin jatuh di satu simpul.
    $tertutup = $simpulAwal === $simpulAkhir && count($titik) > 2;
    if ($tertutup) {
        $titik[count($titik) - 1] = $titik[0];
    }

    $panjang = 0.0;
    for ($i = 1, $n = count($titik); $i < $n; $i++) {
        $panjang += jarak($titik[$i - 1][0], $titik[$i - 1][1], $titik[$i][0], $titik[$i][1]);
    }
    $polilin[] = ['titik' => $titik, 'blok' => $blok, 'sumber' => array_keys($sumber),
        'tepi' => array_values(array_unique($kodeTepi)), 'ruas' => $jumlahRuas,
        'panjang' => $panjang, 'tertutup' => $tertutup];
}

$panjangKeluaran = array_sum(array_column($polilin, 'panjang'));
$jumlahCincin = count(array_filter($polilin, static fn(array $p): bool => $p['tertutup']));
printf("polilin: %d · %s m · ruas tergabung %d · cincin %d\n", count($polilin),
    angkaNum($panjangKeluaran, 1), array_sum(array_column($polilin, 'ruas')), $jumlahCincin);

// ---------------------------------------------------------------------------
// Verifikasi: tidak ada koordinat baru
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

// ---------------------------------------------------------------------------
// Rekap per blok
// ---------------------------------------------------------------------------

$rekap = [];
foreach ($polilin as $p) {
    $k = $p['blok'];
    $rekap[$k]['baris'] = ($rekap[$k]['baris'] ?? 0) + 1;
    $rekap[$k]['panjang'] = ($rekap[$k]['panjang'] ?? 0.0) + $p['panjang'];
    $rekap[$k]['campur'] = ($rekap[$k]['campur'] ?? 0) + (count($p['sumber']) > 1 ? 1 : 0);
}
ksort($rekap);
echo "\nper blok:\n";
foreach ($rekap as $id => $data) {
    printf("  %-10s %4d polilin · %10s m · campur %3d\n", $namaBlok[$id] ?? ('?' . $id),
        $data['baris'], angkaNum($data['panjang'], 1), $data['campur']);
}

if ($rinci) {
    usort($polilin, static fn(array $a, array $b): int => $b['panjang'] <=> $a['panjang']);
    echo "\n30 polilin terpanjang:\n";
    foreach (array_slice($polilin, 0, 30) as $p) {
        printf("  %8s m · %3d ruas · %-10s · %s%s\n", angkaNum($p['panjang'], 1), $p['ruas'],
            $namaBlok[$p['blok']] ?? ('?' . $p['blok']), implode(',', $p['sumber']),
            $p['tertutup'] ? ' · cincin' : '');
    }
}

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
            'kode' => sprintf('JAHIT-%04d', $i + 1),
            'blok' => $namaBlok[$p['blok']] ?? 'TANPA BLOK',
            'blok_id' => $p['blok'],
            'sumber' => count($p['sumber']) > 1 ? 'campur' : $p['sumber'][0],
            'kode_tepi' => $p['tepi'],
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
printf("validasi baca ulang %s: %d fitur · bukan LineString sah %d\n", basename($berkas), count($cek),
    $salah);

// ---------------------------------------------------------------------------
// Metadata
// ---------------------------------------------------------------------------

$berkasMeta = DIR_LAPISAN . '/metadata.json';
$meta = is_file($berkasMeta) ? (array) json_decode((string) file_get_contents($berkasMeta), true) : [];
if ($meta === []) {
    galat('metadata.json tidak dapat dibaca');
}
$meta['layers'][JUDUL] = [
    'features' => count($fitur),
    'types' => ['line' => count($fitur)],
    'file' => $berkasKeluar . '.geojson',
    'panjang_m' => (int) round($panjangKeluaran),
    'derived_from' => 'garis-jalan-tepi.geojson dijahit dengan cincin luar persil tabel bidang per blok',
    'ambang' => ['simpul_m' => SIMPUL_M, 'potong_m' => POTONG_M, 'belok_derajat' => BELOK_MAKS,
        'kembar_jarak_m' => KEMBAR_JARAK, 'kembar_bagi' => KEMBAR_BAGI, 'langkah_sampel_m' => LANGKAH],
    'penyambungan' => ['ruas_masuk' => count($ruas), 'ruas_tergabung' =>
        array_sum(array_column($polilin, 'ruas')), 'polilin' => count($polilin),
        'pasangan' => $pasanganJalan + $pasanganCampur + $pasanganBidang,
        'pasangan_jalan_bidang' => $pasanganCampur,
        'kembar_segmen_bidang_dibuang' => $bidangDibuang,
        'kembar_panjang_m' => round($panjangBidangDibuang, 1),
        'kembar_ruas_sesama_dibuang' => $buangPasangan,
        'potong_segmen_bidang' => count($potong)],
    'per_blok' => array_map(static fn(array $d): array => ['polilin' => $d['baris'],
        'panjang_m' => round($d['panjang'], 1), 'campur' => $d['campur']], $rekap),
    'derived_at' => date('c'),
];
$meta['notes'][] = sprintf(
    JUDUL . ': %d polilin (%s m) dari %d ruas; %d segmen tepi bidang (%s m) dibuang karena sudah'
    . ' tergambar sebagai tepi jalan (sampel tiap %s m, >= %.0f%% dalam %s m). Simpul %s m,'
    . ' ujung tepi yang jatuh di tengah segmen bidang dipotong memakai ujung itu sendiri (<= %s m),'
    . ' lalu di tiap simpul dua ruas paling lurus dipasangkan bila bloknya sama dan belokan <= %.0f'
    . ' derajat; tidak ada koordinat yang digeser.',
    count($polilin), angkaNum($panjangKeluaran, 1), count($ruas), $bidangDibuang,
    angkaNum($panjangBidangDibuang, 1), angkaNum(LANGKAH, 1), KEMBAR_BAGI * 100,
    angkaNum(KEMBAR_JARAK, 1), angkaNum(SIMPUL_M, 2), angkaNum(POTONG_M, 2), BELOK_MAKS
);
if (file_put_contents($berkasMeta, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
    galat('gagal menulis metadata.json');
}
echo "metadata.json diperbarui.\n";
