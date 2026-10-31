<?php
declare(strict_types=1);

/**
 * Impor data lapangan ke basis data SIPETADES.
 *
 *   php tools/impor.php semua          impor semua sumber berurutan
 *   php tools/impor.php blok           hanya blok_peta.geojson
 *   php tools/impor.php bidang         hanya bidang_tanah + bidang_lain
 *   php tools/impor.php sppt [berkas]  impor CSV SPPT (bawaan: files/data excel)
 *   php tools/impor.php sppt-dump     impor data PBB dari dump lama sigtanahdesa.sql
 *   php tools/impor.php buku-c         daftarkan isi folder arsip PDF
 *   php tools/impor.php gg             kumpulkan penanda tanah gang (GG) dari MASTER.dxf
 *   php tools/impor.php gang <berkas>  impor poligon gang menjadi bidang ber-Hak Pakai
 *   php tools/impor.php gabung <asal> <tujuan>
 *                                    gabungkan satu blok ke blok lain
 *   php tools/impor.php batas [blok]  pasang garis luar blok dari MASTER.dxf
 *   php tools/impor.php kira <blok>   perkirakan batas dari gabungan bidangnya
 *   php tools/impor.php pindah <asal> <tujuan>
 *                                    pindahkan bidang yang di luar batas asal
 *   php tools/impor.php ubah-blok <blok> <kode...>
 *                                    tentukan blok beberapa bidang tertentu
 *   php tools/impor.php rapikan <blok...>
 *                                    potong batas blok sampai ke tepi jalan OSM
 *   php tools/impor.php duplikat     tandai bidang yang poligonnya kembar
 *   php tools/impor.php duplikat <kode-kode>
 *                                    sama, tapi salinan kembar lintas blok yang
 *                                    dibuang dipilih petugas (kode yang dibuang)
 *   php tools/impor.php taut-blok   isikan blok peta ke setiap objek pajak dari NOP
 *   php tools/impor.php nama        isi nama pemegang dari label tn_nama MASTER.dxf
 *                                   (label di dalam bidang, lalu label ≤2 m dari tepi
 *                                   yang tak diperebutkan, lalu kembaran poligon)
 *   php tools/impor.php nis         isi bidang.nis dari label `NIS : ...` pada lapisan
 *                                   tn_alashak MASTER.dxf; bawaan hanya bidang
 *                                   perlu_verifikasi, tambah --semua untuk semua bidang
 *                                   tanah hidup
 *   php tools/impor.php kode-nis    ganti kode DXF-<lapisan>-<nomor> menjadi HM-<nis>
 *                                   hanya untuk bidang yang punya satu NIS unik
 *   php tools/impor.php nama-verifikasi
 *                                   isi nama pemegang bidang perlu_verifikasi dari
 *                                   label tn_nama, termasuk lapisan 020100 (tipe lain)
 *   php tools/impor.php nib         isi bidang.nib dari label lapisan `NIB HM` MASTER.dxf;
 *                                   hanya bidang yang nib-nya masih kosong, dan sekaligus
 *                                   melaporkan uji silang lapisan itu terhadap nib lama
 *   php tools/impor.php daftar      isi bidang.nomor_daftar dari label lapisan `080201`
 *                                   MASTER.dxf; kolomnya dibuat pada --terapkan pertama
 *   php tools/impor.php hak-milik   tetapkan jenis hak Hak Milik pada bidang yang jenisnya
 *                                   masih sementara BLM/BLH; bawaan hanya perlu_verifikasi
 *   php tools/impor.php hak-anotasi
 *                                   betulkan jenis hak bidang yang poligonya beranotasi
 *                                   petugas: GG -> Hak Pakai (hanya pada bidang tanpa
 *                                   nomor persil), TANAH DESA/BENGKOK/BALAI -> milik
 *                                   pemerintah desa; masjid dan jalan hanya dilaporkan;
 *                                   yang dulu pindah oleh aturan lama dikembalikan ke HM
 *   php tools/impor.php kembar-tepat
 *                                   tandai duplikat bidang yang tempatnya sama persis dengan
 *                                   bidang hidup lain tapi urutan titiknya beda sehingga
 *                                   lolos dari ST_Equals; kembar aktif + aktif hanya dilaporkan
 *   php tools/impor.php luas        hitung ulang luas_m2 yang melenceng dari luas gambar cincinnya;
 *                                   ambangnya dengan --toleransi=<bagian> (bawaan 0,05)
 *   php tools/impor.php koreksi-blok  pindahkan bidang yang bloknya masih perkiraan otomatis ke
 *                                   garis blok ukur yang benar-benar menaungi titik tengahnya
 *   php tools/impor.php dxf-persil  pasang persil MASTER.dxf yang cincinnya tertutup tapi
 *                                   bendera "closed" tidak diset sehingga tidak ikut didigitasi;
 *                                   aturannya dengan --celah=<m> (bawaan 0,01) dan
 *                                   --minimal=<m2> (bawaan 20); --tanpa-blok juga memasang
 *                                   yang bloknya tidak bisa dipastikan, sebagai bidang
 *                                   ber-blok-kosong yang harus ditetapkan petugas;
 *                                   --longgar melewati uji sudut (tetap menolak duplikat
 *                                   lewat titik tengah dan irisan luas)
 *   php tools/impor.php kuning      sejajarkan tepi bidang yang garisnya kuning (ACI 2) di
 *                                   MASTER.dxf ke garis tetangganya, dan pasang garis kuning
 *                                   yang tidak punya bidang sebagai bidang baru sesudah
 *                                   dipotong sampai pas ke tetangga; jauh tarikan dengan
 *                                   --tarik=<m> (bawaan 0,15)
 *   php tools/impor.php calon <blok> daftar bidang seblok dengan calon NOP terdekat
 *   php tools/impor.php taut <kode-bidang> <nop>
 *                                    tautkan satu objek pajak ke satu bidang
 *   php tools/impor.php semua --reset  kosongkan tabel terkait dulu
 *
 * Tambah --reset pada perintah blok/bidang/sppt untuk membuang data lama.
 * Perintah gang, gabung, batas, kira, pindah, ubah-blok, rapikan, duplikat, kembar-tepat,
 * luas, koreksi-blok, kuning,
 * taut-blok, nama, nis, kode-nis, nama-verifikasi, nib, daftar, hak-milik, hak-anotasi dan taut hanya memeriksa kecuali ditambah --terapkan.
 * Perintah `nis`, `nib`, `daftar`, `hak-milik` dan `hak-anotasi` hanya menyentuh bidang
 * `perlu_verifikasi` kecuali ditambah `--semua`.
 * Tambah --tegas pada perintah batas untuk memasang garis luar utuh saja,
 * tanpa menyisipkan bidang anggota yang berada di luarnya.
 * Tambah --tepat pada perintah kira agar batasnya persis mengikuti gabungan
 * bidang anggotanya tanpa penyangga; tanpa argumen, kira berlaku ke semua blok.
 * Tambah --master=<berkas> pada perintah batas dan gg untuk memakai peta master
 * lain. Berkas .dwg dikonversi otomatis dengan LibreDWG portabel di
 * tools/vendor/libredwg ke files/master peta/<nama>-dari-dwg.dxf.
 */

