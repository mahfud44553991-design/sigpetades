<?php
declare(strict_types=1);

/**
 * Garis turunan Jalan dari label jalan, dua tahap dan dua berkas.
 *
 *   php -d memory_limit=1024M tools/garis-jalan-label.php            # uji kering
 *   php -d memory_limit=1024M tools/garis-jalan-label.php --terapkan
 *
 *   --tahap=tepi|sumbu|semua   bawaan semua
 *   --sumber-tepi=turun|terbit bawaan turun; 'terbit' memakai lapis Jalan yang
 *                              sudah terbit (garis-jalan.geojson) sebagai bahan
 *                              tahap 2 sehingga tahap 1 tidak dijalankan
 *   --lebar=M                  lebar koridor maksimum tahap 2, bawaan 8,0 m
 *   --benih=M                  jarak terjauh label ke titik sampel sebuah baris
 *                              agar baris itu jadi benih tahap 1; bawaan 3,0 m
 *                              (= konstanta BENIH; si pembangkit memakai 2,0 m)
 *   --bagi-pasang=F            bagian titik sampel sebuah baris yang harus
 *                              berpasangan; bawaan 0 = terima tiap titik tengah
 *                              yang lolos semua syarat, 0,50 = hanya baris yang
 *                              separuh panjangnya berpasangan
 *   --eksklusif                label jalan harus yang terdekat; bila label kelas
 *                              lain lebih dekat, tepinya tidak diklaim
 *   --rinci                    cetak 40 sumbu terpanjang
 *
 * Angka flag (`--lebar`, `--bagi-pasang`) menerima koma atau titik desimal.
 *
 * Latar. `tools/garis-batas-020100.php` menurunkan empat kelas sekaligus dan
 * mengklaim SATU entitas 020100 per baris, sehingga lapis Jalan berisi tepian
 * yang terpecah dan tidak pernah menyisakan satu garis per jalan; hasilnya
 * kemudian dirapikan `tools/rapikan-garis-jalan.php` menjadi 1.981 baris tepi.
 * Alat ini khusus Jalan dan berhenti pada dua keluaran: TEPI jalan (sisi kiri
 * dan kanan) dan SUMBU jalan, satu garis di tengah koridor untuk tiap segmen
 * antara dua ujung atau persimpangan.
 *
 * Sumber geometri, sesuai permintaan "bidang & garis":
 *  - berkas `020100.geojson`: 13.681 garis terbuka + 2.626 cincin poligon;
 *  - tabel `bidang` melalui app/config.php, dibaca saja: 4.176 persil
 *    (status <> 'dihapus'). Cincin bidang ikut jadi KANDIDAT tepi, bukan hanya
 *    permukaan; yang >= BAGI_ULANG titiknya sudah tergambar sebagai garis lain
 *    dibuang lebih dulu supaya tidak ada tepi kembar.
 *  - `garis-selokan.geojson` (783) dan `garis-perairan.geojson` (542) hasil
 *    tools/garis-batas-020100.php, dibaca saja dan HANYA jadi pemolong tahap 2:
 *    keduanya tidak pernah jadi kandidat tepi. Alat ini karenanya bergantung
 *    pada dua lapis terbit itu; kalau keduanya dibangkitkan ulang, jalankan
 *    alat ini sesudahnya.
 * Permukaan yang diuji saat koridor adalah gabungan cincin 020100 dan poligon
 * tabel bidang — kisi bidang tanah di peta ini sebagian besar tersimpan sebagai
 * garis terbuka, jadi tanpa permukaan itu koridor di dalam sebidang tanah tampak
 * kosong dan pita merambat antar-bidang (README §3.73).
 *
 * Label: `batas-jalan.geojson` dan `batas-perairan.geojson` dibaca bersama dan
 * kelas diambil dari TEKS (polanya sama dengan si pembangkit), karena nama
 * saluran tersimpan di berkas BATAS PERAIRAN dan dua nama jalan nyasar ke berkas
 * yang sama. Yang dipakai di sini hanya teks berawalan JALAN.
 *
 * Tahap 1 — tepi (garis-jalan-tepi.geojson). Aturannya disengaja identik dengan
 * kelas jalan pada `tools/garis-batas-020100.php` kecuali radius benih (lihat
 * BENIH): benih = kandidat yang punya titik sampel <= BENIH (bawaan 3,0 m) dari
 * label jalan; pita = kandidat yang >= 50 % titik sampelnya punya segmen
 * terklaim jenis sama <= 15 m dengan beda arah <= 25 derajat DAN tengah
 * koridornya tidak berada di dalam bidang (>= 60 % kosong), dua putaran;
 * garis < 2,0 m dibuang.
 *
 * Tahap 2 — sumbu (garis-jalan-sumbu.geojson).
 *  1. potongan tepi yang ujungnya berjarak <= RAGU_SISI dianggap simpul yang
 *     sama, dan potongan yang berbagi simpul adalah satu SISI: dua baris yang
 *     sebenarnya satu sisi jalan tidak boleh berpasangan satu sama lain;
 *  2. tiap titik sampel sisi A dicari sisi B terdekat dengan syarat: sisi B
 *     bukan sisinya sendiri, lebar koridor >= LEBAR_MIN dan <= --lebar, beda
 *     arah <= SUDUT_PASANG, dan tengah antara kedua tepi tidak berada di dalam
 *     bidang dan tidak berada di antara dua tepi air yang sejajar dengan arah
 *     pasangan itu (syarat kedua ditambahkan 14 Nov: tanpa itu tepian sebuah
 *     SALURAN yang berlabel JALAN dipasangkan satu sama lain dan sumbu yang
 *     lahir adalah sumbu selokan, bukan sumbu jalan). Pasangan utama sebuah sisi
 *     = sisi B yang paling sering menang, dan
 *     titik tengahnya dipakai bila jumlahnya >= --bagi-pasang bagian titik
 *     sampel sisi itu (bawaan 0, jadi setiap titik tengah yang lolos semua
 *     syarat ikut terpakai);
 *  3. titik tengah tiap pasangan dikumpulkan dan direnggutkan pada ragum
 *     RAGU_SUMBU: titik tengah dari sisi kiri dan kanan sebuah jalan lurus
 *     jatuh di tempat yang sama, sehingga keduanya menyatu jadi satu rantai,
 *     dan koordinat simpul hasil adalah rata-rata seluruh titik yang masuk;
 *  4. rantai diputus pada simpul berderajat != 2, yaitu ujung buntu dan
 *     persimpangan, sehingga tiap jalur adalah satu segmen jalan; jalur < 2,0 m
 *     dibuang dan sisanya dinomori dari yang terpanjang.
 * Tidak ada verifikasi titik-demi-titik terhadap sumber seperti pada si perapian:
 * koordinat sumbu memang BARU, rata-rata dua tepi, dan itu seluruh isi tahap 2.
 * Yang dilaporkan sebagai gantinya: jarak tiap titik sumbu ke tepi terdekat.
 *
 * Uji kering adalah bawaan; tanpa --terapkan tidak ada berkas yang ditulis.
 * Tabel bidang hanya dibaca, tidak ada satu baris pun yang ditulis.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/lib-impor.php';

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_SUMBER = '020100';
const BERKAS_LABEL = ['batas-jalan', 'batas-perairan'];
const BERKAS_TERBIT = 'garis-jalan';
const BERKAS_TEPI = 'garis-jalan-tepi';
const BERKAS_SUMBU = 'garis-jalan-sumbu';
const JUDUL_TEPI = 'GARIS JALAN TEPI DARI LABEL';
const JUDUL_SUMBU = 'GARIS JALAN SUMBU DARI LABEL';

/** Kelas sebuah teks label; pola pertama yang cocok menang, sama dengan si pembangkit. */
const KELAS = [
    'jalan' => '/^JALAN/u',
    'selokan' => '/SALURAN|SLURAN|IRIGASI|DRAIN|GREBEG/u',
    'perairan' => '/SUNGAI|KALI|BENGKONG/u',
];

