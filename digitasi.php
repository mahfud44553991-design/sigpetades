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

$kelola = boleh_kelola();
$berkasLapis = __DIR__ . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/';
$meta = is_file($berkasLapis . 'metadata.json')
    ? json_decode((string) file_get_contents($berkasLapis . 'metadata.json'), true)
    : null;
$jumlahLapis = static fn (string $kunci): int => (int) ($meta['layers'][$kunci]['features'] ?? 0);
$jumlahTipe = static fn (string $kunci, string $tipe): int
    => (int) ($meta['layers'][$kunci]['types'][$tipe] ?? 0);
$hitungFitur = static function (string $berkas) use ($berkasLapis): ?int {
    if (!is_file($berkasLapis . $berkas)) {
        return null;
    }
    $isi = json_decode((string) file_get_contents($berkasLapis . $berkas), true);
    return is_array($isi['features'] ?? null) ? count($isi['features']) : null;
};

$ringkas = db_one(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(tipe = 'tanah'), 0) AS tanah
     FROM bidang WHERE status NOT IN ('dihapus', 'duplikat')"
) ?? ['total' => 0, 'tanah' => 0];
$jumlahBlok = (int) db_value('SELECT COUNT(*) FROM blok');
$jumlahBlokPerkiraan = (int) db_value('SELECT COUNT(*) FROM blok WHERE batas_perkiraan = 1');
$jumlahBidangManual = (int) db_value(
    "SELECT COUNT(*) FROM bidang WHERE sumber = 'garis-manual' AND status <> 'dihapus'"
);
$jumlahLaporan = $kelola ? (int) db_value('SELECT COUNT(*) FROM laporan') : null;

$jumlahJalan = $hitungFitur('garis-jalan-tepi.geojson');
$jumlahSelokan = $hitungFitur('garis-selokan.geojson');
$jumlahSaluranDariJalan = $hitungFitur('garis-saluran-dari-jalan.geojson');
$jumlahTepiSaluran = $hitungFitur('garis-tepi-saluran.geojson');
$jumlahSumbuSungai = $hitungFitur('garis-sungai-jahit.geojson');
$jumlahSungaiManual = $hitungFitur('garis-sungai-manual.geojson') ?? 0;
$jumlahSumbuManual = $hitungFitur('garis-sumbu-manual.geojson') ?? 0;
$jumlahBatasManual = $hitungFitur('garis-luar-desa-manual.geojson') ?? 0;
$jumlahTitikObyek = $hitungFitur('titik-obyek-manual.geojson');
$jumlahSaluran = ($jumlahSelokan === null || $jumlahSaluranDariJalan === null)
    ? null
    : $jumlahSelokan + $jumlahSaluranDariJalan;

$akarWorkspace = __DIR__;
$ukuranBerkas = static function (int $byte): string {
    if ($byte >= 1048576) {
        return number_format($byte / 1048576, 1, ',', '.') . ' MB';
    }
    if ($byte >= 1024) {
        return number_format($byte / 1024, 0, ',', '.') . ' KB';
    }
    return $byte . ' B';
};
$jenisBerkas = static function (string $ext): ?string {
    return match (strtolower($ext)) {
        'geojson' => 'GeoJSON',
        'dxf', 'dwg' => 'CAD',
        'shp', 'shx', 'dbf', 'prj' => 'Shapefile',
        'gdb', 'gpkg' => 'Geodatabase',
        'csv' => 'Tabel',
        'json' => 'JSON',
        'sql' => 'SQL',
        'kml', 'kmz' => 'KML',
        default => null,
    };
};
$kunciNormal = static fn (string $teks): string
    => preg_replace('/[^a-z0-9]+/', '', strtolower($teks)) ?? '';

$metaLapisBerkas = [];
foreach (($meta['layers'] ?? []) as $kode => $info) {
    $namaBerkas = (string) ($info['file'] ?? (strtolower(str_replace(' ', '-', (string) $kode)) . '.geojson'));
    $metaLapisBerkas[$kunciNormal(pathinfo($namaBerkas, PATHINFO_FILENAME))] = [
        'kode' => (string) $kode,
        'fitur' => (int) ($info['features'] ?? 0),
        'tipe' => (array) ($info['types'] ?? []),
    ];
}

$katalogBerkas = [
    'terbit' => [
        'judul' => 'Data terbit',
        'ringkas' => 'Berkas sumber dan turunan yang dibaca aplikasi.',
        'folder' => [
            [
                'jalur' => 'files/sig-pertanahan-gedong-lokal/data/dxf-layers',
                'judul' => 'Lapis DXF 020100',
                'ringkas' => 'Hasil konversi MASTER-dari-dwg.dxf; dibaca Peta Induk dan Peta Bidang.',
                'metadata' => true,
            ],
            [
                'jalur' => 'files/master peta',
                'judul' => 'Peta induk CAD',
                'ringkas' => 'DXF/DWG asli dan rujukan jalan OSM.',
            ],
            [
                'jalur' => 'files/peta gang',
                'judul' => 'Peta gang',
                'ringkas' => 'Rekap gang dalam CSV dan GeoJSON.',
            ],
            [
                'jalur' => '.',
                'judul' => 'Akar workspace',
                'ringkas' => 'GeoJSON impor awal bidang, blok, dan tabel rekap.',
                'hanya' => ['geojson', 'csv'],
            ],
            [
                'jalur' => 'files/sig-pertanahan-gedong-lokal/uploads',
                'judul' => 'Unggahan aplikasi',
                'ringkas' => 'Folder unggahan; saat ini belum berisi berkas.',
            ],
        ],
    ],
    'cadangan' => [
        'judul' => 'Cadangan & ekspor',
        'ringkas' => 'Salinan sebelum alat menulis, ekspor basis data, dan catatan alat.',
        'folder' => [
            [
                'jalur' => 'storage/cadangan',
                'judul' => 'Cadangan lapis',
                'ringkas' => 'Salinan GeoJSON dan dump SQL sebelum lapis atau tabel diubah.',
            ],
            [
                'jalur' => 'storage/ekspor',
                'judul' => 'Ekspor basis data',
                'ringkas' => 'Snapshot JSON tabel aplikasi.',
            ],
            [
                'jalur' => 'storage/impor',
                'judul' => 'Impor & dump SQL',
                'ringkas' => 'Berkas impor dan dump basis data sebelum pemulihan.',
            ],
            [
                'jalur' => 'storage',
                'judul' => 'Catatan alat',
                'ringkas' => 'Laporan JSON dan dump SQL yang ditulis alat CLI di akar storage.',
                'hanya' => ['json', 'sql'],
            ],
            [
                'jalur' => 'storage/cache',
                'judul' => 'Cache turunan',
                'ringkas' => 'Hasil antara yang dipakai ulang alat garis.',
            ],
        ],
    ],
    'pendukung' => [
        'judul' => 'Tabel pendukung',
        'ringkas' => 'Berkas CSV untuk impor SPPT dan rekap.',
        'folder' => [
            [
                'jalur' => 'files/data excel',
                'judul' => 'Tabel SPPT & pemetaan',
                'ringkas' => 'CSV SPPT 2026, template, dan data pemetaan bidang.',
            ],
        ],
    ],
];

$ringkasJenis = [];
$totalBerkas = 0;
$totalByte = 0;
$totalFolder = 0;
$jumlahTanpaMeta = 0;

