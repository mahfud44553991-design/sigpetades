<?php
declare(strict_types=1);

/**
 * Layer GeoJSON DXF dari arsip aplikasi lama
 * files/sig-pertanahan-gedong-lokal/data/dxf-layers.
 *
 *   GET api/peta-lama.php?lapisan=020100
 *   GET api/peta-lama.php?lapisan=batas-blok
 *   GET api/peta-lama.php?lapisan=batas-blok-mentah
 *   GET api/peta-lama.php?lapisan=batas-perairan
 *   GET api/peta-lama.php?lapisan=tn-nama
 *   GET api/peta-lama.php?lapisan=bidang-hm   (juga hm, optimalisasi-hm, lb-lai,
 *       010500, 020400, nib-hm, batas-desa, blok, batas-jalan)
 *
 * Nama berkas dibatasi daftar putih di bawah, jadi parameter tidak pernah
 * dipakai menyusun path secara bebas. Folder files/ sendiri ditolak Apache;
 * berkas hanya keluar lewat sini setelah pemanggil masuk.
 */

require_once __DIR__ . '/../app/bootstrap.php';

wajib_masuk();

const LAPISAN_PETA_LAMA = [
    '020100'         => '020100.geojson',
    'batas-blok'     => 'batas-blok.geojson',
    'batas-blok-mentah' => 'batas-blok.geojson',
    'batas-perairan' => 'batas-perairan.geojson',
    'tn-nama'        => 'tn-nama.geojson',
    'bidang-hm'      => 'bidang-hm.geojson',
    'hm'             => 'hm.geojson',
    'optimalisasi-hm' => 'optimalisasi-hm.geojson',
    'lb-lai'         => 'lb-lai.geojson',
    '010500'         => '010500.geojson',
    '020400'         => '020400.geojson',
    'nib-hm'         => 'nib-hm.geojson',
    'batas-desa'     => 'batas-desa.geojson',
    'blok'           => 'blok.geojson',
    'calon-bidang'   => 'calon-bidang.geojson',
    'batas-jalan'    => 'batas-jalan.geojson',
];

$lapisan = get_str('lapisan');
if (!isset(LAPISAN_PETA_LAMA[$lapisan])) {
    json_error('Lapisan peta lama tidak dikenal.', 404);
}

if ($lapisan === 'batas-blok') {
    require_once __DIR__ . '/../tools/lib-impor.php';
    $barisBlok = db_all(
        'SELECT id, kode, nomor, batas_tersedia, batas_perkiraan, sumber_perkiraan,
                jumlah_bidang_estimasi, ST_AsGeoJSON(geom) AS geojson
         FROM blok ORDER BY nomor, kode'
    );
    $fitur = [];
    $keDxf = static function (array $titik): array {
        $lon = DXF_KE_WGS84['lon'];
        $lat = DXF_KE_WGS84['lat'];
        $a = $lon[1];
        $b = $lon[2];
        $c = $lat[1];
        $d = $lat[2];
        $determinan = $a * $d - $b * $c;
        if (abs($determinan) < 1e-20) {
            throw new RuntimeException('Transformasi koordinat DXF tidak dapat dibalik.');
        }
        $x = (float) $titik[0] - $lon[0];
        $y = (float) $titik[1] - $lat[0];
        return [
            ($d * $x - $b * $y) / $determinan,
            ($a * $y - $c * $x) / $determinan,
        ];
    };
    $ubahKoordinat = static function (array $koordinat) use (&$ubahKoordinat, $keDxf): array {
        if (count($koordinat) >= 2 && is_numeric($koordinat[0]) && is_numeric($koordinat[1])) {
            return $keDxf($koordinat);
        }
        return array_map($ubahKoordinat, $koordinat);
    };

    foreach ($barisBlok as $blok) {
        $geometri = json_decode((string) $blok['geojson'], true);
        if (!is_array($geometri) || !isset($geometri['type'], $geometri['coordinates'])) {
            continue;
        }
        $fitur[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => $geometri['type'],
                'coordinates' => $ubahKoordinat($geometri['coordinates']),
            ],
            'properties' => [
                'id' => (int) $blok['id'],
                'kode' => (string) $blok['kode'],
                'nomor' => $blok['nomor'] === null ? null : (int) $blok['nomor'],
                'closed' => true,
                'batas_tersedia' => (bool) $blok['batas_tersedia'],
                'batas_perkiraan' => (bool) $blok['batas_perkiraan'],
                'sumber' => (string) ($blok['sumber_perkiraan'] ?? ''),
                'estimasi_bidang' => $blok['jumlah_bidang_estimasi'] === null
                    ? null : (int) $blok['jumlah_bidang_estimasi'],
            ],
        ];
    }

    json_response(['type' => 'FeatureCollection', 'features' => $fitur]);
}

$berkas = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . LAPISAN_PETA_LAMA[$lapisan];
if (!is_file($berkas)) {
    json_error('Berkas lapisan peta lama tidak ditemukan.', 404);
}

header('Content-Type: application/geo+json; charset=utf-8');
header('Cache-Control: private, max-age=300');
header('Content-Length: ' . (string) filesize($berkas));
readfile($berkas);
