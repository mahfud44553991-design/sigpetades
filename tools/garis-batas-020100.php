<?php
declare(strict_types=1);

/**
 * Turunkan garis batas jalan, garis batas perairan, dan garis lain-lain dari
 * layer 020100, lalu simpan sebagai GeoJSON untuk halaman Peta Induk.
 *
 *   php -d memory_limit=1024M tools/garis-batas-020100.php
 *
 * Latar. Layer DXF BATAS JALAN (626 fitur) dan BATAS PERAIRAN (279 fitur) hanya
 * berisi teks label tanpa geometri, sedangkan 020100 menyimpan 13.681 garis
 * terbuka tanpa atribut yang bisa membedakan tepi bidang, tepi jalan, dan tepi
 * sungai; warna pun tidak membantu — 16.031 dari 16.322 entitas layer ini
 * berwarna putih. Hasil ukur 10 Nov 2026: 495 dari 626 label jalan berada
 * <= 2 m dari sebuah garis dan 484 di antaranya sejajar dengan garis itu (median
 * beda arah 2,5 derajat). Label perairan tidak menyimpan rotasi teks di berkas
 * GeoJSON-nya, tetapi 171 dari 279-nya tetap menempel pada garis. Jadi label
 * dipakai sebagai jangkar, bukan sebagai classifier.
 *
 * Aturan penurunan (ambang ikut tertulis di metadata hasil):
 *  - benih : garis terbuka yang punya titik sampel <= radius benih jenisnya
 *          (jalan 2 m, perairan 8 m) dari label; inilah garis yang ditumpangi
 *          tulisan nama jalan atau yang mengapit nama sungai;
 *  - pita  : garis yang belum terklaim dan pada mayoritas titik sampelnya
 *          (>= BAGI_SEJAJAR) punya segmen terklaim jenis sama <= PITA meter
 *          dengan beda arah <= SUDUT, DAN koridor di antara keduanya jatuh di
 *          ruang kosong (>= BAGI_KOSONG titik tengah tidak berada di dalam
 *          bidang). Uji arah membuat pita mengikuti tepi jalan di kedua
 *          sisinya tanpa melompat ke garis potong yang tegak lurus; uji koridor
 *          mencegah pita merambat antar-bidang: sisi sebuah bidang selalu
 *          berhadapan dengan bidang lain, sedangkan tepi jalan dan tebing
 *          sungai berhadapan dengan ruang yang bukan bidang tanah. Permukaan
 *          yang diuji adalah poligon tabel bidang, bukan hanya cincin tertutup
 *          020100 — kisi bidang tanah di peta ini sebagian besar tersimpan
 *          sebagai garis terbuka, jadi tanpa tabel bidang 9.690 dari 13.681
 *          garis terjaring pita dan layer lain-lain berisi 2.286 sisi bidang;
 *          sesudah itu tersisa puluhan garis;
 *  - lain  : garis yang berdiri sendiri — kurang dari RASIO_TEPI titik sampelnya
 *          jatuh di tepi sebuah bidang — dan tidak terjaring dua aturan di
 *          atas. Garis yang hanya mengulang sisi bidang tidak masuk, karena
 *          bidang itu sendiri sudah menggambar garis yang sama;
 *  - setiap kelas membuang garis lebih pendek dari MIN_PANJANG.
 *
 * Satu garis yang memenuhi jalan dan perairan sekaligus masuk ke jenis dengan
 * jarak label terkecil. Tabel bidang hanya dibaca sebagai permukaan; tidak ada
 * satu baris pun yang ditulis, dan keluarannya tiga berkas GeoJSON layer baru.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/lib-impor.php';

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const SEL = 25.0;
const SEL_KISI = 5.0;
const LANGKAH = 2.0;
const PITA = 15.0;
const SUDUT = 25.0;
const PUTARAN = 2;
const BAGI_SEJAJAR = 0.5;
const BAGI_KOSONG = 0.6;
const RASIO_TEPI = 0.3;
const TOLERANSI_TEPI = 0.35;
const MIN_PANJANG = 2.0;

/** jenis => [berkas label sumber, nama layer DXF, berkas hasil, radius benih meter] */
const JENIS = [
    'jalan' => ['label' => 'batas-jalan', 'dxf' => 'BATAS JALAN', 'berkas' => 'garis-jalan',
        'benih' => 2.0],
    // Label perairan ("KALI SARI", "SALURAN") ditulis di tengah alur, bukan di
    // tepi seperti nama jalan, jadi benih perairan perlu radius lebih lebar.
    'perairan' => ['label' => 'batas-perairan', 'dxf' => 'BATAS PERAIRAN', 'berkas' => 'garis-perairan',
        'benih' => 8.0],
];

