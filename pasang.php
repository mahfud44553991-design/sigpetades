<?php
declare(strict_types=1);

/**
 * Pemasangan data lapangan. Hanya Super Admin.
 *
 *   POST aksi=impor  sumber=semua|blok|bidang|sppt|digitasi|sppt-dump|buku-c  [reset=1]
 *
 * Halaman ini hanya membungkus tools/lib-impor.php supaya import bisa dijalankan
 * dari browser tanpa shell. `php tools/impor.php semua` tetap jalan dan hasilnya
 * identik. Berkas sumber dibaca dari folder aplikasi, tidak pernah dari input
 * pengguna; formulir hanya mengirim indeks berkas yang sudah terdeteksi.
 */

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/tools/lib-impor.php';

$pengguna = wajib_masuk([PERAN_SUPER]);

/** Sumber data yang bisa diimpor, lengkap dengan catatan dan dukungan reset. */
const SUMBER_IMPOR = [
    'semua' => [
        'nama' => 'Semua sumber berurutan',
        'catatan' => 'Blok, bidang, objek pajak dari dump lama, lalu indeks Buku C.',
        'bisa_reset' => true,
    ],
    'blok' => [
        'nama' => 'Blok peta (blok_peta.geojson)',
        'catatan' => 'Mendaftarkan poligon blok beserta geometrinya.',
        'bisa_reset' => true,
    ],
    'bidang' => [
        'nama' => 'Bidang tanah (bidang_tanah + bidang_lain)',
        'catatan' => 'Termasuk pusat koordinat, luas, dan status perlu verifikasi.',
        'bisa_reset' => true,
    ],
    'sppt' => [
        'nama' => 'SPPT dari CSV',
        'catatan' => 'Berkas CSV dibaca dari folder files/data excel.',
        'bisa_reset' => true,
    ],
    'digitasi' => [
        'nama' => 'Daftar bidang hasil digitasi (CSV)',
        'catatan' => 'Menambahkan poligon, luas, nomor hak, nama pemilik, dan penggunaan dari CSV. Bidang baru ditandai perlu verifikasi dan bloknya perlu ditetapkan.',
        'bisa_reset' => false,
    ],
    'sppt-dump' => [
        'nama' => 'Objek pajak dari dump lama',
        'catatan' => 'Membaca INSERT pada _lama/sigtanahdesa.sql.',
        'bisa_reset' => true,
    ],
    'buku-c' => [
        'nama' => 'Indeks Buku C Desa',
        'catatan' => 'Mendaftarkan isi folder files/Data C Desa ke tabel buku_c.',
        'bisa_reset' => false,
    ],
    'gg' => [
        'nama' => 'Kumpulan penanda tanah gang (GG)',
        'catatan' => 'Membaca label GG/TANAH GG pada MASTER.dxf, menghitung nomor blok tiap titik, lalu menulis files/peta gang/peta_gg.geojson dan .csv. Basis data tidak diubah.',
        'bisa_reset' => false,
    ],
    'gang' => [
        'nama' => 'Poligon gang menjadi Hak Pakai',
        'catatan' => 'Impor GeoJSON poligon dari files/peta gang. Setiap feature wajib punya id_bidang dan blok; hasilnya bidang tipe "lain" dengan sumber GG, hak HP, pemegang Pemerintah Desa.',
        'bisa_reset' => false,
    ],
];

/** Ukuran berkas sumber, untuk daftar kesiapan. */
function periksa_ukuran(string $path): string
{
    return is_file($path) ? number_format((int) (filesize($path) / 1024)) . ' KB' : 'tidak ada';
}

/**
 * Berkas yang boleh diimpor untuk satu sumber. Formulir hanya mengirim indeks
 * daftar ini, jadi tidak ada jalur untuk membaca berkas di luar folder aplikasi.
 *
 * @return array<int, string>
 */
function daftar_berkas(string $sumber): array
{
    $pola = match ($sumber) {
        'sppt' => SIP_ROOT . '/files/data excel/*.csv',
        'digitasi' => SIP_ROOT . '/files/data excel/*bidang*.csv',
        'gang' => SIP_ROOT . '/files/peta gang/*.geojson',
        default => null,
    };
    if ($pola === null) {
        return [];
    }
    $temuan = glob($pola) ?: [];
    sort($temuan);

    return $temuan;
}

