<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
wajib_masuk();

$katalog = [
    'digitasi-bidang' => [
        'judul' => 'Digitasi bidang',
        'ringkas' => 'Membuat, memecah, dan menggabungkan bidang tanah lewat peta.',
        'alat' => [
            [
                'jenis' => 'web', 'nama' => 'Digitasi Obyek Baru', 'tautan' => 'digitasi-baru.php',
                'ket' => 'Gambar poligon bidang baru di atas peta: pilih bidang referensi, unggah gambar/DXF, tentukan batas, lalu simpan.',
            ],
            [
                'jenis' => 'web', 'nama' => 'Pecah Bidang', 'tautan' => 'pecah-bidang.php',
                'ket' => 'Bagi satu bidang menjadi beberapa bidang baru dengan garis pemisah yang digambar di peta.',
            ],
            [
                'jenis' => 'web', 'nama' => 'Penggabungan Bidang', 'tautan' => 'penggabungan-bidang.php',
                'ket' => 'Gabungkan dua bidang atau lebih yang berdekatan menjadi satu bidang baru.',
            ],
            [
                'jenis' => 'web', 'nama' => 'Daftar Bidang', 'tautan' => 'bidang.php',
                'ket' => 'Cari dan saring bidang tanah, buka rincian, dan periksa geometrinya.',
            ],
        ],
    ],
    'peta-penanda' => [
        'judul' => 'Peta & penanda',
        'ringkas' => 'Penampil peta dan alat penandaan yang menulis langsung ke basis data.',
        'alat' => [
            [
                'jenis' => 'web', 'nama' => 'Peta Bidang', 'tautan' => 'peta.php',
                'ket' => 'Peta kadastrum: Bidang tanah, Batas blok, Jalan, Selokan, Saluran, Tepi saluran, Sungai, dan Sumbu.',
            ],
            [
                'jenis' => 'web', 'nama' => 'Peta Induk', 'tautan' => 'peta-lama.php',
                'ket' => 'Seluruh lapis turunan 020100 plus alat tulis: Penanda titik, Penanda massal, Garis manual (sumbu jalan/sungai, garis luar desa, bidang), Penanda titik obyek, dan Buat bidang dari layer.',
            ],
            [
                'jenis' => 'web', 'nama' => 'Peta Blok', 'tautan' => 'peta-blok.php',
                'ket' => 'Peta khusus batas blok: klik blok untuk melihat status batas dan perkiraan jumlah bidang.',
            ],
        ],
    ],
    'lapis-020100' => [
        'judul' => 'Lapis garis 020100',
        'ringkas' => 'Menurunkan, melengkapi, dan memindahkan lapis garis dari layer DXF 020100.',
        'alat' => [
            [
                'jenis' => 'cli', 'nama' => 'Garis batas 020100', 'perintah' => 'php -d memory_limit=1024M tools/garis-batas-020100.php',
                'ket' => 'Turunkan ulang lapis Jalan, Selokan, Perairan, dan garis lain dari 020100. Menimpa berkas lapis tanpa uji kering, jadi cadangkan storage/ dulu.',
                'tulis' => 'langsung',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Garis jalan dari sumbu', 'perintah' => 'php -d memory_limit=1024M tools/garis-jalan-dari-sumbu.php',
                'ket' => 'Garis tepi Jalan dari semua sumbu jalan yang sudah terbentuk.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Garis jalan dari label', 'perintah' => 'php -d memory_limit=1024M tools/garis-jalan-label.php',
                'ket' => 'Dua tahap: garis tepi jalan lalu sumbu, diturunkan dari label jalan pada 020100.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Garis sungai dari label', 'perintah' => 'php -d memory_limit=1024M tools/garis-sungai-label.php',
                'ket' => 'Sumbu alur sungai dari label perairan, mengikuti celah persil.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Lengkapi garis perairan', 'perintah' => 'php -d memory_limit=1024M tools/lengkapi-garis-perairan.php',
                'ket' => 'Tambahkan garis untuk label Sungai/Selokan yang belum punya garis, dari kandidat 020100 yang belum diklaim lapis lain.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Tepi saluran', 'perintah' => 'php -d memory_limit=1024M tools/tepi-saluran.php',
                'ket' => 'Garis tepi saluran dari semua garis saluran yang sudah terbentuk.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Rapikan tepi saluran', 'perintah' => 'php -d memory_limit=1024M tools/rapikan-tepi-saluran.php',
                'ket' => 'Buang tebing kembar dan jahit potongan pada lapis Tepi saluran.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Tepi sungai dari sumbu', 'perintah' => 'php -d memory_limit=1024M tools/tepi-sungai-dari-sumbu.php',
                'ket' => 'Garis tepi sungai dari semua sumbu sungai. Menggantikan alat tepi-sungai.php dan rapikan-tepi-sungai.php yang dipensiunkan.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Sumbu dari dua tepi', 'perintah' => 'php -d memory_limit=1024M tools/sumbu-dari-dua-tepi.php',
                'ket' => 'Sumbu sungai baru dari pasangan tepi perairan yang berhadapan.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Hapus cincin perairan', 'perintah' => 'php tools/hapus-cincin-perairan.php --ambang=20',
                'ket' => 'Buang cincin (garis tertutup) perairan yang membentuk bidang; sisakan garis terbuka yang menyusur alur.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Pangkas ujung menggantung', 'perintah' => 'php tools/pangkas-ujung-menggantung.php --tol=0.35',
                'ket' => 'Pangkas ujung perairan yang tidak lagi menyentuh garis lain sampai titik sentuh terakhirnya.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Pindah jalan ke saluran', 'perintah' => 'php tools/pindah-jalan-ke-saluran.php',
                'ket' => 'Pindahkan garis Jalan yang seluruh segmennya sudah menjadi pasangan tebing saluran ke lapis Saluran.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Pisah saluran dari jalan', 'perintah' => 'php tools/pisah-saluran-dari-jalan.php',
                'ket' => 'Pisahkan garis saluran dari lapis Jalan (Peta Bidang).',
            ],
        ],
    ],
    'jahit-garis' => [
        'judul' => 'Jahit & rapikan garis',
        'ringkas' => 'Menutup celah, menyambung ujung, dan merapikan potongan pada lapis garis.',
        'alat' => [
            [
                'jenis' => 'cli', 'nama' => 'Rapikan garis jalan', 'perintah' => 'php -d memory_limit=1024M tools/rapikan-garis-jalan.php --lapis=jalan',
                'ket' => 'Rapikan lapis Jalan, Selokan, atau Perairan: buang duplikat, jahit celah, dan rapatkan ujung. Opsi --celah= dan --rapat= mengatur ambang (lapis air wajib --celah).',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Sambung ujung garis', 'perintah' => 'php -d memory_limit=1024M tools/sambung-ujung-garis.php --lapis=semua',
                'ket' => 'Tempel ujung bebas ke segmen garis lain atau jembatani dua ujung yang berhadapan, tanpa memindahkan titik lama.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Jahit sumbu sungai', 'perintah' => 'php -d memory_limit=1024M tools/jahit-sumbu-sungai.php',
                'ket' => 'Jahit rantai sumbu sungai dari garis-sungai-label.geojson menjadi polilin utuh.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Jahit sumbu lewat tepi', 'perintah' => 'php -d memory_limit=1024M tools/jahit-sumbu-tepi.php',
                'ket' => 'Jahit celah sumbu sungai yang bisa disusur dari tepi perairan.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Jahit tepi bidang', 'perintah' => 'php -d memory_limit=1024M tools/jahit-tepi-bidang.php',
                'ket' => 'Jahit garis tepi jalan dengan tepi bidang, dihitung per blok.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Sambung garis lewat bidang', 'perintah' => 'php tools/sambung-garis-bidang.php',
                'ket' => 'Sambung ujung bebas perairan mengikuti graf tepi bidang kadastrum.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Susur tepi sungai', 'perintah' => 'php tools/sambung-sungai-tepi.php',
                'ket' => 'Susur tepi bidang untuk menyambung ujung bebas perairan sampai mendekati garis perairan lain.',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Tarik ke sungai terdekat', 'perintah' => 'php tools/tarik-sungai-terdekat.php',
                'ket' => 'Tarik ujung bebas perairan lurus ke titik terdekat pada garis perairan komponen lain (pertemuan T).',
            ],
        ],
    ],
    'bidang-turunan' => [
        'judul' => 'Bidang & turunannya',
        'ringkas' => 'Membentuk bidang baru dari garis, menata dusun, dan menurunkan lapis titik.',
        'alat' => [
            [
                'jenis' => 'cli', 'nama' => 'Bidang dari garis tertutup', 'perintah' => 'php tools/bidang-dari-garis-tertutup.php --lihat',
                'ket' => 'Jadikan garis tertutup dari layer 020100 sebagai bidang baru.',
                'tulis' => 'db',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Bidang dari batas desa', 'perintah' => 'php tools/bidang-dari-batas-desa.php --lihat',
                'ket' => 'Buat bidang dari garis 020100 yang beririsan dengan Batas desa (20 PTP).',
                'tulis' => 'db',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Polygonize garis terbuka', 'perintah' => 'php tools/polygonize-garis-terbuka.php --lihat',
                'ket' => 'Polygonisasi garis terbuka panjang menjadi bidang besar di atas 10.000 m².',
                'tulis' => 'db',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Assign dusun', 'perintah' => 'php tools/assign-dusun.php --lihat',
                'ket' => 'Tentukan dusun tiap blok dan bidang menurut lokasi.',
                'tulis' => 'db',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Titik tengah bidang HM', 'perintah' => 'php tools/titik-tengah-bidang-hm.php --keluar=pratinjau.geojson',
                'ket' => 'Tulis berkas titik tengah satu per bidang HM tanpa mengubah lapis asalnya. Lapis ini tidak disajikan di peta.',
            ],
        ],
    ],
    'blok-peta' => [
        'judul' => 'Blok peta',
        'ringkas' => 'Merapikan garis luar blok dan menjahit sumbu jalan per blok.',
        'alat' => [
            [
                'jenis' => 'cli', 'nama' => 'Rapikan garis blok', 'perintah' => 'php tools/rapikan-garis-blok.php 3',
                'ket' => 'Rapikan garis luar blok hasil gabungan bidang: tutup celah, buang gerigi, dan satukan bagian. Bisa beberapa nomor blok sekaligus; --terapkan membuat cadangan basis data penuh lebih dulu.',
                'tulis' => 'db',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Jahit sumbu blok', 'perintah' => 'php -d memory_limit=1024M tools/jahit-sumbu-blok.php --blok=2',
                'ket' => 'Jahit semua sumbu jalan satu blok menjadi polilin utuh.',
            ],
        ],
    ],
    'impor-ekspor' => [
        'judul' => 'Impor, konversi & ekspor',
        'ringkas' => 'Memasukkan data lapangan, mengonversi DXF, dan mengeluarkan laporan CSV.',
        'alat' => [
            [
                'jenis' => 'cli', 'nama' => 'Impor data lapangan', 'perintah' => 'php tools/impor.php semua',
                'ket' => 'Impor blok, bidang, SPPT, Buku C, dan sumber lain ke basis data. Ganti semua dengan blok, bidang, sppt, buku-c, atau gabung/pindah/ubah-blok.',
                'tulis' => 'db',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Konversi DXF per lapisan', 'perintah' => 'php -d memory_limit=1024M tools/konversi-dxf-lapisan.php',
                'ket' => 'Konversi layer DXF arsip lama menjadi GeoJSON untuk penampil Peta Induk. Menulis berkas langsung tanpa uji kering.',
                'tulis' => 'langsung',
            ],
            [
                'jenis' => 'cli', 'nama' => 'Ekspor laporan CSV', 'perintah' => 'php tools/ekspor.php --tahun=2025',
                'ket' => 'Tulis laporan lengkap basis data (rekap status, per blok, daftar bidang, bidang kembar, komposisi hak) ke storage/laporan-<waktu>/.',
            ],
        ],
    ],
    'data-administrasi' => [
        'judul' => 'Data & administrasi',
        'ringkas' => 'Pencatatan PBB, Buku C, peralihan hak, laporan, dan pengaturan aplikasi.',
        'alat' => [
            [
                'jenis' => 'web', 'nama' => 'e-SPPT', 'tautan' => 'sppt.php',
                'ket' => 'Data SPPT/PBB per tahun: cari objek, periksa status bayar, dan rincian NOP.',
            ],
            [
                'jenis' => 'web', 'nama' => 'Buku C', 'tautan' => 'buku-c.php',
                'ket' => 'Buku C Desa: daftar persil beserta pemilik dan arsip dokumennya.',
            ],
            [
                'jenis' => 'web', 'nama' => 'Peralihan Hak', 'tautan' => 'peralihan.php',
                'ket' => 'Catat peralihan hak atas bidang beserta dasar dan dokumennya.',
            ],
            [
                'jenis' => 'web', 'nama' => 'Laporan', 'tautan' => 'laporan.php',
                'ket' => 'Laporan warga beserta titik lokasi, ditampilkan juga di Peta Bidang.',
            ],
            [
                'jenis' => 'web', 'nama' => 'Pengaturan', 'tautan' => 'pengaturan.php',
                'ket' => 'Identitas desa, pusat peta, dan sakelar tampilan. Hanya untuk peran Super Admin.',
            ],
            [
                'jenis' => 'web', 'nama' => 'Akun', 'tautan' => 'pengguna.php',
                'ket' => 'Kelola akun dan peran pengguna. Hanya untuk peran Super Admin.',
            ],
        ],
    ],
];

