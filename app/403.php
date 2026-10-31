<?php
/** Halaman penolakan akses. Di-include oleh wajib_masuk() lewat halaman_tolak(). */
$pengguna = $pengguna ?? pengguna_aktif();
$halaman = [
    'index.php'   => 'Dasbor',
    'peta.php'    => 'Peta',
    'bidang.php'  => 'Bidang tanah',
    'sppt.php'    => 'e-SPPT / PBB',
    'buku-c.php'  => 'Buku C Desa',
    'peralihan.php' => 'Peralihan hak',
    'laporan.php' => 'Pelaporan wilayah',
    'pengguna.php' => 'Manajemen akun',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akses ditolak — SIPETADES</title>
    <link rel="stylesheet" href="<?= e(sip_base_url()) ?>/assets/css/app.css">
</head>
<body class="halaman-sederhana">
    <main class="kotak-pusat">
        <p class="label-sistem"><i class="ticon"></i>SIPETADES · Desa Gedong</p>
        <h1>Akses ditolak</h1>
        <p class="penjelasan">
            Peran akun Anda (<strong><?= e(peran_label($pengguna['peran'] ?? null)) ?></strong>)
            tidak memiliki izin untuk bagian ini. Hubungi Super Admin bila Anda memang
            membutuhkan akses tersebut.
        </p>
        <div class="baris-tombol">
            <a class="tombol" href="<?= e(sip_url('index.php')) ?>">Ke dasbor</a>
            <a class="tombol tombol-utama" href="<?= e(sip_url('peta.php')) ?>">Buka peta</a>
        </div>
        <p class="catatan-kecil">
            Anda masuk sebagai <strong><?= e($pengguna['nama_lengkap'] ?? '-') ?></strong>
            (<?= e($pengguna['username'] ?? '-') ?>).
            <a href="<?= e(sip_url('logout.php')) ?>">Keluar</a>
        </p>
    </main>
</body>
</html>
