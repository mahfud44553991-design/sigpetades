<?php
declare(strict_types=1);

/**
 * Indeks Buku C Desa: daftar persil hasil penyalinan buku, lengkap dengan
 * tautan ke hasil pindaiannya (lewat dokumen.php, bukan path publik).
 *
 *   buku-c.php                     -> daftar + saringan
 *   buku-c.php?id=142              -> detail satu entri
 *   buku-c.php?nomor=142           -> detail lewat nomor C
 *   buku-c.php?ekspor=csv&...      -> unduh indeks sebagai CSV
 */

require __DIR__ . '/app/bootstrap.php';

$pengguna = wajib_masuk();
$bolehUbah = boleh_kelola();

const JENIS_BUKU_C = ['C-1', 'C-2', 'C-3'];

$query = [
    'cari'   => get_str('cari'),
    'dusun'  => get_str('dusun'),
    'nomor'  => get_str('nomor'),
    'jenis'  => get_str('jenis'),
    'tanpa_pemegang' => get_str('tanpa_pemegang'),
];
if (!in_array($query['jenis'], JENIS_BUKU_C, true)) {
    $query['jenis'] = '';
}
$queryTautan = array_filter($query, static fn($v) => $v !== '');

// ---------------------------------------------------------------------------
// Unduh indeks
// ---------------------------------------------------------------------------
if (get_str('ekspor') === 'csv') {
    [$where, $param] = buku_c_filter($query);
    $baris = db_all(
        "SELECT bc.nomor, bc.jenis, bc.nama_pemegang, bc.nomor_persil, bc.luas_m2, bc.tahun_daftar,
                du.nama AS dusun, b.kode AS bidang_kode, bc.berkas_pdf, bc.catatan
         FROM buku_c bc
         LEFT JOIN dusun du ON du.id = bc.dusun_id
         LEFT JOIN bidang b ON b.id = bc.bidang_id
         {$where}
         ORDER BY bc.nomor LIMIT 20000",
        $param
    );
    audit('ekspor', 'buku_c', '', count($baris) . ' baris indeks Buku C diunduh sebagai CSV');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="buku-c-' . date('Ymd-Hi') . '.csv"');
    header('Cache-Control: no-store');
    $keluaran = fopen('php://output', 'wb');
    fwrite($keluaran, "\xEF\xBB\xBF");
    fputcsv($keluaran, ['Nomor C', 'Jenis', 'Pemegang', 'Persil', 'Luas m2', 'Tahun daftar',
        'Dusun', 'Bidang', 'Berkas', 'Catatan'], ';');
    foreach ($baris as $b) {
        fputcsv($keluaran, array_values($b), ';');
    }
    fclose($keluaran);
    exit;
}

