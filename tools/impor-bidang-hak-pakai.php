<?php
declare(strict_types=1);

require_once __DIR__ . '/lib-impor.php';

$geojsonFile = 'C:/Users/User/Downloads/bidang-hak-pakai-20261107.geojson';

echo "Membaca file GeoJSON...\n";
$geojson = json_decode(file_get_contents($geojsonFile), true);

if (!$geojson || !isset($geojson['features'])) {
    die("ERROR: File GeoJSON tidak valid\n");
}

$features = $geojson['features'];
echo "Ditemukan " . count($features) . " bidang untuk diimpor\n\n";

// Mapping blok nama → id
$blokMap = [
    'BLOK 4' => 2,
    'BLOK 10' => 17,
    'BLOK 13' => 12,
];

// Mapping penggunaan → jenis_penggunaan_id
$jenisPenggunaanMap = [
    'Fasilitas Umum' => 7,
];

$pdo = db();

$berhasil = 0;
$gagal = 0;
$dilewati = 0;

foreach ($features as $feature) {
    $props = $feature['properties'];
    $geom = $feature['geometry'];
    
    $id = $props['id'];
    $kode = $props['kode'];
    $jenisHak = $props['jenis_hak'];
    $kodeHak = $props['kode_hak'];
    $blokNama = $props['blok'];
    $penggunaan = $props['penggunaan'];
    $luasM2 = $props['luas_m2'];
    $luasUkurM2 = $props['luas_ukur_m2'];
    $status = $props['status'];
    $catatan = $props['catatan'];
    
    echo "Proses ID {$id} ({$kode})...\n";
    
    // Cek duplikat
    $stmt = $pdo->prepare("SELECT id FROM bidang WHERE kode = ?");
    $stmt->execute([$kode]);
    if ($stmt->fetch()) {
        echo "  → Dilewati: kode sudah ada\n\n";
        $dilewati++;
        continue;
    }
    
    // Mapping blok
    $blokId = $blokMap[$blokNama] ?? null;
    if ($blokId === null) {
        echo "  → ERROR: Blok '{$blokNama}' tidak ditemukan di mapping\n\n";
        $gagal++;
        continue;
    }
    
    // Mapping jenis penggunaan
    $jenisPenggunaanId = null;
    if ($penggunaan && isset($jenisPenggunaanMap[$penggunaan])) {
        $jenisPenggunaanId = $jenisPenggunaanMap[$penggunaan];
    }
    
    // Bangun WKT dari Polygon
    if ($geom['type'] !== 'Polygon') {
        echo "  → ERROR: Tipe geometri bukan Polygon\n\n";
        $gagal++;
        continue;
    }
    
    $rings = $geom['coordinates'];
    $wktRings = [];
    foreach ($rings as $ring) {
        $wktPoints = [];
        foreach ($ring as $point) {
            $lon = $point[0];
            $lat = $point[1];
            $wktPoints[] = "{$lon} {$lat}";
        }
        $wktRings[] = "(" . implode(", ", $wktPoints) . ")";
    }
    $wkt = "POLYGON(" . implode(", ", $wktRings) . ")";
    
    // Hitung pusat (centroid sederhana dari first point)
    $pusatLon = $geom['coordinates'][0][0][0];
    $pusatLat = $geom['coordinates'][0][0][1];
    
    // Tentukan tipe: Hak Pakai → 'lain'
    $tipe = 'lain';
    
    // Insert
    $sql = "INSERT INTO bidang (
        kode, tipe, blok_id, jenis_penggunaan_id, luas_m2, status, 
                sumber, catatan, geom, pusat_lon, pusat_lat
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $kode,
        $tipe,
        $blokId,
        $jenisPenggunaanId,
        $luasUkurM2,
        $status,
        'impor-hak-pakai-20261107',
        $catatan,
        $wkt,
        $pusatLon,
        $pusatLat,
    ]);
    
    echo "  → Berhasil: blok_id={$blokId}, luas={$luasUkurM2} m², status={$status}\n\n";
    $berhasil++;
}

echo "========================================\n";
echo "Selesai!\n";
echo "  Berhasil : {$berhasil}\n";
echo "  Gagal    : {$gagal}\n";
echo "  Dilewati : {$dilewati}\n";
echo "========================================\n";
