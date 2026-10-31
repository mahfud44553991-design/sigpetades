<?php
declare(strict_types=1);

/**
 * Angka ringkasan untuk dasbor dan legenda peta.
 *
 *   GET api/statistik.php?tahun=2026
 *
 * Isinya murni memanggil fungsi pembaca di app/data.php supaya dasbor dan
 * endpoint ini tidak pernah menampilkan angka yang berbeda.
 */

require_once __DIR__ . '/../app/bootstrap.php';

try {
    $tahun = get_int('tahun') ?? (int) setelan('tahun_pbb_aktif', '2026');

    json_response([
        'status' => 'berhasil',
        'tahun' => $tahun,
        'tahun_tersedia' => daftar_tahun_pbb(),
        'bidang' => statistik_bidang(),
        'hak' => komposisi_hak(),
        'pbb' => statistik_pbb($tahun),
        'dusun' => rekap_pbb_dusun($tahun),
        'peralihan' => sebaran_peralihan($tahun),
        'laporan' => statistik_laporan(),
        'arsip' => ['buku_c' => (int) db_value('SELECT COUNT(*) FROM buku_c')],
    ]);
} catch (Throwable $error) {
    error_log('SIPETADES api/statistik: ' . $error->getMessage());
    json_error('Statistik belum dapat dihitung.', 500);
}
