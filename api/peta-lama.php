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
 *   GET api/peta-lama.php?lapisan=garis-jalan   (juga garis-selokan,
 *                                                garis-perairan, garis-lain)
 *       garis-jalan menyajikan garis-jalan-dari-sumbu.geojson, yaitu garis tepi
 *       jalan yang diturunkan dari SEMUA sumbu jalan (1.560 sumbu label + 130
 *       sumbu manual) oleh tools/garis-jalan-dari-sumbu.php (sejak 18 Nov 2026).
 *       Berkas lama garis-jalan-tepi.geojson tetap di disk, tidak lagi disajikan.
 *   GET api/peta-lama.php?lapisan=garis-jalan-sumbu
 *       Sumbu jalan yang sudah rapi: garis kembar dibuang lalu sisanya dijahit
 *       menjadi polilin utuh oleh app/sumbu-rapi.php (sejak 19 Nov 2026).
 *       Bahannya garis-jalan-sumbu.geojson (turunan) dan garis-sumbu-manual.geojson
 *       (alat "Garis manual"); hasilnya dicache di storage/cache/sumbu-rapi.json.
 *   GET api/peta-lama.php?lapisan=garis-sumbu-manual
 *       Garis manual Sumbu jalan apa adanya, dipakai halaman Peta Induk sebagai
 *       lapis kendali tersembunyi supaya klik garis manual tetap bisa memilih,
 *       memotong, dan menghapus walau lapis tampilnya sudah dijahit.
 *   GET api/peta-lama.php?lapisan=garis-jalan-panjang
 *       Garis tepi tertutup dari 5 sumbu terpanjang, hasil draf alat yang sama
 *       dengan --sumbu=rapi --maks=5 --tutup=40: tiap sumbu satu cincin
 *       keliling dan pertemuan antarsumbu ikut dijahit. Disajikan apa adanya
 *       dari berkas garis-jalan-dari-sumbu-panjang.geojson.
 *   GET api/peta-lama.php?lapisan=bidang-hm   (juga hm, optimalisasi-hm, lb-lai,
 *       020400, nib-hm, batas-desa, blok, batas-jalan)
 *   GET api/peta-lama.php?lapisan=titik-obyek
 *       Titik obyek dari alat "Penanda titik obyek" (titik-obyek-manual.geojson).
 *       Sebelum ada titik tersimpan, lapis ini menjawab koleksi kosong.
 *   GET api/peta-lama.php?lapisan=010500
 *       Poligon garis luar desa digabung dengan garis manual hasil alat
 *       "Garis manual" (garis-luar-desa-manual.geojson).
 *   GET api/peta-lama.php?lapisan=bidang-manual
 *       Bidang yang digambar lewat alat "Garis manual" (lapis Bidang). Datanya
 *       baris tabel bidang bersumber 'garis-manual' yang belum dihapus, bukan
 *       berkas GeoJSON, lalu dikonversi balik ke meter DXF untuk kanvas ini.
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
    'garis-jalan'    => 'garis-jalan-dari-sumbu.geojson',
    'garis-jalan-panjang' => 'garis-jalan-dari-sumbu-panjang.geojson',
    'garis-jalan-sumbu' => 'garis-jalan-sumbu.geojson',
    'garis-sumbu-manual' => 'garis-sumbu-manual.geojson',
    'garis-selokan'  => 'garis-selokan.geojson',
    'garis-perairan' => 'garis-perairan.geojson',
    'garis-lain'     => 'garis-lain.geojson',
    'titik-obyek'    => 'titik-obyek-manual.geojson',
    'bidang-manual'  => 'bidang-manual.geojson',
];

// Lapis yang berkasnya belum tentu ada karena baru terisi dari alat di Peta
// Induk; selama berkasnya belum ada, jawab koleksi kosong supaya lapis tetap
// bisa dicentang tanpa galat 404.
const LAPISAN_BOLEH_KOSONG = ['titik-obyek', 'garis-sumbu-manual'];

