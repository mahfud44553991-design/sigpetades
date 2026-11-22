<?php
declare(strict_types=1);

/**
 * Pindahkan garis jalan yang seluruh segmennya sudah menjadi pasangan tebing
 * saluran ke lapis Saluran.
 *
 *   php tools/pindah-jalan-ke-saluran.php            # uji kering
 *   php tools/pindah-jalan-ke-saluran.php --terapkan
 *   php tools/pindah-jalan-ke-saluran.php --rinci
 *
 * Aturan. `garis-jalan-tepi.geojson` dan `garis-tepi-saluran.geojson` dibaca
 * bersama. Sebuah baris Jalan dipindah bila SETIAP segmennya (kunci 3 desimal,
 * arah maju atau balik) juga segmen sebuah fitur `cara=pasangan` pada Tepi
 * saluran. Baris seperti itu sudah terbukti sebagai tebing saluran, bukan tepi
 * jalan. Geometrinya disalin verbatim ke BERKAS_KELUAR; tidak ada titik baru.
 * Baris yang sama dibuang dari Jalan supaya satu garis hanya tampil di satu
 * lapis (aturan yang sama dengan tools/pisah-saluran-dari-jalan.php).
 *
 * Berkas keluaran terpisah supaya pil Selokan tetap 689 baris; pil Saluran
 * menggabungkan garis-selokan.geojson dengan berkas ini. Induk saluran tiap
 * baris dipilih dari kode pasangan yang mencakup segmen terbanyak; `label`
 * diambil dari fitur induk di garis-selokan.geojson.
 *
 * --terapkan  cadangkan berkas + metadata, tulis Jalan, berkas keluaran, dan metadata.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

date_default_timezone_set('Asia/Jakarta');

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_JALAN = 'garis-jalan-tepi';
const BERKAS_SELOKAN = 'garis-selokan';
const BERKAS_TEPI = 'garis-tepi-saluran';
const BERKAS_KELUAR = 'garis-saluran-dari-jalan';
const JUDUL_JALAN = 'GARIS JALAN TEPI DARI LABEL';
const JUDUL_TEPI = 'GARIS TEPI SALURAN';
const JUDUL_KELUAR = 'GARIS SALURAN DARI JALAN';

$terapkan = in_array('--terapkan', array_slice($argv, 1), true);
$rinci = in_array('--rinci', array_slice($argv, 1), true);

function bacaGeoJson(string $nama): array
{
    $berkas = DIR_LAPISAN . '/' . $nama . '.geojson';
    if (!is_file($berkas)) {
        fwrite(STDERR, "galat: berkas $nama.geojson tidak ditemukan\n");
        exit(1);
    }
    $data = json_decode((string) file_get_contents($berkas), true);
    if (!is_array($data) || !isset($data['features']) || !is_array($data['features'])) {
        fwrite(STDERR, "galat: $nama.geojson bukan FeatureCollection yang sah\n");
        exit(1);
    }
    return $data;
}

function angka(float $n, int $desimal = 1): string
{
    return number_format($n, $desimal, ',', '.');
}

function kunciTitik(array $p): string
{
    return sprintf('%.3f,%.3f', $p[0], $p[1]);
}

$jalan = bacaGeoJson(BERKAS_JALAN);
$selokan = bacaGeoJson(BERKAS_SELOKAN);
$tepi = bacaGeoJson(BERKAS_TEPI);

$selPerKode = [];
foreach ($selokan['features'] as $f) {
    $selPerKode[(string) ($f['properties']['kode'] ?? '')] = $f;
}

// Segmen setiap pasangan tebing saluran; satu segmen bisa dipakai lebih dari
// satu fitur, jadi daftar kandidat disimpan.
$segPasangan = [];
$jumlahPasangan = 0;
foreach ($tepi['features'] as $f) {
    if ((string) ($f['properties']['cara'] ?? '') !== 'pasangan') {
        continue;
    }
    $jumlahPasangan++;
    $p = $f['properties'] ?? [];
    $c = $f['geometry']['coordinates'] ?? [];
    if (($f['geometry']['type'] ?? '') !== 'LineString' || count($c) < 2) {
        continue;
    }
    for ($i = 0; $i + 1 < count($c); $i++) {
        $a = kunciTitik($c[$i]);
        $b = kunciTitik($c[$i + 1]);
        $isi = ['saluran' => (string) ($p['saluran'] ?? ''), 'sisi' => (string) ($p['sisi'] ?? '')];
        $segPasangan[$a . '|' . $b][] = $isi;
        $segPasangan[$b . '|' . $a][] = $isi;
    }
}

$simpan = [];
$pindah = [];
$panjangSimpan = 0.0;
$panjangPindah = 0.0;
$tanpaInduk = [];
foreach ($jalan['features'] as $f) {
    $p = $f['properties'] ?? [];
    $c = $f['geometry']['coordinates'] ?? [];
    $kodeJalan = (string) ($p['kode'] ?? '?');
    $panjang = (float) ($p['panjang_m'] ?? 0);
    $segmen = (($f['geometry']['type'] ?? '') === 'LineString' && count($c) >= 2) ? count($c) - 1 : 0;

    $cocok = $segmen > 0;
    $vot = [];
    $sisi = [];
    for ($i = 0; $cocok && $i < $segmen; $i++) {
        $a = kunciTitik($c[$i]);
        $b = kunciTitik($c[$i + 1]);
        $daftar = $segPasangan[$a . '|' . $b] ?? null;
        if ($daftar === null) {
            $cocok = false;
            break;
        }
        $pertama = $daftar[0];
        $vot[$pertama['saluran']] = ($vot[$pertama['saluran']] ?? 0) + 1;
        foreach ($daftar as $d) {
            $sisi[$d['sisi']] = true;
        }
    }

    if (!$cocok) {
        $simpan[] = $f;
        $panjangSimpan += $panjang;
        continue;
    }

    $induk = '';
    $terbanyak = -1;
    foreach ($vot as $kode => $n) {
        if ($n > $terbanyak) {
            $terbanyak = $n;
            $induk = $kode;
        }
    }
    if ($induk === '' || !isset($selPerKode[$induk])) {
        $tanpaInduk[] = $kodeJalan . ' -> ' . ($induk === '' ? '?' : $induk);
        continue;
    }

    $pindah[] = [
        'jalan' => $f,
        'kode' => $kodeJalan,
        'induk' => $induk,
        'sisi' => implode(',', array_keys($sisi)),
        'panjang' => $panjang,
        'label' => (string) ($selPerKode[$induk]['properties']['label'] ?? ''),
    ];
    $panjangPindah += $panjang;
}

echo 'jalan sebelum   : ' . count($jalan['features']) . ' baris, ' . angka($panjangSimpan + $panjangPindah) . " m\n";
echo 'pasangan tepi   : ' . $jumlahPasangan . " baris\n";
echo 'dipindah        : ' . count($pindah) . ' baris, ' . angka($panjangPindah) . " m\n";
echo 'jalan sesudah   : ' . count($simpan) . ' baris, ' . angka($panjangSimpan) . " m\n";

if ($tanpaInduk) {
    fwrite(STDERR, 'galat: ' . count($tanpaInduk) . ' baris tidak menemukan induk saluran: '
        . implode(', ', array_slice($tanpaInduk, 0, 10)) . "\n");
    exit(1);
}

// Berkas keluaran yang sudah ada dipakai ulang supaya alat aman dijalankan lagi.
$berkasKeluar = DIR_LAPISAN . '/' . BERKAS_KELUAR . '.geojson';
$keluar = ['type' => 'FeatureCollection', 'features' => []];
if (is_file($berkasKeluar)) {
    $lama = json_decode((string) file_get_contents($berkasKeluar), true);
    if (is_array($lama) && isset($lama['features']) && is_array($lama['features'])) {
        $keluar = $lama;
    }
}
$adaDiKeluar = [];
$nomorTerakhir = 0;
foreach ($keluar['features'] as $f) {
    $p = $f['properties'] ?? [];
    $adaDiKeluar[(string) ($p['dari_jalan'] ?? '')] = true;
    if (preg_match('/^SALURAN-JALAN-(\d+)$/', (string) ($p['kode'] ?? ''), $m)) {
        $nomorTerakhir = max($nomorTerakhir, (int) $m[1]);
    }
}

$baru = [];
$nomor = $nomorTerakhir;
$panjangBaru = 0.0;
foreach ($pindah as $r) {
    $panjangBaru += $r['panjang'];
    if (isset($adaDiKeluar[$r['kode']])) {
        continue;
    }
    $nomor++;
    $baru[] = [
        'type' => 'Feature',
        'geometry' => $r['jalan']['geometry'],
        'properties' => [
            'kode' => sprintf('SALURAN-JALAN-%04d', $nomor),
            'sumber' => BERKAS_JALAN,
            'dari_jalan' => $r['kode'],
            'saluran' => $r['induk'],
            'sisi' => $r['sisi'],
            'panjang_m' => $r['panjang'],
            'label' => $r['label'],
            'cara' => 'pasangan-tepi-saluran',
        ],
    ];
}

echo 'ditambahkan     : ' . count($baru) . ' baris' . (count($baru) < count($pindah) ? ' (sisanya sudah ada)' : '') . "\n";
echo 'saluran sesudah : ' . (count($selokan['features']) + count($keluar['features']) + count($baru)) . " baris\n";

if (!$pindah) {
    echo "tidak ada baris Jalan yang perlu dipindah.\n";
    exit(0);
}

if (!$terapkan) {
    echo "uji kering: tidak ada berkas yang ditulis. Tambahkan --terapkan untuk menulis.\n";
    if ($rinci) {
        foreach ($pindah as $r) {
            echo '  ' . $r['kode'] . ' -> ' . $r['induk'] . ' [' . $r['sisi'] . '] (' . angka($r['panjang']) . " m)\n";
        }
    }
    exit(0);
}

$stempel = date('Ymd-His');
$cadang = __DIR__ . '/../_lama/cadangan-jalan-ke-saluran-' . $stempel;
if (!is_dir($cadang) && !mkdir($cadang, 0775, true)) {
    fwrite(STDERR, "galat: gagal membuat folder cadangan\n");
    exit(1);
}
$berkasJalan = DIR_LAPISAN . '/' . BERKAS_JALAN . '.geojson';
$berkasMeta = DIR_LAPISAN . '/metadata.json';
copy($berkasJalan, $cadang . '/garis-jalan-tepi-sebelum-' . $stempel . '.geojson');
if (is_file($berkasKeluar)) {
    copy($berkasKeluar, $cadang . '/' . BERKAS_KELUAR . '-sebelum-' . $stempel . '.geojson');
}
copy($berkasMeta, $cadang . '/metadata-sebelum-' . $stempel . '.json');
echo 'cadangan: _lama/' . basename($cadang) . "\n";

$keluarJalan = ['type' => 'FeatureCollection'];
foreach ($jalan as $k => $v) {
    if ($k !== 'features') {
        $keluarJalan[$k] = $v;
    }
}
$keluarJalan['features'] = $simpan;
$jsonJalan = json_encode($keluarJalan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($jsonJalan === false || file_put_contents($berkasJalan, $jsonJalan) === false) {
    fwrite(STDERR, "galat: gagal menulis " . BERKAS_JALAN . ".geojson\n");
    exit(1);
}
echo 'berkas ditulis: ' . BERKAS_JALAN . ".geojson\n";

$keluar['features'] = array_merge($keluar['features'], $baru);
$jsonKeluar = json_encode($keluar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($jsonKeluar === false || file_put_contents($berkasKeluar, $jsonKeluar) === false) {
    fwrite(STDERR, "galat: gagal menulis " . BERKAS_KELUAR . ".geojson\n");
    exit(1);
}
echo 'berkas ditulis: ' . BERKAS_KELUAR . ".geojson\n";

$panjangKeluar = 0.0;
foreach ($keluar['features'] as $f) {
    $panjangKeluar += (float) ($f['properties']['panjang_m'] ?? 0);
}

$meta = json_decode((string) file_get_contents($berkasMeta), true);
if (!is_array($meta) || !isset($meta['layers'][JUDUL_JALAN])) {
    fwrite(STDERR, 'galat: entri metadata ' . JUDUL_JALAN . " tidak ditemukan\n");
    exit(1);
}

$meta['layers'][JUDUL_JALAN]['features'] = count($simpan);
$meta['layers'][JUDUL_JALAN]['panjang_m'] = (int) round($panjangSimpan);
$meta['layers'][JUDUL_JALAN]['types']['line'] = count($simpan);
$lamaAmbil = $meta['layers'][JUDUL_JALAN]['ambil_ke_saluran'] ?? [];
$meta['layers'][JUDUL_JALAN]['ambil_ke_saluran'] = [
    'aturan' => 'baris Jalan yang seluruh segmennya juga segmen pasangan tebing saluran dipindah ke lapis Saluran; geometri disalin verbatim',
    'sumber' => BERKAS_TEPI . '.geojson (cara=pasangan)',
    'fitur' => (int) ($lamaAmbil['fitur'] ?? 0) + count($pindah),
    'panjang_m' => round((float) ($lamaAmbil['panjang_m'] ?? 0) + $panjangPindah, 1),
    'fitur_sesudah' => count($simpan),
    'panjang_sesudah_m' => round($panjangSimpan, 1),
    'diterapkan' => $stempel,
];

$keluarMeta = $meta['layers'][JUDUL_KELUAR] ?? [];
$meta['layers'][JUDUL_KELUAR] = [
    'features' => count($keluar['features']),
    'types' => ['line' => count($keluar['features'])],
    'file' => BERKAS_KELUAR . '.geojson',
    'panjang_m' => (int) round($panjangKeluar),
    'derived_from' => 'garis-jalan-tepi.geojson: baris yang SETIAP segmennya menjadi pasangan tebing saluran di garis-tepi-saluran.geojson; geometri disalin verbatim, tanpa titik baru',
    'aturan' => 'satu baris dipindah bila semua segmennya (3 desimal, arah maju atau balik) ada pada fitur cara=pasangan; induk = kode saluran dengan segmen terbanyak',
    'pasangan' => ['file' => BERKAS_TEPI . '.geojson', 'baris' => $jumlahPasangan],
    'terakhir' => [
        'fitur' => count($pindah),
        'panjang_m' => round($panjangPindah, 1),
        'ditambahkan' => count($baru),
        'diterapkan' => $stempel,
        'cadangan' => '_lama/' . basename($cadang) . '/',
    ],
];

if (isset($meta['layers'][JUDUL_TEPI])) {
    $meta['layers'][JUDUL_TEPI]['saluran_tambahan'] = [
        'file' => BERKAS_KELUAR . '.geojson',
        'fitur' => count($keluar['features']),
        'panjang_m' => round($panjangKeluar, 1),
        'catatan' => 'acuan tetap 689 baris; lapis Saluran di peta = garis-selokan.geojson + berkas ini',
        'diterapkan' => $stempel,
    ];
}
if (isset($keluarMeta['terakhir']) && !isset($meta['layers'][JUDUL_KELUAR]['terakhir'])) {
    $meta['layers'][JUDUL_KELUAR]['terakhir'] = $keluarMeta['terakhir'];
}

$metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($metaJson === false || file_put_contents($berkasMeta, $metaJson) === false) {
    fwrite(STDERR, "galat: gagal menulis metadata.json\n");
    exit(1);
}
echo 'metadata.json diperbarui: ' . JUDUL_JALAN . ', ' . JUDUL_KELUAR . ', ' . JUDUL_TEPI . "\n";

if ($rinci) {
    foreach ($pindah as $r) {
        echo '  ' . $r['kode'] . ' -> ' . $r['induk'] . ' [' . $r['sisi'] . '] (' . angka($r['panjang']) . " m)\n";
    }
}