function galat(string $pesan): never
{
    fwrite(STDERR, 'GAGAL: ' . $pesan . PHP_EOL);
    exit(1);
}

/** Beda dua arah garis dalam derajat, 0..90; arah majen tidak dipersoalkan. */
function bedaArah(float $a, float $b): float
{
    $beda = abs(fmod($a - $b, 180.0));
    return $beda > 90.0 ? 180.0 - $beda : $beda;
}

/**
 * Masukkan segmen ke kisi petak $sel.
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

/**
 * Segmen terdekat dalam radius: [jarak, arah, tengahX, tengahY, ...tambahan].
 * `tengah` adalah kaki jarak pada segmen itu, dipakai untuk menandai koridor
 * di antara garis kandidat dan garis terklaim.
 */
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

/** Jarak titik ke label terdekat dalam radius: [jarak, teks label]. */
function jarakLabel(array $kisi, array $label, float $px, float $py, float $radius): ?array
{
    $batas = (int) ceil($radius / SEL);
    $gx = (int) floor($px / SEL);
    $gy = (int) floor($py / SEL);
    $baik = null;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                $jarak = hypot($px - $label[$id][1], $py - $label[$id][2]);
                if ($jarak <= $radius && ($baik === null || $jarak < $baik[0])) {
                    $baik = [$jarak, $label[$id][0]];
                }
            }
        }
    }
    return $baik;
}

/**
 * Titik sampel sebuah garis: setiap LANGKAH meter, dengan arah segmen asalnya.
 *
 * @return list<array{0:float,1:float,2:float}>
 */
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

/**
 * Masukkan cincin poligon ke kisi berdasarkan kotak batasnya. Cincin yang
 * lebih besar dari $maksSel petak disimpan terpisah supaya kisi tidak membengkak.
 */
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

/** Apakah titik jatuh di dalam salah satu poligon? */
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

/** @return array{0:array,1:list<array{0:string,1:float,2:float}>} */
function bacaLabel(string $berkas): array
{
    $kisi = [];
    $label = [];
    foreach (geojsonBaca(DIR_LAPISAN . '/' . $berkas . '.geojson') as $f) {
        $k = $f['geometry']['coordinates'] ?? [null, null];
        if (!is_numeric($k[0]) || !is_numeric($k[1])) {
            continue;
        }
        $teks = trim((string) ($f['properties']['label'] ?? ''));
        $id = count($label);
        $label[$id] = [$teks === '' ? '(tanpa teks)' : $teks, (float) $k[0], (float) $k[1]];
        $kisi[(int) floor((float) $k[0] / SEL) . ':' . (int) floor((float) $k[1] / SEL)][$id] = true;
    }
    return [$kisi, $label];
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

$induk = geojsonBaca(DIR_LAPISAN . '/020100.geojson');

$kisiTepi = [];
$segmenTepi = [];
$kisiPoligon = [];
$poligon = [];
$poligonBesar = [];
$terbuka = [];
$jumlahPoligon = 0;
foreach ($induk as $indeks => $f) {
    $g = $f['geometry'] ?? null;
    $tipe = (string) ($g['type'] ?? '');
    if ($tipe === 'Polygon') {
        $jumlahPoligon++;
        $ring = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) ($g['coordinates'][0] ?? []));
        tambahSegmen($kisiTepi, $segmenTepi, $ring, SEL_KISI);
        tambahPoligon($kisiPoligon, $poligon, $poligonBesar, $ring, SEL_KISI);
    } elseif ($tipe === 'LineString' && count((array) ($g['coordinates'] ?? [])) >= 2) {
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) $g['coordinates']);
        $terbuka[(int) $indeks] = ['titik' => $titik, 'panjang' => panjangRing($titik), 'sampel' => sampel($titik)];
    }
}
printf("020100: %d poligon tertutup (%d segmen tepi) · %d garis terbuka\n",
    $jumlahPoligon, count($segmenTepi), count($terbuka));