// Tahap 1.
/**
 * Radius benih bawaan. Sengaja dinaikkan dari 2,0 m (= nilai si pembangkit
 * `tools/garis-batas-020100.php`) pada 14 Nov: label jalan ditulis di tengah
 * jalan, mediannya 2,11 m dari bahan 020100 untuk label yang tak tercakup,
 * sehingga 2,0 m tidak pernah menjadikannya benih (README §3.82: +49 label,
 * intrusi air tetap 0,7 % panjang). Turunkan lewat `--benih=2` untuk mengulang
 * keluaran lama.
 */
const BENIH = 3.0;
const PITA = 15.0;
const LANGKAH = 2.0;
const SUDUT = 25.0;
const PUTARAN = 2;
const BAGI_SEJAJAR = 0.5;
const BAGI_KOSONG = 0.6;
const TOLERANSI_TEPI = 0.35;
const MIN_PANJANG = 2.0;
const BAGI_ULANG = 0.5;
const SEL = 25.0;
const SEL_KISI = 5.0;

// Tahap 2.
const LEBAR_MIN = 0.25;
const SUDUT_PASANG = 25.0;
/**
 * Bagian titik sampel sebuah baris yang harus berpasangan sebelum baris itu
 * dipakai. Bawaan 0: tiap titik tengah yang lolos SEMUA syarat geometri sudah
 * sah, dan ambang per-baris hanya membuat cakupan sumbu bergantung pada
 * seberapa pendek baris tepinya. Ukur 13 Nov 2026 pada bahan yang sama:
 * bawaan 2.444 dari 3.323 baris terpakai, 1.816 segmen, 30.805,2 m; dengan
 * --bagi-pasang=0,50 tinggal 1.773 baris, 1.428 segmen, 26.951,0 m.
 */
const BAGI_PASANG = 0.0;
const RAGU_SISI = 1.0;
const SEL_PASANG = 10.0;
const RAGU_SUMBU = 1.2;
/**
 * Uji koridor air (14 Nov). Tepi air diambil dari dua lapis terbit hasil
 * tools/garis-batas-020100.php. Sebuah pasangan tepi ditolak bila titik tengah
 * di antara keduanya berada di antara dua tepi air yang sejajar dengan arah
 * pasangan itu: itu bukan jalan, itu selokan atau sungai yang kebetulan berlabel
 * JALAN di sisinya. SUDUT_AIR menjaga persimpangan jalan dengan saluran tetap
 * utuh, sebab di situ tepi air memotong arah pasangan, tidak sejajar.
 */
const BERKAS_AIR = ['garis-selokan', 'garis-perairan'];
const RAGU_AIR = 3.5;
const SUDUT_AIR = 25.0;
/** Jangkauan diagnostik untuk sisi yang tidak terpasang; bukan ambang hasil. */
const SEBERANG_UJI = 15.0;

function galat(string $pesan): never
{
    fwrite(STDERR, 'GAGAL: ' . $pesan . PHP_EOL);
    exit(1);
}

function angkaNum(float $n, int $desimal = 0): string
{
    return number_format($n, $desimal, ',', '.');
}

/**
 * Angka dari argumen baris perintah. Koma desimal diterima karena itu yang
 * biasa diketik di sini: `(float) "0,50"` adalah 0 dan ambangnya punah diam-diam.
 */
function angkaFlag(string $nilai, string $nama): float
{
    $baku = str_replace(',', '.', trim($nilai));
    if ($baku === '' || !is_numeric($baku)) {
        galat($nama . ' membutuhkan angka, bukan "' . $nilai . '"');
    }
    return (float) $baku;
}

/** Beda dua arah garis dalam derajat, 0..90; arah majen tidak dipersoalkan. */
function bedaArah(float $a, float $b): float
{
    $beda = abs(fmod($a - $b, 180.0));
    return $beda > 90.0 ? 180.0 - $beda : $beda;
}

/**
 * Masukkan segmen ke kisi petak $sel. Tambahan dibubuhkan di belakang baris.
 *
 * @param list<array{0:float,1:float}> $titik
 */
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
        $xMin = (int) floor(min($baris[0], $baris[2]) / $sel);
        $xMax = (int) floor(max($baris[0], $baris[2]) / $sel);
        $yMin = (int) floor(min($baris[1], $baris[3]) / $sel);
        $yMax = (int) floor(max($baris[1], $baris[3]) / $sel);
        for ($gx = $xMin; $gx <= $xMax; $gx++) {
            for ($gy = $yMin; $gy <= $yMax; $gy++) {
                $kisi[$gx . ':' . $gy][$id] = true;
            }
        }
    }
}

/** Segmen terdekat dalam radius: [jarak, arah, kakiX, kakiY, ...tambahan]. */
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

/**
 * Label sekelas terdekat dari titik: [jarak, teks]. `$kelas` 'jalan' untuk pola
 * jalan, 'lain' untuk semua kelas bukan jalan.
 */
function labelTerdekat(array $kisi, array $label, float $px, float $py, float $radius, string $kelas): ?array
{
    $batas = (int) ceil($radius / SEL);
    $gx = (int) floor($px / SEL);
    $gy = (int) floor($py / SEL);
    $baik = null;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                $l = $label[$id];
                $cocok = $kelas === 'lain' ? $l[3] !== 'jalan' : $l[3] === 'jalan';
                if (!$cocok) {
                    continue;
                }
                $jarak = hypot($px - $l[1], $py - $l[2]);
                if ($jarak <= $radius && ($baik === null || $jarak < $baik[0])) {
                    $baik = [$jarak, $l[0]];
                }
            }
        }
    }
    return $baik;
}

/** Titik sampel sebuah garis setiap LANGKAH meter, dengan arah segmen asalnya. */
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

function panjang(array $titik): float
{
    $jumlah = 0.0;
    for ($i = 1; $i < count($titik); $i++) {
        $jumlah += hypot((float) $titik[$i][0] - (float) $titik[$i - 1][0],
            (float) $titik[$i][1] - (float) $titik[$i - 1][1]);
    }
    return $jumlah;
}

/** Masukkan cincin poligon ke kisi berdasarkan kotak batasnya. */
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

/** Uji ray casting: apakah titik berada di dalam cincin. */
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