require_once __DIR__ . '/lib-impor.php';

$argv = $_SERVER['argv'] ?? [];
$perintah = strtolower((string) ($argv[1] ?? 'semua'));
$reset = in_array('--reset', $argv, true);
$terapkan = in_array('--terapkan', $argv, true);
$tegas = in_array('--tegas', $argv, true);
$tepat = in_array('--tepat', $argv, true);
$semua = in_array('--semua', $argv, true);
$pasangTanpaBlok = in_array('--tanpa-blok', $argv, true);
$longgar = in_array('--longgar', $argv, true);
$master = null;
$celah = DXF_PERSIL_CELAH_M;
$minimal = DXF_PERSIL_MIN_M2;
$toleransi = LUAS_TOLERANSI;
$tarik = KUNING_TARIK_M;
foreach ($argv as $argumen2) {
    if (str_starts_with((string) $argumen2, '--master=')) {
        $master = (string) substr($argumen2, 9);
    }
    if (str_starts_with((string) $argumen2, '--tarik=')) {
        $tarik = (float) substr($argumen2, 8);
    }
    if (str_starts_with((string) $argumen2, '--celah=')) {
        $celah = (float) substr($argumen2, 8);
    }
    if (str_starts_with((string) $argumen2, '--toleransi=')) {
        $toleransi = (float) substr($argumen2, 12);
    }
    if (str_starts_with((string) $argumen2, '--minimal=')) {
        $minimal = (float) substr($argumen2, 10);
    }
}
$argumen = array_values(array_filter(
    array_slice($argv, 2),
    static fn(string $s): bool => !str_starts_with($s, '--')
));

set_time_limit(0);
ini_set('memory_limit', '1024M');

$root = dirname(__DIR__);

