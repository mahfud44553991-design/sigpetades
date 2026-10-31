<?php
declare(strict_types=1);

/**
 * Peralihan hak: daftar, detail, dan pencatatan mutasi hak atas tanah.
 *
 *   peralihan.php            -> daftar + saringan + formulir catat baru
 *   peralihan.php?id=3       -> detail satu peralihan
 *   POST aksi=catat|ubah     -> simpan perubahan (pengelola)
 */

require __DIR__ . '/app/bootstrap.php';

$pengguna = wajib_masuk();
$kelola = boleh_kelola();

const STATUS_PERALIHAN = [
    'Diajukan' => 'lencana-kuning',
    'Verifikasi' => 'lencana-biru',
    'Selesai' => 'lencana-hijau',
    'Ditolak' => 'lencana-merah',
    'Dicabut' => 'lencana-abu',
];

$tahunTersedia = array_map('strval', db_all('SELECT DISTINCT YEAR(tanggal) t FROM peralihan ORDER BY t DESC'));

// ---------------------------------------------------------------------------
// Pendaftaran & perubahan
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wajib_masuk(PERAN_PENGELOLA);
    wajib_csrf();

    $aksi = post_str('aksi');
    $id = post_int('id');
    $lama = $id === null ? null : detail_peralihan($id);
    $kembali = 'peralihan.php' . ($lama === null ? '' : '?id=' . (int) $lama['id']);

    try {
        if (!in_array($aksi, ['catat', 'ubah'], true)) {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
        if ($aksi === 'ubah' && $lama === null) {
            throw new RuntimeException('Peralihan tidak ditemukan.');
        }

        $tanggal = post_str('tanggal');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
            throw new RuntimeException('Tanggal peralihan wajib diisi (YYYY-MM-DD).');
        }
        $jenisId = post_int('jenis_peralihan_id');
        $jenis = $jenisId === null ? null : db_one('SELECT id, nama FROM jenis_peralihan WHERE id = ? AND aktif = 1', [$jenisId]);
        if ($jenis === null) {
            throw new RuntimeException('Pilih jenis peralihan.');
        }
        $pertama = post_str('pihak_pertama');
        $kedua = post_str('pihak_kedua');
        if (len_utf8($pertama) < 3 || len_utf8($kedua) < 3) {
            throw new RuntimeException('Nama pihak pertama dan pihak kedua wajib diisi.');
        }
        $nilai = post_float('nilai_rp') ?? 0.0;
        if ($nilai < 0 || $nilai > 99999999999999) {
            throw new RuntimeException('Nilai peralihan tidak wajar.');
        }
        $luas = post_float('luas_m2');
        if ($luas !== null && ($luas < 0 || $luas > 9999999999)) {
            throw new RuntimeException('Luas tidak wajar.');
        }
        $status = post_str('status');
        if (!isset(STATUS_PERALIHAN[$status])) {
            $status = 'Diajukan';
        }

        $bidangId = post_int('bidang_id');
        if ($bidangId !== null && db_value('SELECT id FROM bidang WHERE id = ?', [$bidangId]) === null) {
            throw new RuntimeException('Bidang tidak ditemukan.');
        }
        $nop = normalkan_nop(post_str('nop'));
        if ($nop !== '' && db_value('SELECT nop FROM objek_pajak WHERE nop = ?', [$nop]) === null) {
            throw new RuntimeException('NOP belum ada pada data objek pajak desa.');
        }
        $bukuCId = post_int('buku_c_id');
        if ($bukuCId === null && post_str('buku_c_nomor') !== '') {
            $bukuCId = (int) db_value('SELECT id FROM buku_c WHERE nomor = ?', [(int) post_str('buku_c_nomor')]);
            if ($bukuCId === 0) {
                throw new RuntimeException('Nomor Buku C tidak ditemukan.');
            }
        }
        if ($bukuCId !== null && $bukuCId > 0 && db_value('SELECT id FROM buku_c WHERE id = ?', [$bukuCId]) === null) {
            throw new RuntimeException('Buku C tidak ditemukan.');
        }
        $dusunId = post_int('dusun_id');
        if ($dusunId === null && $bidangId !== null) {
            $dusunId = (int) db_value('SELECT dusun_id FROM bidang WHERE id = ?', [$bidangId]);
        }
        if ($dusunId !== null && $dusunId > 0 && db_value('SELECT id FROM dusun WHERE id = ?', [$dusunId]) === null) {
            throw new RuntimeException('Dusun tidak ditemukan.');
        }

        $isi = [
            'bidang_id' => $bidangId,
            'nop' => $nop === '' ? null : $nop,
            'buku_c_id' => ($bukuCId === null || $bukuCId === 0) ? null : $bukuCId,
            'jenis_peralihan_id' => $jenis['id'],
            'tanggal' => $tanggal,
            'no_akta' => post_str('no_akta') ?: null,
            'pejabat' => post_str('pejabat') ?: null,
            'pihak_pertama' => $pertama,
            'pihak_kedua' => $kedua,
            'luas_m2' => $luas,
            'nilai_rp' => $nilai,
            'dusun_id' => ($dusunId === null || $dusunId === 0) ? null : $dusunId,
            'status' => $status,
            'catatan' => post_str('catatan') ?: null,
        ];

        if ($lama === null) {
            $kode = nomor_berikutnya('peralihan', 'kode', 'PRT-' . substr($tanggal, 0, 4) . '-');
            $baru = db_transaksi(static function (PDO $pdo) use ($kode, $isi, $pengguna): int {
                $pdo->prepare(
                    'INSERT INTO peralihan (kode, bidang_id, nop, buku_c_id, jenis_peralihan_id, tanggal,
                                            no_akta, pejabat, pihak_pertama, pihak_kedua, luas_m2, nilai_rp,
                                            dusun_id, status, catatan, dicatat_oleh)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute(array_merge([$kode], array_values($isi), [$pengguna['id']]));
                return (int) $pdo->lastInsertId();
            });
            audit('buat', 'peralihan', (string) $baru,
                'Peralihan ' . $kode . ' (' . $jenis['nama'] . '): ' . $pertama . ' -> ' . $kedua, null, $isi);
            flash_set('berhasil', 'Peralihan ' . $kode . ' tercatat.');
            redirect('peralihan.php?id=' . $baru);
        }

        db_exec(
            'UPDATE peralihan SET bidang_id = ?, nop = ?, buku_c_id = ?, jenis_peralihan_id = ?, tanggal = ?,
                    no_akta = ?, pejabat = ?, pihak_pertama = ?, pihak_kedua = ?, luas_m2 = ?, nilai_rp = ?,
                    dusun_id = ?, status = ?, catatan = ?, dicatat_oleh = ?
             WHERE id = ?',
            array_merge(array_values($isi), [$pengguna['id'], $lama['id']])
        );
        audit('ubah', 'peralihan', (string) $lama['id'],
            'Perubahan peralihan ' . $lama['kode'] . ' (' . $lama['status'] . ' -> ' . $status . ')',
            ['status' => $lama['status'], 'pihak_kedua' => $lama['pihak_kedua'], 'nilai_rp' => $lama['nilai_rp']], $isi);
        flash_set('berhasil', 'Peralihan ' . $lama['kode'] . ' diperbarui.');
        redirect($kembali);
    } catch (Throwable $error) {
        flash_set('gagal', $error->getMessage());
        redirect($kembali);
    }
}

