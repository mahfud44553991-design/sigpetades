<?php
declare(strict_types=1);

/**
 * Sambung ujung garis yang terputus pada satu lapis turunan.
 *
 *   php -d memory_limit=1024M tools/sambung-ujung-garis.php --lapis=semua
 *   php -d memory_limit=1024M tools/sambung-ujung-garis.php --lapis=semua --terapkan
 *   php -d memory_limit=1024M tools/sambung-ujung-garis.php --lapis=jalan-tepi --rapat=2 --celah=3 --rinci
 *
 *   --lapis=NAMA   jalan-tepi, jalan-sumbu, selokan, saluran-jalan, tepi-saluran,
 *                  tepi-sungai, atau semua (bawaan)
 *   --rapat=M      jarak maksimum ujung bebas ke segmen garis lain untuk ditempel (bawaan 2,0 m)
 *   --celah=M      jarak maksimum dua ujung bebas untuk dijembatani (bawaan 3,0 m)
 *   --sudut=D      beda arah tempel terhadap arah ujung garis (bawaan 45 derajat)
 *   --belok=D      beda arah sambungan jembatan di kedua ujung (bawaan 30 derajat)
 *   --toleransi=M  jarak ujung yang dianggap berimpit (bawaan 0,05 m)
 *   --rinci        cetak tiap sambungan yang dibuat
 *   --terapkan     tulis berkas dan perbarui metadata.json
 *
 * Latar. Enam lapis garis turunan masih menyisakan ujung bebas yang tidak
 * bertemu apa pun: 1.028 di Jalan Bidang, 6.210 di Jalan Peta Induk, 281 di
 * Selokan, 38 di Saluran dari Jalan, 425 di Tepi saluran, dan 207 di Tepi
 * sungai. Sebagian besar bukan celah ujung-ke-ujung, melainkan ujung yang
 * berhenti beberapa desimeter sampai dua meter dari BADAN garis lain: label
 * memotong garis, atau digitasi berhenti sebelum persimpangan.
 *
 * Yang alat ini kerjakan, semuanya topologi:
 *   1. ujung baris yang berjarak <= --toleransi dianggap satu simpul; baris
 *      yang pangkal dan ujungnya simpul yang sama adalah cincin dan tidak
 *      disentuh;
 *   2. ujung bebas yang jaraknya <= --rapat dari SEGMEN baris lain ditempel:
 *      titik proyeksi tegak lurus pada segmen itu ditambahkan sebagai verteks
 *      ujung baris sumber. Arah tempel (ujung ke titik proyeksi) harus <=
 *      --sudut terhadap arah keluar ujung itu, supaya garis tidak ditarik
 *      menyamping ke garis sejajar. Ujung yang sudah menyentuh segmen lain
 *      (<= --toleransi) dihitung selesai tanpa diubah;
 *   3. dua ujung bebas baris BERBEDA yang jaraknya <= --celah dijembatani bila
 *      arah sambungannya menerus di kedua ujung (<= --belok): arah keluar ujung
 *      A searah jembatan, dan arah keluar ujung B berlawanan jembatan. Ujung
 *      baris B disalin sebagai verteks ujung baris A, jadi jembatan tetap
 *      memakai dua titik bahan yang sudah ada;
 *   4. sambungan yang membuat cincin baru, jembatan/tempelan yang memotong
 *      segmen lain, atau yang menabrak tempelan sebelumnya ditolak.
 *
 * Tidak ada titik karangan: satu-satunya titik baru adalah proyeksi tegak
 * lurus pada segmen nyata, dan jembatan memakai ujung bahan. Jumlah baris
 * tidak berubah; hanya ujung baris yang menempa yang bergeser sepanjang
 * <= --rapat/--celah meter. `panjang_m`, jumlah titik, `sambungan_ujung` dan
 * `sambungan_ujung_m` tiap baris yang disentuh diperbarui, begitu pula angka
 * lapis di metadata.json. Berkas lama dan metadata dicadangkan ke
 * _lama/cadangan-sambung-ujung-<lapis>-<stempel>/.
 *
 * Verifikasi sebelum menulis: jumlah baris tetap, jumlah cincin tetap, tidak
 * ada titik asing di luar bahan dan proyeksi, tidak ada potongan baru yang
 * menyilang, dan panjang total = panjang bahan + jumlah panjang sambungan.
 *
 * Uji kering adalah bawaan; tanpa --terapkan tidak ada berkas yang ditulis.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

date_default_timezone_set('Asia/Jakarta');

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';

const LAPIS = [
    'jalan-tepi' => ['berkas' => 'garis-jalan-tepi', 'judul' => 'GARIS JALAN TEPI DARI LABEL'],
    'jalan-sumbu' => ['berkas' => 'garis-jalan-dari-sumbu', 'judul' => null],
    'selokan' => ['berkas' => 'garis-selokan', 'judul' => 'GARIS SELOKAN 020100'],
    'saluran-jalan' => ['berkas' => 'garis-saluran-dari-jalan', 'judul' => 'GARIS SALURAN DARI JALAN'],
    'tepi-saluran' => ['berkas' => 'garis-tepi-saluran', 'judul' => 'GARIS TEPI SALURAN'],
    'tepi-sungai' => ['berkas' => 'garis-tepi-sungai-dari-sumbu', 'judul' => 'GARIS TEPI SUNGAI DARI SUMBU'],
];

function galat(string $pesan): never
{
    fwrite(STDERR, 'GAGAL: ' . $pesan . PHP_EOL);
    exit(1);
}

function angkaNum(float $n, int $desimal = 0): string
{
    return number_format($n, $desimal, ',', '.');
}

function panjangBaris(array $pts): float
{
    $jml = 0.0;
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        $jml += hypot($pts[$i][0] - $pts[$i - 1][0], $pts[$i][1] - $pts[$i - 1][1]);
    }
    return $jml;
}

function norm(array $v): array
{
    $l = hypot($v[0], $v[1]);
    return $l > 1e-12 ? [$v[0] / $l, $v[1] / $l] : [0.0, 0.0];
}

/** Sudut antara dua vektor, 0..180 derajat. */
function sudutVektor(array $u, array $v): float
{
    $lu = hypot($u[0], $u[1]);
    $lv = hypot($v[0], $v[1]);
    if ($lu < 1e-12 || $lv < 1e-12) {
        return 180.0;
    }
    $c = ($u[0] * $v[0] + $u[1] * $v[1]) / ($lu * $lv);
    return rad2deg(acos(max(-1.0, min(1.0, $c))));
}

