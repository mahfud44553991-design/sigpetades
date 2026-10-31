<?php
declare(strict_types=1);

/**
 * Pelaporan masyarakat: pendaftaran laporan, pemantauan, dan penanganan.
 *
 *   laporan.php              -> daftar + saringan + formulir laporan baru
 *   laporan.php?id=7         -> detail satu laporan
 *   POST aksi=catat          -> simpan laporan baru (semua peran yang masuk)
 *   POST aksi=tangani        -> ubah status & catatan penanganan (pengelola)
 */

require __DIR__ . '/app/bootstrap.php';

$pengguna = wajib_masuk();
$kelola = boleh_kelola();

const STATUS_LAPORAN = [
    'Menunggu' => 'lencana-merah',
    'Diproses' => 'lencana-kuning',
    'Selesai' => 'lencana-hijau',
    'Ditolak' => 'lencana-abu',
];

$pusatLat = (float) setelan('peta_lat_pusat', '-7.0872');
$pusatLon = (float) setelan('peta_lon_pusat', '110.0693');

// ---------------------------------------------------------------------------
// Penyimpanan
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wajib_csrf();
    $aksi = post_str('aksi');

    try {
        if ($aksi === 'catat') {
            $nama = post_str('nama_pelapor');
            if (len_utf8($nama) < 3 || len_utf8($nama) > 100) {
                throw new RuntimeException('Nama pelapor wajib diisi 3–100 karakter.');
            }
            $kategori = post_str('kategori');
            if (!in_array($kategori, LAPORAN_KATEGORI, true)) {
                throw new RuntimeException('Pilih kategori laporan.');
            }
            $keterangan = post_str('keterangan');
            if (len_utf8($keterangan) < 10) {
                throw new RuntimeException('Jelaskan perkaranya minimal 10 karakter agar bisa ditindaklanjuti.');
            }
            $telepon = post_str('telepon');
            if ($telepon !== '' && !preg_match('/^[0-9+\-\s().]{6,20}$/', $telepon)) {
                throw new RuntimeException('Nomor telepon tidak dikenali.');
            }

            $lat = post_float('lat') ?? $pusatLat;
            $lon = post_float('lon') ?? $pusatLon;
            if (!filter_var($lat, FILTER_VALIDATE_FLOAT) || abs($lat) > 90 || abs($lon) > 180) {
                throw new RuntimeException('Koordinat laporan tidak valid.');
            }

            $bidangId = post_int('bidang_id');
            if ($bidangId !== null && db_value('SELECT id FROM bidang WHERE id = ?', [$bidangId]) === null) {
                throw new RuntimeException('Bidang yang dirujuk tidak ditemukan.');
            }
            $nop = normalkan_nop(post_str('nop'));
            if ($nop !== '' && db_value('SELECT nop FROM objek_pajak WHERE nop = ?', [$nop]) === null) {
                $nop = '';
            }

            $nomor = nomor_berikutnya('laporan', 'nomor', 'LAP-' . date('Y') . '-');
            $idLaporan = db_transaksi(static function (PDO $pdo) use (
                $nomor, $kategori, $nama, $telepon, $keterangan, $lat, $lon, $bidangId, $nop, $pengguna, $kelola
            ): int {
                $pdo->prepare(
                    'INSERT INTO laporan (nomor, kategori, bidang_id, nop, nama_pelapor, telepon,
                                          keterangan, geom, status, ip_lapor, ditangani_oleh)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, ?)'
                )->execute([
                    $nomor, $kategori, $bidangId, $nop ?: null, $nama, $telepon ?: null,
                    $keterangan, 'POINT(' . $lon . ' ' . $lat . ')',
                    $pengguna['peran'] === PERAN_BACA ? 'Menunggu' : 'Diproses',
                    ip_terkini(),
                    $kelola ? $pengguna['id'] : null,
                ]);
                return (int) $pdo->lastInsertId();
            });

            audit('buat', 'laporan', (string) $idLaporan, 'Laporan ' . $nomor . ' (' . $kategori . ') dicatat dari ' . $nama);
            flash_set('berhasil', 'Laporan ' . $nomor . ' tersimpan dan tercatat sebagai milik Anda.');
            redirect('laporan.php?id=' . $idLaporan);
        }

        if ($aksi === 'tangani') {
            wajib_masuk(PERAN_PENGELOLA);
            $id = post_int('id');
            $lama = $id === null ? null : db_one('SELECT id, nomor, status, tanggal_selesai FROM laporan WHERE id = ?', [$id]);
            if ($lama === null) {
                throw new RuntimeException('Laporan tidak ditemukan.');
            }
            $status = post_str('status');
            if (!in_array($status, LAPORAN_STATUS, true)) {
                throw new RuntimeException('Status laporan tidak dikenali.');
            }
            $catatan = post_str('catatan_penanganan');
            if (len_utf8($catatan) > 4000) {
                throw new RuntimeException('Catatan penanganan terlalu panjang.');
            }
            $selesai = $status === 'Selesai' || $status === 'Ditolak';

            db_exec(
                'UPDATE laporan
                 SET status = ?, catatan_penanganan = ?, ditangani_oleh = ?,
                     tanggal_selesai = CASE WHEN ? THEN COALESCE(tanggal_selesai, NOW()) ELSE NULL END
                 WHERE id = ?',
                [$status, $catatan === '' ? null : $catatan, $pengguna['id'], $selesai ? 1 : 0, $id]
            );
            audit('ubah', 'laporan', (string) $id, $lama['status'] . ' -> ' . $status,
                ['status' => $lama['status']], ['status' => $status, 'catatan' => $catatan]);
            flash_set('berhasil', 'Status laporan ' . $lama['nomor'] . ' diubah menjadi ' . $status . '.');
            redirect('laporan.php?id=' . $id);
        }

        throw new RuntimeException('Aksi tidak dikenal.');
    } catch (Throwable $error) {
        flash_set('gagal', $error->getMessage());
        redirect('laporan.php' . (post_int('id') !== null ? '?id=' . post_int('id') : ''));
    }
}

