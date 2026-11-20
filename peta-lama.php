<?php
declare(strict_types=1);

/**
 * Penampil layer DXF dari arsip aplikasi lama
 * (files/sig-pertanahan-gedong-lokal/data/dxf-layers).
 *
 * Geometri memakai koordinat lokal hasil pengukuran dengan L.CRS.Simple,
 * bukan EPSG:4326, jadi halaman ini sengaja tidak memakai basemap GPS.
 */

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/tools/lib-impor.php';

$pengguna = wajib_masuk();
$kelola = boleh_kelola();

$berkasMeta = __DIR__ . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/metadata.json';
$meta = is_file($berkasMeta) ? json_decode((string) file_get_contents($berkasMeta), true) : null;
$rentang = is_array($meta['extent'] ?? null) ? $meta['extent'] : null;
$jumlah = static fn (string $kunci): int => (int) ($meta['layers'][$kunci]['features'] ?? 0);
$jumlahBatasBlok = (int) db_value('SELECT COUNT(*) FROM blok');
$blokPerkiraan = db_all('SELECT nomor, kode FROM blok WHERE batas_perkiraan = 1 ORDER BY nomor');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wajib_masuk([PERAN_SUPER]);
    wajib_csrf();

    $lapisan = post_str('lapisan_bidang');
    try {
        set_time_limit(0);
        $hasil = imporPoligonPetaInduk($lapisan);
        audit(
            'impor',
            'bidang',
            'peta-induk-' . $lapisan,
            'Buat bidang dari semua poligon layer peta induk ' . $hasil['nama_lapisan'],
            null,
            array_diff_key($hasil, ['galat' => 1])
        );
        if ($hasil['jumlah_galat'] > 0) {
            flash_set('gagal', angka((int) $hasil['dibuat']) . ' bidang dibuat; '
                . angka((int) $hasil['jumlah_galat']) . ' poligon bermasalah. '
                . implode(' ', array_slice($hasil['galat'], 0, 3)));
        } else {
            $ringkasan = angka((int) $hasil['dibuat']) . ' bidang dibuat dari layer '
                . $hasil['nama_lapisan'] . '; ' . angka((int) $hasil['dilewati'])
                . ' poligon duplikat dilewati.';
            if ((int) $hasil['poligon_dari_garis'] > 0) {
                $ringkasan .= ' ' . angka((int) $hasil['poligon_dari_garis'])
                    . ' poligon disusun dari garis; ' . angka((int) $hasil['garis_tidak_menutup'])
                    . ' garis tidak membentuk bidang tertutup dan dilewati.';
            }
            if ((int) $hasil['bukan_poligon'] > 0) {
                $ringkasan .= ' ' . angka((int) $hasil['bukan_poligon'])
                    . ' geometri non-poligon tidak diimpor.';
            }
            flash_set('berhasil', $ringkasan);
        }
    } catch (Throwable $error) {
        flash_set('gagal', 'Pembuatan bidang gagal: ' . $error->getMessage());
    }
    redirect('peta-lama.php');
}

$jumlahPoligonLayer = [
    '010500' => (int) ($meta['layers']['010500']['types']['polygon'] ?? 0),
    '020100' => (int) ($meta['layers']['020100']['types']['polygon'] ?? 0),
    '020400' => (int) ($meta['layers']['020400']['types']['polygon'] ?? 0),
    'batas-blok' => (int) ($meta['layers']['BATAS BLOK']['types']['polygon'] ?? 0),
    'bidang-hm' => (int) ($meta['layers']['BIDANG HM']['types']['polygon'] ?? 0),
    'calon-bidang' => (int) ($meta['layers']['CALON BIDANG']['features'] ?? 0),
    'hm' => (int) ($meta['layers']['HM']['types']['polygon'] ?? 0),
    'lb-lai' => (int) ($meta['layers']['lb_lai']['types']['polygon'] ?? 0),
];
$jumlahPoligonLayer['020100'] = (int) ($meta['layers']['020100']['features'] ?? 0);

$berkasGarisJalan = __DIR__ . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-jalan-dari-sumbu.geojson';
$jumlahGarisJalan = 0;
if (is_file($berkasGarisJalan)) {
    $isiGarisJalan = json_decode((string) file_get_contents($berkasGarisJalan), true);
    $jumlahGarisJalan = is_array($isiGarisJalan['features'] ?? null) ? count($isiGarisJalan['features']) : 0;
}

$berkasGarisJalanPanjang = __DIR__ . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-jalan-dari-sumbu-panjang.geojson';
$jumlahGarisJalanPanjang = 0;
if (is_file($berkasGarisJalanPanjang)) {
    $isiGarisJalanPanjang = json_decode((string) file_get_contents($berkasGarisJalanPanjang), true);
    $jumlahGarisJalanPanjang = is_array($isiGarisJalanPanjang['features'] ?? null)
        ? count($isiGarisJalanPanjang['features']) : 0;
}

$berkasSumbuManual = __DIR__ . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-sumbu-manual.geojson';
$jumlahSumbuManual = 0;
if (is_file($berkasSumbuManual)) {
    $isiSumbuManual = json_decode((string) file_get_contents($berkasSumbuManual), true);
    $jumlahSumbuManual = is_array($isiSumbuManual['features'] ?? null) ? count($isiSumbuManual['features']) : 0;
}

$berkasBatasManual = __DIR__ . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-luar-desa-manual.geojson';
$jumlahBatasManual = 0;
if (is_file($berkasBatasManual)) {
    $isiBatasManual = json_decode((string) file_get_contents($berkasBatasManual), true);
    $jumlahBatasManual = is_array($isiBatasManual['features'] ?? null) ? count($isiBatasManual['features']) : 0;
}

$berkasTitikObyek = __DIR__ . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/titik-obyek-manual.geojson';
$jumlahTitikObyek = 0;
if (is_file($berkasTitikObyek)) {
    $isiTitikObyek = json_decode((string) file_get_contents($berkasTitikObyek), true);
    $jumlahTitikObyek = is_array($isiTitikObyek['features'] ?? null) ? count($isiTitikObyek['features']) : 0;
}

$jumlahBidangManual = (int) db_value(
    "SELECT COUNT(*) FROM bidang WHERE sumber = 'garis-manual' AND status <> 'dihapus'"
);

sip_header('Peta Induk', 'peta-lama', ['leaflet' => true]);
sip_judul(
    'Peta Induk',
    'Peta DXF Desa Gedong',
    'Layer MASTER.dxf hasil konversi dari aplikasi lama. Koordinatnya lokal hasil pengukuran, '
        . 'bukan titik GPS, sehingga tidak ditumpuk di atas peta jalan atau citra satelit.'
);
?>

