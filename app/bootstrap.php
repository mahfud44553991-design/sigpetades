<?php
declare(strict_types=1);

/**
 * Titik masuk semua berkas PHP aplikasi.
 * Halaman mana pun dimulai dengan:  require __DIR__ . '/app/bootstrap.php';
 */

define('SIP_ROOT', dirname(__DIR__));
define('SIP_APP', __DIR__);

$configPath = SIP_APP . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Konfigurasi belum ada.\n\n"
       . "Salin app/config.example.php menjadi app/config.php lalu isi\n"
       . " kredensial database pada bagian 'db'.\n";
    exit;
}

$sipConfig = require $configPath;
if (!is_array($sipConfig) || !isset($sipConfig['db']['name'])) {
    throw new RuntimeException('app/config.php harus mengembalikan array berisi kunci "db".');
}

$sipDebug = !empty($sipConfig['debug']);
date_default_timezone_set($sipConfig['app']['zona_waktu'] ?? 'Asia/Jakarta');

error_reporting(E_ALL);
ini_set('display_errors', $sipDebug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('display_startup_errors', $sipConfig['debug'] ? '1' : '0');

/** Konfigurasi terparse, hanya dibaca lewat fungsi sip_config(). */
function sip_config(?string $path = null, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require SIP_APP . '/config.php';
    }
    if ($path === null) {
        return $config;
    }
    $node = $config;
    foreach (explode('.', $path) as $segmen) {
        if (!is_array($node) || !array_key_exists($segmen, $node)) {
            return $default;
        }
        $node = $node[$segmen];
    }
    return $node;
}

function sip_debug(): bool
{
    return !empty(sip_config('debug'));
}

/** URL dasar aplikasi, dipakai layout dan redirect. */
function sip_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = (string) sip_config('app.situs_url', '');
    if ($configured !== '') {
        return $base = rtrim($configured, '/');
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $arah = rtrim(dirname($script), '/');

    // Di Windows, dirname('/login.php') menghasilkan "\". Aplikasi di akar
    // dokument root tetap harus memakai prefix kosong.
    return $base = ($arah === '' || $arah === '.' || $arah === '/' || $arah === '\\') ? '' : $arah;
}

function sip_url(string $halaman, array $param = []): string
{
    $url = sip_base_url() . '/' . ltrim($halaman, '/');
    if ($param !== []) {
        $url .= '?' . http_build_query($param);
    }
    return $url;
}

// Dimuat setelah fungsi di atas tersedia, karena db()/mulai_sesi() memakainya.
require_once SIP_APP . '/db.php';
require_once SIP_APP . '/helpers.php';
require_once SIP_APP . '/data.php';
require_once SIP_APP . '/auth.php';
require_once SIP_APP . '/layout.php';

mulai_sesi();

/**
 * Penutup galat terakhir. Tanpa ini, basis data yang mati atau skema yang
 * belum diimport membuat halaman menampilkan error PHP mentah (saat debug)
 * atau layar putih (saat produksi).
 */
set_exception_handler(static function (Throwable $error): void {
    error_log('SIPETADES galat tak tertangani: ' . $error->getMessage()
        . ' di ' . $error->getFile() . ':' . $error->getLine());

    if (sip_minta_json()) {
        json_error(sip_debug() ? $error->getMessage() : 'Layanan data sedang tidak tersedia.', 500);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    $pesan = sip_debug()
        ? e($error->getMessage())
        : 'Sistem sedang tidak tersedia. Pastikan MariaDB XAMPP berjalan dan skema sudah diimport.';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Sistem belum siap</title>
        <link rel="stylesheet" href="<?= e(sip_base_url()) ?>/assets/css/app.css">
    </head>
    <body class="halaman-sederhana">
        <main class="kotak-pusat">
            <p class="eyebrow">SIPETADES Desa Gedong</p>
            <h1>Sistem belum siap dipakai</h1>
            <div class="seru seru-bahaya" role="alert"><?= $pesan ?></div>
            <p class="penjelasan">
                Kalau ini pemasangan baru, jalankan <code>sql/01_skema.sql</code> kemudian
                <code>php tools/impor.php semua</code>.
            </p>
            <div class="baris-tombol">
                <a class="tombol tombol-utama" href="<?= e(sip_url('login.php')) ?>">Ke halaman masuk</a>
            </div>
        </main>
    </body>
    </html>
    <?php
});