// ---------------------------------------------------------------------------
// Detail satu laporan
// ---------------------------------------------------------------------------
$idMinta = get_int('id');
if ($idMinta !== null) {
    $l = detail_laporan($idMinta, $kelola);
    if ($l === null) {
        flash_set('gagal', 'Laporan tidak ditemukan.');
        redirect('laporan.php');
    }
    $kelasStatus = STATUS_LAPORAN[$l['status']] ?? 'lencana-abu';

    sip_header('Laporan ' . $l['nomor'], 'laporan', ['leaflet' => true]);
    sip_judul('Pelaporan', $l['nomor'] . ' · ' . $l['kategori'], (string) $l['nama_pelapor']);
    ?>

<div class="grid grid-sidebar">
    <div>
        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>Isi laporan</h2><p>Dilaporkan <?= e(tanggal($l['tanggal_lapor'], true)) ?></p></div>
                <span class="lencana <?= e($kelasStatus) ?>"><?= e($l['status']) ?></span>
            </header>
            <div class="isi-kartu">
                <p class="tebal"><?= e($l['keterangan']) ?></p>
                <dl class="aturan">
                    <div class="baris-form">
                        <div>
                            <dt class="label-blok">Kategori</dt><dd><?= e($l['kategori']) ?></dd>
                            <dt class="label-blok">Pelapor</dt><dd><?= e($l['nama_pelapor']) ?></dd>
                            <dt class="label-blok">Telepon</dt><dd><?= e($l['telepon'] ?: 'tidak diisi') ?></dd>
                        </div>
                        <div>
                            <dt class="label-blok">Bidang</dt>
                            <dd>
                                <?php if ($l['bidang_id'] !== null): ?>
                                    <a href="<?= e(sip_url('bidang.php', ['id' => $l['bidang_id']])) ?>"><?= e($l['bidang_kode']) ?></a>
                                <?php else: ?><span class="redup">tidak ditautkan</span><?php endif; ?>
                            </dd>
                            <dt class="label-blok">NOP</dt><dd class="nobreak"><?= e($l['nop'] ?: '—') ?></dd>
                            <dt class="label-blok">Titik laporan</dt>
                            <dd class="nobreak"><?= e(number_format((float) $l['lat'], 6, '.', '')) ?>, <?= e(number_format((float) $l['lon'], 6, '.', '')) ?></dd>
                        </div>
                    </div>
                </dl>
                <div id="petaLaporan" class="peta-kecil"></div>
            </div>
        </section>

        <section class="kartu jarak-atas">
            <header class="kepala-kartu"><div><h2>Penanganan</h2><p>Catatan petugas dan tanggal selesai</p></div></header>
            <div class="isi-kartu">
                <dl class="aturan">
                    <div class="baris-form">
                        <div>
                            <dt class="label-blok">Ditangani oleh</dt><dd><?= e($l['penangan'] ?: 'belum ada petugas') ?></dd>
                            <dt class="label-blok">Tanggal selesai</dt><dd><?= e($l['tanggal_selesai'] ? tanggal($l['tanggal_selesai'], true) : 'belum selesai') ?></dd>
                        </div>
                        <div>
                            <dt class="label-blok">Dilaporkan dari IP</dt><dd class="nobreak"><?= e($l['ip_lapor'] ?: '—') ?></dd>
                            <dt class="label-blok">Lapor pada</dt><dd><?= e(tanggal($l['tanggal_lapor'], true)) ?></dd>
                        </div>
                    </div>
                </dl>
                <?php if (!empty($l['catatan_penanganan'])): ?>
                    <p class="kecil"><?= e($l['catatan_penanganan']) ?></p>
                <?php endif; ?>

                <?php if ($kelola): ?>
                    <form method="post" action="<?= e(sip_url('laporan.php')) ?>" class="jarak-atas">
                        <?= csrf_input() ?>
                        <input type="hidden" name="aksi" value="tangani">
                        <input type="hidden" name="id" value="<?= e($l['id']) ?>">
                        <div class="baris-form">
                            <div class="lapangan">
                                <label for="t-status">Status penanganan</label>
                                <select id="t-status" name="status">
                                    <?php foreach (LAPORAN_STATUS as $opsi): ?>
                                        <option value="<?= e($opsi) ?>" <?= $l['status'] === $opsi ? 'selected' : '' ?>><?= e($opsi) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="lapangan">
                                <label for="t-catatan">Catatan penanganan</label>
                                <input id="t-catatan" name="catatan_penanganan" maxlength="500"
                                       value="<?= e($l['catatan_penanganan']) ?>" placeholder="hasil cek lapangan, berkas yang diminta">
                            </div>
                        </div>
                        <button class="tombol tombol-utama" type="submit">Simpan penanganan</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div>
        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Tindak lanjut</h2></div></header>
            <div class="isi-kartu">
                <div class="baris-tombol">
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('laporan.php')) ?>">Semua laporan</a>
                    <?php if ($l['bidang_id'] !== null): ?>
                        <a class="tombol tombol-kecil" href="<?= e(sip_url('bidang.php', ['id' => $l['bidang_id']])) ?>">Bidang terkait</a>
                    <?php endif; ?>
                    <?php if ($l['nop'] !== null): ?>
                        <a class="tombol tombol-kecil" href="<?= e(sip_url('sppt.php', ['nop' => $l['nop']])) ?>">SPPT terkait</a>
                    <?php endif; ?>
                </div>
                <p class="petunjuk">Laporan yang sudah selesai atau ditolak menyimpan tanggal selesainya otomatis.</p>
            </div>
        </section>
    </div>
