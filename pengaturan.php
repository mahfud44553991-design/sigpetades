<?php
declare(strict_types=1);

/**
 * Pengaturan data — hanya Super Admin.
 *
 *   GET  ?unduh=<nama>          unduh berkas cadangan .sql
 *   GET  ?unduh-ekspor=<nama>   unduh berkas ekspor .json
 *   POST aksi=cadangan|ekspor|hapus-cadangan|pulihkan|impor-uji|impor-terapkan|
 *        kosongkan-uji|kosongkan-terapkan
 *
 * Semua tindakan yang merusak berjalan dua langkah: uji-kering lebih dulu, hasilnya
 * disimpan di sesi, lalu langkah kedua memakainya. Tidak ada satu pun yang bisa
 * dipanggil sekali klik. Sebelum menulis apa pun, sistem membuat cadangan .sql dan
 * berhenti kalau cadangan itu gagal.
 */

require __DIR__ . '/app/bootstrap.php';
require_once SIP_APP . '/lib-pengaturan.php';

$pengguna = wajib_masuk([PERAN_SUPER]);

/** Kata kunci yang harus diketik manual untuk tindakan yang menghapus data. */
const KATA_KUNCI = 'HAPUS';
const KATA_PULIHKAN = 'PULIHKAN';

/**
 * Unduhan ditangani sebelum ada satu byte pun keluar, supaya header tidak gagal.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && (get_str('unduh') !== '' || get_str('unduh-ekspor') !== '')) {
    try {
        if (get_str('unduh') !== '') {
            wajib_csrf_ambil(get_str('csrf'));
            pengaturan_unduh(pengaturan_aman(pengaturan_folder('cadangan'), get_str('unduh'), 'sql'));
        }
        wajib_csrf_ambil(get_str('csrf'));
        pengaturan_unduh(
            pengaturan_aman(pengaturan_folder('ekspor'), get_str('unduh-ekspor'), 'json'),
            'application/json'
        );
    } catch (Throwable $error) {
        http_response_code(404);
        exit('Berkas tidak ditemukan.');
    }
}

/** Token CSRF ikut di query string unduhan, jadi tidak bisa dipancing dari situs lain. */
function wajib_csrf_ambil(?string $token): void
{
    if (!csrf_valid($token)) {
        throw new RuntimeException('Tautan unduhan sudah kedaluwarsa.');
    }
}