function jalankan_impor(string $sumber, bool $reset, ?string $berkas): array
{
    switch ($sumber) {
        case 'blok':
            return [imporBlok(SIP_ROOT . '/blok_peta.geojson', $reset)];

        case 'bidang':
            return [
                imporBidang(SIP_ROOT . '/bidang_tanah.geojson', 'tanah', $reset),
                imporBidang(SIP_ROOT . '/bidang_lain.geojson', 'lain', false),
            ];

        case 'sppt':
            if ($berkas === null || !is_file($berkas)) {
                throw new RuntimeException('Berkas CSV tidak ditemukan di files/data excel.');
            }
            return [imporSpptCsv($berkas, null, $reset)];

        case 'digitasi':
            if ($berkas === null || !is_file($berkas)) {
                throw new RuntimeException('Berkas CSV bidang tidak ditemukan di files/data excel.');
            }
            return [imporDaftarDigitasiCsv($berkas)];

        case 'gang':
            if ($berkas === null || !is_file($berkas)) {
                throw new RuntimeException('GeoJSON gang tidak ditemukan di files/peta gang.');
            }
            return [imporGangBerkas($berkas, true)];

        case 'sppt-dump':
            $dump = SIP_ROOT . '/_lama/sigtanahdesa.sql';
            if (!is_file($dump)) {
                throw new RuntimeException('Dump lama _lama/sigtanahdesa.sql tidak ada.');
            }
            return [imporSpptDump($dump, null, $reset)];

        case 'buku-c':
            return [indeksBukuC()];

        case 'gg':
            return [petaGgEkspor()];

        case 'semua':
            $hasil = [
                imporBlok(SIP_ROOT . '/blok_peta.geojson', $reset),
                imporBidang(SIP_ROOT . '/bidang_tanah.geojson', 'tanah', $reset),
                imporBidang(SIP_ROOT . '/bidang_lain.geojson', 'lain', false),
            ];
            $dump = SIP_ROOT . '/_lama/sigtanahdesa.sql';
            if (is_file($dump)) {
                $hasil[] = imporSpptDump($dump, null, $reset);
            }
            $hasil[] = indeksBukuC();
            return $hasil;
    }
    throw new RuntimeException('Sumber impor tidak dikenal.');
}

/** Ringkasan satu hasil impor menjadi teks "kunci=nilai". */
function ringkas_hasil(array $hasil): string
{
    $bagian = [];
    foreach ($hasil as $kunci => $nilai) {
        if ($kunci === 'jenis' || $kunci === 'galat' || $kunci === 'label') {
            continue;
        }
        if (is_array($nilai)) {
            $bagian[] = $kunci . '=' . count($nilai);
        } elseif (is_numeric($nilai)) {
            $bagian[] = $kunci . '=' . number_format((float) $nilai);
        } else {
            $bagian[] = $kunci . '=' . basename(str_replace('\\', '/', (string) $nilai));
        }
    }
    return $bagian === [] ? '-' : implode('  ', $bagian);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wajib_csrf();
    $sumber = post_str('sumber');
    $reset = post_str('reset') === '1';

    try {
        if (!isset(SUMBER_IMPOR[$sumber])) {
            throw new RuntimeException('Sumber impor tidak dikenal.');
        }
        if ($reset && !SUMBER_IMPOR[$sumber]['bisa_reset']) {
            throw new RuntimeException('Sumber ini tidak mendukung pengosongan data.');
        }

        $berkas = null;
        if (in_array($sumber, ['sppt', 'digitasi', 'gang'], true)) {
            $namaIsian = match ($sumber) {
                'gang' => 'berkas_gang',
                'digitasi' => 'berkas_digitasi',
                default => 'berkas_csv',
            };
            $indeks = post_int($namaIsian);
            $berkas = $indeks === null ? null : (daftar_berkas($sumber)[$indeks] ?? null);
        }

        set_time_limit(0);
        $mulai = microtime(true);
        $hasil = jalankan_impor($sumber, $reset, $berkas);

        $_SESSION['impor_log'] = [
            'sumber' => SUMBER_IMPOR[$sumber]['nama'],
            'reset' => $reset,
            'detik' => round(microtime(true) - $mulai, 1),
            'bagian' => $hasil,
        ];

        audit('impor', 'sistem', $sumber,
            'Impor lewat web' . ($reset ? ' (reset)' : ''),
            null,
            array_map(
                static fn(array $h): array => array_diff_key($h, ['galat' => 1, 'label' => 1]),
                $hasil
            )
        );

        flash_set('berhasil', 'Impor "' . SUMBER_IMPOR[$sumber]['nama'] . '" selesai.');
    } catch (Throwable $error) {
        flash_set('gagal', 'Impor gagal: ' . $error->getMessage());
    }
    redirect('pasang.php');
}