</div>

    <?php
    $kaki = <<<'JS'
<script>
(function () {
    if (typeof L === 'undefined' || !SIP.data.laporan) return;
    const titik = SIP.data.laporan;
    const peta = L.map('petaLaporan');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 20, attribution: '&copy; OpenStreetMap'
    }).addTo(peta);
    L.marker([titik.lat, titik.lon]).addTo(peta).bindPopup(SIP.data.nomor + ' &middot; ' + titik.kategori);
    peta.setView([titik.lat, titik.lon], 16);
})();
</script>
JS;
    sip_footer([
        'leaflet' => true,
        'data' => ['laporan' => ['lat' => (float) $l['lat'], 'lon' => (float) $l['lon'], 'kategori' => $l['kategori']],
                   'nomor' => $l['nomor']],
        'kaki' => $kaki,
    ]);
    return;
}

// ---------------------------------------------------------------------------
// Daftar laporan
// ---------------------------------------------------------------------------
$query = [
    'status'   => get_str('status'),
    'kategori' => get_str('kategori'),
    'cari'     => get_str('cari'),
];
$queryTautan = array_filter($query, static fn($v) => $v !== '');
[$halaman, $perHalaman] = batas_halaman(get_int('halaman'), get_int('per_halaman'), 100);
$daftar = daftar_laporan($query, $halaman, $perHalaman, $kelola);
$ringkas = statistik_laporan();

