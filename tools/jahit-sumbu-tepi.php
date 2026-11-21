<?php
declare(strict_types=1);

/**
 * Jahit celah sumbu sungai yang bisa disusur dari tepi perairan.
 *
 *   php -d memory_limit=1024M tools/jahit-sumbu-tepi.php            # uji kering
 *   php -d memory_limit=1024M tools/jahit-sumbu-tepi.php --terapkan
 *   php -d memory_limit=1024M tools/jahit-sumbu-tepi.php --celah=150 --rinci
 *
 *   --celah=METER      jarak maksimum dua ujung bebas yang dicoba disusur (bawaan 150)
 *   --rinci            cetak semua polilin hasil
 *   --keluaran=BERKAS  nama berkas (tanpa .geojson), bawaan garis-sungai-jahit
 *   --terapkan         tulis berkas keluaran; tanpa ini tidak ada yang ditulis
 *
 * Bahan. Lapis sumbu terbit (garis-sungai-jahit.geojson, 41 polilin, 82 ujung
 * bebas) dan lapis tepi perairan (garis-perairan.geojson, 143 rantai). Ujung
 * bebas dipasangkan berpasangan terdekat sampai --celah, lalu tiap pasangan
 * dicoba disusuri: langkah 2 m mengikuti koridor dua tepi, titik tengah
 * pasangan tepi kiri-kanan menjadi posisi baru, arah dikoreksi 60% ke titik
 * tengah. Pasangan tepi dianggap sah bila masing-masing <= 15 m, kedua tepi
 * sejajar satu sama lain (|cos| >= 0,7), masing-masing membentang di samping
 * titik (|cos(arah tepi, offset)| <= 0,6), kedua offset berlawanan arah
 * (<= -0,4), dan lebar 3-60 m; dari pasangan sah diambil lebar terkecil.
 * Tiap langkah boleh membelok sampai 40 derajat dari arah sekarang. Susuran
 * berhasil bila sampai <= 3 m dari ujung B tanpa tersangkut lebih dari 3
 * langkah dan tanpa menjauh lebih dari 100 m dari penghubung lurus.
 *
 * Yang disambung hanya susuran yang berhasil. Jejak susuran dipakai apa
 * adanya, ditambah satu ruas penutup <= 3 m ke ujung B, lalu dua polilin
 * digabung urut: rantai menuju A, jejak A-B, rantai dari B. Titik sambung
 * yang berimpit dibuang; tidak ada titik bahan yang hilang dan tidak ada
 * koordinat karangan selain jejak susuran. Sudut sambung di A dan B wajib
 * <= 90 derajat supaya tidak lahir huruf U.
 *
 * Yang TIDAK dilakukan: menurunkan sumbu baru di bentang tanpa label. Celah
 * yang tepinya tidak bisa disusuri dibiarkan dua garis; menurut pengukuran
 * 21 Nov 2026 hanya 3 dari 37 celah <= 150 m yang benar-benar bisa disusur
 * (detail: README §3.110).
 *
 * Menjalankan alat ini dua kali tidak menambah apa-apa: celah yang sudah
 * tersambung tidak lagi punya dua ujung bebas, jadi tidak ada pasangan baru.
 */

require_once __DIR__ . '/lib-impor.php';

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_TEPI = 'garis-perairan';
const BERKAS_MASUK = 'garis-sungai-jahit';
const SEL_TEPI = 20.0;
const TEPI_MAKS = 15.0;
const LEBAR_MIN = 3.0;
const LEBAR_MAKS = 60.0;
const SAMING_MAKS = 0.6;
const SEARAH_MIN = 0.7;
const LAWAN_MAKS = -0.4;
const LANGKAH = 2.0;
const TARIK = 0.6;
const SAMPAI_M = 3.0;
const TERSANGKUT_MAKS = 3;
const LANGKAH_MAKS = 600;
const MENJAUH_MAKS = 100.0;
const BELOK_SUSUR = 40.0;
const BELOK_SAMBUNG = 90.0;
const SIMPUL_M = 0.35;
const RAPAT_M = 0.05;

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

function panjangTitik(array $titik): float
{
    $jml = 0.0;
    for ($i = 1, $n = count($titik); $i < $n; $i++) {
        $jml += jarak($titik[$i - 1][0], $titik[$i - 1][1], $titik[$i][0], $titik[$i][1]);
    }

    return $jml;
}

/** Buang titik yang berimpit dengan titik sebelumnya (<= RAPAT_M). */
function rapatkan(array $titik): array
{
    $hasil = [];
    foreach ($titik as $t) {
        $akhir = $hasil === [] ? null : $hasil[count($hasil) - 1];
        if ($akhir !== null && jarak($akhir[0], $akhir[1], $t[0], $t[1]) <= RAPAT_M) {
            continue;
        }
        $hasil[] = $t;
    }

    return $hasil;
}

/** Semua rantai garis dari satu fitur (LineString atau MultiLineString). */
function garisDari(array $f): array
{
    $g = $f['geometry'] ?? null;
    if (!is_array($g)) {
        return [];
    }
    $jenis = $g['type'] ?? '';
    if ($jenis === 'LineString') {
        return [$g['coordinates']];
    }
    if ($jenis === 'MultiLineString') {
        return $g['coordinates'];
    }

    return [];
}