function tampilkan(array $hasil): void
{
    $nama = $hasil['jenis'] ?? 'impor';
    $baris = [];
    foreach ($hasil as $kunci => $nilai) {
        if ($kunci === 'jenis' || $kunci === 'galat') {
            continue;
        }
        $baris[] = $kunci . '=' . (is_array($nilai) ? count($nilai) : $nilai);
    }
    echo str_pad($nama, 18) . ': ' . implode('  ', $baris) . PHP_EOL;
    foreach ((array) ($hasil['contoh'] ?? []) as $contoh) {
        echo '   + ' . $contoh . PHP_EOL;
    }
    foreach ((array) ($hasil['contoh_bersih'] ?? []) as $bersih) {
        echo '   - ' . $bersih . PHP_EOL;
    }
    foreach ((array) ($hasil['galat'] ?? []) as $galat) {
        echo '   ! ' . $galat . PHP_EOL;
    }
}

/** Cetak daftar bidang seblok beserta calon NOP paling mungkin menurut namanya. */
function tampilkanCalon(array $hasil): void
{
    $blok = $hasil['blok'];
    printf(
        "%s — %d bidang belum tertaut, %d objek pajak belum tertaut, %d sudah tertaut%s%s\n",
        $blok['kode'],
        $hasil['jumlah_bidang'],
        $hasil['jumlah_nop'],
        $hasil['jumlah_tertaut'],
        $hasil['tanpa_nama'] > 0 ? ', ' . $hasil['tanpa_nama'] . ' bidang belum ada nama pemegang' : '',
        $hasil['pasti'] > 0 ? ', ' . $hasil['pasti'] . ' bidang punya calon tunggal' : ''
    );
    echo str_repeat('-', 100) . PHP_EOL;
    printf("%-11s %-26s %s\n", 'Bidang', 'Nama pemegang peta', 'Calon NOP seblok (skor nama 3=persis, 2=termuat, 1=satu unsur)');
    foreach ($hasil['pasangan'] as $p) {
        $b = $p['bidang'];
        printf("%-11s %-26s %s\n", $b['kode'], mb_substr((string) ($b['pemegang_nama'] ?? ''), 0, 26) ?: '(tanpa nama)',
            $p['calon'] === [] ? 'tidak ada calon' : implode('   ', array_map(
                static fn(array $c): string => $c['nop'] . ' [' . $c['skor'] . '] '
                    . mb_substr((string) $c['nama_wp'], 0, 20),
                $p['calon']
            )));
    }
    echo "\nAngka skor hanya urutan saran, bukan kepastian: satu nama bisa dipakai beberapa orang, dan"
       . "\nlabel nama di peta bisa keliru menempel ke bidang tetangga. Coblos/digitkan yang cocok menurut"
       . "\nbuku SPPT atau letter C, lalu jalankan:\n"
       . "  php tools/impor.php taut <kode-bidang> <nop> --terapkan\n";
}

