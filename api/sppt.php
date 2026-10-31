<?php
declare(strict_types=1);

/**
 * Data e-SPPT / PBB.
 *
 *   GET api/sppt.php?tahun=2026&status=Belum Bayar&cari=nop%20atau%20nama
 *   GET api/sppt.php?blok=5        -> hanya objek pajak di blok peta itu
 *   GET api/sppt.php?nop=332404000800200010     -> detail satu NOP
 *
 * Angka sisa tidak dihitung di PHP; kolom generated `sisa` di tabel sppt
 * sudah menjamin nilainya konsisten dengan pajak_ditetapkan - terbayar.
 */

require_once __DIR__ . '/../app/bootstrap.php';

wajib_masuk();

try {
    $tahun = get_int('tahun') ?? (int) setelan('tahun_pbb_aktif', '2026');
    $nop = normalkan_nop(get_str('nop'));

    if ($nop !== '') {
        $detail = detail_objek_pajak($nop);
        if ($detail === null) {
            json_error('NOP tidak ditemukan pada data desa.', 404);
        }
        json_response(['status' => 'berhasil'] + $detail);
    }

    $query = [
        'tahun'  => $tahun,
        'status' => get_str('status'),
        'dusun'  => get_str('dusun'),
        'blok'   => get_str('blok'),
        'cari'   => get_str('cari'),
    ];
    [$halaman, $perHalaman] = batas_halaman(get_int('halaman'), get_int('per_halaman'), 100);
    $daftar = daftar_sppt($query, $halaman, $perHalaman);
    $ringkas = $daftar['ringkas'];

    json_response([
        'status' => 'berhasil',
        'tahun' => $tahun,
        'jumlah' => $daftar['total'],
        'halaman' => $halaman,
        'per_halaman' => $perHalaman,
        'total_ketetapan' => (float) ($ringkas['ketetapan'] ?? 0),
        'total_terbayar' => (float) ($ringkas['terbayar'] ?? 0),
        'total_piutang' => (float) ($ringkas['piutang'] ?? 0),
        'items' => $daftar['items'],
    ]);
} catch (Throwable $error) {
    error_log('SIPETADES api/sppt: ' . $error->getMessage());
    json_error('Data SPPT belum dapat dimuat.', 500);
}