<div class="grid grid-sidebar">
    <div>
        <section class="kartu">
            <header class="kepala-kartu kepala-peta">
                <div>
                    <h2>Layer DXF</h2>
                    <p>Sumber: files/sig-pertanahan-gedong-lokal/data/dxf-layers</p>
                </div>
                <div class="baris-tombol baris-peta">
                    <select class="pilih-kecil" id="fokusBlokPerkiraan"
                            data-transformasi="<?= e(json_encode(DXF_KE_WGS84, JSON_UNESCAPED_SLASHES)) ?>"
                            aria-label="Pilih blok perkiraan untuk difokuskan">
                        <option value="">Pilih blok perkiraan</option>
                        <?php foreach ($blokPerkiraan as $bl): ?>
                            <option value="<?= e((string) $bl['nomor']) ?>"><?= e($bl['kode']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="tombol tombol-kecil" type="button" id="fokusBlokPerkiraanBtn">Fokus blok</button>
                    <button class="tombol tombol-kecil" type="button" id="pusatkanLama">Pusatkan</button>
                    <button class="tombol tombol-kecil" type="button" id="alatPenunjuk" aria-pressed="false">Penunjuk titik</button>
                    <?php if ($kelola): ?>
                        <button class="tombol tombol-kecil" type="button" id="alatSumbu" aria-pressed="false">Garis manual</button>
                    <?php endif; ?>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('peta.php')) ?>">Peta bidang</a>
                </div>
            </header>

            <div class="peta-wadah">
                <div id="petaLama" class="peta" aria-label="Peta DXF koordinat lokal Desa Gedong"></div>
            </div>

            <div class="legenda">
                <span><i style="background:#bed7e8"></i>Bidang kadastrum</span>
                <span><i style="background:#14603a; border-radius:50%"></i>Titik tengah bidang</span>
                <span><i style="background:#dc2626; border-radius:50%"></i>Penanda titik</span>
                <span><i style="background:#f87171"></i>Bidang pratinjau</span>
                <span><i style="background:#d946ef; border-radius:50%"></i>Penanda massal</span>
                <span><i style="background:#166534"></i>Bidang batch baru</span>
                <span><i style="background:#d97706"></i>Batch kembar</span>
                <span><i style="background:#5b7893"></i>Garis terbuka</span>
                <span><i style="background:#14603a"></i>Blok ukur</span>
                <span><i style="background:#a06a12"></i>Blok perkiraan</span>
                <span><i style="background:#c2410c"></i>Blok mentah</span>
                <span><i style="background:#087b83"></i>Label perairan &amp; nama</span>
                <span><i style="background:#3fa383"></i>Bidang HM</span>
                <span><i style="background:#166534"></i>Garis HM</span>
                <span><i style="background:#b45309"></i>Optimalisasi HM</span>
                <span><i style="background:#7c3aed"></i>Lain-lain</span>
                <span><i style="background:#0f766e"></i>Garis jalan</span>
                <span><i style="background:#0ea5e9"></i>Garis selokan</span>
                <span><i style="background:#1d4ed8"></i>Garis sungai</span>
                <span><i style="background:#a16207"></i>Garis tanpa label</span>
                <span><i style="background:#9d174d"></i>Sumbu jalan</span>
                <span><i style="background:#7e22ce"></i>Jalan 5 sumbu (cincin)</span>
                <span><i style="background:#0891b2; border-radius:50%"></i>Titik obyek</span>
                <span><i style="background:#f59e0b"></i>Bidang manual</span>
                <span><i style="background:#d97706"></i>Calon bidang</span>
                <span><i style="background:#64748b"></i>Label NIB &amp; batas</span>
            </div>
            <p class="petunjuk" id="infoLama">Menyiapkan layer…</p>
        </section>
    </div>

    <aside>
        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>Penanda titik</h2><p>Klik “Penunjuk titik” di atas peta, lalu klik peta</p></div>
            </header>
            <div class="isi-kartu">
                <dl class="daftar-kecil">
                    <li><span class="redup">X</span><span class="rapek-kanan" id="penandaX">–</span></li>
                    <li><span class="redup">Y</span><span class="rapek-kanan" id="penandaY">–</span></li>
                    <li><span class="redup">Luas</span><span class="rapek-kanan" id="penandaLuas">–</span></li>
                    <li><span class="redup">Sumber</span><span class="rapek-kanan" id="penandaSumber">–</span></li>
                    <li><span class="redup">Kode calon</span><span class="rapek-kanan" id="penandaKode">–</span></li>
                </dl>
                <div class="baris-tombol jarak-atas">
                    <button class="tombol tombol-kecil" type="button" id="pratinjauPenanda" disabled>Pratinjau bidang</button>
                    <button class="tombol tombol-kecil" type="button" id="garisBaruPenanda" disabled>Bidang dari garis sekitar</button>
                    <button class="tombol tombol-kecil tombol-utama" type="button" id="simpanPenanda" style="display:none">Simpan bidang</button>
                    <button class="tombol tombol-kecil" type="button" id="hapusPenanda" disabled>Hapus penanda</button>
                </div>
                <a class="tombol tombol-kecil jarak-atas" id="keDigitasi" href="<?= e(sip_url('digitasi-baru.php', ['dari' => 'pratinjau'])) ?>">Digitasi Obyek Baru</a>
                <p class="petunjuk" id="infoPenanda">Penunjuk belum aktif. Koordinat X/Y berasal dari DXF lokal, bukan GPS.</p>
                <p class="petunjuk">Pratinjau memakai poligon tertutup 020100 yang memuat penanda; bila titik di luar, dipakai garis bidang tertutup (LineString yang ujungnya bertemu), lalu rangkaian garis 020100. Bila titik ada di celah di antara Bidang kadastrum, pratinjau memilih muka terkecil yang dibatasi garis dan tepi cincin poligon 020100, jadi bidang baru mengisi celah itu; penanda yang jatuh tepat di tepi garis (kurang dari 1 m di luar muka) memakai muka terkecil di tepi itu. “Bidang dari garis sekitar” memakai garis terbuka saja. Bidang baru berstatus perlu verifikasi, tanpa blok, dan hanya dapat disimpan Super Admin setelah pratinjau.</p>
            </div>
        </section>

        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>Penanda massal</h2><p>Banyak penanda, satu kali pratinjau</p></div>
            </header>
            <div class="isi-kartu">
                <label class="cek"><input type="checkbox" id="modeBatch" data-batas="150">
                    Mode banyak penanda — klik peta menambah penanda</label>
                <dl class="daftar-kecil">
                    <li><span class="redup">Penanda</span><span class="rapek-kanan" id="batchJumlah">0</span></li>
                    <li><span class="redup">Bidang baru</span><span class="rapek-kanan" id="batchBaru">–</span></li>
                    <li><span class="redup">Kembar</span><span class="rapek-kanan" id="batchKembar">–</span></li>
                    <li><span class="redup">Gagal</span><span class="rapek-kanan" id="batchGagal">–</span></li>
                </dl>
                <div class="baris-tombol jarak-atas">
                    <button class="tombol tombol-kecil" type="button" id="batchPratinjau" disabled>Pratinjau semua</button>
                    <button class="tombol tombol-kecil tombol-utama" type="button" id="batchSimpan" style="display:none">Simpan semua</button>
                    <button class="tombol tombol-kecil" type="button" id="batchHapus" disabled>Kosongkan</button>
                </div>
                <div class="baris-tombol jarak-atas">
                    <button class="tombol tombol-kecil" type="button" id="batchGabung" disabled>Gabung terpilih</button>
                    <button class="tombol tombol-kecil" type="button" id="batchLepasGabung" disabled>Lepas gabung</button>
                </div>
                <ul class="daftar-kecil jarak-atas" id="batchDaftar"></ul>
                <p class="petunjuk" id="infoBatch">Nyalakan mode banyak penanda lalu klik titik-titik yang diinginkan. Satu klik pada penanda merah muda menghapusnya.</p>
                <p class="petunjuk">Setiap penanda diturunkan dengan rumus yang sama seperti penanda tunggal, termasuk muka garis untuk kantong terbuka. Yang sudah punya bidang kembar (≥95% dua arah) ditandai dilewati. Seluruh batch dihitung dan disimpan dalam satu permintaan; penyimpanan hanya untuk Super Admin dan hanya bila ada bidang baru. Baris “bidang baru” bisa dicentang: dua baris atau lebih yang bersambung digabung menjadi satu bidang sebelum disimpan, dan “Lepas gabung” mengembalikannya.</p>
            </div>
        </section>

        <?php if ($kelola): ?>
            <section class="kartu">
                <header class="kepala-kartu">
                    <div><h2>Garis manual</h2><p>Gambar garis pada lapis Sumbu jalan dan Garis luar desa, atau gambar bidang tanah baru</p></div>
                </header>
                <div class="isi-kartu">
                    <div class="lapangan">
                        <label for="garisLapisan">Lapis</label>
                        <select class="pilih-kecil" id="garisLapisan">
                            <option value="garis-jalan-sumbu">Sumbu jalan</option>
                            <option value="010500">Garis luar desa</option>
                            <option value="bidang">Bidang</option>
                        </select>
                    </div>
                    <dl class="daftar-kecil jarak-atas">
                        <li><span class="redup">Titik</span><span class="rapek-kanan" id="sumbuJumlah">0</span></li>
                        <li><span class="redup" id="sumbuLabelPanjang">Panjang</span><span class="rapek-kanan" id="sumbuPanjang">–</span></li>
                        <li id="barisSumbuLuas" style="display:none"><span class="redup">Luas</span><span class="rapek-kanan" id="sumbuLuas">–</span></li>
                    </dl>
                    <div class="lapangan jarak-atas">
                        <label for="sumbuLabel" id="sumbuLabelTeks">Label garis</label>
                        <input id="sumbuLabel" maxlength="60" placeholder="JALAN" autocomplete="off">
                    </div>
                    <div class="baris-tombol jarak-atas">
                        <button class="tombol tombol-kecil" type="button" id="sumbuUndo" disabled>Hapus titik terakhir</button>
                        <button class="tombol tombol-kecil" type="button" id="sumbuBatal" disabled>Batal</button>
                        <button class="tombol tombol-kecil tombol-utama" type="button" id="sumbuSimpan" disabled>Simpan garis</button>
                    </div>
                    <p class="petunjuk jarak-atas">Klik garis manual pada lapis terpilih di peta, atau di dekatnya, untuk memilihnya; lalu potong di satu titik atau hapus seluruh garis.</p>
                    <dl class="daftar-kecil">
                        <li><span class="redup">Garis terpilih</span><span class="rapek-kanan" id="sumbuGarisTerpilih">–</span></li>
                    </dl>
                    <div class="baris-tombol jarak-atas">
                        <button class="tombol tombol-kecil" type="button" id="sumbuPotong" disabled>Potong di titik</button>
                        <button class="tombol tombol-kecil" type="button" id="sumbuHapusGaris" disabled>Hapus garis</button>
                        <button class="tombol tombol-kecil" type="button" id="sumbuLepas" disabled>Lepas pilihan</button>
                    </div>
                    <div class="baris-tombol jarak-atas">
                        <button class="tombol tombol-kecil" type="button" id="sumbuHapusTersimpan"
                                <?= $jumlahSumbuManual === 0 ? 'disabled' : '' ?>>Hapus garis tersimpan terakhir</button>
                    </div>
                    <p class="petunjuk" id="infoSumbu" data-tersimpan="<?= (int) $jumlahSumbuManual ?>"
                        data-tersimpan-batas="<?= (int) $jumlahBatasManual ?>"
                        data-tersimpan-bidang="<?= (int) $jumlahBidangManual ?>">Alat mati. Sumbu jalan tersimpan: <?= e(angka($jumlahSumbuManual)) ?>.</p>
                    <p class="petunjuk">Tiap lapis garis punya berkas manual sendiri (garis-sumbu-manual.geojson dan
                        garis-luar-desa-manual.geojson), terpisah dari berkas turunan supaya tidak terhapus saat
                        alat penurunan dijalankan ulang, lalu digabung saat lapisnya ditampilkan. Garis yang
                        digambar berupa garis terbuka; klik lagi titik awalnya di ujung untuk menutup keliling
                        desa. Panjang dihitung dari jarak lurus antar titik dalam meter DXF, jadi titik yang
                        jarang membuat panjang tercatat lebih pendek daripada lengkung aslinya. Titik minimal
                        dua; penyimpanan hanya untuk Super Admin dan Admin.</p>
                    <p class="petunjuk">Lapis Bidang membentuk bidang tanah baru, bukan garis: gambar minimal
                        tiga sudut dan kelilingnya ditutup otomatis. Simpanannya masuk tabel bidang dengan kode
                        MANUAL-B0001 dan seterusnya, status perlu_verifikasi, sumber garis-manual, dan hak BLM,
                        lalu tampil sebagai lapis “Bidang manual”. Verifikasi dilakukan di Data Bidang: klik
                        popup bidangnya, pilih “Buka detail bidang”, lalu ubah status menjadi Aktif; blok dan
                        dusun sebaiknya ditetapkan lebih dulu, tetapi tidak wajib. Bidang tidak bisa dipotong —
                        hapus lalu gambar ulang — dan hapus hanya menandai status dihapus sehingga kode lamanya
                        tidak dipakai ulang.</p>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($kelola): ?>
            <section class="kartu">
                <header class="kepala-kartu">
                    <div><h2>Penanda titik obyek</h2><p>Tandai bangunan, pohon, atau obyek lain di peta</p></div>
                </header>
                <div class="isi-kartu">
                    <div class="lapangan">
                        <label for="obyekNama">Nama obyek</label>
                        <input id="obyekNama" maxlength="80" placeholder="Misalnya Balai desa" autocomplete="off">
                    </div>
                    <div class="lapangan jarak-atas">
                        <label for="obyekJenis">Jenis</label>
                        <select class="pilih-kecil" id="obyekJenis">
                            <option value="Bangunan">Bangunan</option>
                            <option value="Pohon">Pohon</option>
                            <option value="Sumur">Sumur</option>
                            <option value="Patok">Patok</option>
                            <option value="Saluran">Saluran</option>
                            <option value="Lainnya">Lainnya</option>
                        </select>
                    </div>
                    <div class="baris-tombol jarak-atas">
                        <button class="tombol tombol-kecil" type="button" id="alatObyek" aria-pressed="false">Pasang titik</button>
                        <button class="tombol tombol-kecil" type="button" id="obyekBatal" disabled>Batal</button>
                        <button class="tombol tombol-kecil tombol-utama" type="button" id="obyekSimpan" disabled>Simpan titik</button>
                    </div>
                    <dl class="daftar-kecil jarak-atas">
                        <li><span class="redup">Titik tersimpan</span><span class="rapek-kanan" id="obyekJumlah"><?= e(angka($jumlahTitikObyek)) ?></span></li>
                        <li><span class="redup">Titik baru</span><span class="rapek-kanan" id="obyekPosisi">–</span></li>
                    </dl>
                    <ul class="daftar-kecil jarak-atas" id="obyekDaftar"></ul>
                    <p class="petunjuk" id="infoObyek" data-tersimpan="<?= (int) $jumlahTitikObyek ?>">Alat mati. Titik obyek tersimpan: <?= e(angka($jumlahTitikObyek)) ?>.</p>
                    <p class="petunjuk">Titik obyek adalah penanda kerja, bukan bidang tanah: tidak masuk tabel bidang dan
                        tidak mengubah blok. Simpanannya satu berkas GeoJSON
                        (titik-obyek-manual.geojson) yang ikut tampil sebagai lapis “Titik obyek”. Klik baris daftar
                        untuk menyorot titiknya, atau Hapus untuk membuang satu titik. Klik peta sekali lagi untuk
                        memindahkan titik baru yang belum disimpan.</p>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($pengguna['peran'] === PERAN_SUPER): ?>
            <section class="kartu">
                <header class="kepala-kartu">
                    <div><h2>Buat bidang dari layer</h2><p>Salin semua poligon tertutup dari satu layer ke daftar bidang.</p></div>
                </header>
                <div class="isi-kartu">
                    <form method="post" action="<?= e(sip_url('peta-lama.php')) ?>"
                          data-konfirmasi="Buat bidang tanah dari semua poligon pada layer yang dipilih? Khusus 020100, garis yang tersambung menjadi poligon tertutup juga dibuatkan bidang; garis terbuka dilewati. Baris baru perlu diverifikasi dan tidak mendapat blok otomatis. Poligon yang sudah ada atau hampir sama akan dilewati.">
                        <?= csrf_input() ?>
                        <div class="lapangan">
                            <label for="lapisan-bidang">Layer sumber</label>
                            <select class="pilih-kecil" id="lapisan-bidang" name="lapisan_bidang">
                                <?php foreach (LAPISAN_POLIGON_PETA_INDUK as $kunci => $sumber): ?>
                                    <option value="<?= e($kunci) ?>">
                                        <?= e($sumber['nama']) ?> (<?= e(angka($jumlahPoligonLayer[$kunci] ?? 0)) ?>
                                        <?= $kunci === '020100' ? 'fitur bidang &amp; garis' : 'poligon' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="petunjuk">
                            Koordinat lokal DXF akan dikonversi ke WGS84. Bidang baru berstatus
                            <strong>perlu verifikasi</strong>, tanpa blok dan jenis hak sementara BLM.
                            Kode dibuat otomatis; impor ulang melewati kode dan poligon yang sudah ada.
                        </p>
                        <p class="petunjuk">
                            Semua poligon layer terpilih dibuat sebagai bidang, termasuk 010500 (batas desa)
                            dan BATAS BLOK. Gunakan hanya jika geometri layer tersebut memang ingin dimasukkan
                            ke daftar bidang.
                        </p>
                        <p class="petunjuk">
                            Khusus 020100, garis yang tersambung menjadi cincin tertutup juga disusun menjadi
                            poligon. Garis terbuka yang tidak membentuk bidang tertutup dilewati.
                        </p>
                        <button class="tombol tombol-utama jarak-atas" type="submit">Buat bidang dari layer</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>Tampilkan layer</h2><p>Centang untuk menampilkan</p></div>
            </header>
            <div class="lapisan-kontrol">
                <p class="petunjuk">Bidang kadastrum</p>
                <label class="cek" title="Layer DXF 020100">
                    <input type="checkbox" data-lapisan="020100" checked>
                    Bidang &amp; garis (<?= e(angka($jumlah('020100'))) ?>)</label>
                <label class="cek">
                    <input type="checkbox" id="tampilTitikLama" checked>
                    Titik tengah <b id="jumlahTitikLama" class="redup"></b></label>
                <label class="cek" title="Layer DXF BATAS BLOK">
                    <input type="checkbox" data-lapisan="batas-blok">
                    Blok ukur (<?= e(angka($jumlahBatasBlok)) ?>)</label>
                <label class="cek" title="72 ruas DXF mentah">
                    <input type="checkbox" data-lapisan="batas-blok-mentah">
                    Blok mentah (<?= e(angka($jumlah('BATAS BLOK'))) ?>)</label>
                <label class="cek" title="Layer DXF BATAS PERAIRAN">
                    <input type="checkbox" data-lapisan="batas-perairan">
                    Label perairan (<?= e(angka($jumlah('BATAS PERAIRAN'))) ?>)</label>
                <label class="cek" title="Layer DXF tn_nama">
                    <input type="checkbox" data-lapisan="tn-nama">
                    Label nama (<?= e(angka($jumlah('tn_nama'))) ?>)</label>
                <label class="cek" title="Layer DXF lb_lai">
                    <input type="checkbox" data-lapisan="lb-lai">
                    Lain-lain (<?= e(angka($jumlah('lb_lai'))) ?>)</label>
                <p class="petunjuk">Titik tengah menandai tiap poligon tertutup. Blok perkiraan
                    digaris putus-putus dan ruas mentah belum tentu menutup batas ukur.</p>
                <p class="petunjuk">Bidang berhak</p>
                <label class="cek" title="Layer DXF BIDANG HM">
                    <input type="checkbox" data-lapisan="bidang-hm">
                    Bidang (<?= e(angka($jumlah('BIDANG HM'))) ?>)</label>
                <label class="cek" title="Layer DXF HM">
                    <input type="checkbox" data-lapisan="hm">
                    Garis (<?= e(angka($jumlah('HM'))) ?>)</label>
                <label class="cek" title="Layer DXF OPTIMALISASI HM">
                    <input type="checkbox" data-lapisan="optimalisasi-hm">
                    Optimalisasi (<?= e(angka($jumlah('OPTIMALISASI HM'))) ?>)</label>
                <p class="petunjuk">Garis turunan</p>
                <label class="cek" title="Layer GARIS JALAN DARI SUMBU">
                    <input type="checkbox" data-lapisan="garis-jalan" checked>
                    Jalan (<?= e(angka($jumlahGarisJalan)) ?>)</label>
                <label class="cek" title="Garis tepi tertutup dari 5 sumbu terpanjang, cincin keliling + jahit pertemuan antarsumbu (tools/garis-jalan-dari-sumbu.php --sumbu=rapi --maks=5 --tutup=40)">
                    <input type="checkbox" data-lapisan="garis-jalan-panjang" checked>
                    Jalan 5 sumbu (<?= e(angka($jumlahGarisJalanPanjang)) ?>)</label>
                <label class="cek" title="Layer GARIS SELOKAN 020100">
                    <input type="checkbox" data-lapisan="garis-selokan">
                    Selokan (<?= e(angka($jumlah('GARIS SELOKAN 020100'))) ?>)</label>
                <label class="cek" title="Layer GARIS BATAS PERAIRAN 020100">
                    <input type="checkbox" data-lapisan="garis-perairan">
                    Sungai (<?= e(angka($jumlah('GARIS BATAS PERAIRAN 020100'))) ?>)</label>
                <label class="cek" title="Garis perairan yang disambung menjadi rantai tepi (tools/tepi-sungai.php); geometri sama dengan lapis Sungai, hanya digabung dan dijadikan satu garis per tepian">
                    <input type="checkbox" data-lapisan="garis-tepi-sungai">
                    Tepi sungai (<?= e(angka($jumlah('GARIS TEPI SUNGAI 020100'))) ?>)</label>
                <label class="cek" title="Layer GARIS LAIN 020100">
                    <input type="checkbox" data-lapisan="garis-lain">
                    Tanpa label (<?= e(angka($jumlah('GARIS LAIN 020100'))) ?>)</label>
                <p class="petunjuk">Selokan, Sungai, dan Tanpa label diturunkan dari 13.719
                    kandidat layer 020100 — 13.681 garis terbuka dan 38 cincin poligon. Kelas
                    dibaca dari teks label, bukan dari berkas asalnya. Jalan kini berisi garis
                    tepi yang diturunkan dari semua sumbu jalan oleh
                    tools/garis-jalan-dari-sumbu.php. Perhitungan kelas ada di README §3.77.
                    Tepi sungai menyambung ulang lapis Sungai menjadi 126 rantai (12.349,52 m)
                    dengan tools/tepi-sungai.php; geometrinya tidak digeser, hanya disatukan.</p>
                <p class="petunjuk">Turunan Jalan dua tahap</p>
                <label class="cek" title="Layer GARIS JALAN SUMBU DARI LABEL, dirapikan: garis kembar dibuang lalu dijahit">
                    <input type="checkbox" data-lapisan="garis-jalan-sumbu" checked>
                    Sumbu jalan (<b id="jumlahSumbuJalan"
                        data-turunan="<?= (int) $jumlah('GARIS JALAN SUMBU DARI LABEL') ?>"><?= e(angka($jumlah('GARIS JALAN SUMBU DARI LABEL') + $jumlahSumbuManual)) ?></b>)</label>
                <p class="petunjuk">Garis tepi terbit sebagai pil Jalan di kelompok di atas,
                    diturunkan tools/garis-jalan-dari-sumbu.php dari SELURUH sumbu jalan:
                    tiap titik sampel sumbu dipasangkan ke garis sisi bidang 020100 terdekat
                    di kiri dan kanan. Sumbu dihitung tools/garis-jalan-label.php dari pecahan
                    jalan; sumbu yang digambar dengan alat Sumbu jalan di halaman ini ikut
                    tampil di lapis yang sama, dan berkasnya tetap terpisah supaya tidak
                    terhapus saat alat penurunan dijalankan ulang. Perhitungannya ada di
                    README §3.80.</p>
                <p class="petunjuk">Angka di atas menghitung sumbu bahan. Yang digambar di
                    peta sudah dirapikan app/sumbu-rapi.php: garis yang menumpang garis lain
                    (≥90% sampelnya dalam 0,6 m) dibuang, sisanya dijahit jadi polilin utuh
                    dengan simpul 0,35 m dan belokan lanjutan ≤40°, jadi jumlah garis yang
                    tampil lebih sedikit. Garis manual yang sudah dijahit tetap bisa dipilih,
                    dipotong, dan dihapus lewat klik di dekatnya.</p>
                <p class="petunjuk">Lainnya</p>
                <label class="cek" title="Layer DXF 010500">
                    <input type="checkbox" data-lapisan="010500">
                    Garis luar desa (<b id="jumlahGarisLuarDesa"
                        data-turunan="<?= (int) $jumlah('010500') ?>"><?= e(angka($jumlah('010500') + $jumlahBatasManual)) ?></b>)</label>
                <label class="cek" title="Layer DXF 020400, artinya belum diketahui">
                    <input type="checkbox" data-lapisan="020400">
                    Sisa 020400 (<?= e(angka($jumlah('020400'))) ?>)</label>
                <p class="petunjuk">Obyek manual</p>
                <label class="cek" title="Titik obyek dari alat Penanda titik obyek">
                    <input type="checkbox" data-lapisan="titik-obyek" checked>
                    Titik obyek (<b id="jumlahTitikObyek"><?= e(angka($jumlahTitikObyek)) ?></b>)</label>
                <label class="cek" title="Bidang dari alat Garis manual (tabel bidang, sumber garis-manual)">
                    <input type="checkbox" data-lapisan="bidang-manual" checked>
                    Bidang manual (<b id="jumlahBidangManual" data-turunan="0"
                        data-tersimpan="<?= (int) $jumlahBidangManual ?>"><?= e(angka($jumlahBidangManual)) ?></b>)</label>
                <p class="petunjuk">Belum terpetakan</p>
                <label class="cek" title="Garis 020100 yang hampir tertutup">
                    <input type="checkbox" data-lapisan="calon-bidang" checked>
                    Calon bidang (<?= e(angka($jumlah('CALON BIDANG'))) ?>)</label>
                <p class="petunjuk">Label teks</p>
                <label class="cek" title="Layer DXF NIB HM">
                    <input type="checkbox" data-lapisan="nib-hm">
                    Label NIB (<?= e(angka($jumlah('NIB HM'))) ?>)</label>
                <label class="cek" title="Layer DXF BATAS DESA">
                    <input type="checkbox" data-lapisan="batas-desa">
                    Label batas desa (<?= e(angka($jumlah('BATAS DESA'))) ?>)</label>
                <label class="cek" title="Layer DXF BLOK">
                    <input type="checkbox" data-lapisan="blok">
                    Label blok (<?= e(angka($jumlah('BLOK'))) ?>)</label>
                <label class="cek" title="Layer DXF BATAS JALAN">
                    <input type="checkbox" data-lapisan="batas-jalan">
                    Label jalan (<?= e(angka($jumlah('BATAS JALAN'))) ?>)</label>
            </div>
            <p class="petunjuk">Bidang kadastrum, kedua lapis turunan Jalan, calon bidang, titik obyek,
                dan bidang manual dimuat lebih dulu; sisanya mati secara bawaan agar peta tetap ringan.</p>
            <p class="petunjuk">Calon bidang adalah garis 020100 yang ujungnya hampir bertemu
                (celah ≤ 1 unit, luas ≥ 20 unit²) dan belum tertutup sebagai bidang. Yang bentuknya
                sama dengan poligon tertutup dilewati (<?= e(angka((int) ($meta['layers']['CALON BIDANG']['duplicates_skipped'] ?? 0))) ?> duplikat),
                begitu pula yang sudah tertutup bidang HM atau poligon 020100
                (<?= e(angka((int) ($meta['layers']['CALON BIDANG']['already_mapped_skipped'] ?? 0))) ?> kandidat),
                sehingga layer ini hanya berisi bidang yang benar-benar belum terpetakan.</p>
        </section>

        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Catatan koordinat</h2></div></header>
            <p class="penjelasan">
                Angka X/Y berasal dari DXF asli dan belum diketahui sistem koordinat geografisnya.
                Peta ini hanya untuk memeriksa isi arsip, bukan untuk navigasi lapangan atau
                pengukuran luas resmi.
            </p>
            <?php if ($rentang !== null): ?>
                <dl class="daftar-kecil jarak-atas">
                    <li><span class="redup">X</span><span class="rapek-kanan"><?= e(angka((float) $rentang['min_x'], 1)) ?> – <?= e(angka((float) $rentang['max_x'], 1)) ?></span></li>
                    <li><span class="redup">Y</span><span class="rapek-kanan"><?= e(angka((float) $rentang['min_y'], 1)) ?> – <?= e(angka((float) $rentang['max_y'], 1)) ?></span></li>
                </dl>
                <p class="petunjuk">Sumber: <?= e((string) ($meta['source_file'] ?? 'MASTER.dxf')) ?>.
                    <?= e((string) ($meta['coordinate_system'] ?? 'Koordinat lokal.')) ?>.</p>
            <?php endif; ?>
            <p class="petunjuk">Aplikasi lama sendiri berjalan dengan PHP + PostgreSQL/PostGIS lewat Docker,
                sehingga tidak dijalankan dari XAMPP; halaman ini hanya menampilkan layer petanya.</p>
        </section>
    </aside>
</div>

<style>
#petaLama.mode-penunjuk,
#petaLama.mode-penunjuk .leaflet-interactive { cursor: crosshair !important; }
#petaLama.mode-sumbu,
#petaLama.mode-sumbu .leaflet-interactive { cursor: crosshair !important; }
#petaLama.mode-potong,
#petaLama.mode-potong .leaflet-interactive { cursor: crosshair !important; }
#petaLama.mode-obyek,
#petaLama.mode-obyek .leaflet-interactive { cursor: crosshair !important; }
.penanda-pin { background: none; border: none; }
.penanda-pin span {
    display: block;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #dc2626;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(15, 23, 42, .45), 0 0 0 6px rgba(220, 38, 38, .16);
}
.penanda-pin.massal span {
    background: #d946ef;
    box-shadow: 0 0 0 1px rgba(15, 23, 42, .45), 0 0 0 5px rgba(217, 70, 239, .18);
}
.sumbu-titik { background: none; border: none; }
.sumbu-titik span {
    display: block;
    width: 10px;
    height: 10px;
    border-radius: 2px;
    background: #db2777;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(15, 23, 42, .45);
}
.obyek-pin { background: none; border: none; }
.obyek-pin span {
    display: block;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #0891b2;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(15, 23, 42, .45), 0 0 0 6px rgba(8, 145, 178, .22);
}
#batchDaftar li { cursor: pointer; }
#batchDaftar li:hover { background: #f2f7f4; }
#batchDaftar .cek-baris { align-self: center; margin: 0 2px 0 0; }
#obyekDaftar li { cursor: pointer; }
#obyekDaftar li:hover { background: #f2f7f4; }
#obyekDaftar .hapus-obyek { padding: 2px 8px; font-size: 11px; }
@media (max-width: 720px) {
    .kepala-peta { flex-wrap: wrap; }
    .baris-peta { width: 100%; }
    .baris-peta .pilih-kecil { flex: 1 1 100%; width: 100%; }
}
</style>

<?php
$naskah = <<<'JS'
<script>
(function () {
    'use strict';
    const peta = L.map('petaLama', {
        crs: L.CRS.Simple,
        minZoom: -8,
        maxZoom: 5,
        zoomSnap: .25,
        zoomDelta: .5,
        preferCanvas: true
    }).setView([715800, 263000], -3);

    const info = document.getElementById('infoLama');
    const grup = {};
    const grupTitik = L.layerGroup();
    const grupPenanda = L.layerGroup().addTo(peta);
    const grupPratinjau = L.featureGroup().addTo(peta);
    let penanda = null;
    let pratinjau = null;
    let modePenunjuk = false;

    const grupBatch = L.layerGroup().addTo(peta);
    const grupBatchPratinjau = L.featureGroup().addTo(peta);
    let titikMassal = [];
    let pinMassal = [];
    let pratinjauMassal = null;
    let sorotMassal = {};
    let modeMassal = false;
    const barisMassalTerpilih = new Set();
    const sorotanBlok = L.featureGroup().addTo(peta);
    const memuat = new Set();

    const grupSumbu = L.featureGroup().addTo(peta);
    const elemenInfoSumbu = document.getElementById('infoSumbu');
    const LAPIS_MANUAL = {
        'garis-jalan-sumbu': { nama: 'Sumbu jalan', labelJumlah: 'jumlahSumbuJalan', labelBawaan: 'JALAN' },
        '010500': { nama: 'Garis luar desa', labelJumlah: 'jumlahGarisLuarDesa', labelBawaan: 'BATAS DESA' },
        'bidang': { nama: 'Bidang', labelJumlah: 'jumlahBidangManual', labelBawaan: 'BIDANG', tampil: 'bidang-manual' }
    };
    let lapisManual = 'garis-jalan-sumbu';
    const jumlahManual = {
        'garis-jalan-sumbu': elemenInfoSumbu ? (Number(elemenInfoSumbu.dataset.tersimpan) || 0) : 0,
        '010500': elemenInfoSumbu ? (Number(elemenInfoSumbu.dataset.tersimpanBatas) || 0) : 0,
        'bidang': elemenInfoSumbu ? (Number(elemenInfoSumbu.dataset.tersimpanBidang) || 0) : 0
    };
    let titikSumbu = [];
    let modeSumbu = false;
    const sorotanSumbu = L.featureGroup().addTo(peta);
    let sumbuTerpilih = null;
    let modePotongSumbu = false;

    const elemenInfoObyek = document.getElementById('infoObyek');
    const grupObyekBaru = L.featureGroup().addTo(peta);
    const sorotanObyek = L.featureGroup().addTo(peta);
    let jumlahTitikObyek = elemenInfoObyek ? (Number(elemenInfoObyek.dataset.tersimpan) || 0) : 0;
    let titikObyekBaru = null;
    let modeObyek = false;

    function namaLapisManual() {
        return LAPIS_MANUAL[lapisManual].nama;
    }

    function lapisTampilManual(nama) {
        const kunci = nama || lapisManual;
        return LAPIS_MANUAL[kunci].tampil || kunci;
    }

    function modeBidang() {
        return lapisManual === 'bidang';
    }

    function infoDiam() {
        return 'Alat mati. ' + namaLapisManual() + ' tersimpan: ' + Sip.angka(jumlahManual[lapisManual]) + '.';
    }

    function ikonLabel(teks, kelas) {
        const isi = Sip.esc(teks || '');
        return L.divIcon({
            className: 'cad-label ' + (kelas || ''),
            html: isi,
            iconSize: [Math.max(20, isi.length * 6), 14],
            iconAnchor: [0, 7]
        });
    }

    function gayaBidangDxf(f) {
        const p = f.properties || {};
        if (p.closed) {
            return { color: '#27618f', weight: .8, fillColor: '#6da5d2', fillOpacity: .10 };
        }
        return { color: '#5b7893', weight: .55, opacity: .72 };
    }

    const KELAS_LABEL = {
        'nib-hm': 'nib-label',
        'batas-desa': 'desa-label',
        'blok': 'blok-label',
        'batas-jalan': 'jalan-label'
    };

    const LABEL_JENIS_PENANDA = {
        'tertutup': 'poligon tertutup',
        'garis-tertutup': 'garis bidang tertutup',
        'garis': 'rangkaian garis',
        'garis-baru': 'garis dari tepi sekitar',
        'kadastrum': 'Bidang kadastrum',
        'gabungan': 'gabungan muka'
    };

    function gayaLapisan(nama) {
        if (nama === 'bidang-hm') {
            return (f) => (f.properties && f.properties.closed)
                ? { color: '#1f7a5c', weight: 1.2, fillColor: '#3fa383', fillOpacity: .18 }
                : { color: '#1f7a5c', weight: .7, opacity: .85 };
        }
        if (nama === 'hm') {
            return (f) => (f.properties && f.properties.closed)
                ? { color: '#166534', weight: 1.8, fillColor: '#4ea87b', fillOpacity: .10 }
                : { color: '#166534', weight: 1.5, opacity: .9 };
        }
        if (nama === 'optimalisasi-hm') return () => ({ color: '#b45309', weight: 1.6, dashArray: '6 4', opacity: .95 });
        if (nama === 'lb-lai') return () => ({ color: '#7c3aed', weight: 1.4, dashArray: '2 4', opacity: .9 });
        if (nama === 'garis-jalan') return () => ({ color: '#0f766e', weight: 1.1, opacity: .85 });
        if (nama === 'garis-jalan-panjang') return () => ({ color: '#7e22ce', weight: 2.4, opacity: .9 });
        if (nama === 'garis-selokan') return () => ({ color: '#0ea5e9', weight: 1.4, opacity: .95 });
        if (nama === 'garis-perairan') return () => ({ color: '#1d4ed8', weight: 1.7, opacity: .95 });
        if (nama === 'garis-tepi-sungai') return () => ({ color: '#0e7490', weight: 2.1, opacity: .95 });
        if (nama === 'garis-lain') return () => ({ color: '#a16207', weight: 1.3, dashArray: '6 4', opacity: .95 });
        if (nama === 'garis-jalan-sumbu') return () => ({ color: '#9d174d', weight: 2.2, opacity: .95 });
        if (nama === 'bidang-manual') return () => ({
            color: '#b45309', weight: 1.8, opacity: .95, fillColor: '#f59e0b', fillOpacity: .22
        });
        if (nama === '010500') return () => ({ color: '#64748b', weight: 1, opacity: .8 });
        if (nama === '020400') return () => ({ color: '#475569', weight: 1, opacity: .85 });
        if (nama === 'calon-bidang') return () => ({
            color: '#d97706', weight: 1.3, dashArray: '4 3', fillColor: '#f59e0b', fillOpacity: .12
        });
        return gayaBidangDxf;
    }

    function bangun(nama, data) {
        if (nama === 'batas-perairan' || nama === 'tn-nama' || KELAS_LABEL[nama]) {
            const kelas = nama === 'batas-perairan' ? 'water-label' : (KELAS_LABEL[nama] || '');
            return L.geoJSON(data, {
                pointToLayer: (f, ll) => {
                    const p = f.properties || {};
                    if (p.label) {
                        return L.marker(ll, {
                            icon: ikonLabel(p.label, kelas),
                            interactive: false,
                            keyboard: false
                        });
                    }
                    return L.circleMarker(ll, { radius: 3, color: '#64748b', weight: 1, fillOpacity: .8 });
                }
            });
        }
        if (nama === 'batas-blok') {
            return L.geoJSON(data, {
                style: (f) => ({
                    color: f.properties && f.properties.batas_perkiraan ? '#a06a12' : '#14603a',
                    weight: (f.properties && f.properties.batas_perkiraan) ? 2.4 : 2,
                    opacity: .95,
                    fillColor: '#5b9b74',
                    fillOpacity: .06,
                    dashArray: f.properties && f.properties.batas_perkiraan ? '7 5' : null
                })
            });
        }
        if (nama === 'batas-blok-mentah') {
            return L.geoJSON(data, {
                style: () => ({
                    color: '#c2410c',
                    weight: 1.2,
                    opacity: .8,
                    dashArray: '3 5',
                    fillOpacity: 0
                })
            });
        }
        if (nama === 'titik-obyek') {
            return L.geoJSON(data, {
                pointToLayer: (f, ll) => {
                    const p = f.properties || {};
                    const isi = '<strong>' + Sip.esc(p.nama || 'Titik obyek') + '</strong>'
                        + (p.jenis ? '<br>' + Sip.esc(p.jenis) : '')
                        + (p.kode ? '<br>' + Sip.esc(p.kode) : '')
                        + (p.dibuat_oleh
                            ? '<br><span class="redup">' + Sip.esc(p.dibuat_oleh) + ' · '
                                + Sip.esc(p.dibuat_pada || '') + '</span>'
                            : '');
                    return L.circleMarker(ll, {
                        radius: 7, color: '#ffffff', weight: 2, opacity: 1,
                        fillColor: '#0891b2', fillOpacity: 1
                    }).bindPopup(isi);
                }
            });
        }
        if (nama === 'bidang-manual') {
            return L.geoJSON(data, {
                style: gayaLapisan(nama),
                onEachFeature: (f, l) => {
                    const p = f.properties || {};
                    const labelStatus = {
                        aktif: 'Aktif', perlu_verifikasi: 'Perlu verifikasi',
                        duplikat: 'Duplikat', dihapus: 'Dihapus'
                    }[p.status] || p.status;
                    l.bindPopup('<strong>' + Sip.esc(p.kode || 'Bidang manual') + '</strong>'
                        + (p.luas_m2 ? '<br>' + Sip.angka(p.luas_m2, 2) + ' m²' : '')
                        + (labelStatus ? '<br><span class="redup">' + Sip.esc(labelStatus) + '</span>' : '')
                        + (p.id ? '<br><a href="' + Sip.url('bidang.php', { id: p.id })
                            + '">Buka detail bidang</a>' : ''));
                }
            });
        }
        return L.geoJSON(data, {
            style: gayaLapisan(nama),
            pointToLayer: (f, ll) => {
                const p = f.properties || {};
                if (p.label) {
                    return L.marker(ll, { icon: ikonLabel(p.label, ''), interactive: false, keyboard: false });
                }
                return L.circleMarker(ll, {
                    radius: 2, color: '#8b6b2d', weight: 1, fillOpacity: .7
                });
            }
        });
    }

    // Satu titik di pusat kotak batas tiap poligon tertutup 020100, supaya
    // bidang kecil tetap terlihat di antara ribuan garis DXF.
    function bangunTitikBidang(lapisan) {
        grupTitik.clearLayers();
        let jumlah = 0;
        lapisan.eachLayer((l) => {
            const p = l.feature && l.feature.properties;
            if (!p || !p.closed || !l.getBounds) return;
            const batas = l.getBounds();
            if (!batas.isValid()) return;
            L.circleMarker(batas.getCenter(), {
                radius: 2.6, weight: 1, color: '#ffffff', opacity: .9,
                fillColor: '#14603a', fillOpacity: 1, interactive: false
            }).addTo(grupTitik);
            jumlah++;
        });
        document.getElementById('jumlahTitikLama').textContent = Sip.angka(jumlah);
    }

    const ikonPenanda = L.divIcon({
        className: 'penanda-pin',
        html: '<span></span>',
        iconSize: [14, 14],
        iconAnchor: [7, 7]
    });

    function titikDiCincin(cincin, x, y) {
        let dalam = false;
        for (let i = 0, j = cincin.length - 1; i < cincin.length; j = i++) {
            const xi = cincin[i][0];
            const yi = cincin[i][1];
            const xj = cincin[j][0];
            const yj = cincin[j][1];
            if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) {
                dalam = !dalam;
            }
        }
        return dalam;
    }

    function bidangDiTitik(latlng) {
        const lapisan = grup['020100'];
        if (!lapisan) return null;
        let ketemu = null;
        lapisan.eachLayer((l) => {
            if (ketemu || !l.getBounds || !l.getBounds().contains(latlng)) return;
            const f = l.feature;
            const g = f && f.geometry;
            if (!g) return;
            let poligon = [];
            if (g.type === 'Polygon') {
                poligon = [g.coordinates];
            } else if (g.type === 'MultiPolygon') {
                poligon = g.coordinates;
            } else if (g.type === 'LineString') {
                const c = g.coordinates || [];
                if (c.length >= 4 && c[0][0] === c[c.length - 1][0] && c[0][1] === c[c.length - 1][1]) {
                    poligon = [[c]];
                }
            }
            for (const p of poligon) {
                let dalam = false;
                for (const cincin of p) {
                    if (titikDiCincin(cincin, latlng.lng, latlng.lat)) dalam = !dalam;
                }
                if (dalam) {
                    ketemu = f;
                    return;
                }
            }
        });
        return ketemu;
    }

    function koordDxf(nilai) {
        return Sip.angka(nilai, 2);
    }

    function bersihkanPratinjau() {
        grupPratinjau.clearLayers();
        pratinjau = null;
        document.getElementById('penandaLuas').textContent = '–';
        document.getElementById('penandaSumber').textContent = '–';
        document.getElementById('penandaKode').textContent = '–';
        document.getElementById('simpanPenanda').style.display = 'none';
    }

    function hapusPratinjauServer() {
        Sip.api('api/penanda-bidang.php', {
            method: 'POST',
            json: { aksi: 'bersihkan' }
        }).catch(() => {});
    }

    function tampilkanPratinjau(isi) {
        bersihkanPratinjau();
        pratinjau = isi;
        const luas = Sip.angka(isi.luas_m2, 2) + ' m²';
        document.getElementById('penandaLuas').textContent = luas;
        document.getElementById('penandaSumber').textContent = isi.jenis === 'tertutup'
            ? 'Poligon tertutup ' + (isi.handle || 'tanpa handle')
            : isi.jenis === 'garis-tertutup'
                ? 'Garis bidang tertutup ' + (isi.handle || 'tanpa handle')
                : isi.jenis === 'garis-baru'
                    ? 'Garis bidang baru (' + (isi.handle || 'tanpa handle') + ')'
                    : isi.jenis === 'kadastrum'
                        ? 'Muka Bidang kadastrum (' + (isi.handle || 'tanpa handle') + ')'
                        : 'Rangkaian garis 020100';
        document.getElementById('penandaKode').textContent = isi.kode;

        const lapisan = L.geoJSON(
            { type: 'Feature', geometry: isi.geometri, properties: {} },
            {
                style: {
                    color: '#dc2626',
                    weight: 2.4,
                    opacity: 1,
                    fillColor: '#f87171',
                    fillOpacity: .18,
                    dashArray: isi.jenis === 'garis' ? '6 4' : null
                }
            }
        ).addTo(grupPratinjau);
        const batas = lapisan.getBounds();
        if (batas.isValid()) peta.fitBounds(batas.pad(.35), { maxZoom: 2 });

        const tombol = document.getElementById('simpanPenanda');
        tombol.style.display = isi.boleh_simpan ? '' : 'none';
        if (isi.duplikat) {
            document.getElementById('infoPenanda').textContent = 'Bidang serupa sudah ada: '
                + isi.duplikat.kode + (isi.duplikat.alasan === 'kode'
                    ? ' (kode sama)' : ' (geometri ≥95% sama)') + '. Penyimpanan diblokir.';
        } else if (isi.boleh_simpan) {
            document.getElementById('infoPenanda').textContent = 'Pratinjau siap: ' + isi.kode
                + ' · ' + luas + '. Periksa batas, lalu klik Simpan bidang.';
        } else {
            document.getElementById('infoPenanda').textContent = 'Pratinjau siap: ' + isi.kode
                + ' · ' + luas + '. Akun ini tidak berwenang menyimpan bidang.';
        }
        info.textContent = 'Pratinjau ' + isi.kode + ' · ' + luas + ' — '
            + (isi.jenis === 'tertutup' ? 'poligon tertutup 020100.'
                : isi.jenis === 'garis-tertutup' ? 'garis bidang tertutup 020100.'
                    : isi.jenis === 'garis-baru' ? 'garis bidang baru dari garis sekitar 020100.'
                        : isi.jenis === 'kadastrum' ? 'muka tertutup dari Bidang kadastrum 020100.'
                            : 'rangkaian garis 020100.');
    }

    async function mintaPratinjau(aksi = 'pratinjau') {
        if (!penanda) return;
        const tombol = document.getElementById(aksi === 'garis-baru' ? 'garisBaruPenanda' : 'pratinjauPenanda');
        const titik = penanda.getLatLng();
        tombol.disabled = true;
        document.getElementById('infoPenanda').textContent = aksi === 'garis-baru'
            ? 'Membentuk bidang dari garis 020100 di sekitar penanda…'
            : 'Menghitung bidang di sekitar penanda…';
        try {
            const isi = await Sip.api('api/penanda-bidang.php', {
                method: 'POST',
                json: { aksi: aksi, x: titik.lng, y: titik.lat }
            });
            tampilkanPratinjau(isi);
        } catch (err) {
            bersihkanPratinjau();
            document.getElementById('infoPenanda').textContent = err.message;
            info.textContent = 'Pratinjau gagal: ' + err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            tombol.disabled = !penanda;
        }
    }

    async function simpanBidangPenanda() {
        if (!pratinjau) return;
        const tombol = document.getElementById('simpanPenanda');
        const yakin = window.confirm('Simpan bidang ' + pratinjau.kode + ' ('
            + Sip.angka(pratinjau.luas_m2, 2) + ' m²) sebagai bidang perlu verifikasi, tanpa blok?');
        if (!yakin) return;
        tombol.disabled = true;
        try {
            const hasil = await Sip.api('api/penanda-bidang.php', {
                method: 'POST',
                json: { aksi: 'simpan', token: pratinjau.token }
            });
            pratinjau = null;
            tombol.style.display = 'none';
            document.getElementById('infoPenanda').textContent = 'Bidang ' + hasil.kode
                + ' tersimpan sebagai perlu verifikasi (id ' + hasil.id + ').';
            info.textContent = 'Bidang ' + hasil.kode + ' tersimpan — perlu verifikasi, belum punya blok.';
            Sip.pesan(hasil.pesan, 'berhasil');
        } catch (err) {
            document.getElementById('infoPenanda').textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            tombol.disabled = false;
        }
    }

    function pasangPenanda(latlng) {
        if (penanda) {
            penanda.setLatLng(latlng);
        } else {
            penanda = L.marker(latlng, {
                icon: ikonPenanda, interactive: false, keyboard: false, zIndexOffset: 1000
            }).addTo(grupPenanda);
        }
        const f = bidangDiTitik(latlng);
        bersihkanPratinjau();
        hapusPratinjauServer();
        document.getElementById('penandaX').textContent = koordDxf(latlng.lng);
        document.getElementById('penandaY').textContent = koordDxf(latlng.lat);
        document.getElementById('pratinjauPenanda').disabled = false;
        document.getElementById('garisBaruPenanda').disabled = false;
        document.getElementById('hapusPenanda').disabled = false;
        const keterangan = f
            ? (f.geometry.type === 'LineString' ? 'di dalam garis bidang tertutup' : 'di dalam bidang tertutup')
                + ' 020100 (handle ' + ((f.properties || {}).dxf_handle || '—') + ').'
            : 'di luar bidang atau garis bidang tertutup 020100; pratinjau memakai Bidang kadastrum di sekitarnya.';
        document.getElementById('infoPenanda').textContent = 'Penanda ' + keterangan;
        info.textContent = 'X ' + koordDxf(latlng.lng) + ' · Y ' + koordDxf(latlng.lat) + ' — ' + keterangan;
    }

    const ikonPenandaMassal = L.divIcon({
        className: 'penanda-pin massal',
        html: '<span></span>',
        iconSize: [14, 14],
        iconAnchor: [7, 7]
    });

    function aturJumlahMassal() {
        document.getElementById('batchJumlah').textContent = Sip.angka(titikMassal.length);
        document.getElementById('batchPratinjau').disabled = titikMassal.length === 0;
        document.getElementById('batchHapus').disabled = titikMassal.length === 0;
    }

    function aturTombolGabung() {
        const gabung = document.getElementById('batchGabung');
        const lepas = document.getElementById('batchLepasGabung');
        const adaBaru = pratinjauMassal
            ? (pratinjauMassal.hasil || []).filter((h) => h.status === 'baru').length
            : 0;
        gabung.title = adaBaru < 2
            ? 'Butuh minimal dua baris "bidang baru"; baris kembar atau gagal tidak bisa digabung.'
            : 'Centang minimal dua baris "bidang baru" yang bersambung, lalu klik tombol ini.';
        gabung.disabled = barisMassalTerpilih.size < 2;
        let adaGabungan = false;
        if (pratinjauMassal) {
            (pratinjauMassal.hasil || []).forEach((h) => {
                if (h.gabung && barisMassalTerpilih.has(h.i)) {
                    adaGabungan = true;
                }
            });
        }
        lepas.disabled = !adaGabungan;
    }

    function kosongkanPratinjauMassal(keServer) {
        grupBatchPratinjau.clearLayers();
        sorotMassal = {};
        pratinjauMassal = null;
        barisMassalTerpilih.clear();
        document.getElementById('batchSimpan').style.display = 'none';
        document.getElementById('batchDaftar').textContent = '';
        ['batchBaru', 'batchKembar', 'batchGagal'].forEach((id) => {
            document.getElementById(id).textContent = '–';
        });
        aturTombolGabung();
        if (keServer) {
            Sip.api('api/penanda-bidang.php', {
                method: 'POST',
                json: { aksi: 'batch-bersihkan' }
            }).catch(() => {});
        }
    }

    function tambahPenandaMassal(latlng) {
        const batas = Number(document.getElementById('modeBatch').dataset.batas) || 150;
        if (titikMassal.length >= batas) {
            Sip.pesan('Maksimal ' + batas + ' penanda untuk satu kali pratinjau.', 'gagal');
            return;
        }
        titikMassal.push([latlng.lng, latlng.lat]);
        const pin = L.marker(latlng, {
            icon: ikonPenandaMassal,
            title: 'Klik untuk menghapus penanda ini'
        }).addTo(grupBatch);
        pin.on('click', () => hapusPenandaMassal(pin));
        pinMassal.push(pin);
        kosongkanPratinjauMassal(true);
        aturJumlahMassal();
        info.textContent = 'Penanda massal ' + Sip.angka(titikMassal.length)
            + ': X ' + koordDxf(latlng.lng) + ' · Y ' + koordDxf(latlng.lat);
    }

    function hapusPenandaMassal(pin) {
        const posisi = pinMassal.indexOf(pin);
        if (posisi < 0) return;
        grupBatch.removeLayer(pin);
        pinMassal.splice(posisi, 1);
        titikMassal.splice(posisi, 1);
        kosongkanPratinjauMassal(true);
        aturJumlahMassal();
        document.getElementById('infoBatch').textContent = 'Satu penanda dihapus; pratinjau perlu diulang.';
    }

    function gambarPenandaMassal(h) {
        if (h.status === 'baru' && h.geometri) {
            const lapisan = L.geoJSON(
                { type: 'Feature', geometry: h.geometri, properties: {} },
                {
                    style: {
                        color: '#166534', weight: 1.6, opacity: .95,
                        fillColor: '#4ade80', fillOpacity: .18,
                        dashArray: h.jenis === 'garis' ? '6 4' : null
                    }
                }
            ).addTo(grupBatchPratinjau);
            sorotMassal[h.i] = lapisan;
            return;
        }
        if (h.batas) {
            sorotMassal[h.i] = L.rectangle(
                [[h.batas[1], h.batas[0]], [h.batas[3], h.batas[2]]],
                { color: '#d97706', weight: 1.2, opacity: .85, dashArray: '4 3', fillOpacity: 0 }
            ).addTo(grupBatchPratinjau);
        }
    }

    function barisMassal(h) {
        const li = document.createElement('li');
        if (h.status === 'baru') {
            const centang = document.createElement('input');
            centang.type = 'checkbox';
            centang.className = 'cek-baris';
            centang.checked = barisMassalTerpilih.has(h.i);
            centang.title = 'Centang untuk menggabungkan baris ini dengan baris lain';
            centang.addEventListener('click', (e) => e.stopPropagation());
            centang.addEventListener('change', () => {
                if (centang.checked) {
                    barisMassalTerpilih.add(h.i);
                } else {
                    barisMassalTerpilih.delete(h.i);
                }
                aturTombolGabung();
            });
            li.appendChild(centang);
        }
        const kiri = document.createElement('span');
        kiri.className = 'redup';
        kiri.textContent = (h.i + 1) + '. ' + (h.kode
            || 'X ' + Sip.angka(h.x, 1) + ' · Y ' + Sip.angka(h.y, 1))
            + (h.gabung ? ' · ' + Sip.angka(h.gabung.length) + ' muka' : '');
        const kanan = document.createElement('span');
        kanan.className = 'rapek-kanan';
        kanan.textContent = h.status === 'baru'
            ? Sip.angka(h.luas_m2, 1) + ' m² · '
                + (h.gabung ? 'digabung' : (LABEL_JENIS_PENANDA[h.jenis] || h.jenis))
            : h.status === 'kembar'
                ? 'dilewati · ' + ((h.duplikat && h.duplikat.kode) || 'titik lain di muka yang sama')
                : 'gagal';
        li.appendChild(kiri);
        li.appendChild(kanan);
        li.addEventListener('click', () => {
            const sorot = sorotMassal[h.i];
            if (sorot && sorot.getBounds && sorot.getBounds().isValid()) {
                peta.fitBounds(sorot.getBounds().pad(.5), { maxZoom: 2 });
            } else {
                peta.setView([h.y, h.x], 1);
            }
        });
        return li;
    }

    function tampilkanPratinjauMassal(isi) {
        kosongkanPratinjauMassal(false);
        pratinjauMassal = isi;
        document.getElementById('batchBaru').textContent = Sip.angka(isi.baru);
        document.getElementById('batchKembar').textContent = Sip.angka(isi.kembar);
        document.getElementById('batchGagal').textContent = Sip.angka(isi.gagal);

        const daftar = document.getElementById('batchDaftar');
        (isi.hasil || []).slice(0, 40).forEach((h) => {
            gambarPenandaMassal(h);
            daftar.appendChild(barisMassal(h));
        });
        const sisa = (isi.hasil || []).length - 40;
        if (sisa > 0) {
            const li = document.createElement('li');
            const redup = document.createElement('span');
            redup.className = 'redup';
            redup.textContent = Sip.angka(sisa) + ' baris lainnya tidak ditampilkan.';
            li.appendChild(redup);
            daftar.appendChild(li);
        }
        (isi.hasil || []).slice(40).forEach(gambarPenandaMassal);

        let pesan = Sip.angka(isi.baru) + ' bidang baru, ' + Sip.angka(isi.kembar)
            + ' kembar, ' + Sip.angka(isi.gagal) + ' gagal';
        if (isi.terpangkas > 0) {
            pesan += '; ' + Sip.angka(isi.terpangkas) + ' penanda ganda diabaikan';
        }
        pesan += '.';
        if (isi.baru < 2) {
            pesan += ' Gabung terpilih butuh minimal dua baris bidang baru; baris kembar tidak bisa digabung.';
        }
        if (isi.boleh_simpan) {
            pesan += ' Periksa gambar hijau, lalu klik Simpan semua.';
            document.getElementById('batchSimpan').style.display = '';
        } else if (isi.baru > 0) {
            pesan += ' Akun ini tidak berwenang menyimpan bidang.';
        } else {
            pesan += ' Tidak ada yang perlu disimpan.';
        }
        document.getElementById('infoBatch').textContent = pesan;
        info.textContent = 'Pratinjau massal: ' + pesan;
        aturTombolGabung();
    }

    async function pratinjauSemuaMassal() {
        if (titikMassal.length === 0) return;
        const tombol = document.getElementById('batchPratinjau');
        tombol.disabled = true;
        document.getElementById('infoBatch').textContent
            = 'Menghitung ' + Sip.angka(titikMassal.length) + ' penanda…';
        try {
            const isi = await Sip.api('api/penanda-bidang.php', {
                method: 'POST',
                json: { aksi: 'batch-pratinjau', titik: titikMassal }
            });
            tampilkanPratinjauMassal(isi);
        } catch (err) {
            kosongkanPratinjauMassal(false);
            document.getElementById('infoBatch').textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            tombol.disabled = false;
        }
    }

    async function gabungMassal() {
        if (!pratinjauMassal || barisMassalTerpilih.size < 2) return;
        const daftar = Array.from(barisMassalTerpilih);
        const tombol = document.getElementById('batchGabung');
        tombol.disabled = true;
        document.getElementById('infoBatch').textContent
            = 'Menggabung ' + Sip.angka(daftar.length) + ' baris…';
        try {
            const isi = await Sip.api('api/penanda-bidang.php', {
                method: 'POST',
                json: { aksi: 'batch-gabung', token: pratinjauMassal.token, pilih: daftar }
            });
            tampilkanPratinjauMassal(isi);
            document.getElementById('infoBatch').textContent = isi.pesan;
            info.textContent = 'Penggabungan: ' + isi.pesan;
        } catch (err) {
            document.getElementById('infoBatch').textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            aturTombolGabung();
            tombol.disabled = barisMassalTerpilih.size < 2;
        }
    }

    async function lepasGabungMassal() {
        if (!pratinjauMassal || barisMassalTerpilih.size === 0) return;
        const daftar = Array.from(barisMassalTerpilih);
        const tombol = document.getElementById('batchLepasGabung');
        tombol.disabled = true;
        try {
            const isi = await Sip.api('api/penanda-bidang.php', {
                method: 'POST',
                json: { aksi: 'batch-lepas-gabung', token: pratinjauMassal.token, pilih: daftar }
            });
            tampilkanPratinjauMassal(isi);
            document.getElementById('infoBatch').textContent = isi.pesan;
            info.textContent = 'Gabungan dilepas: ' + isi.pesan;
        } catch (err) {
            document.getElementById('infoBatch').textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            aturTombolGabung();
        }
    }

    async function simpanSemuaMassal() {
        if (!pratinjauMassal) return;
        const digabung = (pratinjauMassal.hasil || []).filter((h) => h.gabung).length;
        const yakin = window.confirm('Simpan ' + pratinjauMassal.baru
            + ' bidang baru sebagai perlu verifikasi, tanpa blok?'
            + (digabung > 0 ? ' ' + digabung + ' di antaranya bidang gabungan.' : '')
            + ' Yang kembar dilewati dan seluruh batch ditulis dalam satu transaksi.');
        if (!yakin) return;
        const tombol = document.getElementById('batchSimpan');
        tombol.disabled = true;
        try {
            const hasil = await Sip.api('api/penanda-bidang.php', {
                method: 'POST',
                json: { aksi: 'batch-simpan', token: pratinjauMassal.token }
            });
            const dibuat = (hasil.dibuat || []).length;
            const dilewati = (hasil.dilewati || []).length;
            kosongkanPratinjauMassal(false);
            grupBatch.clearLayers();
            pinMassal = [];
            titikMassal = [];
            aturJumlahMassal();
            const pesan = Sip.angka(dibuat) + ' bidang tersimpan sebagai perlu verifikasi'
                + (dilewati ? ', ' + Sip.angka(dilewati) + ' dilewati kembar' : '')
                + (hasil.gagal ? ', ' + Sip.angka(hasil.gagal) + ' gagal terbentuk' : '') + '.';
            document.getElementById('infoBatch').textContent = pesan;
            info.textContent = pesan;
            Sip.pesan(hasil.pesan, 'berhasil');
        } catch (err) {
            document.getElementById('infoBatch').textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            tombol.disabled = false;
        }
    }

    // ------------------------------------------------------------------
    // Sumbu jalan manual
    // ------------------------------------------------------------------
    const ikonTitikSumbu = L.divIcon({
        className: 'sumbu-titik',
        html: '<span></span>',
        iconSize: [10, 10],
        iconAnchor: [5, 5]
    });

    function panjangSumbu() {
        let total = 0;
        for (let i = 1; i < titikSumbu.length; i++) {
            total += Math.hypot(
                titikSumbu[i][0] - titikSumbu[i - 1][0],
                titikSumbu[i][1] - titikSumbu[i - 1][1]
            );
        }
        if (modeBidang() && titikSumbu.length >= 3) {
            total += Math.hypot(
                titikSumbu[0][0] - titikSumbu[titikSumbu.length - 1][0],
                titikSumbu[0][1] - titikSumbu[titikSumbu.length - 1][1]
            );
        }
        return total;
    }

    // Luas bidang lukisan (meter DXF) untuk ditampilkan sebelum disimpan;
    // angka resmi tetap dihitung server lewat app/geometri.php.
    function luasSumbu() {
        let duaKali = 0;
        for (let i = 0, n = titikSumbu.length; i < n; i++) {
            const j = (i + 1) % n;
            duaKali += titikSumbu[i][0] * titikSumbu[j][1] - titikSumbu[j][0] * titikSumbu[i][1];
        }
        return Math.abs(duaKali) / 2;
    }

    function gambarSumbu() {
        grupSumbu.clearLayers();
        titikSumbu.forEach((p) => {
            L.marker([p[1], p[0]], { icon: ikonTitikSumbu, interactive: false, keyboard: false })
                .addTo(grupSumbu);
        });
        if (modeBidang() && titikSumbu.length >= 3) {
            L.polygon(
                titikSumbu.map((p) => [p[1], p[0]]),
                { color: '#db2777', weight: 2.6, dashArray: '7 4', opacity: .95,
                    fillColor: '#f59e0b', fillOpacity: .18 }
            ).addTo(grupSumbu);
        } else if (titikSumbu.length >= 2) {
            L.polyline(
                titikSumbu.map((p) => [p[1], p[0]]),
                { color: '#db2777', weight: 2.6, dashArray: '7 4', opacity: .95 }
            ).addTo(grupSumbu);
        }
    }

    function perbaruiSumbu() {
        if (!elemenInfoSumbu) return;
        const minimal = modeBidang() ? 3 : 2;
        document.getElementById('sumbuJumlah').textContent = Sip.angka(titikSumbu.length);
        document.getElementById('sumbuPanjang').textContent = titikSumbu.length >= 2
            ? Sip.angka(panjangSumbu(), 2) + ' m'
            : '–';
        const barisLuas = document.getElementById('barisSumbuLuas');
        if (barisLuas) barisLuas.style.display = modeBidang() ? '' : 'none';
        const isiLuas = document.getElementById('sumbuLuas');
        if (isiLuas) {
            isiLuas.textContent = modeBidang() && titikSumbu.length >= 3
                ? Sip.angka(luasSumbu(), 2) + ' m²'
                : '–';
        }
        const labelPanjang = document.getElementById('sumbuLabelPanjang');
        if (labelPanjang) labelPanjang.textContent = modeBidang() ? 'Keliling' : 'Panjang';
        document.getElementById('sumbuUndo').disabled = titikSumbu.length === 0;
        document.getElementById('sumbuBatal').disabled = titikSumbu.length === 0;
        document.getElementById('sumbuSimpan').disabled = titikSumbu.length < minimal;
        document.getElementById('sumbuHapusTersimpan').disabled = jumlahManual[lapisManual] === 0;
    }

    function tambahTitikSumbu(latlng) {
        titikSumbu.push([latlng.lng, latlng.lat]);
        gambarSumbu();
        perbaruiSumbu();
        const ukuran = titikSumbu.length >= (modeBidang() ? 3 : 2)
            ? (modeBidang() ? 'luas ' + Sip.angka(luasSumbu(), 2) + ' m², keliling ' : '')
                + Sip.angka(panjangSumbu(), 2) + ' m'
            : (modeBidang() ? 'bidang belum terbentuk' : 'garis belum terbentuk');
        info.textContent = namaLapisManual() + ': ' + Sip.angka(titikSumbu.length) + ' titik, '
            + ukuran + ' — X ' + koordDxf(latlng.lng) + ' · Y ' + koordDxf(latlng.lat);
    }

    function batalSumbu(pesan) {
        titikSumbu = [];
        gambarSumbu();
        perbaruiSumbu();
        if (elemenInfoSumbu) {
            elemenInfoSumbu.textContent = pesan || ('Gambar dikosongkan. ' + namaLapisManual()
                + ' tersimpan: ' + Sip.angka(jumlahManual[lapisManual]) + '.');
        }
    }

    function aturSumbu(hidup) {
        if (!elemenInfoSumbu) return;
        modeSumbu = hidup;
        if (hidup) {
            aturPotongSumbu(false);
            if (modePenunjuk) aturPenunjuk(false);
            if (modeMassal) aturMassal(false);
            if (modeObyek) aturObyek(false);
        }
        const tombol = document.getElementById('alatSumbu');
        tombol.setAttribute('aria-pressed', hidup ? 'true' : 'false');
        tombol.textContent = hidup ? 'Matikan garis' : 'Garis manual';
        tombol.classList.toggle('tombol-utama', hidup);
        document.getElementById('petaLama').classList.toggle('mode-sumbu', hidup);
        elemenInfoSumbu.textContent = hidup
            ? (modeBidang()
                ? 'Klik peta untuk menambah sudut bidang. Minimal tiga titik; keliling ditutup otomatis, lalu Simpan bidang.'
                : 'Klik peta untuk menambah titik pada lapis ' + namaLapisManual()
                    + '. Minimal dua titik, lalu Simpan garis.')
            : infoDiam();
        if (hidup) info.textContent = namaLapisManual() + (modeBidang()
            ? ': klik sudut-sudut bidang pada peta.'
            : ': klik titik-titik garis pada peta.');
    }

    function perbaruiJumlahGarisManual(jumlah) {
        jumlahManual[lapisManual] = jumlah;
        const label = document.getElementById(LAPIS_MANUAL[lapisManual].labelJumlah);
        if (label) {
            const turunan = Number(label.dataset.turunan) || 0;
            label.textContent = Sip.angka(turunan + jumlah);
        }
        if (elemenInfoSumbu) {
            elemenInfoSumbu.dataset.tersimpan = String(jumlahManual['garis-jalan-sumbu']);
            elemenInfoSumbu.dataset.tersimpanBatas = String(jumlahManual['010500']);
            elemenInfoSumbu.dataset.tersimpanBidang = String(jumlahManual['bidang']);
        }
    }

    async function muatUlangGarisManual() {
        const nama = lapisTampilManual();
        const kotak = document.querySelector('[data-lapisan="' + nama + '"]');
        if (grup[nama]) {
            peta.removeLayer(grup[nama]);
            delete grup[nama];
        }
        if (kotak && !kotak.checked) kotak.checked = true;
        await pasang(nama, true);
        if (nama === 'garis-jalan-sumbu') await muatKendaliSumbu();
    }

    async function simpanSumbu() {
        const minimal = modeBidang() ? 3 : 2;
        if (titikSumbu.length < minimal) return;
        const tombol = document.getElementById('sumbuSimpan');
        const isianLabel = document.getElementById('sumbuLabel');
        const konfirmasi = modeBidang()
            ? 'Simpan bidang ' + Sip.angka(titikSumbu.length) + ' sudut (luas '
                + Sip.angka(luasSumbu(), 2) + ' m², keliling ' + Sip.angka(panjangSumbu(), 2)
                + ' m) sebagai bidang tanah baru berstatus perlu_verifikasi?'
            : 'Simpan garis ' + Sip.angka(titikSumbu.length) + ' titik ('
                + Sip.angka(panjangSumbu(), 2) + ' m) pada lapis ' + namaLapisManual() + '?';
        if (!window.confirm(konfirmasi)) {
            return;
        }
        tombol.disabled = true;
        try {
            const hasil = await Sip.api('api/garis-manual.php', {
                method: 'POST',
                json: {
                    lapis: lapisManual,
                    aksi: 'simpan',
                    titik: titikSumbu,
                    label: isianLabel ? isianLabel.value : ''
                }
            });
            perbaruiJumlahGarisManual(Number(hasil.jumlah) || 0);
            if (modeBidang()) {
                batalSumbu('Bidang ' + hasil.kode + ' tersimpan ('
                    + Sip.angka(hasil.luas_m2, 2) + ' m²). Total ' + namaLapisManual() + ': '
                    + Sip.angka(jumlahManual[lapisManual]) + '.');
                info.textContent = 'Bidang ' + hasil.kode + ' tersimpan — '
                    + Sip.angka(hasil.luas_m2, 2) + ' m², status perlu_verifikasi.';
            } else {
                batalSumbu('Garis ' + hasil.kode + ' tersimpan ('
                    + Sip.angka(hasil.panjang_m, 2) + ' m). Total ' + namaLapisManual() + ': '
                    + Sip.angka(jumlahManual[lapisManual]) + '.');
                info.textContent = 'Garis ' + hasil.kode + ' tersimpan — '
                    + Sip.angka(hasil.panjang_m, 2) + ' m.';
            }
            Sip.pesan(hasil.pesan, 'berhasil');
            await muatUlangGarisManual();
        } catch (err) {
            if (elemenInfoSumbu) elemenInfoSumbu.textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            perbaruiSumbu();
        }
    }

    async function hapusSumbuTersimpan() {
        if (jumlahManual[lapisManual] === 0) return;
        const konfirmasi = modeBidang()
            ? 'Hapus bidang terakhir yang tersimpan pada lapis Bidang (paling baru)? Statusnya menjadi dihapus, bukan dibuang permanen.'
            : 'Hapus garis manual terakhir yang tersimpan pada lapis '
                + namaLapisManual() + ' (paling baru)?';
        if (!window.confirm(konfirmasi)) {
            return;
        }
        try {
            const hasil = await Sip.api('api/garis-manual.php', {
                method: 'POST',
                json: { lapis: lapisManual, aksi: 'hapus-terakhir' }
            });
            perbaruiJumlahGarisManual(Number(hasil.jumlah) || 0);
            if (sumbuTerpilih && sumbuTerpilih.kode === hasil.kode) lepasPilihanSumbu('');
            if (elemenInfoSumbu) {
                elemenInfoSumbu.textContent = (modeBidang() ? 'Bidang ' : 'Garis ')
                    + (hasil.kode || 'terakhir') + ' dihapus. ' + namaLapisManual() + ' tersimpan: '
                    + Sip.angka(jumlahManual[lapisManual]) + '.';
            }
            info.textContent = hasil.pesan;
            Sip.pesan(hasil.pesan, 'berhasil');
            await muatUlangGarisManual();
        } catch (err) {
            if (elemenInfoSumbu) elemenInfoSumbu.textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            perbaruiSumbu();
        }
    }

    const tombolPenunjuk = document.getElementById('alatPenunjuk');

    function lepasPilihanSumbu(pesan) {
        sumbuTerpilih = null;
        sorotanSumbu.clearLayers();
        aturPotongSumbu(false);
        const label = document.getElementById('sumbuGarisTerpilih');
        if (label) label.textContent = '–';
        ['sumbuPotong', 'sumbuHapusGaris', 'sumbuLepas'].forEach((id) => {
            const tombol = document.getElementById(id);
            if (tombol) tombol.disabled = true;
        });
        if (pesan && elemenInfoSumbu) elemenInfoSumbu.textContent = pesan;
    }

    function pilihSumbuManual(fitur) {
        if (!elemenInfoSumbu || !fitur || !fitur.properties) return;
        if (modeSumbu || modePotongSumbu || modePenunjuk || modeBidang()) return;
        const kode = String(fitur.properties.kode || '');
        if (kode === '') return;
        if (fitur.properties.lapis && fitur.properties.lapis !== lapisManual) return;
        sumbuTerpilih = { kode, panjang: Number(fitur.properties.panjang_m) || 0, lapis: lapisManual };
        sorotanSumbu.clearLayers();
        L.geoJSON(fitur, {
            style: { color: '#f59e0b', weight: 5, opacity: .95 }
        }).addTo(sorotanSumbu);
        document.getElementById('sumbuGarisTerpilih').textContent = kode + ' · '
            + Sip.angka(sumbuTerpilih.panjang, 2) + ' m';
        document.getElementById('sumbuPotong').disabled = false;
        document.getElementById('sumbuHapusGaris').disabled = false;
        document.getElementById('sumbuLepas').disabled = false;
        elemenInfoSumbu.textContent = 'Garis ' + kode
            + ' terpilih. Klik “Potong di titik”, atau hapus seluruh garis.';
    }

    function jarakTitikKeRuas(p, a, b) {
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        const kuadrat = dx * dx + dy * dy;
        let t = kuadrat > 0 ? ((p.x - a.x) * dx + (p.y - a.y) * dy) / kuadrat : 0;
        t = Math.max(0, Math.min(1, t));
        return Math.hypot(p.x - (a.x + t * dx), p.y - (a.y + t * dy));
    }

    // Lapis tampil Sumbu jalan dikirim server dalam bentuk yang sudah dijahit
    // (kode JAHIT-####, sumber 'jahit'), jadi garis manual mentahnya dimuat
    // terpisah dan tidak pernah ditambahkan ke peta. Klik pemilihan, potong,
    // dan hapus memakai lapis kendali ini.
    let lapisKendaliSumbu = null;
    let janjiKendaliSumbu = null;

    function muatKendaliSumbu() {
        if (janjiKendaliSumbu) return janjiKendaliSumbu;
        janjiKendaliSumbu = (async () => {
            try {
                const data = await Sip.api('api/peta-lama.php', {
                    query: { lapisan: 'garis-sumbu-manual' }
                });
                lapisKendaliSumbu = L.geoJSON(data);
            } catch (err) {
                lapisKendaliSumbu = null;
            } finally {
                janjiKendaliSumbu = null;
            }
        })();

        return janjiKendaliSumbu;
    }

    // Cari garis manual terdekat pada lapis terpilih dalam piksel layar supaya
    // klik yang meleset beberapa piksel dari garis tipis tetap memilih garis itu,
    // bukan jatuh ke peta kosong.
    function garisManualTerdekat(latlng, toleransiPx) {
        if (modeBidang()) return null;
        const namaLapis = lapisTampilManual();
        const tampil = grup[namaLapis];
        if (!tampil || !peta.hasLayer(tampil)) return null;
        const lapisan = namaLapis === 'garis-jalan-sumbu' ? lapisKendaliSumbu : tampil;
        if (!lapisan) {
            muatKendaliSumbu();
            return null;
        }
        const klik = peta.latLngToContainerPoint(latlng);
        let terbaik = null;
        lapisan.eachLayer((l) => {
            if (!l.feature || !l.feature.properties || l.feature.properties.sumber !== 'manual') return;
            if (typeof l.getLatLngs !== 'function') return;
            const ruas = l.getLatLngs();
            const daftar = ruas.length && Array.isArray(ruas[0]) ? ruas : [ruas];
            daftar.forEach((baris) => {
                for (let i = 1; i < baris.length; i++) {
                    const a = peta.latLngToContainerPoint(baris[i - 1]);
                    const b = peta.latLngToContainerPoint(baris[i]);
                    const jarak = jarakTitikKeRuas(klik, a, b);
                    if (jarak <= toleransiPx && (!terbaik || jarak < terbaik.jarak)) {
                        terbaik = { jarak, fitur: l.feature };
                    }
                }
            });
        });
        return terbaik ? terbaik.fitur : null;
    }

    function aturPotongSumbu(hidup) {
        const tombol = document.getElementById('sumbuPotong');
        modePotongSumbu = Boolean(hidup && sumbuTerpilih);
        if (!tombol) return;
        if (modePotongSumbu) {
            if (modeSumbu) aturSumbu(false);
            if (modePenunjuk) aturPenunjuk(false);
            if (modeMassal) aturMassal(false);
            if (modeObyek) aturObyek(false);
        }
        tombol.classList.toggle('tombol-utama', modePotongSumbu);
        tombol.textContent = modePotongSumbu ? 'Batalkan potong' : 'Potong di titik';
        document.getElementById('petaLama').classList.toggle('mode-potong', modePotongSumbu);
        if (elemenInfoSumbu) {
            elemenInfoSumbu.textContent = modePotongSumbu
                ? 'Potong ' + sumbuTerpilih.kode + ': klik satu titik di garis itu.'
                : (sumbuTerpilih
                    ? 'Garis ' + sumbuTerpilih.kode + ' terpilih.'
                    : infoDiam());
        }
    }

    async function potongSumbu(latlng) {
        if (!sumbuTerpilih) return;
        const kode = sumbuTerpilih.kode;
        if (!window.confirm('Potong ' + kode + ' (' + Sip.angka(sumbuTerpilih.panjang, 2)
            + ' m) di titik X ' + koordDxf(latlng.lng) + ' · Y ' + koordDxf(latlng.lat) + '?')) {
            return;
        }
        try {
            const hasil = await Sip.api('api/garis-manual.php', {
                method: 'POST',
                json: { lapis: sumbuTerpilih.lapis, aksi: 'potong', kode, titik: [latlng.lng, latlng.lat] }
            });
            perbaruiJumlahGarisManual(Number(hasil.jumlah) || 0);
            lepasPilihanSumbu('');
            await muatUlangGarisManual();
            const kodeBaru = Array.isArray(hasil.kode) ? hasil.kode : [];
            const panjangBaru = Array.isArray(hasil.panjang_m) ? hasil.panjang_m : [];
            const rincian = kodeBaru.map((k, i) => k + ' ' + Sip.angka(panjangBaru[i], 2) + ' m').join(', ');
            if (elemenInfoSumbu) elemenInfoSumbu.textContent = hasil.pesan + ' Bagian: ' + rincian + '.';
            info.textContent = hasil.pesan;
            Sip.pesan(hasil.pesan, 'berhasil');
        } catch (err) {
            if (elemenInfoSumbu) elemenInfoSumbu.textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        }
    }

    async function hapusGarisSumbu() {
        if (!sumbuTerpilih) return;
        const kode = sumbuTerpilih.kode;
        if (!window.confirm('Hapus garis ' + kode + ' (' + Sip.angka(sumbuTerpilih.panjang, 2)
            + ' m) dari berkas ' + namaLapisManual() + ' manual?')) {
            return;
        }
        try {
            const hasil = await Sip.api('api/garis-manual.php', {
                method: 'POST',
                json: { lapis: sumbuTerpilih.lapis, aksi: 'hapus', kode }
            });
            perbaruiJumlahGarisManual(Number(hasil.jumlah) || 0);
            lepasPilihanSumbu('');
            await muatUlangGarisManual();
            if (elemenInfoSumbu) elemenInfoSumbu.textContent = hasil.pesan;
            info.textContent = hasil.pesan;
            Sip.pesan(hasil.pesan, 'berhasil');
        } catch (err) {
            if (elemenInfoSumbu) elemenInfoSumbu.textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        }
    }

    const ikonObyekBaru = L.divIcon({
        className: 'obyek-pin',
        html: '<span></span>',
        iconSize: [14, 14],
        iconAnchor: [7, 7]
    });

    function infoObyekDiam() {
        return 'Alat mati. Titik obyek tersimpan: ' + Sip.angka(jumlahTitikObyek) + '.';
    }

    function pesanObyek() {
        return modeObyek
            ? 'Klik peta untuk menaruh titik obyek. Isi nama dan jenis, lalu Simpan titik.'
            : infoObyekDiam();
    }

    function perbaruiObyek() {
        if (!elemenInfoObyek) return;
        const label = document.getElementById('obyekJumlah');
        if (label) label.textContent = Sip.angka(jumlahTitikObyek);
        const labelLapis = document.getElementById('jumlahTitikObyek');
        if (labelLapis) labelLapis.textContent = Sip.angka(jumlahTitikObyek);
        const posisi = document.getElementById('obyekPosisi');
        if (posisi) {
            posisi.textContent = titikObyekBaru
                ? 'X ' + koordDxf(titikObyekBaru.lng) + ' · Y ' + koordDxf(titikObyekBaru.lat)
                : '–';
        }
        const batal = document.getElementById('obyekBatal');
        if (batal) batal.disabled = !titikObyekBaru;
        const simpan = document.getElementById('obyekSimpan');
        if (simpan) simpan.disabled = !titikObyekBaru;
    }

    function gambarTitikObyekBaru() {
        grupObyekBaru.clearLayers();
        if (!titikObyekBaru) return;
        L.marker(titikObyekBaru, { icon: ikonObyekBaru, keyboard: false }).addTo(grupObyekBaru);
    }

    function aturObyek(hidup) {
        if (!elemenInfoObyek) return;
        modeObyek = hidup;
        if (hidup) {
            if (modeSumbu) aturSumbu(false);
            if (modePotongSumbu) aturPotongSumbu(false);
            if (modePenunjuk) aturPenunjuk(false);
            if (modeMassal) aturMassal(false);
        }
        const tombol = document.getElementById('alatObyek');
        tombol.setAttribute('aria-pressed', hidup ? 'true' : 'false');
        tombol.textContent = hidup ? 'Matikan titik' : 'Pasang titik';
        tombol.classList.toggle('tombol-utama', hidup);
        document.getElementById('petaLama').classList.toggle('mode-obyek', hidup);
        elemenInfoObyek.textContent = pesanObyek();
        if (hidup) info.textContent = 'Titik obyek: klik satu titik di peta.';
    }

    function letakkanTitikObyek(latlng) {
        titikObyekBaru = latlng;
        gambarTitikObyekBaru();
        perbaruiObyek();
        if (elemenInfoObyek) {
            elemenInfoObyek.textContent = 'Titik baru di X ' + koordDxf(latlng.lng) + ' · Y '
                + koordDxf(latlng.lat) + '. Isi nama, lalu Simpan titik.';
        }
        info.textContent = 'Titik obyek: X ' + koordDxf(latlng.lng) + ' · Y ' + koordDxf(latlng.lat) + '.';
        const isian = document.getElementById('obyekNama');
        if (isian) isian.focus();
    }

    function batalTitikObyek(pesan) {
        titikObyekBaru = null;
        gambarTitikObyekBaru();
        perbaruiObyek();
        if (elemenInfoObyek) {
            elemenInfoObyek.textContent = pesan || ('Titik baru dibatalkan. ' + pesanObyek());
        }
    }

    function renderDaftarTitikObyek() {
        const daftar = document.getElementById('obyekDaftar');
        if (!daftar) return;
        daftar.textContent = '';
        const lapisan = grup['titik-obyek'];
        const baris = [];
        if (lapisan) {
            lapisan.eachLayer((l) => {
                if (l.feature) baris.push(l);
            });
        }
        if (baris.length === 0) {
            const li = document.createElement('li');
            const redup = document.createElement('span');
            redup.className = 'redup';
            redup.textContent = 'Belum ada titik obyek tersimpan.';
            li.appendChild(redup);
            daftar.appendChild(li);
            return;
        }
        baris.forEach((lapis) => {
            const p = lapis.feature.properties || {};
            const li = document.createElement('li');
            const kiri = document.createElement('span');
            kiri.textContent = (p.nama || '(tanpa nama)') + ' · ' + (p.jenis || 'Lainnya');
            const kanan = document.createElement('span');
            kanan.className = 'redup';
            kanan.textContent = p.kode || '';
            const hapus = document.createElement('button');
            hapus.type = 'button';
            hapus.className = 'tombol tombol-kecil hapus-obyek';
            hapus.textContent = 'Hapus';
            hapus.addEventListener('click', (ev) => {
                ev.stopPropagation();
                hapusTitikObyek(String(p.kode || '')).catch((err) => Sip.pesan(err.message, 'gagal'));
            });
            li.appendChild(kiri);
            li.appendChild(kanan);
            li.appendChild(hapus);
            li.addEventListener('click', () => sorotTitikObyek(lapis));
            daftar.appendChild(li);
        });
    }

    function sorotTitikObyek(lapis) {
        if (!lapis || typeof lapis.getLatLng !== 'function') return;
        const posisi = lapis.getLatLng();
        sorotanObyek.clearLayers();
        L.circleMarker(posisi, {
            radius: 13, color: '#f59e0b', weight: 3, opacity: .95, fillOpacity: 0, interactive: false
        }).addTo(sorotanObyek);
        peta.setView(posisi, Math.max(peta.getZoom(), 0));
        if (typeof lapis.openPopup === 'function') lapis.openPopup();
    }

    async function muatUlangTitikObyek() {
        if (grup['titik-obyek']) {
            peta.removeLayer(grup['titik-obyek']);
            delete grup['titik-obyek'];
        }
        const kotakLapis = document.querySelector('[data-lapisan="titik-obyek"]');
        if (kotakLapis && !kotakLapis.checked) kotakLapis.checked = true;
        await pasang('titik-obyek', true);
        renderDaftarTitikObyek();
    }

    async function simpanTitikObyek() {
        if (!titikObyekBaru) return;
        const isianNama = document.getElementById('obyekNama');
        const isianJenis = document.getElementById('obyekJenis');
        const nama = isianNama ? isianNama.value.trim() : '';
        if (nama === '') {
            if (isianNama) isianNama.focus();
            Sip.pesan('Isi nama obyek dulu.', 'gagal');
            return;
        }
        const tombol = document.getElementById('obyekSimpan');
        tombol.disabled = true;
        try {
            const hasil = await Sip.api('api/titik-obyek.php', {
                method: 'POST',
                json: {
                    aksi: 'simpan',
                    nama: nama,
                    jenis: isianJenis ? isianJenis.value : 'Lainnya',
                    titik: [titikObyekBaru.lng, titikObyekBaru.lat]
                }
            });
            jumlahTitikObyek = Number(hasil.jumlah) || 0;
            titikObyekBaru = null;
            gambarTitikObyekBaru();
            if (isianNama) isianNama.value = '';
            perbaruiObyek();
            await muatUlangTitikObyek();
            if (elemenInfoObyek) elemenInfoObyek.textContent = hasil.pesan + ' ' + pesanObyek();
            info.textContent = hasil.pesan;
            Sip.pesan(hasil.pesan, 'berhasil');
        } catch (err) {
            if (elemenInfoObyek) elemenInfoObyek.textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            perbaruiObyek();
        }
    }

    async function hapusTitikObyek(kode) {
        if (kode === '') return;
        if (!window.confirm('Hapus titik obyek ' + kode
            + ' dari berkas titik-obyek-manual.geojson?')) {
            return;
        }
        try {
            const hasil = await Sip.api('api/titik-obyek.php', {
                method: 'POST',
                json: { aksi: 'hapus', kode: kode }
            });
            jumlahTitikObyek = Number(hasil.jumlah) || 0;
            perbaruiObyek();
            await muatUlangTitikObyek();
            if (elemenInfoObyek) elemenInfoObyek.textContent = hasil.pesan + ' ' + pesanObyek();
            info.textContent = hasil.pesan;
            Sip.pesan(hasil.pesan, 'berhasil');
        } catch (err) {
            if (elemenInfoObyek) elemenInfoObyek.textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        }
    }

    function aturPenunjuk(hidup) {
        if (hidup && modeSumbu) aturSumbu(false);
        if (hidup && modeObyek) aturObyek(false);
        modePenunjuk = hidup;
        if (!hidup && modeMassal) aturMassal(false);
        tombolPenunjuk.setAttribute('aria-pressed', hidup ? 'true' : 'false');
        tombolPenunjuk.textContent = hidup ? 'Matikan penunjuk' : 'Penunjuk titik';
        tombolPenunjuk.classList.toggle('tombol-utama', hidup);
        document.getElementById('petaLama').classList.toggle('mode-penunjuk', hidup);
        document.getElementById('infoPenanda').textContent = hidup
            ? 'Klik peta untuk menaruh penanda titik.'
            : (penanda ? 'Penunjuk nonaktif. Penanda terakhir tetap ditampilkan.' : 'Penunjuk nonaktif.');
        if (!hidup) info.textContent = 'Layer DXF siap. Koordinat lokal — bukan titik GPS.';
    }

    function titikDiinginkan() {
        const kotakTitik = document.getElementById('tampilTitikLama');
        return !kotakTitik || kotakTitik.checked;
    }

    async function pasang(nama, hidup) {
        if (!hidup) {
            if (grup[nama]) peta.removeLayer(grup[nama]);
            if (nama === '020100') peta.removeLayer(grupTitik);
            if (nama === lapisManual && sumbuTerpilih) lepasPilihanSumbu('');
            return;
        }
        if (grup[nama]) {
            grup[nama].addTo(peta);
            if (nama === '020100' && titikDiinginkan()) grupTitik.addTo(peta);
            return;
        }
        if (memuat.has(nama)) return;
        memuat.add(nama);
        info.textContent = 'Memuat layer ' + nama + '…';
        try {
            const data = await Sip.api('api/peta-lama.php', { query: { lapisan: nama } });
            grup[nama] = bangun(nama, data);
            if (nama === 'garis-jalan-sumbu') muatKendaliSumbu();
            const kotak = document.querySelector('[data-lapisan="' + nama + '"]');
            if (!kotak || kotak.checked) grup[nama].addTo(peta);
            if (nama === '020100') {
                bangunTitikBidang(grup[nama]);
                if (titikDiinginkan()) grupTitik.addTo(peta);
            }
            info.textContent = 'Layer ' + nama + ' dimuat.';
        } finally {
            memuat.delete(nama);
        }
    }

    function pusatkan() {
        const b = grup['020100'] ? grup['020100'].getBounds() : L.latLngBounds([]);
        if (b.isValid()) peta.fitBounds(b.pad(.025));
    }

    async function fokusBlokPerkiraan() {
        const pemilih = document.getElementById('fokusBlokPerkiraan');
        const nomor = Number(pemilih.value);
        if (!Number.isInteger(nomor) || nomor <= 0) {
            Sip.pesan('Pilih blok perkiraan terlebih dahulu.', 'gagal');
            return;
        }

        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'blok' } });
        const fitur = (data.features || []).find((f) => Number(f.properties.nomor) === nomor);
        if (!fitur || !fitur.geometry) {
            throw new Error('Geometri BLOK ' + nomor + ' tidak ditemukan.');
        }

        const transformasi = JSON.parse(pemilih.dataset.transformasi);
        const lon = transformasi.lon;
        const lat = transformasi.lat;
        const determinan = lon[1] * lat[2] - lon[2] * lat[1];
        if (!Number.isFinite(determinan) || determinan === 0) {
            throw new Error('Transformasi koordinat DXF tidak valid.');
        }

        const bounds = L.latLngBounds([]);
        function tambahKoordinat(koordinat) {
            if (typeof koordinat[0] === 'number') {
                const dLon = koordinat[0] - lon[0];
                const dLat = koordinat[1] - lat[0];
                const x = (dLon * lat[2] - lon[2] * dLat) / determinan;
                const y = (lon[1] * dLat - dLon * lat[1]) / determinan;
                bounds.extend([y, x]);
                return [x, y];
            }
            return koordinat.map(tambahKoordinat);
        }
        const geometriLokal = {
            type: fitur.geometry.type,
            coordinates: tambahKoordinat(fitur.geometry.coordinates)
        };
        if (!bounds.isValid()) {
            throw new Error('Batas koordinat BLOK ' + nomor + ' tidak dapat dihitung.');
        }

        sorotanBlok.clearLayers();
        L.geoJSON(Object.assign({}, fitur, { geometry: geometriLokal }), {
            style: { color: '#dc2626', weight: 4, opacity: 1, fillColor: '#ef4444', fillOpacity: .08 }
        }).addTo(sorotanBlok);
        peta.fitBounds(bounds.pad(.12), { maxZoom: 1 });
        document.getElementById('infoLama').textContent =
            'Peta difokuskan ke BLOK ' + nomor + '. Garis acuan tetap perlu dicocokkan secara visual.';
    }

    document.getElementById('pusatkanLama').addEventListener('click', pusatkan);
    document.getElementById('fokusBlokPerkiraanBtn').addEventListener('click', () => {
        fokusBlokPerkiraan().catch((err) => Sip.pesan(err.message, 'gagal'));
    });

    tombolPenunjuk.addEventListener('click', () => aturPenunjuk(!modePenunjuk));

    peta.on('mousemove', (e) => {
        if (modeSumbu) {
            info.textContent = namaLapisManual() + ': X ' + koordDxf(e.latlng.lng) + ' · Y ' + koordDxf(e.latlng.lat)
                + ' — klik untuk menambah titik.';
            return;
        }
        if (modePotongSumbu) {
            info.textContent = 'Potong ' + (sumbuTerpilih ? sumbuTerpilih.kode : '') + ': X '
                + koordDxf(e.latlng.lng) + ' · Y ' + koordDxf(e.latlng.lat)
                + ' — klik di garis untuk memotong.';
            return;
        }
        if (modeObyek) {
            info.textContent = 'Titik obyek: X ' + koordDxf(e.latlng.lng) + ' · Y ' + koordDxf(e.latlng.lat)
                + ' — klik untuk menaruh titik.';
            return;
        }
        if (!modePenunjuk) return;
        info.textContent = 'Penunjuk: X ' + koordDxf(e.latlng.lng) + ' · Y ' + koordDxf(e.latlng.lat)
            + ' — klik untuk menaruh penanda.';
    });

    peta.on('click', (e) => {
        if (modeObyek) {
            letakkanTitikObyek(e.latlng);
            return;
        }
        if (modeSumbu) {
            tambahTitikSumbu(e.latlng);
            return;
        }
        if (modePotongSumbu) {
            potongSumbu(e.latlng).catch((err) => Sip.pesan(err.message, 'gagal'));
            return;
        }
        if (!modePenunjuk) {
            const fitur = garisManualTerdekat(e.latlng, 12);
            if (fitur) pilihSumbuManual(fitur);
            return;
        }
        if (modeMassal) {
            tambahPenandaMassal(e.latlng);
            return;
        }
        pasangPenanda(e.latlng);
    });

    function aturMassal(hidup) {
        if (hidup && modeSumbu) aturSumbu(false);
        if (hidup && modeObyek) aturObyek(false);
        modeMassal = hidup;
        const kotak = document.getElementById('modeBatch');
        kotak.checked = hidup;
        if (hidup && !modePenunjuk) aturPenunjuk(true);
        if (!hidup) kosongkanPratinjauMassal(true);
        document.getElementById('infoBatch').textContent = hidup
            ? 'Klik peta untuk menambah penanda massal. Klik penanda merah muda untuk menghapusnya.'
            : 'Mode banyak penanda mati. ' + Sip.angka(titikMassal.length) + ' penanda masih tersimpan.';
    }

    document.getElementById('modeBatch').addEventListener('change', (ev) => {
        aturMassal(ev.target.checked);
    });
    document.getElementById('batchPratinjau').addEventListener('click', () => {
        pratinjauSemuaMassal().catch((err) => {
            document.getElementById('infoBatch').textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        });
    });
    document.getElementById('batchSimpan').addEventListener('click', () => {
        simpanSemuaMassal().catch((err) => Sip.pesan(err.message, 'gagal'));
    });
    document.getElementById('batchHapus').addEventListener('click', () => {
        grupBatch.clearLayers();
        pinMassal = [];
        titikMassal = [];
        kosongkanPratinjauMassal(true);
        aturJumlahMassal();
        document.getElementById('infoBatch').textContent = 'Penanda massal dikosongkan.';
    });
    document.getElementById('batchGabung').addEventListener('click', () => {
        gabungMassal().catch((err) => Sip.pesan(err.message, 'gagal'));
    });
    document.getElementById('batchLepasGabung').addEventListener('click', () => {
        lepasGabungMassal().catch((err) => Sip.pesan(err.message, 'gagal'));
    });

    document.getElementById('pratinjauPenanda').addEventListener('click', () => {
        mintaPratinjau();
    });
    document.getElementById('garisBaruPenanda').addEventListener('click', () => {
        mintaPratinjau('garis-baru');
    });
    document.getElementById('simpanPenanda').addEventListener('click', () => {
        simpanBidangPenanda();
    });

    document.getElementById('hapusPenanda').addEventListener('click', () => {
        grupPenanda.clearLayers();
        penanda = null;
        bersihkanPratinjau();
        hapusPratinjauServer();
        document.getElementById('penandaX').textContent = '–';
        document.getElementById('penandaY').textContent = '–';
        document.getElementById('pratinjauPenanda').disabled = true;
        document.getElementById('garisBaruPenanda').disabled = true;
        document.getElementById('hapusPenanda').disabled = true;
        document.getElementById('infoPenanda').textContent = modePenunjuk
            ? 'Klik peta untuk menaruh penanda titik.'
            : 'Penunjuk nonaktif.';
    });

    async function aturLapisManual(nama) {
        if (!LAPIS_MANUAL[nama] || nama === lapisManual) return;
        lapisManual = nama;
        const bidang = modeBidang();
        if (modeSumbu) aturSumbu(false);
        batalSumbu('');
        lepasPilihanSumbu('');
        const isian = document.getElementById('sumbuLabel');
        if (isian) isian.placeholder = LAPIS_MANUAL[nama].labelBawaan;
        const teksLabel = document.getElementById('sumbuLabelTeks');
        if (teksLabel) teksLabel.textContent = bidang ? 'Label bidang' : 'Label garis';
        const tombolSimpan = document.getElementById('sumbuSimpan');
        if (tombolSimpan) tombolSimpan.textContent = bidang ? 'Simpan bidang' : 'Simpan garis';
        const tombolHapusTersimpan = document.getElementById('sumbuHapusTersimpan');
        if (tombolHapusTersimpan) {
            tombolHapusTersimpan.textContent = bidang
                ? 'Hapus bidang tersimpan terakhir'
                : 'Hapus garis tersimpan terakhir';
        }
        const tombolPotong = document.getElementById('sumbuPotong');
        if (tombolPotong) {
            tombolPotong.title = bidang
                ? 'Bidang tidak bisa dipotong; hapus lalu gambar ulang.'
                : '';
        }
        const pemilih = document.getElementById('garisLapisan');
        if (pemilih) pemilih.value = nama;
        perbaruiSumbu();
        if (elemenInfoSumbu) elemenInfoSumbu.textContent = infoDiam();
        const namaTampil = lapisTampilManual(nama);
        const kotak = document.querySelector('[data-lapisan="' + namaTampil + '"]');
        if (kotak && !kotak.checked) {
            kotak.checked = true;
            await pasang(namaTampil, true);
        }
    }

    if (elemenInfoSumbu) {
        document.getElementById('alatSumbu').addEventListener('click', () => aturSumbu(!modeSumbu));
        document.getElementById('garisLapisan').addEventListener('change', (ev) => {
            aturLapisManual(ev.target.value).catch((err) => Sip.pesan(err.message, 'gagal'));
        });
        document.getElementById('sumbuUndo').addEventListener('click', () => {
            titikSumbu.pop();
            gambarSumbu();
            perbaruiSumbu();
            elemenInfoSumbu.textContent = 'Satu titik dihapus. Tersisa '
                + Sip.angka(titikSumbu.length) + ' titik.';
        });
        document.getElementById('sumbuBatal').addEventListener('click', () => batalSumbu());
        document.getElementById('sumbuSimpan').addEventListener('click', () => {
            simpanSumbu().catch((err) => Sip.pesan(err.message, 'gagal'));
        });
        document.getElementById('sumbuHapusTersimpan').addEventListener('click', () => {
            hapusSumbuTersimpan().catch((err) => Sip.pesan(err.message, 'gagal'));
        });
        document.getElementById('sumbuPotong').addEventListener('click', () => {
            aturPotongSumbu(!modePotongSumbu);
        });
        document.getElementById('sumbuHapusGaris').addEventListener('click', () => {
            hapusGarisSumbu().catch((err) => Sip.pesan(err.message, 'gagal'));
        });
        document.getElementById('sumbuLepas').addEventListener('click', () => {
            lepasPilihanSumbu('Pilihan dilepas.');
        });
        perbaruiSumbu();
    }

    if (elemenInfoObyek) {
        document.getElementById('alatObyek').addEventListener('click', () => aturObyek(!modeObyek));
        document.getElementById('obyekBatal').addEventListener('click', () => batalTitikObyek(''));
        document.getElementById('obyekSimpan').addEventListener('click', () => {
            simpanTitikObyek().catch((err) => Sip.pesan(err.message, 'gagal'));
        });
        document.getElementById('obyekNama').addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                simpanTitikObyek().catch((err) => Sip.pesan(err.message, 'gagal'));
            }
        });
        perbaruiObyek();
    }

    document.getElementById('keDigitasi').addEventListener('click', async (ev) => {
        ev.preventDefault();
        const tujuan = new URL(ev.currentTarget.href, location.href);
        if (!penanda) {
            hapusPratinjauServer();
            tujuan.searchParams.delete('dari');
            location.href = tujuan.toString();
            return;
        }
        if (!pratinjau) {
            await mintaPratinjau();
            if (!pratinjau) return;
        }
        const titik = penanda.getLatLng();
        tujuan.searchParams.set('dari', 'pratinjau');
        tujuan.searchParams.set('x', String(titik.lng));
        tujuan.searchParams.set('y', String(titik.lat));
        location.href = tujuan.toString();
    });

    document.getElementById('tampilTitikLama').addEventListener('change', (ev) => {
        if (ev.target.checked) {
            if (peta.hasLayer(grup['020100'])) grupTitik.addTo(peta);
            else ev.target.checked = false;
        } else {
            peta.removeLayer(grupTitik);
        }
    });

    const kotak = Array.from(document.querySelectorAll('[data-lapisan]'));
    kotak.forEach((k) => {
        k.addEventListener('change', () => {
            pasang(k.dataset.lapisan, k.checked).catch((err) => {
                k.checked = false;
                Sip.pesan(err.message, 'gagal');
                info.textContent = 'Layer gagal dimuat.';
            });
        });
    });

    (async () => {
        try {
            await pasang('020100', true);
            pusatkan();
            for (const k of kotak) {
                if (k.dataset.lapisan !== '020100' && k.checked) {
                    await pasang(k.dataset.lapisan, true);
                }
            }
            renderDaftarTitikObyek();
            info.textContent = 'Layer DXF siap. Koordinat lokal — bukan titik GPS.';
        } catch (err) {
            Sip.pesan(err.message, 'gagal');
            info.textContent = 'Layer DXF gagal dimuat.';
        }
    })();
})();
</script>
JS;

sip_footer(['leaflet' => true, 'kaki' => $naskah]);