// Permukaan bidang dari basis data. Hanya sebagian kecil garis 020100 tersimpan
// sebagai poligon tertutup, sedangkan kisi bidang tanah terbentuk dari garis
// terbuka yang saling bersentuhan — tanpa permukaan itu koridor di dalam sebidang
// tanah tampak "kosong" dan pita jalan merambat antar-bidang.
$jumlahBidang = 0;
foreach ((array) db_all("SELECT ST_AsGeoJSON(geom) AS geojson FROM bidang "
    . "WHERE status <> 'dihapus' AND geom IS NOT NULL") as $baris) {
    $geometri = json_decode((string) $baris['geojson'], true);
    if (!is_array($geometri) || !isset($geometri['type'], $geometri['coordinates'])) {
        continue;
    }
    $bagian = $geometri['type'] === 'MultiPolygon' ? (array) $geometri['coordinates']
        : ([$geometri['type'] === 'Polygon' ? $geometri['coordinates'] : []]);
    foreach ($bagian as $poligonBagian) {
        $ring = array_map(
            static fn(array $p): array => wgs84KeDxf((float) $p[0], (float) $p[1]),
            (array) ($poligonBagian[0] ?? [])
        );
        if (count($ring) < 4) {
            continue;
        }
        $jumlahBidang++;
        tambahSegmen($kisiTepi, $segmenTepi, $ring, SEL_KISI);
        tambahPoligon($kisiPoligon, $poligon, $poligonBesar, $ring, SEL_KISI);
    }
}
printf("bidang dari basis data: %d poligon · %d segmen tepi · kisi %d petak\n",
    $jumlahBidang, count($segmenTepi), count($kisiTepi));

// Rasio titik sampel yang jatuh di tepi bidang: seberapa jauh garis ini hanya
// mengulang sisi bidang yang sudah ada.
foreach ($terbuka as $indeks => $garis) {
    $kena = 0;
    foreach ($garis['sampel'] as [$x, $y]) {
        if (jarakSegmen($kisiTepi, $segmenTepi, $x, $y, TOLERANSI_TEPI, SEL_KISI) !== null) {
            $kena++;
        }
    }
    $terbuka[$indeks]['rasioTepi'] = $kena / max(1, count($garis['sampel']));
}

$klaim = [];
foreach (JENIS as $jenis => $set) {
    [$kisiLbl, $label] = bacaLabel($set['label']);
    $kisiKlaim = [];
    $segmenKlaim = [];
    $terklaim = [];

    $ambil = static function (int $indeks, string $cara, float $jarak, string $namaLabel) use (
        &$terbuka, &$kisiKlaim, &$segmenKlaim, &$terklaim
    ): void {
        $terklaim[$indeks] = ['cara' => $cara, 'jarak' => $jarak, 'label' => $namaLabel];
        tambahSegmen($kisiKlaim, $segmenKlaim, $terbuka[$indeks]['titik'], SEL, $namaLabel);
    };

    foreach ($terbuka as $indeks => $garis) {
        if ($garis['panjang'] < MIN_PANJANG) {
            continue;
        }
        $dekat = null;
        foreach ($garis['sampel'] as [$x, $y]) {
            $c = jarakLabel($kisiLbl, $label, $x, $y, (float) $set['benih']);
            if ($c !== null && ($dekat === null || $c[0] < $dekat[0])) {
                $dekat = $c;
                if ($dekat[0] <= 0.01) {
                    break;
                }
            }
        }
        if ($dekat !== null) {
            $ambil((int) $indeks, 'benih', $dekat[0], $dekat[1]);
        }
    }

    $tolakSejajar = [];
    $tolakKoridor = [];
    for ($putaran = 0; $putaran < PUTARAN; $putaran++) {
        $baru = 0;
        foreach ($terbuka as $indeks => $garis) {
            if ($garis['panjang'] < MIN_PANJANG || isset($terklaim[$indeks])) {
                continue;
            }
            $sejajar = 0;
            $kosong = 0;
            $jarakLbl = 0.0;
            $namaLbl = '';
            foreach ($garis['sampel'] as [$x, $y, $arah]) {
                $dekat = jarakSegmen($kisiKlaim, $segmenKlaim, $x, $y, PITA, SEL);
                if ($dekat === null || bedaArah($arah, $dekat[1]) > SUDUT) {
                    continue;
                }
                if ($sejajar === 0) {
                    $jarakLbl = $dekat[0];
                    $namaLbl = (string) $dekat[4];
                }
                $sejajar++;
                // Titik tengah antara garis kandidat dan garis terklaim tidak
                // boleh jatuh di dalam bidang tanah: tepi jalan dan tebing
                // sungai berhadapan dengan ruang kosong, sedangkan sisi bidang
                // selalu berhadapan dengan bidang tetangganya.
                if ($dekat[0] < 0.5
                    || !diPoligon(($x + $dekat[2]) / 2, ($y + $dekat[3]) / 2, $kisiPoligon, $poligon,
                        $poligonBesar, SEL_KISI)) {
                    $kosong++;
                }
            }
            if ($sejajar < BAGI_SEJAJAR * count($garis['sampel'])) {
                $tolakSejajar[$indeks] = true;
                continue;
            }
            if ($kosong < BAGI_KOSONG * $sejajar) {
                $tolakKoridor[$indeks] = true;
                continue;
            }
            $ambil((int) $indeks, 'pita' . ($putaran + 1), $jarakLbl, $namaLbl);
            $baru++;
        }
        if ($baru === 0) {
            break;
        }
    }

    $benih = count(array_filter($terklaim, static fn(array $i): bool => $i['cara'] === 'benih'));
    $panjang = 0.0;
    $panjangTepi = 0.0;
    foreach ($terklaim as $indeks => $isi) {
        $panjang += $terbuka[$indeks]['panjang'];
        if ($terbuka[$indeks]['rasioTepi'] >= 0.5) {
            $panjangTepi += $terbuka[$indeks]['panjang'];
        }
        if (!isset($klaim[$indeks]) || $isi['jarak'] < $klaim[$indeks][1]) {
            $klaim[$indeks] = [$jenis, $isi['jarak'], $isi['label'], $isi['cara']];
        }
    }
    printf("%-9s label %4d · garis %5d (benih %4d, pita %5d) · %8.0f m · %2.0f%% menempel tepi bidang"
        . " · ditolak: sejajar %5d, koridor %5d\n",
        $jenis, count($label), count($terklaim), $benih, count($terklaim) - $benih, $panjang,
        100 * $panjangTepi / max(1.0, $panjang), count($tolakSejajar), count($tolakKoridor));
}