foreach ($katalogBerkas as $grupKunci => $grup) {
    foreach ($grup['folder'] as $i => $folder) {
        $abs = $akarWorkspace . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder['jalur']);
        $daftar = [];
        if (is_dir($abs)) {
            foreach (scandir($abs) ?: [] as $nama) {
                if ($nama === '.' || $nama === '..') {
                    continue;
                }
                $path = $abs . DIRECTORY_SEPARATOR . $nama;
                if (!is_file($path)) {
                    continue;
                }
                $ext = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
                $jenis = $jenisBerkas($ext);
                if ($jenis === null || (isset($folder['hanya']) && !in_array($ext, $folder['hanya'], true))) {
                    continue;
                }
                $info = $metaLapisBerkas[$kunciNormal(pathinfo($nama, PATHINFO_FILENAME))] ?? null;
                $daftar[] = [
                    'nama' => $nama,
                    'ext' => $ext,
                    'jenis' => $jenis,
                    'byte' => (int) filesize($path),
                    'waktu' => (int) filemtime($path),
                    'kode' => $info['kode'] ?? null,
                    'fitur' => $info['fitur'] ?? null,
                    'tipe' => $info['tipe'] ?? null,
                ];
            }
        }
        usort($daftar, static fn (array $a, array $b): int => strnatcasecmp($a['nama'], $b['nama']));

        $jenisFolder = [];
        $byteFolder = 0;
        $waktuFolder = null;
        $tanpaMeta = 0;
        foreach ($daftar as $baris) {
            $jenisFolder[$baris['jenis']] = ($jenisFolder[$baris['jenis']] ?? 0) + 1;
            $ringkasJenis[$baris['jenis']] = ($ringkasJenis[$baris['jenis']] ?? 0) + 1;
            $byteFolder += $baris['byte'];
            $waktuFolder = max($waktuFolder ?? 0, $baris['waktu']);
            if ($baris['jenis'] === 'GeoJSON' && $baris['fitur'] === null) {
                $tanpaMeta++;
            }
        }

        $totalBerkas += count($daftar);
        $totalByte += $byteFolder;
        $totalFolder++;
        if (!empty($folder['metadata'])) {
            $jumlahTanpaMeta = $tanpaMeta;
        }

        $katalogBerkas[$grupKunci]['folder'][$i]['hasil'] = [
            'ada' => is_dir($abs),
            'berkas' => $daftar,
            'jenis' => $jenisFolder,
            'byte' => $byteFolder,
            'waktu' => $waktuFolder,
            'tanpa_meta' => $tanpaMeta,
        ];
    }
}

$jenisUrut = ['GeoJSON', 'CAD', 'Shapefile', 'Geodatabase', 'Tabel', 'JSON', 'SQL', 'KML'];
$jumlahSpasial = 0;
foreach (['GeoJSON', 'CAD', 'Shapefile', 'Geodatabase', 'KML'] as $jenis) {
    $jumlahSpasial += $ringkasJenis[$jenis] ?? 0;
}
$jumlahMetadata = (int) count($meta['layers'] ?? []);

