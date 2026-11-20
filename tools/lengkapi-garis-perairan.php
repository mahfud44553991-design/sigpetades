<?php
declare(strict_types=1);

/**
 * Lengkapi garis Sungai dan Selokan untuk label perairan yang belum punya garis.
 *
 *   php -d memory_limit=1024M tools/lengkapi-garis-perairan.php            # uji kering
 *   php -d memory_limit=1024M tools/lengkapi-garis-perairan.php --terapkan
 *
 * Latar. Lapis Sungai (garis-perairan.geojson) dan Selokan (garis-selokan.geojson)
 * diturunkan tools/garis-batas-020100.php lalu dirapikan dan disambung beberapa
 * kali. Ukuran 20 Nov 2026: dari 112 label berkelas sungai (teks SUNGAI/KALI)
 * 41 label lebih dari 10 m dari baris Sungai; dari 165 label berkelas saluran
 * 15 label lebih dari 10 m dari baris Selokan (dua label "JALAN ..." di berkas
 * BATAS PERAIRAN tidak dihitung). Semua label itu masih punya garis 020100
 * dalam 0,4-13 m, jadi kekosongan berasal dari penurunan yang tidak menjangkau
 * label, bukan dari gambar sumber yang kosong.
 *
 * Alat ini menambah baris baru dari 020100 untuk label yatim itu dan TIDAK
 * menyentuh baris lama: kandidat 020100 yang indeksnya sudah dipakai lapis
 * Jalan, Selokan, atau Sungai dilewati, kode baru memakai akhiran -LENGKAP-.
 * Aturan penurunan sama dengan garis-batas-020100.php:
 *  - benih  : kandidat yang punya titik sampel <= radius benih kelasnya
 *             (sungai 8 m, selokan 2 m) dari label yatim;
 *  - yatim  : bila benih tidak menjangkau, satu kandidat terdekat <= 13 m
 *             dari label yatim tetap diambil, supaya label bertulisan di
 *             tengah alur lebar tidak dibiarkan tanpa garis;
 *  - pita   : kandidat lain yang mayoritas sampelnya sejajar (<= 25 derajat)
 *             dengan klaim benih/yatim pada jarak pita kelasnya (sungai 15 m,
 *             selokan 8 m) dan koridor tengahnya tidak jatuh di dalam bidang.
 *             Klaim pita tidak masuk kolam pasangan (satu hop), supaya
 *             perluasan tidak merambat ke seluruh jaringan lama.
 *
 * Cincin. 020100 menggambar koridor sebagai garis ujung-sambung: tebing kanan
 * dan kiri bertemu di ujung. Cincin sempit dibuka menjadi satu tebing (potong
 * di verteks terjauh); cincin dengan lebar rata-rata > 30 m adalah keliling
 * blok/persil, bukan tebing alur, jadi ditolak dan dilaporkan.
 *
 * Label berkoordinat di luar kotak desa (dua label 762 km dari sini) dilewati
 * dan dilaporkan; tidak ada geometri 020100 yang bisa dipakai menjangkaunya.
 *
 * Setelah dijalankan, lapis baru memuat baris tambahan dengan cara
 * lengkapi-benih / lengkapi-yatim / lengkapi-pita1 / lengkapi-pita2. Bila baris
 * baru perlu disatukan dengan rantai lama, jalankan
 * tools/rapikan-garis-jalan.php --lapis=perairan|selokan --celah=15 --rapat=1
 * lalu tools/tepi-sungai.php seperti biasa.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const SEL = 25.0;
const SEL_KISI = 5.0;
const LANGKAH = 2.0;
const SUDUT = 25.0;
const PUTARAN = 2;
const BAGI_SEJAJAR = 0.5;
const BAGI_KOSONG = 0.6;
const TOLERANSI_TEPI = 0.35;
const MIN_PANJANG = 2.0;
const BAGI_ULANG = 0.5;
/** Jarak label ke baris kelasnya agar dianggap sudah punya garis. */
const TUTUP = 10.0;
/** Ronde kedua: kandidat terdekat yang masih diambil walau di luar benih. */
const BENIH_YATIM = 13.0;
/** Jangkauan pita: kandidat pita harus berada sedekat ini dengan label yatim. */
const JANGKAU = 150.0;
/**
 * Lebar rata-rata maksimum cincin tebing yang masih dipakai. 020100 menggambar
 * koridor jalan/saluran/sungai sebagai dua tebing yang bersambung di ujung
 * (cincin tipis, lebar 5-30 m). Cincin yang jauh lebih lebar adalah keliling
 * blok/persil, bukan tebing alur (terpanjang 2.879 m, lebar 44 m).
 */
const LEBAR_CINCIN = 30.0;
/** Kotak desa; label di luarnya (koordinat salah) tidak bisa dijangkau 020100. */
const KOTAK = [200000.0, 700000.0, 300000.0, 900000.0];

const KELAS = [
    'perairan' => [
        'pola' => '/SUNGAI|KALI|BENGKONG/u',
        'berkas' => 'garis-perairan',
        'judul' => 'GARIS BATAS PERAIRAN 020100',
        'benih' => 8.0,
        'pita' => 15.0,
        'kode' => 'PERAIRAN-LENGKAP',
    ],
    'selokan' => [
        'pola' => '/SALURAN|SLURAN|IRIGASI|DRAIN|GREBEG/u',
        'berkas' => 'garis-selokan',
        'judul' => 'GARIS SELOKAN 020100',
        'benih' => 2.0,
        'pita' => 8.0,
        'kode' => 'SELOKAN-LENGKAP',
    ],
];

