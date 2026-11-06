<?php
declare(strict_types=1);

/**
 * Bidang tanah: daftar yang bisa disaring dan halaman detail satu bidang.
 *
 *   bidang.php                 -> tabel daftar + saringan
 *   bidang.php?id=123          -> detail (hak, PBB, peralihan, laporan, Buku C)
 *   bidang.php?aksi=... (POST) -> perubahan data, hanya pengelola
 */

require __DIR__ . '/app/bootstrap.php';

$pengguna = wajib_masuk();
$bolehUbah = boleh_kelola();

const STATUS_BIDANG = [
    'aktif' => ['Aktif', 'lencana-hijau'],
    'perlu_verifikasi' => ['Perlu verifikasi', 'lencana-kuning'],
    'duplikat' => ['Duplikat', 'lencana-abu'],
    'dihapus' => ['Dihapus', 'lencana-merah'],
];

// ---------------------------------------------------------------------------
// Perubahan data (pengelola saja)
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wajib_masuk(PERAN_PENGELOLA);
    wajib_csrf();

    $aksi = post_str('aksi');
    if ($aksi === 'impor-bidang-tanah') {
        wajib_masuk([PERAN_SUPER]);
        require_once __DIR__ . '/tools/lib-impor.php';
        $sumberBidang = [
            'tanah' => ['berkas' => SIP_ROOT . '/bidang_tanah.geojson', 'tipe' => 'tanah'],
            'lain' => ['berkas' => SIP_ROOT . '/bidang_lain.geojson', 'tipe' => 'lain'],
        ];
        $pilihan = post_str('berkas_bidang');
        if (!isset($sumberBidang[$pilihan])) {
            flash_set('gagal', 'Pilih berkas bidang yang akan diimpor.');
            redirect('bidang.php');
        }
        set_time_limit(0);
        $sumber = $sumberBidang[$pilihan];
        $hasil = imporBidang($sumber['berkas'], $sumber['tipe'], false);
        $jumlahBaru = (int) ($hasil['dibuat'] ?? 0);
        $galat = (array) ($hasil['galat'] ?? []);
        audit('impor', 'bidang', 'bidang-' . $pilihan . '-geojson',
            'Impor ' . basename($sumber['berkas']) . ' tanpa reset',
            null,
            array_merge(array_diff_key($hasil, ['galat' => 1]), ['jumlah_galat' => count($galat)])
        );
        if ($galat !== []) {
            flash_set('gagal', angka($jumlahBaru) . ' bidang baru diimpor; '
                . angka(count($galat)) . ' baris bermasalah. ' . implode(' ', array_slice($galat, 0, 3)));
        } else {
            flash_set('berhasil', angka($jumlahBaru) . ' bidang baru diimpor dari '
                . basename($sumber['berkas']) . '; kode yang sudah ada dilewati.');
        }
        redirect('bidang.php');
    }

    if ($aksi === 'hapus-semua-digitasi' || $aksi === 'hapus-semua-jenis') {
        wajib_masuk([PERAN_SUPER]);
        $jenisHapus = post_str('jenis_hapus');
        $hapusTanah = $aksi === 'hapus-semua-jenis' && $jenisHapus === 'tanah';
        if ($aksi === 'hapus-semua-jenis' && !in_array($jenisHapus, ['digitasi', 'tanah'], true)) {
            flash_set('gagal', 'Jenis bidang untuk dihapus tidak dikenal.');
            redirect('bidang.php');
        }
        $kondisi = $hapusTanah ? "tipe = 'tanah'" : "sumber = 'csv-digitasi'";
        $labelHapus = $hapusTanah ? 'semua bidang bertipe tanah' : 'hasil impor CSV digitasi';
        $jumlahHapus = db_transaksi(static fn(PDO $pdo): int => db_exec("DELETE FROM bidang WHERE {$kondisi}"));
        if ($jumlahHapus > 0) {
            audit('hapus', 'bidang', $hapusTanah ? 'tipe-tanah' : 'csv-digitasi',
                'Hapus ' . $labelHapus, null, ['jumlah' => $jumlahHapus]);
        }
        flash_set('berhasil', $jumlahHapus > 0
            ? angka($jumlahHapus) . ' ' . $labelHapus . ' dihapus.'
            : 'Tidak ada ' . $labelHapus . ' untuk dihapus.');
        redirect('bidang.php');
    }

    $idBidang = post_int('id');
    $sebelum = $idBidang === null ? null : db_one(
        'SELECT id, kode, nib, nis, nomor_daftar, status, dusun_id, blok_id, jenis_penggunaan_id, luas_m2, catatan
         FROM bidang WHERE id = ?',
        [$idBidang]
    );
    if ($sebelum === null) {
        flash_set('gagal', 'Bidang tidak ditemukan. Mungkin sudah dihapus.');
        redirect('bidang.php');
    }
    $kembali = 'bidang.php?id=' . (int) $sebelum['id'];

    try {
        if ($aksi === 'ubah') {
            $status = post_str('status');
            if (!isset(STATUS_BIDANG[$status])) {
                throw new RuntimeException('Status bidang tidak dikenal.');
            }
            $luas = post_float('luas_m2');
            if ($luas === null || $luas < 0 || $luas > 9999999999) {
                throw new RuntimeException('Luas bidang harus berupa angka meter persegi yang wajar.');
            }
            $nib = post_str('nib');
            if (len_utf8($nib) > 20) {
                throw new RuntimeException('NIB terlalu panjang (maksimal 20 karakter).');
            }
            $nis = post_str('nis');
            if (len_utf8($nis) > 120) {
                throw new RuntimeException('NIS terlalu panjang (maksimal 120 karakter).');
            }
            $nomorDaftar = post_str('nomor_daftar');
            if (len_utf8($nomorDaftar) > 20) {
                throw new RuntimeException('Nomor daftar terlalu panjang (maksimal 20 karakter).');
            }
            $catatan = post_str('catatan');
            if (len_utf8($catatan) > 5000) {
                throw new RuntimeException('Catatan terlalu panjang.');
            }

            $dusunId = post_int('dusun_id');
            if ($dusunId !== null && db_value('SELECT id FROM dusun WHERE id = ?', [$dusunId]) === null) {
                throw new RuntimeException('Dusun tidak ditemukan.');
            }
            $blokId = post_int('blok_id');
            if ($blokId !== null && db_value('SELECT id FROM blok WHERE id = ?', [$blokId]) === null) {
                throw new RuntimeException('Blok tidak ditemukan.');
            }
            $penggunaanId = post_int('jenis_penggunaan_id');
            if ($penggunaanId !== null
                && db_value('SELECT id FROM jenis_penggunaan WHERE id = ?', [$penggunaanId]) === null) {
                throw new RuntimeException('Jenis penggunaan tidak ditemukan.');
            }

            db_exec(
                'UPDATE bidang SET status = ?, nib = ?, nis = ?, nomor_daftar = ?, catatan = ?, luas_m2 = ?,
                        dusun_id = ?, blok_id = ?, jenis_penggunaan_id = ?
                 WHERE id = ?',
                [
                    $status,
                    $nib === '' ? null : $nib,
                    $nis === '' ? null : $nis,
                    $nomorDaftar === '' ? null : $nomorDaftar,
                    $catatan === '' ? null : $catatan,
                    $luas,
                    $dusunId,
                    $blokId,
                    $penggunaanId,
                    $sebelum['id'],
                ]
            );
            $sesudah = db_one(
                'SELECT id, kode, nib, nis, nomor_daftar, status, dusun_id, blok_id, jenis_penggunaan_id, luas_m2, catatan
                 FROM bidang WHERE id = ?',
                [$sebelum['id']]
            );
            audit('ubah', 'bidang', (string) $sebelum['id'], 'Perubahan data bidang ' . $sebelum['kode'],
                $sebelum, $sesudah);
            flash_set('berhasil', 'Data bidang ' . $sebelum['kode'] . ' diperbarui.');
            redirect($kembali);
        }

        if ($aksi === 'hak') {
            $jenisId = post_int('jenis_hak_id');
            $jenis = $jenisId === null ? null : db_one(
                'SELECT id, kode, nama FROM jenis_hak WHERE id = ? AND aktif = 1',
                [$jenisId]
            );
            if ($jenis === null) {
                throw new RuntimeException('Pilih jenis hak yang benar.');
            }
            $noSertifikat = post_str('no_sertifikat');
            if (len_utf8($noSertifikat) > 60) {
                throw new RuntimeException('Nomor sertifikat maksimal 60 karakter.');
            }
            $noHakPakai = post_str('no_hak_pakai');
            if (len_utf8($noHakPakai) > 100) {
                throw new RuntimeException('Nomor hak pakai maksimal 100 karakter.');
            }
            $pemegang = post_str('pemegang_nama');
            if (len_utf8($pemegang) > 150) {
                throw new RuntimeException('Nama pemegang maksimal 150 karakter.');
            }
            $diperoleh = post_str('diperoleh_pada');
            if ($diperoleh !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $diperoleh)) {
                throw new RuntimeException('Tanggal peroleh belum sesuai format (YYYY-MM-DD).');
            }
            $bukuCId = post_int('buku_c_id');
            if ($bukuCId !== null && db_value('SELECT id FROM buku_c WHERE id = ?', [$bukuCId]) === null) {
                throw new RuntimeException('Nomor Buku C tidak ditemukan.');
            }
            $catatan = post_str('catatan');

            $lama = db_one('SELECT * FROM hak WHERE bidang_id = ?', [$sebelum['id']]);
            $isi = [
                'jenis_hak_id'  => $jenis['id'],
                'no_sertifikat' => $noSertifikat === '' ? null : $noSertifikat,
                'no_hak_pakai'  => $noHakPakai === '' ? null : $noHakPakai,
                'buku_c_id'     => $bukuCId,
                'pemegang_nama' => $pemegang === '' ? null : $pemegang,
                'catatan'       => $catatan === '' ? null : $catatan,
                'diperoleh_pada' => $diperoleh === '' ? null : $diperoleh,
            ];
            if ($lama === null) {
                db_exec(
                    'INSERT INTO hak (bidang_id, jenis_hak_id, no_sertifikat, no_hak_pakai, buku_c_id,
                                      pemegang_nama, catatan, diperoleh_pada, dibuat_oleh, diubah_oleh)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    array_merge(
                        [$sebelum['id']],
                        array_values($isi),
                        [$pengguna['id'], $pengguna['id']]
                    )
                );
                audit('buat', 'hak', (string) $sebelum['id'],
                    'Hak ' . $jenis['nama'] . ' bidang ' . $sebelum['kode'], null, $isi);
                flash_set('berhasil', 'Hak atas bidang ' . $sebelum['kode'] . ' dicatat.');
            } else {
                db_exec(
                    'UPDATE hak SET jenis_hak_id = ?, no_sertifikat = ?, no_hak_pakai = ?, buku_c_id = ?,
                            pemegang_nama = ?, catatan = ?, diperoleh_pada = ?, diubah_oleh = ?
                     WHERE bidang_id = ?',
                    array_merge(array_values($isi), [$pengguna['id'], $sebelum['id']])
                );
                audit('ubah', 'hak', (string) $sebelum['id'],
                    'Perubahan hak bidang ' . $sebelum['kode'], $lama, $isi);
                flash_set('berhasil', 'Hak atas bidang ' . $sebelum['kode'] . ' diperbarui.');
            }
            redirect($kembali);
        }

        if ($aksi === 'hapus-hak') {
            $lama = db_one('SELECT * FROM hak WHERE bidang_id = ?', [$sebelum['id']]);
            if ($lama === null) {
                throw new RuntimeException('Bidang ini belum punya catatan hak.');
            }
            db_exec('DELETE FROM hak WHERE bidang_id = ?', [$sebelum['id']]);
            audit('hapus', 'hak', (string) $sebelum['id'],
                'Hapus catatan hak bidang ' . $sebelum['kode'], $lama, null);
            flash_set('berhasil', 'Catatan hak bidang ' . $sebelum['kode'] . ' dihapus.');
            redirect($kembali);
        }

        if ($aksi === 'pasang-nop') {
            $ketikan = post_str('nop_teks');
            $nop = normalkan_nop($ketikan !== '' ? $ketikan : post_str('nop'));
            if (!nop_valid($nop)) {
                throw new RuntimeException('NOP harus 18 digit dan diawali kode wilayah desa.');
            }
            $objek = db_one('SELECT nop, bidang_id, nama_wp FROM objek_pajak WHERE nop = ?', [$nop]);
            if ($objek === null) {
                throw new RuntimeException('NOP belum ada di daftar objek pajak. Impor SPPT lebih dulu.');
            }
            if ($objek['bidang_id'] !== null && (int) $objek['bidang_id'] !== (int) $sebelum['id']) {
                throw new RuntimeException('NOP ini sudah tertaut ke bidang lain (' .
                    (string) db_value('SELECT kode FROM bidang WHERE id = ?', [(int) $objek['bidang_id']]) . ').');
            }
            db_exec('UPDATE objek_pajak SET bidang_id = ? WHERE nop = ?', [$sebelum['id'], $nop]);
            audit('ubah', 'bidang', (string) $sebelum['id'],
                'Taut NOP ' . $nop . ' ke bidang ' . $sebelum['kode'], $objek,
                ['nop' => $nop, 'bidang_id' => $sebelum['id']]);
            flash_set('berhasil', 'NOP ' . $nop . ' tertaut ke bidang ' . $sebelum['kode'] . '.');
            redirect($kembali);
        }

        if ($aksi === 'lepas-nop') {
            $nop = post_str('nop');
            $objek = db_one('SELECT nop, bidang_id FROM objek_pajak WHERE nop = ? AND bidang_id = ?',
                [normalkan_nop($nop), $sebelum['id']]);
            if ($objek === null) {
                throw new RuntimeException('NOP itu tidak tertaut ke bidang ini.');
            }
            db_exec('UPDATE objek_pajak SET bidang_id = NULL WHERE nop = ?', [$objek['nop']]);
            audit('ubah', 'bidang', (string) $sebelum['id'],
                'Lepas NOP ' . $objek['nop'] . ' dari bidang ' . $sebelum['kode'], $objek, null);
            flash_set('berhasil', 'Tautan NOP dilepas.');
            redirect($kembali);
        }

        throw new RuntimeException('Aksi tidak dikenal.');
    } catch (Throwable $error) {
        flash_set('gagal', $error->getMessage());
        redirect($kembali);
    }
}

