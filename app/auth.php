<?php
declare(strict_types=1);

/**
 * Autentikasi, otorisasi, dan CSRF.
 * Sesi memakai dua batas waktu: menganggur (idle) dan umur maksimum mutlak,
 * karena komputer desa sering ditinggal dalam keadaan browser terbuka.
 */

const PERAN_SUPER = 'super_admin';
const PERAN_ADMIN = 'admin';
const PERAN_KEWIL = 'kewilayahan';
const PERAN_BACA  = 'baca';

const LABEL_PERAN = [
    PERAN_SUPER => 'Super Admin',
    PERAN_ADMIN => 'Admin',
    PERAN_KEWIL => 'Kewilayahan',
    PERAN_BACA  => 'Pengamat (hanya baca)',
];

/** Peran yang boleh mengubah data pertanahan. */
const PERAN_PENGELOLA = [PERAN_SUPER, PERAN_ADMIN];

function peran_label(?string $peran): string
{
    return LABEL_PERAN[$peran ?? ''] ?? 'Tidak diketahui';
}

function mulai_sesi(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $aman = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';

    session_name((string) sip_config('session.nama', 'SIPETADES_SESS'));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) sip_config('session.menguang', 7200));

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim(sip_base_url(), '/') . '/',
        'domain'   => '',
        'secure'   => $aman,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // Batas waktu sesi. Kedaluwarsa = kosongkan isi sesi tapi pertahankan
    // cookie-nya, supaya pesan "sesi berakhir" masih bisa ditampilkan.
    $menguang = (int) sip_config('session.menguang', 7200);
    $maksUmur = (int) sip_config('session.maks_umur', 43200);
    $sekarang = time();
    $peranSesi = '';

    if (isset($_SESSION['mulai_pada']) && $sekarang - (int) $_SESSION['mulai_pada'] > $maksUmur) {
        $peranSesi = 'Sesi berakhir. Silakan masuk kembali.';
    } elseif (isset($_SESSION['aktivitas_pada']) && $sekarang - (int) $_SESSION['aktivitas_pada'] > $menguang) {
        $peranSesi = 'Sesi berakhir karena terlalu lama tidak digunakan.';
    }

    if ($peranSesi !== '') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['perlu_login'] = $peranSesi;
    }

    if (!isset($_SESSION['mulai_pada'])) {
        $_SESSION['mulai_pada'] = $sekarang;
    }
    $_SESSION['aktivitas_pada'] = $sekarang;
}

function keluar_sesi(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

// ---------------------------------------------------------------------------
// Pemakaian
// ---------------------------------------------------------------------------

function auth_user_id(): ?int
{
    $id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    return $id === false ? null : (int) $id;
}

/**
 * Data pengguna aktif, dibaca dari database dan di-cache per request.
 * Akun yang dinonaktifkan atau dihapus langsung kehilangan akses meski
 * cookies-nya masih ada.
 */
function pengguna_aktif(): ?array
{
    static $cache = null;
    static $sudahCek = false;

    if ($sudahCek) {
        return $cache;
    }
    $sudahCek = true;

    $id = auth_user_id();
    if ($id === null) {
        return $cache = null;
    }

    $baris = db_one(
        'SELECT id, username, nama_lengkap, jabatan, peran, terakhir_login
         FROM pengguna WHERE id = ? AND is_active = 1',
        [$id]
    );

    if ($baris === null) {
        keluar_sesi();
        return $cache = null;
    }

    return $cache = $baris;
}

function sudah_masuk(): bool
{
    return pengguna_aktif() !== null;
}

/** Paksa halaman hanya untuk peran tertentu. */
function wajib_masuk(array $peran = []): array
{
    $pengguna = pengguna_aktif();
    if ($pengguna === null) {
        if (sip_minta_json()) {
            json_error('Masuk terlebih dahulu untuk mengakses data ini.', 401);
        }
        $_SESSION['setelah_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php');
    }

    if ($peran !== [] && !in_array($pengguna['peran'], $peran, true)) {
        if (sip_minta_json()) {
            json_error('Peran akun Anda tidak memiliki izin untuk tindakan ini.', 403);
        }
        http_response_code(403);
        halaman_tolak($pengguna);
    }

    return $pengguna;
}

function boleh_kelola(): bool
{
    $pengguna = pengguna_aktif();
    return $pengguna !== null && in_array($pengguna['peran'], PERAN_PENGELOLA, true);
}

function boleh_baca_pribadi(): bool
{
    return pengguna_aktif() !== null;
}

function sip_minta_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json')) {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        return true;
    }
    $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_contains($path, '/api/');
}

// ---------------------------------------------------------------------------
// Masuk & keluar
// ---------------------------------------------------------------------------

/**
 * Hash bcrypt yang valid tapi tidak pernah cocok dengan sandi apa pun.
 * Dipakai untuk menyamakan waktu respons saat nama pengguna tidak dikenal.
 */
function hash_palsu(): string
{
    static $hash = null;
    return $hash ??= password_hash('sipetaudes-penanda-waktu', PASSWORD_DEFAULT);
}

/**
 * Verifikasi kredensial dengan penguncian akun otomatis.
 * @return array{0: bool, 1: string, 2: ?array} sukses, pesan, data pengguna
 */