$log = $_SESSION['impor_log'] ?? null;
unset($_SESSION['impor_log']);

$csvTersedia = daftar_berkas('sppt');
$digitasiTersedia = daftar_berkas('digitasi');
$gangTersedia = daftar_berkas('gang');
$masterDxf = SIP_ROOT . '/files/master peta/MASTER.dxf';
$jumlah = hitunganTabel();
$pdfBukuC = count(glob(SIP_ROOT . '/files/Data C Desa/*.pdf') ?: []);

$periksa = [
    'PHP 8.1 atau lebih baru' => [PHP_VERSION_ID >= 80100, 'Versi aktif ' . PHP_VERSION],
    'Ekstensi pdo_mysql' => [extension_loaded('pdo_mysql'), 'Penghubung MariaDB'],
    'Koneksi basis data' => [(int) db_value('SELECT 1') === 1, (string) (db_value('SELECT VERSION()') ?: '-')],
    'blok_peta.geojson' => [is_file(SIP_ROOT . '/blok_peta.geojson'), periksa_ukuran(SIP_ROOT . '/blok_peta.geojson')],
    'bidang_tanah.geojson' => [is_file(SIP_ROOT . '/bidang_tanah.geojson'), periksa_ukuran(SIP_ROOT . '/bidang_tanah.geojson')],
    'bidang_lain.geojson' => [is_file(SIP_ROOT . '/bidang_lain.geojson'), periksa_ukuran(SIP_ROOT . '/bidang_lain.geojson')],
    'Dump lama sigtanahdesa.sql' => [is_file(SIP_ROOT . '/_lama/sigtanahdesa.sql'), periksa_ukuran(SIP_ROOT . '/_lama/sigtanahdesa.sql')],
    'CSV SPPT' => [$csvTersedia !== [], count($csvTersedia) . ' berkas di files/data excel'],
    'MASTER.dxf (penanda gang)' => [is_file($masterDxf), periksa_ukuran($masterDxf)],
    'Poligon gang siap impor' => [$gangTersedia !== [], count($gangTersedia) . ' GeoJSON di files/peta gang'],
    'Arsip PDF Buku C' => [$pdfBukuC > 0, $pdfBukuC . ' PDF'],
    'Kode wilayah NOP' => [nop_valid(setelan('kode_wilayah_nop')), setelan('kode_wilayah_nop', 'belum diisi')],
    'Tahun PBB aktif' => [setelan('tahun_pbb_aktif') !== '', setelan('tahun_pbb_aktif', 'belum diisi')],
];

