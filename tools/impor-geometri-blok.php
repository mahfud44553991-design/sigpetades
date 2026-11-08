<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$geojson_file = 'C:/Users/User/Downloads/blok-semua-20261107.geojson';

if (!file_exists($geojson_file)) {
    echo "File tidak ditemukan: $geojson_file\n";
    exit(1);
}

$data = json_decode(file_get_contents($geojson_file), true);

if (!$data || !isset($data['features'])) {
    echo "Gagal membaca GeoJSON\n";
    exit(1);
}

echo "Total fitur: " . count($data['features']) . "\n";

$berhasil = 0;
$gagal = 0;

foreach ($data['features'] as $fitur) {
    $prop = $fitur['properties'];
    $kode = $prop['kode'];
    $geom = $fitur['geometry'];
    
    // Convert GeoJSON geometry to WKT
    $wkt = geojsonToWkt($geom);
    
    if (!$wkt) {
        echo "Gagal konversi geometri untuk $kode\n";
        $gagal++;
        continue;
    }
    
    // Update blok dengan geometri baru
    $result = db_exec(
        'UPDATE blok SET geom = ST_GeomFromText(?), batas_tersedia = ?, batas_perkiraan = ?, sumber_perkiraan = ?, jumlah_bidang_estimasi = ?, diubah_pada = ? WHERE kode = ?',
        [
            $wkt,
            $prop['batas_tersedia'] ? 1 : 0,
            $prop['batas_perkiraan'] ? 1 : 0,
            $prop['sumber_perkiraan'],
            $prop['jumlah_anggota'],
            $prop['diubah_pada'],
            $kode
        ]
    );
    
    if ($result) {
        echo "✓ $kode diperbarui\n";
        $berhasil++;
    } else {
        echo "✗ $kode gagal\n";
        $gagal++;
    }
}

echo "\nSelesai: $berhasil berhasil, $gagal gagal\n";

function geojsonToWkt(array $geom): string {
    $type = $geom['type'];
    $coords = $geom['coordinates'];
    
    if ($type === 'MultiPolygon') {
        $polygons = [];
        foreach ($coords as $polygon) {
            $rings = [];
            foreach ($polygon as $ring) {
                $points = [];
                foreach ($ring as $point) {
                    $points[] = $point[0] . ' ' . $point[1];
                }
                $rings[] = '(' . implode(',', $points) . ')';
            }
            $polygons[] = '(' . implode(',', $rings) . ')';
        }
        return 'MULTIPOLYGON(' . implode(',', $polygons) . ')';
    }
    
    if ($type === 'Polygon') {
        $rings = [];
        foreach ($coords as $ring) {
            $points = [];
            foreach ($ring as $point) {
                $points[] = $point[0] . ' ' . $point[1];
            }
            $rings[] = '(' . implode(',', $points) . ')';
        }
        return 'POLYGON(' . implode(',', $rings) . ')';
    }
    
    return '';
}