$toc = [
    'bidang' => [
        'judul' => 'Peta Bidang',
        'tautan' => 'peta.php',
        'ringkas' => 'Peta kadastrum desa dengan basemap dan pengukuran.',
        'kelompok' => array_values(array_filter([
            [
                'judul' => 'Bidang kadastrum',
                'lapis' => [
                    ['nama' => 'Bidang tanah', 'warna' => '#1f9d4d',
                        'jumlah' => (int) $ringkas['tanah'], 'ket' => 'tabel bidang, status aktif'],
                    ['nama' => 'Obyek non-tanah', 'warna' => '#8a7a66',
                        'jumlah' => (int) ($ringkas['total'] - $ringkas['tanah']), 'ket' => 'tipe selain tanah'],
                    ['nama' => 'Batas blok', 'warna' => '#14603a',
                        'jumlah' => $jumlahBlok, 'ket' => 'tabel blok'],
                ],
            ],
            [
                'judul' => 'Garis turunan',
                'lapis' => [
                    ['nama' => 'Jalan', 'warna' => '#0f766e',
                        'jumlah' => $jumlahJalan, 'ket' => 'garis tepi dari semua sumbu jalan'],
                    ['nama' => 'Selokan', 'warna' => '#0ea5e9',
                        'jumlah' => $jumlahSelokan, 'ket' => 'turunan 020100'],
                    ['nama' => 'Saluran', 'warna' => '#0369a1',
                        'jumlah' => $jumlahSaluran, 'ket' => 'selokan + 33 garis Jalan yang menjadi tebing'],
                    ['nama' => 'Tepi saluran', 'warna' => '#0c4a6e',
                        'jumlah' => $jumlahTepiSaluran, 'ket' => '689 acuan + 161 tebing lawan 020100'],
                    ['nama' => 'Sungai', 'warna' => '#1e88e5',
                        'jumlah' => $jumlahSumbuSungai, 'ket' => 'sumbu sungai hasil jahit'],
                ],
            ],
            $kelola ? [
                'judul' => 'Laporan warga',
                'lapis' => [
                    ['nama' => 'Titik laporan', 'warna' => '#b42318',
                        'jumlah' => $jumlahLaporan, 'ket' => 'tabel laporan'],
                ],
            ] : null,
        ])),
    ],
    'induk' => [
        'judul' => 'Peta Induk',
        'tautan' => 'peta-lama.php',
        'ringkas' => 'Penampil layer DXF arsip lama; koordinat lokal hasil pengukuran.',
        'kelompok' => [
            [
                'judul' => 'Bidang kadastrum',
                'lapis' => [
                    ['nama' => 'Bidang', 'kode' => '020100',
                        'jumlah' => $jumlahTipe('020100', 'polygon'), 'ket' => 'poligon 020100'],
                    ['nama' => 'Garis bidang', 'kode' => '020100',
                        'jumlah' => $jumlahTipe('020100', 'line'), 'ket' => 'garis 020100, termasuk cincin'],
                    ['nama' => 'Blok ukur', 'kode' => 'BATAS BLOK',
                        'jumlah' => $jumlahBlok, 'ket' => 'batas ukur dari tabel blok'],
                    ['nama' => 'Blok mentah', 'kode' => 'BATAS BLOK',
                        'jumlah' => $jumlahLapis('BATAS BLOK'), 'ket' => 'ruas DXF mentah'],
                    ['nama' => 'Label perairan', 'kode' => 'BATAS PERAIRAN',
                        'jumlah' => $jumlahLapis('BATAS PERAIRAN'), 'ket' => 'teks label'],
                    ['nama' => 'Label nama', 'kode' => 'tn_nama',
                        'jumlah' => $jumlahLapis('tn_nama'), 'ket' => 'teks nama'],
                    ['nama' => 'Lain-lain', 'kode' => 'lb_lai',
                        'jumlah' => $jumlahLapis('lb_lai'), 'ket' => 'teks lain'],
                ],
            ],
            [
                'judul' => 'Bidang berhak',
                'lapis' => [
                    ['nama' => 'Bidang HM', 'kode' => 'BIDANG HM',
                        'jumlah' => $jumlahLapis('BIDANG HM'), 'ket' => 'poligon hak milik'],
                    ['nama' => 'Titik tengah HM', 'kode' => 'TITIK TENGAH',
                        'jumlah' => $jumlahLapis('TITIK TENGAH BIDANG HM'), 'ket' => 'satu titik per bidang'],
                    ['nama' => 'Garis HM', 'kode' => 'HM',
                        'jumlah' => $jumlahTipe('HM', 'line'), 'ket' => 'hanya garis terbuka'],
                    ['nama' => 'Optimalisasi HM', 'kode' => 'OPTIMALISASI HM',
                        'jumlah' => $jumlahLapis('OPTIMALISASI HM'), 'ket' => 'garis optimalisasi'],
                ],
            ],
            [
                'judul' => 'Garis turunan',
                'lapis' => [
                    ['nama' => 'Jalan', 'kode' => 'GARIS JALAN TEPI',
                        'jumlah' => $jumlahJalan, 'ket' => 'garis tepi dari semua sumbu'],
                    ['nama' => 'Selokan', 'kode' => 'GARIS SELOKAN',
                        'jumlah' => $jumlahSelokan, 'ket' => 'turunan 020100'],
                    ['nama' => 'Tepi sungai', 'kode' => 'TEPI SUNGAI',
                        'jumlah' => $jumlahLapis('GARIS TEPI SUNGAI DARI SUMBU'), 'ket' => 'tepi nyata per segmen sumbu'],
                    ['nama' => 'Sumbu sungai', 'kode' => 'SUMBU SUNGAI',
                        'jumlah' => ($jumlahSumbuSungai ?? 0) + $jumlahSungaiManual, 'ket' => 'polilin hasil jahit'],
                    ['nama' => 'Tanpa label', 'kode' => 'GARIS LAIN',
                        'jumlah' => $jumlahLapis('GARIS LAIN 020100'), 'ket' => 'garis 020100 tanpa kelas'],
                ],
            ],
            [
                'judul' => 'Turunan sumbu jalan',
                'lapis' => [
                    ['nama' => 'Sumbu jalan', 'kode' => 'SUMBU JALAN',
                        'jumlah' => $jumlahLapis('GARIS JALAN SUMBU DARI LABEL') + $jumlahSumbuManual,
                        'ket' => 'sumbu bahan; yang digambar lebih sedikit setelah dirapikan'],
                ],
            ],
            [
                'judul' => 'Lainnya',
                'lapis' => [
                    ['nama' => 'Garis luar desa', 'kode' => '010500',
                        'jumlah' => $jumlahLapis('010500') + $jumlahBatasManual, 'ket' => 'cincin batas desa'],
                    ['nama' => 'Sisa 020400', 'kode' => '020400',
                        'jumlah' => $jumlahLapis('020400'), 'ket' => 'arti belum diketahui'],
                ],
            ],
            [
                'judul' => 'Obyek manual',
                'lapis' => [
                    ['nama' => 'Titik obyek', 'kode' => 'GEOJSON',
                        'jumlah' => $jumlahTitikObyek, 'ket' => 'titik obyek dari alat penanda'],
                    ['nama' => 'Bidang manual', 'kode' => 'TABEL BIDANG',
                        'jumlah' => $jumlahBidangManual, 'ket' => 'sumber garis-manual'],
                ],
            ],
            [
                'judul' => 'Belum terpetakan',
                'lapis' => [
                    ['nama' => 'Calon bidang', 'kode' => 'CALON BIDANG',
                        'jumlah' => $jumlahLapis('CALON BIDANG'), 'ket' => 'garis 020100 hampir tertutup'],
                ],
            ],
            [
                'judul' => 'Label teks',
                'lapis' => [
                    ['nama' => 'Label NIB', 'kode' => 'NIB HM',
                        'jumlah' => $jumlahLapis('NIB HM'), 'ket' => 'teks NIB'],
                    ['nama' => 'Label batas desa', 'kode' => 'BATAS DESA',
                        'jumlah' => $jumlahLapis('BATAS DESA'), 'ket' => 'teks batas desa'],
                    ['nama' => 'Label blok', 'kode' => 'BLOK',
                        'jumlah' => $jumlahLapis('BLOK'), 'ket' => 'teks nomor blok'],
                    ['nama' => 'Label jalan', 'kode' => 'BATAS JALAN',
                        'jumlah' => $jumlahLapis('BATAS JALAN'), 'ket' => 'teks nama jalan'],
                ],
            ],
        ],
    ],
    'blok' => [
        'judul' => 'Peta Blok',
        'tautan' => 'peta-blok.php',
        'ringkas' => 'Peta khusus batas blok beserta jalan dan perairan.',
        'kelompok' => [
            [
                'judul' => 'Batas blok',
                'lapis' => [
                    ['nama' => 'Blok ukur', 'warna' => '#14603a',
                        'jumlah' => $jumlahBlok, 'ket' => 'batas ukur tersedia'],
                    ['nama' => 'Blok perkiraan', 'warna' => '#a06a12',
                        'jumlah' => $jumlahBlokPerkiraan, 'ket' => 'batas perkiraan'],
                ],
            ],
            [
                'judul' => 'Garis label',
                'lapis' => [
                    ['nama' => 'Jalan', 'warna' => '#8b7b68',
                        'jumlah' => $hitungFitur('batas-jalan.geojson'), 'ket' => 'label BATAS JALAN'],
                    ['nama' => 'Sungai & saluran', 'warna' => '#087b83',
                        'jumlah' => $hitungFitur('batas-perairan.geojson'), 'ket' => 'label BATAS PERAIRAN'],
                ],
            ],
        ],
    ],
];

$hitungLapisToc = static function (array $peta): int {
    $n = 0;
    foreach ($peta['kelompok'] as $kelompok) {
        $n += count($kelompok['lapis']);
    }
    return $n;
};

sip_header('ArcToolbox', 'digitasi');
?>

<?php sip_judul('Katalog alat', 'ArcToolbox', 'Seluruh alat pengelola data SIPETADES dalam satu daftar. Alat Web membuka halamannya langsung; alat CLI dijalankan dari terminal. Halaman ini hanya rujukan, tidak ada tombol yang mengubah data.'); ?>

<div class="toolbox-ringkas">
    <span><strong><?= e(angka($jumlahWeb)) ?></strong> alat web</span>
    <span><strong><?= e(angka($jumlahCli)) ?></strong> alat CLI</span>
    <span><strong><?= e(angka(count($katalog))) ?></strong> kelompok</span>
    <span><strong><?= e(angka($totalBerkas)) ?></strong> berkas workspace</span>
</div>