sip_header('Pemasangan data', 'pengguna');
?>
<div class="wadah">
    <div class="baris-judul">
        <?php sip_judul('Super Admin', 'Pemasangan data lapangan',
            'Menjalankan importer GeoJSON, SPPT, dan indeks Buku C dari browser. Cara shell tetap berlaku: php tools/impor.php semua'); ?>
        <div class="baris-tombol">
            <a class="tombol" href="<?= e(sip_url('pengguna.php')) ?>">Kembali ke manajemen akun</a>
        </div>
    </div>

    <div class="grid grid-sidebar">
        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>Jalankan impor</h2><p>Impor besar bisa memakan waktu beberapa menit. Jangan tutup halaman ini sebelum tombol kembali aktif.</p></div>
            </header>
            <div class="isi-kartu">
                <form method="post" action="<?= e(sip_url('pasang.php')) ?>" data-konfirmasi="Jalankan impor ini sekarang?">
                    <?= csrf_input() ?>
                    <input type="hidden" name="aksi" value="impor">
                    <div class="baris-form baris-form-3">
                        <div class="lapangan">
                            <label for="sumber">Sumber data</label>
                            <select class="pilih-kecil" id="sumber" name="sumber">
                                <?php foreach (SUMBER_IMPOR as $kode => $opsi): ?>
                                    <option value="<?= e($kode) ?>"><?= e($opsi['nama']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="petunjuk">SPPT dan daftar bidang digitasi memakai CSV dari files/data excel; poligon gang memakai GeoJSON dari files/peta gang. Impor daftar bidang tidak menghapus data lama.</p>
                        </div>
                        <div class="lapangan">
                            <label for="berkas_csv">Berkas CSV SPPT</label>
                            <select class="pilih-kecil" id="berkas_csv" name="berkas_csv">
                                <?php if ($csvTersedia === []): ?>
                                    <option value="">(belum ada CSV)</option>
                                <?php else: foreach ($csvTersedia as $indeks => $path): ?>
                                    <option value="<?= (int) $indeks ?>"><?= e(basename($path)) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                            <p class="petunjuk">Daftar isinya dibaca dari files/data excel, bukan dari ketikan pengguna.</p>
                        </div>
                        <div class="lapangan">
                            <label for="berkas_digitasi">Berkas CSV daftar bidang</label>
                            <select class="pilih-kecil" id="berkas_digitasi" name="berkas_digitasi">
                                <?php if ($digitasiTersedia === []): ?>
                                    <option value="">(belum ada CSV)</option>
                                <?php else: foreach ($digitasiTersedia as $indeks => $path): ?>
                                    <option value="<?= (int) $indeks ?>"><?= e(basename($path)) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                            <p class="petunjuk">CSV wajib memuat kolom id_bidang dan geojson_boundary (Polygon); kode yang sudah ada akan dilewati.</p>
                        </div>
                        <div class="lapangan">
                            <label for="berkas_gang">Berkas GeoJSON gang</label>
                            <select class="pilih-kecil" id="berkas_gang" name="berkas_gang">
                                <?php if ($gangTersedia === []): ?>
                                    <option value="">(belum ada poligon gang)</option>
                                <?php else: foreach ($gangTersedia as $indeks => $path): ?>
                                    <option value="<?= (int) $indeks ?>"><?= e(basename($path)) ?></option>
                                <?php endforeach; endif; ?>
                            </select>
                            <p class="petunjuk">Jalankan dulu "Kumpulan penanda tanah gang" untuk membuat titik acuan digitasi.</p>
                        </div>
                    </div>
                    <label class="cek">
                        <input type="checkbox" name="reset" value="1">
                        <span>Kosongkan tabel terkait dulu. Data hasil impor sebelumnya ikut terbuang. Tidak berlaku untuk daftar bidang digitasi.</span>
                    </label>
                    <div class="baris-tombol jarak-atas">
                        <button class="tombol tombol-utama" type="submit">Jalankan impor</button>
                        <span class="redup kecil">Atas nama <?= e($pengguna['nama_lengkap']) ?></span>
                    </div>
                </form>

                <?php if ($log !== null): ?>
                    <div class="jarak-atas">
                        <p class="label-blok">
                            Hasil: <?= e($log['sumber']) ?>
                            <span class="lencana <?= $log['reset'] ? 'lencana-merah' : 'lencana-hijau' ?>"><?= $log['reset'] ? 'dengan reset' : 'tanpa reset' ?></span>
                            <span class="redup"><?= e((string) $log['detik']) ?> detik</span>
                        </p>
                        <?php foreach ($log['bagian'] as $bagian): ?>
                            <p class="mono"><?= e((string) ($bagian['jenis'] ?? 'impor')) ?>: <?= e(ringkas_hasil($bagian)) ?></p>
                            <?php $galatList = array_slice((array) ($bagian['galat'] ?? []), 0, 40); ?>
                            <?php foreach ($galatList as $galat): ?>
                                <p class="kecil seru seru-bahaya"><?= e((string) $galat) ?></p>
                            <?php endforeach; ?>
                            <?php $sisa = count((array) ($bagian['galat'] ?? [])) - count($galatList); ?>
                            <?php if ($sisa > 0): ?>
                                <p class="kecil redup">masih <?= e((string) $sisa) ?> galat lain tidak ditampilkan.</p>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <aside class="grid" style="align-content:start">
            <section class="kartu">
                <header class="kepala-kartu">
                    <div><h2>Kesiapan</h2><p>Diperiksa setiap halaman ini dibuka</p></div>
                </header>
                <div class="isi-kartu">
                    <ul class="daftar-kecil">
                        <?php foreach ($periksa as $label => [$beres, $keterangan]): ?>
                            <li>
                                <span class="lencana <?= $beres ? 'lencana-hijau' : 'lencana-merah' ?>"><?= $beres ? 'siap' : 'cek' ?></span>
                                <span class="kecil"><?= e($label) ?></span>
                                <span class="rapek-kanan redup kecil"><?= e((string) $keterangan) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>

            <section class="kartu">
                <header class="kepala-kartu">
                    <div><h2>Isi basis data</h2><p>Baris per tabel sumber kebenaran</p></div>
                </header>
                <div class="isi-kartu">
                    <ul class="daftar-kecil">
                        <?php foreach ($jumlah as $tabel => $baris): ?>
                            <li>
                                <span class="mono kecil"><?= e($tabel) ?></span>
                                <span class="rapek-kanan"><?= e(angka($baris)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        </aside>
    </div>
</div>
<?php
sip_footer();
