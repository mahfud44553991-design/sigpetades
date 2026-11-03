<?php
declare(strict_types=1);

/**
 * Assign dusun ke blok dan bidang berdasarkan lokasi.
 *
 *   php tools/assign-dusun.php --lihat
 *   php tools/assign-dusun.php --terapkan
 *
 * Alur:
 *   1. Mapping blok ke dusun (hardcoded berdasarkan pengetahuan lokal)
 *   2. Update blok.dusun_id
 *   3. Update bidang.dusun_id dari blok induknya
 *
 * Mapping blok ke dusun (sesuaikan dengan kondisi lapangan):
 *   BLOK 16, 17, 18, 25, 26 → DAKAH
 *   BLOK 20, 21, 23 → ROWOGANDU
 *   BLOK 2, 3 → KALIPURU
 *   BLOK 8, 15 → GEDONG
 *   BLOK 12, 13, 14 → SABRANG
 *   BLOK 6, 7 → KANDANG
 *   BLOK 4, 5 → TEMPURAN
 *   BLOK 9, 10 → KREYO
 *   BLOK 19, 24 → NGRANDU
 *   BLOK lainnya → NULL (perlu verifikasi manual)
 */

require_once __DIR__ . '/../app/bootstrap.php';

$terapkan = in_array('--terapkan', $argv, true);
$lihat = in_array('--lihat', $argv, true) || !$terapkan;

if (!$terapkan && !$lihat) {
    fwrite(STDERR, "Gunakan --lihat atau --terapkan\n");
    exit(1);
}

// Mapping blok ke dusun (nama dusun)
$mappingBlokDusun = [
    // DAKAH
    'BLOK 16' => 'DAKAH',
    'BLOK 17' => 'DAKAH',
    'BLOK 18' => 'DAKAH',
    'BLOK 25' => 'DAKAH',
    'BLOK 26' => 'DAKAH',
    // ROWOGANDU
    'BLOK 20' => 'ROWOGANDU',
    'BLOK 21' => 'ROWOGANDU',
    'BLOK 23' => 'ROWOGANDU',
    // KALIPURU
    'BLOK 2' => 'KALIPURU',
    'BLOK 3' => 'KALIPURU',
    // GEDONG
    'BLOK 8' => 'GEDONG',
    'BLOK 15' => 'GEDONG',
    // SABRANG
    'BLOK 12' => 'SABRANG',
    'BLOK 13' => 'SABRANG',
    'BLOK 14' => 'SABRANG',
    // KANDANG
    'BLOK 6' => 'KANDANG',
    'BLOK 7' => 'KANDANG',
    // TEMPURAN
    'BLOK 4' => 'TEMPURAN',
    'BLOK 5' => 'TEMPURAN',
    // KREYO
    'BLOK 9' => 'KREYO',
    'BLOK 10' => 'KREYO',
    // NGRANDU
    'BLOK 19' => 'NGRANDU',
    'BLOK 24' => 'NGRANDU',
];

// Ambil ID dusun dari nama
$dusunMap = [];
$r = db_all("SELECT id, nama FROM dusun");
foreach ($r as $row) {
    $dusunMap[$row['nama']] = (int) $row['id'];
}

echo "=== Mapping Blok ke Dusun ===\n\n";
printf("  %-10s  %-15s  %s\n", 'Blok', 'Dusun', 'Dusun ID');
printf("  %-10s  %-15s  %s\n", str_repeat('-', 10), str_repeat('-', 15), str_repeat('-', 8));

foreach ($mappingBlokDusun as $blok => $dusun) {
    $dusunId = $dusunMap[$dusun] ?? null;
    printf("  %-10s  %-15s  %s\n", $blok, $dusun, $dusunId ?? 'TIDAK DITEMUKAN');
}

// Blok tanpa mapping
$r = db_all("SELECT kode FROM blok WHERE kode NOT IN (" . implode(',', array_fill(0, count(array_keys($mappingBlokDusun)), '?')) . ")", array_keys($mappingBlokDusun));
echo "\nBlok tanpa mapping dusun:\n";
foreach ($r as $row) {
    echo "  " . $row['kode'] . "\n";
}

if ($terapkan) {
    echo "\n=== Menerapkan mapping ===\n\n";
    
    // Update blok.dusun_id
    $blokUpdated = 0;
    foreach ($mappingBlokDusun as $blok => $dusun) {
        $dusunId = $dusunMap[$dusun] ?? null;
        if ($dusunId === null) {
            echo "  SKIP $blok: dusun '$dusun' tidak ditemukan\n";
            continue;
        }
        $stmt = db_query("UPDATE blok SET dusun_id = ? WHERE kode = ?", [$dusunId, $blok]);
        $blokUpdated += $stmt->rowCount();
        echo "  $blok → $dusun (id=$dusunId)\n";
    }
    echo "\nBlok diperbarui: $blokUpdated\n";
    
    // Update bidang.dusun_id dari blok induk
    $bidangUpdated = db_query("
        UPDATE bidang bd
        INNER JOIN blok b ON bd.blok_id = b.id
        SET bd.dusun_id = b.dusun_id
        WHERE bd.dusun_id IS NULL AND b.dusun_id IS NOT NULL
    ")->rowCount();
    echo "Bidang diperbarui: $bidangUpdated\n";
    
    // Verifikasi
    echo "\n=== Verifikasi ===\n\n";
    $r = db_all("
        SELECT d.nama as dusun, COUNT(bd.id) as jumlah, SUM(bd.luas_m2) as total_luas
        FROM dusun d
        LEFT JOIN bidang bd ON bd.dusun_id = d.id AND bd.status='aktif'
        GROUP BY d.id, d.nama
        ORDER BY d.urutan
    ");
    printf("  %-15s  %5s  %12s\n", 'Dusun', 'Jml', 'Total (m2)');
    printf("  %-15s  %5s  %12s\n", str_repeat('-', 15), str_repeat('-', 5), str_repeat('-', 12));
    $totalJumlah = 0;
    $totalLuas = 0;
    foreach ($r as $row) {
        $jumlah = (int) $row['jumlah'];
        $luas = (float) $row['total_luas'];
        $totalJumlah += $jumlah;
        $totalLuas += $luas;
        if ($jumlah > 0) {
            printf("  %-15s  %5d  %11.2f\n", $row['dusun'], $jumlah, $luas);
        }
    }
    printf("  %-15s  %5s  %12s\n", str_repeat('-', 15), str_repeat('-', 5), str_repeat('-', 12));
    printf("  %-15s  %5d  %11.2f\n", 'TOTAL', $totalJumlah, $totalLuas);
    
    $r = db_all("SELECT COUNT(*) as jumlah FROM bidang WHERE status='aktif' AND dusun_id IS NULL");
    echo "\nBidang aktif tanpa dusun: " . $r[0]['jumlah'] . "\n";
} else {
    echo "\nGunakan --terapkan untuk menerapkan mapping.\n";
}
