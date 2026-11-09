<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
wajib_masuk();

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');

if ($query === '') {
    json_response(['sukses' => true, 'data' => []]);
}

try {
    // Kembaran duplikat/baris dihapus tidak boleh bisa dipilih sebagai objek
    // pecah/gabung; saringan ini mengikuti konvensi bidang_filter().
    $sql = "SELECT DISTINCT b.id, b.kode, b.nib, b.nis, b.luas_m2, b.status, b.blok_id, b.dusun_id,
                   bk.nama AS blok_nama, d.nama AS dusun_nama,
                   h.pemegang_nama
            FROM bidang b
            LEFT JOIN blok bk ON b.blok_id = bk.id
            LEFT JOIN dusun d ON b.dusun_id = d.id
            LEFT JOIN hak h ON b.id = h.bidang_id
            WHERE b.status NOT IN ('dihapus', 'duplikat')
              AND (b.kode LIKE ? OR b.nib LIKE ? OR b.nis LIKE ? OR b.nomor_daftar LIKE ? OR h.pemegang_nama LIKE ?)
            ORDER BY b.kode
            LIMIT 50";

    $stmt = db()->prepare($sql);
    $likeQuery = '%' . $query . '%';
    $stmt->execute([$likeQuery, $likeQuery, $likeQuery, $likeQuery, $likeQuery]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    json_response([
        'sukses' => true,
        'data' => array_map(function($b) {
            return [
                'id' => $b['id'],
                'kode' => $b['kode'],
                'nib' => $b['nib'],
                'nis' => $b['nis'],
                'luas_m2' => (float)($b['luas_m2'] ?? 0),
                'status' => $b['status'],
                'blok_id' => $b['blok_id'],
                'dusun_id' => $b['dusun_id'],
                'blok_nama' => $b['blok_nama'] ?? '',
                'dusun_nama' => $b['dusun_nama'] ?? '',
                'pemegang_nama' => $b['pemegang_nama'] ?? '',
                'label' => $b['kode'] . ' | NIB: ' . ($b['nib'] ?? '-') . ' | ' .
                          number_format((float)($b['luas_m2'] ?? 0)) . ' m²' .
                          ($b['blok_nama'] ? ' | ' . $b['blok_nama'] : '') .
                          ($b['dusun_nama'] ? ' | ' . $b['dusun_nama'] : '') .
                          ($b['pemegang_nama'] ? ' | Pemilik: ' . $b['pemegang_nama'] : '') .
                          ($b['status'] === 'aktif' ? '' : ' | ' . str_replace('_', ' ', $b['status']))
            ];
        }, $results)
    ]);
} catch (PDOException $e) {
    json_response(['sukses' => false, 'pesan' => 'Database error: ' . $e->getMessage()], 500);
}