function kunciKisi(int $gx, int $gy): string
{
    return $gx . ':' . $gy;
}

/** Titik terdekat pada satu ruas: [jarak, qx, qy]. */
function dekatRuas(float $px, float $py, float $ax, float $ay, float $bx, float $by): array
{
    $vx = $bx - $ax;
    $vy = $by - $ay;
    $vv = $vx * $vx + $vy * $vy;
    $t = $vv > 0.0 ? (($px - $ax) * $vx + ($py - $ay) * $vy) / $vv : 0.0;
    $t = max(0.0, min(1.0, $t));
    $qx = $ax + $t * $vx;
    $qy = $ay + $t * $vy;

    return [hypot($px - $qx, $py - $qy), $qx, $qy];
}

/**
 * Pasangan tepi kiri-kanan yang sah untuk satu titik pada arah jalan.
 * Kembalikan [xTengah, yTengah, lebar] atau null.
 */
function pasanganTepi(float $px, float $py, float $ux, float $uy, array $ruas, array $kisi): ?array
{
    $gx = (int) floor($px / SEL_TEPI);
    $gy = (int) floor($py / SEL_TEPI);
    $kiri = [];
    $kanan = [];
    $sudah = [];
    for ($ox = -1; $ox <= 1; $ox++) {
        for ($oy = -1; $oy <= 1; $oy++) {
            $k = kunciKisi($gx + $ox, $gy + $oy);
            if (!isset($kisi[$k])) {
                continue;
            }
            foreach ($kisi[$k] as $idx) {
                if (isset($sudah[$idx])) {
                    continue;
                }
                $sudah[$idx] = true;
                $r = $ruas[$idx];
                if ($px < min($r[0], $r[2]) - TEPI_MAKS || $px > max($r[0], $r[2]) + TEPI_MAKS
                    || $py < min($r[1], $r[3]) - TEPI_MAKS || $py > max($r[1], $r[3]) + TEPI_MAKS) {
                    continue;
                }
                [$d, $qx, $qy] = dekatRuas($px, $py, $r[0], $r[1], $r[2], $r[3]);
                if ($d > TEPI_MAKS || $d <= RAPAT_M) {
                    continue;
                }
                $samping = abs((($qx - $px) * $r[4] + ($qy - $py) * $r[5]) / $d);
                if ($samping > SAMING_MAKS) {
                    continue;
                }
                $sisi = $ux * ($qy - $py) - $uy * ($qx - $px);
                $calon = [$d, $qx, $qy, $r[4], $r[5]];
                if ($sisi > 0.0) {
                    $kiri[] = $calon;
                } elseif ($sisi < 0.0) {
                    $kanan[] = $calon;
                }
            }
        }
    }
    if (!$kiri || !$kanan) {
        return null;
    }
    usort($kiri, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    usort($kanan, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $terbaik = null;
    $lebarTerbaik = INF;
    foreach (array_slice($kiri, 0, 6) as $l) {
        foreach (array_slice($kanan, 0, 6) as $r) {
            $w = $l[0] + $r[0];
            if ($w < LEBAR_MIN || $w > LEBAR_MAKS || $w >= $lebarTerbaik) {
                continue;
            }
            if (abs($l[3] * $r[3] + $l[4] * $r[4]) < SEARAH_MIN) {
                continue;
            }
            $olx = ($l[1] - $px) / $l[0];
            $oly = ($l[2] - $py) / $l[0];
            $orx = ($r[1] - $px) / $r[0];
            $ory = ($r[2] - $py) / $r[0];
            if ($olx * $orx + $oly * $ory > LAWAN_MAKS) {
                continue;
            }
            $lebarTerbaik = $w;
            $terbaik = [($l[1] + $r[1]) / 2, ($l[2] + $r[2]) / 2, $w];
        }
    }

    return $terbaik;
}

/**
 * Susuri koridor dari A ke B. Kembalikan jejak, panjang, dan sebab gagal.
 *
 * @return array{jejak: array<int, array{0: float, 1: float}>, panjang: float,
 *               sampai: bool, alasan: string, belok: int, lebar: array<int, float>}
 */
function susurKoridor(array $a, array $b, array $ruas, array $kisi): array
{
    [$x1, $y1] = $a;
    [$x2, $y2] = $b;
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $L = hypot($dx, $dy);
    if ($L <= 0.0) {
        return ['jejak' => [], 'panjang' => 0.0, 'sampai' => false,
            'alasan' => 'dua ujung berimpit', 'belok' => 0, 'lebar' => []];
    }
    $ux = $dx / $L;
    $uy = $dy / $L;

    $px = $x1;
    $py = $y1;
    $arahX = $ux;
    $arahY = $uy;
    $jejak = [[$px, $py]];
    $panjang = 0.0;
    $belok = 0;
    $lebar = [];
    $tersangkut = 0;
    $alasan = '';
    $sampai = false;

    for ($langkah = 0; $langkah < LANGKAH_MAKS; $langkah++) {
        $kandidat = null;
        $sudutTerbaik = 0.0;
        for ($s = 0; $s <= 8; $s++) {
            $selisih = ($s - 4) * 10.0 * M_PI / 180.0;
            $kx = $arahX * cos($selisih) - $arahY * sin($selisih);
            $ky = $arahX * sin($selisih) + $arahY * cos($selisih);
            $cx = $px + LANGKAH * $kx;
            $cy = $py + LANGKAH * $ky;
            $pair = pasanganTepi($cx, $cy, $kx, $ky, $ruas, $kisi);
            if ($pair === null) {
                continue;
            }
            $skor = abs($selisih);
            if ($kandidat === null || $skor < $sudutTerbaik) {
                $sudutTerbaik = $skor;
                $kandidat = [$cx, $cy, $pair, $kx, $ky];
            }
        }

        if ($kandidat === null) {
            $tersangkut++;
            if ($tersangkut > TERSANGKUT_MAKS) {
                $alasan = 'koridor putus di langkah ' . $langkah;
                break;
            }
            $cx = $px + LANGKAH * $arahX;
            $cy = $py + LANGKAH * $arahY;
            $panjang += LANGKAH;
            $px = $cx;
            $py = $cy;
            $jejak[] = [$px, $py];
        } else {
            $tersangkut = 0;
            [$cx, $cy, $pair, $kx, $ky] = $kandidat;
            $nx = $cx + TARIK * ($pair[0] - $cx);
            $ny = $cy + TARIK * ($pair[1] - $cy);
            $baru = hypot($nx - $px, $ny - $py);
            $panjang += $baru;
            if ($baru > RAPAT_M) {
                $arahX = ($nx - $px) / $baru;
                $arahY = ($ny - $py) / $baru;
                if ($arahX * $ux + $arahY * $uy < 0.0) {
                    $alasan = 'arah berbalik di langkah ' . $langkah;
                    break;
                }
                if (abs($arahX * $kx + $arahY * $ky) < 0.98) {
                    $belok++;
                }
            }
            $px = $nx;
            $py = $ny;
            $lebar[] = $pair[2];
            $jejak[] = [$px, $py];
        }

        if (jarak($px, $py, $x2, $y2) <= SAMPAI_M) {
            $sampai = true;
            break;
        }
        if (jarak($px, $py, $x1, $y1) > $L + 2 * MENJAUH_MAKS) {
            $alasan = 'menjauh dari ujung B';
            break;
        }
    }

    return ['jejak' => $jejak, 'panjang' => $panjang, 'sampai' => $sampai,
        'alasan' => $alasan, 'belok' => $belok, 'lebar' => $lebar];
}

$berkasKeluar = 'garis-sungai-jahit';
$celahMaks = 150.0;
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
            galat('--celah harus angka meter, contoh --celah=150');
        }
        $celahMaks = (float) str_replace(',', '.', $isi);
    } elseif (str_starts_with($arg, '--keluaran=')) {
        $berkasKeluar = trim(substr($arg, 11));
        if ($berkasKeluar === '' || !preg_match('/^[a-z0-9-]+$/', $berkasKeluar)) {
            galat('--keluaran harus huruf kecil, angka, dan tanda hubung');
        }
    } else {
        galat('argumen tidak dikenal: ' . $arg);
    }
}
if ($celahMaks <= 0.0) {
    galat('--celah harus lebih besar dari nol');
}

