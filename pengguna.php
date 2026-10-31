<?php
declare(strict_types=1);

/**
 * Manajemen akun dan pengaturan desa. Hanya Super Admin.
 *
 *   POST aksi=buat|ubah|sandi|kunci|hapus|setelan
 *
 * Sandi tidak pernah ditampilkan atau dikirim keluar; hanya hash bcrypt yang
 * disimpan. Akun terakhir yang berperan Super Admin tidak bisa dinonaktifkan,
 * dihapus, atau diturunkan agar sistem tidak terkunci dari dalam.
 */

require __DIR__ . '/app/bootstrap.php';

$pengguna = wajib_masuk([PERAN_SUPER]);

/** Kunci setelan yang boleh diubah lewat halaman ini. */
const SETELAN_BISA_DIUBAH = [
    'nama_sistem' => ['Nama sistem', 'text'],
    'nama_desa' => ['Nama desa', 'text'],
    'nama_kecamatan' => ['Kecamatan', 'text'],
    'nama_kabupaten' => ['Kabupaten', 'text'],
    'nama_provinsi' => ['Provinsi', 'text'],
    'tahun_pbb_aktif' => ['Tahun PBB aktif', 'tahun'],
    'kode_wilayah_nop' => ['Kode wilayah NOP (10 digit)', 'kode'],
    'peta_lat_pusat' => ['Garis lintang pusat peta', 'angka'],
    'peta_lon_pusat' => ['Bujur tengah pusat peta', 'angka'],
    'peta_zoom_awal' => ['Zoom awal peta', 'angka'],
];

/**
 * Sandi minimal 12 karakter dengan huruf dan angka. Aturan ini sengaja lebih
 * ketat daripada sekadar panjang karena akun desa sering dipakai bergantian.
 */
function periksa_sandi(string $sandi): void
{
    if (mb_strlen($sandi, 'UTF-8') < 12) {
        throw new RuntimeException('Kata sandi minimal 12 karakter.');
    }
    if (!preg_match('/[A-Za-z]/', $sandi) || !preg_match('/\d/', $sandi)) {
        throw new RuntimeException('Kata sandi harus mengandung huruf dan angka.');
    }
}

$jumlahSuperAktif = (int) db_value(
    "SELECT COUNT(*) FROM pengguna WHERE peran = ? AND is_active = 1",
    [PERAN_SUPER]
);

