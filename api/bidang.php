<?php
declare(strict_types=1);

/**
 * Data bidang tanah untuk peta dan tabel.
 *
 *   GET api/bidang.php?lapisan=bidang   -> GeoJSON FeatureCollection
 *   GET api/bidang.php?lapisan=blok     -> GeoJSON blok peta
 *   GET api/bidang.php?lapisan=batas    -> GeoJSON garis batas desa (lapisan 010500)
 *   GET api/bidang.php?lapisan=laporan  -> GeoJSON titik laporan (hanya pengelola)
 *   GET api/bidang.php?cari=&dusun=     -> daftar ringkas untuk tabel/pencarian
 *   GET api/bidang.php?tanpa_blok=1     -> hanya bidang yang belum punya blok
 *   GET api/bidang.php?id=123           -> detail satu bidang
 *
 * Semua pembacaan pakai placeholder; tidak ada input yang disisipkan ke SQL.
 *
 * Tanpa sesi masuk, `lapisan=bidang` dan daftar ringkas tetap menyajikan
 * geometri dan atribut fisik, tetapi nama pemegang, NOP, nomor sertifikat, dan
 * status PBB diganti "tersimpan"/kosong lewat sembunyikan_pribadi().
 */

require_once __DIR__ . '/../app/bootstrap.php';

