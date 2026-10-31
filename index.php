<?php
declare(strict_types=1);

/**
 * Dasbor perangkat desa: kondisi pertanahan dan PBB Desa Gedong dalam satu
 * layar. Semua angka berasal dari fungsi pembaca di app/data.php.
 */

require __DIR__ . '/app/bootstrap.php';

if (perlu_setup()) {
    redirect('setup.php');
}

$pengguna = wajib_masuk();
$kelola = boleh_kelola();

$tahun = get_int('tahun') ?? (int) setelan('tahun_pbb_aktif', '2026');
$daftarTahun = daftar_tahun_pbb();
if ($daftarTahun !== [] && !in_array($tahun, $daftarTahun, true)) {
    $tahun = $daftarTahun[0];
}

$bidang = statistik_bidang();
$pbb = statistik_pbb($tahun);
$laporan = statistik_laporan();
$kartuHak = komposisi_hak();
$dusun = rekap_pbb_dusun($tahun);
$peralihan = peralihan_terbaru(6);
$laporanBaru = laporan_terbaru(5);
$verifikasi = bidang_perlu_verifikasi(6);
$arsip = (int) db_value('SELECT COUNT(*) FROM buku_c');
$blok = (int) db_value('SELECT COUNT(*) FROM blok');
$aktivitas = $pengguna['peran'] === PERAN_SUPER ? aktivitas_terbaru(10) : [];

sip_header('Dasbor', 'dasbor');
sip_judul(
    'Perangkat desa',
    'Kondisi pertanahan dan PBB',
    'Desa ' . setelan('nama_desa', 'Gedong') . ', Kecamatan ' . setelan('nama_kecamatan', 'Patean')
        . ', Kabupaten ' . setelan('nama_kabupaten', 'Kendal')
);
?>