// ---------------------------------------------------------------------------
// Detail
// ---------------------------------------------------------------------------
$idMinta = get_int('id');
if ($idMinta !== null) {
    $p = detail_peralihan($idMinta);
    if ($p === null) {
        flash_set('gagal', 'Peralihan dengan nomor itu tidak ditemukan.');
        redirect('peralihan.php');
    }

    sip_header('Peralihan ' . $p['kode'], 'peralihan');
    sip_judul('Peralihan hak', $p['kode'] . ' · ' . $p['jenis'], tanggal($p['tanggal']) . ' — ' . $p['pihak_pertama'] . ' ke ' . $p['pihak_kedua']);
    ?>

<div class="grid grid-2">
    <section class="kartu">
        <header class="kepala-kartu">
            <div><h2>Perincian peralihan</h2><p>Dicatat oleh <?= e($p['pencatat'] ?: 'petugas') ?></p></div>
            <span class="lencana <?= e(STATUS_PERALIHAN[$p['status']] ?? 'lencana-abu') ?>"><?= e($p['status']) ?></span>
        </header>
        <div class="isi-kartu">
            <dl class="aturan">
                <div class="baris-form">
                    <div>
                        <dt class="label-blok">Pihak pertama</dt><dd><?= e($p['pihak_pertama']) ?></dd>
                        <dt class="label-blok">Pihak kedua</dt><dd class="tebal"><?= e($p['pihak_kedua']) ?></dd>
                        <dt class="label-blok">Tanggal</dt><dd><?= e(tanggal($p['tanggal'])) ?></dd>
                        <dt class="label-blok">Jenis peralihan</dt><dd><?= e($p['jenis']) ?></dd>
                    </div>
                    <div>
                        <dt class="label-blok">Dasar (akta / PPAT)</dt>
                        <dd><?= e($p['no_akta'] ?: '—') ?><?= $p['pejabat'] ? ' · ' . e($p['pejabat']) : '' ?></dd>
                        <dt class="label-blok">Nilai peralihan</dt><dd><?= e(rupiah((float) $p['nilai_rp'])) ?></dd>
                        <dt class="label-blok">Luas</dt><dd><?= e($p['luas_m2'] === null ? '—' : angka((float) $p['luas_m2'], 2) . ' m&sup2;') ?></dd>
                        <dt class="label-blok">Dusun</dt><dd><?= e($p['dusun'] ?: '—') ?></dd>
                    </div>
                </div>
            </dl>
            <?php if (!empty($p['catatan'])): ?><p class="kecil redup"><?= e($p['catatan']) ?></p><?php endif; ?>
        </div>
    </section>

    <section class="kartu">
        <header class="kepala-kartu"><div><h2>Tautan data</h2><p>Bidang, NOP, dan Buku C yang bergerak</p></div></header>
        <div class="isi-kartu">
            <ul class="daftar-kecil">
                <li>
                    <span class="redup">Bidang</span>
                    <span class="rapek-kanan">
                        <?php if ($p['bidang_id'] !== null): ?>
                            <a class="tebal" href="<?= e(sip_url('bidang.php', ['id' => $p['bidang_id']])) ?>"><?= e($p['bidang_kode']) ?></a>
                        <?php else: ?>— belum ditautkan<?php endif; ?>
                    </span>
                </li>
                <li>
                    <span class="redup">NOP</span>
                    <span class="rapek-kanan">
                        <?php if ($p['nop'] !== null): ?>
                            <a class="nobreak" href="<?= e(sip_url('sppt.php', ['nop' => $p['nop']])) ?>"><?= e($p['nop']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </span>
                </li>
                <li>
                    <span class="redup">Buku C</span>
                    <span class="rapek-kanan">
                        <?php if ($p['buku_c_nomor'] !== null): ?>
                            <a href="<?= e(sip_url('buku-c.php', ['nomor' => $p['buku_c_nomor']])) ?>">C-<?= e($p['buku_c_nomor']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </span>
                </li>
            </ul>
            <p class="petunjuk">Setelah peralihan selesai, perbarui catatan hak pada halaman bidang agar data tidak tertinggal.</p>
            <?php if ($p['bidang_id'] !== null): ?>
                <div class="baris-tombol">
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('bidang.php', ['id' => $p['bidang_id']])) ?>">Ubah hak bidang ini</a>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if ($kelola): ?>
    <section class="kartu jarak-atas">
        <header class="kepala-kartu"><div><h2>Perbarui catatan peralihan</h2><p>Perubahan tercatat lengkap di log aktivitas</p></div></header>
        <div class="isi-kartu">
            <form method="post" action="<?= e(sip_url('peralihan.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="aksi" value="ubah">
                <input type="hidden" name="id" value="<?= e($p['id']) ?>">
                <?php require __DIR__ . '/app/form-peralihan.php'; ?>
                <div class="baris-form">
                    <div class="lapangan">
                        <label for="p-status">Status</label>
                        <select id="p-status" name="status">
                            <?php foreach (STATUS_PERALIHAN as $opsi => $kelas): ?>
                                <option value="<?= e($opsi) ?>" <?= $p['status'] === $opsi ? 'selected' : '' ?>><?= e($opsi) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lapangan">
                        <label for="p-bukuc">Nomor Buku C</label>
                        <input id="p-bukuc" name="buku_c_nomor" type="number" min="1" value="<?= e($p['buku_c_nomor']) ?>">
                    </div>
                </div>
                <div class="lapangan">
                    <label for="p-catatan">Catatan</label>
                    <textarea id="p-catatan" name="catatan"><?= e($p['catatan']) ?></textarea>
                </div>
                <button class="tombol tombol-utama" type="submit">Simpan perubahan</button>
            </form>
        </div>
    </section>
<?php endif; ?>

    <?php
    sip_footer();
    return;
}

// ---------------------------------------------------------------------------
// Daftar
// ---------------------------------------------------------------------------
$query = [
    'cari'   => get_str('cari'),
    'status' => get_str('status'),
    'jenis'  => get_str('jenis'),
    'dusun'  => get_str('dusun'),
    'tahun'  => get_str('tahun'),
];
if ($query['status'] !== '' && !isset(STATUS_PERALIHAN[$query['status']])) {
    $query['status'] = '';
}
$queryTautan = array_filter($query, static fn($v) => $v !== '');
[$halaman, $perHalaman] = batas_halaman(get_int('halaman'), get_int('per_halaman'), 100);
$daftar = daftar_peralihan($query, $halaman, $perHalaman);
$rekap = statistik_peralihan((int) date('Y'));

sip_header('Peralihan hak', 'peralihan');
sip_judul('Peralihan hak', 'Mutasi hak atas tanah',
    'Setiap peralihan — jual beli, waris, hibah, atau pembagian hak — dicatat di sini agar riwayat bidang tidak hilang.');
?>

<div class="statistik">
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">01</span>
        <div class="stat-teks">
            <small>Peralihan <?= e(date('Y')) ?></small>
            <strong><?= e(angka((int) $rekap['total'])) ?></strong>
            <em><?= e(angka((int) $rekap['selesai'])) ?> sudah selesai</em>
        </div>
    </div></div>
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">02</span>
        <div class="stat-teks">
            <small>Menunggu proses</small>
            <strong><?= e(angka((int) $rekap['menunggu'])) ?></strong>
            <em>diajukan atau masih diverifikasi</em>
        </div>
    </div></div>
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">03</span>
        <div class="stat-teks">
            <small>Nilai peralihan <?= e(date('Y')) ?></small>
            <strong><?= e(rupiah((float) $rekap['nilai'])) ?></strong>
            <em>total dari seluruh catatan tahun ini</em>
        </div>
    </div></div>
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">04</span>
        <div class="stat-teks">
            <small>Hasil saringan</small>
            <strong><?= e(angka($daftar['total'])) ?></strong>
            <em>baris sesuai kondisi pencarian</em>
        </div>
    </div></div>
</div>

<?php if ($kelola): ?>
    <section class="kartu jarak-atas">
        <header class="kepala-kartu"><div><h2>Catat peralihan baru</h2><p>Nomor PRT dibuat otomatis mengikuti tahun tanggal peralihan</p></div></header>
        <div class="isi-kartu">
            <form method="post" action="<?= e(sip_url('peralihan.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="aksi" value="catat">
                <?php require __DIR__ . '/app/form-peralihan.php'; ?>
                <div class="baris-form">
                    <div class="lapangan">
                        <label for="p-status">Status awal</label>
                        <select id="p-status" name="status">
                            <?php foreach (array_slice(array_keys(STATUS_PERALIHAN), 0, 2) as $opsi): ?>
                                <option value="<?= e($opsi) ?>"><?= e($opsi) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lapangan">
                        <label for="p-bukuc">Nomor Buku C</label>
                        <input id="p-bukuc" name="buku_c_nomor" type="number" min="1">
                    </div>
                </div>
                <div class="lapangan">
                    <label for="p-catatan">Catatan</label>
                    <textarea id="p-catatan" name="catatan"></textarea>
                </div>
                <button class="tombol tombol-utama" type="submit">Simpan peralihan</button>
            </form>
        </div>
    </section>
<?php endif; ?>

<section class="kartu jarak-atas">
    <header class="kepala-kartu">
        <div><h2>Saringan</h2><p>Cari nama pihak, kode peralihan, nomor akta, NOP, atau kode bidang</p></div>
        <div class="baris-tombol"><a class="tombol tombol-kecil" href="<?= e(sip_url('peralihan.php')) ?>">Bersihkan</a></div>
    </header>
    <div class="isi-kartu">
        <form class="formulir-alat" method="get" action="<?= e(sip_url('peralihan.php')) ?>">
            <div class="lapangan">
                <label class="label-blok" for="s-cari">Cari</label>
                <input id="s-cari" name="cari" maxlength="60" value="<?= e($query['cari']) ?>">
            </div>
            <div class="lapangan">
                <label class="label-blok" for="s-jenis">Jenis</label>
                <select id="s-jenis" name="jenis">
                    <option value="">Semua jenis</option>
                    <?php foreach (daftar_jenis_peralihan() as $j): ?>
                        <option value="<?= e($j['id']) ?>" <?= $query['jenis'] === (string) $j['id'] ? 'selected' : '' ?>><?= e($j['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="s-status">Status</label>
                <select id="s-status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach (STATUS_PERALIHAN as $opsi => $kelas): ?>
                        <option value="<?= e($opsi) ?>" <?= $query['status'] === $opsi ? 'selected' : '' ?>><?= e($opsi) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="s-dusun">Dusun</label>
                <select id="s-dusun" name="dusun">
                    <option value="">Semua dusun</option>
                    <?php foreach (daftar_dusun() as $d): ?>
                        <option value="<?= e($d['id']) ?>" <?= $query['dusun'] === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="s-tahun">Tahun</label>
                <select id="s-tahun" name="tahun">
                    <option value="">Semua tahun</option>
                    <?php foreach ($tahunTersedia as $opsi): ?>
                        <option value="<?= e($opsi) ?>" <?= $query['tahun'] === $opsi ? 'selected' : '' ?>><?= e($opsi) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan" style="flex:0 0 auto">
                <button class="tombol tombol-utama" type="submit">Terapkan</button>
            </div>
        </form>
    </div>
</section>

<section class="kartu jarak-atas">
    <header class="kepala-kartu"><div><h2>Daftar peralihan</h2><p>Diurutkan dari tanggal terbaru</p></div></header>
    <div class="gulir-tabel">
        <table>
            <thead>
                <tr><th>Kode</th><th>Jenis</th><th>Pihak pertama</th><th>Pihak kedua</th><th>Bidang</th>
                    <th class="nobreak">Tanggal</th><th class="angka">Nilai</th><th class="tengah">Status</th></tr>
            </thead>
            <tbody>
                <?php if ($daftar['items'] === []): ?>
                    <tr><td colspan="8" class="kosong"><strong>Belum ada peralihan tercatat.</strong>Gunakan formulir di atas untuk mencatat yang pertama.</td></tr>
                <?php endif; ?>
                <?php foreach ($daftar['items'] as $b): ?>
                    <tr>
                        <td class="nobreak"><a class="tebal" href="<?= e(sip_url('peralihan.php', ['id' => $b['id']])) ?>"><?= e($b['kode']) ?></a></td>
                        <td><?= e($b['jenis']) ?></td>
                        <td><?= e(perpanjang((string) $b['pihak_pertama'], 24)) ?></td>
                        <td><?= e(perpanjang((string) $b['pihak_kedua'], 24)) ?></td>
                        <td>
                            <?php if ($b['bidang_id'] !== null): ?>
                                <a href="<?= e(sip_url('bidang.php', ['id' => $b['bidang_id']])) ?>"><?= e($b['bidang_kode']) ?></a>
                            <?php else: ?><span class="redup"><?= e($b['nop'] ? 'via NOP' : '—') ?></span><?php endif; ?>
                        </td>
                        <td class="nobreak"><?= e(tanggal($b['tanggal'])) ?></td>
                        <td class="angka"><?= e(rupiah((float) $b['nilai_rp'])) ?></td>
                        <td class="tengah"><span class="lencana <?= e(STATUS_PERALIHAN[$b['status']] ?? 'lencana-abu') ?>"><?= e($b['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginasi_html($halaman, $daftar['total'], $perHalaman, $queryTautan, 'peralihan.php') ?>
</section>

    <?php
    sip_footer();
