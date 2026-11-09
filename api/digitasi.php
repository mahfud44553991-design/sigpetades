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

$kode = trim($_POST['kode'] ?? '');
$nib = trim($_POST['nib'] ?? '') ?: null;
$jenis_hak_id = (int) ($_POST['jenis_hak_id'] ?? 0);
$luas_input = trim((string) ($_POST['luas_m2'] ?? ''));
$luas_m2 = $luas_input !== '' ? (float) $luas_input : null;
$blok_id = trim((string) ($_POST['blok_id'] ?? '')) !== '' ? (int) $_POST['blok_id'] : null;
$dusun_id = trim((string) ($_POST['dusun_id'] ?? '')) !== '' ? (int) $_POST['dusun_id'] : null;
$catatan = trim($_POST['catatan'] ?? '') ?: null;
$geojson = $_POST['geojson'] ?? '';
$referensi_id = (int) ($_POST['bidang_referensi_id'] ?? 0);

if ($kode === '') {
    json_response(['sukses' => false, 'pesan' => 'Kode bidang wajib diisi']);
}

if ($jenis_hak_id === 0) {
    json_response(['sukses' => false, 'pesan' => 'Jenis hak wajib dipilih']);
}

if ($geojson === '') {
    json_response(['sukses' => false, 'pesan' => 'Polygon belum dibuat (minimal 3 titik)']);
}

// Validasi GeoJSON
$geojson_data = json_decode($geojson, true);
if (!is_array($geojson_data) || ($geojson_data['type'] ?? '') !== 'Polygon') {
    json_response(['sukses' => false, 'pesan' => 'Format GeoJSON tidak valid']);
}

$coordinates = $geojson_data['coordinates'][0] ?? [];
if (count($coordinates) < 4) {
    json_response(['sukses' => false, 'pesan' => 'Polygon harus memiliki minimal 3 titik (4 dengan penutup)']);
}

// Validasi rentang koordinat sebelum dijadikan geometri
foreach ($coordinates as $coord) {
    if (!is_array($coord) || count($coord) < 2) {
        json_response(['sukses' => false, 'pesan' => 'Koordinat polygon tidak lengkap']);
    }
    $lon = (float) $coord[0];
    $lat = (float) $coord[1];
    if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
        json_response(['sukses' => false, 'pesan' => 'Koordinat polygon di luar rentang derajat']);
    }
}

$wkt = geometri_ke_wkt($geojson_data);
if ($wkt === '') {
    json_response(['sukses' => false, 'pesan' => 'Polygon tidak terbentuk (minimal 3 titik sudut)']);
}

// Luas dihitung dari geometri kecuali pengguna mengisi manual
if ($luas_m2 === null) {
    $luas_m2 = luas_geometri_m2($geojson_data);
}

$pusat = pusat_geometri($geojson_data);

// Bidang referensi (opsional) divalidasi lalu dicatat sebagai asal digitasi
if ($referensi_id > 0) {
    $kode_referensi = db_value('SELECT kode FROM bidang WHERE id = ?', [$referensi_id]);
    if ($kode_referensi === null) {
        json_response(['sukses' => false, 'pesan' => 'Bidang referensi tidak ditemukan']);
    }
    $catatan = $catatan !== null ? $catatan . ' | ' : '';
    $catatan .= 'Digitasi mengacu pada bidang ' . $kode_referensi;
}

if (db_value('SELECT kode FROM jenis_hak WHERE id = ?', [$jenis_hak_id]) === null) {
    json_response(['sukses' => false, 'pesan' => 'Jenis hak tidak dikenali']);
}

// Cek kode duplikat
$cek = db()->prepare("SELECT id FROM bidang WHERE kode = ?");
$cek->execute([$kode]);
if ($cek->fetch()) {
    json_response(['sukses' => false, 'pesan' => 'Kode bidang sudah ada: ' . $kode]);
}

// Simpan
try {
    $sql = "INSERT INTO bidang (kode, tipe, nib, luas_m2, blok_id, dusun_id, status, sumber, catatan, geom, pusat_lon, pusat_lat)
            VALUES (?, 'tanah', ?, ?, ?, ?, 'aktif', 'digitasi', ?, ST_GeomFromText(?, 4326), ?, ?)";

    $stmt = db()->prepare($sql);
    $stmt->execute([
        $kode,
        $nib,
        $luas_m2,
        $blok_id,
        $dusun_id,
        $catatan,
        $wkt,
        $pusat['lon'],
        $pusat['lat']
    ]);

    $id = (int) db()->lastInsertId();

    // Simpan ke tabel hak
    $hakSql = "INSERT INTO hak (bidang_id, jenis_hak_id, catatan) VALUES (?, ?, ?)";
    $hakStmt = db()->prepare($hakSql);
    $hakStmt->execute([$id, $jenis_hak_id, 'Dibuat via digitasi manual']);

    audit('buat', 'bidang', (string) $id, "Bidang baru dibuat via digitasi: $kode");

    json_response([
        'sukses' => true,
        'id' => $id,
        'kode' => $kode,
        'pesan' => 'Bidang berhasil disimpan'
    ]);
} catch (PDOException $e) {
    json_response(['sukses' => false, 'pesan' => 'Database error: ' . $e->getMessage()], 500);
}