// ---------------------------------------------------------------------------
// Bahan tepi perairan
// ---------------------------------------------------------------------------

$berkasTepi = DIR_LAPISAN . '/' . BERKAS_TEPI . '.geojson';
$fiturTepi = geojsonBaca($berkasTepi);
$ruasTepi = [];
$kisi = [];
foreach ($fiturTepi as $f) {
    foreach (garisDari($f) as $c) {
        for ($i = 0; $i + 1 < count($c); $i++) {
            $ax = (float) $c[$i][0];
            $ay = (float) $c[$i][1];
            $bx = (float) $c[$i + 1][0];
            $by = (float) $c[$i + 1][1];
            $pl = hypot($bx - $ax, $by - $ay);
            if ($pl <= RAPAT_M) {
                continue;
            }
            $idx = count($ruasTepi);
            $ruasTepi[] = [$ax, $ay, $bx, $by, ($bx - $ax) / $pl, ($by - $ay) / $pl];
            $bagian = max(1, (int) ceil($pl / (SEL_TEPI / 2)));
            for ($s = 0; $s <= $bagian; $s++) {
                $t = $s / $bagian;
                $kunci = kunciKisi(
                    (int) floor(($ax + $t * ($bx - $ax)) / SEL_TEPI),
                    (int) floor(($ay + $t * ($by - $ay)) / SEL_TEPI));
                $kisi[$kunci][$idx] = true;
            }
        }
    }
}
foreach ($kisi as $k => $daftar) {
    $kisi[$k] = array_keys($daftar);
}
printf("bahan tepi: %d fitur · %d ruas\n", count($fiturTepi), count($ruasTepi));

// ---------------------------------------------------------------------------
// Bahan sumbu
// ---------------------------------------------------------------------------

