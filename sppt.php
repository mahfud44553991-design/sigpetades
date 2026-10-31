<?php
declare(strict_types=1);

/**
 * e-SPPT / PBB: rekap tahunan, daftar ketetapan, dan pencatatan pembayaran.
 *
 *   sppt.php                  -> daftar tahun aktif
 *   sppt.php?nop=...           -> detail satu objek pajak
 *   sppt.php?ekspor=csv&...    -> unduh hasil saringan sebagai CSV
 *   POST aksi=bayar            -> catat pembayaran (pengelola)
 */

require __DIR__ . '/app/bootstrap.php';

$pengguna = wajib_masuk();
$bolehUbah = boleh_kelola();

const STATUS_BAYAR = [
    'Lunas' => 'lencana-hijau',
    'Sebagian' => 'lencana-kuning',
    'Belum Bayar' => 'lencana-merah',
];

$daftarTahun = daftar_tahun_pbb();
$daftarBlok = db_all('SELECT id, kode FROM blok ORDER BY nomor, kode');
$tahunAktif = (int) setelan('tahun_pbb_aktif', '2026');
$tahunMinta = get_int('tahun');
$tahun = $tahunMinta !== null && in_array($tahunMinta, array_map('intval', $daftarTahun), true)
    ? $tahunMinta
    : ($daftarTahun === [] ? $tahunAktif : (int) $daftarTahun[0]);

$query = [
    'tahun'  => $tahun,
    'status' => get_str('status'),
    'dusun'  => get_str('dusun'),
    'blok'   => get_str('blok'),
    'cari'   => get_str('cari'),
];
$queryTautan = array_filter($query, static fn($v) => $v !== '' && $v !== null);
$queryTautan['tahun'] = $tahun;

// ---------------------------------------------------------------------------
// Unduh CSV (pembacaan saja, tetap dicatat di log)
// ---------------------------------------------------------------------------
if (get_str('ekspor') === 'csv') {
    [$where, $param] = sppt_filter($query);
    $baris = db_all(
        "SELECT s.nop, o.nama_wp, o.alamat_op, du.nama AS dusun, bl.kode AS blok, s.tahun_pajak,
                o.luas_bumi_m2, o.luas_bng_m2, s.njop_pbb, s.pajak_ditetapkan, s.terbayar,
                s.sisa, s.status_bayar, s.tanggal_bayar, b.kode AS bidang
         FROM sppt s
         JOIN objek_pajak o ON o.nop = s.nop
         LEFT JOIN dusun du ON du.id = o.dusun_id
         LEFT JOIN blok bl ON bl.id = o.blok_id
         LEFT JOIN bidang b ON b.id = o.bidang_id
         {$where}
         ORDER BY o.nama_wp
         LIMIT 20000",
        $param
    );

    audit('ekspor', 'sppt', (string) $tahun, count($baris) . ' baris SPPT tahun ' . $tahun . ' diunduh sebagai CSV');

    $namaBerkas = 'sppt-' . $tahun . '-' . date('Ymd-Hi') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $namaBerkas . '"');
    header('Cache-Control: no-store');
    $keluaran = fopen('php://output', 'wb');
    fwrite($keluaran, "\xEF\xBB\xBF");
    fputcsv($keluaran, ['NOP', 'Nama WP', 'Alamat objek', 'Dusun', 'Blok', 'Tahun pajak', 'Luas bumi',
        'Luas bangunan', 'NJOP PBB', 'Pajak ditetapkan', 'Terbayar', 'Sisa', 'Status', 'Tanggal bayar',
        'Bidang tertaut'], ';');
    foreach ($baris as $b) {
        fputcsv($keluaran, array_values($b), ';');
    }
    fclose($keluaran);
    exit;
}