$terapkan = in_array('--terapkan', $argv, true);
$keluar = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--keluar=')) {
        $keluar = substr($a, 9);
    }
}

function galat(string $pesan): never
{
    fwrite(STDERR, 'GAGAL: ' . $pesan . PHP_EOL);
    exit(1);
}

function bedaArah(float $a, float $b): float
{
    $beda = abs(fmod($a - $b, 180.0));
    return $beda > 90.0 ? 180.0 - $beda : $beda;
}

function tambahSegmen(array &$kisi, array &$segmen, array $titik, float $sel, mixed ...$tambahan): void
{
    for ($i = 0; $i + 1 < count($titik); $i++) {
        $id = count($segmen);
        $dx = (float) $titik[$i + 1][0] - (float) $titik[$i][0];
        $dy = (float) $titik[$i + 1][1] - (float) $titik[$i][1];
        $baris = [(float) $titik[$i][0], (float) $titik[$i][1], (float) $titik[$i + 1][0],
            (float) $titik[$i + 1][1], rad2deg(atan2($dy, $dx))];
        foreach ($tambahan as $t) {
            $baris[] = $t;
        }
        $segmen[$id] = $baris;
        foreach (range((int) floor(min($baris[0], $baris[2]) / $sel),
            (int) floor(max($baris[0], $baris[2]) / $sel)) as $gx) {
            foreach (range((int) floor(min($baris[1], $baris[3]) / $sel),
                (int) floor(max($baris[1], $baris[3]) / $sel)) as $gy) {
                $kisi[$gx . ':' . $gy][$id] = true;
            }
        }
    }
}

function jarakSegmen(array $kisi, array $segmen, float $px, float $py, float $radius, float $sel): ?array
{
    $batas = (int) ceil($radius / $sel);
    $gx = (int) floor($px / $sel);
    $gy = (int) floor($py / $sel);
    $baik = null;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                $s = $segmen[$id];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                $t = $pan > 0.0 ? max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan)) : 0.0;
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $jarak = hypot($px - $fx, $py - $fy);
                if ($jarak <= $radius && ($baik === null || $jarak < $baik[0])) {
                    $baik = array_merge([$jarak, $s[4], $fx, $fy], array_slice($s, 5));
                }
            }
        }
    }
    return $baik;
}

/** @return list<array{0:float,1:float,2:float}> */
function sampel(array $titik): array
{
    $keluar = [];
    for ($i = 0; $i + 1 < count($titik); $i++) {
        $dx = (float) $titik[$i + 1][0] - (float) $titik[$i][0];
        $dy = (float) $titik[$i + 1][1] - (float) $titik[$i][1];
        $panjang = hypot($dx, $dy);
        if ($panjang <= 0.0) {
            continue;
        }
        $arah = rad2deg(atan2($dy, $dx));
        $n = max(1, (int) floor($panjang / LANGKAH));
        for ($j = 0; $j < $n; $j++) {
            $t = $j / $n;
            $keluar[] = [(float) $titik[$i][0] + $t * $dx, (float) $titik[$i][1] + $t * $dy, $arah];
        }
    }
    return $keluar === [] ? [[(float) $titik[0][0], (float) $titik[0][1], 0.0]] : $keluar;
}

function panjangRing(array $titik): float
{
    $jumlah = 0.0;
    for ($i = 1; $i < count($titik); $i++) {
        $jumlah += hypot((float) $titik[$i][0] - (float) $titik[$i - 1][0],
            (float) $titik[$i][1] - (float) $titik[$i - 1][1]);
    }
    return $jumlah;
}

/** Lebar rata-rata cincin: 2 x luas / keliling. */
function lebarCincin(array $titik): float
{
    $n = count($titik);
    $luas = 0.0;
    $keliling = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $luas += ((float) $titik[$i][0] - (float) $titik[0][0]) * ((float) $titik[$j][1] - (float) $titik[0][1])
            - ((float) $titik[$j][0] - (float) $titik[0][0]) * ((float) $titik[$i][1] - (float) $titik[0][1]);
        $keliling += hypot((float) $titik[$j][0] - (float) $titik[$i][0],
            (float) $titik[$j][1] - (float) $titik[$i][1]);
    }
    return $keliling > 0.0 ? abs($luas) / $keliling : INF;
}

/** Buka cincin tebing menjadi satu tebing: potong di verteks terjauh dari awal. */
function bukaCincin(array $titik): array
{
    $n = count($titik);
    $k = 1;
    $jauh = -1.0;
    for ($i = 1; $i < $n - 1; $i++) {
        $d = hypot((float) $titik[$i][0] - (float) $titik[0][0],
            (float) $titik[$i][1] - (float) $titik[0][1]);
        if ($d > $jauh) {
            $jauh = $d;
            $k = $i;
        }
    }
    $sisiA = array_slice($titik, 0, $k + 1);
    $sisiB = array_reverse(array_slice($titik, $k));
    return panjangRing($sisiA) >= panjangRing($sisiB) ? $sisiA : $sisiB;
}