<div class="statistik">
    <div class="kartu">
        <div class="stat">
            <span class="stat-ikon">01</span>
            <div class="stat-teks">
                <small>Bidang tercatat</small>
                <strong><?= e(angka((int) $bidang['total'])) ?></strong>
                <em><?= e(angka((int) $bidang['tanah'])) ?> tanah &middot; <?= e(angka((int) $bidang['lain'])) ?> non-tanah
                    <?php if ((int) $bidang['duplikat'] > 0): ?>
                        &middot; <a href="<?= e(sip_url('bidang.php', ['status' => 'duplikat'])) ?>"><?= e(angka((int) $bidang['duplikat'])) ?> kembar tidak dihitung</a>
                    <?php endif; ?></em>
            </div>
        </div>
    </div>
    <div class="kartu">
        <div class="stat">
            <span class="stat-ikon">02</span>
            <div class="stat-teks">
                <small>Luas terpetakan</small>
                <strong><?= e(angka((float) $bidang['total_luas'])) ?> m&sup2;</strong>
                <em><?= e(angka($blok)) ?> blok peta</em>
            </div>
        </div>
    </div>
    <div class="kartu">
        <div class="stat">
            <span class="stat-ikon">03</span>
            <div class="stat-teks">
                <small>Realisasi PBB <?= e($tahun) ?></small>
                <strong><?= e(angka($pbb['persen'], 2)) ?>%</strong>
                <em><?= e(rupiah($pbb['terbayar'])) ?> dari <?= e(rupiah($pbb['ketetapan'])) ?></em>
            </div>
        </div>
    </div>
    <div class="kartu">
        <div class="stat">
            <span class="stat-ikon">04</span>
            <div class="stat-teks">
                <small>Piutang PBB</small>
                <strong><?= e(rupiah($pbb['piutang'])) ?></strong>
                <em><?= e(angka($pbb['belum'])) ?> NOP belum lunas</em>
            </div>
        </div>
    </div>
    <div class="kartu">
        <div class="stat">
            <span class="stat-ikon">05</span>
            <div class="stat-teks">
                <small>Laporan menunggu</small>
                <strong><?= e(angka((int) $laporan['menunggu'])) ?></strong>
                <em><?= e(angka((int) $laporan['diproses'])) ?> sedang diproses</em>
            </div>
        </div>
    </div>
    <div class="kartu">
        <div class="stat">
            <span class="stat-ikon">06</span>
            <div class="stat-teks">
                <small>Perlu verifikasi lapangan</small>
                <strong><?= e(angka((int) $bidang['perlu_verifikasi'])) ?></strong>
                <em><?= e(angka($arsip)) ?> lembar Buku C terindeks</em>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-sidebar">
    <div>
        <section class="kartu">
            <header class="kepala-kartu">
                <div>
                    <h2>Penerimaan PBB per dusun</h2>
                    <p>Tahun pajak <?= e($tahun) ?> &middot; urut dari piutang terbesar</p>
                </div>
                <form class="formulir-alat" method="get" action="index.php">
                    <div class="lapangan">
                        <label class="label-blok" for="tahun">Tahun pajak</label>
                        <select id="tahun" name="tahun" class="pilih-kecil" onchange="this.form.submit()">
                            <?php foreach (($daftarTahun ?: [$tahun]) as $opsi): ?>
                                <option value="<?= e($opsi) ?>" <?= $opsi === $tahun ? 'selected' : '' ?>><?= e($opsi) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </header>
            <div class="gulir-tabel">
                <table>
                    <thead>
                        <tr>
                            <th>Dusun</th><th>NOP</th><th>Ketetapan</th>
                            <th>Terbayar</th><th>Piutang</th><th>Realisasi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($dusun === []): ?>
                            <tr><td colspan="6" class="kosong">Belum ada data PBB untuk tahun ini.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($dusun as $baris):
                            $ketetapan = (float) $baris['ketetapan'];
                            $persen = $ketetapan > 0 ? round((float) $baris['terbayar'] / $ketetapan * 100, 1) : 0.0; ?>
                            <tr>
                                <td><a href="<?= e(sip_url('sppt.php', ['dusun' => $baris['id'], 'tahun' => $tahun])) ?>"><?= e($baris['nama']) ?></a></td>
                                <td><?= e(angka((int) $baris['objek'])) ?></td>
                                <td><?= e(rupiah($ketetapan)) ?></td>
                                <td><?= e(rupiah((float) $baris['terbayar'])) ?></td>
                                <td class="tebal"><?= e(rupiah((float) $baris['piutang'])) ?></td>
                                <td>
                                    <span class="lencana <?= $persen >= 80 ? 'lencana-hijau' : ($persen >= 50 ? 'lencana-kuning' : 'lencana-merah') ?>">
                                        <?= e(angka($persen, 1)) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="kartu">
            <header class="kepala-kartu">
                <div>
                    <h2>Bidang perlu verifikasi</h2>
                    <p>Jenis hak belum pasti atau batas bidang hasil taksiran</p>
                </div>
                <a class="tombol tombol-kecil" href="<?= e(sip_url('bidang.php', ['status' => 'perlu_verifikasi'])) ?>">Lihat semua</a>
            </header>
            <?php if ($verifikasi === []): ?>
                <p class="kosong">Tidak ada bidang yang menunggu verifikasi.</p>
            <?php else: ?>
                <div class="gulir-tabel">
                    <table>
                        <thead><tr><th>Kode bidang</th><th>Blok</th><th>Dusun</th><th>Luas</th><th>Pemegang tercatat</th></tr></thead>
                        <tbody>
                            <?php foreach ($verifikasi as $b): ?>
                                <tr>
                                    <td><a href="<?= e(sip_url('bidang.php', ['id' => $b['id']])) ?>"><?= e($b['kode']) ?></a></td>
                                    <td><?= e($b['blok'] ?: '—') ?></td>
                                    <td><?= e($b['dusun'] ?: 'belum dipetakan') ?></td>
                                    <td><?= $b['luas_m2'] === null ? '—' : e(angka((float) $b['luas_m2'])) . ' m²' ?></td>
                                    <td><?= e($b['pemegang_nama'] ?: 'belum tercatat') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="kartu">
            <header class="kepala-kartu">
                <div>
                    <h2>Peralihan hak terakhir</h2>
                    <p>Jual beli, waris, hibah, dan tukar menukar yang tercatat</p>
                </div>
                <?php if ($kelola): ?>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('peralihan.php', ['baru' => 1])) ?>">Catat peralihan</a>
                <?php endif; ?>
            </header>
            <?php if ($peralihan === []): ?>
                <p class="kosong">Belum ada peralihan hak tercatat. Catat dari halaman Peralihan hak.</p>
            <?php else: ?>
                <div class="gulir-tabel">
                    <table>
                        <thead><tr><th>Tanggal</th><th>Jenis</th><th>Pihak pertama</th><th>Pihak kedua</th><th>Luas</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($peralihan as $p): ?>
                                <tr>
                                    <td><?= e(tanggal($p['tanggal'])) ?></td>
                                    <td><a href="<?= e(sip_url('peralihan.php', ['id' => $p['id']])) ?>"><?= e($p['jenis']) ?></a></td>
                                    <td><?= e(perpanjang((string) $p['pihak_pertama'], 28)) ?></td>
                                    <td><?= e(perpanjang((string) $p['pihak_kedua'], 28)) ?></td>
                                    <td><?= $p['luas_m2'] === null ? '—' : e(angka((float) $p['luas_m2'])) . ' m²' ?></td>
                                    <td><span class="lencana <?= $p['status'] === 'Selesai' ? 'lencana-hijau' : 'lencana-kuning' ?>"><?= e($p['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside>
        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Komposisi hak atas tanah</h2></div></header>
            <?php $maksHak = max(1, (int) ($kartuHak[0]['jumlah'] ?? 1)); ?>
            <ul class="daftar-kecil">
                <?php foreach ($kartuHak as $h): ?>
                    <li>
                        <a href="<?= e(sip_url('bidang.php', ['hak' => $h['kode']])) ?>">
                            <strong><?= e($h['kode']) ?></strong>
                        </a>
                        <span class="redup"><?= e(perpanjang($h['nama'], 30)) ?></span>
                        <span class="meter" aria-hidden="true">
                            <span style="width:<?= e(number_format($h['jumlah'] / $maksHak * 100, 1, '.', '')) ?>%"></span>
                        </span>
                        <span class="rapek-kanan tebal"><?= e(angka($h['jumlah'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ((int) $bidang['total'] > 0): ?>
                <p class="petunjuk">
                    <?= e(angka((int) $bidang['perlu_verifikasi'])) ?> bidang masih berstatus perlu verifikasi
                    (<?= e(angka((int) $bidang['perlu_verifikasi'] / (int) $bidang['total'] * 100, 1)) ?>%).
                </p>
            <?php endif; ?>
        </section>

        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>Laporan terbaru</h2></div>
                <a class="tombol tombol-kecil" href="<?= e(sip_url('laporan.php')) ?>">Kelola</a>
            </header>
            <?php if ($laporanBaru === []): ?>
                <p class="kosong">Belum ada laporan warga masuk.</p>
            <?php else: ?>
                <?php foreach ($laporanBaru as $l): ?>
                    <div class="aturan">
                        <span class="lencana <?= $l['status'] === 'Selesai' ? 'lencana-hijau' : ($l['status'] === 'Ditolak' ? 'lencana-abu' : 'lencana-kuning') ?>">
                            <?= e($l['status']) ?>
                        </span>
                        <strong><?= e($l['nomor']) ?></strong>
                        <span class="redup"><?= e(tanggal($l['tanggal_lapor'], true)) ?></span>
                        <p class="kecil"><?= e(perpanjang((string) $l['keterangan'], 120)) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Navigasi cepat</h2></div></header>
            <div class="baris-tombol">
                <a class="tombol" href="<?= e(sip_url('peta.php')) ?>">Peta desa</a>
                <a class="tombol" href="<?= e(sip_url('bidang.php')) ?>">Bidang tanah</a>
                <a class="tombol" href="<?= e(sip_url('sppt.php')) ?>">e-SPPT</a>
                <a class="tombol" href="<?= e(sip_url('buku-c.php')) ?>">Buku C</a>
                <a class="tombol" href="<?= e(sip_url('peralihan.php')) ?>">Peralihan</a>
                <a class="tombol" href="<?= e(sip_url('laporan.php')) ?>">Pelaporan</a>
                <?php if ($pengguna['peran'] === PERAN_SUPER): ?>
                    <a class="tombol" href="<?= e(sip_url('pengguna.php')) ?>">Manajemen akun</a>
                    <a class="tombol" href="<?= e(sip_url('pasang.php')) ?>">Impor data</a>
                <?php endif; ?>
            </div>
            <p class="petunjuk">
                Akun <strong><?= e($pengguna['username']) ?></strong> berperan
                <?= e(peran_label($pengguna['peran'])) ?>.
                <?php if (!$kelola): ?>
                    Perubahan data pertanahan hanya bisa dilakukan admin atau super admin.
                <?php endif; ?>
            </p>
        </section>

        <?php if ($pengguna['peran'] === PERAN_SUPER): ?>
            <section class="kartu">
                <header class="kepala-kartu">
                    <div><h2>Jejak aktivitas terakhir</h2></div>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('pengguna.php', ['tab' => 'audit'])) ?>">Semua</a>
                </header>
                <ul class="daftar-kecil kecil">
                    <?php foreach ($aktivitas as $a): ?>
                        <li>
                            <span><?= e($a['pelaksana'] ?: 'sistem') ?></span>
                            <span class="redup"><?= e($a['aksi']) ?></span>
                            <span class="rapek-kanan redup"><?= e(tanggal($a['waktu'], true)) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($aktivitas === []): ?>
                        <li class="kosong">Belum ada aktivitas tercatat.</li>
                    <?php endif; ?>
                </ul>
            </section>
        <?php endif; ?>
    </aside>
</div>

<?php sip_footer(); ?>