// Garis bebas yang tidak terjaring: kandidat "lain-lain".
$lain = [];
foreach ($terbuka as $indeks => $garis) {
    if (isset($klaim[$indeks]) || $garis['panjang'] < MIN_PANJANG || $garis['rasioTepi'] >= RASIO_TEPI) {
        continue;
    }
    $lain[(int) $indeks] = $garis;
}

$buangPendek = 0;
$buangTepi = 0;
foreach ($terbuka as $indeks => $garis) {
    if ($garis['panjang'] < MIN_PANJANG) {
        $buangPendek++;
    } elseif (!isset($klaim[$indeks]) && $garis['rasioTepi'] >= RASIO_TEPI) {
        $buangTepi++;
    }
}

$fitur = static function (array $garis, string $kode, int $indeks, ?float $jarak, ?string $label,
    string $cara): array {
    return [
        'type' => 'Feature',
        'properties' => [
            'kode' => $kode,
            'sumber' => '020100',
            'indeks' => $indeks,
            'panjang_m' => round($garis['panjang'], 2),
            'rasio_tepi_bidang' => round($garis['rasioTepi'], 3),
            'cara' => $cara,
            'jarak_label_m' => $jarak === null ? null : round($jarak, 2),
            'label' => $label,
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $garis['titik']],
    ];
};

$daftar = [];
$nomor = [];
foreach (array_keys(JENIS) as $jenis) {
    $daftar[$jenis] = [];
    $nomor[$jenis] = 1;
}
$daftar['lain'] = [];
$nomor['lain'] = 1;
foreach ($terbuka as $indeks => $garis) {
    if (!isset($klaim[$indeks]) || $garis['panjang'] < MIN_PANJANG) {
        continue;
    }
    [$jenis, $jarak, $label, $cara] = $klaim[$indeks];
    $daftar[$jenis][] = $fitur($garis, sprintf('%s-020100-%04d', strtoupper($jenis), $nomor[$jenis]++),
        (int) $indeks, $jarak, $label, $cara);
}
foreach ($lain as $indeks => $garis) {
    $daftar['lain'][] = $fitur($garis, sprintf('LAIN-020100-%04d', $nomor['lain']++), (int) $indeks,
        null, null, 'bebas');
}

$berkasMeta = DIR_LAPISAN . '/metadata.json';
$meta = is_file($berkasMeta) ? (array) json_decode((string) file_get_contents($berkasMeta), true) : [];
if ($meta === []) {
    galat('metadata.json tidak dapat dibaca: ' . $berkasMeta);
}