$isiBaru = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wajib_csrf();
    $aksi = post_str('aksi');

    try {
        if ($aksi === 'cadangan') {
            $hasil = cadangan_buat('cadangan');
            audit('cadangan', 'basis_data', 'seluruh',
                'Cadangan dibuat dari halaman Pengaturan', null,
                ['nama' => $hasil['nama'], 'byte' => $hasil['byte'], 'tabel' => $hasil['tabel']]);
            flash_set('berhasil', 'Cadangan ' . $hasil['nama'] . ' (' . ukuran_berkas($hasil['byte']) . ') sudah dibuat.');
            redirect('pengaturan.php');
        }

        if ($aksi === 'hapus-cadangan') {
            $path = pengaturan_aman(pengaturan_folder('cadangan'), post_str('nama'), 'sql');
            if (post_str('konfirmasi') !== 'HAPUS BERKAS') {
                throw new RuntimeException('Ketik "HAPUS BERKAS" untuk membuang satu berkas cadangan.');
            }
            audit('hapus', 'cadangan', basename($path), 'Berkas cadangan dibuang dari halaman Pengaturan',
                ['byte' => (int) filesize($path)], null);
            if (!unlink($path)) {
                throw new RuntimeException('Berkas gagal dibuang: ' . basename($path));
            }
            flash_set('berhasil', 'Berkas cadangan dibuang.');
            redirect('pengaturan.php');
        }

        if ($aksi === 'pulihkan') {
            if (post_str('konfirmasi') !== KATA_PULIHKAN) {
                throw new RuntimeException('Ketik "' . KATA_PULIHKAN . '" untuk menimpa seluruh isi basis data dengan berkas cadangan.');
            }
            $sebelum = cadangan_buat('sebelum-pulihkan');
            $hasil = cadangan_pulihkan(post_str('nama'));
            audit('pulihkan', 'basis_data', 'seluruh',
                'Basis data ditimpa dari berkas cadangan ' . $hasil['nama'],
                ['cadangan_penyelamat' => $sebelum['nama']],
                ['pemulih' => $sebelum['nama'], 'byte' => $hasil['byte']]);
            flash_set('berhasil', 'Basis data dipulihkan dari ' . $hasil['nama']
                . '. Cadangan sebelum pemulihan tersimpan sebagai ' . $sebelum['nama'] . '.');
            redirect('pengaturan.php');
        }

        if ($aksi === 'ekspor') {
            $hasil = ekspor_buat();
            audit('ekspor', 'basis_data', 'seluruh', 'Ekspor JSON dibuat dari halaman Pengaturan',
                null, ['nama' => $hasil['nama'], 'byte' => $hasil['byte'], 'tabel' => count($hasil['tabel'])]);
            flash_set('berhasil', 'Ekspor ' . $hasil['nama'] . ' (' . ukuran_berkas($hasil['byte'])
                . ') siap diunduh.');
            redirect('pengaturan.php');
        }

        if ($aksi === 'impor-uji') {
            if (!isset($_FILES['berkas']) || (int) ($_FILES['berkas']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Berkas belum diunggah, atau ukurannya melebihi batas ' . ini_get('upload_max_filesize') . '.');
            }
            $simpan = impor_simpan((string) $_FILES['berkas']['tmp_name'], (string) $_FILES['berkas']['name']);
            $bacaan = impor_baca($simpan['path']);
            $_SESSION['impor_siap'] = ['nama' => $simpan['nama'], 'path' => $simpan['path']];
            $isiBaru = ['jenis' => 'impor', 'nama' => $simpan['nama'], 'bacaan' => $bacaan];
        }

        if ($aksi === 'impor-terapkan') {
            $siap = $_SESSION['impor_siap'] ?? null;
            if (!is_array($siap) || !is_file((string) ($siap['path'] ?? ''))) {
                throw new RuntimeException('Uji-kering impor sudah tidak berlaku. Unggah ulang berkasnya.');
            }
            if (post_str('konfirmasi') !== KATA_PULIHKAN) {
                throw new RuntimeException('Ketik "' . KATA_PULIHKAN . '" untuk menimpa basis data dengan isi berkas.');
            }
            $sebelum = cadangan_buat('sebelum-impor');
            $tertulih = impor_terapkan((string) $siap['path']);
            unset($_SESSION['impor_siap']);
            audit('impor', 'basis_data', 'seluruh',
                'Basis data ditimpa dari berkas impor ' . $siap['nama'],
                ['cadangan_penyelamat' => $sebelum['nama']], $tertulih);
            flash_set('berhasil', count($tertulih) . ' tabel ditulih ulang. Keadaan sebelum impor tersimpan di '
                . $sebelum['nama'] . '.');
            redirect('pengaturan.php');
        }

        if ($aksi === 'kosongkan-uji') {
            $grup = array_values(array_filter((array) ($_POST['grup'] ?? []),
                static fn($k): bool => is_string($k) && isset(KELOMPOK_KOSONG[$k])));
            if ($grup === []) {
                throw new RuntimeException('Centang minimal satu kelompok data.');
            }
            if (post_str('konfirmasi') !== KATA_KUNCI) {
                throw new RuntimeException('Ketik "' . KATA_KUNCI . '" untuk melihat daftar baris yang akan dihapus.');
            }
            $_SESSION['kosong_siap'] = $grup;
            $isiBaru = ['jenis' => 'kosong', 'grup' => $grup, 'hitung' => kosongkan_hitung($grup)];
        }

        if ($aksi === 'kosongkan-terapkan') {
            $grup = array_values(array_filter((array) ($_SESSION['kosong_siap'] ?? []),
                static fn($k): bool => is_string($k) && isset(KELOMPOK_KOSONG[$k])));
            if ($grup === []) {
                throw new RuntimeException('Uji-kering pengosongan sudah tidak berlaku. Centang ulang kelompoknya.');
            }
            if (post_str('konfirmasi') !== KATA_KUNCI) {
                throw new RuntimeException('Ketik "' . KATA_KUNCI . '" untuk menghapus data kelompok terpilih.');
            }
            $sebelum = kosongkan_sebelum();
            $terhapus = kosongkan_jalankan($grup, $sebelum['path']);
            unset($_SESSION['kosong_siap']);
            audit('kosongkan', 'basis_data', implode(',', $grup),
                'Pengosongan data dari halaman Pengaturan; cadangan dibuat lebih dulu',
                ['cadangan' => $sebelum['nama'], 'tabel' => kosongkan_hitung($grup)], $terhapus);
            $jumlah = array_sum($terhapus);
            flash_set('berhasil', $jumlah . ' baris dihapus dari ' . count($terhapus) . ' tabel. '
                . 'Sebelum dihapus, keadaannya dicadangkan sebagai ' . $sebelum['nama'] . '.');
            redirect('pengaturan.php');
        }

        // Dua aksi uji-kering tidak memanggil redirect(); mereka mengisi $isiBaru
        // lalu jatuh ke sini. Tanpa penjagaan ini, hasilnya selalu didampangi
        // peringatan "aksi tidak dikenal".
        if ($isiBaru === []) {
            throw new RuntimeException('Aksi tidak dikenal: ' . $aksi);
        }
    } catch (Throwable $error) {
        if ($aksi === 'kosongkan-uji' || $aksi === 'impor-uji') {
            unset($_SESSION['kosong_siap'], $_SESSION['impor_siap']);
        }
        flash_set('gagal', $error->getMessage());
        if ($isiBaru === []) {
            redirect('pengaturan.php');
        }
    }
}