// ---------------------------------------------------------------------------
// Perubahan data (pengelola saja)
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wajib_masuk(PERAN_PENGELOLA);
    wajib_csrf();

    $id = post_int('id');
    $lama = $id === null ? null : db_one('SELECT * FROM buku_c WHERE id = ?', [$id]);
    if ($lama === null) {
        flash_set('gagal', 'Entri Buku C tidak ditemukan.');
        redirect('buku-c.php');
    }
    $kembali = 'buku-c.php?id=' . (int) $lama['id'];

    try {
        if (post_str('aksi') !== 'ubah') {
            throw new RuntimeException('Aksi tidak dikenal.');
        }
        $nama = post_str('nama_pemegang');
        if (len_utf8($nama) > 150) {
            throw new RuntimeException('Nama pemegang maksimal 150 karakter.');
        }
        $persil = post_str('nomor_persil');
        if (len_utf8($persil) > 40) {
            throw new RuntimeException('Nomor persil maksimal 40 karakter.');
        }
        $jenis = post_str('jenis');
        if (!in_array($jenis, JENIS_BUKU_C, true)) {
            throw new RuntimeException('Jenis Buku C tidak dikenal.');
        }
        $luas = post_float('luas_m2');
        if ($luas !== null && ($luas < 0 || $luas > 9999999999)) {
            throw new RuntimeException('Luas tidak wajar.');
        }
        $tahunDaftar = post_int('tahun_daftar');
        if ($tahunDaftar !== null && ($tahunDaftar < 1900 || $tahunDaftar > (int) date('Y') + 1)) {
            throw new RuntimeException('Tahun daftar harus antara 1900 dan tahun depan.');
        }
        $dusunId = post_int('dusun_id');
        if ($dusunId !== null && db_value('SELECT id FROM dusun WHERE id = ?', [$dusunId]) === null) {
            throw new RuntimeException('Dusun tidak ditemukan.');
        }
        $bidangId = post_int('bidang_id');
        if ($bidangId !== null && db_value('SELECT id FROM bidang WHERE id = ?', [$bidangId]) === null) {
            throw new RuntimeException('Bidang tidak ditemukan.');
        }

        $isi = [
            'jenis' => $jenis,
            'nama_pemegang' => $nama,
            'nomor_persil' => $persil === '' ? null : $persil,
            'luas_m2' => $luas,
            'tahun_daftar' => $tahunDaftar,
            'dusun_id' => $dusunId,
            'bidang_id' => $bidangId,
            'catatan' => post_str('catatan') ?: null,
        ];
        db_exec(
            'UPDATE buku_c SET jenis = ?, nama_pemegang = ?, nomor_persil = ?, luas_m2 = ?,
                    tahun_daftar = ?, dusun_id = ?, bidang_id = ?, catatan = ?
             WHERE id = ?',
            array_merge(array_values($isi), [$lama['id']])
        );
        audit('ubah', 'buku_c', (string) $lama['id'], 'Perubahan Buku C-' . $lama['nomor'], $lama, $isi);
        flash_set('berhasil', 'Buku C-' . $lama['nomor'] . ' diperbarui.');
        redirect($kembali);
    } catch (Throwable $error) {
        flash_set('gagal', $error->getMessage());
        redirect($kembali);
    }
}