/** Apakah titik jatuh di dalam salah satu poligon bidang? */
function diPoligon(float $x, float $y, array $kisi, array $poligon, array $besar): bool
{
    foreach (array_keys($kisi[(int) floor($x / SEL_KISI) . ':' . (int) floor($y / SEL_KISI)] ?? []) as $id) {
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

/** Kelas sebuah teks label. */
function kelasLabel(string $teks): ?string
{
    foreach (KELAS as $kelas => $pola) {
        if (preg_match($pola, mb_strtoupper($teks)) === 1) {
            return $kelas;
        }
    }
    return null;
}

/** Satu kolam label dari semua berkas sumber. */
function bacaLabel(): array
{
    $kisi = [];
    $label = [];
    $jumlah = [];
    foreach (BERKAS_LABEL as $berkas) {
        foreach (geojsonBaca(DIR_LAPISAN . '/' . $berkas . '.geojson') as $f) {
            $k = $f['geometry']['coordinates'] ?? [null, null];
            if (!is_numeric($k[0]) || !is_numeric($k[1])) {
                continue;
            }
            $teks = trim((string) ($f['properties']['label'] ?? ''));
            $kelas = kelasLabel($teks);
            if ($kelas === null) {
                continue;
            }
            $id = count($label);
            $label[$id] = [$teks, (float) $k[0], (float) $k[1], $kelas];
            $kisi[(int) floor((float) $k[0] / SEL) . ':' . (int) floor((float) $k[1] / SEL)][$id] = true;
            $jumlah[$kelas] = ($jumlah[$kelas] ?? 0) + 1;
        }
    }
    return [$kisi, $label, $jumlah];
}

/**
 * Tepi air terbit sebagai segmen berarah: [kisi, segmen] dengan id berkas di
 * kolom keenam, supaya dua segmen dari satu garis tidak dianggap dua tepi.
 */
function bacaTepiAir(): array
{
    $kisi = [];
    $segmen = [];
    $fitur = 0;
    foreach (BERKAS_AIR as $berkas) {
        $path = DIR_LAPISAN . '/' . $berkas . '.geojson';
        if (!is_file($path)) {
            galat('berkas tepi air tidak ada: ' . $berkas . '.geojson');
        }
        foreach (geojsonBaca($path) as $f) {
            $g = $f['geometry'] ?? null;
            if (($g['type'] ?? '') !== 'LineString') {
                continue;
            }
            $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
                (array) ($g['coordinates'] ?? []));
            if (count($titik) < 2) {
                continue;
            }
            $fitur++;
            tambahSegmen($kisi, $segmen, $titik, SEL_PASANG, count($segmen));
        }
    }
    return [$kisi, $segmen, $fitur];
}

/** Tengah koridor berada di antara dua tepi air yang sejajar dengan arah ini? */
function diKoridorAir(array $kisi, array $segmen, float $px, float $py, float $arah): bool
{
    $batas = (int) ceil(RAGU_AIR / SEL_PASANG);
    $gx = (int) floor($px / SEL_PASANG);
    $gy = (int) floor($py / SEL_PASANG);
    $a = deg2rad($arah);
    $nx = -sin($a);
    $ny = cos($a);
    $sisi = [];
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                $s = $segmen[$id];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                if ($pan <= 0.0 || bedaArah($arah, $s[4]) > SUDUT_AIR) {
                    continue;
                }
                $t = max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan));
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $jarak = hypot($px - $fx, $py - $fy);
                if ($jarak <= 0.05 || $jarak > RAGU_AIR) {
                    continue;
                }
                // Sisi diukur terhadap arah pasangan, bukan arah segmen airnya:
                // dua tepi satu selokan bisa digitasi dengan arah berlawanan.
                $belok = ($px - $fx) * $nx + ($py - $fy) * $ny;
                $lagu = $belok > 0.0 ? 'kiri' : 'kanan';
                $fid = (int) $s[5];
                $sisi[$lagu][$fid] = min($sisi[$lagu][$fid] ?? INF, $jarak);
            }
        }
    }
    foreach ($sisi['kiri'] ?? [] as $fid => $_) {
        foreach ($sisi['kanan'] ?? [] as $gid => $_) {
            if ($fid !== $gid) {
                return true;
            }
        }
    }
    return false;
}

/** Cari sisi di seberang koridor: beda sisi, rata, lebar dalam batas, tengah kosong. */
function pasanganTerdekat(array $kisi, array $segmen, array $komiSegmen, float $px, float $py,
    float $radius, int $komiSendiri, float $arah, array $kisiPoligon, array $poligon,
    array $poligonBesar, array $kisiAir, array $segmenAir, int &$tolakAir): ?array
{
    $batas = (int) ceil($radius / SEL_PASANG);
    $gx = (int) floor($px / SEL_PASANG);
    $gy = (int) floor($py / SEL_PASANG);
    $baik = null;
    $kenaAir = false;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                if ($komiSegmen[$id] === $komiSendiri) {
                    continue;
                }
                $s = $segmen[$id];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                $t = $pan > 0.0 ? max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan)) : 0.0;
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $jarak = hypot($px - $fx, $py - $fy);
                if ($jarak < LEBAR_MIN || $jarak > $radius || bedaArah($arah, $s[4]) > SUDUT_PASANG) {
                    continue;
                }
                if (diPoligon(($px + $fx) / 2, ($py + $fy) / 2, $kisiPoligon, $poligon, $poligonBesar)) {
                    continue;
                }
                if (diKoridorAir($kisiAir, $segmenAir, ($px + $fx) / 2, ($py + $fy) / 2, $arah)) {
                    $kenaAir = true;
                    continue;
                }
                if ($baik === null || $jarak < $baik[0]) {
                    $baik = [$jarak, $fx, $fy, $segmen[$id][5]];
                }
            }
        }
    }
    if ($kenaAir) {
        $tolakAir++;
    }
    return $baik;
}

/**
 * Diagnostik untuk sisi yang tidak terpasang: seberapa jauh sisi sejajar
 * terdekat (tanpa uji koridor) dan seberapa jauh yang koridor tengahnya kosong.
 * Mengembalikan [jarakTerdekat, jarakKosongAtauNull].
 */
function seberangTerdekat(array $kisi, array $segmen, array $komiSegmen, float $px, float $py,
    float $radius, int $komiSendiri, float $arah, array $kisiPoligon, array $poligon,
    array $poligonBesar, array $kisiAir, array $segmenAir): array
{
    $batas = (int) ceil($radius / SEL_PASANG);
    $gx = (int) floor($px / SEL_PASANG);
    $gy = (int) floor($py / SEL_PASANG);
    $dekat = null;
    $kosong = null;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                if ($komiSegmen[$id] === $komiSendiri) {
                    continue;
                }
                $s = $segmen[$id];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                $t = $pan > 0.0 ? max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan)) : 0.0;
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $jarak = hypot($px - $fx, $py - $fy);
                if ($jarak < LEBAR_MIN || $jarak > $radius || bedaArah($arah, $s[4]) > SUDUT_PASANG) {
                    continue;
                }
                if ($dekat === null || $jarak < $dekat) {
                    $dekat = $jarak;
                }
                if (!diPoligon(($px + $fx) / 2, ($py + $fy) / 2, $kisiPoligon, $poligon, $poligonBesar)
                    && !diKoridorAir($kisiAir, $segmenAir, ($px + $fx) / 2, ($py + $fy) / 2, $arah)
                    && ($kosong === null || $jarak < $kosong)) {
                    $kosong = $jarak;
                }
            }
        }
    }
    return [$dekat, $kosong];
}

/** Cari simpul terdekat dalam ragum, atau buat baru. */
function simpul(array &$ragu, array &$titik, float $x, float $y, float $ragum): int
{
    $gx = (int) floor($x / $ragum);
    $gy = (int) floor($y / $ragum);
    $baik = null;
    $jarakBaik = INF;
    for ($ix = $gx - 1; $ix <= $gx + 1; $ix++) {
        for ($iy = $gy - 1; $iy <= $gy + 1; $iy++) {
            foreach (array_keys($ragu[$ix . ':' . $iy] ?? []) as $id) {
                $d = hypot($titik[$id][0] - $x, $titik[$id][1] - $y);
                if ($d <= $ragum && $d < $jarakBaik) {
                    $jarakBaik = $d;
                    $baik = $id;
                }
            }
        }
    }
    if ($baik !== null) {
        return (int) $baik;
    }
    $id = count($titik);
    $titik[$id] = [$x, $y];
    $ragu[$gx . ':' . $gy][$id] = true;
    return $id;
}

function induk(array &$parent, int $a): int
{
    while ($parent[$a] !== $a) {
        $parent[$a] = $parent[$parent[$a]];
        $a = (int) $parent[$a];
    }
    return $a;
}

function gabung(array &$parent, int $a, int $b): void
{
    $ra = induk($parent, $a);
    $rb = induk($parent, $b);
    if ($ra !== $rb) {
        $parent[$rb] = $ra;
    }
}