const GARIS_MANUAL_PER_LAPISAN = [
    '010500' => 'garis-luar-desa-manual.geojson',
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

if ($lapisan === 'bidang-manual') {
    require_once __DIR__ . '/../tools/lib-impor.php';
    $barisBidang = db_all(
        "SELECT id, kode, status, luas_m2, ST_AsGeoJSON(geom) AS geojson
         FROM bidang
         WHERE sumber = 'garis-manual' AND status <> 'dihapus' AND geom IS NOT NULL
         ORDER BY id"
    );
    $ubahKoordinat = static function (array $koordinat) use (&$ubahKoordinat): array {
        if (count($koordinat) >= 2 && is_numeric($koordinat[0]) && is_numeric($koordinat[1])) {
            [$x, $y] = wgs84KeDxf((float) $koordinat[0], (float) $koordinat[1]);
            return [round($x, 3), round($y, 3)];
        }
        return array_map($ubahKoordinat, $koordinat);
    };
    $fitur = [];
    foreach ($barisBidang as $bidang) {
        $geometri = json_decode((string) $bidang['geojson'], true);
        if (!is_array($geometri) || !isset($geometri['type'], $geometri['coordinates'])
            || !in_array($geometri['type'], ['Polygon', 'MultiPolygon'], true)) {
            continue;
        }
        $fitur[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => $geometri['type'],
                'coordinates' => $ubahKoordinat((array) $geometri['coordinates']),
            ],
            'properties' => [
                'id' => (int) $bidang['id'],
                'kode' => (string) $bidang['kode'],
                'status' => (string) $bidang['status'],
                'luas_m2' => $bidang['luas_m2'] === null ? null : (float) $bidang['luas_m2'],
                'closed' => true,
                'sumber' => 'garis-manual',
                'lapis' => 'bidang',
            ],
        ];
    }

    json_response(['type' => 'FeatureCollection', 'features' => $fitur]);
}

if ($lapisan === 'garis-jalan-sumbu') {
    require_once __DIR__ . '/../app/sumbu-rapi.php';
    $dir = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/';
    $hasil = sumbu_rapi_lapis($dir . 'garis-jalan-sumbu.geojson', $dir . 'garis-sumbu-manual.geojson');
    if ($hasil === null) {
        json_error('Berkas lapisan garis-jalan-sumbu tidak dapat dibaca.', 500);
    }
    json_response([
        'type' => 'FeatureCollection',
        'features' => $hasil['features'],
        'ringkasan' => $hasil['ringkasan'],
    ]);
}

$berkas = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . LAPISAN_PETA_LAMA[$lapisan];

if (isset(GARIS_MANUAL_PER_LAPISAN[$lapisan])) {
    // Satu lapis berisi geometri turunan dan garis yang digambar dengan alat
    // Garis manual. Berkas manual tetap terpisah supaya tidak terhapus saat
    // alat penurunan dijalankan ulang.
    $turunan = is_file($berkas) ? json_decode((string) file_get_contents($berkas), true) : null;
    if (!is_array($turunan) || ($turunan['type'] ?? '') !== 'FeatureCollection'
        || !is_array($turunan['features'] ?? null)) {
        json_error('Berkas lapisan ' . $lapisan . ' tidak dapat dibaca.', 500);
    }
    $fitur = array_values(array_filter($turunan['features'], 'is_array'));
    $berkasManual = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/'
        . GARIS_MANUAL_PER_LAPISAN[$lapisan];
    if (is_file($berkasManual)) {
        $manual = json_decode((string) file_get_contents($berkasManual), true);
        if (is_array($manual) && ($manual['type'] ?? '') === 'FeatureCollection'
            && is_array($manual['features'] ?? null)) {
            foreach ($manual['features'] as $f) {
                if (is_array($f)) {
                    $fitur[] = $f;
                }
            }
        }
    }
    json_response(['type' => 'FeatureCollection', 'features' => $fitur]);
}

if (!is_file($berkas)) {
    if (in_array($lapisan, LAPISAN_BOLEH_KOSONG, true)) {
        json_response(['type' => 'FeatureCollection', 'features' => []]);
    }
    json_error('Berkas lapisan peta lama tidak ditemukan.', 404);
}

header('Content-Type: application/geo+json; charset=utf-8');
header('Cache-Control: private, max-age=300');
header('Content-Length: ' . (string) filesize($berkas));
readfile($berkas);