function tambahPoligon(array &$kisi, array &$poligon, array &$besar, array $ring, float $sel,
    int $maksSel = 900): void
{
    if (count($ring) < 4) {
        return;
    }
    $xs = array_column($ring, 0);
    $ys = array_column($ring, 1);
    $gxMin = (int) floor((float) min($xs) / $sel);
    $gxMax = (int) floor((float) max($xs) / $sel);
    $gyMin = (int) floor((float) min($ys) / $sel);
    $gyMax = (int) floor((float) max($ys) / $sel);
    if (($gxMax - $gxMin + 1) * ($gyMax - $gyMin + 1) > $maksSel) {
        $besar[] = $ring;
        return;
    }
    $id = count($poligon);
    $poligon[$id] = $ring;
    for ($gx = $gxMin; $gx <= $gxMax; $gx++) {
        for ($gy = $gyMin; $gy <= $gyMax; $gy++) {
            $kisi[$gx . ':' . $gy][$id] = true;
        }
    }
}

function dalamRing(float $x, float $y, array $ring): bool
{
    $dalam = false;
    for ($i = 0, $k = count($ring) - 1; $i < count($ring); $k = $i++) {
        $xi = (float) $ring[$i][0];
        $yi = (float) $ring[$i][1];
        $xk = (float) $ring[$k][0];
        $yk = (float) $ring[$k][1];
        if ((($yi > $y) !== ($yk > $y)) && ($x < ($xk - $xi) * ($y - $yi) / ($yk - $yi) + $xi)) {
            $dalam = !$dalam;
        }
    }
    return $dalam;
}

function diPoligon(float $x, float $y, array $kisi, array $poligon, array $besar, float $sel): bool
{
    foreach (array_keys($kisi[(int) floor($x / $sel) . ':' . (int) floor($y / $sel)] ?? []) as $id) {
        if (dalamRing($x, $y, $poligon[$id])) {
            return true;
        }
    }
    foreach ($besar as $ring) {
        if (dalamRing($x, $y, $ring)) {
            return true;
        }
    }
    return false;
}

function kelasLabel(string $teks): ?string
{
    foreach (KELAS as $jenis => $set) {
        if (preg_match($set['pola'], mb_strtoupper($teks)) === 1) {
            return $jenis;
        }
    }
    return null;
}

function jarakKeFitur(float $x, float $y, array $features): float
{
    $terbaik = INF;
    foreach ($features as $f) {
        $g = $f['geometry'];
        if (($g['type'] ?? '') !== 'LineString') {
            continue;
        }
        $c = $g['coordinates'];
        for ($i = 0; $i + 1 < count($c); $i++) {
            $ax = (float) $c[$i][0];
            $ay = (float) $c[$i][1];
            $bx = (float) $c[$i + 1][0];
            $by = (float) $c[$i + 1][1];
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $l2 = $dx * $dx + $dy * $dy;
            $t = $l2 > 0.0 ? max(0.0, min(1.0, (($x - $ax) * $dx + ($y - $ay) * $dy) / $l2)) : 0.0;
            $d = hypot($x - ($ax + $t * $dx), $y - ($ay + $t * $dy));
            if ($d < $terbaik) {
                $terbaik = $d;
            }
        }
    }
    return $terbaik;
}

// ------------------------------------------------------------------ bahan
$induk = geojsonBaca(DIR_LAPISAN . '/020100.geojson');

$kisiTepi = [];
$segmenTepi = [];
$kisiUlang = [];
$segmenUlang = [];
$kisiPoligon = [];
$poligon = [];
$poligonBesar = [];
$terbuka = [];
$cincin = [];
$cincinLebar = [];
foreach ($induk as $indeks => $f) {
    $g = $f['geometry'] ?? null;
    $tipe = (string) ($g['type'] ?? '');
    if ($tipe === 'Polygon') {
        $ring = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) ($g['coordinates'][0] ?? []));
        if (count($ring) < 4) {
            continue;
        }
        tambahSegmen($kisiTepi, $segmenTepi, $ring, SEL_KISI);
        tambahPoligon($kisiPoligon, $poligon, $poligonBesar, $ring, SEL_KISI);
        $cincin[(int) $indeks] = $ring;
    } elseif ($tipe === 'LineString' && count((array) ($g['coordinates'] ?? [])) >= 2) {
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) $g['coordinates']);
        $panjang = panjangRing($titik);
        $akhir = count($titik) - 1;
        $catatan = [];
        if ($panjang >= 50.0
            && hypot($titik[0][0] - $titik[$akhir][0], $titik[0][1] - $titik[$akhir][1]) <= 1.0) {
            $lebar = lebarCincin($titik);
            if ($lebar > LEBAR_CINCIN) {
                $cincinLebar[(int) $indeks] = [$panjang, $lebar];
                continue;
            }
            $titik = bukaCincin($titik);
            $panjang = panjangRing($titik);
            $catatan['dibuka_dari_cincin'] = round($lebar, 1);
        }
        $terbuka[(int) $indeks] = ['titik' => $titik, 'panjang' => $panjang,
            'sampel' => sampel($titik), 'asal' => 'garis'] + $catatan;
        tambahSegmen($kisiUlang, $segmenUlang, $terbuka[(int) $indeks]['titik'], SEL_KISI);
    }
}