function tulisBerkas(string $berkas, array $daftar): void
{
    $naskah = json_encode(['type' => 'FeatureCollection', 'features' => $daftar],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($naskah === false) {
        galat('gagal menyusun JSON ' . $berkas);
    }
    if (file_put_contents(DIR_LAPISAN . '/' . $berkas . '.geojson', $naskah) === false) {
        galat('gagal menulis ' . $berkas . '.geojson');
    }
}

/** Cincin luar sebuah Polygon/MultiPolygon GeoJSON, diturunkan ke meter DXF. */
function cincinDariGeojson(array $geometri): array
{
    $tipe = (string) ($geometri['type'] ?? '');
    if (!isset($geometri['coordinates'])) {
        return [];
    }
    $bagian = match ($tipe) {
        'MultiPolygon' => (array) $geometri['coordinates'],
        'Polygon' => [(array) $geometri['coordinates']],
        default => [],
    };
    $keluar = [];
    foreach ($bagian as $poligonBagian) {
        $ring = array_map(
            static fn(array $p): array => wgs84KeDxf((float) $p[0], (float) $p[1]),
            (array) ($poligonBagian[0] ?? [])
        );
        if (count($ring) >= 4) {
            $keluar[] = $ring;
        }
    }
    return $keluar;
}

// ---------------------------------------------------------------------------
// Argumen
// ---------------------------------------------------------------------------

$terapkan = false;
$rinci = false;
$eksklusif = false;
$tahap = 'semua';
$sumberTepi = 'turun';
$lebarMaks = 8.0;
$benihMaks = BENIH;
$bagiPasang = BAGI_PASANG;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--terapkan') {
        $terapkan = true;
    } elseif ($a === '--rinci') {
        $rinci = true;
    } elseif ($a === '--eksklusif') {
        $eksklusif = true;
    } elseif (str_starts_with($a, '--tahap=')) {
        $tahap = substr($a, 8);
    } elseif (str_starts_with($a, '--sumber-tepi=')) {
        $sumberTepi = substr($a, 14);
    } elseif (str_starts_with($a, '--bagi-pasang=')) {
        $bagiPasang = angkaFlag(substr($a, 14), '--bagi-pasang');
    } elseif (str_starts_with($a, '--lebar=')) {
        $lebarMaks = angkaFlag(substr($a, 8), '--lebar');
    } elseif (str_starts_with($a, '--benih=')) {
        $benihMaks = angkaFlag(substr($a, 8), '--benih');
    } else {
        galat('argumen tidak dikenal: ' . $a);
    }
}
if (!in_array($tahap, ['tepi', 'sumbu', 'semua'], true)) {
    galat('--tahap harus tepi, sumbu, atau semua');
}
if (!in_array($sumberTepi, ['turun', 'terbit'], true)) {
    galat('--sumber-tepi harus turun atau terbit');
}
if ($tahap === 'tepi' && $sumberTepi === 'terbit') {
    galat('dengan --sumber-tepi=terbit tidak ada tepi yang diturunkan; pakai --tahap=sumbu');
}
if ($lebarMaks <= LEBAR_MIN) {
    galat('--lebar harus lebih besar dari ' . LEBAR_MIN);
}
if ($bagiPasang < 0.0 || $bagiPasang > 1.0) {
    galat('--bagi-pasang harus antara 0 dan 1');
}
if ($benihMaks <= 0.0 || $benihMaks > PITA) {
    galat('--benih harus antara 0 dan ' . angkaNum(PITA, 1) . ' meter');
}
printf("mode: tahap %s · sumber tepi %s · benih %s m · lebar koridor maksimum %s m · bagi pasang %s · %s · %s\n",
    $tahap, $sumberTepi, angkaNum($benihMaks, 1), angkaNum($lebarMaks, 1), angkaNum($bagiPasang, 2),
    $eksklusif ? 'label jalan harus yang terdekat' : 'kelas lain tidak jadi syarat',
    $terapkan ? 'TERAPKAN' : 'uji kering');

// ---------------------------------------------------------------------------
// Bahan: 020100, tabel bidang, dan label
// ---------------------------------------------------------------------------

$deriveTepi = $sumberTepi === 'turun';
$kisiTepi = [];
$segmenTepi = [];
$kisiUlang = [];
$segmenUlang = [];
$kisiPoligon = [];
$poligon = [];
$poligonBesar = [];
$kandidat = [];
$cincin020100 = [];
$cincinBidang = [];
$jumlahPoligon020100 = 0;

foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_SUMBER . '.geojson') as $indeks => $f) {
    $g = $f['geometry'] ?? null;
    $tipe = (string) ($g['type'] ?? '');
    if ($tipe === 'Polygon') {
        $jumlahPoligon020100++;
        $ring = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) ($g['coordinates'][0] ?? []));
        tambahSegmen($kisiTepi, $segmenTepi, $ring, SEL_KISI);
        tambahPoligon($kisiPoligon, $poligon, $poligonBesar, $ring, SEL_KISI);
        if ($deriveTepi && count($ring) >= 4) {
            $cincin020100[(int) $indeks] = $ring;
        }
    } elseif ($tipe === 'LineString' && count((array) ($g['coordinates'] ?? [])) >= 2) {
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) $g['coordinates']);
        if ($deriveTepi) {
            $kandidat['G:' . (int) $indeks] = ['titik' => $titik, 'panjang' => panjang($titik),
                'sumber' => '020100', 'indeks' => (int) $indeks, 'asal' => 'garis'];
        }
        tambahSegmen($kisiUlang, $segmenUlang, $titik, SEL_KISI);
    }
}
printf("020100: %d poligon tertutup (%d cincin jadi calon) · %d garis terbuka jadi calon"
    . " · %d segmen tergambar\n", $jumlahPoligon020100, count($cincin020100), count($kandidat),
    count($segmenUlang));

$jumlahPersil = 0;
foreach ((array) db_all('SELECT id, ST_AsGeoJSON(geom) AS geojson FROM bidang '
    . "WHERE status <> 'dihapus' AND geom IS NOT NULL ORDER BY id") as $baris) {
    $geometri = json_decode((string) $baris['geojson'], true);
    if (!is_array($geometri)) {
        continue;
    }
    foreach (cincinDariGeojson($geometri) as $ring) {
        $jumlahPersil++;
        tambahSegmen($kisiTepi, $segmenTepi, $ring, SEL_KISI);
        tambahPoligon($kisiPoligon, $poligon, $poligonBesar, $ring, SEL_KISI);
        if ($deriveTepi) {
            $cincinBidang[(int) $baris['id']] = $ring;
        }
    }
}
printf("bidang dari basis data (dibaca saja): %d persil · %d segmen tepi total · kisi permukaan"
    . " %d petak\n", $jumlahPersil, count($segmenTepi), count($kisiPoligon));

[$kisiLbl, $label, $jumlahLabel] = bacaLabel();
$labelJalan = (int) ($jumlahLabel['jalan'] ?? 0);
printf("label: %d titik terpakai (%s)\n", count($label), implode(', ', array_map(
    static fn(string $k, int $n): string => $k . ' ' . $n,
    array_keys($jumlahLabel), array_values($jumlahLabel))));

// ---------------------------------------------------------------------------
// Tahap 1: tepi
// ---------------------------------------------------------------------------

$tepi = [];

$simpanTepi = static function (string $kunci, array $titik, string $sumber, int $indeks, string $asal,
    string $cara, ?float $jarak, ?string $nama, ?float $rasioTepi) use (&$tepi): void {
    $tepi[] = ['kunci' => $kunci, 'titik' => $titik, 'panjang' => panjang($titik), 'sumber' => $sumber,
        'indeks' => $indeks, 'asal' => $asal, 'cara' => $cara, 'jarak' => $jarak, 'label' => $nama,
        'rasioTepi' => $rasioTepi];
};

