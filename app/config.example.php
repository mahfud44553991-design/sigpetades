<?php
declare(strict_types=1);

/**
 * Contoh konfigurasi. Salin berkas ini menjadi config.php lalu isi
 * kredensial database yang sebenarnya. config.php tidak boleh dibagikan
 * atau diunggah ke repository.
 */

$dbUser = 'root';
$dbPass = '';

$env = static function (string $key, string $fallback) use ($dbUser, $dbPass): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        // Nilai environment selalu menang atas config.php supaya deployment
        // bisa diubah tanpa menyentuh berkas di disk.
        return $fallback;
    }
    return $value;
};

return [
    'debug' => true,                      // false saat dipakai sungguhan

    'db' => [
        'host'    => $env('SIG_DB_HOST', '127.0.0.1'),
        'port'    => (int) $env('SIG_DB_PORT', '3306'),
        'name'    => $env('SIG_DB_NAME', 'sipetades_gedong'),
        'user'    => $env('SIG_DB_USER', $dbUser),
        'pass'    => $env('SIG_DB_PASSWORD', $dbPass),
        'charset' => 'utf8mb4',
    ],

    'app' => [
        'nama'      => 'SIPETADES',
        'subjudul'  => 'Sistem Informasi Pertanahan & PBB Desa Gedong',
        'zona_waktu' => 'Asia/Jakarta',
        'situs_url'  => '',              // kosong = auto-deteksi
    ],

    'session' => [
        'nama'        => 'SIPETADES_SESS',
        'menguang'    => 7200,           // 2 jam tanpa aktivitas = keluar
        'maks_umur'   => 43200,          // 12 jam sejak login, tak peduli aktif
        'gagal_maks'  => 5,              // percobaan login sebelum akun terkunci
        'kunci_detik' => 900,            // 15 menit
    ],

    'path' => [
        // Arsip hasil pindai Buku C. Folder ini dilindungi .htaccess dan
        // hanya bisa dibaca lewat dokumen.php setelah cek izin.
        'arsip_buku_c' => dirname(__DIR__) . '/files/Data C Desa',
        'unggah_laporan' => dirname(__DIR__) . '/storage/laporan',
    ],

    'unggah' => [
        'maks_mb' => 25,
    ],
];