sip_header('Pelaporan', 'laporan');
sip_judul('Pelaporan', 'Laporan masyarakat dan sengketa tanah',
    'Catat pengaduan warga, pantau penanganannya, dan tautkan ke bidang atau NOP yang bersangkutan.');
?>

<div class="statistik">
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">01</span>
        <div class="stat-teks">
            <small>Menunggu</small>
            <strong><?= e(angka((int) $ringkas['menunggu'])) ?></strong>
            <em>belum ada petugas yang menangani</em>
        </div>
    </div></div>
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">02</span>
        <div class="stat-teks">
            <small>Diproses</small>
            <strong><?= e(angka((int) $ringkas['diproses'])) ?></strong>
            <em>sedang dicek di lapangan</em>
        </div>
    </div></div>
    <div class="kartu"><div class="stat">
        <span class="stat-ikon">03</span>
        <div class="stat-teks">
            <small>Selesai</small>
            <strong><?= e(angka((int) $ringkas['selesai'])) ?></strong>
            <em>total laporan sepanjang <?= e(date('Y')) ?></em>
        </div>
    </div></div>
</div>

<section class="kartu jarak-atas">
    <header class="kepala-kartu">
        <div><h2>Catat laporan baru</h2><p>Formulir ini dipakai petugas desa saat menerima pengaduan</p></div>
    </header>
    <div class="isi-kartu">
        <form method="post" action="<?= e(sip_url('laporan.php')) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="aksi" value="catat">
            <input type="hidden" name="lat" id="l-lat" value="<?= e($pusatLat) ?>">
            <input type="hidden" name="lon" id="l-lon" value="<?= e($pusatLon) ?>">
            <div class="baris-form">
                <div class="lapangan">
                    <label for="n-nama">Nama pelapor <span class="redup">*</span></label>
                    <input id="n-nama" name="nama_pelapor" maxlength="100" required>
                </div>
                <div class="lapangan">
                    <label for="n-telepon">Nomor telepon</label>
                    <input id="n-telepon" name="telepon" maxlength="20" placeholder="0812xxxxxxx">
                </div>
            </div>
            <div class="baris-form">
                <div class="lapangan">
                    <label for="n-kategori">Kategori <span class="redup">*</span></label>
                    <select id="n-kategori" name="kategori" required>
                        <?php foreach (LAPORAN_KATEGORI as $opsi): ?>
                            <option value="<?= e($opsi) ?>"><?= e($opsi) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lapangan">
                    <label for="n-nop">NOP terkait</label>
                    <input id="n-nop" name="nop" maxlength="24" placeholder="kosongkan bila belum tahu">
                </div>
            </div>
            <div class="lapangan">
                <label for="n-keterangan">Keterangan <span class="redup">*</span></label>
                <textarea id="n-keterangan" name="keterangan" required
                          placeholder="jelajahi perkaranya: lokasi, sejak kapan, pihak yang terlibat"></textarea>
                <p class="petunjuk">Titik laporan memakai pusat desa bila tidak diisi. Buka peta desa untuk menempatkan titik yang lebih tepat.</p>
            </div>
            <button class="tombol tombol-utama" type="submit">Simpan laporan</button>
        </form>
    </div>
