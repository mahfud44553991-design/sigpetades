<?php
declare(strict_types=1);

/**
 * Pisahkan garis saluran dari lapis Jalan (Peta Bidang).
 *
 *   php tools/pisah-saluran-dari-jalan.php            # uji kering
 *   php tools/pisah-saluran-dari-jalan.php --terapkan
 *   php tools/pisah-saluran-dari-jalan.php --rinci    # daftar baris yang dibuang
 *
 * Aturan. `garis-jalan-tepi.geojson` memuat garis tepi jalan turunan label.
 * Sebagian barisnya memakai geometri yang juga dipakai `garis-selokan.geojson`
 * (lapis Saluran). Satu baris jalan dibuang bila salah satu:
 *   - `indeks` rantai 020100-nya termasuk `indeks_all` salah satu fitur saluran;
 *   - geometrinya penggalan utuh satu garis saluran (deret verteks yang sama,
 *     arah maju atau balik), meskipun indeks rantainya berbeda.
 * Setiap verteks baris yang dibuang karena indeks wajib ada di verteks saluran;
 * bila ada yang tidak cocok, alat berhenti tanpa menulis. Lapis Saluran tidak diubah.
 *
 * --terapkan  cadangkan berkas dan metadata, tulis jalan tepi, perbarui metadata.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

date_default_timezone_set('Asia/Jakarta');

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_JALAN = 'garis-jalan-tepi';
const BERKAS_SALURAN = 'garis-selokan';
const JUDUL_META = 'GARIS JALAN TEPI DARI LABEL';

$terapkan = in_array('--terapkan', array_slice($argv, 1), true);

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

function ruas(array $fitur): array
{
    $g = $fitur['geometry'] ?? [];
    $t = $g['type'] ?? '';
    if ($t === 'LineString') {
        return [$g['coordinates']];
    }
    if ($t === 'MultiLineString' || $t === 'Polygon') {
        return $g['coordinates'];
    }
    if ($t === 'MultiPolygon') {
        $o = [];
        foreach ($g['coordinates'] as $p) {
            foreach ($p as $r) {
                $o[] = $r;
            }
        }
        return $o;
    }
    return [];
}

function angka(float $n, int $desimal = 1): string
{
    return number_format($n, $desimal, ',', '.');
}

$saluran = bacaGeoJson(BERKAS_SALURAN);
$jalan = bacaGeoJson(BERKAS_JALAN);

$indeksSaluran = [];
$kodePerIndeks = [];
$verteksSaluran = [];
$garisSaluran = [];
foreach ($saluran['features'] as $f) {
    $p = $f['properties'] ?? [];
    $kode = (string) ($p['kode'] ?? '?');
    $daftar = $p['indeks_all'] ?? [$p['indeks'] ?? -1];
    foreach ($daftar as $i) {
        $indeksSaluran[(int) $i] = true;
        $kodePerIndeks[(int) $i] = $kode;
    }
    foreach (ruas($f) as $ln) {
        $deret = [];
        foreach ($ln as $c) {
            $kunci = sprintf('%.3f,%.3f', $c[0], $c[1]);
            $verteksSaluran[$kunci] = true;
            $deret[] = $kunci;
        }
        $garisSaluran[] = ['kode' => $kode, 'maju' => implode(';', $deret)];
    }
}

/** @return string kode saluran bila garis jalan adalah penggalan utuh garis saluran */
function penggalanSaluran(array $fitur, array $garisSaluran): string
{
    foreach (ruas($fitur) as $ln) {
        $deret = [];
        foreach ($ln as $c) {
            $deret[] = sprintf('%.3f,%.3f', $c[0], $c[1]);
        }
        $maju = implode(';', $deret);
        $balik = implode(';', array_reverse($deret));
        foreach ($garisSaluran as $g) {
            if (strpos($g['maju'], $maju) !== false || strpos($g['maju'], $balik) !== false) {
                return $g['kode'];
            }
        }
    }
    return '';
}

$simpan = [];
$dibuang = [];
$panjangSimpan = 0.0;
$panjangBuang = 0.0;
$dibuangRantai = [];
$dibuangPenggalan = [];
$verteksBeda = [];
foreach ($jalan['features'] as $f) {
    $p = $f['properties'] ?? [];
    $indeks = (int) ($p['indeks'] ?? -1);
    $panjang = (float) ($p['panjang_m'] ?? 0);
    $kodeJalan = (string) ($p['kode'] ?? '?');
    $sebab = '';
    $kodeSaluran = '';
    if (isset($indeksSaluran[$indeks])) {
        $cocok = true;
        foreach (ruas($f) as $ln) {
            foreach ($ln as $c) {
                if (!isset($verteksSaluran[sprintf('%.3f,%.3f', $c[0], $c[1])])) {
                    $cocok = false;
                    break 2;
                }
            }
        }
        if (!$cocok) {
            $verteksBeda[] = $kodeJalan;
        }
        $sebab = 'rantai';
        $kodeSaluran = $kodePerIndeks[$indeks] ?? '?';
    } else {
        $kodeSaluran = penggalanSaluran($f, $garisSaluran);
        if ($kodeSaluran !== '') {
            $sebab = 'penggalan';
        }
    }
    if ($sebab === '') {
        $simpan[] = $f;
        $panjangSimpan += $panjang;
        continue;
    }
    $baris = ['kode' => $kodeJalan, 'sebab' => $sebab,
        'saluran' => $kodeSaluran, 'panjang_m' => $panjang];
    $dibuang[] = $baris;
    if ($sebab === 'rantai') {
        $dibuangRantai[] = $baris;
    } else {
        $dibuangPenggalan[] = $baris;
    }
    $panjangBuang += $panjang;
}