/** Arah keluar di ujung baris, dirata-rata sampai $jendela verteks. */
function arahUjung(array $pts, bool $akhir, int $jendela = 4): array
{
    $n = count($pts);
    if ($n < 2) {
        return [0.0, 0.0];
    }
    if ($akhir) {
        $a = $pts[$n - 1];
        $b = $pts[max(0, $n - 1 - $jendela)];
    } else {
        $a = $pts[0];
        $b = $pts[min($n - 1, $jendela)];
    }
    return norm([$a[0] - $b[0], $a[1] - $b[1]]);
}

/** [jarak, qx, qy, t] proyeksi tegak lurus $p ke segmen $a-$b, t dijepit 0..1. */
function proyeksi(array $p, array $a, array $b): array
{
    $dx = $b[0] - $a[0];
    $dy = $b[1] - $a[1];
    $l2 = $dx * $dx + $dy * $dy;
    if ($l2 < 1e-12) {
        return [INF, $a[0], $a[1], 0.0];
    }
    $t = (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $l2;
    $t = max(0.0, min(1.0, $t));
    $qx = $a[0] + $t * $dx;
    $qy = $a[1] + $t * $dy;
    return [hypot($p[0] - $qx, $p[1] - $qy), $qx, $qy, $t];
}

/** Perpotongan murni dua ruas (bersilangan di titik dalam kedua ruas). */
function silangProper(array $a, array $b, array $c, array $d, float $eps = 1e-9): bool
{
    $o = static function (array $p, array $q, array $r): float {
        return ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);
    };
    $d1 = $o($a, $b, $c);
    $d2 = $o($a, $b, $d);
    $d3 = $o($c, $d, $a);
    $d4 = $o($c, $d, $b);
    return (($d1 > $eps && $d2 < -$eps) || ($d1 < -$eps && $d2 > $eps))
        && (($d3 > $eps && $d4 < -$eps) || ($d3 < -$eps && $d4 > $eps));
}

function kunciTitik(array $p): string
{
    return sprintf('%.6f:%.6f', $p[0], $p[1]);
}

/**
 * Komponen garis yang benar-benar bersambung: ujung berimpit, atau ujung yang
 * menyentuh segmen baris lain. Dipakai untuk mengukur manfaat sambungan.
 */