try {
    switch ($perintah) {
        case 'blok':
            tampilkan(imporBlok($root . '/blok_peta.geojson', $reset));
            break;

        case 'gabung':
            $asal = $argumen[0] ?? null;
            $tujuan = $argumen[1] ?? null;
            if ($asal === null || $tujuan === null) {
                throw new RuntimeException(
                    'Sebutkan blok sumber dan tujuan. Contoh: php tools/impor.php gabung 11 13 --terapkan'
                );
            }
            $hasil = gabungBlok((string) $asal, (string) $tujuan, $terapkan);
            $daftar = (array) ($hasil['bidang'] ?? []);
            unset($hasil['bidang']);
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan($hasil);
            foreach (array_slice($daftar, 0, 20) as $kode) {
                echo '   - ' . $kode . PHP_EOL;
            }
            if (count($daftar) > 20) {
                echo '   ... dan ' . (count($daftar) - 20) . " bidang lainnya\n";
            }
            break;

        case 'bidang':
            tampilkan(imporBidang($root . '/bidang_tanah.geojson', 'tanah', $reset));
            tampilkan(imporBidang($root . '/bidang_lain.geojson', 'lain', false));
            break;

        case 'batas':
            if ($argumen === []) {
                foreach (garisLuarBlok($master) as $kode => $l) {
                    printf(
                        "%-9s garis luar %9s m2, %4d bidang ada di dalamnya\n",
                        $kode,
                        number_format($l['luas_m2'], 0, ',', '.'),
                        $l['bidang']
                    );
                }
                echo "\nPasang dengan: php tools/impor.php batas 3 17 23 13 --terapkan\n";
                echo "Tanpa bidang sisipan (satu garis utuh): tambah --tegas\n";
                break;
            }
            if ($tegas) {
                echo 'GARIS UTUH: hanya garis luar '
                    . basename($master ?? $root . '/files/master peta/MASTER.dxf')
                    . " yang dipasang, bidang anggota di luarnya tidak disisipkan.\n";
            }
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(pasangGarisLuarBlok($argumen, $terapkan, !$tegas, $master));
            break;

        case 'kira':
            $daftar = $argumen;
            if ($daftar === []) {
                $daftar = array_column(
                    db_all('SELECT kode FROM blok ORDER BY nomor + 0, kode'),
                    'kode'
                );
                echo "Tanpa argumen: semua " . count($daftar) . " blok ikut dirapikan.\n";
            }
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(perkirakanBatasBlok($daftar, $terapkan, $tepat ? 0.0 : BATAS_PENYANGGA_M));
            break;

        case 'pindah':
            $asal = $argumen[0] ?? null;
            $tujuan = $argumen[1] ?? null;
            if ($asal === null || $tujuan === null) {
                throw new RuntimeException(
                    'Sebutkan blok asal dan blok tujuan. Contoh: php tools/impor.php pindah 3 9 --terapkan'
                );
            }
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            $hasil = pindahkanBidangLuarBatas((string) $asal, (string) $tujuan, $terapkan);
            $daftar = (array) ($hasil['bidang'] ?? []);
            unset($hasil['bidang']);
            tampilkan($hasil);
            foreach (array_slice($daftar, 0, 20) as $kode) {
                echo '   - ' . $kode . PHP_EOL;
            }
            if (count($daftar) > 20) {
                echo '   ... dan ' . (count($daftar) - 20) . " bidang lainnya\n";
            }
            break;

        case 'ubah-blok':
            if (count($argumen) < 2) {
                throw new RuntimeException(
                    'Sebutkan blok tujuan lalu kode bidangnya. Contoh: php tools/impor.php ubah-blok 7 DXF-020100-1028 HM-0430 --terapkan'
                );
            }
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(ubahBlokBidang(array_slice($argumen, 1), (string) $argumen[0], $terapkan));
            break;

        case 'rapikan':
            if ($argumen === []) {
                throw new RuntimeException(
                    'Sebutkan blok yang dirapikan. Contoh: php tools/impor.php rapikan 21 22 23 --terapkan'
                );
            }
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(rapikanBatasJalan($argumen, $terapkan));
            break;

        case 'luas':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(hitungUlangLuasBidang($terapkan, $toleransi));
            echo "\nLuas_m2 impor GeoJSON adalah luas gambar, bukan angka pendaftaran, jadi yang melenceng\n"
               . "lebih dari " . $toleransi . " bagian dari cincin di geom dianggap cacat digitasi.\n";
            break;

        case 'koreksi-blok':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(koreksiBlokPerkiraan($terapkan));
            echo "\nBidang yang diperiksa adalah yang bloknya dipasang oleh alat, terbukti dari tiga corak\n"
               . "catatan mesin: `Blok diperkirakan otomatis%` (kira), `Cincin MASTER.dxf lapis %` (dxf-persil)\n"
               . "dan `Garis kuning (ACI 2)%` (kuning). Catatan kosong TIDAK ikut — `pindah` dan `ubah-blok`\n"
               . "tidak pernah menulis catatan, jadi baris tanpa catatan tidak bisa dibuktikan bukan keputusan\n"
               . "petugas. Pindah hanya bila titik tengahnya sudah keluar dari garis bloknya sendiri dan jatuh\n"
               . "di dalam tepat satu garis blok lain, dan garis tujuan itu bergaris ukur MASTER.dxf yang\n"
               . "menaungi >= 90% anggotanya sendiri. Keda-laman itu lalu diuji ulang dengan ST_Contains\n"
               . "milik MariaDB — `wktBagian()` membaca cincin sebagai bagian padat dan lebih murah hati, dan\n"
               . "ronde 20 Okt 10:27 memindah satu bidang ke garis yang berjarak 482 m. Blok turunan\n"
               . "(`gabungan bidang anggota`) dan blok yang garisnya sendiri sudah tidak cocok dengan\n"
               . "anggotanya tidak dipakai; yang ditahan dilaporkan, tidak ditebak.\n";
            break;

        case 'duplikat':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            $hasil = tandaiBidangDuplikat($terapkan, $argumen);
            tampilkan($hasil);
            echo "\nStatus duplikat belum disaring di mana pun, jadi angka luas dan peta masih\n"
               . "memuat bidang yang ditandai. Membuangnya dari perhitungan adalah keputusan terpisah.\n";
            break;

        case 'taut-blok':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(tautanNopKeBlok($terapkan));
            break;

        case 'nama':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(namaPemegangDariMaster($terapkan, $master === null ? null : (string) $master));
            break;

        case 'nis':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(nisDariMaster($terapkan, $semua, $master === null ? null : (string) $master));
            break;

        case 'nib':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(nibDariMaster($terapkan, $semua, $master === null ? null : (string) $master));
            echo "\nLapisan `NIB HM` hanya menulis nomor persil HM, jadi cakupannya kecil.\n"
               . "Angka cocok/berbeda di atas adalah uji silangnya terhadap nib yang sudah ada.\n";
            break;

        case 'daftar':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(nomorDaftarDariMaster($terapkan, $semua, $master === null ? null : (string) $master));
            echo "\nNomor lapisan `080201` disimpan di bidang.nomor_daftar, bukan di nib — ia register\n"
               . "lain dan pada bidang yang punya keduanya biasanya berbeda dari nib.\n";
            break;

        case 'nama-verifikasi':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(namaVerifikasiDariMaster($terapkan, $master === null ? null : (string) $master));
            echo "\nPerintah `nama` biasa hanya menyentuh tipe tanah; yang ini justru untuk bidang\n"
               . "lapisan 020100 (tipe lain) yang selama ini tidak pernah dibaca labelnya.\n";
            break;

        case 'kode-nis':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(kodeDariNis($terapkan));
            echo "\nKode lama disimpan di cadangan JSON, jadi penukaran bisa dibaca balik.\n"
               . "Perintah ini tidak bertahan di atas `impor.php bidang --reset`: kode sementara\n"
               . "DXF-... datang lagi dari GeoJSON.\n";
            break;

        case 'hak-milik':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(hakMilikVerifikasi($terapkan, $semua));
            echo "\nHanya jenis hak sementara (BLM/BLH) yang pindah; nomor sertifikat, nomor hak pakai dan\n"
               . "tautan Buku C pada baris itu dibiarkan. Jenis yang sudah sungguh-sungguh tidak disentuh.\n"
               . "Bidang yang poligonya beranotasi petugas (GG, TANAH DESA, BENGKOK, masjid, jalan)\n"
               . "dikecualikan — betulkan dengan `impor.php hak-anotasi`. Label GG pada persil yang punya\n"
               . "nomornya sendiri tidak dikecualikan: gang digambar sebagai titik, jadi labelnya yang\n"
               . "bergeser, tanahnya tetap milik orang.\n";
            break;

        case 'hak-anotasi':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            $hasil = hakDariAnotasi($terapkan, $semua, $master === null ? null : (string) $master);
            tampilkan(['jenis' => 'hak-anotasi', 'terapkan' => $terapkan, 'sasaran' => $hasil['sasaran'],
                'bidang_beranotasi' => $hasil['bidang_beranotasi'], 'akan_diubah' => $hasil['akan_diubah'],
                'akan_dipulihkan' => $hasil['akan_dipulihkan'],
                'sudah_sesuai' => count($hasil['sudah_sesuai']),
                'ditahan_nama' => $hasil['ditahan_nama'], 'ditahan_bukti' => $hasil['ditahan_bukti'],
                'jenis_sudah_sungguhan' => $hasil['jenis_sudah_sungguhan'],
                'di_luar_sasaran' => $hasil['di_luar_sasaran'],
                'ditahan_gg_bergeser' => $hasil['ditahan_gg_bergeser'],
                'fasilitas_dilaporkan' => $hasil['fasilitas_dilaporkan'],
                'luas HP' => $hasil['luas_m2']['HP'] ?? '-',
                'luas KDH' => $hasil['luas_m2']['KDH'] ?? '-',
                'cadangan' => $hasil['cadangan'] ?? '-', 'galat' => $hasil['galat']]);
            $judul = ['ubah' => 'Akan dibetulkan', 'pulihkan' => 'Dipulihkan ke Hak Milik',
                'sudah_sesuai' => 'Sudah sesuai anotasi'];
            foreach (['ubah', 'pulihkan', 'nama', 'bukti', 'jenis_sudah_sungguhan', 'di_luar_sasaran',
                'gg_bergeser', 'fasilitas', 'sudah_sesuai'] as $kunci) {
                $daftar = $hasil['rincian'][$kunci] ?? [];
                if ($daftar === []) {
                    continue;
                }
                echo "\n== " . ($judul[$kunci] ?? 'Ditahan: ' . $kunci) . ' (' . count($daftar) . ") ==\n";
                printf("%-18s %-10s %-17s %10s     %-30s %s\n", 'Kode', 'Blok', 'Status', 'Luas', 'Anotasi', 'Sebab');
                foreach ($daftar as $baris) {
                    echo '  ' . $baris . PHP_EOL;
                }
            }
            echo "\nGG gang -> Hak Pakai; TANAH DESA / BENGKOK / BALAI -> Milik Pemerintah Desa; penggunaan\n"
               . "tanahnya diisi Fasilitas Umum. Masjid, mushola dan jalan hanya dilaporkan: jenisnya\n"
               . "keputusan petugas, bukan alat.\n"
               . "Label GG di atas persil yang punya nib/nis/nomor daftar sendiri tidak memutuskan apa-apa —\n"
               . "peta master menggambar gang sebagai titik, jadi 13 dari 15 labelnya jatuh di ruang terbuka.\n"
               . "Bidang yang dulu dipindahkan aturan lama dan sekarang tidak lagi diputuskan anotasi,\n"
               . "kembali ke Hak Milik beserta penggunaan Fasilitas Umum yang dilepas.\n";
            break;

        case 'dxf-persil':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            $hasil = persilDariDxf($terapkan, $celah, $minimal, $master === null ? null : (string) $master,
                $pasangTanpaBlok, $longgar);
            tampilkan(['jenis' => 'dxf-persil', 'terapkan' => $terapkan,
                'cincin_dibaca' => $hasil['cincin_dibaca'], 'sudah_tercakup' => $hasil['sudah_tercakup'],
                'dibuang_bertumpuk' => $hasil['dibuang_bertumpuk'],
                'kembar_diantara_calon' => $hasil['kembar_diantara_calon'],
                'calon' => $hasil['calon'], 'calon_tanpa_blok' => $hasil['calon_tanpa_blok'],
                'dipasang_tanpa_blok' => $hasil['dipasang_tanpa_blok'],
                'luas_m2' => $hasil['luas_m2'], 'celah_m' => $hasil['celah_m'],
                'minimum_m2' => $hasil['minimum_m2'], 'cadangan' => $hasil['cadangan'] ?? '-']);
            $rinci = static function (array $daftar, string $judul, int $terbatas = 0): void {
                if ($daftar === []) {
                    return;
                }
                $seluruh = count($daftar);
                echo "\n== " . $judul . ' (' . $seluruh . ") ==\n";
                foreach ($terbatas > 0 ? array_slice($daftar, 0, $terbatas) : $daftar as $c) {
                    printf("  %-20s %-10s %-9s %10s m2  pusat %s %s\n", $c['kode'] ?? '-', $c['lapisan'],
                        $c['blok'] ?? 'DI LUAR BLOK', number_format((float) $c['luas_m2'], 1, ',', '.'),
                        number_format((float) $c['pusat'][0], 6, ',', '.'),
                        number_format((float) $c['pusat'][1], 6, ',', '.'));
                    if (($c['alasan'] ?? '') !== '') {
                        echo '       alasannya: ' . $c['alasan'] . "\n";
                    }
                    if (($c['blok_alasan'] ?? '') !== '' && !str_starts_with((string) $c['blok_alasan'], 'titik tengah')) {
                        echo '       bloknya: ' . $c['blok_alasan'] . "\n";
                    }
                }
                if ($terbatas > 0 && $seluruh > $terbatas) {
                    echo '     ... ' . ($seluruh - $terbatas) . " lagi tidak ditampilkan\n";
                }
            };
            $rinci($hasil['contoh'] ?? [], 'Akan dipasang', 25);
            $rinci($hasil['tanpa_blok'] ?? [],
                'Ditahan: titik tengahnya tidak jatuh di dalam blok mana pun', 25);
            echo "\nIni penutup kekurangan digitasi, bukan tambahan geometri karangan: yang diambil hanya\n"
               . ($longgar
                    ? "cincin yang titik tengahnya tidak bersentuhan dengan bidang mana pun (uji sudut dilewati\n"
                      . "dengan --longgar karena cincin yang baru ditutup dari celah sampai " . $celah . " m\n"
                      . "sering hanya menyentuh sudut tetangga), dan irisan sebenarnya (ST_Intersection)"
                    : "cincin yang sudut dan titik tengahnya tidak bersentuhan dengan bidang mana pun, dan\n"
                      . "irisan sebenarnya (ST_Intersection)")
               . " terhadap bidang lama di bawah " . DXF_PERSIL_TUMPANG . " bagian luasnya.\n"
               . ($pasangTanpaBlok
                    ? "Opsi --tanpa-blok dipakai: yang bloknya tidak bisa dipastikan ikut masuk sebagai bidang\n"
                      . "ber-blok-kosong, dengan garis blok terdekat di catatannya. Blok tidak dikarang — petugas\n"
                      . "yang menetapkannya lewat `impor.php ubah-blok <nomor blok> <kode bidang> --terapkan`.\n"
                    : "Blok diambil dari titik tengah yang jatuh di dalam garis luar blok; yang tidak bisa dipastikan\n"
                      . "ditahan. Tambah --tanpa-blok untuk memasang cincinnya tetap, sebagai bidang ber-blok-kosong.\n")
               . "Sesudah --terapkan, jalankan lagi `nama-verifikasi`, `nib`, `daftar`, `nis` dan `hak-milik`\n"
               . "karena bidang baru ini masuk tanpa nama, tanpa nomor, dan berjenis hak sementara BLM.\n";
            break;

        case 'kuning':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(kuningMasterSelaraskan($terapkan, $tarik, $master === null ? null : (string) $master));
            echo "\nYang dibaca hanya poligon bewarna kuning (ACI 2) pada seksi ENTITIES MASTER.dxf; garis\n"
               . "kuning di dalam seksi BLOCKS berkoordinat lokal sehingga bukan persil lapangan. Padanannya\n"
               . "dipilih dari berapa bagian garis kuning yang terjelaskan oleh sebuah bidang, bukan\n"
               . "sebaliknya. Penjajaran cuma memindahkan sudut yang sudah jatuh <= --tarik (bawaan "
               . number_format(KUNING_TARIK_M, 2, ',', '.') . " m) ke garis tetangga — celah digitasi ditutup,\n"
               . "bentuk tidak dikarang. Luas_m2 yang tercatat tidak disentuh karena itu angka pendaftaran.\n"
               . "Garis kuning yang tidak punya bidang dipasang sebagai bidang `perlu_verifikasi` berjenis\n"
               . "sementara BLM sesudah dipotong sampai pas ke tetangga; lanjutkan dengan `nama-verifikasi`,\n"
               . "`nib`, `daftar` dan `hak-milik` seperti sesudah `dxf-persil`.\n"
               . "Perintah ini RONDE SATU. Sesudah --terapkan jangan dijalankan lagi: dua bidang yang\n"
               . "berdempetan menarik sudut yang sama ke garis masing-masing, jadi ronde kedua mengulang\n"
               . "ronde pertama ke arah sebaliknya (terukur 20 Okt: ronde 2 memindah 8 bidang 2,98 m2,\n"
               . "ronde 3 membatalkan 5 di antaranya). Celah tersisa <= 0,063 m pada tepi yang berdua\n"
               . "dibiarkan; kalau itu salah, yang dibetulkan garis bloknya, bukan penjajarannya.\n";
            break;

        case 'kembar-tepat':
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(kembarTepatDuplikat($terapkan));
            echo "\nIni lanjutan `duplikat`: yang dicari bidang yang tempatnya sama persis tapi urutan titiknya\n"
               . "beda sehingga ST_Equals menolaknya. Kembaran aktif + aktif tidak pernah ditandai otomatis.\n";
            break;

        case 'calon':
            $blok = $argumen[0] ?? null;
            if ($blok === null) {
                throw new RuntimeException('Sebutkan bloknnya, misalnya: calon 7.');
            }
            tampilkanCalon(calonTautanBlok((string) $blok));
            break;

        case 'taut':
            $kode = $argumen[0] ?? null;
            $nop = $argumen[1] ?? null;
            if ($kode === null || $nop === null) {
                throw new RuntimeException('Pakai: taut <kode-bidang> <nop> [--terapkan].');
            }
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang diubah. Tambah --terapkan untuk menjalankan.\n";
            }
            $hasil = tautkanNopKeBidang((string) $kode, (string) $nop, $terapkan);
            tampilkan($hasil);
            foreach ((array) ($hasil['peringatan'] ?? []) as $peringatan) {
                echo '   ! ' . $peringatan . PHP_EOL;
            }
            break;

        case 'sppt':
            $berkas = $argumen[0] ?? null;
            if ($berkas === null) {
                $calon = glob($root . '/files/data excel/*.csv') ?: [];
                $berkas = $calon[0] ?? null;
                if ($berkas === null) {
                    throw new RuntimeException(
                        'Tidak ada CSV di files/data excel. Sebutkan path berkasnya sebagai argumen kedua.'
                    );
                }
            }
            tampilkan(imporSpptCsv($berkas, null, $reset));
            break;

        case 'sppt-dump':
            $berkas = $argumen[0] ?? $root . '/_lama/sigtanahdesa.sql';
            tampilkan(imporSpptDump($berkas, null, $reset));
            break;

        case 'buku-c':
            tampilkan(indeksBukuC());
            break;

        case 'gg':
            $hasil = petaGgEkspor($master);
            $label = $hasil['label'];
            printf("Penanda gang ditemukan: %d\n", count($label));
            printf("%-8s %-10s %-13s %-9s %11s %s\n", 'KODE', 'LABEL', 'BLOK', 'NOMOR', 'JARAK M', 'KETERANGAN');
            foreach ($label as $g) {
                printf(
                    "%-8s %-10s %-13s %-9s %11s %s\n",
                    $g['kode'],
                    $g['teks'],
                    $g['blok'] ?? '-',
                    $g['nomor_blok'] ?? '-',
                    $g['jarak_blok_m'] === null ? '-' : number_format($g['jarak_blok_m'], 1, ',', '.'),
                    $g['perlu_dicek'] ? 'di luar batas blok, perlu dicek' : ''
                );
            }
            printf("\nGeoJSON : %s\nCSV     : %s\n", $hasil['geojson'], $hasil['csv']);
            echo "\nBerkas itu berisi titik penanda, bukan poligon. Digitasi bentuk gang dari titik ini,"
               . " simpan sebagai GeoJSON poligon dengan kolom blok dan id_bidang, lalu jalankan:\n"
               . "   php tools/impor.php gang <berkas.geojson> --terapkan\n";
            break;

        case 'gang':
            $berkas = $argumen[0] ?? null;
            if ($berkas === null) {
                throw new RuntimeException(
                    'Sebutkan GeoJSON poligon gang. Contoh: php tools/impor.php gang "files/peta gang/gang.geojson" --terapkan'
                );
            }
            if (!$terapkan) {
                echo "MODE PERIKSA: tidak ada data yang ditulis. Tambah --terapkan untuk menjalankan.\n";
            }
            tampilkan(imporGangBerkas($berkas, $terapkan));
            break;


        case 'semua':
            // Rantai ini menyusun ulang seluruh sumber lama dan tidak bisa diuji per langkah,
            // jadi ia baru jalan kalau diminta terang-terangan. Tanpa syarat itu, satu ketikan
            // `php tools/impor.php` tanpa argumen menyisipkan ulang 1.582 bidang GeoJSON lama
            // di atas data yang bersumber MASTER.dxf (20 Okt 2026, dibatalkan pada hari yang sama).
            if (!$terapkan) {
                echo "MODE PERIKSA: `semua` menulis ke basis data dan mengimpor ulang GeoJSON lama.\n"
                   . "Sumber geometri sejati kini MASTER.dxf; untuk satu langkah tertentu pakai perintah\n"
                   . "yang bernama, misalnya `dxf-persil`, `batas`, `nama` atau `hak-milik`.\n"
                   . "Tambah --terapkan bila memang ingin menjalankan rantai lengkapnya.\n";
                break;
            }
            tampilkan(imporBlok($root . '/blok_peta.geojson', $reset));
            tampilkan(imporBidang($root . '/bidang_tanah.geojson', 'tanah', $reset));
            tampilkan(imporBidang($root . '/bidang_lain.geojson', 'lain', false));
            tampilkan(namaPemegangDariMaster(true, $master));
            $dump = $root . '/_lama/sigtanahdesa.sql';
            if (is_file($dump)) {
                tampilkan(imporSpptDump($dump, null, $reset));
                tampilkan(tautanNopKeBlok(true));
            } else {
                echo "   (lewati SPPT: sigtanahdesa.sql tidak ditemukan)\n";
            }
            tampilkan(indeksBukuC());
            break;

        default:
            echo "Perintah tidak dikenal: {$perintah}\n"
               . "Pakai: semua | blok | bidang | sppt | sppt-dump | buku-c | gg | gang | gabung | batas "
               . "| kira | pindah | ubah-blok | rapikan | duplikat | kembar-tepat | taut-blok | nama | nis "
               . "| kode-nis | nama-verifikasi | nib | daftar | hak-milik | hak-anotasi | calon | taut\n";
            exit(1);
    }

    echo PHP_EOL . 'Isi basis data sekarang:' . PHP_EOL;
    foreach (hitunganTabel() as $tabel => $jumlah) {
        echo '   ' . str_pad($tabel, 16) . ': ' . number_format($jumlah) . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'GAGAL: ' . $error->getMessage() . PHP_EOL);
    if (sip_debug()) {
        fwrite(STDERR, $error->getTraceAsString() . PHP_EOL);
    }
    exit(1);
}
