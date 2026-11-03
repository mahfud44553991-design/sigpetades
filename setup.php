<?php
declare(strict_types=1);

/**
 * Pembuatan Super Admin pertama. Halaman ini menutup dirinya begitu akun
 * Super Admin sudah ada, dan tidak pernah menampilkan kredensial contoh.
 */

require __DIR__ . '/app/bootstrap.php';

$galat = '';
$tetutup = false;

try {
    if (!perlu_setup()) {
        $tetutup = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        wajib_csrf();
        $nama = post_str('nama_lengkap');
        $username = post_str('username');
        $password = (string) ($_POST['password'] ?? '');
        $ulangi = (string) ($_POST['password_ulangi'] ?? '');

        if ($password !== $ulangi) {
            $galat = 'Konfirmasi kata sandi tidak sama.';
        } else {
            $pengguna = buat_super_admin($nama, $username, $password);
            masuk($pengguna);
            audit('buat', 'pengguna', (string) $pengguna['id'], 'Super Admin pertama dibuat');
            flash_set('berhasil', 'Akun Super Admin dibuat. Silakan lanjutkan dengan mengimpor data desa.');
            redirect('beranda.php');
        }
    }
} catch (RuntimeException $error) {
    $galat = $error->getMessage();
} catch (Throwable $error) {
    error_log('SIPETADES setup gagal: ' . $error->getMessage());
    $galat = sip_debug()
        ? 'Pengaturan awal gagal: ' . $error->getMessage()
        : 'Pengaturan awal gagal. Periksa log PHP dan koneksi database.';
}

$base = sip_base_url();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pengaturan awal — SIPETADES</title>
    <link rel="stylesheet" href="<?= e($base) ?>/assets/css/app.css">
</head>
<body class="halaman-sederhana">
    <main class="kotak-pusat">
        <img src="<?= e($base) ?>/logo-kabupaten-kendal.png" alt="Lambang Kabupaten Kendal" class="lambang">
        <p class="eyebrow">Pengaturan awal</p>
        <h1>Buat akun Super Admin</h1>

        <?php if ($tetutup): ?>
            <div class="seru seru-info">
                Super Admin sudah ada, jadi halaman ini sudah tertutup.
                <a href="<?= e(sip_url('login.php')) ?>">Masuk ke sistem</a>.
            </div>
        <?php else: ?>
            <p class="penjelasan">
                Isi dengan nama perangkat desa yang bertanggung jawab atas sistem ini.
                Gunakan kata sandi minimal 12 karakter yang belum dipakai di tempat lain.
            </p>

            <?php if ($galat !== '' && !$tetutup): ?>
                <div class="seru seru-bahaya" role="alert"><?= e($galat) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= e(sip_url('setup.php')) ?>">
                <?= csrf_input() ?>
                <div class="lapangan">
                    <label for="nama_lengkap">Nama lengkap</label>
                    <input id="nama_lengkap" name="nama_lengkap" maxlength="100" required autocomplete="name">
                </div>
                <div class="lapangan">
                    <label for="username">Nama pengguna</label>
                    <input id="username" name="username" maxlength="50" required
                           pattern="[A-Za-z0-9._-]{3,50}" autocomplete="username" spellcheck="false">
                </div>
                <div class="lapangan">
                    <label for="password">Kata sandi (minimal 12 karakter)</label>
                    <input id="password" name="password" type="password" required minlength="12"
                           autocomplete="new-password">
                </div>
                <div class="lapangan">
                    <label for="password_ulangi">Ulangi kata sandi</label>
                    <input id="password_ulangi" name="password_ulangi" type="password" required minlength="12"
                           autocomplete="new-password">
                </div>
                <div class="baris-tombol">
                    <button class="tombol tombol-utama" type="submit">Buat Super Admin</button>
                </div>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
