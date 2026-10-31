<?php
declare(strict_types=1);

/**
 * Indeks Buku C Desa (arsip hasil pemindaian lembar C-1/C-2/C-3).
 *
 *   GET api/buku-c.php?cari=nama+atau+nomor&dusun=&bidang=
 *   GET api/buku-c.php?id=142
 *
 * Berkas PDF-nya sengaja tidak dibuka lewat path publik. Halaman web hanya
 * menampilkan tautan ke dokumen.php, yang memeriksa hak akses sebelum
 * mengalirkan isinya.
 */

require_once __DIR__ . '/../app/bootstrap.php';

wajib_masuk();

try {
    $id = get_int('id');
    if ($id !== null) {
        $item = detail_buku_c($id);
        if ($item === null) {
            json_error('Entri Buku C tidak ditemukan.', 404);
        }
        json_response(['status' => 'berhasil', 'item' => $item]);
    }

    $query = [
        'cari'   => get_str('cari'),
        'dusun'  => get_str('dusun'),
        'nomor'  => get_str('nomor'),
        'jenis'  => get_str('jenis'),
        'tanpa_pemegang' => get_str('tanpa_pemegang'),
    ];
    [$halaman, $perHalaman] = batas_halaman(get_int('halaman'), get_int('per_halaman'), 100);
    $daftar = daftar_buku_c($query, $halaman, $perHalaman);

    json_response([
        'status' => 'berhasil',
        'jumlah' => $daftar['total'],
        'halaman' => $halaman,
        'per_halaman' => $perHalaman,
        'items' => $daftar['items'],
    ]);
} catch (Throwable $error) {
    error_log('SIPETADES api/buku-c: ' . $error->getMessage());
    json_error('Indeks Buku C belum dapat dimuat.', 500);
}