$panjangRantai = array_sum(array_column($dibuangRantai, 'panjang_m'));
$panjangPenggalan = array_sum(array_column($dibuangPenggalan, 'panjang_m'));

echo 'jalan sebelum            : ' . count($jalan['features']) . ' baris, ' . angka($panjangSimpan + $panjangBuang) . " m\n";
echo 'dibuang (indeks rantai)  : ' . count($dibuangRantai) . ' baris, ' . angka($panjangRantai) . " m\n";
echo 'dibuang (penggalan utuh) : ' . count($dibuangPenggalan) . ' baris, ' . angka($panjangPenggalan) . " m\n";
echo 'jalan sesudah            : ' . count($simpan) . ' baris, ' . angka($panjangSimpan) . " m\n";
echo 'saluran                  : ' . count($saluran['features']) . " fitur, tidak diubah\n";

if ($verteksBeda) {
    fwrite(STDERR, 'galat: ' . count($verteksBeda) . ' baris yang akan dibuang memuat verteks bukan milik saluran: '
        . implode(', ', array_slice($verteksBeda, 0, 10)) . "\n");
    exit(1);
}

if (!$terapkan) {
    echo "uji kering: tidak ada berkas yang ditulis. Tambahkan --terapkan untuk menulis.\n";
    exit(0);
}

$stempel = date('Ymd-His');
$cadang = __DIR__ . '/../_lama/cadangan-jalan-tanpa-saluran-' . $stempel;
if (!is_dir($cadang) && !mkdir($cadang, 0775, true)) {
    fwrite(STDERR, "galat: gagal membuat folder cadangan\n");
    exit(1);
}
$berkasJalan = DIR_LAPISAN . '/' . BERKAS_JALAN . '.geojson';
$berkasMeta = DIR_LAPISAN . '/metadata.json';
copy($berkasJalan, $cadang . '/garis-jalan-tepi-sebelum-' . $stempel . '.geojson');
copy($berkasMeta, $cadang . '/metadata-sebelum-' . $stempel . '.json');
echo 'cadangan: _lama/' . basename($cadang) . "\n";

$keluar = ['type' => 'FeatureCollection'];
foreach ($jalan as $k => $v) {
    if ($k !== 'features') {
        $keluar[$k] = $v;
    }
}
$keluar['features'] = $simpan;
$json = json_encode($keluar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($berkasJalan, $json) === false) {
    fwrite(STDERR, "galat: gagal menulis " . BERKAS_JALAN . ".geojson\n");
    exit(1);
}
echo 'berkas ditulis: ' . BERKAS_JALAN . ".geojson\n";

$meta = json_decode((string) file_get_contents($berkasMeta), true);
if (!is_array($meta) || !isset($meta['layers'][JUDUL_META])) {
    fwrite(STDERR, 'galat: entri metadata ' . JUDUL_META . " tidak ditemukan\n");
    exit(1);
}
$sebelum = $meta['layers'][JUDUL_META]['pisah_saluran'] ?? [];
$akum = [
    'rantai' => ['fitur' => count($dibuangRantai), 'panjang_m' => $panjangRantai],
    'penggalan' => ['fitur' => count($dibuangPenggalan), 'panjang_m' => $panjangPenggalan],
];
foreach (($sebelum['sebab'] ?? []) as $k => $v) {
    if (isset($akum[$k])) {
        $akum[$k]['fitur'] += (int) ($v['fitur'] ?? 0);
        $akum[$k]['panjang_m'] += (float) ($v['panjang_m'] ?? 0);
    }
}
$totalFitur = $akum['rantai']['fitur'] + $akum['penggalan']['fitur'];
$totalPanjang = round($akum['rantai']['panjang_m'] + $akum['penggalan']['panjang_m'], 1);
foreach ($akum as $k => $v) {
    $akum[$k]['panjang_m'] = round($v['panjang_m'], 1);
}

$meta['layers'][JUDUL_META]['features'] = count($simpan);
$meta['layers'][JUDUL_META]['panjang_m'] = (int) round($panjangSimpan);
$meta['layers'][JUDUL_META]['pisah_saluran'] = [
    'aturan' => 'baris jalan dibuang bila indeks rantainya dipakai garis-selokan atau geometrinya penggalan utuh garis saluran',
    'sumber' => BERKAS_SALURAN . '.geojson',
    'fitur_dibuang' => $totalFitur,
    'panjang_dibuang_m' => $totalPanjang,
    'sebab' => $akum,
    'fitur_sesudah' => count($simpan),
    'panjang_sesudah_m' => round($panjangSimpan, 1),
    'diterapkan' => $stempel,
];
$metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($metaJson === false || file_put_contents($berkasMeta, $metaJson) === false) {
    fwrite(STDERR, "galat: gagal menulis metadata.json\n");
    exit(1);
}
echo 'metadata.json diperbarui: ' . JUDUL_META . "\n";

if (in_array('--rinci', array_slice($argv, 1), true)) {
    foreach ($dibuang as $d) {
        echo '  ' . $d['kode'] . ' [' . $d['sebab'] . '] -> ' . $d['saluran'] . ' (' . angka($d['panjang_m']) . " m)\n";
    }
}
