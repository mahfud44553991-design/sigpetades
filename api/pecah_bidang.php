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
$asal = db_row("SELECT id, kode, nib, blok_id, dusun_id, jenis_penggunaan_id, luas_m2, status FROM bidang WHERE id = ?", [$bidang_asal_id]);
if (!$asal) {
    json_response(['sukses' => false, 'pesan' => 'Bidang asal tidak ditemukan']);
}

// Ambil hak bidang asal
$hak_asal = db_row("SELECT jenis_hak_id, pemegang_nama, catatan FROM hak WHERE bidang_id = ?", [$bidang_asal_id]);

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
        $geojson_str = json_encode($geom);

        $wkt = geojson_polygon_to_wkt($geom);
        $luas = hitung_luas_dari_wkt($wkt);
        $pusat = hitung_pusat_dari_wkt($wkt);

        $cek = $db->prepare("SELECT id FROM bidang WHERE kode = ?");
        $cek->execute([$kode]);
        if ($cek->fetch()) {
            $kode = $kode . '-' . ($i + 1);
        }

        $sql = "INSERT INTO bidang (kode, tipe, nib, blok_id, dusun_id, jenis_penggunaan_id, luas_m2, status, sumber, catatan, geom, pusat_lon, pusat_lat)
                VALUES (?, 'tanah', ?, ?, ?, ?, ?, 'aktif', 'pecah_bidang', ?, ST_GeomFromText(?, 4326), ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $kode,
            $asal['nib'],
            $asal['blok_id'],
            $asal['dusun_id'],
            $asal['jenis_penggunaan_id'],
            $luas,
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
}

function geojson_polygon_to_wkt(array $geom): string {
    if ($geom['type'] !== 'Polygon') return '';
    $rings = $geom['coordinates'];
    $ringStrs = [];
    foreach ($rings as $ring) {
        $points = [];
        foreach ($ring as $coord) {
            $points[] = (float) $coord[0] . ' ' . (float) $coord[1];
        }
        $ringStrs[] = '(' . implode(',', $points) . ')';
    }
    return 'POLYGON(' . implode(',', $ringStrs) . ')';
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