try {
    $lapisan = get_str('lapisan');
    $publik = !sudah_masuk();

    // ------------------------------------------------------------------ titik laporan
    if ($lapisan === 'laporan') {
        wajib_masuk(PERAN_PENGELOLA);
        $baris = db_all(
            "SELECT id, nomor, kategori, nama_pelapor, keterangan, status, tanggal_lapor,
                    nop, bidang_id, ST_AsText(geom) AS wkt
             FROM laporan
             ORDER BY tanggal_lapor DESC
             LIMIT 500"
        );
        $fitur = [];
        foreach ($baris as $l) {
            if (!preg_match('/POINT\s*\(\s*(-?[0-9.]+)\s+(-?[0-9.]+)\s*\)/i', (string) $l['wkt'], $c)) {
                continue;
            }
            $fitur[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) $c[1], (float) $c[2]]],
                'properties' => [
                    'id' => (int) $l['id'],
                    'nomor' => $l['nomor'],
                    'kategori' => $l['kategori'],
                    'status' => $l['status'],
                    'pelapor' => $l['nama_pelapor'],
                    'keterangan' => perpanjang((string) $l['keterangan'], 240),
                    'tanggal' => $l['tanggal_lapor'],
                    'nop' => $l['nop'],
                    'bidang_id' => $l['bidang_id'] === null ? null : (int) $l['bidang_id'],
                ],
            ];
        }
        json_response(['type' => 'FeatureCollection', 'features' => $fitur]);
    }

    // ------------------------------------------------------------------ batas desa
    // Cincin lapisan 010500 di arsip DXF adalah garis keliling desa (±6,7 km²),
    // bukan persil, jadi memang tidak pernah masuk tabel bidang. Koordinatnya
    // masih meter lokal DXF dan diterjemahkan dxfKeWgs84() di sini.
    if ($lapisan === 'batas') {
        require_once __DIR__ . '/../tools/lib-impor.php';
        $berkas = SIP_ROOT . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/010500.geojson';
        if (!is_file($berkas)) {
            json_error('Berkas batas desa tidak ditemukan. Jalankan tools/konversi-dxf-lapisan.php.', 404);
        }
        $data = json_decode((string) file_get_contents($berkas), true);
        $cincin = $data['features'][0]['geometry']['coordinates'][0] ?? null;
        $garis = [];
        if (is_array($cincin)) {
            foreach ($cincin as $titik) {
                if (!is_array($titik) || count($titik) < 2) {
                    continue;
                }
                [$lon, $lat] = dxfKeWgs84((float) $titik[0], (float) $titik[1]);
                $garis[] = [round($lon, 7), round($lat, 7)];
            }
        }
        if (count($garis) < 4) {
            json_error('Geometri batas desa tidak sah.', 500);
        }
        $garis[] = $garis[0];
        json_response([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => $garis],
                'properties' => ['label' => 'Batas desa', 'sumber' => '33240400080'],
            ]],
        ]);
    }

    // ------------------------------------------------------------------ jalan & sungai
    // Label jalan dan sungai dari lapisan DXF "BATAS JALAN" dan "BATAS PERAIRAN".
    // Koordinat masih meter lokal DXF dan diterjemahkan dxfKeWgs84().
    if ($lapisan === 'jalan' || $lapisan === 'sungai') {
        require_once __DIR__ . '/../tools/lib-impor.php';
        $namaFile = $lapisan === 'jalan' ? 'batas-jalan.geojson' : 'batas-perairan.geojson';
        $berkas = SIP_ROOT . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . $namaFile;
        if (!is_file($berkas)) {
            json_error('Berkas ' . $namaFile . ' tidak ditemukan.', 404);
        }
        $data = json_decode((string) file_get_contents($berkas), true);
        $fitur = [];
        foreach (($data['features'] ?? []) as $f) {
            $geom = $f['geometry'] ?? [];
            $prop = $f['properties'] ?? [];
            if (($geom['type'] ?? '') !== 'Point') continue;
            $koordinat = $geom['coordinates'] ?? [];
            if (!is_array($koordinat) || count($koordinat) < 2) continue;
            [$lon, $lat] = dxfKeWgs84((float) $koordinat[0], (float) $koordinat[1]);
            $label = $prop['label'] ?? $prop['text'] ?? '';
            if ($label === '') continue;
            $fitur[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [round($lon, 7), round($lat, 7)]],
                'properties' => ['label' => $label],
            ];
        }
        json_response(['type' => 'FeatureCollection', 'features' => $fitur]);
    }

    // ------------------------------------------------------------------ blok peta
    if ($lapisan === 'blok') {
        $baris = db_all(
            'SELECT id, kode, nomor, batas_tersedia, batas_perkiraan, jumlah_bidang_estimasi,
                    ST_AsGeoJSON(geom) AS geojson
             FROM blok ORDER BY nomor, kode'
        );
        $fitur = [];
        foreach ($baris as $bl) {
            $geometry = json_decode((string) $bl['geojson'], true);
            if (!is_array($geometry)) {
                continue;
            }
            $fitur[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'id' => (int) $bl['id'],
                    'kode' => 'BLOK ' . str_pad((string) $bl['nomor'], 2, '0', STR_PAD_LEFT),
                    'nomor' => $bl['nomor'] === null ? null : (int) $bl['nomor'],
                    'batas_tersedia' => (int) $bl['batas_tersedia'] === 1,
                    'batas_perkiraan' => (int) $bl['batas_perkiraan'] === 1,
                    'estimasi_bidang' => $bl['jumlah_bidang_estimasi'] === null
                        ? null : (int) $bl['jumlah_bidang_estimasi'],
                ],
            ];
        }
        json_response(['type' => 'FeatureCollection', 'features' => $fitur]);
    }

    $query = [
        'tipe'   => get_str('tipe'),
        'dusun'  => get_str('dusun'),
        'blok'   => get_str('blok'),
        'status' => get_str('status'),
        'hak'    => get_str('hak'),
        'cari'   => get_str('cari'),
        'punyaNop' => get_str('punya_nop'),
        'tanpaBlok' => get_str('tanpa_blok'),
    ];
    [$where, $param] = bidang_filter($query);

    // ------------------------------------------------------------------ detail satu bidang
    $idBidang = get_int('id');
    if ($idBidang !== null) {
        wajib_masuk();
        $utama = detail_bidang($idBidang);
        if ($utama === null) {
            json_error('Bidang tidak ditemukan.', 404);
        }
        json_response(['status' => 'berhasil', 'item' => $utama]);
    }

    // ------------------------------------------------------------------ GeoJSON lapisan bidang
    if ($lapisan === 'bidang') {
        $maks = max(100, min(50000, (int) (get_str('maks') ?: 6000)));
        $baris = db_all(
            "SELECT b.id, b.kode, b.tipe, b.nib, b.nis, b.nomor_daftar, b.luas_m2, b.status, b.sumber,
                    b.pusat_lon, b.pusat_lat,
                    bl.kode AS blok, du.nama AS dusun, jp.nama AS penggunaan,
                    jh.kode AS hak_kode, jh.nama AS hak_nama, h.pemegang_nama,
                    o.nop, o.nama_wp,
                    (SELECT s.status_bayar FROM sppt s WHERE s.nop = o.nop
                      ORDER BY s.tahun_pajak DESC LIMIT 1) AS status_bayar,
                    ST_AsGeoJSON(b.geom) AS geojson
             " . BIDANG_JOIN . ' ' . $where . "
             LIMIT {$maks}",
            $param
        );

        // Kandidat blok hanya dihitung untuk bidang yang belum ber-blok, supaya
        // muatan 4.000+ bidang tidak menambah puluhan ribu kueri.
        $tanpaBlok = array_values(array_filter(
            $baris,
            static fn(array $b): bool => $b['blok'] === null && $b['sumber'] !== 'peta-020100'
        ));
        $kandidat = kandidat_blok_bidang($tanpaBlok);

        $fitur = [];
        foreach ($baris as $b) {
            $geometry = json_decode((string) $b['geojson'], true);
            if (!is_array($geometry)) {
                continue;
            }
            $fitur[] = [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => [
                    'id' => (int) $b['id'],
                    'kode' => $b['kode'],
                    'tipe' => $b['tipe'],
                    'nib' => $b['nib'],
                    'nis' => $b['nis'],
                    'nomor_daftar' => $b['nomor_daftar'],
                    'luas' => $b['luas_m2'] === null ? null : (float) $b['luas_m2'],
                    'status' => $b['status'],
                    'blok' => $b['blok'],
                    'blok_kandidat' => $b['blok'] === null ? ($kandidat[(int) $b['id']] ?? null) : null,
                    'dusun' => $b['dusun'],
                    'penggunaan' => $b['penggunaan'],
                    'hak_kode' => $b['hak_kode'],
                    'hak_nama' => $b['hak_nama'],
                    'pemegang' => $b['pemegang_nama'] ?: ($b['nama_wp'] ?: ''),
                    'nop' => $b['nop'],
                    'pbb' => $b['status_bayar'],
                ],
            ];
        }
        if ($publik) {
            $fitur = sembunyikan_pribadi($fitur, ['pemegang', 'nop']);
        }
        json_response([
            'type' => 'FeatureCollection',
            'jumlah' => count($fitur),
            'terpotong' => count($fitur) >= $maks,
            'features' => $fitur,
        ]);
    }

    // ------------------------------------------------------------------ daftar ringkas
    [$halaman, $perHalaman] = batas_halaman(get_int('halaman'), get_int('per_halaman'), 100);
    $daftar = daftar_bidang($query, $halaman, $perHalaman);
    if ($publik) {
        $daftar['items'] = sembunyikan_pribadi(
            $daftar['items'],
            ['pemegang_nama', 'nama_wp', 'no_sertifikat', 'nop']
        );
    }

    json_response([
        'status' => 'berhasil',
        'jumlah' => $daftar['total'],
        'halaman' => $halaman,
        'per_halaman' => $perHalaman,
        'items' => $daftar['items'],
    ]);
} catch (Throwable $error) {
    error_log('SIPETADES api/bidang: ' . $error->getMessage());
    json_error('Data bidang belum dapat dimuat.', 500);
}