$jumlahBidang = 0;
foreach ((array) db_all('SELECT ST_AsGeoJSON(geom) AS geojson FROM bidang '
    . "WHERE status <> 'dihapus' AND geom IS NOT NULL") as $baris) {
    $geometri = json_decode((string) $baris['geojson'], true);
    if (!is_array($geometri) || !isset($geometri['type'], $geometri['coordinates'])) {
        continue;
    }
    $bagian = $geometri['type'] === 'MultiPolygon' ? (array) $geometri['coordinates']
        : ([$geometri['type'] === 'Polygon' ? $geometri['coordinates'] : []]);
    foreach ($bagian as $poligonBagian) {
        $ring = array_map(static fn(array $p): array => wgs84KeDxf((float) $p[0], (float) $p[1]),
            (array) ($poligonBagian[0] ?? []));
        if (count($ring) < 4) {
            continue;
        }
        $jumlahBidang++;
        tambahSegmen($kisiTepi, $segmenTepi, $ring, SEL_KISI);
        tambahPoligon($kisiPoligon, $poligon, $poligonBesar, $ring, SEL_KISI);
    }
}

foreach ($terbuka as $indeks => $garis) {
    $kena = 0;
    foreach ($garis['sampel'] as [$x, $y]) {
        if (jarakSegmen($kisiTepi, $segmenTepi, $x, $y, TOLERANSI_TEPI, SEL_KISI) !== null) {
            $kena++;
        }
    }
    $terbuka[$indeks]['rasioTepi'] = $kena / max(1, count($garis['sampel']));
}

uasort($cincin, static fn(array $a, array $b): int => panjangRing($b) <=> panjangRing($a));
$cincinDipakai = 0;
foreach ($cincin as $indeks => $ring) {
    $panjang = panjangRing($ring);
    if ($panjang < MIN_PANJANG) {
        continue;
    }
    $sampel = sampel($ring);
    $kena = 0;
    foreach ($sampel as [$x, $y]) {
        if (jarakSegmen($kisiUlang, $segmenUlang, $x, $y, TOLERANSI_TEPI, SEL_KISI) !== null) {
            $kena++;
        }
    }
    if ($kena / max(1, count($sampel)) >= BAGI_ULANG) {
        continue;
    }
    // Poligon 020100 yang menggambar koridor juga berupa cincin; perlakukan
    // sama seperti cincin garis: tebing sempit dibuka, keliling blok ditolak.
    $lebar = lebarCincin($ring);
    if ($lebar > LEBAR_CINCIN) {
        $cincinLebar[(int) $indeks] = [$panjang, $lebar];
        continue;
    }
    $titik = bukaCincin($ring);
    $terbuka[(int) $indeks] = ['titik' => $titik, 'panjang' => panjangRing($titik),
        'sampel' => sampel($titik), 'rasioTepi' => $kena / max(1, count($sampel)),
        'asal' => 'bidang', 'dibuka_dari_cincin' => round($lebar, 1)];
    $cincinDipakai++;
    tambahSegmen($kisiUlang, $segmenUlang, $ring, SEL_KISI);
}
printf("020100: %d garis terbuka + %d cincin dipakai · %d poligon bidang · %d segmen tepi\n",
    count($terbuka) - $cincinDipakai, $cincinDipakai, $jumlahBidang, count($segmenTepi));
$jumlahBuka = count(array_filter($terbuka,
    static fn(array $g): bool => isset($g['dibuka_dari_cincin'])));
printf("cincin: %d tebing berujung-sambung dibuka · %d cincin lebar (keliling blok) ditolak\n",
    $jumlahBuka, count($cincinLebar));

// Indeks 020100 yang sudah dipakai salah satu lapis garis terbit. Baris
// "Tanpa label" (garis-lain) bukan kelas, jadi boleh direklasifikasi menjadi
// air bila sebuah label air jatuh dekat; baris Jalan/Selokan/Sungai tidak,
// supaya satu garis 020100 tidak tergambar di dua pil sekaligus.
$dipakai = [];
$pemilik = [];
$lama = [];
foreach (['garis-jalan', 'garis-selokan', 'garis-perairan'] as $berkas) {
    $lama[$berkas] = geojsonBaca(DIR_LAPISAN . '/' . $berkas . '.geojson');
    foreach ($lama[$berkas] as $f) {
        $p = $f['properties'] ?? [];
        if (isset($p['indeks'])) {
            $dipakai[(int) $p['indeks']] = true;
            $pemilik[(int) $p['indeks']] = $pemilik[(int) $p['indeks']] ?? $berkas;
        }
        foreach ((array) ($p['indeks_all'] ?? []) as $i) {
            $dipakai[(int) $i] = true;
            $pemilik[(int) $i] = $pemilik[(int) $i] ?? $berkas;
        }
    }
}
$lama['garis-lain'] = geojsonBaca(DIR_LAPISAN . '/garis-lain.geojson');
foreach ($lama['garis-lain'] as $f) {
    $p = $f['properties'] ?? [];
    if (isset($p['indeks'])) {
        $pemilik[(int) $p['indeks']] = $pemilik[(int) $p['indeks']] ?? 'garis-lain';
    }
}