function hitungKomponen(array $garis, float $toleransi): int
{
    $n = count($garis);
    if ($n === 0) {
        return 0;
    }
    $induk = range(0, $n - 1);
    $cari = static function (int $x) use (&$induk): int {
        while ($induk[$x] !== $x) {
            $induk[$x] = $induk[$induk[$x]];
            $x = $induk[$x];
        }
        return $x;
    };
    $gabung = static function (int $a, int $b) use (&$cari, &$induk): void {
        $ra = $cari($a);
        $rb = $cari($b);
        if ($ra !== $rb) {
            $induk[$ra] = $rb;
        }
    };

    $sel = max(0.05, $toleransi);
    $kisiN = [];
    $simpul = [];
    $ambil = static function (array $p) use (&$kisiN, &$simpul, $sel, $toleransi): int {
        $gx = (int) floor($p[0] / $sel);
        $gy = (int) floor($p[1] / $sel);
        for ($x = $gx - 2; $x <= $gx + 2; $x++) {
            for ($y = $gy - 2; $y <= $gy + 2; $y++) {
                foreach ($kisiN[$x . ':' . $y] ?? [] as $id) {
                    if (hypot($simpul[$id][0] - $p[0], $simpul[$id][1] - $p[1]) <= $toleransi) {
                        return $id;
                    }
                }
            }
        }
        $id = count($simpul);
        $simpul[$id] = $p;
        $kisiN[$gx . ':' . $gy][] = $id;
        return $id;
    };

    $perSimpul = [];
    foreach ($garis as $i => $g) {
        $p = $g['pts'];
        $a = $ambil($p[0]);
        $b = $ambil($p[count($p) - 1]);
        $perSimpul[$a][] = $i;
        $perSimpul[$b][] = $i;
    }
    foreach ($perSimpul as $ids) {
        for ($j = 1, $m = count($ids); $j < $m; $j++) {
            $gabung($ids[0], $ids[$j]);
        }
    }

    $selS = max(5.0, $toleransi);
    $kisiS = [];
    $seg = [];
    foreach ($garis as $i => $g) {
        for ($s = 1, $m = count($g['pts']); $s < $m; $s++) {
            $a = $g['pts'][$s - 1];
            $b = $g['pts'][$s];
            $id = count($seg);
            $seg[$id] = [$i, $a, $b];
            $x0 = (int) floor(min($a[0], $b[0]) / $selS);
            $x1 = (int) floor(max($a[0], $b[0]) / $selS);
            $y0 = (int) floor(min($a[1], $b[1]) / $selS);
            $y1 = (int) floor(max($a[1], $b[1]) / $selS);
            for ($x = $x0; $x <= $x1; $x++) {
                for ($y = $y0; $y <= $y1; $y++) {
                    $kisiS[$x . ':' . $y][] = $id;
                }
            }
        }
    }
    foreach ($garis as $i => $g) {
        $m = count($g['pts']);
        foreach ([$g['pts'][0], $g['pts'][$m - 1]] as $p) {
            $gx = (int) floor($p[0] / $selS);
            $gy = (int) floor($p[1] / $selS);
            $lihat = [];
            for ($x = $gx - 1; $x <= $gx + 1; $x++) {
                for ($y = $gy - 1; $y <= $gy + 1; $y++) {
                    foreach ($kisiS[$x . ':' . $y] ?? [] as $id) {
                        if (isset($lihat[$id])) {
                            continue;
                        }
                        $lihat[$id] = true;
                        [$j, $a, $b] = $seg[$id];
                        if ($j === $i) {
                            continue;
                        }
                        [$d] = proyeksi($p, $a, $b);
                        if ($d <= $toleransi) {
                            $gabung($i, $j);
                        }
                    }
                }
            }
        }
    }

    $akar = [];
    for ($i = 0; $i < $n; $i++) {
        $akar[$cari($i)] = true;
    }
    return count($akar);
}

// ---------------------------------------------------------------------------
// Argumen
// ---------------------------------------------------------------------------

$argv = $_SERVER['argv'] ?? [];
$terapkan = in_array('--terapkan', $argv, true);
$rinci = in_array('--rinci', $argv, true);
$ambilAngka = static function (string $nama, float $bawaan) use ($argv): float {
    foreach ($argv as $a) {
        if (str_starts_with($a, '--' . $nama . '=')) {
            return (float) substr($a, strlen($nama) + 3);
        }
    }
    return $bawaan;
};
$rapat = $ambilAngka('rapat', 2.0);
$celah = $ambilAngka('celah', 3.0);
$sudut = $ambilAngka('sudut', 45.0);
$belok = $ambilAngka('belok', 30.0);
$toleransi = $ambilAngka('toleransi', 0.05);
$lapisArg = 'semua';
foreach ($argv as $a) {
    if (str_starts_with($a, '--lapis=')) {
        $lapisArg = (string) substr($a, 8);
    }
}
if ($rapat < 0 || $celah < 0) {
    galat('--rapat dan --celah tidak boleh negatif.');
}
if ($sudut <= 0 || $sudut > 90 || $belok <= 0 || $belok > 90) {
    galat('--sudut dan --belok harus antara 0 dan 90 derajat.');
}
if ($toleransi <= 0) {
    galat('--toleransi harus positif.');
}
if ($lapisArg === 'semua') {
    $daftarLapis = array_keys(LAPIS);
} elseif (isset(LAPIS[$lapisArg])) {
    $daftarLapis = [$lapisArg];
} else {
    galat('--lapis harus salah satu dari: ' . implode(', ', array_keys(LAPIS)) . ', atau semua.');
}
if ($rapat <= $toleransi) {
    fwrite(STDERR, "catatan: --rapat <= --toleransi, tempelan praktis mati.\n");
}
if ($celah <= $toleransi) {
    fwrite(STDERR, "catatan: --celah <= --toleransi, jembatan praktis mati.\n");
}

$stempel = date('Ymd-His');
$ringkasan = [];

// ---------------------------------------------------------------------------
// Pemroses satu lapis
// ---------------------------------------------------------------------------

