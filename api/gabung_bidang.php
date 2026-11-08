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
$luas_m2 = $_POST['luas_m2'] !== '' ? (float) $_POST['luas_m2'] : null;
$blok_id = $_POST['blok_id'] !== '' ? (int) $_POST['blok_id'] : null;
$dusun_id = $_POST['dusun_id'] !== '' ? (int) $_POST['dusun_id'] : null;
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

$db = db();
$db->beginTransaction();

try {
    $wkt = geojson_to_wkt($geometry);
    if ($wkt === '') {
        throw new RuntimeException('Gagal mengkonversi geometri ke WKT');
    }

    if ($luas_m2 === null) {
        $luas_m2 = hitung_luas_dari_wkt($wkt);
    }

    $pusat = hitung_pusat_dari_wkt($wkt);

    $cek = $db->prepare("SELECT id FROM bidang WHERE kode = ?");
    $cek->execute([$kode_baru]);
    if ($cek->fetch()) {
        json_response(['sukses' => false, 'pesan' => 'Kode bidang sudah ada: ' . $kode_baru]);
    }

    $sql = "INSERT INTO bidang (kode, tipe, nib, luas_m2, blok_id, dusun_id, status, sumber, catatan, geom, pusat_lon, pusat_lat)
            VALUES (?, 'tanah', ?, ?, ?, ?, 'aktif', 'penggabungan', ?, ST_GeomFromText(?, 4326), ?, ?)";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        $kode_baru,
        $nib_baru,
        $luas_m2,
        $blok_id,
        $dusun_id,
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
} catch (RuntimeException $e) {
    $db->rollBack();
    json_response(['sukses' => false, 'pesan' => $e->getMessage()], 500);
}

function geojson_to_wkt(array $geometry): string {
    if ($geometry['type'] === 'Polygon') {
        $rings = $geometry['coordinates'];
        $ringStrs = [];
        foreach ($rings as $ring) {
            $points = [];
            foreach ($ring as $coord) {
                $points[] = (float) $coord[0] . ' ' . (float) $coord[1];
            }
            $ringStrs[] = '(' . implode(',', $points) . ')';
        }
        return 'POLYGON(' . implode(',', $ringStrs) . ')';
    } elseif ($geometry['type'] === 'MultiPolygon') {
        $polygons = $geometry['coordinates'];
        $polyStrs = [];
        foreach ($polygons as $rings) {
            $ringStrs = [];
            foreach ($rings as $ring) {
                $points = [];
                foreach ($ring as $coord) {
                    $points[] = (float) $coord[0] . ' ' . (float) $coord[1];
                }
                $ringStrs[] = '(' . implode(',', $points) . ')';
            }
            $polyStrs[] = '(' . implode(',', $ringStrs) . ')';
        }
        return 'MULTIPOLYGON(' . implode(',', $polyStrs) . ')';
    }
    return '';
}

function hitung_luas_dari_wkt(string $wkt): float {
    preg_match('/POLYGON\(\((.+)\)\)/', $wkt, $matches);
    if (!isset($matches[1])) return 0.0;

    $points = [];
    $pairs = explode(',', $matches[1]);
    foreach ($pairs as $pair) {
        $coords = explode(' ', trim($pair));
        if (count($coords) >= 2) {
            $points[] = [(float) $coords[0], (float) $coords[1]];
        }
    }

    $n = count($points);
    if ($n < 3) return 0.0;

    $luas_derajat = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $luas_derajat += $points[$i][0] * $points[$j][1];
        $luas_derajat -= $points[$j][0] * $points[$i][1];
    }
    $luas_derajat = abs($luas_derajat) / 2.0;
    $luas_m2 = $luas_derajat * pow(111320, 2);

    return round($luas_m2, 2);
}

function hitung_pusat_dari_wkt(string $wkt): array {
    preg_match('/POLYGON\(\((.+)\)\)/', $wkt, $matches);
    if (!isset($matches[1])) return ['lon' => 0, 'lat' => 0];

    $points = [];
    $pairs = explode(',', $matches[1]);
    foreach ($pairs as $pair) {
        $coords = explode(' ', trim($pair));
        if (count($coords) >= 2) {
            $points[] = [(float) $coords[0], (float) $coords[1]];
        }
    }

    $n = count($points);
    if ($n === 0) return ['lon' => 0, 'lat' => 0];

    $sum_lon = 0.0;
    $sum_lat = 0.0;
    foreach ($points as $p) {
        $sum_lon += $p[0];
        $sum_lat += $p[1];
    }

    return [
        'lon' => round($sum_lon / $n, 7),
        'lat' => round($sum_lat / $n, 7)
    ];
}