/** Cadangan wajib sebelum pengosongan; namanya dipakai sebagai bukti di langkah kedua. */
function kosongkan_sebelum(): array
{
    return cadangan_buat('sebelum-kosongkan');
}

// ---------------------------------------------------------------------------
// Bahan tampilan
// ---------------------------------------------------------------------------
$ringkas = pengaturan_ringkasan();
$cadangan = cadangan_daftar();
$ekspor = ekspor_daftar();
$tabelSistem = pengaturan_tabel_basis();
$jumlahTabel = [];
foreach ($tabelSistem as $nama) {
    $jumlahTabel[$nama] = pengaturan_jumlah($nama);
}
$siapImpor = $isiBaru['jenis'] ?? '';

sip_header('Pengaturan data', 'pengaturan');
sip_judul('Super Admin', 'Pengaturan data',
    'Cadangkan, pindahkan, dan kosongkan isi basis data. Empat kartu di bawah ini satu-satunya '
    . 'tempat sistem boleh mengubah seluruh tabel sekaligus, jadi setiap tindakannya diuji dulu '
    . 'sebelum ditulis dan selalu didahului cadangan.');
?>

<div class="pengaturan-grid">
<?php
// --------------------------------------------------------------- 1. cadangan
sip_kartu('Cadangan basis data', static function () use ($cadangan): void {
    ?>
    <p class="pengantar">Mencadangkan seluruh basis data ke <code>storage/cadangan/</code> memakai
        <code>mysqldump</code>. Geometri ikut tercakup, jadi satu berkas ini sudah cukup untuk
        memindahkan desa ke komputer lain.</p>
    <div class="seru seru-info">Berkas cadangan ditulis ke disk yang sama dengan basis data, jadi ia
        tidak melindungi dari cakram yang mati. Unduh berkas terbaru lewat tautan di bawah dan simpan
        salinannya di tempat lain — flashdisk, komputer lain, atau surel perangkat desa.</div>
    <form method="post" action="<?= e(sip_url('pengaturan.php')) ?>" class="baris-tombol">
        <?= csrf_input() ?>
        <input type="hidden" name="aksi" value="cadangan">
        <button class="tombol tombol-utama" type="submit">Buat cadangan sekarang</button>
    </form>

    <?php if ($cadangan === []): ?>
        <p class="catatan-kecil">Belum ada berkas cadangan.</p>
    <?php else: ?>
        <table class="rapat">
            <thead><tr><th>Berkas</th><th>Ukuran</th><th>Dibuat</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($cadangan as $satu): ?>
                <tr>
                    <td><code><?= e($satu['nama']) ?></code></td>
                    <td><?= e(ukuran_berkas($satu['byte'])) ?></td>
                    <td><?= e(tanggal(date('Y-m-d H:i:s', $satu['waktu']), true)) ?></td>
                    <td class="baris-tombol">
                        <a class="tombol tombol-kecil" href="<?= e(sip_url('pengaturan.php',
                            ['unduh' => $satu['nama'], 'csrf' => csrf_token()])) ?>">Unduh</a>
                        <form method="post" action="<?= e(sip_url('pengaturan.php')) ?>" class="pulihkan">
                            <?= csrf_input() ?>
                            <input type="hidden" name="aksi" value="pulihkan">
                            <input type="hidden" name="nama" value="<?= e($satu['nama']) ?>">
                            <input type="text" name="konfirmasi" placeholder="<?= e(KATA_PULIHKAN) ?>"
                                   size="10" autocomplete="off" required>
                            <button class="tombol tombol-kecil tombol-bahaya" type="submit">Pulihkan</button>
                        </form>
                        <form method="post" action="<?= e(sip_url('pengaturan.php')) ?>" class="pulihkan">
                            <?= csrf_input() ?>
                            <input type="hidden" name="aksi" value="hapus-cadangan">
                            <input type="hidden" name="nama" value="<?= e($satu['nama']) ?>">
                            <input type="hidden" name="konfirmasi" value="HAPUS BERKAS">
                            <button class="tombol tombol-kecil" type="submit">Buang</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="catatan-kecil">Memulihkan menimpa seluruh isi basis data. Sistem tetap membuat
            cadangan <em>sebelum-pulihkan</em> lebih dulu, jadi tindakan ini bisa dibalik.</p>
    <?php endif; ?>
    <?php
}, 'kartu-cadangan', 'Langkah 1 dari 4');

