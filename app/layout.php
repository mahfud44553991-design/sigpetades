<?php
declare(strict_types=1);

/**
 * Kerangka bersama semua halaman: <head>, bilah atas, notifikasi, penutup.
 * Setiap halaman memanggil sip_header() lalu sip_footer().
 */

/** Menu utama. Kunci peran membatasi item tertentu. */
function menu_utama(): array
{
    return [
        ['beranda.php',    'Beranda',    'beranda', null],
        ['peta.php',       'Peta',       'peta',    null],
        ['bidang.php',     'Bidang',     'bidang',  null],
        ['sppt.php',       'e-SPPT',     'sppt',    null],
        ['buku-c.php',     'Buku C',     'buku-c',  null],
        ['peralihan.php',  'Peralihan',  'peralihan', null],
        ['laporan.php',    'Laporan',    'laporan', null],
        ['pengaturan.php', 'Pengaturan', 'pengaturan', [PERAN_SUPER]],
        ['pengguna.php',   'Akun',       'pengguna', [PERAN_SUPER]],
    ];
}

/** Penanda versi berkas aset agar peramban tidak memakai CSS/JS lama. */
function sip_versi_aset(string $berkas): string
{
    $jalan = SIP_ROOT . '/' . ltrim($berkas, '/');
    return is_file($jalan) ? '?v=' . filemtime($jalan) : '';
}

