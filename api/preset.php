<?php
declare(strict_types=1);

/**
 * CRUD preset tampilan peta.
 *
 *   GET  api/preset.php              daftar semua preset
 *   GET  api/preset.php?id=1         ambil satu preset
 *   POST api/preset.php              simpan preset baru atau perbarui yang ada
 *         body: {aksi: 'simpan', id?: int, nama: string, data: object}
 *   POST api/preset.php
 *         body: {aksi: 'hapus', id: int}
 *
 * Preset disimpan per pengguna dan bisa dimuat dari browser mana saja.
 */

require_once __DIR__ . '/../app/bootstrap.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $id = get_int('id');
        if ($id !== null) {
            $preset = db_all("SELECT id, nama, data_json, dibuat_oleh, dibuat_pada, diubah_pada FROM preset_peta WHERE id = ?", [$id]);
            if (empty($preset)) {
                json_error('Preset tidak ditemukan.', 404);
            }
            $preset[0]['data'] = json_decode($preset[0]['data_json'], true);
            unset($preset[0]['data_json']);
            json_ok(['preset' => $preset[0]]);
        }

        $daftar = db_all("SELECT id, nama, data_json, dibuat_oleh, dibuat_pada, diubah_pada FROM preset_peta ORDER BY nama");
        foreach ($daftar as &$d) {
            $d['data'] = json_decode($d['data_json'], true);
            unset($d['data_json']);
        }
        json_ok(['preset' => $daftar]);
    }

    if ($method === 'POST') {
        $pengguna = wajib_masuk();
        wajib_csrf();

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            json_error('Data tidak valid.', 400);
        }

        $aksi = $body['aksi'] ?? '';
        if ($aksi === 'simpan') {
            $id = isset($body['id']) ? (int) $body['id'] : null;
            $nama = trim((string) ($body['nama'] ?? ''));
            $data = $body['data'] ?? null;

            if ($nama === '') {
                json_error('Nama preset wajib diisi.', 400);
            }
            if (!is_array($data)) {
                json_error('Data preset tidak valid.', 400);
            }

            $data_json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($id === null) {
                $stmt = db_query("INSERT INTO preset_peta (nama, data_json, dibuat_oleh) VALUES (?, ?, ?)", [$nama, $data_json, $pengguna['id']]);
                $idBaru = (int) db_value("SELECT LAST_INSERT_ID()");
                json_response(['status' => 'berhasil', 'pesan' => 'Preset disimpan.', 'id' => $idBaru], 201);
            } else {
                $existing = db_all("SELECT dibuat_oleh FROM preset_peta WHERE id = ?", [$id]);
                if (empty($existing)) {
                    json_error('Preset tidak ditemukan.', 404);
                }
                db_query("UPDATE preset_peta SET nama = ?, data_json = ? WHERE id = ?", [$nama, $data_json, $id]);
                json_ok(['id' => $id, 'pesan' => 'Preset diperbarui.']);
            }
        } elseif ($aksi === 'hapus') {
            $id = (int) ($body['id'] ?? 0);
            if ($id <= 0) {
                json_error('ID preset tidak valid.', 400);
            }
            $existing = db_all("SELECT dibuat_oleh FROM preset_peta WHERE id = ?", [$id]);
            if (empty($existing)) {
                json_error('Preset tidak ditemukan.', 404);
            }
            db_query("DELETE FROM preset_peta WHERE id = ?", [$id]);
            json_ok(['pesan' => 'Preset dihapus.']);
        } else {
            json_error('Aksi tidak dikenal.', 422);
        }
    }

    json_error('Metode tidak didukung.', 405);
} catch (Throwable $error) {
    error_log('SIPETADES api/preset: ' . $error->getMessage());
    json_error('Terjadi kesalahan saat memproses preset.', 500);
}
