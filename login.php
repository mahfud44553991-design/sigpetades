<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (sudah_masuk()) {
    redirect('index.php');
}

$galat = '';
$siapSetup = false;
try {
    $siapSetup = perlu_setup();
} catch (Throwable $error) {
    $galat = 'Basis data belum siap: ' . $error->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf();
    [$berhasil, $pesan, $pengguna] = coba_masuk(post_str('username'), (string) ($_POST['password'] ?? ''));
    if ($berhasil) {
        $tujuan = (string) ($_SESSION['setelah_login'] ?? '');
        unset($_SESSION['setelah_login']);
        // Hanya boleh ke path relatif situs sendiri, tidak ke URL luar.
        if ($tujuan === '' || !str_starts_with($tujuan, '/') || str_starts_with($tujuan, '//')) {
            $tujuan = sip_url('index.php');
        } else {
            $tujuan = rtrim(sip_base_url(), '/') . $tujuan;
        }
        header('Location: ' . $tujuan);
        exit;
    }
    $galat = $pesan;
}

$base = sip_base_url();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk — <?= e(setelan('nama_sistem', 'SIPETADES')) ?> Desa <?= e(setelan('nama_desa', 'Gedong')) ?></title>
    <link rel="stylesheet" href="<?= e($base) ?>/assets/css/app.css">
</head>
<body class="halaman-sederhana halaman-masuk">
    <main class="kotak-pusat">
        <img src="<?= e($base) ?>/logo-kabupaten-kendal.png" alt="Lambang Kabupaten Kendal" class="lambang">
        <p class="eyebrow">Perangkat Desa <?= e(setelan('nama_desa', 'Gedong')) ?></p>
        <h1><?= e(setelan('nama_sistem', 'SIPETADES')) ?></h1>
        <p class="masuk-tagline">Sistem Informasi Geografis Pertanahan Desa</p>
        <p class="penjelasan">
            Sistem Informasi Pertanahan dan PBB. Masuk dengan akun perangkat desa.
            Daftar laporan dan identitas pelapor hanya dibuka untuk admin dan Super Admin.
        </p>

        <?php if ($galat !== ''): ?>
            <div class="seru seru-bahaya" role="alert"><?= e($galat) ?></div>
        <?php endif; ?>

        <?php if ((string) ($_SESSION['perlu_login'] ?? '') !== ''): ?>
            <div class="seru seru-info"><?= e((string) $_SESSION['perlu_login']) ?></div>
            <?php unset($_SESSION['perlu_login']); ?>
        <?php endif; ?>

        <?php if (($info = flash_get('info')) !== ''): ?>
            <div class="seru seru-info"><?= e($info) ?></div>
        <?php endif; ?>

        <?php if ($siapSetup): ?>
            <div class="seru seru-info">
                Belum ada Super Admin. <a href="<?= e(sip_url('setup.php')) ?>">Buat akun pertama</a> sebelum sistem dipakai.
            </div>
        <?php else: ?>
            <form method="post" action="<?= e(sip_url('login.php')) ?>" autocomplete="on">
                <?= csrf_input() ?>
                <div class="lapangan">
                    <label for="username">Nama pengguna</label>
                    <input id="username" name="username" maxlength="50" required autofocus
                           autocomplete="username" spellcheck="false" value="<?= e(post_str('username')) ?>">
                </div>
                <div class="lapangan">
                    <label for="password">Kata sandi</label>
                    <input id="password" name="password" type="password" required
                           autocomplete="current-password">
                </div>
                <div class="baris-tombol">
                    <button class="tombol tombol-utama" type="submit">Masuk</button>
                </div>
            </form>
            <p class="catatan-kecil">
                Warga melaporkan sengketa lewat petugas kewilayahan, yang mencatatnya
                di menu Pelaporan. Lupa kata sandi? Minta Super Admin membuatkan kata
                sandi baru lewat halaman Manajemen akun.
            </p>
        <?php endif; ?>

        <p class="catatan-kecil"><a href="<?= e(sip_url('beranda.php')) ?>">Kembali ke beranda</a></p>
    </main>
</body>
</html>
