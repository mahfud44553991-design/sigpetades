<?php
declare(strict_types=1);

/**
 * Layer GeoJSON DXF dari arsip aplikasi lama
 * files/sig-pertanahan-gedong-lokal/data/dxf-layers.
 *
 *   GET api/peta-lama.php?lapisan=020100
 *   GET api/peta-lama.php?lapisan=batas-blok
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

$berkas = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . LAPISAN_PETA_LAMA[$lapisan];
if (!is_file($berkas)) {
    json_error('Berkas lapisan peta lama tidak ditemukan.', 404);
}

header('Content-Type: application/geo+json; charset=utf-8');
header('Cache-Control: private, max-age=300');
header('Content-Length: ' . (string) filesize($berkas));
readfile($berkas);