// --------------------------------------------------------------- 2. ekspor
sip_kartu('Ekspor data', static function () use ($ekspor, $jumlahTabel): void {
    ?>
    <p class="pengantar">Menulis seluruh tabel ke satu berkas JSON, termasuk geometri dalam WKB.
        Berkas inilah yang dibaca kembali oleh kartu Impor. Laporan CSV untuk Excel tetap dibuat
        dari baris perintah: <code>php tools/ekspor.php</code>.</p>
    <form method="post" action="<?= e(sip_url('pengaturan.php')) ?>" class="baris-tombol">
        <?= csrf_input() ?>
        <input type="hidden" name="aksi" value="ekspor">
        <button class="tombol tombol-utama" type="submit">Buat berkas ekspor</button>
    </form>
    <?php if ($ekspor !== []): ?>
        <ul class="berkas">
        <?php foreach ($ekspor as $satu): ?>
            <li>
                <a href="<?= e(sip_url('pengaturan.php',
                    ['unduh-ekspor' => $satu['nama'], 'csrf' => csrf_token()])) ?>"><?= e($satu['nama']) ?></a>
                <span class="redup"><?= e(ukuran_berkas($satu['byte'])) ?> · <?= e(tanggal(date('Y-m-d H:i:s', $satu['waktu']), true)) ?></span>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <details class="rinci">
        <summary>Jumlah baris per tabel saat ini</summary>
        <table class="rapat">
            <tbody>
            <?php foreach ($jumlahTabel as $nama => $n): ?>
                <tr><td><code><?= e($nama) ?></code></td><td><?= e(angka($n)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
    <?php
}, 'kartu-ekspor', 'Langkah 2 dari 4');

// --------------------------------------------------------------- 3. impor
sip_kartu('Impor data', static function () use ($siapImpor, $isiBaru): void {
    ?>
    <p class="pengantar">Membaca berkas hasil ekspor dan menimpa seluruh tabel di dalamnya.
        Berkas yang tidak dikenal ditolak sebelum apa pun berubah.</p>
    <?php if ($siapImpor === 'impor'): ?>
        <?php $bacaan = $isiBaru['bacaan']; ?>
        <div class="seru seru-info" role="status">
            Uji-kering <code><?= e($isiBaru['nama']) ?></code> — dibuat <?= e(tanggal($bacaan['dibuat'], true)) ?>
            dari basis data <code><?= e($bacaan['basis']) ?></code>
            <?php if ($bacaan['desa'] !== ''): ?>· desa <code><?= e($bacaan['desa']) ?></code><?php endif; ?>.
            Belum ada yang berubah.
        </div>
        <table class="rapat">
            <thead><tr><th>Tabel</th><th>Di berkas</th><th>Sekarang</th></tr></thead>
            <tbody>
            <?php foreach (impor_banding($bacaan) as $satu): ?>
                <tr class="<?= $satu['beda'] ? 'beda' : '' ?>">
                    <td><code><?= e($satu['tabel']) ?></code></td>
                    <td><?= e(angka($satu['berkas'])) ?></td>
                    <td><?= e(angka($satu['sekarang'])) ?><?= $satu['beda'] ? ' <span class="lencana lencana-kuning">berbeda</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" action="<?= e(sip_url('pengaturan.php')) ?>" class="baris-tombol jarak-atas">
            <?= csrf_input() ?>
            <input type="hidden" name="aksi" value="impor-terapkan">
            <label class="ketik-kunci">Ketik <strong><?= e(KATA_PULIHKAN) ?></strong> untuk menerapkan
                <input type="text" name="konfirmasi" autocomplete="off" required></label>
            <button class="tombol tombol-bahaya" type="submit">Timpa basis data dengan berkas ini</button>
        </form>
    <?php else: ?>
        <form method="post" action="<?= e(sip_url('pengaturan.php')) ?>" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="aksi" value="impor-uji">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= e((string) (pengaturan_maks_byte())) ?>">
            <p><input type="file" name="berkas" accept="application/json,.json" required></p>
            <p class="catatan-kecil">Batas unggah <?= e(ini_get('upload_max_filesize')) ?>.
                Berkas disimpan di <code>storage/impor/</code> supaya uji-keringnya bisa dibuka lagi.</p>
            <button class="tombol tombol-utama" type="submit">Unggah dan uji</button>
        </form>
    <?php endif; ?>
<?php
}, 'kartu-impor', 'Langkah 3 dari 4');