// ---------------------------------------------------------------------------
// Pencatatan pembayaran
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wajib_masuk(PERAN_PENGELOLA);
    wajib_csrf();

    $nop = normalkan_nop(post_str('nop'));
    $kembali = 'sppt.php?nop=' . urlencode($nop);

    try {
        if ($nop === '' || !nop_valid($nop)) {
            throw new RuntimeException('NOP tidak valid.');
        }

        if (post_str('aksi') === 'bayar') {
            $tahunPajak = post_int('tahun_pajak');
            $jumlah = post_float('jumlah');
            $tanggal = post_str('tanggal_bayar');
            $metode = post_str('metode');
            if ($tahunPajak === null) {
                throw new RuntimeException('Tahun pajak wajib dipilih.');
            }
            if ($jumlah === null || $jumlah <= 0 || $jumlah > 99999999999) {
                throw new RuntimeException('Jumlah pembayaran harus lebih dari nol.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
                throw new RuntimeException('Tanggal pembayaran belum sesuai format.');
            }
            if (!in_array($metode, ['Tunai', 'Transfer', 'Non-tunai', 'Lainnya'], true)) {
                $metode = 'Tunai';
            }

            $hasil = db_transaksi(static function (PDO $pdo) use (
                $nop, $tahunPajak, $jumlah, $tanggal, $metode, $pengguna
            ): array {
                $statement = $pdo->prepare('SELECT * FROM sppt WHERE nop = ? AND tahun_pajak = ? FOR UPDATE');
                $statement->execute([$nop, $tahunPajak]);
                $sppt = $statement->fetch();
                if ($sppt === false) {
                    throw new RuntimeException('SPPT tahun itu tidak ditemukan untuk NOP ini.');
                }

                $terbayarBaru = round((float) $sppt['terbayar'] + $jumlah, 2);
                $ketetapan = (float) $sppt['pajak_ditetapkan'];
                $status = $terbayarBaru >= $ketetapan && $ketetapan > 0 ? 'Lunas' : ($terbayarBaru > 0 ? 'Sebagian' : 'Belum Bayar');
                if ($status === 'Lunas') {
                    $terbayarBaru = $ketetapan;
                }

                $pdo->prepare(
                    'INSERT INTO pembayaran (sppt_id, tanggal_bayar, jumlah, metode, referensi, pemungut,
                                             dicatat_oleh, keterangan)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $sppt['id'], $tanggal, $jumlah, $metode,
                    post_str('referensi') ?: null, post_str('pemungut') ?: null,
                    $pengguna['id'], post_str('keterangan') ?: null,
                ]);

                $pdo->prepare(
                    'UPDATE sppt SET terbayar = ?, status_bayar = ?,
                            tanggal_bayar = COALESCE(tanggal_bayar, ?), pemungut = COALESCE(pemungut, ?)
                     WHERE id = ?'
                )->execute([$terbayarBaru, $status, $tanggal, post_str('pemungut') ?: null, $sppt['id']]);

                return ['sebelum' => $sppt, 'terbayar' => $terbayarBaru, 'status' => $status];
            });

            audit('ubah', 'sppt', $nop . '/' . $tahunPajak,
                'Pembayaran ' . rupiah($jumlah) . ' (' . $metode . ') tercatat',
                $hasil['sebelum'], ['terbayar' => $hasil['terbayar'], 'status_bayar' => $hasil['status']]);
            flash_set('berhasil', 'Pembayaran ' . rupiah($jumlah) . ' tercatat. Status: ' . $hasil['status'] . '.');
            redirect($kembali);
        }

        if (post_str('aksi') === 'hapus-bayar') {
            $idBayar = post_int('id_pembayaran');
            $baris = $idBayar === null ? null : db_one(
                'SELECT pb.*, s.nop, s.tahun_pajak, s.terbayar, s.pajak_ditetapkan
                 FROM pembayaran pb JOIN sppt s ON s.id = pb.sppt_id WHERE pb.id = ?',
                [$idBayar]
            );
            if ($baris === null) {
                throw new RuntimeException('Pembayaran tidak ditemukan.');
            }
            db_transaksi(static function (PDO $pdo) use ($baris): void {
                $pdo->prepare('DELETE FROM pembayaran WHERE id = ?')->execute([$baris['id']]);
                $terbayar = max(0, round((float) $baris['terbayar'] - (float) $baris['jumlah'], 2));
                $ketetapan = (float) $baris['pajak_ditetapkan'];
                $status = $terbayar >= $ketetapan && $ketetapan > 0 ? 'Lunas' : ($terbayar > 0 ? 'Sebagian' : 'Belum Bayar');
                $pdo->prepare('UPDATE sppt SET terbayar = ?, status_bayar = ? WHERE id = ?')
                    ->execute([$terbayar, $status, $baris['sppt_id']]);
            });
            audit('hapus', 'pembayaran', (string) $baris['id'],
                'Pembayaran ' . rupiah((float) $baris['jumlah']) . ' NOP ' . $baris['nop'] . ' dihapus', $baris, null);
            flash_set('berhasil', 'Pembayaran dihapus dan ketetapan dihitung ulang.');
            redirect($kembali);
        }

        throw new RuntimeException('Aksi tidak dikenal.');
    } catch (Throwable $error) {
        flash_set('gagal', $error->getMessage());
        redirect($kembali . (strpos($kembali, '?') === false ? '?' : '&') . 'tahun=' . $tahun);
    }
}