function coba_masuk(string $username, string $password): array
{
    $maksGagal = (int) sip_config('session.gagal_maks', 5);
    $kunciDetik = (int) sip_config('session.kunci_detik', 900);

    $pengguna = db_one(
        'SELECT id, username, password_hash, nama_lengkap, jabatan, peran, is_active,
                gagal_login, terkunci_sampai
         FROM pengguna WHERE username = ? LIMIT 1',
        [$username]
    );

    // Waktu respons dibuat merata untuk akun yang ada maupun tidak ada,
    // supaya jumlah percobaan tidak bisa disimpulkan dari lama respons.
    if ($pengguna === null) {
        password_verify($password, hash_palsu());
        audit('login_gagal', 'pengguna', $username, 'Nama pengguna tidak dikenal', null, null);
        return [false, 'Nama pengguna atau kata sandi salah.', null];
    }

    if ((int) $pengguna['is_active'] !== 1) {
        audit('login_gagal', 'pengguna', (string) $pengguna['id'], 'Akun nonaktif', null, null);
        return [false, 'Akun Anda dinonaktifkan. Hubungi Super Admin.', null];
    }

    if ($pengguna['terkunci_sampai'] !== null
        && strtotime((string) $pengguna['terkunci_sampai']) > time()) {
        $sisa = (int) ceil((strtotime((string) $pengguna['terkunci_sampai']) - time()) / 60);
        return [false, "Terlalu banyak percobaan gagal. Coba lagi dalam {$sisa} menit.", null];
    }

    if (!password_verify($password, (string) $pengguna['password_hash'])) {
        $baru = (int) $pengguna['gagal_login'] + 1;
        $terkunci = null;
        if ($baru >= $maksGagal) {
            $terkunci = date('Y-m-d H:i:s', time() + $kunciDetik);
            $baru = 0;
        }
        db_exec('UPDATE pengguna SET gagal_login = ?, terkunci_sampai = ? WHERE id = ?',
            [$baru, $terkunci, $pengguna['id']]);
        audit('login_gagal', 'pengguna', (string) $pengguna['id'], 'Kata sandi salah', null, null);

        if ($terkunci !== null) {
            return [false, 'Akun dikunci sementara selama 15 menit karena terlalu banyak percobaan gagal.', null];
        }
        $sisa = $maksGagal - $baru;
        return [false, "Nama pengguna atau kata sandi salah. Sisa {$sisa} percobaan sebelum akun dikunci.", null];
    }

    if (password_needs_rehash((string) $pengguna['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE pengguna SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $pengguna['id']]);
    }

    masuk($pengguna);
    return [true, '', $pengguna];
}

function masuk(array $pengguna): void
{
    mulai_sesi();
    session_regenerate_id(true);
    $_SESSION = [
        'user_id'      => (int) $pengguna['id'],
        'mulai_pada'   => time(),
        'aktivitas_pada' => time(),
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    db_exec('UPDATE pengguna SET gagal_login = 0, terkunci_sampai = NULL, terakhir_login = NOW() WHERE id = ?',
        [$pengguna['id']]);
    audit('login', 'pengguna', (string) $pengguna['id'], 'Berhasil masuk sebagai ' . peran_label($pengguna['peran']));
}

function keluar(): void
{
    $id = auth_user_id();
    if ($id !== null) {
        audit('logout', 'pengguna', (string) $id);
    }
    keluar_sesi();
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    mulai_sesi();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Panggil di awal setiap handler POST/PUT/DELETE. */
function wajib_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!csrf_valid(is_string($token) ? $token : null)) {
        if (sip_minta_json()) {
            json_error('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.', 403);
        }
        http_response_code(403);
        exit('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

// ---------------------------------------------------------------------------
// Pengaturan awal
// ---------------------------------------------------------------------------

function perlu_setup(): bool
{
    static $hasil = null;
    if ($hasil !== null) {
        return $hasil;
    }
    try {
        $hasil = (int) db_value("SELECT COUNT(*) FROM pengguna WHERE peran = 'super_admin'") === 0;
    } catch (Throwable $error) {
        error_log('SIPETADES cek setup gagal: ' . $error->getMessage());
        throw $error;
    }
    return $hasil;
}

/**
 * Super Admin pertama kali dibuat lewat setup.php. Password min. 12 karakter.
 * @return array data pengguna baru
 */
function buat_super_admin(string $nama, string $username, string $password): array
{
    if (len_utf8($nama) < 3 || len_utf8($nama) > 100) {
        throw new RuntimeException('Nama lengkap wajib diisi 3–100 karakter.');
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
        throw new RuntimeException('Nama pengguna harus 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
    }
    if (mb_strlen($password, 'UTF-8') < 12) {
        throw new RuntimeException('Kata sandi minimal 12 karakter.');
    }

    $pdo = db();
    $terkunci = (int) $pdo->query("SELECT GET_LOCK('sipetaudes_setup', 10)")->fetchColumn() === 1;
    if (!$terkunci) {
        throw new RuntimeException('Pengaturan akun sedang dipakai proses lain. Coba lagi sebentar.');
    }

    try {
        return db_transaksi(static function (PDO $pdo) use ($nama, $username, $password): array {
            $jumlah = (int) $pdo->query("SELECT COUNT(*) FROM pengguna WHERE peran = 'super_admin'")->fetchColumn();
            if ($jumlah > 0) {
                throw new RuntimeException('Super Admin sudah ada. Halaman pengaturan ini sudah terkunci.');
            }
            $statement = $pdo->prepare(
                'INSERT INTO pengguna (username, password_hash, nama_lengkap, jabatan, peran, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)'
            );
            $statement->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $nama,
                peran_label(PERAN_SUPER),
                PERAN_SUPER,
            ]);

            return [
                'id'           => (int) $pdo->lastInsertId(),
                'username'     => $username,
                'nama_lengkap' => $nama,
                'peran'        => PERAN_SUPER,
                'jabatan'      => peran_label(PERAN_SUPER),
            ];
        });
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('sipetaudes_setup')");
    }
}

function halaman_tolak(array $pengguna): never
{
    http_response_code(403);
    mulai_sesi();
    include SIP_APP . '/403.php';
    exit;
}