$jumlahWeb = 0;
$jumlahCli = 0;
foreach ($katalog as $bagian) {
    foreach ($bagian['alat'] as $alat) {
        if ($alat['jenis'] === 'web') {
            $jumlahWeb++;
        } else {
            $jumlahCli++;
        }
    }
}

sip_header('ArcToolbox', 'digitasi');
?>

<?php sip_judul('Katalog alat', 'ArcToolbox', 'Seluruh alat pengelola data SIPETADES dalam satu daftar. Alat Web membuka halamannya langsung; alat CLI dijalankan dari terminal. Halaman ini hanya rujukan, tidak ada tombol yang mengubah data.'); ?>

<div class="toolbox-ringkas">
    <span><strong><?= e(angka($jumlahWeb)) ?></strong> alat web</span>
    <span><strong><?= e(angka($jumlahCli)) ?></strong> alat CLI</span>
    <span><strong><?= e(angka(count($katalog))) ?></strong> kelompok</span>
</div>

<div class="toolbox-tata-letak">
    <nav class="kartu toolbox-nav" aria-label="Kelompok alat">
        <h2>Kelompok</h2>
        <ol>
            <?php foreach ($katalog as $kunci => $bagian): ?>
                <li>
                    <a href="#alat-<?= e($kunci) ?>">
                        <span><?= e($bagian['judul']) ?></span>
                        <b><?= e(angka(count($bagian['alat']))) ?></b>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <div>
        <?php foreach ($katalog as $kunci => $bagian): ?>
            <section class="kartu toolbox-bagian" id="alat-<?= e($kunci) ?>">
                <header class="kepala-kartu">
                    <div>
                        <h2><?= e($bagian['judul']) ?></h2>
                        <p><?= e($bagian['ringkas']) ?></p>
                    </div>
                    <span class="lencana lencana-abu"><?= e(angka(count($bagian['alat']))) ?> alat</span>
                </header>
                <div class="toolbox-daftar">
                    <?php foreach ($bagian['alat'] as $alat): ?>
                        <?php if ($alat['jenis'] === 'web'): ?>
                            <a class="toolbox-alat" href="<?= e(sip_url($alat['tautan'])) ?>">
                                <span class="toolbox-baris-atas">
                                    <span class="toolbox-nama"><?= e($alat['nama']) ?></span>
                                    <span class="lencana lencana-biru">Web</span>
                                    <span class="toolbox-arah">Buka &rarr;</span>
                                </span>
                                <span class="toolbox-ket"><?= e($alat['ket']) ?></span>
                            </a>
                        <?php else: ?>
                            <div class="toolbox-alat">
                                <span class="toolbox-baris-atas">
                                    <span class="toolbox-nama"><?= e($alat['nama']) ?></span>
                                    <span class="lencana lencana-abu">CLI</span>
                                    <?php if (($alat['tulis'] ?? '') === 'db'): ?>
                                        <span class="lencana lencana-kuning">Menulis basis data</span>
                                    <?php elseif (($alat['tulis'] ?? '') === 'langsung'): ?>
                                        <span class="lencana lencana-merah">Menulis langsung</span>
                                    <?php endif; ?>
                                </span>
                                <span class="toolbox-ket"><?= e($alat['ket']) ?></span>
                                <code class="toolbox-perintah"><?= e($alat['perintah']) ?></code>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <section class="kartu">
            <h2 style="font-size:16px;">Cara memakai alat CLI</h2>
            <ol class="toolbox-catatan">
                <li>Jalankan dari folder aplikasi (<code>D:\xampp\htdocs\sigpetades</code>) dengan PHP XAMPP, mis. <code>\d\xampp\php\php.exe tools\ekspor.php</code>.</li>
                <li>Alat ber-<code>--terapkan</code> adalah uji kering bila flag itu tidak ada; tambahkan <code>--terapkan</code> untuk menulis lapis, berkas, atau tabel.</li>
                <li>Alat bertanda <strong>Menulis langsung</strong> tidak punya mode uji kering, dan <strong>Menulis basis data</strong> mengubah tabel hidup.</li>
                <li>Sebelum menulis data terbit, salin folder <code>storage/</code> dan buat dump basis data; beberapa alat membuat cadangannya sendiri, tetapi salinan tangan tetap aman.</li>
                <li>Skrip sekali pakai (mis. <code>buat-geometri-blok*.php</code>, <code>convex-hull-cluster*.php</code>, <code>fit-transform-dxf.php</code>, <code>lib-*.php</code>, <code>server.php</code>) dan alat yang sudah digantikan (<code>tepi-sungai.php</code>, <code>rapikan-tepi-sungai.php</code>) tidak didaftarkan di sini.</li>
            </ol>
        </section>
    </div>