$proses = static function (string $kunci, array $cfg) use (
    $rapat, $celah, $sudut, $belok, $toleransi, $rinci, $terapkan, $stempel, &$ringkasan
): void {
    $berkas = DIR_LAPISAN . '/' . $cfg['berkas'] . '.geojson';
    echo "\n" . str_repeat('-', 78) . "\n== $kunci · " . $cfg['berkas'] . " ==\n";
    if (!is_file($berkas)) {
        echo "tidak ada berkas, dilewati.\n";
        return;
    }
    $fc = json_decode((string) file_get_contents($berkas), true);
    if (!is_array($fc) || !isset($fc['features']) || !is_array($fc['features'])) {
        galat('berkas tidak dapat dibaca: ' . $berkas);
    }
    $fiturAsli = $fc['features'];

    $garis = [];
    $titikAsal = [];
    $panjangAwal = 0.0;
    foreach ($fiturAsli as $i => $f) {
        $g = $f['geometry'] ?? [];
        if (($g['type'] ?? '') !== 'LineString') {
            galat('fitur #' . ($i + 1) . ' bertipe ' . ($g['type'] ?? '?')
                . '; alat ini hanya menangani LineString.');
        }
        $pts = [];
        foreach ((array) ($g['coordinates'] ?? []) as $c) {
            $p = [(float) $c[0], (float) $c[1]];
            $pts[] = $p;
            $titikAsal[kunciTitik($p)] = true;
        }
        if (count($pts) < 2) {
            galat('fitur #' . ($i + 1) . ' kurang dari dua titik.');
        }
        $garis[$i] = [
            'pts' => $pts,
            'props' => (array) ($f['properties'] ?? []),
            'kode' => (string) ($f['properties']['kode'] ?? ('#' . ($i + 1))),
            'tambah' => 0,
            'tambah_m' => 0.0,
        ];
        $panjangAwal += panjangBaris($pts);
    }
    $jumlahBaris = count($garis);
    if ($jumlahBaris === 0) {
        echo "tidak ada baris, dilewati.\n";
        return;
    }

    // -- 1. simpul ujung, cincin, ujung bebas -------------------------------
    $selN = max(0.01, $toleransi);
    $node = [];
    $kisiN = [];
    $ambilNode = static function (array $p) use (&$node, &$kisiN, $selN, $toleransi): int {
        $gx = (int) floor($p[0] / $selN);
        $gy = (int) floor($p[1] / $selN);
        for ($x = $gx - 2; $x <= $gx + 2; $x++) {
            for ($y = $gy - 2; $y <= $gy + 2; $y++) {
                foreach ($kisiN[$x . ':' . $y] ?? [] as $id) {
                    if (hypot($node[$id][0] - $p[0], $node[$id][1] - $p[1]) <= $toleransi) {
                        return $id;
                    }
                }
            }
        }
        $id = count($node);
        $node[$id] = $p;
        $kisiN[$gx . ':' . $gy][] = $id;
        return $id;
    };
    $derajat = [];
    $ring = [];
    $nodeA = [];
    $nodeB = [];
    foreach ($garis as $i => $g) {
        $m = count($g['pts']);
        $a = $ambilNode($g['pts'][0]);
        $b = $ambilNode($g['pts'][$m - 1]);
        $nodeA[$i] = $a;
        $nodeB[$i] = $b;
        $ring[$i] = ($a === $b);
        $derajat[$a] = ($derajat[$a] ?? 0) + 1;
        if ($a !== $b) {
            $derajat[$b] = ($derajat[$b] ?? 0) + 1;
        }
    }
    $bebas = [];
    foreach ($garis as $i => $g) {
        if ($ring[$i]) {
            continue;
        }
        $m = count($g['pts']);
        if (($derajat[$nodeA[$i]] ?? 0) === 1) {
            $bebas[] = ['i' => $i, 'sisi' => 0, 'p' => $g['pts'][0],
                'out' => arahUjung($g['pts'], false)];
        }
        if (($derajat[$nodeB[$i]] ?? 0) === 1) {
            $bebas[] = ['i' => $i, 'sisi' => 1, 'p' => $g['pts'][$m - 1],
                'out' => arahUjung($g['pts'], true)];
        }
    }
    $ringAwal = count(array_filter($ring));
    $kompAwal = hitungKomponen($garis, $toleransi);

    // -- 2. kisi segmen untuk pencarian tetangga ----------------------------
    $selS = max(5.0, $rapat, $celah);
    $seg = [];
    $kisiS = [];
    foreach ($garis as $i => $g) {
        for ($s = 1, $m = count($g['pts']); $s < $m; $s++) {
            $a = $g['pts'][$s - 1];
            $b = $g['pts'][$s];
            $id = count($seg);
            $seg[$id] = [$i, $a, $b];
            $x0 = (int) floor(min($a[0], $b[0]) / $selS);
            $x1 = (int) floor(max($a[0], $b[0]) / $selS);
            $y0 = (int) floor(min($a[1], $b[1]) / $selS);
            $y1 = (int) floor(max($a[1], $b[1]) / $selS);
            for ($x = $x0; $x <= $x1; $x++) {
                for ($y = $y0; $y <= $y1; $y++) {
                    $kisiS[$x . ':' . $y][] = $id;
                }
            }
        }
    }
    /** Segmen di sekitar satu titik; jari-jari aman = satu sel. */
    $segDekat = static function (array $p) use (&$kisiS, $selS): array {
        $gx = (int) floor($p[0] / $selS);
        $gy = (int) floor($p[1] / $selS);
        $hasil = [];
        $lihat = [];
        for ($x = $gx - 1; $x <= $gx + 1; $x++) {
            for ($y = $gy - 1; $y <= $gy + 1; $y++) {
                foreach ($kisiS[$x . ':' . $y] ?? [] as $id) {
                    if (!isset($lihat[$id])) {
                        $lihat[$id] = true;
                        $hasil[] = $id;
                    }
                }
            }
        }
        return $hasil;
    };

    // -- 3. calon tempel dan jembatan ---------------------------------------
    $kandidat = [];
    $pakai = [];
    $menempel = 0;
    $tolakArah = 0;
    $tolakSilang = 0;
    $tolakCincin = 0;

    foreach ($bebas as $k => $u) {
        $menempelDiSini = false;
        $lolosArah = false;
        $terbaik = null;
        foreach ($segDekat($u['p']) as $sid) {
            [$j, $a, $b] = $seg[$sid];
            if ($j === $u['i']) {
                continue;
            }
            [$d, $qx, $qy] = proyeksi($u['p'], $a, $b);
            if ($d > $rapat) {
                continue;
            }
            if ($d <= $toleransi) {
                $menempelDiSini = true;
                continue;
            }
            $v = norm([$qx - $u['p'][0], $qy - $u['p'][1]]);
            if (sudutVektor($u['out'], $v) > $sudut) {
                continue;
            }
            $lolosArah = true;
            if ($terbaik === null || $d < $terbaik['d']) {
                $terbaik = ['d' => $d, 'q' => [$qx, $qy], 'j' => $j];
            }
        }
        if ($menempelDiSini) {
            $pakai[$k] = true;
            $menempel++;
            continue;
        }
        if ($terbaik === null) {
            if (!$lolosArah) {
                $tolakArah++;
            }
            continue;
        }
        $g = $garis[$u['i']];
        $lain = $u['sisi'] === 1 ? $g['pts'][0] : $g['pts'][count($g['pts']) - 1];
        if (hypot($terbaik['q'][0] - $lain[0], $terbaik['q'][1] - $lain[1]) <= $toleransi) {
            $tolakCincin++;
            continue;
        }
        $kandidat[] = ['d' => $terbaik['d'], 'jenis' => 'rapat', 'a' => $k,
            'p' => $u['p'], 'q' => $terbaik['q'], 'j' => $terbaik['j']];
    }

    // Jembatan: pasangan ujung bebas baris berbeda.
    $selB = max(2.0, $celah);
    $kisiB = [];
    foreach ($bebas as $k => $u) {
        if (isset($pakai[$k])) {
            continue;
        }
        $kisiB[(int) floor($u['p'][0] / $selB) . ':' . (int) floor($u['p'][1] / $selB)][] = $k;
    }
    $tolakJembatanSilang = 0;
    foreach ($kisiB as $sel => $ids) {
        // Pasangan di dalam sel yang sama.
        for ($x = 0, $m = count($ids); $x < $m; $x++) {
            for ($y = $x + 1; $y < $m; $y++) {
                $kandidat[] = [$ids[$x], $ids[$y]];
            }
        }
    }
    // Pasangan antar sel tetangga (tiap pasangan muncul sekali).
    $selTerisi = array_keys($kisiB);
    foreach ($selTerisi as $sel) {
        [$gx, $gy] = array_map('intval', explode(':', $sel));
        foreach ([[1, 0], [0, 1], [1, 1], [1, -1]] as [$dx, $dy]) {
            $lain = ($gx + $dx) . ':' . ($gy + $dy);
            if (!isset($kisiB[$lain])) {
                continue;
            }
            foreach ($kisiB[$sel] as $a) {
                foreach ($kisiB[$lain] as $b) {
                    $kandidat[] = [$a, $b];
                }
            }
        }
    }
    $kandidatPasangan = [];
    foreach ($kandidat as $c) {
        if (isset($c['jenis'])) {
            $kandidatPasangan[] = $c;
            continue;
        }
        [$ka, $kb] = $c;
        $ua = $bebas[$ka];
        $ub = $bebas[$kb];
        if ($ua['i'] === $ub['i']) {
            continue;
        }
        $d = hypot($ub['p'][0] - $ua['p'][0], $ub['p'][1] - $ua['p'][1]);
        if ($d <= $toleransi || $d > $celah) {
            continue;
        }
        $v = norm([$ub['p'][0] - $ua['p'][0], $ub['p'][1] - $ua['p'][1]]);
        if (sudutVektor($ua['out'], $v) > $belok
            || sudutVektor($ub['out'], [-$v[0], -$v[1]]) > $belok) {
            continue;
        }
        $g = $garis[$ua['i']];
        $lain = $ua['sisi'] === 1 ? $g['pts'][0] : $g['pts'][count($g['pts']) - 1];
        if (hypot($ub['p'][0] - $lain[0], $ub['p'][1] - $lain[1]) <= $toleransi) {
            $tolakCincin++;
            continue;
        }
        $kandidatPasangan[] = ['d' => $d, 'jenis' => 'celah', 'a' => $ka, 'b' => $kb,
            'p' => $ua['p'], 'q' => $ub['p']];
    }
    $kandidat = $kandidatPasangan;
    usort($kandidat, static function (array $x, array $y): int {
        $jx = $x['jenis'] === 'celah' ? 0 : 1;
        $jy = $y['jenis'] === 'celah' ? 0 : 1;
        return ($x['d'] <=> $y['d']) ?: ($jx <=> $jy) ?: ($x['a'] <=> $y['a'])
            ?: (($x['b'] ?? -1) <=> ($y['b'] ?? -1));
    });

    // -- 4. terapkan sambungan terpilih -------------------------------------
    $priorTails = [];
    $dipakai = [];
    $tempel = [];
    $jembatan = [];
    $tolakTail = 0;
    $tabrakPrior = static function (array $p, array $q, array $prior): bool {
        foreach ($prior as $t) {
            if (silangProper($p, $q, [$t[0], $t[1]], [$t[2], $t[3]])) {
                return true;
            }
        }
        return false;
    };
    $tabrakSegmen = static function (array $p, array $q) use ($segDekat, $seg): bool {
        $tengah = [($p[0] + $q[0]) / 2, ($p[1] + $q[1]) / 2];
        foreach ($segDekat($tengah) as $sid) {
            [, $a, $b] = $seg[$sid];
            if (silangProper($p, $q, $a, $b)) {
                return true;
            }
        }
        return false;
    };
    $tambahUjung = static function (int $i, array $q, int $sisi, float $d) use (&$garis): void {
        if ($sisi === 1) {
            $garis[$i]['pts'][] = $q;
        } else {
            array_unshift($garis[$i]['pts'], $q);
        }
        $garis[$i]['tambah']++;
        $garis[$i]['tambah_m'] += $d;
    };

    foreach ($kandidat as $c) {
        if ($c['jenis'] === 'rapat') {
            $k = $c['a'];
            if (isset($dipakai[$k])) {
                continue;
            }
            if ($tabrakSegmen($c['p'], $c['q']) || $tabrakPrior($c['p'], $c['q'], $priorTails)) {
                $tolakSilang++;
                continue;
            }
            $dipakai[$k] = true;
            $u = $bebas[$k];
            $tambahUjung($u['i'], $c['q'], $u['sisi'], $c['d']);
            $priorTails[] = [$c['p'][0], $c['p'][1], $c['q'][0], $c['q'][1]];
            $tempel[] = ['kode' => $garis[$u['i']]['kode'], 'ke' => $garis[$c['j']]['kode'],
                'd' => $c['d'], 'sudut' => sudutVektor($u['out'],
                    norm([$c['q'][0] - $c['p'][0], $c['q'][1] - $c['p'][1]]))];
            continue;
        }
        $ka = $c['a'];
        $kb = $c['b'];
        if (isset($dipakai[$ka]) || isset($dipakai[$kb])) {
            continue;
        }
        if ($tabrakSegmen($c['p'], $c['q']) || $tabrakPrior($c['p'], $c['q'], $priorTails)) {
            $tolakJembatanSilang++;
            continue;
        }
        $dipakai[$ka] = true;
        $dipakai[$kb] = true;
        $ua = $bebas[$ka];
        $ub = $bebas[$kb];
        $tambahUjung($ua['i'], $ub['p'], $ua['sisi'], $c['d']);
        $priorTails[] = [$c['p'][0], $c['p'][1], $c['q'][0], $c['q'][1]];
        $jembatan[] = ['kode' => $garis[$ua['i']]['kode'], 'ke' => $garis[$ub['i']]['kode'],
            'd' => $c['d']];
    }

    // -- 5. properti, hasil, dan verifikasi ---------------------------------
    $panjangSesudah = 0.0;
    $panjangSambungan = 0.0;
    foreach ($tempel as $t) {
        $panjangSambungan += $t['d'];
    }
    foreach ($jembatan as $j) {
        $panjangSambungan += $j['d'];
    }
    foreach ($garis as $i => $g) {
        if ($g['tambah'] > 0) {
            $pts = $g['pts'];
            $len = panjangBaris($pts);
            $p = $g['props'];
            $p['panjang_m'] = round($len, 2);
            if (array_key_exists('titik', $p)) {
                $p['titik'] = count($pts);
            }
            if (array_key_exists('segmen', $p)) {
                $p['segmen'] = count($pts) - 1;
            }
            if (array_key_exists('jumlah_titik', $p)) {
                $p['jumlah_titik'] = count($pts);
            }
            $p['sambungan_ujung'] = $g['tambah'];
            $p['sambungan_ujung_m'] = round($g['tambah_m'], 2);
            $garis[$i]['props'] = $p;
        }
        $panjangSesudah += panjangBaris($garis[$i]['pts']);
    }

    $kunciProyeksi = [];
    foreach ($kandidat as $c) {
        if ($c['jenis'] !== 'rapat') {
            continue;
        }
        $kunciProyeksi[kunciTitik($c['q'])] = true;
    }
    $titikBaru = 0;
    $titikAsing = 0;
    foreach ($garis as $g) {
        foreach ($g['pts'] as $p) {
            $k = kunciTitik($p);
            if (!isset($titikAsal[$k])) {
                $titikBaru++;
                if (!isset($kunciProyeksi[$k])) {
                    $titikAsing++;
                }
            }
        }
    }
    $ringSesudah = 0;
    foreach ($garis as $g) {
        $m = count($g['pts']);
        if (hypot($g['pts'][0][0] - $g['pts'][$m - 1][0],
                $g['pts'][0][1] - $g['pts'][$m - 1][1]) <= $toleransi) {
            $ringSesudah++;
        }
    }
    $kompSesudah = hitungKomponen($garis, $toleransi);
    $ujungSesudah = count($bebas) - ($menempel + count($tempel) + 2 * count($jembatan));
    $selisih = $panjangSesudah - $panjangAwal - $panjangSambungan;
    $galat = [];
    if ($ringSesudah !== $ringAwal) {
        $galat[] = "cincin berubah $ringAwal -> $ringSesudah";
    }
    if ($titikAsing > 0) {
        $galat[] = "titik di luar bahan/proyeksi $titikAsing";
    }
    if (abs($selisih) > 0.05) {
        $galat[] = 'panjang tidak seimbang: selisih ' . angkaNum($selisih, 3) . ' m';
    }

    printf("bahan               : %s baris · %s m · ujung bebas %s · cincin %d · komponen %s\n",
        angkaNum((float) $jumlahBaris), angkaNum($panjangAwal, 1),
        angkaNum((float) count($bebas)), $ringAwal, angkaNum((float) $kompAwal));
    printf("menempel (<=%s m)  : %d ujung sudah menyentuh segmen lain\n",
        angkaNum($toleransi, 2), $menempel);
    printf("tempel <=%s m/%s°  : %d ujung · %s m\n", angkaNum($rapat, 1), angkaNum($sudut, 0),
        count($tempel), angkaNum(array_sum(array_column($tempel, 'd')), 1));
    printf("jembatan <=%s m/%s°: %d · %s m\n", angkaNum($celah, 1), angkaNum($belok, 0),
        count($jembatan), angkaNum(array_sum(array_column($jembatan, 'd')), 1));
    printf("ditolak             : arah %d · silang %d · jembatan silang %d · cincin %d\n",
        $tolakArah, $tolakSilang, $tolakJembatanSilang, $tolakCincin);
    printf("sesudah             : ujung bebas %s · komponen %s · panjang %s -> %s m (+%s m)\n",
        angkaNum((float) max(0, $ujungSesudah)), angkaNum((float) $kompSesudah),
        angkaNum($panjangAwal, 1), angkaNum($panjangSesudah, 1), angkaNum($panjangSambungan, 1));
    printf("uji                 : baris %s · titik baru %d · titik asing %d · selisih panjang %s m%s\n",
        $jumlahBaris === count($fiturAsli) ? 'tetap' : 'BERUBAH', $titikBaru, $titikAsing,
        angkaNum($selisih, 3), $galat === [] ? '' : ' · GALAT: ' . implode('; ', $galat));

    if ($rinci) {
        foreach ($tempel as $t) {
            printf("  tempel  %-22s -> %-22s %5s m · sudut %s°\n", $t['kode'], $t['ke'],
                angkaNum($t['d'], 2), angkaNum($t['sudut'], 1));
        }
        foreach ($jembatan as $j) {
            printf("  jembatan %-22s -> %-22s %5s m\n", $j['kode'], $j['ke'],
                angkaNum($j['d'], 2));
        }
    }

    $ringkasan[$kunci] = [
        'ujung_bebas_sebelum' => count($bebas),
        'menempel' => $menempel,
        'tempel' => count($tempel),
        'jembatan' => count($jembatan),
        'ujung_bebas_sesudah' => max(0, $ujungSesudah),
        'komponen_sebelum' => $kompAwal,
        'komponen_sesudah' => $kompSesudah,
        'panjang_sebelum_m' => round($panjangAwal, 1),
        'panjang_sesudah_m' => round($panjangSesudah, 1),
        'galat' => $galat,
    ];

    if ($galat !== []) {
        fwrite(STDERR, "galat lapis $kunci; berkas TIDAK ditulis.\n");
        return;
    }
    if (count($tempel) === 0 && count($jembatan) === 0) {
        echo "tidak ada sambungan baru; berkas tidak ditulis.\n";
        return;
    }
    if (!$terapkan) {
        return;
    }

    // -- 6. tulis berkas + metadata ----------------------------------------
    $hasil = [];
    foreach ($fiturAsli as $i => $f) {
        $f['geometry']['coordinates'] = array_map(
            static fn(array $p): array => [$p[0], $p[1]],
            $garis[$i]['pts']
        );
        $f['properties'] = $garis[$i]['props'];
        $hasil[] = $f;
    }
    $naskah = json_encode(['type' => 'FeatureCollection', 'features' => $hasil],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($naskah === false) {
        galat('gagal menyusun JSON hasil.');
    }

    $cadang = __DIR__ . '/../_lama/cadangan-sambung-ujung-' . $kunci . '-' . $stempel;
    if (!is_dir($cadang) && !mkdir($cadang, 0775, true) && !is_dir($cadang)) {
        galat('gagal membuat folder cadangan.');
    }
    if (!copy($berkas, $cadang . '/' . basename($berkas))) {
        galat('gagal mencadangkan berkas lama.');
    }
    if (file_put_contents($berkas, $naskah) === false) {
        galat('gagal menulis ' . $berkas);
    }
    echo 'ditulis: files/sig-pertanahan-gedong-lokal/data/dxf-layers/'
        . $cfg['berkas'] . ".geojson\n";

    $judul = $cfg['judul'];
    if ($judul === null) {
        echo "metadata: lapis ini tidak punya blok metadata, dilewati.\n";
        return;
    }
    $berkasMeta = DIR_LAPISAN . '/metadata.json';
    $meta = json_decode((string) file_get_contents($berkasMeta), true);
    if (!is_array($meta) || !isset($meta['layers'][$judul])) {
        fwrite(STDERR, "peringatan: metadata.json tidak memuat $judul; metadata dilewati.\n");
        return;
    }
    copy($berkasMeta, $cadang . '/metadata-sebelum-' . $stempel . '.json');
    $l = $meta['layers'][$judul];
    $l['features'] = $jumlahBaris;
    $l['types'] = ['line' => $jumlahBaris];
    $l['panjang_m'] = (int) round($panjangSesudah);
    if ($kunci === 'tepi-saluran') {
        $pAcuan = 0.0;
        $pPasangan = 0.0;
        foreach ($garis as $g) {
            $len = panjangBaris($g['pts']);
            if (($g['props']['cara'] ?? '') === 'acuan') {
                $pAcuan += $len;
            } else {
                $pPasangan += $len;
            }
        }
        if (array_key_exists('panjang_acuan_m', $l)) {
            $l['panjang_acuan_m'] = round($pAcuan, 1);
        }
        if (array_key_exists('panjang_pasangan_m', $l)) {
            $l['panjang_pasangan_m'] = round($pPasangan, 1);
        }
        if (isset($l['acuan']['panjang_m'])) {
            $l['acuan']['panjang_m'] = round($pAcuan, 1);
        }
        if (isset($l['pasangan']['panjang_m'])) {
            $l['pasangan']['panjang_m'] = round($pPasangan, 1);
        }
    }
    $l['disambung_ujung'] = [
        'alat' => 'tools/sambung-ujung-garis.php',
        'pada' => date('c'),
        'aturan' => 'ujung bebas ditempel ke segmen garis lain bila jaraknya <= rapat_m dan arah '
            . 'tempel <= sudut_derajat; dua ujung bebas baris berbeda dijembatani bila jaraknya '
            . '<= celah_m dan arah sambungannya menerus <= belok_derajat; titik baru hanya '
            . 'proyeksi tegak lurus, tanpa cincin baru, sambungan yang memotong ditolak',
        'rapat_m' => $rapat,
        'celah_m' => $celah,
        'sudut_derajat' => $sudut,
        'belok_derajat' => $belok,
        'toleransi_m' => $toleransi,
        'ujung_bebas_sebelum' => count($bebas),
        'menempel' => $menempel,
        'tempel' => count($tempel),
        'tempel_m' => round(array_sum(array_column($tempel, 'd')), 2),
        'jembatan' => count($jembatan),
        'jembatan_m' => round(array_sum(array_column($jembatan, 'd')), 2),
        'ujung_bebas_sesudah' => max(0, $ujungSesudah),
        'komponen_sebelum' => $kompAwal,
        'komponen_sesudah' => $kompSesudah,
        'baris' => $jumlahBaris,
        'panjang_sebelum_m' => round($panjangAwal, 2),
        'panjang_sesudah_m' => round($panjangSesudah, 2),
        'titik_baru' => $titikBaru,
        'ditolak' => ['arah' => $tolakArah, 'silang' => $tolakSilang,
            'jembatan_silang' => $tolakJembatanSilang, 'cincin' => $tolakCincin],
        'diterapkan' => $stempel,
    ];
    $meta['layers'][$judul] = $l;
    $meta['derived_at'] = date('c');
    if (file_put_contents($berkasMeta, json_encode($meta,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
        galat('gagal menulis metadata.json');
    }
    echo "metadata.json diperbarui: $judul · cadangan _lama/" . basename($cadang) . "\n";
};

foreach ($daftarLapis as $kunci) {
    $proses($kunci, LAPIS[$kunci]);
}

// ---------------------------------------------------------------------------
// Ringkasan
// ---------------------------------------------------------------------------

echo "\n" . str_repeat('=', 78) . "\nRINGKASAN\n";
printf("%-15s %8s %8s %8s %8s %10s %10s %13s\n", 'lapis', 'bebas', 'menempel', 'tempel',
    'jembatan', 'bebas akhir', 'komponen', 'panjang m');
foreach ($ringkasan as $kunci => $r) {
    printf("%-15s %8s %8s %8s %8s %10s %10s %13s\n", $kunci,
        angkaNum((float) $r['ujung_bebas_sebelum']), angkaNum((float) $r['menempel']),
        angkaNum((float) $r['tempel']), angkaNum((float) $r['jembatan']),
        angkaNum((float) $r['ujung_bebas_sesudah']),
        angkaNum((float) $r['komponen_sebelum']) . '->' . angkaNum((float) $r['komponen_sesudah']),
        angkaNum($r['panjang_sebelum_m'], 1) . '->' . angkaNum($r['panjang_sesudah_m'], 1));
}
if ($terapkan) {
    echo "DITERAPKAN: berkas lapis yang punya sambungan baru sudah ditulis ulang.\n";
} else {
    echo "UJI KERING: tidak ada berkas yang ditulis. Tambahkan --terapkan untuk menulis.\n";
}