// ---------------------------------------------------------------------------
// Detail satu entri
// ---------------------------------------------------------------------------
$idMinta = get_int('id');
if ($idMinta === null && $query['nomor'] !== '') {
    $idMinta = (int) db_value('SELECT id FROM buku_c WHERE nomor = ?', [(int) $query['nomor']]);
    $idMinta = $idMinta > 0 ? $idMinta : null;
}
if ($idMinta !== null) {
    $c = detail_buku_c((int) $idMinta);
    if ($c === null) {
        flash_set('gagal', 'Entri Buku C dengan nomor itu belum terindeks.');
        redirect('buku-c.php');
    }

    sip_header('Buku C-' . $c['nomor'], 'buku-c');
    sip_judul('Buku C ' . $c['jenis'], 'C-' . $c['nomor'],
        $c['nama_pemegang'] !== '' ? $c['nama_pemegang'] : 'Lembar ini belum memuat nama pemegang.');
    ?>

<div class="grid grid-2">
    <section class="kartu">
        <header class="kepala-kartu"><div><h2>Isi lembar</h2><p>Sesuai hasil penyalinan buku C desa</p></div></header>
        <div class="isi-kartu">
            <dl class="aturan">
                <div class="baris-form">
                    <div>
                        <dt class="label-blok">Nomor C</dt><dd><?= e($c['nomor']) ?></dd>
                        <dt class="label-blok">Jenis</dt><dd><?= e($c['jenis']) ?></dd>
                        <dt class="label-blok">Pemegang</dt><dd><?= e($c['nama_pemegang'] ?: '—') ?></dd>
                        <dt class="label-blok">Persil</dt><dd><?= e($c['nomor_persil'] ?: '—') ?></dd>
                    </div>
                    <div>
                        <dt class="label-blok">Luas tercatat</dt><dd><?= e($c['luas_m2'] === null ? '—' : angka((float) $c['luas_m2'], 2) . ' m&sup2;') ?></dd>
                        <dt class="label-blok">Tahun daftar</dt><dd><?= e($c['tahun_daftar'] ?: '—') ?></dd>
                        <dt class="label-blok">Dusun</dt><dd><?= e($c['dusun'] ?: '—') ?></dd>
                        <dt class="label-blok">Bidang tertaut</dt>
                        <dd>
                            <?php if ($c['bidang_id'] !== null): ?>
                                <a href="<?= e(sip_url('bidang.php', ['id' => $c['bidang_id']])) ?>"><?= e($c['bidang_kode']) ?></a>
                            <?php else: ?><span class="redup">belum ditautkan</span><?php endif; ?>
                        </dd>
                    </div>
                </div>
            </dl>
            <?php if (!empty($c['catatan'])): ?>
                <p class="kecil redup"><?= e($c['catatan']) ?></p>
            <?php endif; ?>
            <div class="baris-tombol jarak-atas">
                <?php if (!empty($c['berkas_pdf'])): ?>
                    <a class="tombol tombol-utama" href="<?= e(sip_url('dokumen.php', ['buku-c' => $c['id']])) ?>" target="_blank" rel="noopener">
                        Buka hasil pindaian
                    </a>
                <?php else: ?>
                    <span class="redup kecil">Berkas pindaian belum tersedia untuk nomor ini.</span>
                <?php endif; ?>
                <a class="tombol tombol-kecil" href="<?= e(sip_url('buku-c.php')) ?>">Kembali ke indeks</a>
            </div>
        </div>
    </section>

    <?php if ($bolehUbah): ?>
        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Perbaiki catatan</h2><p>Nomor C tidak diubah agar tetap cocok dengan nama berkasnya</p></div></header>
            <div class="isi-kartu">
                <form method="post" action="<?= e(sip_url('buku-c.php')) ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="aksi" value="ubah">
                    <input type="hidden" name="id" value="<?= e($c['id']) ?>">
                    <div class="baris-form">
                        <div class="lapangan">
                            <label for="c-jenis">Jenis</label>
                            <select id="c-jenis" name="jenis">
                                <?php foreach (JENIS_BUKU_C as $opsi): ?>
                                    <option value="<?= e($opsi) ?>" <?= $c['jenis'] === $opsi ? 'selected' : '' ?>><?= e($opsi) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="lapangan">
                            <label for="c-persil">Nomor persil</label>
                            <input id="c-persil" name="nomor_persil" maxlength="40" value="<?= e($c['nomor_persil']) ?>">
                        </div>
                    </div>
                    <div class="lapangan">
                        <label for="c-nama">Nama pemegang</label>
                        <input id="c-nama" name="nama_pemegang" maxlength="150" value="<?= e($c['nama_pemegang']) ?>">
                    </div>
                    <div class="baris-form">
                        <div class="lapangan">
                            <label for="c-luas">Luas (m&sup2;)</label>
                            <input id="c-luas" name="luas_m2" type="number" step="0.01" min="0" value="<?= e($c['luas_m2']) ?>">
                        </div>
                        <div class="lapangan">
                            <label for="c-tahun">Tahun daftar</label>
                            <input id="c-tahun" name="tahun_daftar" type="number" min="1900" max="<?= e(date('Y') + 1) ?>" value="<?= e($c['tahun_daftar']) ?>">
                        </div>
                    </div>
                    <div class="baris-form">
                        <div class="lapangan">
                            <label for="c-dusun">Dusun</label>
                            <select id="c-dusun" name="dusun_id">
                                <option value="">Belum ditentukan</option>
                                <?php foreach (daftar_dusun() as $d): ?>
                                    <option value="<?= e($d['id']) ?>" <?= (int) $c['dusun_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['nama']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="lapangan">
                            <label for="c-bidang">Nomor bidang</label>
                            <input id="c-bidang" name="bidang_id" type="number" min="1" value="<?= e($c['bidang_id']) ?>"
                                   placeholder="id bidang hasil digitasi">
                        </div>
                    </div>
                    <div class="lapangan">
                        <label for="c-catatan">Catatan</label>
                        <textarea id="c-catatan" name="catatan"><?= e($c['catatan']) ?></textarea>
                    </div>
                    <button class="tombol tombol-utama" type="submit">Simpan perubahan</button>
                </form>
            </div>
        </section>
    <?php endif; ?>
</div>

<div class="grid grid-2 jarak-atas">
    <section class="kartu">
        <header class="kepala-kartu"><div><h2>Hak yang merujuk lembar ini</h2><p>Catatan hak yang menautkan buku C</p></div></header>
        <div class="gulir-tabel">
            <table>
                <thead><tr><th>Bidang</th><th>Jenis hak</th><th>No. sertifikat</th><th>Pemegang</th></tr></thead>
                <tbody>
                    <?php if ($c['hak'] === []): ?>
                        <tr><td colspan="4" class="kosong">Belum ada catatan hak yang menautkan lembar ini.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($c['hak'] as $h): ?>
                        <tr>
                            <td><a href="<?= e(sip_url('bidang.php', ['id' => $h['bidang_id']])) ?>"><?= e($h['bidang_kode']) ?></a></td>
                            <td><?= e($h['hak_nama']) ?></td>
                            <td><?= e($h['no_sertifikat'] ?: '—') ?></td>
                            <td><?= e(perpanjang((string) ($h['pemegang_nama'] ?: '—'), 30)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="kartu">
        <header class="kepala-kartu"><div><h2>Peralihan tercatat</h2><p>Dua puluh peralihan terakhir yang menyebut lembar ini</p></div></header>
        <div class="gulir-tabel">
            <table>
                <thead><tr><th>Kode</th><th>Jenis</th><th>Tanggal</th><th>Ke pihak kedua</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if ($c['peralihan'] === []): ?>
                        <tr><td colspan="5" class="kosong">Belum ada peralihan yang menautkan lembar ini.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($c['peralihan'] as $p): ?>
                        <tr>
                            <td><a href="<?= e(sip_url('peralihan.php', ['id' => $p['id']])) ?>"><?= e($p['kode']) ?></a></td>
                            <td><?= e($p['jenis']) ?></td>
                            <td class="nobreak"><?= e(tanggal($p['tanggal'])) ?></td>
                            <td><?= e(perpanjang((string) $p['pihak_kedua'], 26)) ?></td>
                            <td><span class="lencana <?= $p['status'] === 'Selesai' ? 'lencana-hijau' : 'lencana-kuning' ?>"><?= e($p['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

    <?php
    sip_footer();
    return;
}

// ---------------------------------------------------------------------------
// Daftar indeks
// ---------------------------------------------------------------------------
[$halaman, $perHalaman] = batas_halaman(get_int('halaman'), get_int('per_halaman'), 100);
$daftar = daftar_buku_c($query, $halaman, $perHalaman);
$arsip = db_one(
    "SELECT COUNT(*) AS jumlah, SUM(berkas_pdf IS NOT NULL AND berkas_pdf <> '') AS punya_berkas
     FROM buku_c"
) ?? [];

sip_header('Buku C Desa', 'buku-c');
sip_judul('Buku C Desa', 'Indeks buku C',
    'Setiap baris mewakili satu lembar buku C yang pernah disalin petugas; nomor C-nya sama dengan nama berkas pindaiannya.');
?>

<div class="statistik">
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">01</span>
        <div class="stat-teks">
            <small>Lembar terindeks</small>
            <strong><?= e(angka((int) $arsip['jumlah'])) ?></strong>
            <em>nomor 1 sampai <?= e(angka((int) db_value('SELECT MAX(nomor) FROM buku_c'))) ?></em>
        </div>
    </div></div>
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">02</span>
        <div class="stat-teks">
            <small>Pindaian tersedia</small>
            <strong><?= e(angka((int) $arsip['punya_berkas'])) ?></strong>
            <em>disimpan di folder terlindungi</em>
        </div>
    </div></div>
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">03</span>
        <div class="stat-teks">
            <small>Hasil saringan</small>
            <strong><?= e(angka($daftar['total'])) ?></strong>
            <em>baris sesuai kondisi pencarian</em>
        </div>
    </div></div>
</div>

<section class="kartu jarak-atas">
    <header class="kepala-kartu">
        <div><h2>Saringan</h2><p>Cari nama pemegang, nomor C, atau nomor persil</p></div>
        <div class="baris-tombol">
            <a class="tombol tombol-kecil" href="<?= e(sip_url('buku-c.php', $queryTautan + ['ekspor' => 'csv'])) ?>">Unduh CSV</a>
            <a class="tombol tombol-kecil" href="<?= e(sip_url('api/buku-c.php', $queryTautan)) ?>">Versi JSON</a>
            <a class="tombol tombol-kecil" href="<?= e(sip_url('buku-c.php')) ?>">Bersihkan</a>
        </div>
    </header>
    <div class="isi-kartu">
        <form class="formulir-alat" method="get" action="<?= e(sip_url('buku-c.php')) ?>">
            <div class="lapangan">
                <label class="label-blok" for="k-cari">Cari</label>
                <input id="k-cari" name="cari" maxlength="60" value="<?= e($query['cari']) ?>" placeholder="nama pemegang atau nomor">
            </div>
            <div class="lapangan">
                <label class="label-blok" for="k-nomor">Nomor C</label>
                <input id="k-nomor" name="nomor" type="number" min="1" value="<?= e($query['nomor']) ?>">
            </div>
            <div class="lapangan">
                <label class="label-blok" for="k-jenis">Jenis</label>
                <select id="k-jenis" name="jenis">
                    <option value="">Semua jenis</option>
                    <?php foreach (JENIS_BUKU_C as $opsi): ?>
                        <option value="<?= e($opsi) ?>" <?= $query['jenis'] === $opsi ? 'selected' : '' ?>><?= e($opsi) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="k-dusun">Dusun</label>
                <select id="k-dusun" name="dusun">
                    <option value="">Semua dusun</option>
                    <?php foreach (daftar_dusun() as $d): ?>
                        <option value="<?= e($d['id']) ?>" <?= $query['dusun'] === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="k-kosong">Pemegang kosong</label>
                <select id="k-kosong" name="tanpa_pemegang">
                    <option value="">Semua lembar</option>
                    <option value="1" <?= $query['tanpa_pemegang'] === '1' ? 'selected' : '' ?>>Hanya yang belum ada namanya</option>
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
        <div><h2>Lembar buku C</h2><p>Halaman <?= e($halaman) ?> &middot; diurutkan sesuai nomor asli buku</p></div>
    </header>
    <div class="gulir-tabel">
        <table>
            <thead>
                <tr><th>No. C</th><th>Pemegang</th><th>Persil</th><th>Dusun</th><th>Bidang</th>
                    <th class="angka">Luas (m&sup2;)</th><th class="tengah">Pindaian</th></tr>
            </thead>
            <tbody>
                <?php if ($daftar['items'] === []): ?>
                    <tr><td colspan="7" class="kosong"><strong>Tidak ada lembar yang cocok.</strong>Coba kata kunci lain.</td></tr>
                <?php endif; ?>
                <?php foreach ($daftar['items'] as $b): ?>
                    <tr>
                        <td class="nobreak">
                            <a class="tebal" href="<?= e(sip_url('buku-c.php', ['id' => $b['id']])) ?>">C-<?= e($b['nomor']) ?></a>
                            <span class="kecil redup"><?= e($b['jenis']) ?></span>
                        </td>
                        <td><?= e(perpanjang((string) ($b['nama_pemegang'] ?: '— belum tersalin —'), 38)) ?></td>
                        <td><?= e($b['nomor_persil'] ?: '—') ?></td>
                        <td><?= e($b['dusun'] ?: '—') ?></td>
                        <td>
                            <?php if ($b['bidang_id'] !== null): ?>
                                <a href="<?= e(sip_url('bidang.php', ['id' => $b['bidang_id']])) ?>"><?= e($b['bidang_kode']) ?></a>
                            <?php else: ?><span class="redup">—</span><?php endif; ?>
                        </td>
                        <td class="angka"><?= e($b['luas_m2'] === null ? '—' : angka((float) $b['luas_m2'], 2)) ?></td>
                        <td class="tengah">
                            <?php if (!empty($b['berkas_pdf'])): ?>
                                <a class="lencana lencana-biru" href="<?= e(sip_url('dokumen.php', ['buku-c' => $b['id']])) ?>" target="_blank" rel="noopener">PDF</a>
                            <?php else: ?><span class="redup">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginasi_html($halaman, $daftar['total'], $perHalaman, $queryTautan, 'buku-c.php') ?>
</section>

    <?php
    sip_footer();