// ---------------------------------------------------------------------------
// Detail satu NOP
// ---------------------------------------------------------------------------
$nopMinta = normalkan_nop(get_str('nop'));
if ($nopMinta !== '') {
    $detail = detail_objek_pajak($nopMinta);
    if ($detail === null) {
        flash_set('gagal', 'NOP ' . $nopMinta . ' tidak ada dalam data PBB desa.');
        redirect('sppt.php');
    }
    $objek = $detail['objek'];
    $riwayat = $detail['sppt'];

    sip_header('SPPT ' . $objek['nop'], 'sppt');
    sip_judul('e-SPPT / PBB', $objek['nop'], $objek['nama_wp'] ?: 'Nama wajib pajak belum tercatat');
    ?>

<div class="grid grid-2">
    <section class="kartu">
        <header class="kepala-kartu"><div><h2>Data objek pajak</h2><p>Diambil dari file SPPT yang diimpor</p></div></header>
        <div class="isi-kartu">
            <dl class="aturan">
                <div class="baris-form">
                    <div>
                        <dt class="label-blok">Nama wajib pajak</dt><dd><?= e($objek['nama_wp'] ?: '—') ?></dd>
                        <dt class="label-blok">Alamat objek</dt><dd><?= e($objek['alamat_op'] ?: '—') ?></dd>
                        <dt class="label-blok">Dusun</dt><dd><?= e($objek['dusun'] ?: 'belum dikenali') ?></dd>
                        <dt class="label-blok">Blok peta</dt>
                        <dd>
                            <?php if ($objek['blok'] !== null): ?>
                                <a href="<?= e(sip_url('bidang.php', ['blok' => $objek['blok_id']])) ?>"><?= e($objek['blok']) ?></a>
                                <span class="kecil redup">(dibaca dari digit 12-14 NOP)</span>
                            <?php else: ?>
                                <span class="redup">kode blok NOP tidak dikenali</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="label-blok">Alamat WP</dt><dd><?= e($objek['alamat_wp'] ?: '—') ?></dd>
                        <dt class="label-blok">Luas bumi / bangunan</dt>
                        <dd><?= e(angka((int) $objek['luas_bumi_m2'])) ?> m&sup2; / <?= e(angka((int) $objek['luas_bng_m2'])) ?> m&sup2;</dd>
                        <dt class="label-blok">Bidang tertaut</dt>
                        <dd>
                            <?php if ($objek['bidang_id'] !== null): ?>
                                <a href="<?= e(sip_url('bidang.php', ['id' => $objek['bidang_id']])) ?>"><?= e($objek['bidang_kode']) ?></a>
                            <?php else: ?>
                                <span class="redup">belum ditautkan</span>
                                <?php if ($bolehUbah): ?>
                                    <a class="tombol tombol-kecil" href="<?= e(sip_url('bidang.php', ['cari' => $objek['nama_wp']])) ?>">cari bidangnya</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                </div>
            </dl>
        </div>
    </section>

    <section class="kartu">
        <header class="kepala-kartu"><div><h2>Ketetapan per tahun</h2><p>NOP ini memiliki <?= e(count($riwayat)) ?> tahun SPPT</p></div></header>
        <div class="gulir-tabel">
            <table>
                <thead>
                    <tr><th>Tahun</th><th class="angka">NJOP PBB</th><th class="angka">Ketetapan</th>
                        <th class="angka">Terbayar</th><th class="angka">Sisa</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if ($riwayat === []): ?>
                        <tr><td colspan="6" class="kosong">NOP ini tercatat sebagai objek pajak tetapi belum punya ketetapan SPPT.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($riwayat as $s): ?>
                        <tr>
                            <td class="tebal"><?= e($s['tahun_pajak']) ?></td>
                            <td class="angka"><?= e(rupiah((float) $s['njop_pbb'])) ?></td>
                            <td class="angka"><?= e(rupiah((float) $s['pajak_ditetapkan'])) ?></td>
                            <td class="angka"><?= e(rupiah((float) $s['terbayar'])) ?></td>
                            <td class="angka tebal"><?= e(rupiah((float) $s['sisa'])) ?></td>
                            <td><span class="lencana <?= e(STATUS_BAYAR[$s['status_bayar']] ?? 'lencana-abu') ?>"><?= e($s['status_bayar']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="grid grid-sidebar jarak-atas">
    <div>
        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Pencatatan pembayaran</h2><p>Hanya pembayaran yang dicatat di sistem ini</p></div></header>
            <div class="gulir-tabel">
                <table>
                    <thead>
                        <tr><th>Tanggal</th><th class="angka">Jumlah</th><th>Metode</th><th>Referensi</th>
                            <th>Keterangan</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php if ($detail['pembayaran'] === []): ?>
                            <tr><td colspan="6" class="kosong">Belum ada pembayaran yang dicatat manual di sini.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($detail['pembayaran'] as $pb): ?>
                            <tr>
                                <td class="nobreak"><?= e(tanggal($pb['tanggal_bayar'])) ?></td>
                                <td class="angka tebal"><?= e(rupiah((float) $pb['jumlah'])) ?></td>
                                <td><?= e($pb['metode']) ?></td>
                                <td><?= e($pb['referensi'] ?: '—') ?></td>
                                <td><?= e(perpanjang((string) ($pb['keterangan'] ?: '—'), 40)) ?></td>
                                <td class="tengah">
                                    <?php if ($bolehUbah): ?>
                                        <form method="post" action="<?= e(sip_url('sppt.php')) ?>"
                                              data-konfirmasi="Hapus pembayaran ini dan hitung ulang ketetapannya?">
                                            <?= csrf_input() ?>
                                            <input type="hidden" name="aksi" value="hapus-bayar">
                                            <input type="hidden" name="id_pembayaran" value="<?= e($pb['id']) ?>">
                                            <input type="hidden" name="nop" value="<?= e($objek['nop']) ?>">
                                            <button class="tombol tombol-kecil tombol-bahaya" type="submit">Hapus</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div>
        <?php if ($bolehUbah && $riwayat !== []): ?>
            <section class="kartu">
                <header class="kepala-kartu"><div><h2>Catat pembayaran</h2><p>Termasuk pelunasan sebagian</p></div></header>
                <div class="isi-kartu">
                    <form method="post" action="<?= e(sip_url('sppt.php')) ?>">
                        <?= csrf_input() ?>
                        <input type="hidden" name="aksi" value="bayar">
                        <input type="hidden" name="nop" value="<?= e($objek['nop']) ?>">
                        <div class="baris-form">
                            <div class="lapangan">
                                <label for="b-tahun">Tahun pajak</label>
                                <select id="b-tahun" name="tahun_pajak" required>
                                    <?php foreach ($riwayat as $s): ?>
                                        <option value="<?= e($s['tahun_pajak']) ?>">
                                            <?= e($s['tahun_pajak']) ?> &middot; sisa <?= e(rupiah((float) $s['sisa'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="lapangan">
                                <label for="b-jumlah">Jumlah (Rp)</label>
                                <input id="b-jumlah" name="jumlah" type="number" min="1" step="1" required>
                            </div>
                        </div>
                        <div class="baris-form">
                            <div class="lapangan">
                                <label for="b-tanggal">Tanggal bayar</label>
                                <input id="b-tanggal" name="tanggal_bayar" type="date" value="<?= e(date('Y-m-d')) ?>" required>
                            </div>
                            <div class="lapangan">
                                <label for="b-metode">Metode</label>
                                <select id="b-metode" name="metode">
                                    <option>Tunai</option><option>Transfer</option>
                                    <option>Non-tunai</option><option>Lainnya</option>
                                </select>
                            </div>
                        </div>
                        <div class="baris-form">
                            <div class="lapangan">
                                <label for="b-ref">No. referensi / kuitansi</label>
                                <input id="b-ref" name="referensi" maxlength="80">
                            </div>
                            <div class="lapangan">
                                <label for="b-pemungut">Pemungut</label>
                                <input id="b-pemungut" name="pemungut" maxlength="60" value="<?= e($pengguna['nama_lengkap']) ?>">
                            </div>
                        </div>
                        <div class="lapangan">
                            <label for="b-ket">Keterangan</label>
                            <input id="b-ket" name="keterangan" maxlength="255">
                        </div>
                        <button class="tombol tombol-utama" type="submit">Simpan pembayaran</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Navigasi</h2></div></header>
            <div class="isi-kartu">
                <div class="baris-tombol">
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('sppt.php', $queryTautan)) ?>">Kembali ke daftar</a>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('api/sppt.php', ['nop' => $objek['nop']])) ?>">Versi JSON</a>
                </div>
            </div>
        </section>
    </div>
</div>

    <?php
    sip_footer();
    return;
}

// ---------------------------------------------------------------------------
// Daftar SPPT satu tahun
// ---------------------------------------------------------------------------
[$halaman, $perHalaman] = batas_halaman(get_int('halaman'), get_int('per_halaman'), 100);
$hasil = daftar_sppt($query, $halaman, $perHalaman);
$ringkas = $hasil['ringkas'];
$ketetapan = (float) ($ringkas['ketetapan'] ?? 0);
$terbayar = (float) ($ringkas['terbayar'] ?? 0);
$realisasi = $ketetapan > 0 ? round($terbayar / $ketetapan * 100, 1) : 0.0;

sip_header('e-SPPT / PBB ' . $tahun, 'sppt');
sip_judul('e-SPPT / PBB', 'Ketetapan PBB tahun ' . $tahun,
    'Nomor NOP, nama wajib pajak, dan ketetapan diambil dari file SPPT yang diimpor. Pembayaran manual menambah nilainya.');
?>

<div class="statistik">
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">01</span>
        <div class="stat-teks">
            <small>Objek pajak</small>
            <strong><?= e(angka($hasil['total'])) ?></strong>
            <em>NOP dengan SPPT <?= e($tahun) ?></em>
        </div>
    </div></div>
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">02</span>
        <div class="stat-teks">
            <small>Total ketetapan</small>
            <strong><?= e(rupiah($ketetapan)) ?></strong>
            <em>PBK2 yang harus dipungut</em>
        </div>
    </div></div>
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">03</span>
        <div class="stat-teks">
            <small>Terbayar</small>
            <strong><?= e(rupiah($terbayar)) ?></strong>
            <em><?= e(angka($realisasi, 1)) ?>% realisasi</em>
        </div>
    </div></div>
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">04</span>
        <div class="stat-teks">
            <small>Piutang</small>
            <strong><?= e(rupiah((float) ($ringkas['piutang'] ?? 0))) ?></strong>
            <em>Sisa hingga ketetapan</em>
        </div>
    </div></div>
</div>

<section class="kartu jarak-atas">
    <header class="kepala-kartu">
        <div><h2>Saringan</h2><p>Klik baris NOP untuk membuka riwayat pajaknya</p></div>
        <div class="baris-tombol">
            <a class="tombol tombol-kecil" href="<?= e(sip_url('sppt.php', $queryTautan + ['ekspor' => 'csv'])) ?>">Unduh CSV</a>
            <a class="tombol tombol-kecil" href="<?= e(sip_url('api/sppt.php', $queryTautan + ['per_halaman' => 25])) ?>">Versi JSON</a>
        </div>
    </header>
    <div class="isi-kartu">
        <form class="formulir-alat" method="get" action="<?= e(sip_url('sppt.php')) ?>">
            <div class="lapangan">
                <label class="label-blok" for="p-tahun">Tahun pajak</label>
                <select id="p-tahun" name="tahun">
                    <?php foreach (($daftarTahun ?: [(string) $tahun]) as $opsi): ?>
                        <option value="<?= e($opsi) ?>" <?= (int) $opsi === $tahun ? 'selected' : '' ?>><?= e($opsi) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="p-cari">Cari NOP / nama</label>
                <input id="p-cari" name="cari" maxlength="60" value="<?= e($query['cari']) ?>" placeholder="nama, alamat, atau nop">
            </div>
            <div class="lapangan">
                <label class="label-blok" for="p-dusun">Dusun</label>
                <select id="p-dusun" name="dusun">
                    <option value="">Semua dusun</option>
                    <?php foreach (daftar_dusun() as $d): ?>
                        <option value="<?= e($d['id']) ?>" <?= $query['dusun'] === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['nama']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="p-blok">Blok peta</label>
                <select id="p-blok" name="blok">
                    <option value="">Semua blok</option>
                    <?php foreach ($daftarBlok as $b): ?>
                        <option value="<?= e($b['id']) ?>" <?= $query['blok'] === (string) $b['id'] ? 'selected' : '' ?>><?= e($b['kode']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="p-status">Status bayar</label>
                <select id="p-status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach (STATUS_BAYAR as $label => $kelas): ?>
                        <option value="<?= e($label) ?>" <?= $query['status'] === $label ? 'selected' : '' ?>><?= e($label) ?></option>
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
    <header class="kepala-kartu">
        <div><h2>Ketetapan PBB</h2><p>Diurutkan dari sisa pembayaran terbesar</p></div>
    </header>
    <div class="gulir-tabel">
        <table>
            <thead>
                <tr>
                    <th>NOP</th><th>Wajib pajak</th><th>Dusun</th><th>Blok</th><th>Bidang</th>
                    <th class="angka">Ketetapan</th><th class="angka">Terbayar</th>
                    <th class="angka">Sisa</th><th class="tengah">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($hasil['items'] === []): ?>
                    <tr><td colspan="9" class="kosong"><strong>Belum ada SPPT tahun <?= e($tahun) ?>.</strong>Impor file e-SPPT lewat halaman pemasangan data.</td></tr>
                <?php endif; ?>
                <?php foreach ($hasil['items'] as $s): ?>
                    <tr>
                        <td class="nobreak">
                            <a class="tebal" href="<?= e(sip_url('sppt.php', ['nop' => $s['nop'], 'tahun' => $tahun])) ?>"><?= e($s['nop']) ?></a>
                        </td>
                        <td>
                            <?= e(perpanjang((string) ($s['nama_wp'] ?: '—'), 34)) ?>
                            <div class="kecil redup"><?= e(perpanjang((string) ($s['alamat_op'] ?: ''), 44)) ?></div>
                        </td>
                        <td><?= e($s['dusun'] ?: '—') ?></td>
                        <td class="nobreak"><?= e($s['blok'] ?: '—') ?></td>
                        <td>
                            <?php if ($s['bidang_id'] !== null): ?>
                                <a href="<?= e(sip_url('bidang.php', ['id' => (int) $s['bidang_id']])) ?>"><?= e($s['bidang_kode']) ?></a>
                            <?php else: ?>
                                <span class="redup">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="angka"><?= e(rupiah((float) $s['pajak_ditetapkan'])) ?></td>
                        <td class="angka"><?= e(rupiah((float) $s['terbayar'])) ?></td>
                        <td class="angka tebal"><?= e(rupiah((float) $s['sisa'])) ?></td>
                        <td class="tengah">
                            <span class="lencana <?= e(STATUS_BAYAR[$s['status_bayar']] ?? 'lencana-abu') ?>"><?= e($s['status_bayar']) ?></span>
                            <div class="kecil redup"><?= e($s['tanggal_bayar'] ? tanggal($s['tanggal_bayar']) : '') ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginasi_html($halaman, $hasil['total'], $perHalaman, $queryTautan, 'sppt.php') ?>
</section>

    <?php
    sip_footer();