// ---------------------------------------------------------------------------
// Perubahan
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wajib_csrf();
    $aksi = post_str('aksi');
    $id = post_int('id');
    $target = $id === null ? null : db_one('SELECT * FROM pengguna WHERE id = ?', [$id]);

    try {
        if ($aksi === 'setelan') {
            foreach (array_keys(SETELAN_BISA_DIUBAH) as $kunci) {
                $nilai = trim((string) ($_POST[$kunci] ?? ''));
                if ($nilai === '') {
                    continue;
                }
                $jenis = SETELAN_BISA_DIUBAH[$kunci][1];
                if ($jenis === 'tahun' && !preg_match('/^(19|20)\d{2}$/', $nilai)) {
                    throw new RuntimeException('Tahun PBB aktif harus berupa tahun empat digit.');
                }
                if ($kunci === 'kode_wilayah_nop' && !preg_match('/^\d{10}$/', $nilai)) {
                    throw new RuntimeException('Kode wilayah NOP harus 10 digit.');
                }
                if ($jenis === 'angka'
                    && !filter_var($nilai, FILTER_VALIDATE_FLOAT) && !is_numeric($nilai)) {
                    throw new RuntimeException('Nilai "' . SETELAN_BISA_DIUBAH[$kunci][0] . '" harus angka.');
                }
                if (len_utf8($nilai) > 255) {
                    throw new RuntimeException('Nilai setelan terlalu panjang.');
                }
                $lama = db_one('SELECT nilai FROM setelan WHERE kunci = ?', [$kunci]);
                if ($lama === null) {
                    db_exec('INSERT INTO setelan (kunci, nilai, keterangan, diubah_oleh) VALUES (?, ?, ?, ?)',
                        [$kunci, $nilai, SETELAN_BISA_DIUBAH[$kunci][0], $pengguna['id']]);
                } else {
                    db_exec('UPDATE setelan SET nilai = ?, diubah_oleh = ? WHERE kunci = ?',
                        [$nilai, $pengguna['id'], $kunci]);
                }
                if ((string) ($lama['nilai'] ?? '') !== $nilai) {
                    audit('ubah', 'setelan', $kunci, 'Setelan ' . SETELAN_BISA_DIUBAH[$kunci][0] . ' diubah',
                        ['nilai' => $lama['nilai'] ?? null], ['nilai' => $nilai]);
                }
            }
            flash_set('berhasil', 'Pengaturan desa disimpan.');
            redirect('pengguna.php');
        }

        if ($aksi === 'buat') {
            $nama = post_str('nama_lengkap');
            $username = post_str('username');
            $peran = post_str('peran');
            $sandi = (string) ($_POST['password'] ?? '');
            if (len_utf8($nama) < 3 || len_utf8($nama) > 100) {
                throw new RuntimeException('Nama lengkap wajib 3–100 karakter.');
            }
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
                throw new RuntimeException('Nama pengguna 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
            }
            if (!array_key_exists($peran, LABEL_PERAN)) {
                throw new RuntimeException('Peran tidak dikenal.');
            }
            periksa_sandi($sandi);
            if (db_value('SELECT id FROM pengguna WHERE username = ?', [$username]) !== null) {
                throw new RuntimeException('Nama pengguna itu sudah dipakai.');
            }

            $baru = db_transaksi(static function (PDO $pdo) use ($username, $sandi, $nama, $peran, $pengguna): int {
                $statement = $pdo->prepare(
                    'INSERT INTO pengguna (username, password_hash, nama_lengkap, jabatan, peran, is_active)
                     VALUES (?, ?, ?, ?, ?, 1)'
                );
                $statement->execute([
                    $username,
                    password_hash($sandi, PASSWORD_DEFAULT),
                    $nama,
                    peran_label($peran),
                    $peran,
                ]);
                return (int) $pdo->lastInsertId();
            });

            audit('buat', 'pengguna', (string) $baru, 'Akun ' . $username . ' dibuat dengan peran ' . peran_label($peran));
            flash_set('berhasil', 'Akun ' . $username . ' dibuat. Sampaikan kata sandinya secara langsung, bukan lewat layar ini.');
            redirect('pengguna.php');
        }

        if ($target === null) {
            throw new RuntimeException('Akun tidak ditemukan.');
        }

        if ($aksi === 'ubah') {
            $nama = post_str('nama_lengkap');
            if (len_utf8($nama) < 3 || len_utf8($nama) > 100) {
                throw new RuntimeException('Nama lengkap wajib 3–100 karakter.');
            }
            $peran = post_str('peran');
            if (!array_key_exists($peran, LABEL_PERAN)) {
                throw new RuntimeException('Peran tidak dikenal.');
            }
            $aktif = post_str('is_active') === '1';
            $superTerakhir = $target['peran'] === PERAN_SUPER && (int) $target['is_active'] === 1 && $jumlahSuperAktif <= 1;
            if ((int) $target['id'] === (int) $pengguna['id'] && $peran !== $target['peran']) {
                throw new RuntimeException('Anda tidak dapat mengubah peran akun Anda sendiri.');
            }
            if ($superTerakhir && (!$aktif || $peran !== PERAN_SUPER)) {
                throw new RuntimeException('Akun Super Admin terakhir tidak boleh dinonaktifkan atau diturunkan.');
            }

            db_exec('UPDATE pengguna SET nama_lengkap = ?, jabatan = ?, peran = ?, is_active = ? WHERE id = ?',
                [$nama, post_str('jabatan'), $peran, $aktif ? 1 : 0, $target['id']]);
            audit('ubah', 'pengguna', (string) $target['id'],
                'Akun ' . $target['username'] . ' diperbarui (' . peran_label($peran) . ', ' . ($aktif ? 'aktif' : 'nonaktif') . ')',
                ['peran' => $target['peran'], 'is_active' => $target['is_active']],
                ['peran' => $peran, 'is_active' => $aktif ? 1 : 0]);
            flash_set('berhasil', 'Akun ' . $target['username'] . ' diperbarui.');
            redirect('pengguna.php?lihat=' . (int) $target['id']);
        }

        if ($aksi === 'sandi') {
            $sandi = (string) ($_POST['password'] ?? '');
            periksa_sandi($sandi);
            db_exec('UPDATE pengguna SET password_hash = ?, gagal_login = 0, terkunci_sampai = NULL WHERE id = ?',
                [password_hash($sandi, PASSWORD_DEFAULT), $target['id']]);
            audit('ubah', 'pengguna', (string) $target['id'], 'Kata sandi akun ' . $target['username'] . ' disetel ulang');
            flash_set('berhasil', 'Kata sandi ' . $target['username'] . ' diganti. Sampaikan lewat jalur resmi, jangan tulis di layar.');
            redirect('pengguna.php');
        }

        if ($aksi === 'kunci') {
            db_exec('UPDATE pengguna SET gagal_login = 0, terkunci_sampai = NULL WHERE id = ?', [$target['id']]);
            audit('buka_kunci', 'pengguna', (string) $target['id'], 'Penguncian akun ' . $target['username'] . ' dibuka manual');
            flash_set('berhasil', 'Akun ' . $target['username'] . ' bisa dicoba masuk lagi.');
            redirect('pengguna.php');
        }

        if ($aksi === 'hapus') {
            if ((int) $target['id'] === (int) $pengguna['id']) {
                throw new RuntimeException('Anda tidak dapat menghapus akun sendiri.');
            }
            if ($target['peran'] === PERAN_SUPER && $jumlahSuperAktif <= 1) {
                throw new RuntimeException('Akun Super Admin terakhir tidak boleh dihapus.');
            }
            db_exec('DELETE FROM pengguna WHERE id = ?', [$target['id']]);
            audit('hapus', 'pengguna', (string) $target['id'], 'Akun ' . $target['username'] . ' dihapus', $target, null);
            flash_set('berhasil', 'Akun ' . $target['username'] . ' dihapus.');
            redirect('pengguna.php');
        }

        throw new RuntimeException('Aksi tidak dikenal.');
    } catch (Throwable $error) {
        flash_set('gagal', $error->getMessage());
        redirect('pengguna.php' . ($target === null ? '' : '?lihat=' . (int) $target['id']));
    }
}