// ---------------------------------------------------------------------------
// Detail satu bidang
// ---------------------------------------------------------------------------
$idMinta = get_int('id');
if ($idMinta !== null) {
    $bidang = detail_bidang($idMinta);
    if ($bidang === null) {
        flash_set('gagal', 'Bidang dengan nomor ' . $idMinta . ' tidak ditemukan.');
        redirect('bidang.php');
    }

    $hak = $bidang['hak'][0] ?? null;
    $statusLabel = STATUS_BIDANG[$bidang['status']][0] ?? $bidang['status'];
    $statusKelas = STATUS_BIDANG[$bidang['status']][1] ?? 'lencana-abu';
    $daftarBlok = db_all('SELECT id, kode FROM blok ORDER BY kode');
    $adaGeometri = is_array($bidang['geojson']);
    $calonNop = $bolehUbah
        ? calon_nop_bidang((int) ($bidang['blok_id'] ?? 0), $hak['pemegang_nama'] ?? null)
        : [];

    sip_header('Bidang ' . $bidang['kode'], 'bidang', ['leaflet' => $adaGeometri]);
    sip_judul('Bidang tanah', $bidang['kode'], 'Detail satu bidang hasil digitasi, lengkap dengan hak, PBB, dan riwayatnya.');
    ?>

<div class="grid grid-2">
    <?php if ($adaGeometri): ?>
        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Letak bidang</h2><p>Batas poligon sesuai data digitasi</p></div></header>
            <div class="isi-kartu">
                <div id="petaDetail" class="peta-kecil"></div>
                <div class="baris-tombol jarak-atas">
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('peta.php', ['cari' => $bidang['kode']])) ?>">Buka di peta desa</a>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="kartu">
        <header class="kepala-kartu">
            <div><h2>Informasi bidang</h2><p>Sumber data: <?= e($bidang['sumber'] ?: 'master') ?></p></div>
            <span class="lencana <?= e($statusKelas) ?>"><?= e($statusLabel) ?></span>
        </header>
        <div class="isi-kartu">
            <dl class="aturan">
                <div class="baris-form">
                    <div>
                        <dt class="label-blok">NIB peta</dt><dd><?= e($bidang['nib'] ?: '—') ?></dd>
                        <dt class="label-blok">Nomor daftar 080201</dt><dd><?= e($bidang['nomor_daftar'] ?: '—') ?></dd>
                        <dt class="label-blok">NIS alas hak</dt><dd><?= e($bidang['nis'] ?: '—') ?></dd>
                        <dt class="label-blok">Tipe</dt><dd><?= e($bidang['tipe'] === 'tanah' ? 'Tanah' : 'Bangunan / lainnya') ?></dd>
                        <dt class="label-blok">Dusun</dt><dd><?= e($bidang['dusun'] ?: 'belum ditentukan') ?></dd>
                        <dt class="label-blok">Luas</dt><dd><?= e(angka((float) $bidang['luas_m2'], 2)) ?> m&sup2;</dd>
                    </div>
                    <div>
                        <dt class="label-blok">Blok</dt><dd><?= e($bidang['blok'] ?: '—') ?></dd>
                        <dt class="label-blok">Penggunaan</dt><dd><?= e($bidang['penggunaan'] ?: '—') ?></dd>
                        <dt class="label-blok">Dibuat</dt><dd><?= e(tanggal($bidang['dibuat_pada'])) ?></dd>
                        <dt class="label-blok">Diubah</dt><dd><?= e(tanggal($bidang['diubah_pada'], true)) ?></dd>
                    </div>
                </div>
            </dl>
            <?php if (!empty($bidang['catatan'])): ?>
                <p class="kecil redup"><?= e($bidang['catatan']) ?></p>
            <?php endif; ?>
        </div>
    </section>