function sip_header(string $judul, string $aktif = '', array $opsi = []): void
{
    $pengguna = pengguna_aktif();
    $namaSistem = setelan('nama_sistem', (string) sip_config('app.nama', 'SIPETADES'));
    $base = sip_base_url();
    $leafletCss = $opsi['leaflet'] ?? false
        ? '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">'
        : '';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0f4c81">
    <title><?= e($judul) ?> — <?= e($namaSistem) ?> Desa <?= e(setelan('nama_desa', 'Gedong')) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Caveat:wght@600&display=swap">
    <?= $leafletCss ?>
    <link rel="stylesheet" href="<?= e($base) ?>/assets/css/app.css<?= e(sip_versi_aset('assets/css/app.css')) ?>">
</head>
<body class="halaman-<?= e($aktif ?: 'umum') ?>">
<header class="bilah-atas">
    <div class="wadah">
        <a class="merek" href="<?= e(sip_url('beranda.php')) ?>">
            <img src="<?= e($base) ?>/logo-kabupaten-kendal.png" alt="" class="lambang">
            <span class="merek-teks">
                <strong><?= e($namaSistem) ?></strong>
                <small>Desa <?= e(setelan('nama_desa', 'Gedong')) ?> · Kec. <?= e(setelan('nama_kecamatan', 'Patean')) ?> · <?= e(setelan('nama_kabupaten', 'Kendal')) ?></small>
            </span>
        </a>

        <button class="tombol-menu" type="button" aria-expanded="false" aria-controls="menuUtama" data-buka-menu>
            <span class="garis"></span><span class="garis"></span><span class="garis"></span>
        </button>

        <nav id="menuUtama" class="menu-utama" data-menu>
            <?php foreach (menu_utama() as [$berkas, $label, $kunci, $peranDibutuhkan]):
                if ($peranDibutuhkan !== null
                    && ($pengguna === null || !in_array($pengguna['peran'], $peranDibutuhkan, true))) {
                    continue;
                } ?>
                <a href="<?= e(sip_url($berkas)) ?>" class="menu-<?= e($kunci) ?><?= $kunci === $aktif ? ' aktif' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="kanan-atas">
            <?php if ($pengguna === null): ?>
                <a class="tombol tombol-utama" href="<?= e(sip_url('login.php')) ?>">Masuk</a>
            <?php else: ?>
                <a class="tautan-dasbor<?= $aktif === 'dasbor' ? ' aktif' : '' ?>" href="<?= e(sip_url('index.php')) ?>">Dasbor</a>
                <span class="akun">
                    <span class="inisial"><?= e(mb_strtoupper(mb_substr($pengguna['nama_lengkap'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                    <span class="akun-teks">
                        <strong><?= e($pengguna['nama_lengkap']) ?></strong>
                        <small><?= e(peran_label($pengguna['peran'])) ?></small>
                    </span>
                </span>
                <a class="tombol" href="<?= e(sip_url('logout.php')) ?>">Keluar</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="<?= empty($opsi['penuh']) ? 'wadah ' : '' ?>isi<?= empty($opsi['penuh']) ? '' : ' isi-penuh' ?>">
    <div class="seru-wadah">
    <?php
    $notifikasi = [
        ['info', 'seru-info', (string) ($_SESSION['perlu_login'] ?? '')],
        ['berhasil', 'seru-seru', flash_get('berhasil')],
        ['info', 'seru-info', flash_get('info')],
        ['gagal', 'seru-bahaya', flash_get('gagal')],
    ];
    unset($_SESSION['perlu_login']);

    $tampil = [];
    foreach ($notifikasi as [, $kelas, $pesan]) {
        if ($pesan !== '' && !in_array([$kelas, $pesan], $tampil, true)) {
            $tampil[] = [$kelas, $pesan];
            echo '<div class="seru ' . e($kelas) . '" role="status">' . e($pesan) . '</div>';
        }
    } ?>
    </div>
<?php
}

function sip_footer(array $opsi = []): void
{
    $base = sip_base_url();
    $leafletJs = $opsi['leaflet'] ?? false
        ? '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>'
        : '';
    $konstanta = [
        'base'   => $base,
        'csrf'   => csrf_token(),
        'masuk'  => pengguna_aktif() !== null,
        'kelola' => boleh_kelola(),
        'peta'   => [
            'lat'      => (float) setelan('peta_lat_pusat', '-7.0872'),
            'lon'      => (float) setelan('peta_lon_pusat', '110.0693'),
            'zoom'     => (int) setelan('peta_zoom_awal', '15'),
            'kodeNop'  => setelan('kode_wilayah_nop', '3324040008'),
        ],
        'data'   => $opsi['data'] ?? [],
    ];
    ?>
</main>

<footer class="bilah-bawah">
    <div class="wadah">
        <span>&copy; <?= date('Y') ?> Pemerintah Desa <?= e(setelan('nama_desa', 'Gedong')) ?>
            &middot; <?= e(setelan('nama_sistem', 'SIPETADES')) ?></span>
        <span class="redup"><?= e(number_format((int) (db_value("SELECT COUNT(*) FROM bidang WHERE status NOT IN ('dihapus', 'duplikat')") ?: 0))) ?> bidang terdaftar</span>
    </div>
</footer>

<?= $leafletJs ?>
<script>window.SIP = <?= json_encode($konstanta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= e($base) ?>/assets/js/app.js<?= e(sip_versi_aset('assets/js/app.js')) ?>"></script>
<?= $opsi['kaki'] ?? '' ?>
</body>
</html>
<?php
}

/** Blok judul halaman. */
function sip_judul(string $eyebrow, string $judul, string $deskripsi = ''): void
{
    ?>
    <div class="kepala-halaman">
        <?php if ($eyebrow !== ''): ?><p class="eyebrow"><?= e($eyebrow) ?></p><?php endif; ?>
        <h1><?= e($judul) ?></h1>
        <?php if ($deskripsi !== ''): ?><p class="pengantar"><?= e($deskripsi) ?></p><?php endif; ?>
    </div>
    <?php
}

/**
 * Kartu panel. Isi boleh HTML yang sudah dibangun dengan e() di pemanggil,
 * atau callback yang dipanggil di dalam buffer.
 */
function sip_kartu(string $judul, callable|string $isi, string $kelas = '', string $catatan = ''): void
{
    ?>
    <section class="kartu <?= e($kelas) ?>">
        <?php if ($judul !== ''): ?>
            <header class="kepala-kartu">
                <div>
                    <h2><?= e($judul) ?></h2>
                    <?php if ($catatan !== ''): ?><p><?= e($catatan) ?></p><?php endif; ?>
                </div>
            </header>
        <?php endif; ?>
        <div class="isi-kartu"><?php echo is_string($isi) ? $isi : $isi(); ?></div>
    </section>
    <?php
}