// ---------------------------------------------------------------------------
// Tampilan
// ---------------------------------------------------------------------------
$daftar = db_all(
    'SELECT id, username, nama_lengkap, jabatan, peran, is_active, gagal_login, terkunci_sampai,
            terakhir_login, dibuat_pada,
            (SELECT COUNT(*) FROM audit_log a WHERE a.pengguna_id = pengguna.id) AS jejak
     FROM pengguna ORDER BY (peran = \'super_admin\') DESC, nama_lengkap'
);
$aktifitas = aktivitas_terbaru(15);

sip_header('Manajemen akun', 'pengguna');
sip_judul('Super Admin', 'Akun pengguna & pengaturan desa',
    'Buat akun untuk petugas desa, atur perannya, dan tentukan identitas desa serta nilai awal sistem.');
?>

<div class="grid grid-sidebar">
    <div>
        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>Daftar akun</h2><p><?= e(count($daftar)) ?> akun &middot; <?= e($jumlahSuperAktif) ?> Super Admin aktif</p></div>
            </header>
            <div class="gulir-tabel">
                <table>
                    <thead>
                        <tr><th>Nama</th><th>Nama pengguna</th><th>Jabatan</th><th>Peran</th>
                            <th class="tengah">Status</th><th>Terakhir masuk</th><th class="tengah">Jejak</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daftar as $u):
                            $terkunci = $u['terkunci_sampai'] !== null && strtotime((string) $u['terkunci_sampai']) > time(); ?>
                            <tr>
                                <td>
                                    <a class="tebal" href="<?= e(sip_url('pengguna.php', ['lihat' => $u['id']])) ?>"><?= e($u['nama_lengkap']) ?></a>
                                    <?php if ((int) $u['id'] === (int) $pengguna['id']): ?><span class="kecil redup">(Anda)</span><?php endif; ?>
                                </td>
                                <td class="nobreak"><?= e($u['username']) ?></td>
                                <td><?= e($u['jabatan'] ?: '—') ?></td>
                                <td><span class="lencana <?= $u['peran'] === PERAN_SUPER ? 'lencana-biru' : ($u['peran'] === PERAN_BACA ? 'lencana-abu' : 'lencana-hijau') ?>"><?= e(peran_label($u['peran'])) ?></span></td>
                                <td class="tengah">
                                    <?php if ($terkunci): ?>
                                        <span class="lencana lencana-merah">Terkunci</span>
                                    <?php elseif ((int) $u['is_active'] === 1): ?>
                                        <span class="lencana lencana-hijau">Aktif</span>
                                    <?php else: ?>
                                        <span class="lencana lencana-abu">Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="nobreak"><?= e($u['terakhir_login'] ? tanggal($u['terakhir_login'], true) : 'belum pernah') ?></td>
                                <td class="tengah"><?= e(angka((int) $u['jejak'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="kartu jarak-atas">
            <header class="kepala-kartu"><div><h2>Log aktivitas terakhir</h2><p>Setiap perubahan data tercatat di sini</p></div></header>
            <div class="gulir-tabel">
                <table>
                    <thead><tr><th>Waktu</th><th>Pelaksana</th><th>Aksi</th><th>Objek</th><th>Keterangan</th></tr></thead>
                    <tbody>
                        <?php if ($aktifitas === []): ?>
                            <tr><td colspan="5" class="kosong">Belum ada aktivitas tercatat.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($aktifitas as $a): ?>
                            <tr>
                                <td class="nobreak kecil"><?= e(tanggal($a['waktu'], true)) ?></td>
                                <td><?= e($a['pelaksana'] ?: 'sistem') ?></td>
                                <td><span class="lencana lencana-abu"><?= e($a['aksi']) ?></span></td>
                                <td class="kecil"><?= e($a['objek']) ?> <?= e($a['objek_id']) ?></td>
                                <td><?= e(perpanjang((string) $a['keterangan'], 70)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div>
        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Buat akun baru</h2><p>Kata sandi hanya diisi sekali, tidak pernah ditampilkan lagi</p></div></header>
            <div class="isi-kartu">
                <form method="post" action="<?= e(sip_url('pengguna.php')) ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="aksi" value="buat">
                    <div class="lapangan">
                        <label for="u-nama">Nama lengkap <span class="redup">*</span></label>
                        <input id="u-nama" name="nama_lengkap" maxlength="100" required>
                    </div>
                    <div class="baris-form">
                        <div class="lapangan">
                            <label for="u-username">Nama pengguna <span class="redup">*</span></label>
                            <input id="u-username" name="username" maxlength="50" required pattern="[A-Za-z0-9._-]{3,50}">
                        </div>
                        <div class="lapangan">
                            <label for="u-jabatan">Jabatan</label>
                            <input id="u-jabatan" name="jabatan" maxlength="60" placeholder="Kasi Pemerintahan">
                        </div>
                    </div>
                    <div class="baris-form">
                        <div class="lapangan">
                            <label for="u-peran">Peran <span class="redup">*</span></label>
                            <select id="u-peran" name="peran" required>
                                <?php foreach (LABEL_PERAN as $kode => $label): ?>
                                    <option value="<?= e($kode) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="lapangan">
                            <label for="u-sandi">Kata sandi <span class="redup">*</span></label>
                            <input id="u-sandi" name="password" type="password" minlength="12" required autocomplete="new-password">
                        </div>
                    </div>
                    <p class="petunjuk">Minimal 12 karakter, mengandung huruf dan angka. Pengamat (baca) tidak dapat mengubah data apa pun.</p>
                    <button class="tombol tombol-utama" type="submit">Buat akun</button>
                </form>
            </div>
        </section>

        <?php
        $pilih = get_int('lihat');
        $detail = $pilih === null ? null : db_one('SELECT * FROM pengguna WHERE id = ?', [$pilih]);
        if ($detail !== null): ?>
            <section class="kartu jarak-atas">
                <header class="kepala-kartu">
                    <div><h2>Ubah akun</h2><p><?= e($detail['username']) ?></p></div>
                </header>
                <div class="isi-kartu">
                    <form method="post" action="<?= e(sip_url('pengguna.php')) ?>">
                        <?= csrf_input() ?>
                        <input type="hidden" name="aksi" value="ubah">
                        <input type="hidden" name="id" value="<?= e($detail['id']) ?>">
                        <div class="lapangan">
                            <label for="d-nama">Nama lengkap</label>
                            <input id="d-nama" name="nama_lengkap" maxlength="100" required value="<?= e($detail['nama_lengkap']) ?>">
                        </div>
                        <div class="baris-form">
                            <div class="lapangan">
                                <label for="d-jabatan">Jabatan</label>
                                <input id="d-jabatan" name="jabatan" maxlength="60" value="<?= e($detail['jabatan']) ?>">
                            </div>
                            <div class="lapangan">
                                <label for="d-peran">Peran</label>
                                <select id="d-peran" name="peran" <?= (int) $detail['id'] === (int) $pengguna['id'] ? 'disabled' : '' ?>>
                                    <?php foreach (LABEL_PERAN as $kode => $label): ?>
                                        <option value="<?= e($kode) ?>" <?= $detail['peran'] === $kode ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ((int) $detail['id'] === (int) $pengguna['id']): ?>
                                    <input type="hidden" name="peran" value="<?= e($detail['peran']) ?>">
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="lapangan">
                            <label class="cek" for="d-aktif">
                                <input id="d-aktif" type="checkbox" name="is_active" value="1" <?= (int) $detail['is_active'] === 1 ? 'checked' : '' ?>>
                                Akun ini aktif dan boleh masuk
                            </label>
                        </div>
                        <div class="baris-tombol">
                            <button class="tombol tombol-utama" type="submit">Simpan</button>
                            <?php if ((int) $detail['id'] !== (int) $pengguna['id']): ?>
                                <button class="tombol tombol-bahaya" type="submit"
                                        data-konfirmasi="Hapus akun <?= e($detail['username']) ?>? Riwayat lognya tetap disimpan."
                                        onclick="this.form.querySelector('[name=aksi]').value='hapus'">Hapus akun</button>
                            <?php endif; ?>
                        </div>
                    </form>

                    <form method="post" action="<?= e(sip_url('pengguna.php')) ?>" class="jarak-atas">
                        <?= csrf_input() ?>
                        <input type="hidden" name="aksi" value="sandi">
                        <input type="hidden" name="id" value="<?= e($detail['id']) ?>">
                        <div class="lapangan">
                            <label for="s-sandi">Setel ulang kata sandi</label>
                            <input id="s-sandi" name="password" type="password" minlength="12" required autocomplete="new-password">
                        </div>
                        <div class="baris-tombol">
                            <button class="tombol" type="submit">Ganti sandi</button>
                            <?php if ((int) $detail['id'] !== (int) $pengguna['id']): ?>
                                <button class="tombol" type="submit" formnovalidate
                                        onclick="this.form.querySelector('[name=aksi]').value='kunci'">Buka penguncian</button>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if ($detail['terkunci_sampai'] !== null || (int) $detail['gagal_login'] > 0): ?>
                        <p class="petunjuk">Gagal masuk terakhir: <?= e((int) $detail['gagal_login']) ?> kali<?=
                            $detail['terkunci_sampai'] ? ', terkunci sampai ' . e(tanggal($detail['terkunci_sampai'], true)) : '' ?>.</p>
                    <?php endif; ?>
                </div>
            </section>
        <?php else: ?>
            <section class="kartu jarak-atas">
                <div class="isi-kartu">
                    <p class="kosong">Pilih salah satu akun pada daftar untuk mengubah peran, menyetal ulang sandi, atau membuka pengunciannya.</p>
                </div>
            </section>
        <?php endif; ?>

        <section class="kartu jarak-atas">
            <header class="kepala-kartu"><div><h2>Pengaturan desa</h2><p>Dipakai layout, peta, dan importer</p></div>
                <a class="tombol tombol-kecil" href="<?= e(sip_url('pasang.php')) ?>">Pemasangan data</a>
            </header>
            <div class="isi-kartu">
                <form method="post" action="<?= e(sip_url('pengguna.php')) ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="aksi" value="setelan">
                    <div class="baris-form">
                        <?php foreach (SETELAN_BISA_DIUBAH as $kunci => [$label, $jenis]): ?>
                            <div class="lapangan">
                                <label for="t-<?= e($kunci) ?>"><?= e($label) ?></label>
                                <input id="t-<?= e($kunci) ?>" name="<?= e($kunci) ?>"
                                       type="<?= $jenis === 'angka' ? 'number' : 'text' ?>"
                                       <?= $jenis === 'angka' ? 'step="any"' : '' ?>
                                       value="<?= e(setelan($kunci)) ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="tombol tombol-utama" type="submit">Simpan pengaturan</button>
                </form>
            </div>
        </section>
    </div>
</div>

    <?php
    sip_footer();