<div class="toolbox-tata-letak">
    <nav class="kartu toolbox-nav" aria-label="Daftar isi ArcToolbox">
        <h2>Table of Contents</h2>
        <ol class="toolbox-nav-toc">
            <li><a href="#toc-lapisan"><span>Semua lapis</span><b><?= e(angka($hitungLapisToc($toc['bidang']) + $hitungLapisToc($toc['induk']) + $hitungLapisToc($toc['blok']))) ?></b></a></li>
            <?php foreach ($toc as $kunci => $peta): ?>
                <li>
                    <a href="#toc-<?= e($kunci) ?>">
                        <span><?= e($peta['judul']) ?></span>
                        <b><?= e(angka($hitungLapisToc($peta))) ?></b>
                    </a>
                </li>
            <?php endforeach; ?>
        </ol>
        <h2 class="toolbox-nav-pisah">Katalog</h2>
        <ol>
            <li>
                <a href="#katalog-berkas">
                    <span>Katalog berkas</span>
                    <b><?= e(angka($totalBerkas)) ?></b>
                </a>
            </li>
        </ol>
        <h2 class="toolbox-nav-pisah">Tampilan</h2>
        <ol>
            <li>
                <a href="#tampilan-peta">
                    <span>Data View &amp; Layout View</span>
                    <b>2</b>
                </a>
            </li>
        </ol>
        <h2 class="toolbox-nav-pisah">Peta</h2>
        <ol>
            <li>
                <a href="#alat-ukur-peta">
                    <span>Alat ukur</span>
                    <b>2</b>
                </a>
            </li>
            <li>
                <a href="#menuju-koordinat">
                    <span>Menuju koordinat</span>
                    <b>1</b>
                </a>
            </li>
            <li>
                <a href="#navigasi-peta">
                    <span>Navigasi peta</span>
                    <b>5</b>
                </a>
            </li>
            <li>
                <a href="#seleksi-atribut">
                    <span>Seleksi &amp; tabel atribut</span>
                    <b>2</b>
                </a>
            </li>
        </ol>
        <h2 class="toolbox-nav-pisah">Analisis</h2>
        <ol>
            <li>
                <a href="#analisis-spasial">
                    <span>Analisis spasial dasar</span>
                    <b>4</b>
                </a>
            </li>
        </ol>
        <h2 class="toolbox-nav-pisah">Kelompok alat</h2>
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
        <section class="kartu toolbox-bagian toolbox-toc" id="toc-lapisan">
            <header class="kepala-kartu">
                <div>
                    <h2>Table of Contents (Layers)</h2>
                    <p>Lapis yang tampil di tiap peta. Angka dihitung dari metadata DXF, berkas GeoJSON,
                        dan basis data saat halaman ini dibuka.</p>
                </div>
            </header>
            <?php foreach ($toc as $kunci => $peta): ?>
                <div class="toolbox-toc-peta" id="toc-<?= e($kunci) ?>">
                    <header>
                        <div>
                            <h3><?= e($peta['judul']) ?></h3>
                            <p><?= e($peta['ringkas']) ?></p>
                        </div>
                        <a class="toolbox-arah" href="<?= e(sip_url($peta['tautan'])) ?>">Buka peta &rarr;</a>
                    </header>
                    <?php foreach ($peta['kelompok'] as $kelompok): ?>
                        <div class="toolbox-toc-kelompok">
                            <h4><?= e($kelompok['judul']) ?></h4>
                            <ul class="toolbox-toc-daftar">
                                <?php foreach ($kelompok['lapis'] as $lapis): ?>
                                    <li>
                                        <i class="toolbox-warna<?= isset($lapis['warna']) ? '' : ' toolbox-warna-kosong' ?>"
                                            <?= isset($lapis['warna']) ? 'style="background:' . e($lapis['warna']) . '"' : '' ?>></i>
                                        <span class="toolbox-toc-nama"><?= e($lapis['nama']) ?>
                                            <?php if (isset($lapis['kode'])): ?><code class="toolbox-kode"><?= e($lapis['kode']) ?></code><?php endif; ?>
                                        </span>
                                        <b class="toolbox-toc-jumlah"><?= $lapis['jumlah'] === null ? '&mdash;' : e(angka((int) $lapis['jumlah'])) ?></b>
                                        <span class="toolbox-toc-ket"><?= e($lapis['ket']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="kartu toolbox-bagian toolbox-tampilan" id="tampilan-peta">
            <header class="kepala-kartu">
                <div>
                    <h2>Data View &amp; Layout View</h2>
                    <p>Dua mode tampilan di halaman Peta Bidang: Data View untuk mengolah data spasial,
                        Layout View untuk merancang tata letak peta sebelum diekspor.</p>
                </div>
            </header>
            <div class="toolbox-mode">
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana">Data View</span>
                        <h3>Mode pengolahan data</h3>
                    </header>
                    <p>Peta interaktif penuh: panel lapisan, penyaring, daftar bidang, dan legenda hidup
                        tampil seperti biasa.</p>
                    <ul>
                        <li>Centang lapisan Bidang tanah, Batas blok, Jalan, Selokan, Saluran, Tepi saluran,
                            Sungai, atau Titik laporan.</li>
                        <li>Klik bidang untuk rincian; saring menurut blok, hak, atau kata kunci.</li>
                        <li>Peta ini menampilkan data, bukan menggambar batas baru.</li>
                    </ul>
                    <a class="toolbox-arah" href="<?= e(sip_url('peta.php')) ?>">Buka Data View &rarr;</a>
                </article>
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana toolbox-mode-lencana-garis">Layout View</span>
                        <h3>Mode perancangan tata letak</h3>
                    </header>
                    <p>Peta dibingkai sebagai lembar A4 lanskap dengan blok judul, legenda, skala perkiraan,
                        arah utara, tanggal, dan sumber data.</p>
                    <ul>
                        <li>Panel lapisan dan daftar bidang disembunyikan agar lembar bersih.</li>
                        <li>Geser dan perbesar peta tetap bisa; skala di blok judul ikut berubah.</li>
                        <li>Tombol <strong>Ekspor PDF</strong> membuka dialog cetak peramban: pilih
                            <em>Simpan sebagai PDF</em>, ukuran A4 lanskap.</li>
                    </ul>
                    <a class="toolbox-arah" href="<?= e(sip_url('peta.php', ['tampilan' => 'layout'])) ?>">Buka Layout View &rarr;</a>
                </article>
            </div>
            <p class="toolbox-katalog-catatan">Mode dipilih lewat tombol <strong>Data View</strong> dan
                <strong>Layout View</strong> di atas peta, atau langsung dengan alamat
                <code>peta.php?tampilan=layout</code>. Tidak ada data yang ditulis: tata letak hanya mengubah
                cara peta ditampilkan dan dicetak.</p>
        </section>

        <section class="kartu toolbox-bagian toolbox-ukur" id="alat-ukur-peta">
            <header class="kepala-kartu">
                <div>
                    <h2>Measure (Alat ukur)</h2>
                    <p>Ukur jarak dan luas langsung di Peta Bidang. Semua hitungan berjalan di peramban;
                        tidak ada hasil yang disimpan ke basis data.</p>
                </div>
                <a class="toolbox-arah" href="<?= e(sip_url('peta.php')) ?>">Buka Peta Bidang &rarr;</a>
            </header>
            <div class="toolbox-mode">
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana">Jarak</span>
                        <h3>Garis berantai</h3>
                    </header>
                    <p>Klik peta untuk menambah titik; panjang tiap ruas dijumlahkan mengikuti rumus
                        jarak bola Leaflet. Cocok untuk mengukur lebar jalan atau panjang saluran.</p>
                    <ul>
                        <li>Hasil di bawah 1 km dalam meter satu desimal; di atasnya dalam kilometer dua desimal.</li>
                        <li>Titik ditandai lingkaran, ruas digambar putus-putus oranye.</li>
                        <li><strong>Hapus titik</strong> mengosongkan ukuran tanpa menutup alat.</li>
                    </ul>
                </article>
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana toolbox-mode-lencana-garis">Luas</span>
                        <h3>Poligon tertutup</h3>
                    </header>
                    <p>Mulai dari tiga titik; poligon menutup sendiri dari titik terakhir ke titik pertama.
                        Luas memakai kelebihan bola (Chamberlain-Duquette), jadi persil ratusan meter tetap
                        akurat tanpa proyeksi bidang datar.</p>
                    <ul>
                        <li>Hasil ditampilkan dalam m² dan hektare, disertai keliling.</li>
                        <li>Ganti jenis ukuran kapan saja; titik yang sudah ada dipakai ulang.</li>
                        <li><strong>Tutup</strong> atau tombol <kbd>Esc</kbd> mengakhiri pengukuran.</li>
                    </ul>
                </article>
            </div>
            <p class="toolbox-katalog-catatan">Tombol <strong>Ukur</strong> ada di bilah Tampilan, di atas peta,
                dan hanya aktif di Data View. Selama mengukur, klik pada bidang tidak membuka popup supaya
                titik ukur tidak tertutup; klik ganda untuk zoom dimatikan sementara dan dinyalakan lagi
                ketika alat ditutup.</p>
        </section>

        <section class="kartu toolbox-bagian toolbox-koordinat" id="menuju-koordinat">
            <header class="kepala-kartu">
                <div>
                    <h2>Go To XY (Menuju koordinat)</h2>
                    <p>Pusatkan Peta Bidang ke lintang dan bujur tertentu tanpa mencari nama bidang.
                        Hanya memindahkan pandangan; tidak ada data yang ditulis.</p>
                </div>
                <a class="toolbox-arah" href="<?= e(sip_url('peta.php')) ?>">Buka Peta Bidang &rarr;</a>
            </header>
            <div class="toolbox-mode">
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana toolbox-mode-lencana-garis">Go To XY</span>
                        <h3>Langsung ke derajat desimal</h3>
                    </header>
                    <p>Isi <strong>Lintang</strong> lalu <strong>Bujur</strong> dalam derajat desimal, misalnya
                        <code>-7,0432</code> dan <code>110,0631</code>. Koma maupun titik diterima sebagai
                        pemisah desimal.</p>
                    <ul>
                        <li><strong>Menuju</strong> memusatkan peta, memasang penanda merah di tujuan, dan
                            memperbesar peta paling sedikit ke tingkat 17.</li>
                        <li>Lintang dibatasi -90 sampai 90 dan bujur -180 sampai 180; isian salah
                            dijawab pesan, bukan peta kosong.</li>
                        <li>Baris <em>Pusat peta</em> membaca ulang koordinat tengah setiap kali peta
                            digeser atau diperbesar.</li>
                        <li><kbd>Esc</kbd> atau <strong>Tutup</strong> menyembunyikan panel; penanda tujuan
                            tetap tampil sampai halaman dimuat ulang.</li>
                    </ul>
                </article>
            </div>
            <p class="toolbox-katalog-catatan">Tombol <strong>Go To XY</strong> ada di bilah Tampilan, di atas peta,
                dan hanya tampil di Data View. Membuka alat ukur menutup panel koordinat dan sebaliknya,
                supaya klik peta tidak berebut fungsi.</p>
        </section>

        <section class="kartu toolbox-bagian toolbox-navigasi" id="navigasi-peta">
            <header class="kepala-kartu">
                <div>
                    <h2>Navigasi peta</h2>
                    <p>Bilah <strong>Navigasi</strong> di bawah bilah Tampilan Peta Bidang: zoom, geser,
                        seluruh data, dan riwayat pandangan. Semua hanya mengubah pandangan peta.</p>
                </div>
                <a class="toolbox-arah" href="<?= e(sip_url('peta.php')) ?>">Buka Peta Bidang &rarr;</a>
            </header>
            <div class="toolbox-mode">
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana">Zoom</span>
                        <h3>Zoom In &amp; Zoom Out</h3>
                    </header>
                    <p>Dua tombol memperbesar dan memperkecil peta satu tingkat. Roda tetikus dan
                        tombol <kbd>+</kbd>/<kbd>&minus;</kbd> bawaan Leaflet tetap berfungsi.</p>
                    <ul>
                        <li>Setiap langkah zoom ikut dicatat di riwayat pandangan.</li>
                        <li>Tingkat zoom saat ini tampil di legenda peta dan blok judul Layout View.</li>
                    </ul>
                </article>
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana">Pan</span>
                        <h3>Geser peta</h3>
                    </header>
                    <p>Tombol <strong>Pan</strong> menyalakan atau mematikan geser dengan menarik peta.
                        Saat dimatikan, menarik peta tidak memindahkan pandangan sehingga klik bidang
                        lebih mudah.</p>
                    <ul>
                        <li>Keadaan tombol tersimpan selama halaman terbuka; bawaan menyala.</li>
                        <li>Memperbesar peta dan menekan tombol navigasi tetap bisa saat Pan dimatikan.</li>
                    </ul>
                </article>
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana">Full Extent</span>
                        <h3>Seluruh data</h3>
                    </header>
                    <p>Memperkecil dan memusatkan peta sampai semua bidang pada saringan ini masuk layar.
                        Bila bidang belum termuat, batas blok dan lapis garis yang aktif dipakai sebagai
                        cadangan.</p>
                    <ul>
                        <li>Batas dihitung dari geometri yang sedang tampil, bukan batas desa.</li>
                        <li>Peta bergerak halus (fly) tanpa memuat ulang data.</li>
                    </ul>
                </article>
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana toolbox-mode-lencana-garis">Riwayat</span>
                        <h3>Previous/Next Extent</h3>
                    </header>
                    <p>Tombol <strong>Sebelum</strong> dan <strong>Sesudah</strong> melangkah mundur dan
                        maju di antara pandangan yang pernah dipakai, seperti Next/Previous Extent di ArcMap.</p>
                    <ul>
                        <li>Riwayat mencatat sampai 60 pandangan terakhir selama halaman terbuka.</li>
                        <li>Pandangan baru setelah melangkah mundur menghapus cabang maju.</li>
                        <li>Tombol nonaktif bila tidak ada langkah tersisa.</li>
                    </ul>
                </article>
            </div>
            <p class="toolbox-katalog-catatan">Bilah Navigasi hanya tampil di Data View; di Layout View
                ia disembunyikan agar lembar cetak bersih. Tidak ada data yang ditulis.</p>
        </section>

        <section class="kartu toolbox-bagian toolbox-seleksi" id="seleksi-atribut">
            <header class="kepala-kartu">
                <div>
                    <h2>Seleksi &amp; tabel atribut</h2>
                    <p>Menandai bidang langsung di peta dan membacanya sebagai tabel. Seleksi hanya
                        penyorotan di peramban; status bidang di basis data tidak berubah.</p>
                </div>
                <a class="toolbox-arah" href="<?= e(sip_url('peta.php')) ?>">Buka Peta Bidang &rarr;</a>
            </header>
            <div class="toolbox-mode">
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana">Pilih</span>
                        <h3>Seleksi di peta</h3>
                    </header>
                    <p>Tombol <strong>Pilih</strong> mengubah klik bidang menjadi menandai atau melepas
                        pilihan. Bidang terpilih diberi garis merah tebal dan isi merah muda, lalu diangkat
                        ke depan.</p>
                    <ul>
                        <li>Jumlah terpilih tampil pada tombol Pilih dan pada tabel atribut.</li>
                        <li>Sebelum alat ukur dipakai, seleksi tetap boleh; klik bidang tidak membuka popup
                            selama mode Pilih menyala.</li>
                        <li>Mengganti saringan memuat ulang bidang dan membersihkan pilihan.</li>
                    </ul>
                </article>
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana toolbox-mode-lencana-garis">Tabel atribut</span>
                        <h3>Attribute Table (baca-saja)</h3>
                    </header>
                    <p>Tombol <strong>Tabel atribut</strong> membuka kartu berisi kolom Kode, Blok, Hak,
                        Pemegang, Luas, dan Status untuk bidang yang sedang tampil. Klik baris untuk
                        memusatkan peta; centang untuk memilih bidang.</p>
                    <ul>
                        <li>Maksimal 500 baris pertama ditampilkan; persempit Saring peta untuk sisanya.</li>
                        <li><strong>Pilih semua baris</strong> dan <strong>Bersihkan pilihan</strong>
                            bekerja pada baris yang tampil.</li>
                        <li>Tabel mengikuti saringan yang sama dengan peta; tidak ada sel yang bisa diubah.</li>
                    </ul>
                </article>
            </div>
            <p class="toolbox-katalog-catatan">Select by Attribute sepadan dengan kartu <strong>Saring peta</strong>
                di samping peta (kata kunci, blok, hak, status), yang menyaring bidang sebelum tabel dibaca.
                Add Data untuk SHP, raster, atau basemap baru belum tersedia di Peta Bidang.</p>
        </section>

        <section class="kartu toolbox-bagian toolbox-analisis" id="analisis-spasial">
            <header class="kepala-kartu">
                <div>
                    <h2>Analisis spasial dasar</h2>
                    <p>Empat alat pada kartu <strong>Analisis spasial</strong> di samping Peta Induk:
                        memotong, menyangga, mengiris atau menggabung, dan menyatukan fitur. Hitungan
                        berjalan di peramban dengan pustaka Turf memakai koordinat DXF lokal; data Peta
                        Induk tidak diubah.</p>
                </div>
                <a class="toolbox-arah" href="<?= e(sip_url('peta-lama.php')) ?>">Buka Peta Induk &rarr;</a>
            </header>
            <div class="toolbox-mode">
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana">Clip</span>
                        <h3>Memotong lapis input</h3>
                    </header>
                    <p>Memotong fitur lapis input dengan batas poligon lapis lain, misalnya jalan atau
                        sungai sesuai batas desa. Fitur yang seluruhnya di dalam batas disalin apa adanya;
                        yang sebagian di luar batas diiris tepat pada garis poligon.</p>
                    <ul>
                        <li>Input boleh titik, garis, atau poligon; lapis pemotong wajib punya poligon.</li>
                        <li>Poligon diperiksa dulu apakah utuh di dalam; hanya yang terpotong yang diiris.</li>
                        <li>Atribut fitur input dibawa ke hasil.</li>
                    </ul>
                </article>
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana">Buffer</span>
                        <h3>Zona penyangga</h3>
                    </header>
                    <p>Membuat zona penyangga berjarak tertentu di sekitar tiap titik, garis, atau poligon
                        lapis terpilih. Jarak diisi dalam meter, 0,1 sampai 5.000.</p>
                    <ul>
                        <li>Zona dibuat per fitur; centang <strong>Gabung zona</strong> untuk menyatukan
                            zona yang bertumpang tindih.</li>
                        <li>Penggabungan bisa gagal pada zona yang sangat rapat; hasil per fitur tetap
                            ditampilkan dengan catatan.</li>
                    </ul>
                </article>
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana toolbox-mode-lencana-garis">Overlay</span>
                        <h3>Intersect &amp; Union</h3>
                    </header>
                    <p>Intersect mengambil bagian yang bertumpang antara lapis A dan B; Union menambahkan
                        sisa tiap fitur sehingga kedua lapis utuh. Atribut kedua lapis digabung, dan nama
                        atribut yang sama diberi awalan <code>B_</code> agar tidak tertimpa.</p>
                    <ul>
                        <li>Hanya poligon; pasangan dicari lewat kotak batas lebih dulu.</li>
                        <li>Batas 20.000 pasangan berdekatan; kurangi lapis bila terlampaui.</li>
                    </ul>
                </article>
                <article class="toolbox-mode-kartu">
                    <header>
                        <span class="toolbox-mode-lencana toolbox-mode-lencana-garis">Dissolve</span>
                        <h3>Menyatukan atribut sama</h3>
                    </header>
                    <p>Menyatukan poligon yang nilai atributnya sama menjadi satu kesatuan, misalnya
                        bidang pada blok atau hak yang sama. Tanpa pilihan atribut, seluruh fitur lapis
                        digabung.</p>
                    <ul>
                        <li>Daftar atribut dibaca dari lapis terpilih; <code>dxf_handle</code> tidak
                            ditawarkan.</li>
                        <li>Hasil dipisah per bagian bila satu nilai menutupi beberapa wilayah terpisah.</li>
                    </ul>
                </article>
            </div>
            <p class="toolbox-katalog-catatan">Hasil keempat alat tampil sebagai lapis ungu sementara
                bertajuk <strong>Hasil analisis spasial</strong>, lengkap dengan jumlah fitur, luas,
                panjang, dan tombol unduh GeoJSON. <strong>Bersihkan hasil</strong> menghapus lapis itu
                tanpa menyentuh data Peta Induk, dan hasilnya hilang saat halaman dimuat ulang.</p>
        </section>

        <section class="kartu toolbox-bagian toolbox-katalog" id="katalog-berkas">
            <header class="kepala-kartu">
                <div>
                    <h2>Katalog (Workspace)</h2>
                    <p>Folder, berkas spasial, dan metadata yang tersimpan di workspace server. Panel ini membaca
                        isi folder saat halaman dibuka; belum ada tombol unggah, ubah nama, atau hapus.</p>
                </div>
                <span class="lencana lencana-abu"><?= e(angka($totalBerkas)) ?> berkas</span>
            </header>
            <div class="toolbox-ringkas toolbox-katalog-jumlah">
                <span><strong><?= e(angka($totalFolder)) ?></strong> folder</span>
                <span><strong><?= e(angka($jumlahSpasial)) ?></strong> berkas spasial</span>
                <span><strong><?= e(angka($jumlahMetadata)) ?></strong> entri metadata</span>
                <span><strong><?= e($ukuranBerkas($totalByte)) ?></strong> total</span>
                <?php foreach ($jenisUrut as $jenis): ?>
                    <span class="<?= ($ringkasJenis[$jenis] ?? 0) === 0 ? 'toolbox-katalog-nol' : '' ?>">
                        <strong><?= e(angka($ringkasJenis[$jenis] ?? 0)) ?></strong> <?= e($jenis) ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <p class="toolbox-katalog-catatan">Pemindaian satu tingkat per folder; <code>_lama/</code> (arsip riwayat)
                tidak dihitung. Ukuran dan waktu diambil dari berkas di disk, jadi angka berubah bila alat menulis lapis baru.</p>
            <?php foreach ($katalogBerkas as $grup): ?>
                <div class="toolbox-katalog-grup">
                    <h3><?= e($grup['judul']) ?></h3>
                    <p><?= e($grup['ringkas']) ?></p>
                    <div class="toolbox-katalog-daftar">
                        <?php foreach ($grup['folder'] as $folder): ?>
                            <?php $hasil = $folder['hasil']; ?>
                            <article class="toolbox-katalog-folder">
                                <header class="toolbox-katalog-kepala">
                                    <div>
                                        <h4><?= e($folder['judul']) ?></h4>
                                        <code class="toolbox-katalog-jalur"><?= e($folder['jalur'] === '.' ? 'sigpetades/' : $folder['jalur']) ?></code>
                                    </div>
                                    <div class="toolbox-katalog-angka">
                                        <b><?= e(angka(count($hasil['berkas']))) ?></b> berkas
                                        &middot; <?= e($ukuranBerkas($hasil['byte'])) ?>
                                        <?php if ($hasil['waktu'] !== null): ?>
                                            &middot; diubah <?= e(tanggal(date('c', $hasil['waktu']), true)) ?>
                                        <?php endif; ?>
                                    </div>
                                </header>
                                <p class="toolbox-katalog-isiberkas"><?= e($folder['ringkas']) ?></p>
                                <?php if ($hasil['jenis'] !== []): ?>
                                    <div class="toolbox-katalog-jenis">
                                        <?php foreach ($jenisUrut as $jenis): ?>
                                            <?php if (($hasil['jenis'][$jenis] ?? 0) > 0): ?>
                                                <span><?= e($jenis) ?> <?= e(angka($hasil['jenis'][$jenis])) ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($hasil['berkas'] === []): ?>
                                    <p class="toolbox-katalog-kosong">Folder kosong<?= $hasil['ada'] ? '' : ' atau tidak ditemukan' ?>.</p>
                                <?php else: ?>
                                    <details class="toolbox-katalog-rinci">
                                        <summary>Lihat <?= e(angka(count($hasil['berkas']))) ?> berkas</summary>
                                        <ul class="toolbox-katalog-berkas">
                                            <li class="judul"><span>Berkas</span><span>Fitur</span><span>Ukuran</span><span>Diubah</span></li>
                                            <?php foreach ($hasil['berkas'] as $berkas): ?>
                                                <li>
                                                    <span class="toolbox-katalog-nama">
                                                        <?php if ($berkas['kode'] !== null): ?><code class="toolbox-kode"><?= e($berkas['kode']) ?></code><?php endif; ?>
                                                        <?= e($berkas['nama']) ?>
                                                    </span>
                                                    <b class="toolbox-katalog-fitur"><?= $berkas['fitur'] === null ? '&mdash;' : e(angka($berkas['fitur'])) ?></b>
                                                    <span class="toolbox-katalog-ukuran"><?= e($ukuranBerkas($berkas['byte'])) ?></span>
                                                    <span class="toolbox-katalog-waktu"><?= e(tanggal(date('c', $berkas['waktu']))) ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($jumlahMetadata > 0): ?>
                <div class="toolbox-katalog-meta" id="katalog-metadata">
                    <h3>Metadata konversi DXF</h3>
                    <p>Sumber, sistem koordinat, rentang, dan catatan dari <code>metadata.json</code> di folder lapis DXF.</p>
                    <dl class="toolbox-katalog-meta-daftar">
                        <div>
                            <dt>Berkas sumber</dt>
                            <dd><?= e((string) ($meta['source_file'] ?? '—')) ?></dd>
                        </div>
                        <div>
                            <dt>Sistem koordinat</dt>
                            <dd><?= e((string) ($meta['coordinate_system'] ?? '—')) ?></dd>
                        </div>
                        <div>
                            <dt>Rentang X</dt>
                            <dd><?= e(number_format((float) ($meta['extent']['min_x'] ?? 0), 1, ',', '.')) ?>
                                &mdash; <?= e(number_format((float) ($meta['extent']['max_x'] ?? 0), 1, ',', '.')) ?></dd>
                        </div>
                        <div>
                            <dt>Rentang Y</dt>
                            <dd><?= e(number_format((float) ($meta['extent']['min_y'] ?? 0), 1, ',', '.')) ?>
                                &mdash; <?= e(number_format((float) ($meta['extent']['max_y'] ?? 0), 1, ',', '.')) ?></dd>
                        </div>
                        <div>
                            <dt>Dikonversi</dt>
                            <dd><?= e(tanggal((string) ($meta['converted_at'] ?? ''), true)) ?></dd>
                        </div>
                        <div>
                            <dt>Turunan terakhir</dt>
                            <dd><?= e(tanggal((string) ($meta['derived_at'] ?? ''), true)) ?></dd>
                        </div>
                        <div>
                            <dt>Entri lapis</dt>
                            <dd><?= e(angka($jumlahMetadata)) ?> lapis,
                                <?= e(angka($jumlahTanpaMeta)) ?> GeoJSON tanpa entri</dd>
                        </div>
                    </dl>
                    <?php if (!empty($meta['warning'])): ?>
                        <p class="toolbox-katalog-peringatan"><?= e((string) $meta['warning']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($meta['notes']) && is_array($meta['notes'])): ?>
                        <details class="toolbox-katalog-rinci">
                            <summary>Lihat <?= e(angka(count($meta['notes']))) ?> catatan konversi</summary>
                            <ol class="toolbox-katalog-catatan-ol">
                                <?php foreach ($meta['notes'] as $catatan): ?>
                                    <li><?= e(str_replace('`', '', (string) $catatan)) ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

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
.toolbox-tata-letak > * { min-width: 0; }
.toolbox-nav {
    position: sticky; top: 86px; padding: 14px;
    max-height: calc(100vh - 104px); overflow-y: auto;
}
.toolbox-nav h2 {
    margin: 0 0 9px; color: var(--redup); font-size: 10.5px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .8px;
}
.toolbox-nav-pisah { margin-top: 16px !important; }
.toolbox-nav ol { list-style: none; margin: 0; padding: 0; display: grid; gap: 2px; }
.toolbox-nav a {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    padding: 7px 9px; border-radius: 8px; color: #3b4a44;
    font-size: 12px; font-weight: 600; text-decoration: none;
}
.toolbox-nav a:hover { background: var(--hijau-muda); color: var(--hijau-tua); }
.toolbox-nav b { color: var(--redup); font-size: 10.5px; }
.toolbox-toc { margin-bottom: 18px; }
.toolbox-toc-peta { padding: 14px 0 2px; border-top: 1px solid var(--garis); scroll-margin-top: 86px; }
.toolbox-toc-peta:first-of-type { border-top: 0; padding-top: 2px; }
.toolbox-toc-peta > header {
    display: flex; flex-wrap: wrap; align-items: baseline;
    justify-content: space-between; gap: 8px;
}
.toolbox-toc-peta > header a { text-decoration: none; }
.toolbox-toc-peta h3 { margin: 0; color: var(--tinta); font-size: 14px; }
.toolbox-toc-peta header p { margin: 2px 0 0; color: var(--redup); font-size: 11.5px; }
.toolbox-toc-kelompok { margin-top: 11px; }
.toolbox-toc-kelompok h4 {
    margin: 0 0 4px; color: var(--redup); font-size: 10.5px; font-weight: 800;
    text-transform: uppercase; letter-spacing: .7px;
}
.toolbox-toc-daftar {
    list-style: none; margin: 0; padding: 0;
    display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 3px 16px;
}
.toolbox-toc-daftar li {
    display: grid; grid-template-columns: 10px minmax(0, 1fr) auto; gap: 2px 7px;
    align-items: center; padding: 5px 0; border-bottom: 1px dashed var(--garis);
}
.toolbox-warna {
    width: 10px; height: 10px; border-radius: 3px;
    border: 1px solid rgba(0, 0, 0, .15); align-self: center;
}
.toolbox-warna-kosong { background: transparent; border: 1px dashed var(--garis); }
.toolbox-toc-nama { color: var(--tinta); font-size: 12px; font-weight: 600; }
.toolbox-kode {
    padding: 1px 4px; border-radius: 4px; background: #f4f7f5; color: #5b6b64;
    font: 600 9.5px ui-monospace, "Cascadia Mono", Consolas, monospace;
}
.toolbox-toc-jumlah {
    color: var(--tinta); font-size: 11.5px; font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.toolbox-toc-ket { grid-column: 2 / -1; color: var(--redup); font-size: 10.5px; line-height: 1.45; }
.toolbox-katalog { margin-bottom: 18px; }
.toolbox-katalog-jumlah { margin: 8px 0 0; }
.toolbox-katalog-nol { opacity: .55; }
.toolbox-katalog-catatan { margin: 9px 0 0; color: var(--redup); font-size: 11.5px; line-height: 1.6; }
.toolbox-tampilan { margin-bottom: 18px; }
.toolbox-mode { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 10px; }
.toolbox-mode-kartu {
    display: flex; flex-direction: column; gap: 8px;
    padding: 14px 15px; border: 1px solid var(--garis); border-radius: 12px; background: #fbfcfb;
}
.toolbox-mode-kartu header { display: flex; align-items: center; gap: 8px; }
.toolbox-mode-kartu h3 { margin: 0; font-size: 13.5px; }
.toolbox-mode-lencana {
    padding: 2px 9px; border-radius: 999px; background: var(--hijau-tua);
    color: #fff; font-size: 10px; font-weight: 800; letter-spacing: .04em;
}
.toolbox-mode-lencana-garis {
    background: #fff; color: var(--hijau-tua); border: 1px solid var(--hijau-tua);
}
.toolbox-mode-kartu p { margin: 0; color: #46564f; font-size: 12px; line-height: 1.6; }
.toolbox-mode-kartu ul {
    margin: 0; padding-left: 17px; color: #46564f;
    font-size: 11.5px; line-height: 1.65;
}
.toolbox-mode-kartu .toolbox-arah { margin-top: auto; font-size: 12px; font-weight: 700; }
@media (max-width: 760px) { .toolbox-mode { grid-template-columns: 1fr; } }
.toolbox-katalog-catatan code,
.toolbox-katalog-meta code {
    padding: 1px 5px; border-radius: 4px; background: #f4f7f5;
    font: 600 10.5px ui-monospace, "Cascadia Mono", Consolas, monospace; color: #2b3b34;
}
.toolbox-katalog-grup { margin-top: 16px; }
.toolbox-katalog-grup > h3 { margin: 0; color: var(--tinta); font-size: 13px; }
.toolbox-katalog-grup > p { margin: 2px 0 0; color: var(--redup); font-size: 11.5px; }
.toolbox-katalog-daftar { display: grid; gap: 8px; margin-top: 8px; }
.toolbox-katalog-folder { padding: 10px 12px; border: 1px solid var(--garis); border-radius: 10px; }
.toolbox-katalog-kepala {
    display: flex; flex-wrap: wrap; align-items: baseline;
    justify-content: space-between; gap: 4px 12px;
}
.toolbox-katalog-kepala h4 { margin: 0; color: var(--tinta); font-size: 12.5px; }
.toolbox-katalog-jalur {
    display: inline-block; margin-top: 3px; padding: 1px 5px; border-radius: 4px;
    background: #f4f7f5; color: #5b6b64;
    font: 600 10px ui-monospace, "Cascadia Mono", Consolas, monospace; overflow-wrap: anywhere;
}
.toolbox-katalog-angka { color: var(--redup); font-size: 10.5px; }
.toolbox-katalog-angka b { color: var(--tinta); font-size: 11.5px; }
.toolbox-katalog-isiberkas { margin: 4px 0 0; color: var(--redup); font-size: 11.5px; line-height: 1.5; }
.toolbox-katalog-jenis { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.toolbox-katalog-jenis span {
    padding: 1px 7px; border-radius: 999px; background: #f4f7f5;
    color: #4b5b54; font-size: 10px; font-weight: 600;
}
.toolbox-katalog-kosong { margin: 6px 0 0; color: var(--redup); font-size: 11px; font-style: italic; }
.toolbox-katalog-rinci { margin-top: 6px; }
.toolbox-katalog-rinci > summary {
    cursor: pointer; padding: 3px 0; color: var(--hijau-tua);
    font-size: 11px; font-weight: 700;
}
.toolbox-katalog-berkas { list-style: none; margin: 4px 0 0; padding: 0; display: grid; gap: 1px; }
.toolbox-katalog-berkas li {
    display: grid; grid-template-columns: minmax(0, 1fr) 68px 78px 104px; gap: 8px;
    align-items: center; padding: 4px 0; border-bottom: 1px dashed var(--garis); font-size: 11px;
}
.toolbox-katalog-berkas li.judul {
    border-bottom: 0; padding-bottom: 1px; color: var(--redup);
    font-size: 9.5px; font-weight: 800; letter-spacing: .6px; text-transform: uppercase;
}
.toolbox-katalog-berkas li.judul span:nth-child(n+2) { text-align: right; }
.toolbox-katalog-nama { color: var(--tinta); font-weight: 600; overflow-wrap: anywhere; }
.toolbox-katalog-nama code { margin-right: 4px; }
.toolbox-katalog-fitur {
    color: var(--tinta); font-weight: 700; text-align: right;
    font-variant-numeric: tabular-nums;
}
.toolbox-katalog-ukuran, .toolbox-katalog-waktu {
    color: var(--redup); text-align: right; font-variant-numeric: tabular-nums;
}
.toolbox-katalog-meta { margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--garis); }
.toolbox-katalog-meta h3 { margin: 0; color: var(--tinta); font-size: 14px; }
.toolbox-katalog-meta > p { margin: 2px 0 0; color: var(--redup); font-size: 11.5px; line-height: 1.55; }
.toolbox-katalog-meta-daftar {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
    gap: 8px; margin: 10px 0 0;
}
.toolbox-katalog-meta-daftar div { padding: 7px 9px; border: 1px solid var(--garis); border-radius: 8px; }
.toolbox-katalog-meta-daftar dt {
    color: var(--redup); font-size: 9.5px; font-weight: 800;
    letter-spacing: .6px; text-transform: uppercase;
}
.toolbox-katalog-meta-daftar dd {
    margin: 2px 0 0; color: var(--tinta); font-size: 11.5px; line-height: 1.45; overflow-wrap: anywhere;
}
.toolbox-katalog-peringatan {
    margin: 10px 0 0; padding: 8px 10px; border: 1px solid #f0d9a8; border-radius: 8px;
    background: #fdf6e7; color: #7a5b12; font-size: 11.5px; line-height: 1.6;
}
.toolbox-katalog-catatan-ol { margin: 6px 0 0; padding-left: 18px; color: var(--redup); font-size: 11px; line-height: 1.65; }
.toolbox-bagian { scroll-margin-top: 86px; }
.toolbox-bagian > .kepala-kartu { margin-bottom: 4px; }
.toolbox-daftar { display: grid; grid-template-columns: minmax(0, 1fr); gap: 6px; margin-top: 10px; }
.toolbox-alat {
    display: block; min-width: 0; padding: 10px 12px; border: 1px solid var(--garis);
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
    .toolbox-nav { position: static; max-height: none; }
    .toolbox-nav ol { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .toolbox-nav ol.toolbox-nav-toc { grid-template-columns: 1fr; }
}
@media (max-width: 620px) {
    .toolbox-nav ol { grid-template-columns: 1fr; }
    .toolbox-katalog-berkas li { grid-template-columns: minmax(0, 1fr) 60px 70px; }
    .toolbox-katalog-berkas li > :nth-child(4) { display: none; }
}
</style>

<?php sip_footer(); ?>