// Label: satu pool, kelas dari teks; yang sudah dekat baris kelasnya dilewati.
$labelYatim = [];
$labelLuar = [];
foreach (geojsonBaca(DIR_LAPISAN . '/batas-perairan.geojson') as $f) {
    $c = $f['geometry']['coordinates'] ?? [null, null];
    if (!is_numeric($c[0]) || !is_numeric($c[1])) {
        continue;
    }
    $teks = trim((string) ($f['properties']['label'] ?? ''));
    $jenis = kelasLabel($teks);
    if ($jenis === null) {
        continue;
    }
    $x = (float) $c[0];
    $y = (float) $c[1];
    if ($x < KOTAK[0] || $x > KOTAK[2] || $y < KOTAK[1] || $y > KOTAK[3]) {
        $labelLuar[] = [$teks, $x, $y];
        continue;
    }
    $jarak = jarakKeFitur($x, $y, $lama[KELAS[$jenis]['berkas']]);
    if ($jarak <= TUTUP) {
        continue;
    }
    $labelYatim[] = ['jenis' => $jenis, 'teks' => $teks, 'x' => $x, 'y' => $y, 'jarak' => $jarak];
}
$hitungYatim = [];
foreach ($labelYatim as $l) {
    $hitungYatim[$l['jenis']] = ($hitungYatim[$l['jenis']] ?? 0) + 1;
}
printf("label yatim: %s · di luar kotak desa: %d%s\n",
    json_encode($hitungYatim, JSON_UNESCAPED_UNICODE), count($labelLuar),
    $labelLuar === [] ? '' : ' (' . implode('; ', array_map(
        static fn(array $l): string => sprintf('%s @ %.0f,%.0f', $l[0], $l[1], $l[2]), $labelLuar)) . ')');

