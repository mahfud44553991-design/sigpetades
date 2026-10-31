<?php
declare(strict_types=1);

/**
 * Proksi berkas arsip. Folder "files/Data C Desa" dan "storage/laporan"
 * ditutup untuk HTTP lewat .htaccess, jadi pindaian Buku C hanya bisa dibuka
 * lewat halaman ini setelah pengguna masuk dan haknya diperiksa.
 *
 *   dokumen.php?buku-c=142   -> mengalirkan berkas PDF lembar C nomor 142
 */

require __DIR__ . '/app/bootstrap.php';

$pengguna = wajib_masuk();

/** Amanahkan nama berkas: hanya baseline, tanpa path dan tanpa traversal. */
function nama_berkas_aman(string $nilai): string
{
    $hanya = str_replace('\\', '/', $nilai);
    return basename($hanya);
}

/**
 * Cari berkas di dalam folder yang diizinkan.
 * @return array{0: ?string, 1: string} [path absolut, pesan galat]
 */
function cari_arsip(string $folder, string $nama): array
{
    $realFolder = realpath($folder);
    if ($realFolder === false || !is_dir($realFolder)) {
        return [null, 'Folder arsip belum ada di server. Periksa pengaturan path.arsip_buku_c.'];
    }
    $nama = nama_berkas_aman($nama);
    if ($nama === '' || $nama === '.' || $nama === '..') {
        return [null, 'Nama berkas tidak valid.'];
    }
    $realBerkas = realpath($realFolder . '/' . $nama);
    if ($realBerkas === false || !is_file($realBerkas)
        || !str_starts_with($realBerkas, $realFolder . DIRECTORY_SEPARATOR)) {
        return [null, 'Berkas tidak ditemukan di folder arsip.'];
    }
    return [$realBerkas, ''];
}

$idBuku = get_int('buku-c');
if ($idBuku === null) {
    http_response_code(400);
    exit('Berkas arsip harus diminta lewat nomor entry-nya.');
}

$baris = db_one('SELECT id, nomor, jenis, berkas_pdf, nama_pemegang FROM buku_c WHERE id = ?', [$idBuku]);
if ($baris === null) {
    http_response_code(404);
    exit('Entri Buku C tidak ditemukan.');
}

$namaBerkas = (string) ($baris['berkas_pdf'] ?: $baris['nomor'] . '.pdf');
[$path, $galat] = cari_arsip((string) sip_config('path.arsip_buku_c', ''), $namaBerkas);
if ($path === null) {
    http_response_code(404);
    exit($galat);
}

if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
    http_response_code(415);
    exit('Berkas ini bukan PDF dan tidak dialirkan lewat proksi.');
}

audit('ekspor', 'buku_c', (string) $baris['nomor'],
    'Pindaian Buku C-' . $baris['nomor'] . ' dibuka sebagai ' . $pengguna['peran']);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="Buku-C-' . $baris['nomor'] . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
header('Pragma: no-cache');

readfile($path);
exit;