foreach (['jalan', 'perairan', 'lain'] as $jenis) {
    $namaBerkas = $jenis === 'lain' ? 'garis-lain' : JENIS[$jenis]['berkas'];
    tulisBerkas($namaBerkas, $daftar[$jenis]);
    $panjang = array_sum(array_map(static fn(array $f): float => (float) $f['properties']['panjang_m'],
        $daftar[$jenis]));
    $cara = [];
    foreach ($daftar[$jenis] as $f) {
        $cara[$f['properties']['cara']] = ($cara[$f['properties']['cara']] ?? 0) + 1;
    }
    $judul = $jenis === 'lain' ? 'GARIS LAIN 020100' : 'GARIS BATAS ' . strtoupper($jenis) . ' 020100';
    $meta['layers'][$judul] = [
        'features' => count($daftar[$jenis]),
        'types' => ['line' => count($daftar[$jenis])],
        'file' => $namaBerkas . '.geojson',
        'panjang_m' => (int) round($panjang),
        'derived_from' => 'garis terbuka 020100' . ($jenis === 'lain'
            ? ' yang berdiri sendiri tanpa label jalan/perairan'
            : ' yang ditumpangi label DXF ' . JENIS[$jenis]['dxf'] . ' dan pita sejajarnya'),
        'ambang' => ['benih_m' => $jenis === 'lain' ? null : JENIS[$jenis]['benih'],
            'pita_m' => PITA, 'sudut_derajat' => SUDUT,
            'putaran' => PUTARAN, 'bagi_sejajar' => BAGI_SEJAJAR, 'bagi_koridor_kosong' => BAGI_KOSONG,
            'rasio_tepi' => RASIO_TEPI, 'min_panjang_m' => MIN_PANJANG, 'langkah_sampel_m' => LANGKAH],
        'permukaan_bidang' => ['sumber' => 'tabel bidang (status <> dihapus)', 'poligon' => $jumlahBidang,
            'segmen_tepi' => count($segmenTepi)],
        'cara' => $cara,
    ];
    printf("%-22s %5d fitur · %8.0f m · %s\n", $namaBerkas . '.geojson', count($daftar[$jenis]), $panjang,
        json_encode($cara, JSON_UNESCAPED_UNICODE));
}

$catatan = [];
foreach ((array) ($meta['notes'] ?? []) as $n) {
    if (!str_starts_with((string) $n, 'GARIS BATAS ') && !str_starts_with((string) $n, 'GARIS LAIN')) {
        $catatan[] = $n;
    }
}
$catatan[] = sprintf(
    'GARIS BATAS JALAN dan GARIS BATAS PERAIRAN diturunkan dari garis terbuka 020100: benih = garis yang '
    . 'membawa label nama (jalan <= %.1f m, perairan <= %.1f m dari titik label — label perairan ditulis di '
    . 'tengah alur sehingga radiusnya lebih lebar), lalu pita sejajar <= %.1f m dengan beda arah '
    . 'maksimal %.0f derajat mengambil tepi di sisi lain. Kandidat pita hanya diterima bila minimal %.0f%% '
    . 'titik sampelnya sejajar dan tengah koridornya ke garis terklaim tidak berada di dalam bidang hasil '
    . 'impor (minimal %.0f%% kosong) — tanpa penyaring koridor terhadap permukaan bidang ini pita menyerap '
    . '9.690 garis karena kisi bidang tanah hampir selalu sejajar dalam 15 m. GARIS LAIN berisi garis 020100 '
    . 'yang berdiri sendiri (kurang dari %.0f%% titik sampelnya jatuh di tepi bidang) dan tidak terjaring '
    . 'dua aturan itu. Garis lebih pendek dari %.1f m dibuang (%d garis), dan %d garis yang hanya mengulang '
    . 'tepi bidang tidak masuk layer lain-lain. Ketiganya hanya lapisan referensi pada peta DXF lama; tidak '
    . 'ada yang ditulis ke tabel bidang.',
    (float) JENIS['jalan']['benih'], (float) JENIS['perairan']['benih'], PITA, SUDUT,
    BAGI_SEJAJAR * 100, BAGI_KOSONG * 100, RASIO_TEPI * 100, MIN_PANJANG,
    $buangPendek, $buangTepi
);
$meta['notes'] = $catatan;
$meta['derived_at'] = date('c');
if (file_put_contents($berkasMeta, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
    galat('gagal menulis metadata.json');
}

printf("\ndiklaim jalan/perairan: %d garis · lain-lain: %d · dibuang pendek: %d · mengulang tepi: %d\n",
    count($klaim), count($lain), $buangPendek, $buangTepi);
echo "metadata.json diperbarui.\n";