</div>

<div class="grid grid-sidebar jarak-atas">
    <div>
        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>Hak atas tanah</h2><p>Satu catatan hak aktif untuk setiap bidang</p></div>
                <?php if ($bolehUbah): ?>
                    <div class="baris-tombol">
                        <?php if ($hak !== null): ?>
                            <form method="post" action="<?= e(sip_url('bidang.php')) ?>"
                                  data-konfirmasi="Hapus catatan hak pada bidang ini?">
                                <?= csrf_input() ?>
                                <input type="hidden" name="aksi" value="hapus-hak">
                                <input type="hidden" name="id" value="<?= e($bidang['id']) ?>">
                                <button class="tombol tombol-kecil tombol-bahaya" type="submit">Hapus hak</button>
                            </form>
                        <?php endif; ?>
                        <button class="tombol tombol-kecil" type="button" data-modal-buka="formHak">
                            <?= $hak === null ? 'Catat hak' : 'Ubah hak' ?>
                        </button>
                    </div>
                <?php endif; ?>
            </header>
            <div class="gulir-tabel">
                <table>
                    <thead>
                        <tr><th>Jenis hak</th><th>No. sertifikat</th><th>Pemegang</th><th>Diperoleh</th><th>Buku C</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($bidang['hak'] === []): ?>
                            <tr><td colspan="5" class="kosong">Belum ada catatan hak. Status hak bidang ini belum terverifikasi.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($bidang['hak'] as $h): ?>
                            <tr>
                                <td><span class="lencana <?= $h['hak_kode'] === 'HM' ? 'lencana-hijau' : 'lencana-abu' ?>"><?= e($h['hak_nama']) ?></span></td>
                                <td><?= e($h['no_sertifikat'] ?: ('Hak pakai ' . ($h['no_hak_pakai'] ?: '—'))) ?></td>
                                <td class="tebal"><?= e($h['pemegang_nama'] ?: '—') ?></td>
                                <td><?= e(tanggal($h['diperoleh_pada'])) ?></td>
                                <td>
                                    <?php if ($h['buku_c_nomor'] !== null): ?>
                                        <a href="<?= e(sip_url('buku-c.php', ['nomor' => $h['buku_c_nomor']])) ?>">C-<?= e($h['buku_c_nomor']) ?></a>
                                    <?php else: ?>
                                        <span class="redup">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>PBB atas bidang ini</h2><p>Ketetapan tahunan per NOP</p></div>
                <?php if ($bolehUbah): ?>
                    <button class="tombol tombol-kecil" type="button" data-modal-buka="formNop">Tautkan NOP</button>
                <?php endif; ?>
            </header>
            <div class="gulir-tabel">
                <table>
                    <thead>
                        <tr><th>NOP</th><th>Wajib pajak</th><th class="tengah">Tahun</th>
                            <th class="angka">Ketetapan</th><th class="angka">Terbayar</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($bidang['pajak'] === []): ?>
                            <tr><td colspan="6" class="kosong">Belum ada NOP yang tertaut ke bidang ini.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($bidang['pajak'] as $p): ?>
                            <tr>
                                <td class="nobreak"><a href="<?= e(sip_url('sppt.php', ['nop' => $p['nop']])) ?>"><?= e($p['nop']) ?></a></td>
                                <td><?= e(perpanjang((string) ($p['nama_wp'] ?: '—'), 34)) ?></td>
                                <td class="tengah"><?= e($p['tahun_pajak'] ?: '—') ?></td>
                                <td class="angka"><?= e(rupiah((float) $p['pajak_ditetapkan'])) ?></td>
                                <td class="angka"><?= e(rupiah((float) $p['terbayar'])) ?></td>
                                <td>
                                    <?php if ($p['tahun_pajak'] !== null): ?>
                                        <span class="lencana <?= $p['status_bayar'] === 'Lunas' ? 'lencana-hijau' : ($p['status_bayar'] === 'Sebagian' ? 'lencana-kuning' : 'lencana-merah') ?>"><?= e($p['status_bayar']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="isi-kartu">
                <p class="kecil redup">NOP yang tertaut:
                    <?= $bidang['pajak'] === [] ? '<em>belum ada</em>' : e($bidang['pajak'][0]['nop']) ?>
                    <?php if ($bolehUbah && $bidang['pajak'] !== []): ?>
                        <form method="post" action="<?= e(sip_url('bidang.php')) ?>" style="display:inline"
                              data-konfirmasi="Lepas tautan NOP ini dari bidang?">
                            <?= csrf_input() ?>
                            <input type="hidden" name="aksi" value="lepas-nop">
                            <input type="hidden" name="id" value="<?= e($bidang['id']) ?>">
                            <input type="hidden" name="nop" value="<?= e($bidang['pajak'][0]['nop']) ?>">
                            <button class="tombol tombol-kecil" type="submit">Lepas</button>
                        </form>
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Riwayat peralihan</h2><p>Sepuluh peralihan terakhir terkait bidang ini</p></div></header>
            <div class="gulir-tabel">
                <table>
                    <thead><tr><th>Kode</th><th>Jenis</th><th>Dari</th><th>Ke</th><th>Tanggal</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if ($bidang['peralihan'] === []): ?>
                            <tr><td colspan="6" class="kosong">Tidak ada riwayat peralihan.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($bidang['peralihan'] as $p): ?>
                            <tr>
                                <td><a href="<?= e(sip_url('peralihan.php', ['id' => $p['id']])) ?>"><?= e($p['kode']) ?></a></td>
                                <td><?= e($p['jenis']) ?></td>
                                <td><?= e(perpanjang((string) $p['pihak_pertama'], 26)) ?></td>
                                <td><?= e(perpanjang((string) $p['pihak_kedua'], 26)) ?></td>
                                <td class="nobreak"><?= e(tanggal($p['tanggal'])) ?></td>
                                <td><span class="lencana <?= $p['status'] === 'Selesai' ? 'lencana-hijau' : ($p['status'] === 'Ditolak' ? 'lencana-merah' : 'lencana-kuning') ?>"><?= e($p['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div>
        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Buku C Desa</h2><p>Persil yang merujuk bidang ini</p></div></header>
            <div class="isi-kartu">
                <?php if ($bidang['buku_c'] === []): ?>
                    <p class="kosong">Belum ada Buku C yang tertaut.</p>
                <?php else: ?>
                    <ul class="daftar-kecil">
                        <?php foreach ($bidang['buku_c'] as $c): ?>
                            <li>
                                <a href="<?= e(sip_url('buku-c.php', ['id' => $c['id']])) ?>">C-<?= e($c['nomor']) ?> (<?= e($c['jenis']) ?>)</a>
                                <span class="rapek-kanan redup"><?= e($c['nama_pemegang'] ?: '—') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>

        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Laporan &amp; sengketa</h2><p>Pelaporan yang menandai bidang ini</p></div></header>
            <div class="isi-kartu">
                <?php if ($bidang['laporan'] === []): ?>
                    <p class="kosong">Tidak ada laporan terkait.</p>
                <?php else: ?>
                    <ul class="daftar-kecil">
                        <?php foreach ($bidang['laporan'] as $l): ?>
                            <li>
                                <a href="<?= e(sip_url('laporan.php', ['id' => $l['id']])) ?>"><?= e($l['nomor']) ?></a>
                                <span class="redup"><?= e($l['kategori']) ?></span>
                                <span class="rapek-kanan lencana <?= $l['status'] === 'Selesai' ? 'lencana-hijau' : 'lencana-kuning' ?>"><?= e($l['status']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($bolehUbah): ?>
            <section class="kartu">
                <header class="kepala-kartu"><div><h2>Ubah data bidang</h2><p>Setiap perubahan tercatat di log aktivitas</p></div></header>
                <div class="isi-kartu">
                    <form method="post" action="<?= e(sip_url('bidang.php')) ?>">
                        <?= csrf_input() ?>
                        <input type="hidden" name="aksi" value="ubah">
                        <input type="hidden" name="id" value="<?= e($bidang['id']) ?>">
                        <div class="lapangan">
                            <label for="f-status">Status</label>
                            <select id="f-status" name="status">
                                <?php foreach (STATUS_BIDANG as $kode => [$label]): ?>
                                    <option value="<?= e($kode) ?>" <?= $bidang['status'] === $kode ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="baris-form">
                            <div class="lapangan">
                                <label for="f-dusun">Dusun</label>
                                <select id="f-dusun" name="dusun_id">
                                    <option value="">Belum ditentukan</option>
                                    <?php foreach (daftar_dusun() as $d): ?>
                                        <option value="<?= e($d['id']) ?>" <?= (int) $bidang['dusun_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['nama']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="lapangan">
                                <label for="f-blok">Blok</label>
                                <select id="f-blok" name="blok_id">
                                    <option value="">Belum ada blok</option>
                                    <?php foreach ($daftarBlok as $b): ?>
                                        <option value="<?= e($b['id']) ?>" <?= (int) $bidang['blok_id'] === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['kode']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="baris-form">
                            <div class="lapangan">
                                <label for="f-penggunaan">Jenis penggunaan</label>
                                <select id="f-penggunaan" name="jenis_penggunaan_id">
                                    <option value="">Belum ditetapkan</option>
                                    <?php foreach (daftar_jenis_penggunaan() as $j): ?>
                                        <option value="<?= e($j['id']) ?>" <?= (int) $bidang['jenis_penggunaan_id'] === (int) $j['id'] ? 'selected' : '' ?>><?= e($j['nama']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="lapangan">
                                <label for="f-luas">Luas (m&sup2;)</label>
                                <input id="f-luas" name="luas_m2" type="number" step="0.01" min="0"
                                       value="<?= e($bidang['luas_m2'] === null ? '' : (string) $bidang['luas_m2']) ?>">
                            </div>
                        </div>
                        <div class="baris-form">
                            <div class="lapangan">
                                <label for="f-nib">NIB pada peta</label>
                                <input id="f-nib" name="nib" maxlength="20" value="<?= e($bidang['nib']) ?>">
                            </div>
                            <div class="lapangan">
                                <label for="f-nomor-daftar">Nomor daftar lapisan 080201</label>
                                <input id="f-nomor-daftar" name="nomor_daftar" maxlength="20"
                                       value="<?= e($bidang['nomor_daftar']) ?>">
                            </div>
                            <div class="lapangan">
                                <label for="f-nis">NIS alas hak pada peta</label>
                                <input id="f-nis" name="nis" maxlength="120" value="<?= e($bidang['nis']) ?>"
                                       placeholder="mis. 204B, beberapa dipisah &quot; / &quot;">
                            </div>
                        </div>
                        <div class="lapangan">
                            <label for="f-catatan">Catatan</label>
                            <textarea id="f-catatan" name="catatan"><?= e($bidang['catatan']) ?></textarea>
                        </div>
                        <button class="tombol tombol-utama" type="submit">Simpan perubahan</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php if ($bolehUbah): ?>
    <div class="modal-latar" id="formHak">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="formHakJudul">
            <form method="post" action="<?= e(sip_url('bidang.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="aksi" value="hak">
                <input type="hidden" name="id" value="<?= e($bidang['id']) ?>">
                <div class="modal-kepala">
                    <h2 id="formHakJudul">Hak atas bidang <?= e($bidang['kode']) ?></h2>
                    <button class="modal-tutup" type="button" data-modal-tutup="formHak" aria-label="Tutup">&times;</button>
                </div>
                <div class="modal-badan">
                    <div class="baris-form">
                        <div class="lapangan">
                            <label for="h-jenis">Jenis hak <span class="redup">*</span></label>
                            <select id="h-jenis" name="jenis_hak_id" required>
                                <?php foreach (daftar_jenis_hak() as $j): ?>
                                    <option value="<?= e($j['id']) ?>" <?= ($hak['jenis_hak_id'] ?? null) === $j['id'] ? 'selected' : '' ?>>
                                        <?= e($j['kode'] . ' — ' . $j['nama']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="lapangan">
                            <label for="h-buku">Nomor Buku C</label>
                            <input id="h-buku" name="buku_c_id" type="number" min="1" value="<?= e($hak['buku_c_id'] ?? '') ?>">
                            <p class="petunjuk">Kosongkan bila bidang belum masuk Buku C Desa.</p>
                        </div>
                    </div>
                    <div class="baris-form">
                        <div class="lapangan">
                            <label for="h-sertifikat">Nomor sertifikat</label>
                            <input id="h-sertifikat" name="no_sertifikat" maxlength="60" value="<?= e($hak['no_sertifikat'] ?? '') ?>">
                        </div>
                        <div class="lapangan">
                            <label for="h-pakai">Nomor hak pakai</label>
                            <input id="h-pakai" name="no_hak_pakai" maxlength="100" value="<?= e($hak['no_hak_pakai'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="baris-form">
                        <div class="lapangan">
                            <label for="h-pemegang">Nama pemegang</label>
                            <input id="h-pemegang" name="pemegang_nama" maxlength="150" value="<?= e($hak['pemegang_nama'] ?? '') ?>">
                        </div>
                        <div class="lapangan">
                            <label for="h-tanggal">Tanggal diperoleh</label>
                            <input id="h-tanggal" name="diperoleh_pada" type="date" value="<?= e($hak['diperoleh_pada'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="lapangan">
                        <label for="h-catatan">Catatan</label>
                        <textarea id="h-catatan" name="catatan"><?= e($hak['catatan'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="modal-kaki">
                    <button class="tombol" type="button" data-modal-tutup="formHak">Batal</button>
                    <button class="tombol tombol-utama" type="submit">Simpan hak</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-latar" id="formNop">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="formNopJudul">
            <form method="post" action="<?= e(sip_url('bidang.php')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="aksi" value="pasang-nop">
                <input type="hidden" name="id" value="<?= e($bidang['id']) ?>">
                <div class="modal-kepala">
                    <h2 id="formNopJudul">Tautkan NOP ke bidang <?= e($bidang['kode']) ?></h2>
                    <button class="modal-tutup" type="button" data-modal-tutup="formNop" aria-label="Tutup">&times;</button>
                </div>
                <div class="modal-badan">
                    <?php if ($calonNop !== []): ?>
                        <p class="petunjuk">
                            <?php if (($hak['pemegang_nama'] ?? '') !== ''): ?>
                                Nama pemegang di peta: <strong><?= e($hak['pemegang_nama']) ?></strong>.
                                Calon di bawah diurutkan menurut kemiripan nama itu dengan wajib pajak seblok
                                (angka 3 = persis, 2 = termuat, 1 = satu unsur sama).
                            <?php else: ?>
                                Bidang ini belum punya nama pemegang, jadi daftar ini hanya semua objek pajak
                                seblok yang belum tertaut.
                            <?php endif; ?>
                            Satu nama bisa dipakai beberapa orang, jadi urutan hanya saran.
                            Pilih satu baris, atau isi NOP sendiri di bawah.
                        </p>
                        <div class="gulir-tabel">
                            <table>
                                <thead>
                                    <tr><th></th><th>NOP</th><th class="tengah">Persil</th>
                                        <th>Wajib pajak</th><th class="tengah">Nama</th><th class="angka">Ketetapan</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($calonNop as $c): ?>
                                        <tr>
                                            <td>
                                                <input type="radio" name="nop" value="<?= e($c['nop']) ?>"
                                                       aria-label="Pilih NOP <?= e($c['nop']) ?>">
                                            </td>
                                            <td class="nobreak"><?= e($c['nop']) ?></td>
                                            <td class="tengah redup"><?= e($c['persil'] . '-' . $c['urut']) ?></td>
                                            <td><?= e(perpanjang((string) ($c['nama_wp'] ?: '—'), 24)) ?></td>
                                            <td class="tengah">
                                                <?php if ((int) $c['skor'] >= 2): ?>
                                                    <span class="lencana lencana-hijau"><?= (int) $c['skor'] ?></span>
                                                <?php elseif ((int) $c['skor'] === 1): ?>
                                                    <span class="lencana lencana-abu">1</span>
                                                <?php else: ?>
                                                    <span class="redup">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="angka"><?= e(rupiah((float) $c['pajak_ditetapkan'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <div class="lapangan jarak-atas">
                        <label for="n-nop">NOP 18 digit</label>
                        <input id="n-nop" name="nop_teks" maxlength="24"
                               placeholder="<?= e(setelan('kode_wilayah_nop', '3324040008')) ?>0001.0">
                        <p class="petunjuk">Titik dan spasi diabaikan. NOP harus sudah ada di data SPPT yang diimpor.</p>
                    </div>
                </div>
                <div class="modal-kaki">
                    <button class="tombol" type="button" data-modal-tutup="formNop">Batal</button>
                    <button class="tombol tombol-utama" type="submit">Tautkan</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

    <?php
    $kaki = '';
    if ($adaGeometri) {
        $kaki = <<<'JS'
<script>
(function () {
    const geometri = SIP.data.geometri;
    if (!geometri || typeof L === 'undefined') return;
    const peta = L.map('petaDetail', { zoomControl: true, attributionControl: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 20 }).addTo(peta);
    const lapisan = L.geoJSON({ type: 'FeatureCollection', features: [{ type: 'Feature', geometry: geometri, properties: {} }] }, {
        style: { color: '#1f6b50', weight: 2, fillColor: '#1f6b50', fillOpacity: .18 }
    }).addTo(peta);
    peta.fitBounds(lapisan.getBounds(), { padding: [18, 18] });
})();
</script>
JS;
    }
    sip_footer(['leaflet' => $adaGeometri, 'data' => ['geometri' => $bidang['geojson']], 'kaki' => $kaki]);
    return;
}

// ---------------------------------------------------------------------------
// Daftar bidang
// ---------------------------------------------------------------------------
$query = [
    'cari'   => get_str('cari'),
    'blok'   => get_str('blok'),
    'dusun'  => get_str('dusun'),
    'hak'    => get_str('hak'),
    'status' => get_str('status'),
    'tipe'   => get_str('tipe', 'tanah'),
];
if ($query['status'] !== '' && !isset(STATUS_BIDANG[$query['status']])) {
    $query['status'] = '';
}
if (!in_array($query['tipe'], ['semua', 'tanah', 'lain'], true)) {
    $query['tipe'] = 'tanah';
}
[$halaman, $perHalaman] = batas_halaman(get_int('halaman'), get_int('per_halaman'), 100);
$queryTautan = array_filter($query, static fn($v) => $v !== '');

if (get_str('ekspor') === 'csv') {
    [$where, $param] = bidang_filter($query);
    $joinEkspor = '
        FROM bidang b
        LEFT JOIN blok bl              ON bl.id = b.blok_id
        LEFT JOIN dusun du             ON du.id = b.dusun_id
        LEFT JOIN jenis_penggunaan jp  ON jp.id = b.jenis_penggunaan_id
        LEFT JOIN hak h                ON h.bidang_id = b.id
        LEFT JOIN jenis_hak jh         ON jh.id = h.jenis_hak_id
        LEFT JOIN (
            SELECT bidang_id,
                   GROUP_CONCAT(DISTINCT nama_wp ORDER BY nama_wp SEPARATOR " / ") AS nama_wp,
                   GROUP_CONCAT(DISTINCT nop ORDER BY nop SEPARATOR " / ") AS nop
            FROM objek_pajak
            WHERE bidang_id IS NOT NULL
            GROUP BY bidang_id
        ) o ON o.bidang_id = b.id';
    $jumlahEkspor = (int) db_value('SELECT COUNT(DISTINCT b.id) ' . $joinEkspor . ' ' . $where, $param);
    $baris = db_query(
        'SELECT b.kode, b.tipe, b.status, bl.kode AS blok, du.nama AS dusun,
                jp.nama AS penggunaan, b.luas_m2, b.nib, b.nis, b.nomor_daftar,
                jh.kode AS jenis_hak, h.pemegang_nama, h.no_sertifikat, h.no_hak_pakai,
                o.nama_wp, o.nop,
                (SELECT s.status_bayar FROM sppt s
                  JOIN objek_pajak op ON op.nop = s.nop
                  WHERE op.bidang_id = b.id
                  ORDER BY s.tahun_pajak DESC LIMIT 1) AS status_bayar,
                b.pusat_lat, b.pusat_lon, b.sumber, b.catatan, ST_AsText(b.geom) AS geometri_wkt
         ' . $joinEkspor . ' ' . $where . '
         ORDER BY b.kode LIMIT 50000',
        $param
    );
    audit('ekspor', 'bidang', 'hasil-digitasi', $jumlahEkspor . ' bidang diunduh sebagai CSV');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bidang-digitasi-' . date('Ymd-Hi') . '.csv"');
    header('Cache-Control: no-store');
    $keluaran = fopen('php://output', 'wb');
    fwrite($keluaran, "\xEF\xBB\xBF");
    fputcsv($keluaran, [
        'Kode', 'Tipe', 'Status', 'Blok', 'Dusun', 'Penggunaan', 'Luas m2',
        'NIB', 'NIS', 'Nomor daftar 080201', 'Jenis hak', 'Pemegang',
        'Nomor sertifikat', 'Nomor hak pakai', 'Wajib pajak', 'NOP', 'Status PBB',
        'Lintang', 'Bujur', 'Sumber data', 'Catatan', 'Geometri WKT',
    ], ';');
    $kolomTeks = [0, 1, 2, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 19, 20, 21];
    while ($baris !== false && ($row = $baris->fetch(PDO::FETCH_NUM)) !== false) {
        foreach ($kolomTeks as $index) {
            if (is_string($row[$index]) && preg_match('/^\s*[=+\-@\t\r]/', $row[$index])) {
                $row[$index] = "'" . $row[$index];
            }
        }
        fputcsv($keluaran, $row, ';');
    }
    fclose($keluaran);
    exit;
}

$daftar = daftar_bidang($query, $halaman, $perHalaman);
$daftarBlok = db_all('SELECT kode FROM blok ORDER BY nomor, kode');
$hakTersedia = daftar_jenis_hak();
$jumlahDigitasi = $pengguna['peran'] === PERAN_SUPER
    ? (int) db_value("SELECT COUNT(*) FROM bidang WHERE sumber = 'csv-digitasi'")
    : 0;
$jumlahTanah = $pengguna['peran'] === PERAN_SUPER
    ? (int) db_value("SELECT COUNT(*) FROM bidang WHERE tipe = 'tanah'")
    : 0;

sip_header('Bidang tanah', 'bidang');
sip_judul('Bidang tanah', 'Daftar bidang hasil digitasi',
    'Pencarian mencakup kode bidang, NIB peta, nama pemegang hak, nama wajib pajak, dan NOP.');
?>

<section class="kartu">
    <header class="kepala-kartu">
        <div><h2>Saringan</h2><p><?= e(angka($daftar['total'])) ?> bidang cocok dengan kondisi ini</p></div>
        <div class="baris-tombol">
            <a class="tombol tombol-kecil" href="<?= e(sip_url('bidang.php', array_merge($queryTautan, ['ekspor' => 'csv']))) ?>">Ekspor CSV</a>
            <a class="tombol tombol-kecil" href="<?= e(sip_url('peta.php', $queryTautan)) ?>">Lihat di peta</a>
            <a class="tombol tombol-kecil" href="<?= e(sip_url('bidang.php')) ?>">Bersihkan</a>
        </div>
    </header>
    <div class="isi-kartu">
        <form class="formulir-alat" method="get" action="<?= e(sip_url('bidang.php')) ?>">
            <div class="lapangan">
                <label class="label-blok" for="s-cari">Cari</label>
                <input id="s-cari" name="cari" maxlength="60" value="<?= e($query['cari']) ?>" placeholder="nama, kode, NIB, atau NOP">
            </div>
            <div class="lapangan">
                <label class="label-blok" for="s-blok">Blok</label>
                <select id="s-blok" name="blok">
                    <option value="">Semua blok</option>
                    <?php foreach ($daftarBlok as $b): ?>
                        <option value="<?= e($b['kode']) ?>" <?= $query['blok'] === $b['kode'] ? 'selected' : '' ?>><?= e($b['kode']) ?></option>
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
                <label class="label-blok" for="s-hak">Jenis hak</label>
                <select id="s-hak" name="hak">
                    <option value="">Semua hak</option>
                    <?php foreach ($hakTersedia as $j): ?>
                        <option value="<?= e($j['kode']) ?>" <?= $query['hak'] === $j['kode'] ? 'selected' : '' ?>><?= e($j['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="s-status">Status</label>
                <select id="s-status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach (STATUS_BIDANG as $kode => [$label]): ?>
                        <option value="<?= e($kode) ?>" <?= $query['status'] === $kode ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="s-tipe">Tipe</label>
                <select id="s-tipe" name="tipe">
                    <option value="semua" <?= $query['tipe'] === 'semua' ? 'selected' : '' ?>>Tanah &amp; lainnya</option>
                    <option value="tanah" <?= $query['tipe'] === 'tanah' ? 'selected' : '' ?>>Tanah saja</option>
                    <option value="lain" <?= $query['tipe'] === 'lain' ? 'selected' : '' ?>>Bangunan / lainnya</option>
                </select>
            </div>
            <div class="lapangan" style="flex:0 0 auto">
                <button class="tombol tombol-utama" type="submit">Terapkan</button>
            </div>
        </form>
    </div>
</section>

<section class="kartu jarak-atas">
    <header class="kepala-kartu">
        <div><h2>Bidang tanah</h2><p>Halaman <?= e($halaman) ?> &middot; <?= e($perHalaman) ?> baris per halaman</p></div>
        <?php if ($pengguna['peran'] === PERAN_SUPER): ?>
            <form method="post" action="<?= e(sip_url('bidang.php')) ?>"
                  class="baris-tombol"
                  data-konfirmasi="Impor berkas GeoJSON yang dipilih tanpa menghapus data yang ada? Kode bidang yang sudah ada akan dilewati.">
                <?= csrf_input() ?>
                <input type="hidden" name="aksi" value="impor-bidang-tanah">
                <select class="pilih-kecil" name="berkas_bidang" aria-label="Berkas GeoJSON bidang yang akan diimpor">
                    <option value="tanah">bidang_tanah.geojson</option>
                    <option value="lain">bidang_lain.geojson</option>
                </select>
                <button class="tombol tombol-kecil" type="submit">Impor Bidang tanah</button>
            </form>
            <form method="post" action="<?= e(sip_url('bidang.php')) ?>" class="baris-tombol"
                  data-konfirmasi="Hapus semua bidang dari jenis yang dipilih? Catatan hak ikut terhapus; tautan laporan, peralihan, Buku C, dan objek pajak akan dilepas. Tindakan ini tidak dapat dibatalkan.">
                <?= csrf_input() ?>
                <input type="hidden" name="aksi" value="hapus-semua-jenis">
                <select class="pilih-kecil" id="jenis-hapus-bidang" name="jenis_hapus"
                    aria-label="Jenis bidang yang dihapus">
                    <option value="digitasi">Daftar bidang hasil digitasi (<?= e(angka($jumlahDigitasi)) ?>)</option>
                    <option value="tanah">Bidang tanah (<?= e(angka($jumlahTanah)) ?>)</option>
                </select>
                <button class="tombol tombol-kecil tombol-bahaya" type="submit"
                    <?= $jumlahDigitasi === 0 && $jumlahTanah === 0 ? 'disabled' : '' ?>>
                    Hapus semua
                </button>
            </form>
        <?php endif; ?>
    </header>
    <div class="gulir-tabel">
        <table>
            <thead>
                <tr>
                    <th>Kode</th><th>Pemegang / wajib pajak</th><th>Hak</th><th>Blok</th><th>Dusun</th>
                    <th class="angka">Luas (m&sup2;)</th><th>NOP</th><th class="tengah">PBB</th><th class="tengah">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($daftar['items'] === []): ?>
                    <tr><td colspan="9" class="kosong"><strong>Tidak ada bidang yang cocok.</strong>Ubah kata kunci atau bersihkan saringan.</td></tr>
                <?php endif; ?>
                <?php foreach ($daftar['items'] as $b):
                    $statusKelas = STATUS_BIDANG[$b['status']][1] ?? 'lencana-abu'; ?>
                    <tr>
                        <td class="nobreak">
                            <a class="tebal" href="<?= e(sip_url('bidang.php', ['id' => $b['id']])) ?>"><?= e($b['kode']) ?></a>
                            <?php if ($b['nib'] !== null): ?><div class="kecil redup"><?= e($b['nib']) ?></div><?php endif; ?>
                            <?php if ($b['nis'] !== null): ?><div class="kecil redup">NIS <?= e($b['nis']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?= e(perpanjang((string) ($b['pemegang_nama'] ?: ($b['nama_wp'] ?: '—')), 34)) ?>
                            <div class="kecil redup"><?= e(perpanjang((string) ($b['penggunaan'] ?: 'belum ditetapkan'), 30)) ?></div>
                        </td>
                        <td>
                            <?php if ($b['hak_nama'] !== null): ?>
                                <span class="lencana <?= $b['hak_kode'] === 'HM' ? 'lencana-hijau' : 'lencana-abu' ?>"><?= e($b['hak_kode']) ?></span>
                            <?php else: ?>
                                <span class="lencana lencana-merah">Belum ada</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($b['blok'] ?: '—') ?></td>
                        <td><?= e($b['dusun'] ?: '—') ?></td>
                        <td class="angka"><?= e(angka((float) $b['luas_m2'], 2)) ?></td>
                        <td class="nobreak"><?= e($b['nop'] ?: '—') ?></td>
                        <td class="tengah">
                            <?php if ($b['status_bayar'] !== null): ?>
                                <span class="lencana <?= $b['status_bayar'] === 'Lunas' ? 'lencana-hijau' : 'lencana-merah' ?>"><?= e($b['status_bayar'] === 'Lunas' ? 'Lunas' : 'Belum') ?></span>
                            <?php else: ?><span class="redup">—</span><?php endif; ?>
                        </td>
                        <td class="tengah"><span class="lencana <?= e($statusKelas) ?>"><?= e($b['status'] === 'perlu_verifikasi' ? 'Verifikasi' : ucfirst((string) $b['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginasi_html($halaman, $daftar['total'], $perHalaman, $queryTautan, 'bidang.php') ?>
</section>

    <?php
    sip_footer();