</div>

<style>
.toolbox-ringkas { display: flex; flex-wrap: wrap; gap: 9px; margin: 0 0 18px; }
.toolbox-ringkas span {
    padding: 6px 12px; border-radius: 999px; background: var(--putih);
    border: 1px solid var(--garis); color: var(--redup); font-size: 11.5px;
}
.toolbox-ringkas strong { color: var(--tinta); font-size: 13px; }
.toolbox-tata-letak { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 18px; align-items: start; }
.toolbox-nav { position: sticky; top: 86px; padding: 14px; }
.toolbox-nav h2 {
    margin: 0 0 9px; color: var(--redup); font-size: 10.5px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .8px;
}
.toolbox-nav ol { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
.toolbox-nav a {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 7px 9px; border-radius: 8px; color: #3b4a44;
    font-size: 12px; font-weight: 600; text-decoration: none;
}
.toolbox-nav a:hover { background: var(--hijau-muda); color: var(--hijau-tua); }
.toolbox-nav b { color: var(--redup); font-size: 10.5px; }
.toolbox-bagian { scroll-margin-top: 86px; }
.toolbox-bagian > .kepala-kartu { margin-bottom: 4px; }
.toolbox-daftar { display: grid; gap: 6px; margin-top: 10px; }
.toolbox-alat {
    display: block; padding: 10px 12px; border: 1px solid var(--garis);
    border-radius: 10px; color: inherit; text-decoration: none;
}
a.toolbox-alat:hover { border-color: var(--hijau-garis); background: #fbfdfb; }
.toolbox-baris-atas { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.toolbox-nama { color: var(--tinta); font-size: 13px; font-weight: 700; }
.toolbox-arah { margin-left: auto; color: var(--hijau-tua); font-size: 11px; font-weight: 700; }
.toolbox-ket { display: block; margin-top: 3px; color: var(--redup); font-size: 11.5px; line-height: 1.55; }
.toolbox-perintah {
    display: block; margin-top: 7px; max-width: 100%; overflow-x: auto;
    padding: 4px 8px; border: 1px solid var(--garis); border-radius: 6px;
    background: #f4f7f5; color: #2b3b34;
    font: 600 11px ui-monospace, "Cascadia Mono", Consolas, monospace; white-space: nowrap;
}
.toolbox-catatan { margin: 10px 0 0; padding-left: 20px; color: var(--redup); font-size: 12.5px; line-height: 1.8; }
.toolbox-catatan code {
    padding: 1px 5px; border-radius: 4px; background: #f4f7f5;
    font: 600 11px ui-monospace, "Cascadia Mono", Consolas, monospace; color: #2b3b34;
}
@media (max-width: 980px) {
    .toolbox-tata-letak { grid-template-columns: 1fr; }
    .toolbox-nav { position: static; }
    .toolbox-nav ol { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 620px) {
    .toolbox-nav ol { grid-template-columns: 1fr; }
}
</style>

<?php sip_footer(); ?>