if ($deriveTepi) {
    // Rasio titik sampel yang jatuh di tepi bidang: seberapa jauh garis ini
    // hanya mengulang sisi bidang yang sudah ada.
    foreach ($kandidat as $kunci => $garis) {
        $s = sampel($garis['titik']);
        $kena = 0;
        foreach ($s as [$x, $y]) {
            if (jarakSegmen($kisiTepi, $segmenTepi, $x, $y, TOLERANSI_TEPI, SEL_KISI) !== null) {
                $kena++;
            }
        }
        $kandidat[$kunci]['sampel'] = $s;
        $kandidat[$kunci]['rasioTepi'] = $kena / max(1, count($s));
    }

    // Cincin 020100 lalu cincin tabel bidang, masing-masing diurut dari keliling
    // terbesar dan ditolak bila >= BAGI_ULANG titiknya sudah tergambar lebih dulu.
    $cincinDipakai = ['020100' => 0, 'bidang' => 0];
    $cincinMengulang = ['020100' => 0, 'bidang' => 0];
    $cincinPendek = ['020100' => 0, 'bidang' => 0];
    foreach (['020100' => $cincin020100, 'bidang' => $cincinBidang] as $asal => $daftarCincin) {
        uasort($daftarCincin, static fn(array $a, array $b): int => panjang($b) <=> panjang($a));
        foreach ($daftarCincin as $indeks => $ring) {
            $p = panjang($ring);
            if ($p < MIN_PANJANG) {
                $cincinPendek[$asal]++;
                continue;
            }
            $s = sampel($ring);
            $kena = 0;
            foreach ($s as [$x, $y]) {
                if (jarakSegmen($kisiUlang, $segmenUlang, $x, $y, TOLERANSI_TEPI, SEL_KISI) !== null) {
                    $kena++;
                }
            }
            if ($kena / max(1, count($s)) >= BAGI_ULANG) {
                $cincinMengulang[$asal]++;
                continue;
            }
            $kandidat[($asal === '020100' ? 'P:' : 'B:') . $indeks] = ['titik' => $ring, 'panjang' => $p,
                'sampel' => $s, 'rasioTepi' => $kena / max(1, count($s)),
                'sumber' => $asal === '020100' ? '020100' : 'tabel-bidang', 'indeks' => (int) $indeks,
                'asal' => $asal === '020100' ? 'cincin-020100' : 'cincin-bidang'];
            $cincinDipakai[$asal]++;
            tambahSegmen($kisiUlang, $segmenUlang, $ring, SEL_KISI);
        }
        printf("cincin %s: %d diuji · %d dipakai sebagai calon tepi · %d mengulang garis lain"
            . " · %d terlalu pendek\n", $asal, count($daftarCincin), $cincinDipakai[$asal],
            $cincinMengulang[$asal], $cincinPendek[$asal]);
    }

    $kisiKlaim = [];
    $segmenKlaim = [];
    $terklaim = [];
    $buangPendek = 0;
    $lainDepan = 0;

    $klaim = static function (string $kunci, string $cara, ?float $jarak, ?string $nama) use (
        &$kandidat, &$kisiKlaim, &$segmenKlaim, &$terklaim
    ): void {
        $terklaim[$kunci] = ['cara' => $cara, 'jarak' => $jarak, 'label' => $nama];
        tambahSegmen($kisiKlaim, $segmenKlaim, $kandidat[$kunci]['titik'], SEL, (string) ($nama ?? ''));
    };

    foreach ($kandidat as $kunci => $garis) {
        if ($garis['panjang'] < MIN_PANJANG) {
            $buangPendek++;
            continue;
        }
        $jalan = null;
        foreach ($garis['sampel'] as [$x, $y]) {
            $c = labelTerdekat($kisiLbl, $label, $x, $y, $benihMaks, 'jalan');
            if ($c !== null && ($jalan === null || $c[0] < $jalan[0])) {
                $jalan = $c;
            }
            if ($jalan !== null && $jalan[0] <= 0.01) {
                break;
            }
        }
        if ($jalan === null) {
            continue;
        }
        $lain = null;
        foreach ($garis['sampel'] as [$x, $y]) {
            $d = labelTerdekat($kisiLbl, $label, $x, $y, $benihMaks, 'lain');
            if ($d !== null && ($lain === null || $d[0] < $lain[0])) {
                $lain = $d;
            }
        }
        if ($lain !== null && $lain[0] < $jalan[0]) {
            $lainDepan++;
            if ($eksklusif) {
                continue;
            }
        }
        $klaim((string) $kunci, 'benih', $jalan[0], $jalan[1]);
    }
    $benih = count($terklaim);

    $tolakSejajar = [];
    $tolakKoridor = [];
    for ($putaran = 0; $putaran < PUTARAN; $putaran++) {
        $baru = 0;
        foreach ($kandidat as $kunci => $garis) {
            if ($garis['panjang'] < MIN_PANJANG || isset($terklaim[$kunci])) {
                continue;
            }
            $sejajar = 0;
            $kosong = 0;
            $jarakLbl = null;
            $namaLbl = null;
            foreach ($garis['sampel'] as [$x, $y, $arah]) {
                $dekat = jarakSegmen($kisiKlaim, $segmenKlaim, $x, $y, PITA, SEL);
                if ($dekat === null || bedaArah($arah, $dekat[1]) > SUDUT) {
                    continue;
                }
                if ($sejajar === 0) {
                    $jarakLbl = $dekat[0];
                    $namaLbl = (string) ($dekat[4] ?? '');
                }
                $sejajar++;
                if ($dekat[0] < 0.5
                    || !diPoligon(($x + $dekat[2]) / 2, ($y + $dekat[3]) / 2, $kisiPoligon, $poligon,
                        $poligonBesar)) {
                    $kosong++;
                }
            }
            if ($sejajar < BAGI_SEJAJAR * count($garis['sampel'])) {
                $tolakSejajar[(string) $kunci] = true;
                continue;
            }
            if ($kosong < BAGI_KOSONG * $sejajar) {
                $tolakKoridor[(string) $kunci] = true;
                continue;
            }
            $klaim((string) $kunci, 'pita' . ($putaran + 1), $jarakLbl, $namaLbl);
            $baru++;
        }
        if ($baru === 0) {
            break;
        }
    }

    foreach ($kandidat as $kunci => $garis) {
        if (!isset($terklaim[$kunci]) || $garis['panjang'] < MIN_PANJANG) {
            continue;
        }
        $isi = $terklaim[$kunci];
        $simpanTepi((string) $kunci, $garis['titik'], $garis['sumber'], (int) $garis['indeks'],
            $garis['asal'], $isi['cara'], $isi['jarak'], $isi['label'], $garis['rasioTepi']);
    }
    $caraTepi = array_count_values(array_column($tepi, 'cara'));
    printf("tepi: %d baris diklaim dari %d calon (%s) · %s m · benih %d, pita %d, tolak sejajar %d,"
        . " tolak koridor %d, pendek %d, label kelas lain lebih dekat %d\n",
        count($tepi), count($kandidat), implode('/', array_map(
            static fn(string $k, int $n): string => $k . ' ' . $n, array_keys($caraTepi),
            array_values($caraTepi))),
        angkaNum((float) array_sum(array_column($tepi, 'panjang')), 1), $benih, count($tepi) - $benih,
        count($tolakSejajar), count($tolakKoridor), $buangPendek, $lainDepan);
} else {
    foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_TERBIT . '.geojson') as $f) {
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) ($f['geometry']['coordinates'] ?? []));
        if (count($titik) < 2) {
            continue;
        }
        $p = $f['properties'] ?? [];
        $simpanTepi((string) ($p['kode'] ?? count($tepi)), $titik, (string) ($p['sumber'] ?? '020100'),
            (int) ($p['indeks'] ?? 0), (string) ($p['asal'] ?? 'garis'), (string) ($p['cara'] ?? ''),
            isset($p['jarak_label_m']) && $p['jarak_label_m'] !== null ? (float) $p['jarak_label_m'] : null,
            isset($p['label']) ? (string) $p['label'] : null,
            isset($p['rasio_tepi_bidang']) ? (float) $p['rasio_tepi_bidang'] : null);
    }
    printf("tepi dari lapis terbit %s.geojson: %d baris · %s m\n", BERKAS_TERBIT, count($tepi),
        angkaNum((float) array_sum(array_column($tepi, 'panjang')), 1));
}