</section>

<section class="kartu jarak-atas">
    <header class="kepala-kartu">
        <div><h2>Saringan</h2><p><?= e(angka($daftar['total'])) ?> laporan cocok</p></div>
        <div class="baris-tombol">
            <a class="tombol tombol-kecil" href="<?= e(sip_url('api/laporan.php', $queryTautan)) ?>">Versi JSON</a>
            <a class="tombol tombol-kecil" href="<?= e(sip_url('laporan.php')) ?>">Bersihkan</a>
        </div>
    </header>
    <div class="isi-kartu">
        <form class="formulir-alat" method="get" action="<?= e(sip_url('laporan.php')) ?>">
            <div class="lapangan">
                <label class="label-blok" for="f-cari">Cari</label>
                <input id="f-cari" name="cari" maxlength="60" value="<?= e($query['cari']) ?>" placeholder="nomor, pelapor, nop">
            </div>
            <div class="lapangan">
                <label class="label-blok" for="f-kategori">Kategori</label>
                <select id="f-kategori" name="kategori">
                    <option value="">Semua kategori</option>
                    <?php foreach (LAPORAN_KATEGORI as $opsi): ?>
                        <option value="<?= e($opsi) ?>" <?= $query['kategori'] === $opsi ? 'selected' : '' ?>><?= e($opsi) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="lapangan">
                <label class="label-blok" for="f-status">Status</label>
                <select id="f-status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach (LAPORAN_STATUS as $opsi): ?>
                        <option value="<?= e($opsi) ?>" <?= $query['status'] === $opsi ? 'selected' : '' ?>><?= e($opsi) ?></option>
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
    <header class="kepala-kartu"><div><h2>Daftar laporan</h2><p>Yang belum selesai ditampilkan lebih dulu</p></div></header>
    <div class="gulir-tabel">
        <table>
            <thead>
                <tr><th>Nomor</th><th>Kategori</th><th>Pelapor</th><th>Keterangan</th>
                    <th>Bidang</th><th>Lapor pada</th><th class="tengah">Status</th></tr>
            </thead>
            <tbody>
                <?php if ($daftar['items'] === []): ?>
                    <tr><td colspan="7" class="kosong"><strong>Belum ada laporan.</strong>Catat pengaduan pertama lewat formulir di atas.</td></tr>
                <?php endif; ?>
                <?php foreach ($daftar['items'] as $b): ?>
                    <tr>
                        <td class="nobreak"><a class="tebal" href="<?= e(sip_url('laporan.php', ['id' => $b['id']])) ?>"><?= e($b['nomor']) ?></a></td>
                        <td><?= e($b['kategori']) ?></td>
                        <td>
                            <?= e(perpanjang((string) $b['nama_pelapor'], 24)) ?>
                            <div class="kecil redup"><?= e($b['telepon'] ?: '') ?></div>
                        </td>
                        <td><?= e(perpanjang((string) $b['keterangan'], 60)) ?></td>
                        <td>
                            <?php if ($b['bidang_id'] !== null): ?>
                                <a href="<?= e(sip_url('bidang.php', ['id' => $b['bidang_id']])) ?>"><?= e($b['bidang_kode']) ?></a>
                            <?php else: ?><span class="redup">—</span><?php endif; ?>
                        </td>
                        <td class="nobreak"><?= e(tanggal($b['tanggal_lapor'])) ?></td>
                        <td class="tengah"><span class="lencana <?= e(STATUS_LAPORAN[$b['status']] ?? 'lencana-abu') ?>"><?= e($b['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= paginasi_html($halaman, $daftar['total'], $perHalaman, $queryTautan, 'laporan.php') ?>
</section>

    <?php
    sip_footer();
