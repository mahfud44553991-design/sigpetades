<?php
declare(strict_types=1);

/**
 * Router untuk server bawaan PHP saat pengembangan.
 *
 *   php -S 127.0.0.1:8099 tools/server.php
 *
 * Server bawaan mengabaikan .htaccess, jadi folder data desa dan kode internal
 * ditutup di sini supaya perilaku saat dev sama dengan Apache/XAMPP.
 */

$root = dirname(__DIR__);

$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$rel = ltrim(rawurldecode(str_replace('\\', '/', $uri)), '/');
$awal = explode('/', $rel)[0] ?? '';

/** Imbangi RedirectMatch pada .htaccess root. */
const TERTUTUP = ['app', 'sql', 'tools', 'storage', 'files', '_lama'];

/** Imbangi <FilesMatch> pada .htaccess root. */
const EKSTENSI_TERTUTUP = [
    'sql', 'md', 'txt', 'log', 'ini', 'conf', 'json', 'geojson',
    'csv', 'tsv', 'bak', 'old', 'dist', 'example', 'lock',
];

function tolak(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Tidak ditemukan.';
}

if ($rel === '' || $rel === '/') {
    require $root . '/beranda.php';
    return;
}

if (in_array(strtolower($awal), TERTUTUP, true)
    || preg_match('/(^|\/)\.(env|git|gitignore|htaccess|htpasswd)/i', $rel)) {
    tolak();
    return;
}

$ekstensi = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
if (in_array($ekstensi, EKSTENSI_TERTUTUP, true)) {
    tolak();
    return;
}

$berkas = $root . '/' . $rel;
if (is_file($berkas)) {
    return false;
}

tolak();
