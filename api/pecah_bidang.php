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

$bidang_asal_id = (int) ($_POST['bidang_asal_id'] ?? 0);
$hasil_geojson = $_POST['hasil_geojson'] ?? '';
$kode_baru_json = $_POST['kode_baru'] ?? '[]';

if ($bidang_asal_id === 0) {
    json_response(['sukses' => false, 'pesan' => 'Bidang asal tidak valid']);
}

if ($hasil_geojson === '') {
    json_response(['sukses' => false, 'pesan' => 'Hasil geojson kosong']);
}

$hasil = json_decode($hasil_geojson, true);
if (!is_array($hasil) || !isset($hasil['geometry'])) {
    json_response(['sukses' => false, 'pesan' => 'Format GeoJSON tidak valid']);
}

$kode_baru = json_decode($kode_baru_json, true);
if (!is_array($kode_baru)) {
    $kode_baru = [];
}

// Ambil data bidang asal
$asal = db_one("SELECT id, kode, nib, blok_id, dusun_id, jenis_penggunaan_id, luas_m2, status FROM bidang WHERE id = ?", [$bidang_asal_id]);
if (!$asal) {
    json_response(['sukses' => false, 'pesan' => 'Bidang asal tidak ditemukan']);
}

// Ambil hak bidang asal
$hak_asal = db_one("SELECT jenis_hak_id, pemegang_nama, catatan FROM hak WHERE bidang_id = ?", [$bidang_asal_id]);

$geometry = $hasil['geometry'];
$bagian_list = [];

if ($geometry['type'] === 'MultiPolygon') {
    foreach ($geometry['coordinates'] as $coords) {
        $bagian_list[] = ['type' => 'Polygon', 'coordinates' => $coords];
    }
} elseif ($geometry['type'] === 'Polygon') {
    $bagian_list[] = $geometry;
} else {
    json_response(['sukses' => false, 'pesan' => 'Tipe geometri tidak didukung: ' . $geometry['type']]);
}

if (count($bagian_list) < 2) {
    json_response(['sukses' => false, 'pesan' => 'Hasil pecah harus minimal 2 bagian']);
}

$db = db();
$db->beginTransaction();
$bidang_baru_ids = [];

try {
    foreach ($bagian_list as $i => $geom) {
        $kode = !empty($kode_baru[$i]) ? trim($kode_baru[$i]) : $asal['kode'] . '-' . chr(65 + $i);
        $wkt = geometri_ke_wkt($geom);
        if ($wkt === '') {
            throw new RuntimeException('Bagian ke-' . ($i + 1) . ' bukan polygon yang sah');
        }

        $luas = luas_geometri_m2($geom);
        $pusat = pusat_geometri($geom);

        $kode = kode_tersedia($kode, $db);

        // Anak mewarisi status induk supaya antrean verifikasi tidak hilang
        // saat bidang perlu_verifikasi dipecah.
        $sql = "INSERT INTO bidang (kode, tipe, nib, blok_id, dusun_id, jenis_penggunaan_id, luas_m2, status, sumber, catatan, geom, pusat_lon, pusat_lat)
                VALUES (?, 'tanah', ?, ?, ?, ?, ?, ?, 'pecah_bidang', ?, ST_GeomFromText(?, 4326), ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $kode,
            $asal['nib'],
            $asal['blok_id'],
            $asal['dusun_id'],
            $asal['jenis_penggunaan_id'],
            $luas,
            $asal['status'],
            'Pecahan dari bidang ' . $asal['kode'] . ' (bagian ' . ($i + 1) . ')',
            $wkt,
            $pusat['lon'],
            $pusat['lat']
        ]);

        $id_baru = (int) $db->lastInsertId();
        $bidang_baru_ids[] = $id_baru;

        if ($hak_asal) {
            $hakSql = "INSERT INTO hak (bidang_id, jenis_hak_id, pemegang_nama, catatan) VALUES (?, ?, ?, ?)";
            $hakStmt = $db->prepare($hakSql);
            $hakStmt->execute([
                $id_baru,
                $hak_asal['jenis_hak_id'],
                $hak_asal['pemegang_nama'],
                'Pecahan dari bidang ' . $asal['kode']
            ]);
        }

        audit('buat', 'bidang', (string) $id_baru, "Pecahan bidang $asal[kode] -> $kode");
    }

    // Induk ditandai dihapus; kalau tidak, luasnya dihitung ganda bersama anak-anaknya.
    $hapusAsal = $db->prepare("UPDATE bidang SET status = 'dihapus', catatan = CONCAT(COALESCE(catatan, ''), '\nDipecah menjadi ', ?) WHERE id = ?");
    $hapusAsal->execute([implode(', ', $bidang_baru_ids), $bidang_asal_id]);

    $db->commit();

    json_response([
        'sukses' => true,
        'jumlah' => count($bidang_baru_ids),
        'bidang_baru' => $bidang_baru_ids,
        'pesan' => 'Bidang berhasil dipecah menjadi ' . count($bidang_baru_ids) . ' bagian'
    ]);
} catch (PDOException $e) {
    $db->rollBack();
    json_response(['sukses' => false, 'pesan' => 'Database error: ' . $e->getMessage()], 500);
} catch (RuntimeException $e) {
    $db->rollBack();
    json_response(['sukses' => false, 'pesan' => $e->getMessage()], 422);
}

/**
 * Pastikan kode unik sebelum INSERT; hindari 1062 membatalkan seluruh transaksi.
 * Batas 32 karakter mengikuti kolom bidang.kode.
 */
function kode_tersedia(string $kode, PDO $db): string
{
    $cek = $db->prepare('SELECT 1 FROM bidang WHERE kode = ?');
    $urutan = 1;
    $calon = $kode;
    $cek->execute([$calon]);
    while ($cek->fetch()) {
        $urutan++;
        $buntut = '-' . $urutan;
        $maks = max(1, 32 - strlen($buntut));
        $calon = substr($kode, 0, $maks) . $buntut;
        $cek->execute([$calon]);
        if ($urutan > 200) {
            throw new RuntimeException('Kode turunan untuk ' . $kode . ' sudah habis');
        }
    }

    return $calon;
}