// --------------------------------------------------------------- 4. kosongkan
sip_kartu('Kosongkan data', static function () use ($siapImpor, $isiBaru, $jumlahTabel): void {
    ?>
    <p class="pengantar">Menghapus isi tabel terpilih. Master data (dusun, jenis hak, jenis penggunaan,
        jenis peralihan, pengaturan desa) dan akun pengguna <strong>tidak pernah</strong> ikut terkosongkan.</p>
    <?php if ($siapImpor === 'kosong'): ?>
        <div class="seru seru-bahaya" role="alert">
            Uji-kering pengosongan — belum ada yang dihapus.
        </div>
        <table class="rapat">
            <tbody>
            <?php foreach ($isiBaru['hitung'] as $nama => $n): ?>
                <tr><td><code><?= e($nama) ?></code></td><td><?= e(angka($n)) ?> baris hilang</td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" action="<?= e(sip_url('pengaturan.php')) ?>" class="baris-tombol jarak-atas">
            <?= csrf_input() ?>
            <input type="hidden" name="aksi" value="kosongkan-terapkan">
            <label class="ketik-kunci">Ketik <strong><?= e(KATA_KUNCI) ?></strong> untuk menghapus
                <input type="text" name="konfirmasi" autocomplete="off" required></label>
            <button class="tombol tombol-bahaya" type="submit">Hapus data terpilih</button>
        </form>
        <p class="catatan-kecil">Sistem membuat cadangan <em>sebelum-kosongkan</em> lebih dulu dan
            membatalkan pengosongan kalau cadangan itu gagal ditulis.</p>
    <?php else: ?>
        <form method="post" action="<?= e(sip_url('pengaturan.php')) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="aksi" value="kosongkan-uji">
            <fieldset class="grup">
                <?php foreach (KELOMPOK_KOSONG as $kunci => $grup): ?>
                    <label class="grup-baris">
                        <input type="checkbox" name="grup[]" value="<?= e($kunci) ?>">
                        <span>
                            <strong><?= e($grup['nama']) ?></strong>
                            <small><?= e(implode(', ', $grup['tabel'])) ?> ·
                                <?= e(angka(array_sum(array_map(
                                    static fn(string $t): int => $jumlahTabel[$t] ?? 0, $grup['tabel']
                                )))) ?> baris</small>
                            <?php if (isset($grup['peringatan'])): ?>
                                <em><?= e($grup['peringatan']) ?></em>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <p class="jarak-atas"><label class="ketik-kunci">Ketik <strong><?= e(KATA_KUNCI) ?></strong>
                untuk melihat uji-keringnya
                <input type="text" name="konfirmasi" autocomplete="off" required></label></p>
            <button class="tombol" type="submit">Uji-kering</button>
        </form>
    <?php endif; ?>
<?php
}, 'kartu-kosong', 'Langkah 4 dari 4');
?>
</div>

<div class="seru-wadah">
<?php
sip_kartu('Yang tidak bisa diubah dari halaman ini', static function () use ($ringkas, $tabelSistem): void {
    ?>
    <p>Empat tabel view (<code>v_bidang</code>, <code>v_rekap_dusun</code>, <code>v_ringkasan_sistem</code>,
        <code>v_statistik_pbb</code>) tidak ikut diekspor karena selalu dihitung dari tabel dasarnya.
        Impor GeoJSON, DXF, dan NOP tetap lewat <code>php tools/impor.php</code> — halaman ini hanya
        menerima berkas hasil ekspor sendiri.</p>
    <p class="catatan-kecil">Terakhir dihitung: <?= e(angka($ringkas['tabel'])) ?> tabel basis,
        ± <?= e(ukuran_berkas($ringkas['byte'])) ?> di cakram, termasuk <?= e(angka(count($tabelSistem))) ?> tabel yang dibacakan.</p>
<?php
}, 'kartu-kecil');
?>
</div>

<?php
sip_footer();
