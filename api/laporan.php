<?php
declare(strict_types=1);

/**
 * Laporan masyarakat.
 *
 *   GET  api/laporan.php?status=&kategori=   daftar untuk pengelola (butuh masuk)
 *   POST api/laporan.php action=ubah-status  perbarui penanganan (admin/super admin)
 *
 * Pembuatan laporan sengaja tidak ada di sini: formulirnya diproses langsung
 * oleh laporan.php sehingga tetap jalan tanpa JavaScript, dan seperti halaman
 * itu endpoint ini juga mewajibkan pengguna masuk.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$kategoriSah = ['Sengketa', 'Tumpang tindih', 'Data salah', 'Peralihan belum tercatat', 'Lainnya'];
$statusSah = ['Menunggu', 'Diproses', 'Selesai', 'Ditolak'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pengguna = wajib_masuk(PERAN_PENGELOLA);
        wajib_csrf();

        $aksi = post_str('action');
        if ($aksi !== 'ubah-status') {
            json_error('Tindakan tidak dikenal.', 422);
        }

        $id = post_int('id');
        $status = post_str('status');
        if ($id === null || !in_array($status, $statusSah, true)) {
            json_error('Laporan atau status yang dipilih tidak valid.', 422);
        }

        $lama = db_one('SELECT id, nomor, status, tanggal_selesai FROM laporan WHERE id = ?', [$id]);
        if ($lama === null) {
            json_error('Laporan tidak ditemukan.', 404);
        }

        $selesai = $status === 'Selesai' || $status === 'Ditolak';
        db_exec(
            'UPDATE laporan
             SET status = ?, catatan_penanganan = ?, ditangani_oleh = ?,
                 tanggal_selesai = CASE WHEN ? THEN COALESCE(tanggal_selesai, NOW()) ELSE NULL END
             WHERE id = ?',
            [
                $status,
                post_str('catatan') === '' ? null : mb_substr(post_str('catatan'), 0, 4000),
                (int) $pengguna['id'],
                $selesai ? 1 : 0,
                $id,
            ]
        );

        audit('ubah', 'laporan', (string) $id,
            $lama['status'] . ' -> ' . $status,
            ['status' => $lama['status']],
            ['status' => $status]);

        json_ok(['nomor' => $lama['nomor'], 'status' => $status], 'Status laporan diperbarui.');
    }

    $pengguna = wajib_masuk();
    $kelola = in_array($pengguna['peran'], PERAN_PENGELOLA, true);

    $query = [
        'status'   => get_str('status'),
        'kategori' => get_str('kategori'),
        'cari'     => get_str('cari'),
    ];
    [$halaman, $perHalaman] = batas_halaman(get_int('halaman'), get_int('per_halaman'), 100);
    $daftar = daftar_laporan($query, $halaman, $perHalaman, $kelola);

    json_response([
        'status' => 'berhasil',
        'jumlah' => $daftar['total'],
        'halaman' => $halaman,
        'per_halaman' => $perHalaman,
        'items' => $daftar['items'],
    ]);
} catch (Throwable $error) {
    error_log('SIPETADES api/laporan: ' . $error->getMessage());
    json_error('Data laporan belum dapat dimuat.', 500);
}