// ------------------------------------------------------------------ klaim
$klaim = [];
$fiturBaru = [];
foreach (array_keys(KELAS) as $jenis) {
    $set = KELAS[$jenis];
    $target = array_values(array_filter($labelYatim, static fn(array $l): bool => $l['jenis'] === $jenis));
    if ($target === []) {
        continue;
    }
    // Kolam pita hanya berisi klaim baru. Baris lama tidak diajak berpasangan:
    // kalau diajak, satu tebing yang sudah terbit menarik setiap garis sejajar
    // dalam 15 m di sepanjang jaringan lama dan perluasan lari ke seluruh desa
    // (uji 20 Nov: 240 baris, 80 di antaranya >250 m dari label yatim).
    $kisiKlaim = [];
    $segmenKlaim = [];
    $terklaim = [];

    // Kandidat yang boleh dipakai: panjang cukup, belum dipakai lapis lain,
    // dan belum diklaim. Cincin lebar sudah disaring saat bahan dimuat.
    $kandidat = static function (int $indeks) use (&$terbuka, &$dipakai, &$klaim): bool {
        return !isset($dipakai[$indeks]) && !isset($klaim[$indeks])
            && $terbuka[$indeks]['panjang'] >= MIN_PANJANG;
    };

    // Kandidat yang berada dalam jangkauan salah satu label yatim kelas ini.
    $kisiSampel = [];
    foreach ($terbuka as $indeks => $garis) {
        if (!$kandidat((int) $indeks)) {
            continue;
        }
        foreach ($garis['sampel'] as [$x, $y]) {
            $kisiSampel[(int) floor($x / SEL) . ':' . (int) floor($y / SEL)][] = [$indeks, $x, $y];
        }
    }
    $kandidatDekat = [];
    foreach ($target as $l) {
        $batas = (int) ceil(JANGKAU / SEL);
        $gx = (int) floor($l['x'] / SEL);
        $gy = (int) floor($l['y'] / SEL);
        for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
            for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
                foreach ($kisiSampel[$ix . ':' . $iy] ?? [] as [$indeks, $x, $y]) {
                    if (hypot($x - $l['x'], $y - $l['y']) <= JANGKAU) {
                        $kandidatDekat[(int) $indeks] = true;
                    }
                }
            }
        }
    }
    // Hanya klaim benih/yatim yang masuk kolam pasangan pita. Klaim pita tidak
    // masuk, supaya perluasan berhenti setelah satu hop dari garis berlabel.
    $ambil = static function (int $indeks, string $cara, float $jarak, string $nama,
        bool $masukKolam = false) use (
        &$terbuka, &$kisiKlaim, &$segmenKlaim, &$terklaim, &$klaim
    ): void {
        $terklaim[$indeks] = ['cara' => $cara, 'jarak' => $jarak, 'label' => $nama];
        $klaim[$indeks] = true;
        if ($masukKolam) {
            tambahSegmen($kisiKlaim, $segmenKlaim, $terbuka[$indeks]['titik'], SEL, $nama);
        }
    };

    foreach ($target as $l) {
        $dekat = null;
        foreach ($terbuka as $indeks => $garis) {
            if (!$kandidat((int) $indeks)) {
                continue;
            }
            foreach ($garis['sampel'] as [$x, $y]) {
                $d = hypot($x - $l['x'], $y - $l['y']);
                if ($d <= (float) $set['benih'] && ($dekat === null || $d < $dekat[0])) {
                    $dekat = [$d, (int) $indeks];
                }
            }
        }
        if ($dekat !== null) {
            $ambil($dekat[1], 'lengkapi-benih', $dekat[0], $l['teks'], true);
        }
    }

    // Ronde yatim: label yang belum terjangkau benih mengambil satu kandidat
    // terdekat. Hanya kandidat yang benar-benar dekat label; koridor pita yang
    // menyusul menentukan pasangannya. Jarak diukur ke kolam klaim yang sudah
    // berisi baris lama, jadi salah satu teks SUNGAI tidak dianggap terwakili
    // oleh klaim untuk teks SUNGAI lain yang kebetulan berbunyi sama.
    foreach ($target as $l) {
        $punya = false;
        foreach ($terklaim as $indeks => $isi) {
            foreach ($terbuka[$indeks]['sampel'] as [$x, $y]) {
                if (hypot($x - $l['x'], $y - $l['y']) <= TUTUP) {
                    $punya = true;
                    break 2;
                }
            }
        }
        if ($punya) {
            continue;
        }
        $dekat = null;
        foreach ($terbuka as $indeks => $garis) {
            if (!$kandidat((int) $indeks)) {
                continue;
            }
            foreach ($garis['sampel'] as [$x, $y]) {
                $d = hypot($x - $l['x'], $y - $l['y']);
                if ($d <= BENIH_YATIM && ($dekat === null || $d < $dekat[0])) {
                    $dekat = [$d, (int) $indeks];
                }
            }
        }
        if ($dekat !== null) {
            $ambil($dekat[1], 'lengkapi-yatim', $dekat[0], $l['teks'], true);
        }
    }

    for ($putaran = 0; $putaran < PUTARAN; $putaran++) {
        $baru = 0;
        foreach ($terbuka as $indeks => $garis) {
            if (!$kandidat((int) $indeks) || !isset($kandidatDekat[$indeks])) {
                continue;
            }
            $sejajar = 0;
            $kosong = 0;
            $jarakLbl = 0.0;
            $namaLbl = '';
            foreach ($garis['sampel'] as [$x, $y, $arah]) {
                $d = jarakSegmen($kisiKlaim, $segmenKlaim, $x, $y, (float) $set['pita'], SEL);
                if ($d === null || bedaArah($arah, $d[1]) > SUDUT) {
                    continue;
                }
                if ($sejajar === 0) {
                    $jarakLbl = $d[0];
                    $namaLbl = (string) ($d[4] ?? '');
                }
                $sejajar++;
                if ($d[0] < 0.5
                    || !diPoligon(($x + $d[2]) / 2, ($y + $d[3]) / 2, $kisiPoligon, $poligon,
                        $poligonBesar, SEL_KISI)) {
                    $kosong++;
                }
            }
            if ($sejajar < BAGI_SEJAJAR * count($garis['sampel'])) {
                continue;
            }
            if ($kosong < BAGI_KOSONG * $sejajar) {
                continue;
            }
            $ambil((int) $indeks, 'lengkapi-pita' . ($putaran + 1), $jarakLbl, $namaLbl);
            $baru++;
        }
        if ($baru === 0) {
            break;
        }
    }

    $nomor = 1;
    foreach ($terklaim as $indeks => $isi) {
        $garis = $terbuka[$indeks];
        $fiturBaru[$jenis][] = [
            'type' => 'Feature',
            'properties' => [
                'kode' => sprintf('%s-%04d', $set['kode'], $nomor++),
                'sumber' => '020100',
                'indeks' => (int) $indeks,
                'indeks_all' => [(int) $indeks],
                'jumlah_bagian' => 1,
                'panjang_m' => round($garis['panjang'], 2),
                'rasio_tepi_bidang' => round($garis['rasioTepi'] ?? 0.0, 3),
                'asal' => (string) ($garis['asal'] ?? 'garis'),
                'cara' => $isi['cara'],
                'jarak_label_m' => round($isi['jarak'], 2),
                'label' => $isi['label'],
                'dirapikan' => 0,
                'jembatan_celah' => 0,
                'dibuka_dari_cincin' => $garis['dibuka_dari_cincin'] ?? 0,
            ],
            'geometry' => ['type' => 'LineString', 'coordinates' => $garis['titik']],
        ];
    }
    $panjangBaru = array_sum(array_column(array_map(
        static fn(array $f): array => $f['properties'], $fiturBaru[$jenis] ?? []), 'panjang_m'));
    printf("%-8s label yatim %2d · ditambah %2d baris · %7.1f m\n", $jenis,
        count($target), count($fiturBaru[$jenis] ?? []), $panjangBaru);

    // Sebaran jarak baris baru ke label yatim terdekat: klaim yang jauh dari
    // semua label hanya merambat lewat pita, bukan mengisi kekosongan label.
    $kelasJarak = ['<=15 m' => 0, '<=30 m' => 0, '<=60 m' => 0, '<=120 m' => 0, '<=250 m' => 0, '>250 m' => 0];
    foreach ($terklaim as $indeks => $isi) {
        $dekat = INF;
        foreach ($target as $l) {
            foreach ($terbuka[$indeks]['sampel'] as [$x, $y]) {
                $dekat = min($dekat, hypot($x - $l['x'], $y - $l['y']));
            }
        }
        $kunci = $dekat <= 15 ? '<=15 m' : ($dekat <= 30 ? '<=30 m' : ($dekat <= 60 ? '<=60 m'
            : ($dekat <= 120 ? '<=120 m' : ($dekat <= 250 ? '<=250 m' : '>250 m'))));
        $kelasJarak[$kunci]++;
    }
    printf("  jarak ke label yatim terdekat: %s\n", json_encode($kelasJarak));

    // Label yang tetap tanpa baris baru (kandidat 020100 semuanya sudah dipakai).
    foreach ($target as $l) {
        $punya = false;
        foreach ($fiturBaru[$jenis] ?? [] as $f) {
            $c = $f['geometry']['coordinates'];
            for ($i = 0; $i + 1 < count($c); $i++) {
                $ax = (float) $c[$i][0];
                $ay = (float) $c[$i][1];
                $bx = (float) $c[$i + 1][0];
                $by = (float) $c[$i + 1][1];
                $dx = $bx - $ax;
                $dy = $by - $ay;
                $l2 = $dx * $dx + $dy * $dy;
                $t = $l2 > 0.0 ? max(0.0, min(1.0, (($l['x'] - $ax) * $dx + ($l['y'] - $ay) * $dy) / $l2)) : 0.0;
                if (hypot($l['x'] - ($ax + $t * $dx), $l['y'] - ($ay + $t * $dy)) <= TUTUP) {
                    $punya = true;
                    break 2;
                }
            }
        }
        if (!$punya) {
            // Sebabnya: garis terdekat sudah dipegang pil lain. Sebutkan pilnya
            // supaya pengguna tahu kanal itu memang sudah tergambar, bukan hilang.
            $dekat = null;
            foreach ($terbuka as $indeks => $garis) {
                if (isset($dipakai[$indeks])) {
                    continue;
                }
                foreach ($garis['sampel'] as [$x, $y]) {
                    $d = hypot($x - $l['x'], $y - $l['y']);
                    if ($d <= 20.0 && ($dekat === null || $d < $dekat[0])) {
                        $dekat = [$d, $indeks];
                    }
                }
            }
            $sebab = 'tidak ada 020100 bebas dalam 20 m';
            if ($dekat !== null) {
                if (isset($klaim[$dekat[1]])) {
                    $sebab = sprintf('garis #%d (%.1f m) sudah dipakai label air lain',
                        $dekat[1], $dekat[0]);
                } elseif ($terbuka[$dekat[1]]['panjang'] < MIN_PANJANG) {
                    $sebab = sprintf('garis #%d hanya %.1f m', $dekat[1],
                        $terbuka[$dekat[1]]['panjang']);
                } else {
                    $sebab = sprintf('kandidat bebas %.1f m (#%d) di luar jangkau', $dekat[0], $dekat[1]);
                }
            } else {
                // Cari garis terdekat milik pil lain untuk dilaporkan.
                $lainDekat = null;
                foreach ($terbuka as $indeks => $garis) {
                    if (!isset($dipakai[$indeks])) {
                        continue;
                    }
                    foreach ($garis['sampel'] as [$x, $y]) {
                        $d = hypot($x - $l['x'], $y - $l['y']);
                        if ($d <= 20.0 && ($lainDekat === null || $d < $lainDekat[0])) {
                            $lainDekat = [$d, $indeks];
                        }
                    }
                }
                if ($lainDekat !== null) {
                    $sebab = sprintf('garis terdekat %.1f m sudah di pil %s',
                        $lainDekat[0], $pemilik[$lainDekat[1]] ?? '?');
                }
            }
            printf("  tanpa baris baru: [%s] @ %.1f,%.1f — %s\n",
                $l['teks'], $l['x'], $l['y'], $sebab);
        }
    }
}

