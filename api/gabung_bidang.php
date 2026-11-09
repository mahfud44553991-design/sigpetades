<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
wajib_masuk();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['sukses' => false, 'pesan' => 'Method tidak diizinkan'], 405);
}

if (!csrf_valid($_POST['csrf'] ?? null)) {
    json_response(['sukses' => false, 'pesan' => 'Token CSRF tidak valid. Muat ulang halaman.'], 403);
}

$asal_ids_json = $_POST['asal_ids'] ?? '';
$gabung_geojson = $_POST['gabung_geojson'] ?? '';
$kode_baru = trim($_POST['kode_baru'] ?? '');
$nib_baru = trim($_POST['nib_baru'] ?? '') ?: null;
$jenis_hak_id = (int) ($_POST['jenis_hak_id'] ?? 0);
$luas_input = trim((string) ($_POST['luas_m2'] ?? ''));
$luas_m2 = $luas_input !== '' ? (float) $luas_input : null;
$blok_id = trim((string) ($_POST['blok_id'] ?? '')) !== '' ? (int) $_POST['blok_id'] : null;
$dusun_id = trim((string) ($_POST['dusun_id'] ?? '')) !== '' ? (int) $_POST['dusun_id'] : null;
$catatan = trim($_POST['catatan'] ?? '') ?: null;

if ($asal_ids_json === '') {
    json_response(['sukses' => false, 'pesan' => 'Daftar bidang asal kosong']);
}

$asal_ids = json_decode($asal_ids_json, true);
if (!is_array($asal_ids) || count($asal_ids) < 2) {
    json_response(['sukses' => false, 'pesan' => 'Minimal 2 bidang harus dipilih untuk penggabungan']);
}

if ($kode_baru === '') {
    json_response(['sukses' => false, 'pesan' => 'Kode bidang baru wajib diisi']);
}

if ($jenis_hak_id === 0) {
    json_response(['sukses' => false, 'pesan' => 'Jenis hak wajib dipilih']);
}

if ($gabung_geojson === '') {
    json_response(['sukses' => false, 'pesan' => 'GeoJSON gabungan kosong']);
}

$geometry = json_decode($gabung_geojson, true);
if (!is_array($geometry) || !in_array($geometry['type'] ?? '', ['Polygon', 'MultiPolygon'])) {
    json_response(['sukses' => false, 'pesan' => 'Format GeoJSON tidak valid']);
}

// Semua bidang asal harus ada; kalau tidak, hasil gabungan kehilangan induknya
// dan luas desa menyusut tanpa jejak.
$asal_ids = array_values(array_filter(array_map('intval', $asal_ids), static fn(int $id): bool => $id > 0));
$tanya = implode(',', array_fill(0, count($asal_ids), '?'));
$daftar_asal = db_all("SELECT id, kode, status FROM bidang WHERE id IN ($tanya)", $asal_ids);
if (count($daftar_asal) !== count($asal_ids)) {
    json_response(['sukses' => false, 'pesan' => 'Sebagian bidang asal tidak ditemukan, penggabungan dibatalkan']);
}

// Hasil gabungan ikut antrean verifikasi bila ada salah satu induk yang belum terverifikasi.
$status_baru = 'aktif';
foreach ($daftar_asal as $s) {
    if ($s['status'] !== 'aktif') {
        $status_baru = 'perlu_verifikasi';
        break;
    }
}

$wkt = geometri_ke_wkt($geometry);
if ($wkt === '') {
    json_response(['sukses' => false, 'pesan' => 'Geometri gabungan tidak dapat dikonversi menjadi WKT']);
}

if ($luas_m2 === null) {
    $luas_m2 = luas_geometri_m2($geometry);
}
$pusat = pusat_geometri($geometry);

if (db_value('SELECT kode FROM bidang WHERE kode = ?', [$kode_baru]) !== null) {
    json_response(['sukses' => false, 'pesan' => 'Kode bidang sudah ada: ' . $kode_baru]);
}

if (db_value('SELECT kode FROM jenis_hak WHERE id = ?', [$jenis_hak_id]) === null) {
    json_response(['sukses' => false, 'pesan' => 'Jenis hak tidak dikenali']);
}

$db = db();
$db->beginTransaction();

try {
    $sql = "INSERT INTO bidang (kode, tipe, nib, luas_m2, blok_id, dusun_id, status, sumber, catatan, geom, pusat_lon, pusat_lat)
            VALUES (?, 'tanah', ?, ?, ?, ?, ?, 'penggabungan', ?, ST_GeomFromText(?, 4326), ?, ?)";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $kode_baru,
        $nib_baru,
        $luas_m2,
        $blok_id,
        $dusun_id,
        $status_baru,
        $catatan,
        $wkt,
        $pusat['lon'],
        $pusat['lat']
    ]);

    $id_baru = (int) $db->lastInsertId();

    $hakSql = "INSERT INTO hak (bidang_id, jenis_hak_id, catatan) VALUES (?, ?, ?)";
    $hakStmt = $db->prepare($hakSql);
    $hakStmt->execute([$id_baru, $jenis_hak_id, 'Penggabungan dari bidang: ' . implode(', ', $asal_ids)]);

    foreach ($asal_ids as $asal_id) {
        $updateSql = "UPDATE bidang SET status = 'dihapus', catatan = CONCAT(COALESCE(catatan, ''), '\nDigabungkan ke bidang ', ?) WHERE id = ?";
        $updateStmt = $db->prepare($updateSql);
        $updateStmt->execute([$id_baru, $asal_id]);
    }

    audit('buat', 'bidang', (string) $id_baru, "Penggabungan bidang " . implode(',', $asal_ids) . " -> $kode_baru");

    $db->commit();

    json_response([
        'sukses' => true,
        'id' => $id_baru,
        'kode' => $kode_baru,
        'jumlah_asal' => count($asal_ids),
        'pesan' => 'Bidang gabungan berhasil disimpan'
    ]);
} catch (PDOException $e) {
    $db->rollBack();
    json_response(['sukses' => false, 'pesan' => 'Database error: ' . $e->getMessage()], 500);
}