$berkasMasuk = DIR_LAPISAN . '/' . BERKAS_MASUK . '.geojson';
$fiturMasuk = geojsonBaca($berkasMasuk);
$isiMasuk = json_decode((string) file_get_contents($berkasMasuk), true);
if (!is_array($isiMasuk)) {
    galat('gagal membaca metadata ' . basename($berkasMasuk));
}
$metaAsli = is_array($isiMasuk['metadata'] ?? null) ? $isiMasuk['metadata'] : [];

$fitur = [];
$panjangMasuk = 0.0;
foreach ($fiturMasuk as $f) {
    if (($f['geometry']['type'] ?? '') !== 'LineString') {
        galat('bahan sumbu memuat geometri selain LineString');
    }
    $koor = [];
    foreach (($f['geometry']['coordinates'] ?? []) as $p) {
        if (!is_array($p) || count($p) < 2) {
            galat('koordinat sumbu tidak sah');
        }
        $koor[] = [(float) $p[0], (float) $p[1]];
    }
    if (count($koor) < 2) {
        galat('baris sumbu kurang dari dua titik');
    }
    $panjang = panjangTitik($koor);
    $panjangMasuk += $panjang;
    $fitur[] = ['titik' => $koor, 'panjang' => $panjang,
        'pro' => is_array($f['properties'] ?? null) ? $f['properties'] : []];
}
printf("bahan sumbu: %d polilin · %s m\n", count($fitur), angkaNum($panjangMasuk, 1));

$ujung = [];
foreach ($fitur as $fi => $f) {
    $c = $f['titik'];
    $n = count($c);
    if (jarak($c[0][0], $c[0][1], $c[$n - 1][0], $c[$n - 1][1]) <= SIMPUL_M) {
        continue;
    }
    $ujung[] = ['x' => $c[0][0], 'y' => $c[0][1], 'fitur' => $fi, 'e' => 0];
    $ujung[] = ['x' => $c[$n - 1][0], 'y' => $c[$n - 1][1], 'fitur' => $fi, 'e' => 1];
}
printf("ujung bebas: %d\n", count($ujung));

// ---------------------------------------------------------------------------
// 1. Pasangan ujung bebas <= --celah (terpendek didahulukan)
// ---------------------------------------------------------------------------