if ($keluar !== null) {
    $semuaBaru = [];
    foreach ($fiturBaru as $rows) {
        foreach ($rows as $f) {
            $semuaBaru[] = $f;
        }
    }
    file_put_contents($keluar, json_encode(['type' => 'FeatureCollection', 'features' => $semuaBaru],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    printf("pratinjau: %s (%d baris)\n", $keluar, count($semuaBaru));
}

if (!$terapkan) {
    printf("UJI KERING: tidak ada berkas yang ditulis. Jalankan dengan --terapkan.\n");
    exit(0);
}

// ------------------------------------------------------------------ tulis
$stempel = date('Ymd-His');
foreach (KELAS as $jenis => $set) {
    $baru = $fiturBaru[$jenis] ?? [];
    if ($baru === []) {
        continue;
    }
    $path = DIR_LAPISAN . '/' . $set['berkas'] . '.geojson';
    $cadangan = SIP_ROOT . '/storage/cadangan/' . $set['berkas'] . '-sebelum-lengkapi-' . $stempel . '.geojson';
    if (!is_dir(dirname($cadangan))) {
        galat('folder storage/cadangan tidak ada.');
    }
    if (!copy($path, $cadangan)) {
        galat('gagal mencadangkan ' . $path);
    }
    $semua = array_merge($lama[$set['berkas']], $baru);
    $naskah = json_encode(['type' => 'FeatureCollection', 'features' => $semua],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($naskah === false || file_put_contents($path, $naskah) === false) {
        galat('gagal menulis ' . $path);
    }
    $cara = [];
    $asal = [];
    $panjang = 0.0;
    foreach ($semua as $f) {
        $cara[$f['properties']['cara']] = ($cara[$f['properties']['cara']] ?? 0) + 1;
        $asal[$f['properties']['asal']] = ($asal[$f['properties']['asal']] ?? 0) + 1;
        $panjang += (float) $f['properties']['panjang_m'];
    }
    printf("DITULIS: %s · %d baris (+%d) · %.1f m (+%.1f)\n", $set['berkas'] . '.geojson',
        count($semua), count($baru), $panjang,
        array_sum(array_map(static fn(array $f): float => (float) $f['properties']['panjang_m'], $baru)));
    printf("  cadangan: %s\n", str_replace(SIP_ROOT . '/', '', $cadangan));

    $jejak = [
        'tanggal' => date('c'),
        'lapis' => $set['berkas'],
        'label_yatim' => count(array_filter($labelYatim, static fn(array $l): bool => $l['jenis'] === $jenis)),
        'label_luar_kotak' => count($labelLuar),
        'ditambah' => count($baru),
        'panjang_ditambah_m' => round(array_sum(array_map(
            static fn(array $f): float => (float) $f['properties']['panjang_m'], $baru)), 2),
        'cara' => array_count_values(array_map(
            static fn(array $f): string => (string) $f['properties']['cara'], $baru)),
        'sesudah' => ['baris' => count($semua), 'panjang_m' => round($panjang, 2)],
        'ambang' => ['tutup_m' => TUTUP, 'benih_m' => $set['benih'], 'benih_yatim_m' => BENIH_YATIM,
            'pita_m' => $set['pita'], 'sudut_derajat' => SUDUT, 'putaran' => PUTARAN],
        'cadangan' => str_replace(SIP_ROOT . '/', '', $cadangan),
    ];
    $berkasJejak = SIP_ROOT . '/storage/lengkapi-' . $set['berkas'] . '-perairan-' . $stempel . '.json';
    file_put_contents($berkasJejak, json_encode($jejak,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    printf("  jejak: %s\n", str_replace(SIP_ROOT . '/', '', $berkasJejak));

    $berkasMeta = DIR_LAPISAN . '/metadata.json';
    $meta = json_decode((string) file_get_contents($berkasMeta), true);
    if (!is_array($meta)) {
        galat('metadata.json tidak dapat dibaca.');
    }
    $blok = $meta['layers'][$set['judul']] ?? [];
    $blok['features'] = count($semua);
    $blok['types'] = ['line' => count($semua)];
    $blok['panjang_m'] = (int) round($panjang);
    $blok['cara'] = $cara;
    $blok['asal'] = $asal;
    $blok['lengkapi_label'] = $jejak;
    $meta['layers'][$set['judul']] = $blok;
    if (file_put_contents($berkasMeta, json_encode($meta,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
        galat('gagal menulis metadata.json');
    }
}

// Baris yang tadinya "Tanpa label" tetapi terklaim label air dikeluarkan dari
// garis-lain supaya tidak tergambar dua kali.
$pindahLain = [];
foreach ($fiturBaru as $jenis => $rows) {
    foreach ($rows as $f) {
        $i = (int) $f['properties']['indeks'];
        if (($pemilik[$i] ?? '') === 'garis-lain') {
            $pindahLain[$i] = $jenis;
        }
    }
}
if ($pindahLain !== []) {
    $path = DIR_LAPISAN . '/garis-lain.geojson';
    $cadangan = SIP_ROOT . '/storage/cadangan/garis-lain-sebelum-lengkapi-' . $stempel . '.geojson';
    if (!copy($path, $cadangan)) {
        galat('gagal mencadangkan ' . $path);
    }
    $sisa = [];
    foreach ($lama['garis-lain'] as $f) {
        if (isset($pindahLain[(int) ($f['properties']['indeks'] ?? -1)])) {
            continue;
        }
        $sisa[] = $f;
    }
    $naskah = json_encode(['type' => 'FeatureCollection', 'features' => $sisa],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($naskah === false || file_put_contents($path, $naskah) === false) {
        galat('gagal menulis garis-lain.geojson');
    }
    $panjangLain = 0.0;
    $caraLain = [];
    $asalLain = [];
    foreach ($sisa as $f) {
        $panjangLain += (float) $f['properties']['panjang_m'];
        $caraLain[$f['properties']['cara']] = ($caraLain[$f['properties']['cara']] ?? 0) + 1;
        $asalLain[$f['properties']['asal']] = ($asalLain[$f['properties']['asal']] ?? 0) + 1;
    }
    $meta = json_decode((string) file_get_contents(DIR_LAPISAN . '/metadata.json'), true);
    $blok = $meta['layers']['GARIS LAIN 020100'] ?? [];
    $blok['features'] = count($sisa);
    $blok['types'] = ['line' => count($sisa)];
    $blok['panjang_m'] = (int) round($panjangLain);
    $blok['cara'] = $caraLain;
    $blok['asal'] = $asalLain;
    $kelompok = [];
    foreach ($pindahLain as $i => $jenis) {
        $kelompok[KELAS[$jenis]['berkas']][] = $i;
    }
    $blok['dipindah_ke'] = $kelompok;
    $meta['layers']['GARIS LAIN 020100'] = $blok;
    if (file_put_contents(DIR_LAPISAN . '/metadata.json', json_encode($meta,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
        galat('gagal menulis metadata.json');
    }
    printf("GARIS-LAIN: %d baris dipindah ke lapis air · %d tersisa · %s\n",
        count($pindahLain), count($sisa), str_replace(SIP_ROOT . '/', '', $cadangan));
}