if ($tepi === []) {
    galat('tidak ada satu pun tepi yang bisa dipakai');
}

$berkasTepi = [];
foreach ($tepi as $nomor => $t) {
    $berkasTepi[] = [
        'type' => 'Feature',
        'properties' => [
            'kode' => sprintf('TEPI-%04d', $nomor + 1),
            'kunci' => $t['kunci'],
            'sumber' => $t['sumber'],
            'indeks' => $t['indeks'],
            'panjang_m' => round($t['panjang'], 2),
            'rasio_tepi_bidang' => $t['rasioTepi'] === null ? null : round($t['rasioTepi'], 3),
            'asal' => $t['asal'],
            'cara' => $t['cara'],
            'jarak_label_m' => $t['jarak'] === null ? null : round($t['jarak'], 2),
            'label' => $t['label'],
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $t['titik']],
    ];
}

if ($terapkan) {
    tulisBerkas(BERKAS_TEPI, $berkasTepi);
}
printf("%-26s %5d fitur · %10s m · %s\n", BERKAS_TEPI . '.geojson', count($berkasTepi),
    angkaNum((float) array_sum(array_column($tepi, 'panjang')), 1),
    $terapkan ? 'ditulis' : 'uji kering');

if ($tahap === 'tepi') {
    echo 'tahap sumbu dilewati (--tahap=tepi).' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// Tahap 2: sumbu
// ---------------------------------------------------------------------------

// 1. Sisi: potongan tepi yang berbagi simpul adalah satu sisi jalan.
$parent = [];
for ($id = 0; $id < count($tepi); $id++) {
    $parent[$id] = $id;
}
$raguSisi = [];
$titikSisi = [];
$tepidiSimpul = [];
foreach ($tepi as $id => $t) {
    $ujung = $t['titik'][0];
    $pangkal = $t['titik'][count($t['titik']) - 1];
    $a = simpul($raguSisi, $titikSisi, (float) $ujung[0], (float) $ujung[1], RAGU_SISI);
    $b = simpul($raguSisi, $titikSisi, (float) $pangkal[0], (float) $pangkal[1], RAGU_SISI);
    $tepidiSimpul[$a][$id] = true;
    $tepidiSimpul[$b][$id] = true;
}
foreach ($tepidiSimpul as $anggota) {
    $ids = array_keys($anggota);
    for ($i = 1; $i < count($ids); $i++) {
        gabung($parent, (int) $ids[0], (int) $ids[$i]);
    }
}
$komi = [];
foreach ($tepi as $id => $t) {
    $komi[$id] = induk($parent, (int) $id);
}
$sisi = count(array_unique($komi));

// 2. Pasangan di seberang koridor.
$kisiPasang = [];
$segmenPasang = [];
$komiSegmen = [];
foreach ($tepi as $id => $t) {
    $sebelum = count($segmenPasang);
    tambahSegmen($kisiPasang, $segmenPasang, $t['titik'], SEL_PASANG, $id);
    for ($i = $sebelum; $i < count($segmenPasang); $i++) {
        $komiSegmen[$i] = $komi[$id];
    }
}
$terpasang = 0;
$tunggal = 0;
$panjangTunggal = 0.0;
$setengah = 0;
$koridorTertutup = 0;
$tolakAir = 0;
[$kisiAir, $segmenAir, $jumlahAir] = bacaTepiAir();
printf("tepi air: %d baris dari %s · %d segmen · ragum %s m · sudut %s derajat\n",
    $jumlahAir, implode(' + ', BERKAS_AIR), count($segmenAir), angkaNum(RAGU_AIR, 1),
    angkaNum(SUDUT_AIR, 0));
$pitaTunggal = ['<=2 m' => 0, '2-4 m' => 0, '4-8 m' => 0, '8-15 m' => 0, 'tidak ada sisi sejajar' => 0];
$jalur = [];
$pasangan = [];
foreach ($tepi as $id => $t) {
    $s = sampel($t['titik']);
    $oleh = [];
    foreach ($s as [$x, $y, $arah]) {
        $p = pasanganTerdekat($kisiPasang, $segmenPasang, $komiSegmen, $x, $y, $lebarMaks, (int) $komi[$id],
            $arah, $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir, $tolakAir);
        if ($p === null) {
            continue;
        }
        $lain = (int) $komi[$p[3]];
        $oleh[$lain]['n'] = ($oleh[$lain]['n'] ?? 0) + 1;
        $oleh[$lain]['titik'][] = [($x + $p[1]) / 2, ($y + $p[2]) / 2, $p[0], (int) $id];
    }
    $utama = null;
    foreach ($oleh as $k => $isi) {
        if ($utama === null || $isi['n'] > $oleh[$utama]['n']) {
            $utama = $k;
        }
    }
    $sebab = null;
    if ($utama === null) {
        $sebab = 'sejajar';
    } elseif ($oleh[$utama]['n'] < $bagiPasang * count($s)) {
        $sebab = 'setengah';
    }
    if ($sebab !== null) {
        $tunggal++;
        $panjangTunggal += $t['panjang'];
        $jjarak = null;
        $jkosong = null;
        foreach ($s as [$x, $y, $arah]) {
            [$d, $k] = seberangTerdekat($kisiPasang, $segmenPasang, $komiSegmen, $x, $y, SEBERANG_UJI,
                (int) $komi[$id], $arah, $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir);
            if ($d !== null && ($jjarak === null || $d < $jjarak)) {
                $jjarak = $d;
            }
            if ($k !== null && ($jkosong === null || $k < $jkosong)) {
                $jkosong = $k;
            }
        }
        if ($jjarak === null) {
            $pitaTunggal['tidak ada sisi sejajar'] = ($pitaTunggal['tidak ada sisi sejajar'] ?? 0) + 1;
        } else {
            $nama = $jjarak <= 2 ? '<=2 m' : ($jjarak <= 4 ? '2-4 m'
                : ($jjarak <= $lebarMaks ? '4-' . angkaNum($lebarMaks, 0) . ' m'
                    : angkaNum($lebarMaks, 0) . '-' . angkaNum(SEBERANG_UJI, 0) . ' m'));
            $pitaTunggal[$nama] = ($pitaTunggal[$nama] ?? 0) + 1;
        }
        if ($jjarak !== null && $jkosong === null) {
            $koridorTertutup++;
        }
        if ($sebab === 'setengah') {
            $setengah++;
        }
        continue;
    }
    $terpasang++;
    $pasangan[min((int) $komi[$id], $utama) . ':' . max((int) $komi[$id], $utama)] = true;
    $jalur[] = $oleh[$utama]['titik'];
}
printf("sisi: %d komponen tepi · %d baris menyumbang titik tengah · %d baris tanpa pasangan sama"
    . " sekali (%s m panjangnya, %d di antaranya kandas karena ambang --bagi-pasang %s)\n", $sisi,
    $terpasang, $tunggal, angkaNum($panjangTunggal, 1), $setengah, angkaNum($bagiPasang, 2));
printf("       sisi tunggal: sisi sejajar terdekat %s · %d sisi sejajar tapi koridornya selalu"
    . " tertutup (bidang atau air)\n", json_encode($pitaTunggal, JSON_UNESCAPED_UNICODE), $koridorTertutup);
printf("koridor: %d pasangan sisi berbeda · %d untai titik tengah · titik sampel dengan kandidat"
    . " tertolak air: %d\n", count($pasangan), count($jalur), $tolakAir);

// 3. Renggut titik tengah dan rangkai menjadi rantai.
$raguSum = [];
$titikSum = [];
$jumlahSum = [];
$bebanSum = [];
$tepiSum = [];
$jumX = [];
$jumY = [];
$tali = [];
foreach ($jalur as $urutan) {
    $sebelum = null;
    foreach ($urutan as $p) {
        $nd = simpul($raguSum, $titikSum, (float) $p[0], (float) $p[1], RAGU_SUMBU);
        $jumlahSum[$nd] = ($jumlahSum[$nd] ?? 0) + 1;
        $bebanSum[$nd] = ($bebanSum[$nd] ?? 0.0) + (float) $p[2];
        $jumX[$nd] = ($jumX[$nd] ?? 0.0) + (float) $p[0];
        $jumY[$nd] = ($jumY[$nd] ?? 0.0) + (float) $p[1];
        $tepiSum[$nd][(int) $p[3]] = true;
        if ($sebelum !== null && $sebelum !== $nd) {
            $tali[$sebelum . ':' . $nd] = [$sebelum, $nd];
        }
        $sebelum = $nd;
    }
}
// Koordinat tiap simpul rantai = rata-rata semua titik tengah yang direnggut
// ke dalamnya, jadi sisi kiri dan kanan sebuah jalan lurus menyatu ke satu
// garis, bukan dua garis sejajar.
foreach ($titikSum as $nd => $p) {
    $titikSum[$nd] = [$jumX[$nd] / $jumlahSum[$nd], $jumY[$nd] / $jumlahSum[$nd]];
}
$adj = [];
foreach ($tali as [$a, $b]) {
    $adj[$a][$b] = true;
    $adj[$b][$a] = true;
}
$simpang = count(array_filter($adj, static fn(array $nb): bool => count($nb) >= 3));
printf("rangka: %d simpul · %d batang · %d simpul persimpangan (derajat >= 3)\n", count($titikSum),
    count($tali), $simpang);

$dipakai = [];
$jalurSum = [];
$jejak = static function (int $a, int $b) use (&$adj, &$dipakai): array {
    $out = [$a];
    while (true) {
        $out[] = $b;
        $dipakai[$a . ':' . $b] = true;
        $dipakai[$b . ':' . $a] = true;
        if (count($adj[$b] ?? []) !== 2) {
            break;
        }
        $berikut = null;
        foreach (array_keys($adj[$b]) as $k) {
            if ((int) $k !== $a && !isset($dipakai[$b . ':' . $k])) {
                $berikut = (int) $k;
                break;
            }
        }
        if ($berikut === null) {
            break;
        }
        $a = $b;
        $b = $berikut;
    }
    return $out;
};
foreach ($adj as $a => $nb) {
    foreach (array_keys($nb) as $b) {
        if (isset($dipakai[$a . ':' . $b])) {
            continue;
        }
        $jalurSum[] = $jejak((int) $a, (int) $b);
    }
}

// 4. Satu sumbu per jalur.
$buangPendekSumbu = 0;
$fitur = [];
$pitaLebar = ['<=1 m' => 0, '1-2 m' => 0, '2-4 m' => 0, '4-6 m' => 0, '>6 m' => 0];
$jarakTepi = [];
$jarakHilang = 0;
foreach ($jalurSum as $nd) {
    $titik = [];
    foreach ($nd as $i) {
        $p = $titikSum[$i];
        if ($titik !== [] && hypot($p[0] - $titik[count($titik) - 1][0], $p[1] - $titik[count($titik) - 1][1])
            < 0.01) {
            continue;
        }
        $titik[] = $p;
    }
    $p = count($titik) > 1 ? panjang($titik) : 0.0;
    if ($p < MIN_PANJANG) {
        $buangPendekSumbu++;
        continue;
    }
    $beban = 0.0;
    $n = 0;
    $tepinya = [];
    $labelnya = [];
    foreach ($nd as $i) {
        $beban += (float) ($bebanSum[$i] ?? 0.0);
        $n += (int) ($jumlahSum[$i] ?? 0);
        foreach (array_keys($tepiSum[$i] ?? []) as $id) {
            $tepinya[(int) $id] = true;
        }
    }
    foreach ($tepinya as $id) {
        $l = $tepi[$id]['label'];
        if ($l !== null && $l !== '') {
            $labelnya[$l] = ($labelnya[$l] ?? 0) + 1;
        }
    }
    $lebar = $n > 0 ? $beban / $n : 0.0;
    foreach ($titik as $tp) {
        $dekat = jarakSegmen($kisiPasang, $segmenPasang, (float) $tp[0], (float) $tp[1],
            $lebarMaks + 2.0, SEL_PASANG);
        if ($dekat === null) {
            $jarakHilang++;
        } else {
            $jarakTepi[] = $dekat[0];
        }
    }
    $asal = array_values(array_unique(array_map(
        static fn(int $id): string => $tepi[$id]['asal'], array_keys($tepinya))));
    $cara = array_count_values(array_map(
        static fn(int $id): string => $tepi[$id]['cara'], array_keys($tepinya)));
    arsort($labelnya);
    $pita = match (true) {
        $lebar <= 1 => '<=1 m',
        $lebar <= 2 => '1-2 m',
        $lebar <= 4 => '2-4 m',
        $lebar <= 6 => '4-6 m',
        default => '>6 m',
    };
    $pitaLebar[$pita]++;
    $fitur[] = ['panjang' => $p, 'titik' => $titik, 'lebar' => $lebar,
        'tepinya' => array_map(static fn(int $id): string => $tepi[$id]['kunci'], array_keys($tepinya)),
        'label' => array_key_first($labelnya), 'asal' => count($asal) > 1 ? 'campur' : $asal[0],
        'cara' => $cara, 'pita' => $pita];
}
usort($fitur, static fn(array $a, array $b): int => $b['panjang'] <=> $a['panjang']);

$daftar = [];
foreach ($fitur as $i => $f) {
    $daftar[] = [
        'type' => 'Feature',
        'properties' => [
            'kode' => sprintf('SUMBU-%04d', $i + 1),
            'sumber' => 'label-jalan',
            'panjang_m' => round($f['panjang'], 2),
            'lebar_m' => round($f['lebar'], 2),
            'jumlah_bagian' => count($f['titik']),
            'jumlah_tepi' => count($f['tepinya']),
            'tepi_all' => $f['tepinya'],
            'asal' => $f['asal'],
            'cara_tepi' => $f['cara'],
            'cara' => 'sumbu',
            'label' => $f['label'],
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $f['titik']],
    ];
}

sort($jarakTepi);
$medan = static fn(array $a): float => $a === [] ? 0.0 : (float) $a[(int) floor(count($a) / 2)];
$panjangSum = array_sum(array_map(static fn(array $f): float => $f['panjang'], $fitur));
$lebarSum = $panjangSum > 0.0
    ? array_sum(array_map(static fn(array $f): float => $f['lebar'] * $f['panjang'], $fitur)) / $panjangSum
    : 0.0;
printf("sumbu: %d segmen · %s m · lebar timbang panjang %s m · pita lebar %s\n", count($fitur),
    angkaNum($panjangSum, 1), angkaNum($lebarSum, 2), json_encode($pitaLebar, JSON_UNESCAPED_UNICODE));
printf("       dibuang pendek (< %s m): %d · jarak titik sumbu ke tepi: median %s m, maksimum %s m"
    . " (%d titik diuji, %d tidak menemukan tepi dalam %s m)\n", angkaNum(MIN_PANJANG, 1),
    $buangPendekSumbu, angkaNum($medan($jarakTepi), 2),
    angkaNum((float) ($jarakTepi === [] ? 0.0 : max($jarakTepi)), 2), count($jarakTepi), $jarakHilang,
    angkaNum($lebarMaks + 2.0, 1));

if ($rinci) {
    foreach (array_slice($fitur, 0, 40) as $i => $f) {
        printf("  SUMBU-%04d %8s m · lebar %5s m · %2d titik · %3d tepi · %s\n", $i + 1,
            angkaNum($f['panjang'], 1), angkaNum($f['lebar'], 2), count($f['titik']),
            count($f['tepinya']), (string) ($f['label'] ?? '(tanpa label)'));
    }
}

if ($terapkan) {
    tulisBerkas(BERKAS_SUMBU, $daftar);
    foreach ([BERKAS_TEPI, BERKAS_SUMBU] as $berkas) {
        $cek = geojsonBaca(DIR_LAPISAN . '/' . $berkas . '.geojson');
        $jenis = array_count_values(array_map(
            static fn(array $f): string => (string) ($f['geometry']['type'] ?? '?'), $cek));
        $ringkas = [];
        foreach ($jenis as $tipe => $n) {
            $ringkas[] = $tipe . ' ' . $n;
        }
        printf("validasi baca ulang %-22s %d fitur · %s\n", $berkas . '.geojson', count($cek),
            implode(', ', $ringkas));
    }
}
printf("%-26s %5d fitur · %10s m · %s\n", BERKAS_SUMBU . '.geojson', count($daftar),
    angkaNum($panjangSum, 1), $terapkan ? 'ditulis' : 'uji kering');

if (!$terapkan) {
    echo PHP_EOL . 'uji kering: tidak ada berkas yang ditulis; pakai --terapkan.' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// metadata.json
// ---------------------------------------------------------------------------

$berkasMeta = DIR_LAPISAN . '/metadata.json';
$meta = is_file($berkasMeta) ? (array) json_decode((string) file_get_contents($berkasMeta), true) : [];
if ($meta === []) {
    galat('metadata.json tidak dapat dibaca: ' . $berkasMeta);
}

$meta['layers'][JUDUL_TEPI] = [
    'features' => count($berkasTepi),
    'types' => ['line' => count($berkasTepi)],
    'file' => BERKAS_TEPI . '.geojson',
    'panjang_m' => (int) round(array_sum(array_column($tepi, 'panjang'))),
    'derived_from' => $deriveTepi
        ? 'kandidat 020100 (garis terbuka dan cincin poligon) serta cincin tabel bidang yang membawa label'
            . ' berawalan JALAN (kelas dibaca dari teks, bukan dari berkas asal) serta pita sejajarnya'
        : 'lapis ' . BERKAS_TERBIT . '.geojson yang sudah terbit, dibaca apa adanya sebagai tepi',
    'ambang' => ['benih_m' => $benihMaks, 'pita_m' => PITA, 'sudut_derajat' => SUDUT, 'putaran' => PUTARAN,
        'bagi_sejajar' => BAGI_SEJAJAR, 'bagi_koridor_kosong' => BAGI_KOSONG,
        'min_panjang_m' => MIN_PANJANG, 'langkah_sampel_m' => LANGKAH, 'bagi_ulang_cincin' => BAGI_ULANG,
        'eksklusif' => $eksklusif],
    'label' => $labelJalan,
    'permukaan_bidang' => ['sumber' => 'tabel bidang (status <> dihapus)', 'persil' => $jumlahPersil,
        'segmen_tepi' => count($segmenTepi)],
    'asal' => array_count_values(array_column($tepi, 'asal')),
    'cara' => array_count_values(array_column($tepi, 'cara')),
];

$meta['layers'][JUDUL_SUMBU] = [
    'features' => count($daftar),
    'types' => ['line' => count($daftar)],
    'file' => BERKAS_SUMBU . '.geojson',
    'panjang_m' => (int) round($panjangSum),
    'derived_from' => 'rata-rata dua sisi tepi ' . BERKAS_TEPI . '.geojson yang saling berhadapan di dalam'
        . ' koridor kosong; satu garis per segmen antara dua ujung atau persimpangan',
    'ambang' => ['lebar_min_m' => LEBAR_MIN, 'lebar_maks_m' => $lebarMaks,
        'sudut_pasang_derajat' => SUDUT_PASANG, 'bagi_pasang' => $bagiPasang, 'ragu_sisi_m' => RAGU_SISI,
        'ragu_sumbu_m' => RAGU_SUMBU, 'min_panjang_m' => MIN_PANJANG, 'sumber_tepi' => $sumberTepi],
    'sisi' => ['komponen' => $sisi, 'terpasang' => $terpasang, 'tunggal' => $tunggal,
        'tunggal_setengah' => $setengah, 'koridor_tertutup' => $koridorTertutup,
        'seberang_terdekat' => $pitaTunggal, 'tunggal_panjang_m' => (int) round($panjangTunggal),
        'koridor' => count($pasangan), 'uji_seberang_m' => SEBERANG_UJI],
    'lebar_m' => ['timbang_panjang' => round($lebarSum, 2), 'pita' => $pitaLebar],
    'verifikasi' => ['titik_diuji' => count($jarakTepi), 'tanpa_tepi' => $jarakHilang,
        'ke_tepi_median_m' => round($medan($jarakTepi), 2),
        'ke_tepi_maksimum_m' => round((float) ($jarakTepi === [] ? 0.0 : max($jarakTepi)), 2)],
    'cara' => ['sumbu' => count($daftar)],
];

$catatan = [];
foreach ((array) ($meta['notes'] ?? []) as $nn) {
    if (!str_starts_with((string) $nn, JUDUL_TEPI) && !str_starts_with((string) $nn, JUDUL_SUMBU)) {
        $catatan[] = $nn;
    }
}
$catatan[] = sprintf(
    '%s dan %s dibuat tools/garis-jalan-label.php pada %s. Tahap 1 mengklaim tepi dari label berawalan'
    . ' JALAN (benih <= %.1f m, pita <= %.1f m dengan beda arah <= %.0f derajat, koridor >= %.0f%% kosong'
    . ' terhadap gabungan cincin 020100 dan %d persil tabel bidang yang hanya dibaca). Tahap 2'
    . ' merata-ratakan dua sisi yang berhadapan <= %.1f m menjadi satu sumbu per segmen: %d baris tepi'
    . ' menyumbang titik tengah dan lahir %d segmen sumbu sepanjang %s m dari %d komponen sisi; %d baris'
    . ' (%s m) tidak punya sisi seberang sama sekali sehingga tidak bisa disumbukan. Koordinat sumbu baru,'
    . ' rata-rata dua tepi, bukan titik turunan 020100. Keduanya hanya lapisan referensi dan belum dipasang'
    . ' di panel Peta Induk.',
    JUDUL_TEPI, JUDUL_SUMBU, date('c'), $benihMaks, PITA, SUDUT, BAGI_KOSONG * 100, $jumlahPersil, $lebarMaks,
    $terpasang, count($daftar), angkaNum($panjangSum, 1), $sisi, $tunggal, angkaNum($panjangTunggal, 1)
);
$meta['notes'] = $catatan;
$meta['derived_at'] = date('c');
if (file_put_contents($berkasMeta, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
    galat('gagal menulis metadata.json');
}
echo "metadata.json diperbarui.\n";