$kandidat = [];
for ($i = 0, $m = count($ujung); $i < $m; $i++) {
    for ($j = $i + 1; $j < $m; $j++) {
        if ($ujung[$i]['fitur'] === $ujung[$j]['fitur']) {
            continue;
        }
        $d = jarak($ujung[$i]['x'], $ujung[$i]['y'], $ujung[$j]['x'], $ujung[$j]['y']);
        if ($d <= $celahMaks) {
            $kandidat[] = [$d, $i, $j];
        }
    }
}
usort($kandidat, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
$pakai = [];
$pasangan = [];
foreach ($kandidat as [$d, $i, $j]) {
    if (isset($pakai[$i]) || isset($pakai[$j])) {
        continue;
    }
    $pakai[$i] = true;
    $pakai[$j] = true;
    $pasangan[] = [$i, $j, $d];
}
printf("pasangan celah <= %s m: %d\n", angkaNum($celahMaks), count($pasangan));

// ---------------------------------------------------------------------------
// 2. Susuri tiap pasangan
// ---------------------------------------------------------------------------

printf("%-4s %8s %8s %8s %6s %7s  %s\n", 'no', 'celah', 'susur', 'lebar', 'belok',
    'belokA/B', 'hasil');
$sukses = [];
$gagal = [];
foreach ($pasangan as $no => [$i, $j, $jarakUjung]) {
    $a = [$ujung[$i]['x'], $ujung[$i]['y']];
    $b = [$ujung[$j]['x'], $ujung[$j]['y']];
    $hasil = susurKoridor($a, $b, $ruasTepi, $kisi);

    $lebarMed = 0.0;
    if ($hasil['lebar'] !== []) {
        $w = $hasil['lebar'];
        sort($w);
        $lebarMed = $w[(int) floor(count($w) / 2)];
    }

    $jejak = $hasil['jejak'];
    $akhir = $jejak === [] ? $a : $jejak[count($jejak) - 1];
    $tutup = jarak($akhir[0], $akhir[1], $b[0], $b[1]);
    $alasan = $hasil['sampai'] && $hasil['alasan'] === '' ? '' : ($hasil['alasan'] ?: 'belum sampai B');
    if ($alasan === '' && count($jejak) < 2) {
        $alasan = 'jejak kurang dari dua titik';
    }
    if ($alasan !== '') {
        $gagal[] = ['no' => $no + 1, 'celah' => $jarakUjung, 'alasan' => $alasan,
            'panjang_susur' => $hasil['panjang'], 'langkah' => count($jejak)];
        printf("%-4d %8s %8s %8s %6d %8s  %s\n", $no + 1, angkaNum($jarakUjung, 1),
            '-', $lebarMed > 0.0 ? angkaNum($lebarMed, 1) : '-', $hasil['belok'], '-', $alasan);

        continue;
    }

    // Sudut sambung di A: arah keluar rantai A vs arah jejak di A.
    $fa = $fitur[$ujung[$i]['fitur']]['titik'];
    $fb = $fitur[$ujung[$j]['fitur']]['titik'];
    $na = count($fa);
    $nb = count($fb);
    $keluarA = $ujung[$i]['e'] === 0 ? arah($fa[1], $fa[0]) : arah($fa[$na - 2], $fa[$na - 1]);
    $keluarB = $ujung[$j]['e'] === 0 ? arah($fb[1], $fb[0]) : arah($fb[$nb - 2], $fb[$nb - 1]);
    $belokA = belokan($keluarA, arah($a, $jejak[1]));
    // Di B jejak masuk ke badan rantai, jadi arah kedatangan harus melawan
    // arah keluar B (yang menunjuk menjauh dari badan rantai).
    $belokB = belokan(arah($akhir, $b), [-$keluarB[0], -$keluarB[1]]);
    if ($belokA > BELOK_SAMBUNG || $belokB > BELOK_SAMBUNG) {
        $gagal[] = ['no' => $no + 1, 'celah' => $jarakUjung,
            'alasan' => sprintf('sudut sambung %s/%s derajat', angkaNum($belokA), angkaNum($belokB)),
            'panjang_susur' => $hasil['panjang'], 'langkah' => count($jejak)];
        printf("%-4d %8s %8s %8s %6d %8s  sudut sambung terlalu tajam\n", $no + 1,
            angkaNum($jarakUjung, 1), angkaNum($hasil['panjang'], 1),
            $lebarMed > 0.0 ? angkaNum($lebarMed, 1) : '-', $hasil['belok'],
            angkaNum($belokA) . '/' . angkaNum($belokB));

        continue;
    }

    $sukses[] = ['i' => $i, 'j' => $j, 'celah' => $jarakUjung, 'jejak' => $jejak,
        'panjang_susur' => $hasil['panjang'], 'tutup' => $tutup, 'lebar' => $lebarMed,
        'belok' => $hasil['belok'], 'belokA' => $belokA, 'belokB' => $belokB];
    printf("%-4d %8s %8s %8s %6d %8s  tersusur\n", $no + 1, angkaNum($jarakUjung, 1),
        angkaNum($hasil['panjang'], 1), $lebarMed > 0.0 ? angkaNum($lebarMed, 1) : '-',
        $hasil['belok'], angkaNum($belokA) . '/' . angkaNum($belokB));
}

$jumlahSusur = count($sukses);
$meterCelah = array_sum(array_column($sukses, 'celah'));
printf("\ntersusur: %d dari %d pasangan · celah %s m\n", $jumlahSusur, count($pasangan),
    angkaNum($meterCelah, 1));
if ($sukses === []) {
    echo "\ntidak ada celah yang bisa disusur: tidak ada yang ditulis.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// 3. Rakit: polilin bahan + jejak susuran sebagai ruas, lalu telusuri
// ---------------------------------------------------------------------------

$ruas = [];
$idFitur = [];
foreach ($fitur as $fi => $f) {
    $idFitur[$fi] = count($ruas);
    $ruas[] = ['a' => 'f' . $fi . ':0', 'b' => 'f' . $fi . ':1', 'titik' => $f['titik'],
        'jenis' => 'bahan', 'fitur' => $fi, 'panjang' => $f['panjang'],
        'jembatan' => (int) ($f['pro']['jembatan'] ?? 0),
        'jembatan_m' => (float) ($f['pro']['jembatan_m'] ?? 0.0),
        'simpul' => (int) ($f['pro']['simpul'] ?? 0),
        'simpul_m' => (float) ($f['pro']['simpul_m'] ?? 0.0),
        'rantai' => is_array($f['pro']['rantai'] ?? null) ? $f['pro']['rantai'] : [],
        'label' => is_array($f['pro']['label'] ?? null) ? $f['pro']['label'] : []];
}
$pasangRuas = [];
$panjangSusur = 0.0;
foreach ($sukses as $s) {
    $a = [$ujung[$s['i']]['x'], $ujung[$s['i']]['y']];
    $b = [$ujung[$s['j']]['x'], $ujung[$s['j']]['y']];
    $titik = rapatkan($s['jejak']);
    $akhir = $titik[count($titik) - 1];
    if (jarak($akhir[0], $akhir[1], $b[0], $b[1]) > RAPAT_M) {
        $titik[] = $b;
    }
    $panjang = panjangTitik($titik);
    $panjangSusur += $panjang;
    $id = count($ruas);
    $ruas[] = ['a' => 'f' . $ujung[$s['i']]['fitur'] . ':' . $ujung[$s['i']]['e'],
        'b' => 'f' . $ujung[$s['j']]['fitur'] . ':' . $ujung[$s['j']]['e'],
        'titik' => $titik, 'jenis' => 'tepi', 'panjang' => $panjang];
    // Dua ujung ini hanya boleh punya satu sambungan: pasangan greedy menjaminnya.
    $simpulA = 'f' . $ujung[$s['i']]['fitur'] . ':' . $ujung[$s['i']]['e'];
    $simpulB = 'f' . $ujung[$s['j']]['fitur'] . ':' . $ujung[$s['j']]['e'];
    $polA = $idFitur[$ujung[$s['i']]['fitur']];
    $polB = $idFitur[$ujung[$s['j']]['fitur']];
    if (isset($pasangRuas[$simpulA]) || isset($pasangRuas[$simpulB])) {
        galat('satu ujung dipakai dua kali, pasangan tidak sah');
    }
    $pasangRuas[$simpulA][$polA] = $id;
    $pasangRuas[$simpulA][$id] = $polA;
    $pasangRuas[$simpulB][$polB] = $id;
    $pasangRuas[$simpulB][$id] = $polB;
}

/** Titik ruas menurut arah jalan: dari simpul $dari. */
$jalur = static function (array $r, string $dari): array
{
    return $r['a'] === $dari ? $r['titik'] : array_reverse($r['titik']);
};

$terpakai = [];
$polilin = [];
foreach ($ruas as $idAwal => $rAwal) {
    if (isset($terpakai[$idAwal])) {
        continue;
    }
    $terpakai[$idAwal] = true;
    $titik = $jalur($rAwal, $rAwal['a']);
    $rantai = [];
    $label = [];
    $jumlahRuasBahan = 0;
    $jumlahJembatan = 0;
    $panjangJembatan = 0.0;
    $jumlahSimpul = 0;
    $panjangSimpul = 0.0;
    $jumlahTepi = 0;
    $panjangTepi = 0.0;
    $serap = static function (array $r) use (&$rantai, &$label, &$jumlahRuasBahan,
        &$jumlahJembatan, &$panjangJembatan, &$jumlahSimpul, &$panjangSimpul,
        &$jumlahTepi, &$panjangTepi): void {
        if ($r['jenis'] === 'tepi') {
            $jumlahTepi++;
            $panjangTepi += $r['panjang'];

            return;
        }
        $jumlahRuasBahan++;
        $jumlahJembatan += $r['jembatan'];
        $panjangJembatan += $r['jembatan_m'];
        $jumlahSimpul += $r['simpul'];
        $panjangSimpul += $r['simpul_m'];
        foreach ($r['rantai'] as $k) {
            $rantai[(string) $k] = true;
        }
        foreach ($r['label'] as $k) {
            $label[(string) $k] = true;
        }
    };
    $serap($rAwal);
    $simpulAwal = $rAwal['a'];
    $simpulAkhir = $rAwal['b'];

    foreach ([true, false] as $maju) {
        $simpulKini = $maju ? $simpulAkhir : $simpulAwal;
        $idKini = $idAwal;
        while (($idLanjut = $pasangRuas[$simpulKini][$idKini] ?? null) !== null) {
            if (isset($terpakai[$idLanjut])) {
                break;
            }
            $lanjut = $ruas[$idLanjut];
            $dariSimpul = $lanjut['a'] === $simpulKini;
            if (!$dariSimpul && $lanjut['b'] !== $simpulKini) {
                break;
            }
            $simpulBaru = $dariSimpul ? $lanjut['b'] : $lanjut['a'];
            $potong = $jalur($lanjut, $maju ? $simpulKini : $simpulBaru);
            // Titik sambung yang berimpit dengan titik tepi yang sudah ada dibuang.
            if ($maju) {
                $akhirTitik = $titik[count($titik) - 1];
                if (jarak($akhirTitik[0], $akhirTitik[1], $potong[0][0], $potong[0][1]) <= RAPAT_M) {
                    array_shift($potong);
                }
            } else {
                $awalTitik = $titik[0];
                $akhirPotong = $potong[count($potong) - 1];
                if (jarak($akhirPotong[0], $akhirPotong[1], $awalTitik[0], $awalTitik[1]) <= RAPAT_M) {
                    array_pop($potong);
                }
            }
            $terpakai[$idLanjut] = true;
            $serap($lanjut);
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
        'rantai' => array_keys($rantai),
        'label' => array_keys($label),
        'ruas' => $jumlahRuasBahan,
        'jembatan' => $jumlahJembatan,
        'jembatan_m' => $panjangJembatan,
        'simpul' => $jumlahSimpul,
        'simpul_m' => $panjangSimpul,
        'tepi' => $jumlahTepi,
        'tepi_m' => $panjangTepi,
        'panjang' => panjangTitik($titik),
    ];
}

usort($polilin, static fn(array $a, array $b): int => $b['panjang'] <=> $a['panjang']);
$panjangKeluaran = array_sum(array_column($polilin, 'panjang'));
$jumlahTitik = array_sum(array_map(static fn(array $p): int => count($p['titik']), $polilin));
printf("polilin: %d · %s m · %d titik · susuran %d/%s m\n", count($polilin),
    angkaNum($panjangKeluaran, 1), $jumlahTitik, $jumlahSusur, angkaNum($panjangSusur, 1));

// ---------------------------------------------------------------------------
// Verifikasi
// ---------------------------------------------------------------------------

// a. Titik bahan tidak hilang. Hitung kemunculan tiap koordinat.
$kunciTitik = static fn(array $t): string => sprintf('%.7f:%.7f', $t[0], $t[1]);
$bahanKali = [];
foreach ($fitur as $f) {
    foreach ($f['titik'] as $t) {
        $k = $kunciTitik($t);
        $bahanKali[$k] = ($bahanKali[$k] ?? 0) + 1;
    }
}
$keluarKali = [];
foreach ($polilin as $p) {
    foreach ($p['titik'] as $t) {
        $k = $kunciTitik($t);
        $keluarKali[$k] = ($keluarKali[$k] ?? 0) + 1;
    }
}
$hilang = 0;
foreach ($bahanKali as $k => $kali) {
    if (($keluarKali[$k] ?? 0) < $kali) {
        $hilang++;
    }
}
printf("verifikasi titik: %d titik bahan · %d titik keluaran · koordinat bahan yang hilang %d\n",
    array_sum($bahanKali), $jumlahTitik, $hilang);
if ($hilang > 0) {
    galat('ada titik bahan yang hilang dari keluaran');
}

// b. Titik baru hanya boleh berasal dari jejak susuran.
$titikSusur = [];
foreach ($sukses as $s) {
    foreach ($s['jejak'] as $t) {
        $titikSusur[$kunciTitik($t)] = true;
    }
}
$baru = 0;
foreach ($keluarKali as $k => $kali) {
    $kelebihan = $kali - ($bahanKali[$k] ?? 0);
    if ($kelebihan > 0 && !isset($titikSusur[$k])) {
        $baru++;
    }
}
printf("verifikasi titik baru: %d koordinat yang bukan titik bahan maupun jejak susuran\n", $baru);
if ($baru > 0) {
    galat('ada titik keluaran yang bukan titik bahan maupun jejak susuran');
}

// c. Panjang: bahan + jejak - titik sambung yang dibuang.
$selisih = $panjangKeluaran - ($panjangMasuk + $panjangSusur);
printf("verifikasi panjang: bahan %s + susur %s = %s m · keluaran %s m · beda %s m\n",
    angkaNum($panjangMasuk, 1), angkaNum($panjangSusur, 1),
    angkaNum($panjangMasuk + $panjangSusur, 1), angkaNum($panjangKeluaran, 1),
    angkaNum($selisih, 2));
if (abs($selisih) > $jumlahSusur * 2 * RAPAT_M + 0.05) {
    galat('panjang keluaran menyimpang dari bahan ditambah susuran');
}

// d. Tiap polilin bahan tetap utuh dan berurutan di keluarannya.
$cari = static function (array $cariTitik) use ($polilin, $kunciTitik): bool
{
    $n = count($cariTitik);
    foreach ($polilin as $p) {
        $q = $p['titik'];
        $m = count($q);
        for ($k = 0; $k + 1 < $m; $k++) {
            if ($kunciTitik($q[$k]) !== $kunciTitik($cariTitik[0])) {
                continue;
            }
            if ($kunciTitik($q[$k + 1]) !== $kunciTitik($cariTitik[1])) {
                continue;
            }
            if ($k + $n > $m) {
                continue;
            }
            $cocok = true;
            for ($x = 0; $x < $n; $x++) {
                if ($kunciTitik($q[$k + $x]) !== $kunciTitik($cariTitik[$x])) {
                    $cocok = false;
                    break;
                }
            }
            if ($cocok) {
                return true;
            }
        }
    }

    return false;
};
$tidakUtuh = 0;
foreach ($fitur as $f) {
    $maju = $cari($f['titik']);
    if (!$maju && !$cari(array_reverse($f['titik']))) {
        $tidakUtuh++;
    }
}
printf("verifikasi urutan: %d polilin bahan yang tidak muncul utuh · %d utuh\n",
    $tidakUtuh, count($fitur) - $tidakUtuh);
if ($tidakUtuh > 0) {
    galat('ada polilin bahan yang tidak muncul utuh dan berurutan');
}

// e. Jarak jejak baru ke tepi terdekat.
$terjauh = 0.0;
foreach ($sukses as $s) {
    foreach ($s['jejak'] as $t) {
        $terdekat = INF;
        $gx = (int) floor($t[0] / SEL_TEPI);
        $gy = (int) floor($t[1] / SEL_TEPI);
        $sudah = [];
        for ($ox = -1; $ox <= 1; $ox++) {
            for ($oy = -1; $oy <= 1; $oy++) {
                foreach ($kisi[kunciKisi($gx + $ox, $gy + $oy)] ?? [] as $idx) {
                    if (isset($sudah[$idx])) {
                        continue;
                    }
                    $sudah[$idx] = true;
                    $r = $ruasTepi[$idx];
                    $terdekat = min($terdekat, dekatRuas($t[0], $t[1], $r[0], $r[1], $r[2], $r[3])[0]);
                }
            }
        }
        if (is_finite($terdekat)) {
            $terjauh = max($terjauh, $terdekat);
        }
    }
}
printf("verifikasi koridor: titik jejak terjauh dari tepi %s m (batas %s m)\n",
    angkaNum($terjauh, 1), angkaNum(TEPI_MAKS, 0));
if ($terjauh > TEPI_MAKS + 0.01) {
    galat('ada titik jejak yang lebih jauh dari batas tepi');
}

// Sisa ujung bebas per pita jarak.
$ujungKeluar = [];
foreach ($polilin as $pi => $p) {
    $c = $p['titik'];
    $n = count($c);
    if (jarak($c[0][0], $c[0][1], $c[$n - 1][0], $c[$n - 1][1]) <= SIMPUL_M) {
        continue;
    }
    $ujungKeluar[] = [$pi, $c[0]];
    $ujungKeluar[] = [$pi, $c[$n - 1]];
}
$pita = ['<=2 m' => 0, '2-10 m' => 0, '10-50 m' => 0, '>50 m' => 0];
$pasanganUjung = [];
foreach ($ujungKeluar as $a => [$ia, $pa]) {
    $terdekat = INF;
    $kepada = null;
    foreach ($ujungKeluar as $b => [$ib, $pb]) {
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
printf("\nsisa celah antar ujung polilin: %d ujung bebas ·", count($ujungKeluar));
foreach ($pita as $k => $jml) {
    echo ' ' . $k . '=' . $jml;
}
echo "\n";

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
        if ($p['tepi'] > 0) {
            $catatan[] = 'tepi ' . angkaNum($p['tepi_m'], 1) . ' m';
        }
        printf("  %3d. %8s m · %3d rantai · %s%s\n", $i + 1, angkaNum($p['panjang'], 1),
            count($p['rantai']), implode(',', $p['rantai']),
            $catatan === [] ? '' : ' · ' . implode(' · ', $catatan));
    }
}

// ---------------------------------------------------------------------------
// Tulis
// ---------------------------------------------------------------------------

if (!$terapkan) {
    echo "\nuji kering: tidak ada berkas yang ditulis; pakai --terapkan.\n";
    exit(0);
}

$fiturKeluar = [];
foreach ($polilin as $i => $p) {
    $fiturKeluar[] = [
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
            'tepi_susur' => $p['tepi'],
            'tepi_susur_m' => round($p['tepi_m'], 2),
            'panjang_m' => round($p['panjang'], 2),
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $p['titik']],
    ];
}

$meta = $metaAsli;
$meta['dibuat_tepi'] = date('c');
$meta['alat_tepi'] = 'tools/jahit-sumbu-tepi.php';
$meta['tepi'] = [
    'bahan_tepi' => BERKAS_TEPI . '.geojson',
    'celah_m' => $celahMaks,
    'lebar_tepi_m' => TEPI_MAKS,
    'lebar_alur_m' => [LEBAR_MIN, LEBAR_MAKS],
    'langkah_m' => LANGKAH,
    'sampai_m' => SAMPAI_M,
    'belok_susur_derajat' => BELOK_SUSUR,
    'belok_sambung_derajat' => BELOK_SAMBUNG,
    'jumlah' => $jumlahSusur,
    'celah_m_total' => round($meterCelah, 2),
    'susur_m_total' => round($panjangSusur, 2),
    'konektor' => array_map(static fn(array $s): array => [
        'celah_m' => round($s['celah'], 2),
        'susur_m' => round($s['panjang_susur'], 2),
        'tutup_m' => round($s['tutup'], 2),
        'lebar_m' => round($s['lebar'], 1),
        'belok_sambung' => [round($s['belokA'], 1), round($s['belokB'], 1)],
        'fitur' => [$ujung[$s['i']]['fitur'], $ujung[$s['j']]['fitur']],
    ], $sukses),
];
$meta['sesudah_tepi'] = [
    'polilin' => count($fiturKeluar),
    'panjang_m' => round($panjangKeluaran, 2),
    'titik' => $jumlahTitik,
    'sisa_ujung_bebas' => count($ujungKeluar),
];

$naskah = json_encode(['type' => 'FeatureCollection', 'features' => $fiturKeluar,
    'metadata' => $meta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_PRESERVE_ZERO_FRACTION);
if ($naskah === false) {
    galat('gagal menyusun JSON keluaran');
}
$berkas = DIR_LAPISAN . '/' . $berkasKeluar . '.geojson';
if (file_put_contents($berkas, $naskah) === false) {
    galat('gagal menulis ' . $berkas);
}
printf("\n%s: %d fitur · %s m · ditulis\n", basename($berkas), count($fiturKeluar),
    angkaNum($panjangKeluaran, 1));

// Validasi baca ulang.
$cek = geojsonBaca($berkas);
$salah = 0;
$titikCek = 0;
$panjangCek = 0.0;
foreach ($cek as $f) {
    $c = $f['geometry']['coordinates'] ?? [];
    if (($f['geometry']['type'] ?? '') !== 'LineString' || count($c) < 2) {
        $salah++;
        continue;
    }
    $titikCek += count($c);
    $panjangCek += panjangTitik(array_map(static fn(array $p): array => [(float) $p[0],
        (float) $p[1]], $c));
}
printf("validasi baca ulang %s: %d fitur · bukan LineString sah %d · %d titik · %s m\n",
    basename($berkas), count($cek), $salah, $titikCek, angkaNum($panjangCek, 1));
if ($salah > 0) {
    galat('berkas keluaran memuat geometri tidak sah');
}
