<?php
declare(strict_types=1);

/**
 * Garis turunan Jalan dari label jalan, dua tahap dan dua berkas.
 *
 *   php -d memory_limit=1024M tools/garis-jalan-label.php            # uji kering
 *   php -d memory_limit=1024M tools/garis-jalan-label.php --terapkan
 *
 *   --tahap=tepi|sumbu|semua   bawaan semua
 *   --tepi=terpakai|semua      isi garis-jalan-tepi.geojson; bawaan 'terpakai'
 *                              = hanya baris yang kuncinya muncul di `tepi_all`
 *                              sebuah sumbu, sehingga tepian bidang tanah yang
 *                              cuma ikut terklaim tidak lagi terbit sebagai tepi
 *                              jalan. 'semua' = seluruh baris terklaim (keadaan
 *                              sebelum 15 Nov). Wajib 'semua' bersama --tahap=tepi,
 *                              sebab tanpa sumbu tidak ada yang menentukan
 *                              baris mana yang terpakai.
 *   --sumber-tepi=turun|terbit bawaan turun; 'terbit' memakai lapis Jalan yang
 *                              sudah terbit (garis-jalan.geojson) sebagai bahan
 *                              tahap 2 sehingga tahap 1 tidak dijalankan
 *   --lebar=M                  lebar koridor maksimum tahap 2, bawaan 8,0 m
 *   --benih=M                  jarak terjauh label ke titik sampel sebuah baris
 *                              agar baris itu jadi benih tahap 1; bawaan 3,0 m
 *                              (= konstanta BENIH; si pembangkit memakai 2,0 m)
 *   --benih-yatim=M            jarak benih kedua, hanya untuk label yang sama
 *                              sekali tidak mendapat benih dalam --benih; bawaan
 *                              15,0 m (= BENIH_YATIM = PITA), 0 = mati dan
 *                              keluaran kembali ke keadaan §3.83.
 *   --lebar-yatim=M            lebar koridor ronde kedua tahap 2, ditanyakan per
 *                              titik sampel yang di ronde pertama tidak mendapat
 *                              pasangan dalam --lebar; bawaan 10,0 m
 *                              (= LEBAR_YATIM), 0 = mati. M harus > --lebar dan
 *                              <= 15,0 m (= SEBERANG_UJI, uji sisi terdekat).
 *   --bagi-pasang=F            bagian titik sampel sebuah baris yang harus
 *                              berpasangan; bawaan 0 = terima tiap titik tengah
 *                              yang lolos semua syarat, 0,50 = hanya baris yang
 *                              separuh panjangnya berpasangan
 *   --jahit=M                  panjang jahitan maksimum antara dua ujung buntu
 *                              sumbu; bawaan 3,0 m (= konstanta JAHIT),
 *                              0 = matikan penyambungan celah
 *   --cincin=hapus|simpan      bawaan 'hapus': (a) baris tepi tertutup yang
 *                              menutup >= 90 % keliling satu persil dibuang, (b)
 *                              baris yang tergabung dalam komponen tertutup
 *                              (tiap simpul berderajat 2, ujung dibulatkan 1 cm)
 *                              juga dibuang, dan (c) sesudah `--tepi=terpakai`
 *                              komponen sisa yang setiap simpulnya berderajat 2
 *                              (siklus yang menempel di <= 1 simpul) dibuang
 *                              berikut rujukannya di `tepi_all`; semuanya
 *                              keliling bidang tanah, bukan tepi jalan. 'simpan' =
 *                              keadaan §3.91 (tanpa saringan cincin)
 *   --pembatas-persil=hapus|simpan  bawaan 'hapus': baris tepi yang kedua sisinya
 *                              (offset tegak lurus 0,8 m) jatuh di dalam persil
 *                              yang BERBEDA dibuang, karena garis semacam itu
 *                              pembatas dua bidang tanah, bukan tepi jalan.
 *                              'simpan' = keadaan §3.87/§3.89 (tanpa saringan)
 *   --eksklusif                label jalan harus yang terdekat; bila label kelas
 *                              lain lebih dekat, tepinya tidak diklaim
 *   --rinci                    cetak 40 sumbu terpanjang
 *   --tanya=x,y[,radius]       diagnostik saja: untuk tiap baris tepi dalam radius dari
 *                              titik itu cetak sisi tempat ia bergabung, pemenang
 *                              pasangan pada tiga radius, serta apakah ronde pertama
 *                              memberinya pasangan. Koordinat pakai titik desimal.
 *   --tanya-berkas=BERKAS      diagnostik yang sama untuk banyak titik sekaligus,
 *                              satu titik per baris: x,y[,radius][,nama], dengan
 *                              radius bawaan 20 m. Cetakannya lebih ringkas: satu
 *                              baris per baris tepi, tanpa rincian tiga radius.
 *                              Baris berawalan # diabaikan.
 *   --jejak-pasang=BERKAS      diagnostik saja: tulis CSV setiap baris tepi yang
 *                              akan terbit berisi kunci, bagian titik sampel yang
 *                              berpasangan (0..1), panjang_m, asal, cara. Bagian
 *                              itu dihitung dari ronde pertama dan ronde
 *                              --lebar-yatim, bukan dari pasangan yang menang.
 *
 * Angka flag (`--lebar`, `--bagi-pasang`, `--benih`, `--jahit`) menerima koma
 * atau titik desimal.
 *
 * Latar. `tools/garis-batas-020100.php` menurunkan empat kelas sekaligus dan
 * mengklaim SATU entitas 020100 per baris, sehingga lapis Jalan berisi tepian
 * yang terpecah dan tidak pernah menyisakan satu garis per jalan; hasilnya
 * kemudian dirapikan `tools/rapikan-garis-jalan.php` menjadi 1.981 baris tepi.
 * Alat ini khusus Jalan dan berhenti pada dua keluaran: TEPI jalan (sisi kiri
 * dan kanan) dan SUMBU jalan, satu garis di tengah koridor untuk tiap segmen
 * antara dua ujung atau persimpangan.
 *
 * Sumber geometri, sesuai permintaan "bidang & garis":
 *  - berkas `020100.geojson`: 13.681 garis terbuka + 2.626 cincin poligon;
 *  - tabel `bidang` melalui app/config.php, dibaca saja: 4.176 persil
 *    (status <> 'dihapus'). Cincin bidang ikut jadi KANDIDAT tepi, bukan hanya
 *    permukaan; yang >= BAGI_ULANG titiknya sudah tergambar sebagai garis lain
 *    dibuang lebih dulu supaya tidak ada tepi kembar.
 *  - `garis-selokan.geojson` (783) dan `garis-perairan.geojson` (542) hasil
 *    tools/garis-batas-020100.php, dibaca saja dan HANYA jadi pemolong tahap 2:
 *    keduanya tidak pernah jadi kandidat tepi. Alat ini karenanya bergantung
 *    pada dua lapis terbit itu; kalau keduanya dibangkitkan ulang, jalankan
 *    alat ini sesudahnya.
 * Permukaan yang diuji saat koridor adalah gabungan cincin 020100 dan poligon
 * tabel bidang — kisi bidang tanah di peta ini sebagian besar tersimpan sebagai
 * garis terbuka, jadi tanpa permukaan itu koridor di dalam sebidang tanah tampak
 * kosong dan pita merambat antar-bidang (README §3.73).
 *
 * Label: `batas-jalan.geojson` dan `batas-perairan.geojson` dibaca bersama dan
 * kelas diambil dari TEKS (polanya sama dengan si pembangkit), karena nama
 * saluran tersimpan di berkas BATAS PERAIRAN dan dua nama jalan nyasar ke berkas
 * yang sama. Yang dipakai di sini hanya teks berawalan JALAN.
 *
 * Tahap 1 — tepi (garis-jalan-tepi.geojson). Aturannya disengaja identik dengan
 * kelas jalan pada `tools/garis-batas-020100.php` kecuali radius benih (lihat
 * BENIH): benih = kandidat yang punya titik sampel <= BENIH (bawaan 3,0 m) dari
 * label jalan; pita = kandidat yang >= 50 % titik sampelnya punya segmen
 * terklaim jenis sama <= 15 m dengan beda arah <= 25 derajat DAN tengah
 * koridornya tidak berada di dalam bidang (>= 60 % kosong), dua putaran;
 * garis < 2,0 m dibuang. Radius kedua (--benih-yatim) hanya ditanyakan pada
 * label yang dalam --benih sama sekali tidak mendapat benih, sehingga label
 * yang sudah terjangkau tidak ikut berubah. Baris yang terklaim di tahap 1
 * tetapi tidak muncul di `tepi_all` satu pun sumbu dibuang dari berkas terbit
 * (`--tepi=terpakai`, bawaan sejak 15 Nov): baris semacam itu tepian bidang
 * tanah yang cuma ikut terklaim lewat benih atau pita, dan menyimpannya membuat
 * lapis tepi jalan terbaca sebagai peta bidang.
 *
 * Saringan cincin (15 Nov, `--cincin=hapus` bawaan), tiga lapis. Lapis pertama
 * dan kedua berlaku SESUDAH tahap 1 dan SEBELUM saringan pembatas persil. Lapis
 * pertama per baris: baris yang titik awal dan akhirnya sama (<= 5 cm) lalu
 * >= 90 % titik sampel keliling SATU persil jatuh <= 0,35 m dari baris itu
 * adalah keliling bidang yang ditulis sebagai satu polilin. Lapis kedua
 * topologis: ujung tiap baris tepi dibulatkan 1 cm, baris yang berbagi ujung
 * dianggap bersambung, lalu tiap komponen yang setiap simpulnya berderajat 2
 * (kembali ke titik awal) dibuang. Jalan lewat, tidak berkeliling; komponen
 * tertutup adalah keliling sebuah bidang. Lapis per baris perlu lebih dulu
 * karena keliling persil yang bertemu keliling lain di satu simpul membentuk
 * komponen berderajat 4, dan komponen semacam itu tidak lagi sederhana. Ukur
 * 15 Nov pada lapis §3.91 (2.385 baris): 347 baris tertutup, 218 di antaranya
 * menutup >= 90 % keliling satu persil (26.925,0 m); pada sisa barisnya 228
 * komponen tertutup (300 baris, 30.356,3 m, 202 di antaranya menempel tepat pada
 * keliling sebuah bidang).
 *
 * Lapis KETIGA (15 Nov, `--cincin=hapus` juga) berlaku SESUDAH `--tepi=terpakai`,
 * karena pelapisan itulah yang membuat keliling bidang lepas dari jaringan
 * jalan: baris cincin yang di tahap 1 menempel pada baris lain bisa kehilangan
 * tetangganya begitu baris tak terpakai dibuang. Pada graf baris yang tinggal
 * (`siklusLepasTepi`): simpul daun berderajat < 2 dipangkas berulang, lalu tiap
 * simpul berderajat >= 3 (persimpangan) dicabut, sehingga tersisa komponen
 * terpencil; komponen yang setiap simpulnya berderajat 2 adalah siklus yang
 * menempel di <= 1 simpul atau sama sekali lepas — keliling bidang tanah, bukan
 * jalan. Baris siklus itu dibuang dari berkas terbit, rujukannya dicabut dari
 * `tepi_all`, dan sumbu yang tinggal tanpa rujukan ikut dibuang; geometri sumbu
 * lain TIDAK dihitung ulang. Komponen terpencil yang bukan siklus (ada ujung
 * buntu di dalamnya, mis. sisa digitasi) dibiarkan. Ukur 15 Nov pada lapis
 * 2.088 baris: 98 siklus lepas, 137 baris, 13.037,2 m, 290 sumbu tersentuh dan
 * hanya 3 di antaranya jadi tanpa rujukan.
 *
 * Saringan pembatas persil (15 Nov, `--pembatas-persil=hapus` bawaan) berlaku
 * SESUDAH tahap 1 dan SEBELUM tahap 2: tiap baris tepi disampel tiap 2 m, lalu
 * dua titik 0,8 m di kiri-kanan garis diuji terhadap poligon tabel bidang. Bila
 * >= 80 % sampel punya persil di kedua sisi dan persilnya berbeda, baris itu
 * garis bidang tanah yang ikut terklaim (biasanya lewat pita), bukan tepi jalan,
 * jadi dibuang dari `$tepi`. Karena tahap 2 membaca `$tepi` yang sama, sumbu
 * ikut dibangun ulang tanpa baris itu dan `tepi_all` tetap menunjuk baris yang
 * ada.
 *
 * Tahap 2 — sumbu (garis-jalan-sumbu.geojson).
 *  1. potongan tepi yang ujungnya berjarak <= RAGU_SISI dianggap simpul yang
 *     sama, dan potongan yang berbagi simpul adalah satu SISI: dua baris yang
 *     sebenarnya satu sisi jalan tidak boleh berpasangan satu sama lain;
 *  2. tiap titik sampel sisi A dicari sisi B terdekat dengan syarat: sisi B
 *     bukan sisinya sendiri, lebar koridor >= LEBAR_MIN dan <= --lebar, beda
 *     arah <= SUDUT_PASANG, dan tengah antara kedua tepi tidak berada di dalam
 *     bidang dan tidak berada di antara dua tepi air yang sejajar dengan arah
 *     pasangan itu (syarat kedua ditambahkan 14 Nov: tanpa itu tepian sebuah
 *     SALURAN yang berlabel JALAN dipasangkan satu sama lain dan sumbu yang
 *     lahir adalah sumbu selokan, bukan sumbu jalan). Pasangan utama sebuah sisi
 *     = sisi B yang paling sering menang, dan
 *     titik tengahnya dipakai bila jumlahnya >= --bagi-pasang bagian titik
 *     sampel sisi itu (bawaan 0, jadi setiap titik tengah yang lolos semua
 *     syarat ikut terpakai); aturan yang sama diulang sekali lagi dengan
 *     koridor --lebar-yatim untuk SETIAP TITIK SAMPEL yang pada ronde pertama
 *     tidak mendapat pasangan — pemenang suara sebuah baris tidak digeser, jadi
 *     sumbu yang sudah ada tetap utuh dan ronde kedua hanya menambal lubangnya;
 *  3. titik tengah tiap pasangan dikumpulkan dan direnggutkan pada ragum
 *     RAGU_SUMBU: titik tengah dari sisi kiri dan kanan sebuah jalan lurus
 *     jatuh di tempat yang sama, sehingga keduanya menyatu jadi satu rantai,
 *     dan koordinat simpul hasil adalah rata-rata seluruh titik yang masuk;
 *  4. rantai diputus pada simpul berderajat != 2, yaitu ujung buntu dan
 *     persimpangan, sehingga tiap jalur adalah satu segmen jalan;
 *  5. dua ujung buntu dari dua untaian yang berbeda, berjarak > RAGU_SUMBU dan
 *     <= --jahit (bawaan JAHIT) serta sama-sama menunjuk satu sama lain <=
 *     SUDUT_JAHIT, disambungkan dengan satu ruas garis; tengah ruas itu harus
 *     lolos kedua uji koridor aturan 2, sebuah ujung hanya sekali dipakai, dan
 *     dua untaian yang berbagi simpul tidak disambung. Ini menutup celah
 *     digitasi tanpa pernah menutup lingkaran atau memotong persimpangan;
 *  6. jalur < 2,0 m dibuang dan sisanya dinomori dari yang terpanjang.
 * Tidak ada verifikasi titik-demi-titik terhadap sumber seperti pada si perapian:
 * koordinat sumbu memang BARU, rata-rata dua tepi, dan itu seluruh isi tahap 2.
 * Yang dilaporkan sebagai gantinya: jarak tiap titik sumbu ke tepi terdekat.
 *
 * Uji kering adalah bawaan; tanpa --terapkan tidak ada berkas yang ditulis.
 * Tabel bidang hanya dibaca, tidak ada satu baris pun yang ditulis.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/lib-impor.php';

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_SUMBER = '020100';
const BERKAS_LABEL = ['batas-jalan', 'batas-perairan'];
const BERKAS_TERBIT = 'garis-jalan';
const BERKAS_TEPI = 'garis-jalan-tepi';
const BERKAS_SUMBU = 'garis-jalan-sumbu';
const JUDUL_TEPI = 'GARIS JALAN TEPI DARI LABEL';
const JUDUL_SUMBU = 'GARIS JALAN SUMBU DARI LABEL';

/** Kelas sebuah teks label; pola pertama yang cocok menang, sama dengan si pembangkit. */
const KELAS = [
    'jalan' => '/^JALAN/u',
    'selokan' => '/SALURAN|SLURAN|IRIGASI|DRAIN|GREBEG/u',
    'perairan' => '/SUNGAI|KALI|BENGKONG/u',
];

// Tahap 1.
/**
 * Radius benih bawaan. Sengaja dinaikkan dari 2,0 m (= nilai si pembangkit
 * `tools/garis-batas-020100.php`) pada 14 Nov: label jalan ditulis di tengah
 * jalan, mediannya 2,11 m dari bahan 020100 untuk label yang tak tercakup,
 * sehingga 2,0 m tidak pernah menjadikannya benih (README §3.82: +49 label,
 * intrusi air tetap 0,7 % panjang). Turunkan lewat `--benih=2` untuk mengulang
 * keluaran lama.
 */
const BENIH = 3.0;
/**
 * Radius benih kedua, HANYA untuk label jalan yang dalam BENIH sama sekali
 * tidak mendapat benih. Naik ke nilai ini 14 Nov atas permintaan "buatkan garis
 * sumbu jalan raya patean dan semua label jalan yang belum dibuat": 59 dari 120
 * label tanpa sumbu tidak punya benih sama sekali, dan label terjauh yang masih
 * punya bahan berada 14,9 m dari garis 020100 terdekat. Ukur 14 Nov: 12 dari 59
 * tidak mendapat benih sama sekali. Batas atas flag ini adalah PITA 15,0 m,
 * pita sejajar tahap 1. Ukur 14 Nov: 12 dari 59 label itu mendapat bahan pada
 * radius ini (294 baris tepi, terjauh pada jarak bulat 14 m). Turunkan lewat
 * `--benih-yatim=0` untuk kembali ke keadaan §3.83.
 */
const BENIH_YATIM = 15.0;
const PITA = 15.0;
const LANGKAH = 2.0;
const SUDUT = 25.0;
const PUTARAN = 2;
const BAGI_SEJAJAR = 0.5;
const BAGI_KOSONG = 0.6;
const TOLERANSI_TEPI = 0.35;
const MIN_PANJANG = 2.0;
const BAGI_ULANG = 0.5;
const SEL = 25.0;
const SEL_KISI = 5.0;
/**
 * Saringan pembatas persil (15 Nov 2026). Sebuah baris tepi disampel tiap
 * LANGKAH, lalu dua titik OFFSET tegak lurus di kiri dan kanan diuji ke poligon
 * tabel bidang. Baris dianggap pembatas persil bila kedua sisi berada di dalam
 * persil yang berbeda pada >= BAGI_PERSIL bagian sampel. Ukur 15 Nov pada lapis
 * terbit 2.872 baris: 197 baris (13.200,9 m, 8,9 % panjang) memenuhi syarat itu
 * dan garisnya memang duduk di batas dua persil yang berbagi sisi.
 */
const PERSIL_OFFSET = 0.8;
const PERSIL_BAGI = 0.8;

/**
 * Saringan cincin (15 Nov 2026). Ujung tiap baris tepi dibulatkan CINCIN_BULAT
 * desimal meter (1 cm) supaya dua ujung yang bertemu dianggap satu simpul;
 * cabang yang menempel dipangkas, lalu siklus sederhana yang tersisa diuji:
 * titik CINCIN_UJI meter ke arah dalam siklus harus berada di dalam persil pada
 * >= CINCIN_BAGI bagian titik uji. Siklus seperti itu keliling sebuah bidang
 * tanah, bukan jalan. Siklus yang mengurung ruang kosong — misalnya dua tepi
 * jalan yang bertemu di ujung gang — dibiarkan. Ukur 15 Nov pada lapis terbit
 * 2.685 baris: 228 komponen tertutup, 300 baris (30.356,3 m, 22,4 % panjang),
 * 202 di antaranya menempel tepat pada keliling sebuah bidang.
 */
const CINCIN_BULAT = 2;
const CINCIN_UJI = 1.0;
const CINCIN_BAGI = 0.8;
/**
 * Saringan keliling satu baris: baris tepi yang titik awal dan titik akhirnya
 * sama (jarak <= CINCIN_TUTUP) dan yang >= CINCIN_TUTUP_BAGI titik sampel
 * keliling SATU persil jatuh <= TOLERANSI_TEPI dari baris itu adalah keliling
 * bidang tanah yang ditulis sebagai satu polilin. Uji ini tidak melihat baris
 * lain, jadi tetap berlaku ketika dua keliling persil bertemu di satu simpul
 * (komponen berderajat 4) — bentuk yang lolos dari saringan cincin topologis.
 * Ukur 15 Nov pada lapis terbit 2.385 baris: 347 baris tertutup, 218 di
 * antaranya menutup >= 90 % keliling satu persil (26.925,0 m).
 */
const CINCIN_TUTUP = 0.05;
const CINCIN_TUTUP_BAGI = 0.9;

// Tahap 2.
const LEBAR_MIN = 0.25;
/**
 * Lebar koridor ronde kedua: ditanyakan per TITIK SAMPEL yang di ronde pertama
 * tidak mendapat pasangan dalam LEBAR bawaan (--lebar, 8,0 m). Naik ke nilai
 * ini 14 Nov atas permintaan yang sama dengan BENIH_YATIM. Alasannya terukur:
 * satu baris tepi bisa memanjang melewati dua koridor berbeda, dan pemenang
 * suara mayoritas baris itu menggeser ruas yang lebih lebar — di label JALAN
 * RAYA PATEAN, tepi kiri dan kanan berjarak 9,51 m sementara baris tepi kirinya
 * (B:19338, 169,9 m) menang suara di sisi lain dengan 24 dari 80 titik, sehingga
 * ruas Patean tidak pernah terpasang selama ronde kedua dihitung per baris.
 * 10,0 m adalah ambang bulat terkecil yang melampaui 9,51 m itu; 9,5 m justru
 * tidak cukup dan 12,0 m menaikkan pita sumbu "> 6 m" dari 167 ke 428.
 * Matikan dengan `--lebar-yatim=0`.
 */
const LEBAR_YATIM = 10.0;
const SUDUT_PASANG = 25.0;
/**
 * Bagian titik sampel sebuah baris yang harus berpasangan sebelum baris itu
 * dipakai. Bawaan 0: tiap titik tengah yang lolos SEMUA syarat geometri sudah
 * sah, dan ambang per-baris hanya membuat cakupan sumbu bergantung pada
 * seberapa pendek baris tepinya. Ukur 13 Nov 2026 pada bahan yang sama:
 * bawaan 2.444 dari 3.323 baris terpakai, 1.816 segmen, 30.805,2 m; dengan
 * --bagi-pasang=0,50 tinggal 1.773 baris, 1.428 segmen, 26.951,0 m.
 */
const BAGI_PASANG = 0.0;
const RAGU_SISI = 1.0;
const SEL_PASANG = 10.0;
const RAGU_SUMBU = 1.2;
/**
 * Jahit celah (14 Nov). Rantai diputus pada simpul berderajat != 2, jadi satu
 * celah digitasi di tengah ruas memecah satu jalan menjadi dua SUMBU. Dua ujung
 * buntu yang saling menunggu lebih jauh dari RAGU_SUMBU tetapi tidak lebih jauh
 * dari JAHIT, dan yang arah keduanya menunjuk satu sama lain <= SUDUT_JAHIT,
 * adalah satu ruas yang sama. Ujung yang sudah terjahit tidak dipakai lagi dan
 * dua untaian yang berbagi simpul tidak disambung, jadi jahitan tidak pernah
 * menutup lingkaran ataupun memotong persimpangan. Ruas jahitan diuji terhadap
 * permukaan bidang dan koridor air pada titik tengahnya, seperti pasangan tepi.
 */
const JAHIT = 3.0;
const SUDUT_JAHIT = 25.0;
/**
 * Plafon untuk --jahit. Celah yang lebih lebar dari ini tetap terukur sebagai
 * ujung sepi, tapi menyambungnya berarti mengarang ruas baru, bukan menutup
 * celah digitasi. 20 m adalah jangkauan diagnostik yang sama dipakai
 * _lama/scratch/ukur-sumbu-retak-air.php untuk melaporkan kelas celah.
 */
const JAHIT_MAKS = 20.0;
/**
 * Uji koridor air (14 Nov). Tepi air diambil dari dua lapis terbit hasil
 * tools/garis-batas-020100.php. Sebuah pasangan tepi ditolak bila titik tengah
 * di antara keduanya berada di antara dua tepi air yang sejajar dengan arah
 * pasangan itu: itu bukan jalan, itu selokan atau sungai yang kebetulan berlabel
 * JALAN di sisinya. SUDUT_AIR menjaga persimpangan jalan dengan saluran tetap
 * utuh, sebab di situ tepi air memotong arah pasangan, tidak sejajar.
 */
const BERKAS_AIR = ['garis-selokan', 'garis-perairan'];
const RAGU_AIR = 3.5;
const SUDUT_AIR = 25.0;
/** Jangkauan diagnostik untuk sisi yang tidak terpasang; bukan ambang hasil. */
const SEBERANG_UJI = 15.0;

function galat(string $pesan): never
{
    fwrite(STDERR, 'GAGAL: ' . $pesan . PHP_EOL);
    exit(1);
}

function angkaNum(float $n, int $desimal = 0): string
{
    return number_format($n, $desimal, ',', '.');
}

/**
 * Angka dari argumen baris perintah. Koma desimal diterima karena itu yang
 * biasa diketik di sini: `(float) "0,50"` adalah 0 dan ambangnya punah diam-diam.
 */
function angkaFlag(string $nilai, string $nama): float
{
    $baku = str_replace(',', '.', trim($nilai));
    if ($baku === '' || !is_numeric($baku)) {
        galat($nama . ' membutuhkan angka, bukan "' . $nilai . '"');
    }
    return (float) $baku;
}

/** Beda dua arah garis dalam derajat, 0..90; arah majen tidak dipersoalkan. */
function bedaArah(float $a, float $b): float
{
    $beda = abs(fmod($a - $b, 180.0));
    return $beda > 90.0 ? 180.0 - $beda : $beda;
}

/**
 * Masukkan segmen ke kisi petak $sel. Tambahan dibubuhkan di belakang baris.
 *
 * @param list<array{0:float,1:float}> $titik
 */
function tambahSegmen(array &$kisi, array &$segmen, array $titik, float $sel, mixed ...$tambahan): void
{
    for ($i = 0; $i + 1 < count($titik); $i++) {
        $id = count($segmen);
        $dx = (float) $titik[$i + 1][0] - (float) $titik[$i][0];
        $dy = (float) $titik[$i + 1][1] - (float) $titik[$i][1];
        $baris = [(float) $titik[$i][0], (float) $titik[$i][1], (float) $titik[$i + 1][0],
            (float) $titik[$i + 1][1], rad2deg(atan2($dy, $dx))];
        foreach ($tambahan as $t) {
            $baris[] = $t;
        }
        $segmen[$id] = $baris;
        $xMin = (int) floor(min($baris[0], $baris[2]) / $sel);
        $xMax = (int) floor(max($baris[0], $baris[2]) / $sel);
        $yMin = (int) floor(min($baris[1], $baris[3]) / $sel);
        $yMax = (int) floor(max($baris[1], $baris[3]) / $sel);
        for ($gx = $xMin; $gx <= $xMax; $gx++) {
            for ($gy = $yMin; $gy <= $yMax; $gy++) {
                $kisi[$gx . ':' . $gy][$id] = true;
            }
        }
    }
}

/** Segmen terdekat dalam radius: [jarak, arah, kakiX, kakiY, ...tambahan]. */
function jarakSegmen(array $kisi, array $segmen, float $px, float $py, float $radius, float $sel): ?array
{
    $batas = (int) ceil($radius / $sel);
    $gx = (int) floor($px / $sel);
    $gy = (int) floor($py / $sel);
    $baik = null;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                $s = $segmen[$id];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                $t = $pan > 0.0 ? max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan)) : 0.0;
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $jarak = hypot($px - $fx, $py - $fy);
                if ($jarak <= $radius && ($baik === null || $jarak < $baik[0])) {
                    $baik = array_merge([$jarak, $s[4], $fx, $fy], array_slice($s, 5));
                }
            }
        }
    }
    return $baik;
}

/**
 * Label sekelas terdekat dari titik: [jarak, teks]. `$kelas` 'jalan' untuk pola
 * jalan, 'lain' untuk semua kelas bukan jalan.
 */
function labelTerdekat(array $kisi, array $label, float $px, float $py, float $radius, string $kelas): ?array
{
    $batas = (int) ceil($radius / SEL);
    $gx = (int) floor($px / SEL);
    $gy = (int) floor($py / SEL);
    $baik = null;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                $l = $label[$id];
                $cocok = $kelas === 'lain' ? $l[3] !== 'jalan' : $l[3] === 'jalan';
                if (!$cocok) {
                    continue;
                }
                $jarak = hypot($px - $l[1], $py - $l[2]);
                if ($jarak <= $radius && ($baik === null || $jarak < $baik[0])) {
                    $baik = [$jarak, $l[0]];
                }
            }
        }
    }
    return $baik;
}

/** Titik sampel sebuah garis setiap LANGKAH meter, dengan arah segmen asalnya. */
function sampel(array $titik): array
{
    $keluar = [];
    for ($i = 0; $i + 1 < count($titik); $i++) {
        $dx = (float) $titik[$i + 1][0] - (float) $titik[$i][0];
        $dy = (float) $titik[$i + 1][1] - (float) $titik[$i][1];
        $panjang = hypot($dx, $dy);
        if ($panjang <= 0.0) {
            continue;
        }
        $arah = rad2deg(atan2($dy, $dx));
        $n = max(1, (int) floor($panjang / LANGKAH));
        for ($j = 0; $j < $n; $j++) {
            $t = $j / $n;
            $keluar[] = [(float) $titik[$i][0] + $t * $dx, (float) $titik[$i][1] + $t * $dy, $arah];
        }
    }
    return $keluar === [] ? [[(float) $titik[0][0], (float) $titik[0][1], 0.0]] : $keluar;
}

function panjang(array $titik): float
{
    $jumlah = 0.0;
    for ($i = 1; $i < count($titik); $i++) {
        $jumlah += hypot((float) $titik[$i][0] - (float) $titik[$i - 1][0],
            (float) $titik[$i][1] - (float) $titik[$i - 1][1]);
    }
    return $jumlah;
}

/** Masukkan cincin poligon ke kisi berdasarkan kotak batasnya. */
function tambahPoligon(array &$kisi, array &$poligon, array &$besar, array $ring, float $sel,
    int $maksSel = 900): void
{
    if (count($ring) < 4) {
        return;
    }
    $xs = array_column($ring, 0);
    $ys = array_column($ring, 1);
    $gxMin = (int) floor((float) min($xs) / $sel);
    $gxMax = (int) floor((float) max($xs) / $sel);
    $gyMin = (int) floor((float) min($ys) / $sel);
    $gyMax = (int) floor((float) max($ys) / $sel);
    if (($gxMax - $gxMin + 1) * ($gyMax - $gyMin + 1) > $maksSel) {
        $besar[] = $ring;
        return;
    }
    $id = count($poligon);
    $poligon[$id] = $ring;
    for ($gx = $gxMin; $gx <= $gxMax; $gx++) {
        for ($gy = $gyMin; $gy <= $gyMax; $gy++) {
            $kisi[$gx . ':' . $gy][$id] = true;
        }
    }
}

/** Uji ray casting: apakah titik berada di dalam cincin. */
function dalamRing(float $x, float $y, array $ring): bool
{
    $dalam = false;
    for ($i = 0, $k = count($ring) - 1; $i < count($ring); $k = $i++) {
        $xi = (float) $ring[$i][0];
        $yi = (float) $ring[$i][1];
        $xk = (float) $ring[$k][0];
        $yk = (float) $ring[$k][1];
        if ((($yi > $y) !== ($yk > $y)) && ($x < ($xk - $xi) * ($y - $yi) / ($yk - $yi) + $xi)) {
            $dalam = !$dalam;
        }
    }
    return $dalam;
}

/** Apakah titik jatuh di dalam salah satu poligon bidang? */
function diPoligon(float $x, float $y, array $kisi, array $poligon, array $besar): bool
{
    foreach (array_keys($kisi[(int) floor($x / SEL_KISI) . ':' . (int) floor($y / SEL_KISI)] ?? []) as $id) {
        if (dalamRing($x, $y, $poligon[$id])) {
            return true;
        }
    }
    foreach ($besar as $ring) {
        if (dalamRing($x, $y, $ring)) {
            return true;
        }
    }
    return false;
}

/**
 * Id persil tabel bidang yang memuat titik, atau null. Kisi permukaan yang sama
 * dengan `diPoligon` dipakai ulang; `$kisiId`/`$besarId` memetakan indeks
 * poligon ke id bidang, sehingga cincin 020100 yang tidak punya id dilewati.
 */
function idPersil(float $x, float $y, array $kisi, array $poligon, array $besar, array $kisiId,
    array $besarId): ?int
{
    foreach (array_keys($kisi[(int) floor($x / SEL_KISI) . ':' . (int) floor($y / SEL_KISI)] ?? []) as $id) {
        if (isset($kisiId[$id]) && dalamRing($x, $y, $poligon[$id])) {
            return (int) $kisiId[$id];
        }
    }
    foreach ($besar as $i => $ring) {
        if (isset($besarId[$i]) && dalamRing($x, $y, $ring)) {
            return (int) $besarId[$i];
        }
    }
    return null;
}

/**
 * Uji pembatas persil: [pembatas?, sampel dua sisi, sampel dua persil, jumlah
 * sampel]. Arah normal diambil dari arah segmen sampel, jadi putaran garis tidak
 * mengubah hasil.
 */
function pembatasPersil(array $titik, array $kisi, array $poligon, array $besar, array $kisiId,
    array $besarId): array
{
    $dua = 0;
    $beda = 0;
    $n = 0;
    foreach (sampel($titik) as [$x, $y, $arah]) {
        $n++;
        $nx = -sin(deg2rad($arah));
        $ny = cos(deg2rad($arah));
        $kiri = idPersil($x + $nx * PERSIL_OFFSET, $y + $ny * PERSIL_OFFSET, $kisi, $poligon, $besar,
            $kisiId, $besarId);
        $kanan = idPersil($x - $nx * PERSIL_OFFSET, $y - $ny * PERSIL_OFFSET, $kisi, $poligon, $besar,
            $kisiId, $besarId);
        if ($kiri !== null && $kanan !== null) {
            $dua++;
            if ($kiri !== $kanan) {
                $beda++;
            }
        }
    }
    return [$n > 0 && $dua >= PERSIL_BAGI * $n && $beda >= PERSIL_BAGI * $n, $dua, $beda, $n];
}

/**
 * Kunci baris tepi yang membentuk siklus sederhana yang mengurung sebuah bidang
 * tanah, serta jumlah siklus dan siklus yang diuji. Ujung baris dibulatkan
 * CINCIN_BULAT desimal supaya dua ujung yang bertemu tetap dianggap satu simpul.
 *
 * Langkahnya:
 *  1. daun (simpul berderajat < 2) dipangkas berulang, jadi cabang yang menempel
 *     pada sebuah keliling tidak lagi menghalangi pengenalan kelilingnya;
 *  2. komponen sisa yang setiap simpulnya berderajat 2 adalah siklus sederhana;
 *  3. titik 1 m ke arah dalam siklus diuji ke poligon tabel bidang. Siklus yang
 *     mengurung persil (>= CINCIN_BAGI bagian titik uji berada di dalam persil)
 *     adalah keliling bidang; siklus yang mengurung ruang kosong dibiarkan,
 *     karena dua tepi jalan yang bertemu di ujung gang juga membentuk siklus.
 *
 * @return array{0: array<string, true>, 1: int, 2: int}
 */
function cincinTepi(array $tepi, array $kisi, array $poligon, array $besar, array $kisiId,
    array $besarId): array
{
    $simpul = [];
    $sisi = [];
    foreach ($tepi as $i => $t) {
        $titik = $t['titik'];
        $n = count($titik);
        if ($n < 2) {
            continue;
        }
        $a = round((float) $titik[0][0], CINCIN_BULAT) . ':' . round((float) $titik[0][1], CINCIN_BULAT);
        $b = round((float) $titik[$n - 1][0], CINCIN_BULAT) . ':'
            . round((float) $titik[$n - 1][1], CINCIN_BULAT);
        $sisi[$i] = [$a, $b];
        $simpul[$a][] = $i;
        $simpul[$b][] = $i;
    }

    // Derajat tiap simpul, lalu pangkas daun sampai tidak ada lagi.
    $derajat = [];
    foreach ($simpul as $s => $daftar) {
        $derajat[$s] = count($daftar);
    }
    $hidup = array_fill_keys(array_keys($sisi), true);
    $antre = [];
    foreach ($derajat as $s => $d) {
        if ($d < 2) {
            $antre[] = $s;
        }
    }
    while ($antre !== []) {
        $s = array_pop($antre);
        if (($derajat[$s] ?? 0) >= 2 || !isset($simpul[$s])) {
            continue;
        }
        foreach ($simpul[$s] as $i) {
            if (!isset($hidup[$i])) {
                continue;
            }
            unset($hidup[$i]);
            foreach ($sisi[$i] as $lain) {
                if ($lain === $s) {
                    continue;
                }
                $derajat[$lain]--;
                if ($derajat[$lain] === 1) {
                    $antre[] = $lain;
                }
            }
        }
        $derajat[$s] = 0;
    }
    if ($hidup === []) {
        return [[], 0, 0];
    }

    // Tetangga dalam inti sisa.
    $tetangga = [];
    foreach (array_keys($hidup) as $i) {
        [$a, $b] = $sisi[$i];
        $tetangga[$a][] = [$i, $b];
        $tetangga[$b][] = [$i, $a];
    }

    $pakai = [];
    $buang = [];
    $siklus = 0;
    $diuji = 0;
    foreach (array_keys($hidup) as $i) {
        if (isset($pakai[$i])) {
            continue;
        }
        // Kumpulkan komponen inti.
        $antre = [$i];
        $anggota = [];
        $derajatInti = [];
        while ($antre !== []) {
            $j = array_pop($antre);
            if (isset($pakai[$j])) {
                continue;
            }
            $pakai[$j] = true;
            $anggota[] = $j;
            foreach ($sisi[$j] as $s) {
                $derajatInti[$s] = ($derajatInti[$s] ?? 0) + 1;
                foreach ($tetangga[$s] as [$k, $lain]) {
                    if (!isset($pakai[$k])) {
                        $antre[] = $k;
                    }
                }
            }
        }
        $sederhana = true;
        foreach ($derajatInti as $d) {
            if ($d !== 2) {
                $sederhana = false;
                break;
            }
        }
        if (!$sederhana) {
            continue;
        }

        // Telusuri siklus berurutan dari simpul pertamanya.
        $awal = $sisi[$anggota[0]][0];
        $urutan = [];
        $poly = [];
        $kini = $awal;
        $dari = -1;
        while (true) {
            $pilih = null;
            foreach ($tetangga[$kini] as [$k, $lain]) {
                if ($k !== $dari && isset($hidup[$k])) {
                    $pilih = [$k, $lain];
                    break;
                }
            }
            if ($pilih === null) {
                break;
            }
            [$k, $lain] = $pilih;
            $dari = $k;
            $maju = (round((float) $tepi[$k]['titik'][0][0], CINCIN_BULAT) . ':'
                . round((float) $tepi[$k]['titik'][0][1], CINCIN_BULAT)) === $kini;
            $urutan[] = [$k, $maju];
            $titik = $maju ? $tepi[$k]['titik'] : array_reverse($tepi[$k]['titik']);
            foreach ($titik as $p) {
                $poly[] = [(float) $p[0], (float) $p[1]];
            }
            $kini = $lain;
            if ($kini === $awal || count($urutan) > count($anggota) + 1) {
                break;
            }
        }
        if (count($urutan) < count($anggota) || count($poly) < 4) {
            continue;
        }
        // Titik penutup jangan dihitung dua kali pada luas.
        $akhir = $poly[count($poly) - 1];
        if (abs($akhir[0] - $poly[0][0]) <= 0.05 && abs($akhir[1] - $poly[0][1]) <= 0.05) {
            array_pop($poly);
        }
        if (count($poly) < 3) {
            continue;
        }
        $diuji++;

        // Arah dalam siklus dari tanda luas: positif berarti berlawanan jarum
        // jam, jadi bagian dalam ada di kiri arah telusur.
        $luas = 0.0;
        for ($k = 0, $m = count($poly); $k < $m; $k++) {
            $p = $poly[$k];
            $q = $poly[($k + 1) % $m];
            $luas += $p[0] * $q[1] - $q[0] * $p[1];
        }
        $luas /= 2.0;
        if (abs($luas) < 1.0) {
            continue;
        }
        $kiri = $luas > 0.0;
        $dalam = 0;
        $uji = 0;
        foreach ($urutan as [$k, $maju]) {
            $titik = $tepi[$k]['titik'];
            $n = count($titik);
            if ($n < 2) {
                continue;
            }
            // Titik tengah menurut panjang baris, bukan titik tengah talibusur.
            $sisa = (float) $tepi[$k]['panjang'] / 2.0;
            $px = (float) $titik[0][0];
            $py = (float) $titik[0][1];
            $dx = 0.0;
            $dy = 0.0;
            for ($q = 0; $q < $n - 1; $q++) {
                $x0 = (float) $titik[$q][0];
                $y0 = (float) $titik[$q][1];
                $x1 = (float) $titik[$q + 1][0];
                $y1 = (float) $titik[$q + 1][1];
                $dx = $x1 - $x0;
                $dy = $y1 - $y0;
                $p = hypot($dx, $dy);
                if ($p <= 0.0) {
                    continue;
                }
                if ($sisa <= $p) {
                    $t = $sisa / $p;
                    $px = $x0 + $t * $dx;
                    $py = $y0 + $t * $dy;
                    break;
                }
                $sisa -= $p;
            }
            $p = hypot($dx, $dy);
            if ($p <= 0.0) {
                continue;
            }
            $arah = $maju ? 1.0 : -1.0;
            $nx = $kiri ? -$dy / $p : $dy / $p;
            $ny = $kiri ? $dx / $p : -$dx / $p;
            $uji++;
            if (idPersil($px + $arah * $nx * CINCIN_UJI, $py + $arah * $ny * CINCIN_UJI,
                $kisi, $poligon, $besar, $kisiId, $besarId) !== null) {
                $dalam++;
            }
        }
        if ($uji === 0 || $dalam < CINCIN_BAGI * $uji) {
            continue;
        }
        $siklus++;
        foreach ($urutan as [$k]) {
            $buang[(string) $tepi[$k]['kunci']] = true;
        }
    }
    return [$buang, $siklus, $diuji];
}

/**
 * Baris terbit yang membentuk siklus yang menempel ke jaringan jalan pada paling
 * banyak satu simpul, beserta jumlah siklusnya. Dipakai SESUDAH baris tidak
 * terpakai dibuang, karena baris yang di tahap klaim masih bersambung ke jaringan
 * bisa menjadi keliling yang lepas setelah penyaringan itu — persis keluhan
 * "masih terlihat garis peta bidang tanah".
 *
 * Langkahnya sama dengan `cincinTepi` sampai inti, lalu berbeda pada uji akhir:
 *  1. daun dipangkas berulang;
 *  2. semua simpul yang (pada inti) bersambung >= 3 dibuang — simpul itu
 *     persimpangan, dan siklus jalan yang lewat persimpangan tidak boleh ikut;
 *  3. komponen sisa yang setiap simpulnya bersambung 2 adalah siklus yang hanya
 *     menempel di satu simpul atau sama sekali lepas: keliling bidang tanah,
 *     bukan jalan. Siklus yang menyambung ke jaringan di dua simpul atau lebih
 *     terbelah oleh langkah 2, jadi tidak pernah masuk golongan ini.
 *
 * @return array{0: array<int, true>, 1: int}
 */
function siklusLepasTepi(array $tepi): array
{
    $simpul = [];
    $sisi = [];
    foreach ($tepi as $i => $t) {
        $n = count($t['titik']);
        if ($n < 2) {
            continue;
        }
        $a = round((float) $t['titik'][0][0], CINCIN_BULAT) . ':'
            . round((float) $t['titik'][0][1], CINCIN_BULAT);
        $b = round((float) $t['titik'][$n - 1][0], CINCIN_BULAT) . ':'
            . round((float) $t['titik'][$n - 1][1], CINCIN_BULAT);
        $sisi[$i] = [$a, $b];
        $simpul[$a][] = $i;
        $simpul[$b][] = $i;
    }
    $derajat = [];
    foreach ($simpul as $s => $daftar) {
        $derajat[$s] = count($daftar);
    }
    $hidup = array_fill_keys(array_keys($sisi), true);
    $antre = [];
    foreach ($derajat as $s => $d) {
        if ($d < 2) {
            $antre[] = $s;
        }
    }
    while ($antre !== []) {
        $s = array_pop($antre);
        if (($derajat[$s] ?? 0) >= 2 || !isset($simpul[$s])) {
            continue;
        }
        foreach ($simpul[$s] as $i) {
            if (!isset($hidup[$i])) {
                continue;
            }
            unset($hidup[$i]);
            foreach ($sisi[$i] as $lain) {
                if ($lain === $s) {
                    continue;
                }
                $derajat[$lain]--;
                if ($derajat[$lain] === 1) {
                    $antre[] = $lain;
                }
            }
        }
        $derajat[$s] = 0;
    }
    if ($hidup === []) {
        return [[], 0];
    }
    foreach (array_keys($hidup) as $i) {
        foreach ($sisi[$i] as $s) {
            if (($derajat[$s] ?? 0) >= 3) {
                unset($hidup[$i]);
                break;
            }
        }
    }
    $tetangga = [];
    foreach (array_keys($hidup) as $i) {
        [$a, $b] = $sisi[$i];
        $tetangga[$a][] = [$i, $b];
        $tetangga[$b][] = [$i, $a];
    }
    $pakai = [];
    $buang = [];
    $siklus = 0;
    foreach (array_keys($hidup) as $i) {
        if (isset($pakai[$i])) {
            continue;
        }
        $antre = [$i];
        $anggota = [];
        $derajatKomponen = [];
        while ($antre !== []) {
            $j = array_pop($antre);
            if (isset($pakai[$j])) {
                continue;
            }
            $pakai[$j] = true;
            $anggota[] = $j;
            foreach ($sisi[$j] as $s) {
                $derajatKomponen[$s] = ($derajatKomponen[$s] ?? 0) + 1;
                foreach ($tetangga[$s] ?? [] as [$k]) {
                    if (!isset($pakai[$k])) {
                        $antre[] = $k;
                    }
                }
            }
        }
        $sederhana = true;
        foreach ($derajatKomponen as $d) {
            if ($d !== 2) {
                $sederhana = false;
                break;
            }
        }
        if (!$sederhana) {
            continue;
        }
        $siklus++;
        foreach ($anggota as $j) {
            $buang[(int) $j] = true;
        }
    }
    return [$buang, $siklus];
}

/** Apakah titik berada <= radius dari salah satu segmen garis. */
function dekatGaris(array $titik, float $px, float $py, float $radius): bool
{
    for ($i = 0; $i + 1 < count($titik); $i++) {
        $x0 = (float) $titik[$i][0];
        $y0 = (float) $titik[$i][1];
        $dx = (float) $titik[$i + 1][0] - $x0;
        $dy = (float) $titik[$i + 1][1] - $y0;
        $pan = $dx * $dx + $dy * $dy;
        $t = $pan > 0.0 ? max(0.0, min(1.0, (($px - $x0) * $dx + ($py - $y0) * $dy) / $pan)) : 0.0;
        if (hypot($px - ($x0 + $t * $dx), $py - ($y0 + $t * $dy)) <= $radius) {
            return true;
        }
    }
    return false;
}

/**
 * Baris tertutup yang sudah menggambar (hampir) seluruh keliling satu bidang
 * tanah. Titik sampel tiap persil di sekitar baris (LANGKAH 2 m) dihitung yang
 * jatuh <= TOLERANSI_TEPI dari baris; satu persil saja sudah cukup untuk
 * menjawab ya. Uji ini tidak memakai topologi, jadi keliling yang bertemu
 * keliling lain di satu simpul tetap dikenali — hal yang lolos dari `cincinTepi`
 * karena komponennya tidak lagi sederhana.
 */
function tertutupPersil(array $titik, array $kisi, array $poligon, array $besar, array $kisiId,
    array $besarId): bool
{
    $n = count($titik);
    if ($n < 4) {
        return false;
    }
    if (hypot((float) $titik[0][0] - (float) $titik[$n - 1][0],
            (float) $titik[0][1] - (float) $titik[$n - 1][1]) > CINCIN_TUTUP) {
        return false;
    }
    $xs = array_column($titik, 0);
    $ys = array_column($titik, 1);
    $x0 = (float) min($xs) - TOLERANSI_TEPI;
    $y0 = (float) min($ys) - TOLERANSI_TEPI;
    $x1 = (float) max($xs) + TOLERANSI_TEPI;
    $y1 = (float) max($ys) + TOLERANSI_TEPI;
    $kandidat = [];
    for ($gx = (int) floor($x0 / SEL_KISI); $gx <= (int) floor($x1 / SEL_KISI); $gx++) {
        for ($gy = (int) floor($y0 / SEL_KISI); $gy <= (int) floor($y1 / SEL_KISI); $gy++) {
            foreach (array_keys($kisi[$gx . ':' . $gy] ?? []) as $id) {
                if (isset($kisiId[$id])) {
                    $kandidat[$id] = true;
                }
            }
        }
    }
    $daftar = [];
    foreach (array_keys($kandidat) as $id) {
        $daftar[] = $poligon[$id];
    }
    foreach ($besar as $i => $ring) {
        if (isset($besarId[$i])) {
            $daftar[] = $ring;
        }
    }
    foreach ($daftar as $ring) {
        $s = sampel($ring);
        if ($s === []) {
            continue;
        }
        $kena = 0;
        foreach ($s as [$px, $py]) {
            if (dekatGaris($titik, $px, $py, TOLERANSI_TEPI)) {
                $kena++;
            }
        }
        if ($kena >= CINCIN_TUTUP_BAGI * count($s)) {
            return true;
        }
    }
    return false;
}

/** Kelas sebuah teks label. */
function kelasLabel(string $teks): ?string
{
    foreach (KELAS as $kelas => $pola) {
        if (preg_match($pola, mb_strtoupper($teks)) === 1) {
            return $kelas;
        }
    }
    return null;
}

/** Satu kolam label dari semua berkas sumber. */
function bacaLabel(): array
{
    $kisi = [];
    $label = [];
    $jumlah = [];
    foreach (BERKAS_LABEL as $berkas) {
        foreach (geojsonBaca(DIR_LAPISAN . '/' . $berkas . '.geojson') as $f) {
            $k = $f['geometry']['coordinates'] ?? [null, null];
            if (!is_numeric($k[0]) || !is_numeric($k[1])) {
                continue;
            }
            $teks = trim((string) ($f['properties']['label'] ?? ''));
            $kelas = kelasLabel($teks);
            if ($kelas === null) {
                continue;
            }
            $id = count($label);
            $label[$id] = [$teks, (float) $k[0], (float) $k[1], $kelas];
            $kisi[(int) floor((float) $k[0] / SEL) . ':' . (int) floor((float) $k[1] / SEL)][$id] = true;
            $jumlah[$kelas] = ($jumlah[$kelas] ?? 0) + 1;
        }
    }
    return [$kisi, $label, $jumlah];
}

/**
 * Tepi air terbit sebagai segmen berarah: [kisi, segmen] dengan id berkas di
 * kolom keenam, supaya dua segmen dari satu garis tidak dianggap dua tepi.
 */
function bacaTepiAir(): array
{
    $kisi = [];
    $segmen = [];
    $fitur = 0;
    foreach (BERKAS_AIR as $berkas) {
        $path = DIR_LAPISAN . '/' . $berkas . '.geojson';
        if (!is_file($path)) {
            galat('berkas tepi air tidak ada: ' . $berkas . '.geojson');
        }
        foreach (geojsonBaca($path) as $f) {
            $g = $f['geometry'] ?? null;
            if (($g['type'] ?? '') !== 'LineString') {
                continue;
            }
            $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
                (array) ($g['coordinates'] ?? []));
            if (count($titik) < 2) {
                continue;
            }
            $fitur++;
            tambahSegmen($kisi, $segmen, $titik, SEL_PASANG, count($segmen));
        }
    }
    return [$kisi, $segmen, $fitur];
}

/** Tengah koridor berada di antara dua tepi air yang sejajar dengan arah ini? */
function diKoridorAir(array $kisi, array $segmen, float $px, float $py, float $arah): bool
{
    $batas = (int) ceil(RAGU_AIR / SEL_PASANG);
    $gx = (int) floor($px / SEL_PASANG);
    $gy = (int) floor($py / SEL_PASANG);
    $a = deg2rad($arah);
    $nx = -sin($a);
    $ny = cos($a);
    $sisi = [];
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                $s = $segmen[$id];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                if ($pan <= 0.0 || bedaArah($arah, $s[4]) > SUDUT_AIR) {
                    continue;
                }
                $t = max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan));
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $jarak = hypot($px - $fx, $py - $fy);
                if ($jarak <= 0.05 || $jarak > RAGU_AIR) {
                    continue;
                }
                // Sisi diukur terhadap arah pasangan, bukan arah segmen airnya:
                // dua tepi satu selokan bisa digitasi dengan arah berlawanan.
                $belok = ($px - $fx) * $nx + ($py - $fy) * $ny;
                $lagu = $belok > 0.0 ? 'kiri' : 'kanan';
                $fid = (int) $s[5];
                $sisi[$lagu][$fid] = min($sisi[$lagu][$fid] ?? INF, $jarak);
            }
        }
    }
    foreach ($sisi['kiri'] ?? [] as $fid => $_) {
        foreach ($sisi['kanan'] ?? [] as $gid => $_) {
            if ($fid !== $gid) {
                return true;
            }
        }
    }
    return false;
}

/** Cari sisi di seberang koridor: beda sisi, rata, lebar dalam batas, tengah kosong. */
function pasanganTerdekat(array $kisi, array $segmen, array $komiSegmen, float $px, float $py,
    float $radius, int $komiSendiri, float $arah, array $kisiPoligon, array $poligon,
    array $poligonBesar, array $kisiAir, array $segmenAir, int &$tolakAir): ?array
{
    $batas = (int) ceil($radius / SEL_PASANG);
    $gx = (int) floor($px / SEL_PASANG);
    $gy = (int) floor($py / SEL_PASANG);
    $baik = null;
    $kenaAir = false;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                if ($komiSegmen[$id] === $komiSendiri) {
                    continue;
                }
                $s = $segmen[$id];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                $t = $pan > 0.0 ? max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan)) : 0.0;
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $jarak = hypot($px - $fx, $py - $fy);
                if ($jarak < LEBAR_MIN || $jarak > $radius || bedaArah($arah, $s[4]) > SUDUT_PASANG) {
                    continue;
                }
                if (diPoligon(($px + $fx) / 2, ($py + $fy) / 2, $kisiPoligon, $poligon, $poligonBesar)) {
                    continue;
                }
                if (diKoridorAir($kisiAir, $segmenAir, ($px + $fx) / 2, ($py + $fy) / 2, $arah)) {
                    $kenaAir = true;
                    continue;
                }
                if ($baik === null || $jarak < $baik[0]) {
                    $baik = [$jarak, $fx, $fy, $segmen[$id][5]];
                }
            }
        }
    }
    if ($kenaAir) {
        $tolakAir++;
    }
    return $baik;
}

/**
 * Diagnostik untuk sisi yang tidak terpasang: seberapa jauh sisi sejajar
 * terdekat (tanpa uji koridor) dan seberapa jauh yang koridor tengahnya kosong.
 * Mengembalikan [jarakTerdekat, jarakKosongAtauNull].
 */
function seberangTerdekat(array $kisi, array $segmen, array $komiSegmen, float $px, float $py,
    float $radius, int $komiSendiri, float $arah, array $kisiPoligon, array $poligon,
    array $poligonBesar, array $kisiAir, array $segmenAir): array
{
    $batas = (int) ceil($radius / SEL_PASANG);
    $gx = (int) floor($px / SEL_PASANG);
    $gy = (int) floor($py / SEL_PASANG);
    $dekat = null;
    $kosong = null;
    for ($ix = $gx - $batas; $ix <= $gx + $batas; $ix++) {
        for ($iy = $gy - $batas; $iy <= $gy + $batas; $iy++) {
            foreach (array_keys($kisi[$ix . ':' . $iy] ?? []) as $id) {
                if ($komiSegmen[$id] === $komiSendiri) {
                    continue;
                }
                $s = $segmen[$id];
                $dx = $s[2] - $s[0];
                $dy = $s[3] - $s[1];
                $pan = $dx * $dx + $dy * $dy;
                $t = $pan > 0.0 ? max(0.0, min(1.0, (($px - $s[0]) * $dx + ($py - $s[1]) * $dy) / $pan)) : 0.0;
                $fx = $s[0] + $t * $dx;
                $fy = $s[1] + $t * $dy;
                $jarak = hypot($px - $fx, $py - $fy);
                if ($jarak < LEBAR_MIN || $jarak > $radius || bedaArah($arah, $s[4]) > SUDUT_PASANG) {
                    continue;
                }
                if ($dekat === null || $jarak < $dekat) {
                    $dekat = $jarak;
                }
                if (!diPoligon(($px + $fx) / 2, ($py + $fy) / 2, $kisiPoligon, $poligon, $poligonBesar)
                    && !diKoridorAir($kisiAir, $segmenAir, ($px + $fx) / 2, ($py + $fy) / 2, $arah)
                    && ($kosong === null || $jarak < $kosong)) {
                    $kosong = $jarak;
                }
            }
        }
    }
    return [$dekat, $kosong];
}

/** Cari simpul terdekat dalam ragum, atau buat baru. */
function simpul(array &$ragu, array &$titik, float $x, float $y, float $ragum): int
{
    $gx = (int) floor($x / $ragum);
    $gy = (int) floor($y / $ragum);
    $baik = null;
    $jarakBaik = INF;
    for ($ix = $gx - 1; $ix <= $gx + 1; $ix++) {
        for ($iy = $gy - 1; $iy <= $gy + 1; $iy++) {
            foreach (array_keys($ragu[$ix . ':' . $iy] ?? []) as $id) {
                $d = hypot($titik[$id][0] - $x, $titik[$id][1] - $y);
                if ($d <= $ragum && $d < $jarakBaik) {
                    $jarakBaik = $d;
                    $baik = $id;
                }
            }
        }
    }
    if ($baik !== null) {
        return (int) $baik;
    }
    $id = count($titik);
    $titik[$id] = [$x, $y];
    $ragu[$gx . ':' . $gy][$id] = true;
    return $id;
}

function induk(array &$parent, int $a): int
{
    while ($parent[$a] !== $a) {
        $parent[$a] = $parent[$parent[$a]];
        $a = (int) $parent[$a];
    }
    return $a;
}

function gabung(array &$parent, int $a, int $b): void
{
    $ra = induk($parent, $a);
    $rb = induk($parent, $b);
    if ($ra !== $rb) {
        $parent[$rb] = $ra;
    }
}

function tulisBerkas(string $berkas, array $daftar): void
{
    $naskah = json_encode(['type' => 'FeatureCollection', 'features' => $daftar],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($naskah === false) {
        galat('gagal menyusun JSON ' . $berkas);
    }
    if (file_put_contents(DIR_LAPISAN . '/' . $berkas . '.geojson', $naskah) === false) {
        galat('gagal menulis ' . $berkas . '.geojson');
    }
}

/** Cincin luar sebuah Polygon/MultiPolygon GeoJSON, diturunkan ke meter DXF. */
function cincinDariGeojson(array $geometri): array
{
    $tipe = (string) ($geometri['type'] ?? '');
    if (!isset($geometri['coordinates'])) {
        return [];
    }
    $bagian = match ($tipe) {
        'MultiPolygon' => (array) $geometri['coordinates'],
        'Polygon' => [(array) $geometri['coordinates']],
        default => [],
    };
    $keluar = [];
    foreach ($bagian as $poligonBagian) {
        $ring = array_map(
            static fn(array $p): array => wgs84KeDxf((float) $p[0], (float) $p[1]),
            (array) ($poligonBagian[0] ?? [])
        );
        if (count($ring) >= 4) {
            $keluar[] = $ring;
        }
    }
    return $keluar;
}

// ---------------------------------------------------------------------------
// Argumen
// ---------------------------------------------------------------------------

$terapkan = false;
$rinci = false;
$eksklusif = false;
$tahap = 'semua';
$tepiKeluar = 'terpakai';
$sumberTepi = 'turun';
$lebarMaks = 8.0;
$lebarYatim = LEBAR_YATIM;
$tanya = null;
$tanyaBerkas = null;
$jejakPasang = null;
$benihMaks = BENIH;
$benihYatim = BENIH_YATIM;
$bagiPasang = BAGI_PASANG;
$jahitMaks = JAHIT;
$pembatasKeluar = 'hapus';
$cincinKeluar = 'hapus';
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--terapkan') {
        $terapkan = true;
    } elseif ($a === '--rinci') {
        $rinci = true;
    } elseif ($a === '--eksklusif') {
        $eksklusif = true;
    } elseif (str_starts_with($a, '--tahap=')) {
        $tahap = substr($a, 8);
    } elseif (str_starts_with($a, '--tepi=')) {
        $tepiKeluar = substr($a, 7);
    } elseif (str_starts_with($a, '--sumber-tepi=')) {
        $sumberTepi = substr($a, 14);
    } elseif (str_starts_with($a, '--bagi-pasang=')) {
        $bagiPasang = angkaFlag(substr($a, 14), '--bagi-pasang');
    } elseif (str_starts_with($a, '--tanya=')) {
        $bag = explode(',', substr($a, 8));
        if (count($bag) < 2 || count($bag) > 3) {
            galat('--tanya memakai pemisah koma: --tanya=x,y[.radius]; pakai titik desimal untuk koordinat');
        }
        $tanya = [(float) $bag[0], (float) $bag[1], count($bag) === 3 ? angkaFlag($bag[2], 'radius') : 20.0];
    } elseif (str_starts_with($a, '--tanya-berkas=')) {
        $tanyaBerkas = [];
        $isi = @file_get_contents(substr($a, 15));
        if ($isi === false) {
            galat('--tanya-berkas tidak terbaca: ' . substr($a, 15));
        }
        foreach (preg_split('/\r\n|\n|\r/', (string) $isi) as $no => $baris) {
            $baris = trim($baris);
            if ($baris === '' || $baris[0] === '#') {
                continue;
            }
            $bag = explode(',', $baris);
            if (count($bag) < 2 || count($bag) > 4) {
                galat('--tanya-berkas baris ' . ($no + 1) . ': pakai x,y[,radius][,nama]');
            }
            [$tx, $ty] = [(float) $bag[0], (float) $bag[1]];
            $tr = isset($bag[2]) && trim($bag[2]) !== '' ? angkaFlag($bag[2], 'radius') : 20.0;
            if ($tr <= 0.0) {
                galat('--tanya-berkas baris ' . ($no + 1) . ': radius harus lebih besar dari 0');
            }
            $tanyaBerkas[] = [trim($bag[3] ?? ''), $tx, $ty, $tr];
        }
        if ($tanyaBerkas === []) {
            galat('--tanya-berkas tidak berisi satu titik pun');
        }
    } elseif (str_starts_with($a, '--jejak-pasang=')) {
        $jejakPasang = substr($a, 15);
    } elseif (str_starts_with($a, '--lebar-yatim=')) {
        $lebarYatim = angkaFlag(substr($a, 14), '--lebar-yatim');
    } elseif (str_starts_with($a, '--lebar=')) {
        $lebarMaks = angkaFlag(substr($a, 8), '--lebar');
    } elseif (str_starts_with($a, '--benih-yatim=')) {
        $benihYatim = angkaFlag(substr($a, 14), '--benih-yatim');
    } elseif (str_starts_with($a, '--benih=')) {
        $benihMaks = angkaFlag(substr($a, 8), '--benih');
    } elseif (str_starts_with($a, '--jahit=')) {
        $jahitMaks = angkaFlag(substr($a, 8), '--jahit');
    } elseif (str_starts_with($a, '--pembatas-persil=')) {
        $pembatasKeluar = substr($a, 18);
    } elseif (str_starts_with($a, '--cincin=')) {
        $cincinKeluar = substr($a, 9);
    } else {
        galat('argumen tidak dikenal: ' . $a);
    }
}
if (!in_array($tahap, ['tepi', 'sumbu', 'semua'], true)) {
    galat('--tahap harus tepi, sumbu, atau semua');
}
if (!in_array($tepiKeluar, ['terpakai', 'semua'], true)) {
    galat('--tepi harus terpakai atau semua');
}
if ($tahap === 'tepi' && $tepiKeluar === 'terpakai') {
    galat('--tahap=tepi tidak punya sumbu untuk menentukan baris terpakai; pakai --tepi=semua');
}
if (!in_array($sumberTepi, ['turun', 'terbit'], true)) {
    galat('--sumber-tepi harus turun atau terbit');
}
if ($tahap === 'tepi' && $sumberTepi === 'terbit') {
    galat('dengan --sumber-tepi=terbit tidak ada tepi yang diturunkan; pakai --tahap=sumbu');
}
if ($lebarMaks <= LEBAR_MIN) {
    galat('--lebar harus lebih besar dari ' . LEBAR_MIN);
}
if ($lebarYatim !== 0.0 && ($lebarYatim <= $lebarMaks || $lebarYatim > SEBERANG_UJI)) {
    galat('--lebar-yatim harus 0 (mati) atau antara ' . angkaNum($lebarMaks, 1) . ' dan '
        . angkaNum(SEBERANG_UJI, 1) . ' meter');
}
if ($tanya !== null && $tanya[2] <= 0.0) {
    galat('radius --tanya harus lebih besar dari 0');
}
if ($bagiPasang < 0.0 || $bagiPasang > 1.0) {
    galat('--bagi-pasang harus antara 0 dan 1');
}
if ($benihMaks <= 0.0 || $benihMaks > PITA) {
    galat('--benih harus antara 0 dan ' . angkaNum(PITA, 1) . ' meter');
}
if ($benihYatim !== 0.0 && ($benihYatim <= $benihMaks || $benihYatim > PITA)) {
    galat('--benih-yatim harus 0 (mati) atau antara ' . angkaNum($benihMaks, 1) . ' dan '
        . angkaNum(PITA, 1) . ' meter');
}
if ($jahitMaks < 0.0 || $jahitMaks > JAHIT_MAKS) {
    galat('--jahit harus antara 0 dan ' . angkaNum(JAHIT_MAKS, 1) . ' meter; 0 mematikan penyambungan');
}
if (!in_array($pembatasKeluar, ['hapus', 'simpan'], true)) {
    galat('--pembatas-persil harus hapus atau simpan');
}
if (!in_array($cincinKeluar, ['hapus', 'simpan'], true)) {
    galat('--cincin harus hapus atau simpan');
}
printf("mode: tahap %s · sumber tepi %s · tepi diterbitkan %s · benih %s m%s · lebar koridor maksimum %s m%s"
    . " · bagi pasang %s · jahit celah %s m · cincin %s · pembatas persil %s · %s · %s\n",
    $tahap, $sumberTepi, $tepiKeluar, angkaNum($benihMaks, 1),
    $benihYatim === 0.0 ? '' : ' + yatim ' . angkaNum($benihYatim, 1),
    angkaNum($lebarMaks, 1),
    $lebarYatim === 0.0 ? '' : ' + yatim ' . angkaNum($lebarYatim, 1),
    angkaNum($bagiPasang, 2),
    angkaNum($jahitMaks, 1),
    $cincinKeluar,
    $pembatasKeluar,
    $eksklusif ? 'label jalan harus yang terdekat' : 'kelas lain tidak jadi syarat',
    $terapkan ? 'TERAPKAN' : 'uji kering');

// ---------------------------------------------------------------------------
// Bahan: 020100, tabel bidang, dan label
// ---------------------------------------------------------------------------

$deriveTepi = $sumberTepi === 'turun';
$kisiTepi = [];
$segmenTepi = [];
$kisiUlang = [];
$segmenUlang = [];
$kisiPoligon = [];
$poligon = [];
$poligonBesar = [];
$poligonBidang = [];
$besarBidang = [];
$kandidat = [];
$cincin020100 = [];
$cincinBidang = [];
$jumlahPoligon020100 = 0;

foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_SUMBER . '.geojson') as $indeks => $f) {
    $g = $f['geometry'] ?? null;
    $tipe = (string) ($g['type'] ?? '');
    if ($tipe === 'Polygon') {
        $jumlahPoligon020100++;
        $ring = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) ($g['coordinates'][0] ?? []));
        tambahSegmen($kisiTepi, $segmenTepi, $ring, SEL_KISI);
        tambahPoligon($kisiPoligon, $poligon, $poligonBesar, $ring, SEL_KISI);
        if ($deriveTepi && count($ring) >= 4) {
            $cincin020100[(int) $indeks] = $ring;
        }
    } elseif ($tipe === 'LineString' && count((array) ($g['coordinates'] ?? [])) >= 2) {
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) $g['coordinates']);
        if ($deriveTepi) {
            $kandidat['G:' . (int) $indeks] = ['titik' => $titik, 'panjang' => panjang($titik),
                'sumber' => '020100', 'indeks' => (int) $indeks, 'asal' => 'garis'];
        }
        tambahSegmen($kisiUlang, $segmenUlang, $titik, SEL_KISI);
    }
}
printf("020100: %d poligon tertutup (%d cincin jadi calon) · %d garis terbuka jadi calon"
    . " · %d segmen tergambar\n", $jumlahPoligon020100, count($cincin020100), count($kandidat),
    count($segmenUlang));

$jumlahPersil = 0;
foreach ((array) db_all('SELECT id, ST_AsGeoJSON(geom) AS geojson FROM bidang '
    . "WHERE status <> 'dihapus' AND geom IS NOT NULL ORDER BY id") as $baris) {
    $geometri = json_decode((string) $baris['geojson'], true);
    if (!is_array($geometri)) {
        continue;
    }
    foreach (cincinDariGeojson($geometri) as $ring) {
        $jumlahPersil++;
        $sebelumPoligon = count($poligon);
        $sebelumBesar = count($poligonBesar);
        tambahSegmen($kisiTepi, $segmenTepi, $ring, SEL_KISI);
        tambahPoligon($kisiPoligon, $poligon, $poligonBesar, $ring, SEL_KISI);
        if (count($poligon) > $sebelumPoligon) {
            $poligonBidang[$sebelumPoligon] = (int) $baris['id'];
        } elseif (count($poligonBesar) > $sebelumBesar) {
            $besarBidang[$sebelumBesar] = (int) $baris['id'];
        }
        if ($deriveTepi) {
            $cincinBidang[(int) $baris['id']] = $ring;
        }
    }
}
printf("bidang dari basis data (dibaca saja): %d persil · %d segmen tepi total · kisi permukaan"
    . " %d petak\n", $jumlahPersil, count($segmenTepi), count($kisiPoligon));

[$kisiLbl, $label, $jumlahLabel] = bacaLabel();
$labelJalan = (int) ($jumlahLabel['jalan'] ?? 0);
printf("label: %d titik terpakai (%s)\n", count($label), implode(', ', array_map(
    static fn(string $k, int $n): string => $k . ' ' . $n,
    array_keys($jumlahLabel), array_values($jumlahLabel))));

// ---------------------------------------------------------------------------
// Tahap 1: tepi
// ---------------------------------------------------------------------------

$tepi = [];

$simpanTepi = static function (string $kunci, array $titik, string $sumber, int $indeks, string $asal,
    string $cara, ?float $jarak, ?string $nama, ?float $rasioTepi) use (&$tepi): void {
    $tepi[] = ['kunci' => $kunci, 'titik' => $titik, 'panjang' => panjang($titik), 'sumber' => $sumber,
        'indeks' => $indeks, 'asal' => $asal, 'cara' => $cara, 'jarak' => $jarak, 'label' => $nama,
        'rasioTepi' => $rasioTepi];
};

if ($deriveTepi) {
    // Rasio titik sampel yang jatuh di tepi bidang: seberapa jauh garis ini
    // hanya mengulang sisi bidang yang sudah ada.
    foreach ($kandidat as $kunci => $garis) {
        $s = sampel($garis['titik']);
        $kena = 0;
        foreach ($s as [$x, $y]) {
            if (jarakSegmen($kisiTepi, $segmenTepi, $x, $y, TOLERANSI_TEPI, SEL_KISI) !== null) {
                $kena++;
            }
        }
        $kandidat[$kunci]['sampel'] = $s;
        $kandidat[$kunci]['rasioTepi'] = $kena / max(1, count($s));
    }

    // Cincin 020100 lalu cincin tabel bidang, masing-masing diurut dari keliling
    // terbesar dan ditolak bila >= BAGI_ULANG titiknya sudah tergambar lebih dulu.
    $cincinDipakai = ['020100' => 0, 'bidang' => 0];
    $cincinMengulang = ['020100' => 0, 'bidang' => 0];
    $cincinPendek = ['020100' => 0, 'bidang' => 0];
    foreach (['020100' => $cincin020100, 'bidang' => $cincinBidang] as $asal => $daftarCincin) {
        uasort($daftarCincin, static fn(array $a, array $b): int => panjang($b) <=> panjang($a));
        foreach ($daftarCincin as $indeks => $ring) {
            $p = panjang($ring);
            if ($p < MIN_PANJANG) {
                $cincinPendek[$asal]++;
                continue;
            }
            $s = sampel($ring);
            $kena = 0;
            foreach ($s as [$x, $y]) {
                if (jarakSegmen($kisiUlang, $segmenUlang, $x, $y, TOLERANSI_TEPI, SEL_KISI) !== null) {
                    $kena++;
                }
            }
            if ($kena / max(1, count($s)) >= BAGI_ULANG) {
                $cincinMengulang[$asal]++;
                continue;
            }
            $kandidat[($asal === '020100' ? 'P:' : 'B:') . $indeks] = ['titik' => $ring, 'panjang' => $p,
                'sampel' => $s, 'rasioTepi' => $kena / max(1, count($s)),
                'sumber' => $asal === '020100' ? '020100' : 'tabel-bidang', 'indeks' => (int) $indeks,
                'asal' => $asal === '020100' ? 'cincin-020100' : 'cincin-bidang'];
            $cincinDipakai[$asal]++;
            tambahSegmen($kisiUlang, $segmenUlang, $ring, SEL_KISI);
        }
        printf("cincin %s: %d diuji · %d dipakai sebagai calon tepi · %d mengulang garis lain"
            . " · %d terlalu pendek\n", $asal, count($daftarCincin), $cincinDipakai[$asal],
            $cincinMengulang[$asal], $cincinPendek[$asal]);
    }

    $kisiKlaim = [];
    $segmenKlaim = [];
    $terklaim = [];
    $buangPendek = 0;
    $lainDepan = 0;

    $klaim = static function (string $kunci, string $cara, ?float $jarak, ?string $nama) use (
        &$kandidat, &$kisiKlaim, &$segmenKlaim, &$terklaim
    ): void {
        $terklaim[$kunci] = ['cara' => $cara, 'jarak' => $jarak, 'label' => $nama];
        tambahSegmen($kisiKlaim, $segmenKlaim, $kandidat[$kunci]['titik'], SEL, (string) ($nama ?? ''));
    };

    foreach ($kandidat as $kunci => $garis) {
        if ($garis['panjang'] < MIN_PANJANG) {
            $buangPendek++;
            continue;
        }
        $jalan = null;
        foreach ($garis['sampel'] as [$x, $y]) {
            $c = labelTerdekat($kisiLbl, $label, $x, $y, $benihMaks, 'jalan');
            if ($c !== null && ($jalan === null || $c[0] < $jalan[0])) {
                $jalan = $c;
            }
            if ($jalan !== null && $jalan[0] <= 0.01) {
                break;
            }
        }
        if ($jalan === null) {
            continue;
        }
        $lain = null;
        foreach ($garis['sampel'] as [$x, $y]) {
            $d = labelTerdekat($kisiLbl, $label, $x, $y, $benihMaks, 'lain');
            if ($d !== null && ($lain === null || $d[0] < $lain[0])) {
                $lain = $d;
            }
        }
        if ($lain !== null && $lain[0] < $jalan[0]) {
            $lainDepan++;
            if ($eksklusif) {
                continue;
            }
        }
        $klaim((string) $kunci, 'benih', $jalan[0], $jalan[1]);
    }
    $benih = count($terklaim);
    $labelYatim = 0;
    $klaimYatim = 0;

    // Radius kedua (--benih-yatim). Label yang dalam --benih sama sekali tidak
    // mendapat baris mustahil menjadi benih apa pun, dan itulah 120 label tanpa
    // sumbu (README §3.82/§3.83): medannya bahan 020100 yang digambar 3-15 m di
    // luar radius benih. Yang dijangkau di sini HANYA label tanpa benih, jadi
    // baris yang sudah terkumpul di atas tidak berubah dan seluruh keluaran
    // sebelumnya tetap utuh; sisanya tetap disaring pita sejajar, koridor kosong,
    // uji lebar dan uji air seperti biasa.
    if ($benihYatim > 0.0) {
        $petakBenih = [];
        foreach ($terklaim as $kunci => $isi) {
            if ($isi['cara'] !== 'benih') {
                continue;
            }
            foreach ($kandidat[$kunci]['sampel'] as $s) {
                $petakBenih[(int) floor($s[0] / SEL) . ':' . (int) floor($s[1] / SEL)][] = [$s[0], $s[1]];
            }
        }
        $batas = (int) ceil($benihMaks / SEL);
        $yatim = [];
        foreach ($label as $i => $l) {
            if ($l[3] !== 'jalan') {
                continue;
            }
            $gx = (int) floor($l[1] / SEL);
            $gy = (int) floor($l[2] / SEL);
            $ada = false;
            for ($ix = $gx - $batas; $ix <= $gx + $batas && !$ada; $ix++) {
                for ($iy = $gy - $batas; $iy <= $gy + $batas && !$ada; $iy++) {
                    foreach ($petakBenih[$ix . ':' . $iy] ?? [] as [$bx, $by]) {
                        if (hypot($l[1] - $bx, $l[2] - $by) <= $benihMaks) {
                            $ada = true;
                            break;
                        }
                    }
                }
            }
            if (!$ada) {
                $yatim[] = $i;
            }
        }
        $labelYatim = count($yatim);

        $petakCalon = [];
        foreach ($kandidat as $kunci => $garis) {
            if (isset($terklaim[$kunci]) || $garis['panjang'] < MIN_PANJANG) {
                continue;
            }
            foreach ($garis['sampel'] as $s) {
                $petakCalon[(int) floor($s[0] / SEL) . ':' . (int) floor($s[1] / SEL)][]
                    = [$kunci, $s[0], $s[1], $s[2]];
            }
        }
        $batasY = (int) ceil($benihYatim / SEL);
        $jauhYatim = [];
        foreach ($yatim as $i) {
            [$teks, $lx, $ly] = [$label[$i][0], $label[$i][1], $label[$i][2]];
            $gx = (int) floor($lx / SEL);
            $gy = (int) floor($ly / SEL);
            $temu = [];
            for ($ix = $gx - $batasY; $ix <= $gx + $batasY; $ix++) {
                for ($iy = $gy - $batasY; $iy <= $gy + $batasY; $iy++) {
                    foreach ($petakCalon[$ix . ':' . $iy] ?? [] as [$kunci, $x, $y]) {
                        if (isset($terklaim[$kunci]) || isset($temu[$kunci])) {
                            continue;
                        }
                        $d = hypot($lx - $x, $ly - $y);
                        if ($d <= $benihYatim) {
                            $temu[$kunci] = [$d, $x, $y];
                        }
                    }
                }
            }
            foreach ($temu as $kunci => [$d, $x, $y]) {
                if (isset($terklaim[$kunci])) {
                    continue;
                }
                $lain = labelTerdekat($kisiLbl, $label, $x, $y, $benihYatim, 'lain');
                if ($lain !== null && $lain[0] < $d) {
                    $lainDepan++;
                    continue;
                }
                $klaim((string) $kunci, 'benih-yatim', $d, $teks);
                $jauhYatim[(int) floor($d)] = ($jauhYatim[(int) floor($d)] ?? 0) + 1;
                $klaimYatim++;
            }
        }
        ksort($jauhYatim);
        $benih = count($terklaim);
        printf("benih yatim: %d dari %d label jalan tanpa benih dalam %s m · %d baris baru diklaim"
            . " pada jarak (meter bulat) %s\n",
            count($jauhYatim), $labelYatim, angkaNum($benihYatim, 1), $klaimYatim,
            $jauhYatim === [] ? '-' : implode(' ', array_map(
                static fn(string $k, int $n): string => $k . ' m:' . $n, array_keys($jauhYatim),
                array_values($jauhYatim))));
    }

    $tolakSejajar = [];
    $tolakKoridor = [];
    for ($putaran = 0; $putaran < PUTARAN; $putaran++) {
        $baru = 0;
        foreach ($kandidat as $kunci => $garis) {
            if ($garis['panjang'] < MIN_PANJANG || isset($terklaim[$kunci])) {
                continue;
            }
            $sejajar = 0;
            $kosong = 0;
            $jarakLbl = null;
            $namaLbl = null;
            foreach ($garis['sampel'] as [$x, $y, $arah]) {
                $dekat = jarakSegmen($kisiKlaim, $segmenKlaim, $x, $y, PITA, SEL);
                if ($dekat === null || bedaArah($arah, $dekat[1]) > SUDUT) {
                    continue;
                }
                if ($sejajar === 0) {
                    $jarakLbl = $dekat[0];
                    $namaLbl = (string) ($dekat[4] ?? '');
                }
                $sejajar++;
                if ($dekat[0] < 0.5
                    || !diPoligon(($x + $dekat[2]) / 2, ($y + $dekat[3]) / 2, $kisiPoligon, $poligon,
                        $poligonBesar)) {
                    $kosong++;
                }
            }
            if ($sejajar < BAGI_SEJAJAR * count($garis['sampel'])) {
                $tolakSejajar[(string) $kunci] = true;
                continue;
            }
            if ($kosong < BAGI_KOSONG * $sejajar) {
                $tolakKoridor[(string) $kunci] = true;
                continue;
            }
            $klaim((string) $kunci, 'pita' . ($putaran + 1), $jarakLbl, $namaLbl);
            $baru++;
        }
        if ($baru === 0) {
            break;
        }
    }

    foreach ($kandidat as $kunci => $garis) {
        if (!isset($terklaim[$kunci]) || $garis['panjang'] < MIN_PANJANG) {
            continue;
        }
        $isi = $terklaim[$kunci];
        $simpanTepi((string) $kunci, $garis['titik'], $garis['sumber'], (int) $garis['indeks'],
            $garis['asal'], $isi['cara'], $isi['jarak'], $isi['label'], $garis['rasioTepi']);
    }
    $caraTepi = array_count_values(array_column($tepi, 'cara'));
    printf("tepi: %d baris diklaim dari %d calon (%s) · %s m · benih %d, pita %d, tolak sejajar %d,"
        . " tolak koridor %d, pendek %d, label kelas lain lebih dekat %d\n",
        count($tepi), count($kandidat), implode('/', array_map(
            static fn(string $k, int $n): string => $k . ' ' . $n, array_keys($caraTepi),
            array_values($caraTepi))),
        angkaNum((float) array_sum(array_column($tepi, 'panjang')), 1), $benih, count($tepi) - $benih,
        count($tolakSejajar), count($tolakKoridor), $buangPendek, $lainDepan);
} else {
    foreach (geojsonBaca(DIR_LAPISAN . '/' . BERKAS_TERBIT . '.geojson') as $f) {
        $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]],
            (array) ($f['geometry']['coordinates'] ?? []));
        if (count($titik) < 2) {
            continue;
        }
        $p = $f['properties'] ?? [];
        $simpanTepi((string) ($p['kode'] ?? count($tepi)), $titik, (string) ($p['sumber'] ?? '020100'),
            (int) ($p['indeks'] ?? 0), (string) ($p['asal'] ?? 'garis'), (string) ($p['cara'] ?? ''),
            isset($p['jarak_label_m']) && $p['jarak_label_m'] !== null ? (float) $p['jarak_label_m'] : null,
            isset($p['label']) ? (string) $p['label'] : null,
            isset($p['rasio_tepi_bidang']) ? (float) $p['rasio_tepi_bidang'] : null);
    }
    printf("tepi dari lapis terbit %s.geojson: %d baris · %s m\n", BERKAS_TERBIT, count($tepi),
        angkaNum((float) array_sum(array_column($tepi, 'panjang')), 1));
}

if ($tepi === []) {
    galat('tidak ada satu pun tepi yang bisa dipakai');
}

// Saringan cincin: komponen tepi yang setiap simpulnya berderajat 2 kembali ke
// titik awal, jadi komponen itu keliling sebuah bidang tanah, bukan tepi jalan.
// Dibuang sebelum saringan pembatas persil dan sebelum tahap 2 supaya sumbu
// dibangun tanpa baris itu dan `tepi_all` tidak menunjuk baris yang hilang.
$jumlahTepiKlaim = count($tepi);
$tutupDiuji = 0;
$tutupBuang = 0;
$tutupPanjang = 0.0;
$tutupCara = [];
$tutupAsal = [];
$cincinBuang = [];
$cincinKomponen = 0;
$cincinDiuji = 0;
$cincinPanjang = 0.0;
$cincinCara = [];
$cincinAsal = [];
if ($cincinKeluar === 'hapus') {
    // Saringan keliling satu baris lebih dulu: satu polilin tertutup yang
    // menutup satu persil tidak perlu topologi, dan membuangnya lebih dulu
    // membuat komponen sisa lebih sederhana bagi saringan cincin di bawahnya.
    $sisaTepi = [];
    foreach ($tepi as $t) {
        $n = count($t['titik']);
        $tertutup = $n >= 4
            && hypot((float) $t['titik'][0][0] - (float) $t['titik'][$n - 1][0],
                (float) $t['titik'][0][1] - (float) $t['titik'][$n - 1][1]) <= CINCIN_TUTUP;
        if ($tertutup) {
            $tutupDiuji++;
        }
        if ($tertutup && tertutupPersil($t['titik'], $kisiPoligon, $poligon, $poligonBesar,
                $poligonBidang, $besarBidang)) {
            $tutupBuang++;
            $tutupPanjang += $t['panjang'];
            $tutupCara[$t['cara']] = ($tutupCara[$t['cara']] ?? 0) + 1;
            $tutupAsal[$t['asal']] = ($tutupAsal[$t['asal']] ?? 0) + 1;
            continue;
        }
        $sisaTepi[] = $t;
    }
    $tepi = $sisaTepi;
    ksort($tutupCara);
    ksort($tutupAsal);
    printf("saring keliling satu baris: %d baris tertutup diperiksa · %d menutup >= %d%% keliling"
        . " satu persil · dibuang %s m (%s; %s) · tersisa %d baris\n", $tutupDiuji, $tutupBuang,
        (int) round(CINCIN_TUTUP_BAGI * 100), angkaNum($tutupPanjang, 1),
        json_encode($tutupCara, JSON_UNESCAPED_UNICODE),
        json_encode($tutupAsal, JSON_UNESCAPED_UNICODE), count($tepi));
    if ($tepi === []) {
        galat('saringan keliling satu baris membuang seluruh tepi; pakai --cincin=simpan');
    }

    [$cincinBuang, $cincinKomponen, $cincinDiuji] = cincinTepi($tepi, $kisiPoligon, $poligon,
        $poligonBesar, $poligonBidang, $besarBidang);
    if ($cincinBuang !== []) {
        $sisaTepi = [];
        foreach ($tepi as $t) {
            if (isset($cincinBuang[(string) $t['kunci']])) {
                $cincinPanjang += $t['panjang'];
                $cincinCara[$t['cara']] = ($cincinCara[$t['cara']] ?? 0) + 1;
                $cincinAsal[$t['asal']] = ($cincinAsal[$t['asal']] ?? 0) + 1;
                continue;
            }
            $sisaTepi[] = $t;
        }
        $tepi = $sisaTepi;
    }
    ksort($cincinCara);
    ksort($cincinAsal);
    printf("saring cincin: %d dari %d siklus sederhana mengurung persil · %d baris dibuang (%s m; %s; %s)"
        . " · tersisa %d baris\n",
        $cincinKomponen, $cincinDiuji, count($cincinBuang), angkaNum($cincinPanjang, 1),
        json_encode($cincinCara, JSON_UNESCAPED_UNICODE), json_encode($cincinAsal, JSON_UNESCAPED_UNICODE),
        count($tepi));
    if ($tepi === []) {
        galat('saringan cincin membuang seluruh tepi; pakai --cincin=simpan');
    }
} else {
    echo 'saring cincin: dimatikan (--cincin=simpan).' . PHP_EOL;
}

// Saringan pembatas persil: baris yang kedua sisinya jatuh di persil berbeda
// adalah garis bidang tanah, bukan tepi jalan. Dibuang sebelum tahap 2 supaya
// sumbu dibangun tanpa baris itu dan `tepi_all` tidak menunjuk baris yang hilang.
$pembatasBuang = 0;
$pembatasPanjang = 0.0;
$pembatasAsal = [];
if ($pembatasKeluar === 'hapus') {
    $sisaTepi = [];
    foreach ($tepi as $t) {
        [$pembatas] = pembatasPersil($t['titik'], $kisiPoligon, $poligon, $poligonBesar,
            $poligonBidang, $besarBidang);
        if ($pembatas) {
            $pembatasBuang++;
            $pembatasPanjang += $t['panjang'];
            $pembatasAsal[$t['asal']] = ($pembatasAsal[$t['asal']] ?? 0) + 1;
            continue;
        }
        $sisaTepi[] = $t;
    }
    $tepi = $sisaTepi;
    ksort($pembatasAsal);
    printf("saring pembatas persil: %d baris klaim dibuang (%s m; %s) · tersisa %d baris\n",
        $pembatasBuang, angkaNum($pembatasPanjang, 1), json_encode($pembatasAsal, JSON_UNESCAPED_UNICODE),
        count($tepi));
    if ($tepi === []) {
        galat('saringan pembatas persil membuang seluruh tepi; pakai --pembatas-persil=simpan');
    }
} else {
    echo 'saring pembatas persil: dimatikan (--pembatas-persil=simpan).' . PHP_EOL;
}

$susunTepi = static function (array $baris): array {
    $keluar = [];
    $nomor = 0;
    foreach ($baris as $t) {
        $keluar[] = [
            'type' => 'Feature',
            'properties' => [
                'kode' => sprintf('TEPI-%04d', ++$nomor),
                'kunci' => $t['kunci'],
                'sumber' => $t['sumber'],
                'indeks' => $t['indeks'],
                'panjang_m' => round($t['panjang'], 2),
                'rasio_tepi_bidang' => $t['rasioTepi'] === null ? null : round($t['rasioTepi'], 3),
                'asal' => $t['asal'],
                'cara' => $t['cara'],
                'jarak_label_m' => $t['jarak'] === null ? null : round($t['jarak'], 2),
                'label' => $t['label'],
            ],
            'geometry' => ['type' => 'LineString', 'coordinates' => $t['titik']],
        ];
    }
    return $keluar;
};

$berkasTepi = $susunTepi($tepi);

if ($tahap === 'tepi') {
    if ($terapkan) {
        tulisBerkas(BERKAS_TEPI, $berkasTepi);
    }
    printf("%-26s %5d fitur · %10s m · %s\n", BERKAS_TEPI . '.geojson', count($berkasTepi),
        angkaNum((float) array_sum(array_column($tepi, 'panjang')), 1),
        $terapkan ? 'ditulis' : 'uji kering');
    echo 'tahap sumbu dilewati (--tahap=tepi).' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// Tahap 2: sumbu
// ---------------------------------------------------------------------------

// 1. Sisi: potongan tepi yang berbagi simpul adalah satu sisi jalan.
$parent = [];
for ($id = 0; $id < count($tepi); $id++) {
    $parent[$id] = $id;
}
$raguSisi = [];
$titikSisi = [];
$tepidiSimpul = [];
foreach ($tepi as $id => $t) {
    $ujung = $t['titik'][0];
    $pangkal = $t['titik'][count($t['titik']) - 1];
    $a = simpul($raguSisi, $titikSisi, (float) $ujung[0], (float) $ujung[1], RAGU_SISI);
    $b = simpul($raguSisi, $titikSisi, (float) $pangkal[0], (float) $pangkal[1], RAGU_SISI);
    $tepidiSimpul[$a][$id] = true;
    $tepidiSimpul[$b][$id] = true;
}
foreach ($tepidiSimpul as $anggota) {
    $ids = array_keys($anggota);
    for ($i = 1; $i < count($ids); $i++) {
        gabung($parent, (int) $ids[0], (int) $ids[$i]);
    }
}
$komi = [];
foreach ($tepi as $id => $t) {
    $komi[$id] = induk($parent, (int) $id);
}
$sisi = count(array_unique($komi));

// 2. Pasangan di seberang koridor.
$kisiPasang = [];
$segmenPasang = [];
$komiSegmen = [];
foreach ($tepi as $id => $t) {
    $sebelum = count($segmenPasang);
    tambahSegmen($kisiPasang, $segmenPasang, $t['titik'], SEL_PASANG, $id);
    for ($i = $sebelum; $i < count($segmenPasang); $i++) {
        $komiSegmen[$i] = $komi[$id];
    }
}
$terpasang = 0;
$tunggal = 0;
$panjangTunggal = 0.0;
$setengah = 0;
$koridorTertutup = 0;
$tolakAir = 0;
[$kisiAir, $segmenAir, $jumlahAir] = bacaTepiAir();
printf("tepi air: %d baris dari %s · %d segmen · ragum %s m · sudut %s derajat\n",
    $jumlahAir, implode(' + ', BERKAS_AIR), count($segmenAir), angkaNum(RAGU_AIR, 1),
    angkaNum(SUDUT_AIR, 0));
$pitaTunggal = ['<=2 m' => 0, '2-4 m' => 0, '4-8 m' => 0, '8-15 m' => 0, 'tidak ada sisi sejajar' => 0];
$jalur = [];
$pasangan = [];

/** Titik tengah per komponen seberang, ditambah sampel yang tidak berpasangan. */
$olehSeberang = static function (int $id, float $radius) use ($tepi, $komi, $kisiPasang, $segmenPasang,
    $komiSegmen, $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir, &$tolakAir): array {
    $s = sampel($tepi[$id]['titik']);
    $oleh = [];
    $lubang = [];
    $tutup = array_fill(0, count($s), false);
    foreach ($s as $k => [$x, $y, $arah]) {
        $p = pasanganTerdekat($kisiPasang, $segmenPasang, $komiSegmen, $x, $y, $radius, (int) $komi[$id],
            $arah, $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir, $tolakAir);
        if ($p === null) {
            $lubang[] = [$x, $y, $arah, $k];
            continue;
        }
        $tutup[$k] = true;
        $lain = (int) $komi[$p[3]];
        $oleh[$lain]['n'] = ($oleh[$lain]['n'] ?? 0) + 1;
        $oleh[$lain]['titik'][] = [($x + $p[1]) / 2, ($y + $p[2]) / 2, $p[0], $id];
    }
    return [$oleh, count($s), $lubang, $tutup];
};

/** Komponen seberang yang paling sering muncul bagi satu baris. */
$pilihUtama = static function (array $oleh): ?int {
    $utama = null;
    foreach ($oleh as $k => $isi) {
        if ($utama === null || $isi['n'] > $oleh[$utama]['n']) {
            $utama = $k;
        }
    }
    return $utama === null ? null : (int) $utama;
};

$sisa = [];
$lubangBaris = [];
foreach ($tepi as $id => $t) {
    [$oleh, $jumlahSampel, $lubang, $tutup] = $olehSeberang((int) $id, $lebarMaks);
    $tepi[$id]['pasang'] = $tutup;
    if ($lubang !== [] && $lebarYatim > $lebarMaks) {
        $lubangBaris[(int) $id] = $lubang;
    }
    $utama = $pilihUtama($oleh);
    if ($utama === null) {
        $sisa[(int) $id] = 'sejajar';
        continue;
    }
    if ($oleh[$utama]['n'] < $bagiPasang * $jumlahSampel) {
        $sisa[(int) $id] = 'setengah';
        continue;
    }
    $terpasang++;
    $pasangan[min((int) $komi[$id], $utama) . ':' . max((int) $komi[$id], $utama)] = true;
    $jalur[] = $oleh[$utama]['titik'];
}

// Ronde kedua (--lebar-yatim), ditanyakan PER TITIK SAMPEL, bukan per baris.
// Yang dicari ulang hanya sampel yang di ronde pertama tidak mendapat pasangan
// dalam --lebar; pemenang suara mayorotas sebuah baris tidak pernah digeser, jadi
// sumbu yang sudah ada tetap utuh dan ronde ini menambal lubangnya saja. Ini
// perlu karena satu baris tepi bisa berpanjangan melewati dua koridor berbeda:
// sampel di ruas yang diapit 9,5 m tidak terpasang dalam 8 m, sementara barisnya
// sendiri menang suara di koridor lain yang lebih sempit.
$terbaiki = 0;
$titikTerbaiki = 0;
$panjangTerbaiki = 0.0;
$tolakAirYatim = 0;
$untaiSebelum = count($jalur);
if ($lebarYatim > $lebarMaks) {
    $sebelumAir = $tolakAir;
    foreach ($lubangBaris as $id => $lubang) {
        $grup = [];
        foreach ($lubang as [$x, $y, $arah, $k]) {
            $p = pasanganTerdekat($kisiPasang, $segmenPasang, $komiSegmen, $x, $y, $lebarYatim,
                (int) $komi[$id], $arah, $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir,
                $tolakAir);
            if ($p === null) {
                continue;
            }
            $tepi[$id]['pasang'][$k] = true;
            $grup[(int) $komi[$p[3]]][] = [($x + $p[1]) / 2, ($y + $p[2]) / 2, $p[0], $id];
        }
        foreach ($grup as $lain => $titik) {
            if (count($titik) < 2) {
                continue;
            }
            $jalur[] = $titik;
            $titikTerbaiki += count($titik);
            $pasangan[min((int) $komi[$id], $lain) . ':' . max((int) $komi[$id], $lain)] = true;
            if (isset($sisa[$id])) {
                unset($sisa[$id]);
                $terpasang++;
                $terbaiki++;
                $panjangTerbaiki += $tepi[$id]['panjang'];
            }
        }
    }
    $tolakAirYatim = $tolakAir - $sebelumAir;
    $tolakAir = $sebelumAir;
    printf("lebar yatim: %d titik sampel yang tidak berpasangan dalam %s m kini berpasangan dalam"
        . " koridor <= %s m · %s untai baru · %d baris yang semula tanpa pasangan kini terpasang"
        . " (%s m) · %d titik tertolak uji air\n", $titikTerbaiki, angkaNum($lebarMaks, 1),
        angkaNum($lebarYatim, 1), count($jalur) - $untaiSebelum, $terbaiki,
        angkaNum($panjangTerbaiki, 1), $tolakAirYatim);
}

foreach ($sisa as $id => $sebab) {
    $t = $tepi[$id];
    $tunggal++;
    $panjangTunggal += $t['panjang'];
    $s = sampel($t['titik']);
    $jjarak = null;
    $jkosong = null;
    foreach ($s as [$x, $y, $arah]) {
        [$d, $k] = seberangTerdekat($kisiPasang, $segmenPasang, $komiSegmen, $x, $y, SEBERANG_UJI,
            (int) $komi[$id], $arah, $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir);
        if ($d !== null && ($jjarak === null || $d < $jjarak)) {
            $jjarak = $d;
        }
        if ($k !== null && ($jkosong === null || $k < $jkosong)) {
            $jkosong = $k;
        }
    }
    if ($jjarak === null) {
        $pitaTunggal['tidak ada sisi sejajar'] = ($pitaTunggal['tidak ada sisi sejajar'] ?? 0) + 1;
    } else {
        $nama = $jjarak <= 2 ? '<=2 m' : ($jjarak <= 4 ? '2-4 m'
            : ($jjarak <= $lebarMaks ? '4-' . angkaNum($lebarMaks, 0) . ' m'
                : angkaNum($lebarMaks, 0) . '-' . angkaNum(SEBERANG_UJI, 0) . ' m'));
        $pitaTunggal[$nama] = ($pitaTunggal[$nama] ?? 0) + 1;
    }
    if ($jjarak !== null && $jkosong === null) {
        $koridorTertutup++;
    }
    if ($sebab === 'setengah') {
        $setengah++;
    }
}
printf("sisi: %d komponen tepi · %d baris menyumbang titik tengah · %d baris tanpa pasangan sama"
    . " sekali (%s m panjangnya, %d di antaranya kandas karena ambang --bagi-pasang %s)\n", $sisi,
    $terpasang, $tunggal, angkaNum($panjangTunggal, 1), $setengah, angkaNum($bagiPasang, 2));
printf("       sisi tunggal: sisi sejajar terdekat %s · %d sisi sejajar tapi koridornya selalu"
    . " tertutup (bidang atau air)\n", json_encode($pitaTunggal, JSON_UNESCAPED_UNICODE), $koridorTertutup);
printf("koridor: %d pasangan sisi berbeda · %d untai titik tengah · titik sampel dengan kandidat"
    . " tertolak air: %d\n", count($pasangan), count($jalur), $tolakAir);

// Baris tepi yang menyentuh radius sebuah titik tanya: kunci, cara, panjang, dan
// sampel terdekat (jarak, x, y, arah). Dipakai --tanya dan --tanya-berkas.
$barisTanya = static function (float $tx, float $ty, float $tr) use ($tepi): array {
    $kumpul = [];
    foreach ($tepi as $id => $t) {
        $s = sampel($t['titik']);
        $jmin = INF;
        $uji = null;
        foreach ($s as [$x, $y, $arah]) {
            $d = hypot($x - $tx, $y - $ty);
            if ($d < $jmin) {
                $jmin = $d;
                $uji = [$x, $y, $arah];
            }
        }
        if ($jmin > $tr) {
            continue;
        }
        $kumpul[(int) $id] = [$t['kunci'], $t['cara'], $t['panjang'], $jmin, $uji, count($s)];
    }
    return $kumpul;
};

if ($tanya !== null) {
    $tolakAirSimbul = 0;
    [$tx, $ty, $tr] = $tanya;
    printf("%stanya: titik (%s, %s) radius %s m%s", PHP_EOL, angkaNum($tx, 2), angkaNum($ty, 2),
        angkaNum($tr, 1), PHP_EOL);
    foreach ($barisTanya($tx, $ty, $tr) as $id => [$kunci, $cara, $panjang, $jmin, $uji, $jumlahTitik]) {
        [$x, $y, $arah] = $uji;
        printf("  %-12s sisi %6d · %-12s · %6s m · sampel terdekat %5s m arah %5s%s", $kunci,
            (int) $komi[$id], $cara, angkaNum($panjang, 1), angkaNum($jmin, 2),
            angkaNum($arah, 1), PHP_EOL);
        foreach ([[$lebarMaks, 'ronde 1'], [$lebarYatim > $lebarMaks ? $lebarYatim : 12.0, 'lebar yatim'],
            [SEBERANG_UJI, 'uji seberang']] as [$r, $nama]) {
            $p = pasanganTerdekat($kisiPasang, $segmenPasang, $komiSegmen, $x, $y, $r, (int) $komi[$id],
                $arah, $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir, $tolakAirSimbul);
            [$dekat, $kosong] = seberangTerdekat($kisiPasang, $segmenPasang, $komiSegmen, $x, $y, $r,
                (int) $komi[$id], $arah, $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir);
            [$oleh] = $olehSeberang((int) $id, $r);
            $utama = $pilihUtama($oleh);
            printf("     %-13s %5s m : %s · seberang terdekat %s, yang koridornya kosong %s · %s\n",
                $nama, angkaNum($r, 1),
                $p === null ? 'tidak ada pasangan'
                    : 'pasangan ' . angkaNum($p[0], 2) . ' m pada sisi ' . (int) $komi[$p[3]],
                $dekat === null ? '-' : angkaNum($dekat, 2), $kosong === null ? '-' : angkaNum($kosong, 2),
                $utama === null ? 'tidak ada komponen pemenang'
                    : sprintf('pemenang sisi %d dengan %d dari %d titik', $utama, $oleh[$utama]['n'],
                        $jumlahTitik));
        }
        printf("     %s%s%s", isset($sisa[$id]) ? 'ronde 1 tanpa pasangan (' . $sisa[$id] . ')'
            : 'ronde 1 terpasang', PHP_EOL, PHP_EOL);
    }
}

if ($tanyaBerkas !== null) {
    $tolakAirSimbul = 0;
    foreach ($tanyaBerkas as [$nama, $tx, $ty, $tr]) {
        printf("%stanya berkas: %s(%s, %s) radius %s m%s", PHP_EOL, $nama === '' ? '' : $nama . ' ',
            angkaNum($tx, 2), angkaNum($ty, 2), angkaNum($tr, 1), PHP_EOL);
        foreach ($barisTanya($tx, $ty, $tr) as $id => [$kunci, $cara, $panjang, $jmin, $uji]) {
            [$x, $y, $arah] = $uji;
            [$oleh, $jumlahSampel] = $olehSeberang($id, $lebarMaks);
            $utama = $pilihUtama($oleh);
            $p = pasanganTerdekat($kisiPasang, $segmenPasang, $komiSegmen, $x, $y, $lebarYatim,
                (int) $komi[$id], $arah, $kisiPoligon, $poligon, $poligonBesar, $kisiAir, $segmenAir,
                $tolakAirSimbul);
            [$dekat, $kosong] = seberangTerdekat($kisiPasang, $segmenPasang, $komiSegmen, $x, $y,
                $lebarYatim, (int) $komi[$id], $arah, $kisiPoligon, $poligon, $poligonBesar, $kisiAir,
                $segmenAir);
            if (isset($sisa[$id])) {
                $ronde1 = 'tanpa pasangan (' . $sisa[$id] . ')';
            } elseif ($utama === null) {
                $ronde1 = 'terpasang';
            } else {
                $ronde1 = sprintf('menang sisi %d %d/%d titik', $utama, $oleh[$utama]['n'], $jumlahSampel);
            }
            printf("  %-12s sisi %6d · %-12s · %6s m · %5s m · %-26s · yatim %s · seberang %s,"
                . " kosong %s%s", $kunci, (int) $komi[$id], $cara, angkaNum($panjang, 1),
                angkaNum($jmin, 2), $ronde1,
                $p === null ? 'tidak ada' : angkaNum($p[0], 2) . ' m sisi ' . (int) $komi[$p[3]],
                $dekat === null ? '-' : angkaNum($dekat, 2), $kosong === null ? '-' : angkaNum($kosong, 2),
                PHP_EOL);
        }
    }
}

// 3. Renggut titik tengah dan rangkai menjadi rantai.
$raguSum = [];
$titikSum = [];
$jumlahSum = [];
$bebanSum = [];
$tepiSum = [];
$jumX = [];
$jumY = [];
$tali = [];
foreach ($jalur as $urutan) {
    $sebelum = null;
    foreach ($urutan as $p) {
        $nd = simpul($raguSum, $titikSum, (float) $p[0], (float) $p[1], RAGU_SUMBU);
        $jumlahSum[$nd] = ($jumlahSum[$nd] ?? 0) + 1;
        $bebanSum[$nd] = ($bebanSum[$nd] ?? 0.0) + (float) $p[2];
        $jumX[$nd] = ($jumX[$nd] ?? 0.0) + (float) $p[0];
        $jumY[$nd] = ($jumY[$nd] ?? 0.0) + (float) $p[1];
        $tepiSum[$nd][(int) $p[3]] = true;
        if ($sebelum !== null && $sebelum !== $nd) {
            $tali[$sebelum . ':' . $nd] = [$sebelum, $nd];
        }
        $sebelum = $nd;
    }
}
// Koordinat tiap simpul rantai = rata-rata semua titik tengah yang direnggut
// ke dalamnya, jadi sisi kiri dan kanan sebuah jalan lurus menyatu ke satu
// garis, bukan dua garis sejajar.
foreach ($titikSum as $nd => $p) {
    $titikSum[$nd] = [$jumX[$nd] / $jumlahSum[$nd], $jumY[$nd] / $jumlahSum[$nd]];
}
$adj = [];
foreach ($tali as [$a, $b]) {
    $adj[$a][$b] = true;
    $adj[$b][$a] = true;
}
$simpang = count(array_filter($adj, static fn(array $nb): bool => count($nb) >= 3));
printf("rangka: %d simpul · %d batang · %d simpul persimpangan (derajat >= 3)\n", count($titikSum),
    count($tali), $simpang);

$dipakai = [];
$jalurSum = [];
$jejak = static function (int $a, int $b) use (&$adj, &$dipakai): array {
    $out = [$a];
    while (true) {
        $out[] = $b;
        $dipakai[$a . ':' . $b] = true;
        $dipakai[$b . ':' . $a] = true;
        if (count($adj[$b] ?? []) !== 2) {
            break;
        }
        $berikut = null;
        foreach (array_keys($adj[$b]) as $k) {
            if ((int) $k !== $a && !isset($dipakai[$b . ':' . $k])) {
                $berikut = (int) $k;
                break;
            }
        }
        if ($berikut === null) {
            break;
        }
        $a = $b;
        $b = $berikut;
    }
    return $out;
};
foreach ($adj as $a => $nb) {
    foreach (array_keys($nb) as $b) {
        if (isset($dipakai[$a . ':' . $b])) {
            continue;
        }
        $jalurSum[] = $jejak((int) $a, (int) $b);
    }
}

// 3b. Jahit celah. Dua ujung buntu yang saling menunggu adalah satu ruas jalan
// yang terdigitasi terputus, bukan persimpangan: ruas sepanjang celah itu
// ditambahkan ke untaian. Ujung di persimpangan (derajat != 2) tidak disentuh,
// dua untaian yang berbagi simpul tidak disambung, dan sebuah ujung hanya
// boleh dipakai sekali, jadi jahitan tidak pernah melingkar atau memotong
// persimpangan. Titik tengah ruas jahitan diuji terhadap permukaan bidang dan
// koridor air dengan syarat yang sama seperti pasangan tepi di atas.
$jahitRuat = 0;
$jahitPanjang = 0.0;
$ujngSum = 0;
$tolakJahit = ['tidak searah' => 0, 'masuk bidang' => 0, 'koridor air' => 0,
    'sudah terjahit' => 0, 'berbagi simpul' => 0];
if ($jahitMaks > 0.0) {
    // Beda dua arah MAJEN dalam derajat, 0..180; bedaArah() membuang informasi
    // ini karena pasangan tepi memang tidak punya sisi maju.
    $bedaMaju = static function (float $a, float $b): float {
        $d = abs(fmod($a - $b, 360.0));
        return $d > 180.0 ? 360.0 - $d : $d;
    };
    $ujng = [];
    $kisiUjng = [];
    foreach ($jalurSum as $i => $nd) {
        foreach ([0, 1] as $p) {
            $depan = $p === 0 ? $nd[0] : $nd[count($nd) - 1];
            if (count($adj[$depan] ?? []) !== 1) {
                continue;
            }
            $sebelah = $p === 0 ? $nd[1] : $nd[count($nd) - 2];
            $x = (float) $titikSum[$depan][0];
            $y = (float) $titikSum[$depan][1];
            $id = count($ujng);
            $ujng[$id] = ['n' => $depan, 'r' => $i, 'x' => $x, 'y' => $y,
                'arah' => rad2deg(atan2($y - (float) $titikSum[$sebelah][1],
                    $x - (float) $titikSum[$sebelah][0]))];
            $kisiUjng[(int) floor($x / $jahitMaks) . ':' . (int) floor($y / $jahitMaks)][$id] = true;
        }
    }
    $ujngSum = count($ujng);
    $calon = [];
    foreach ($ujng as $id => $u) {
        $gx = (int) floor($u['x'] / $jahitMaks);
        $gy = (int) floor($u['y'] / $jahitMaks);
        for ($ix = $gx - 1; $ix <= $gx + 1; $ix++) {
            for ($iy = $gy - 1; $iy <= $gy + 1; $iy++) {
                foreach (array_keys($kisiUjng[$ix . ':' . $iy] ?? []) as $jd) {
                    if ($jd <= $id || $ujng[$jd]['r'] === $u['r']) {
                        continue;
                    }
                    $w = $ujng[$jd];
                    $dx = $w['x'] - $u['x'];
                    $dy = $w['y'] - $u['y'];
                    $d = hypot($dx, $dy);
                    if ($d <= RAGU_SUMBU || $d > $jahitMaks) {
                        continue;
                    }
                    $maju = rad2deg(atan2($dy, $dx));
                    if ($bedaMaju($u['arah'], $maju) > SUDUT_JAHIT
                        || $bedaMaju($w['arah'], $maju + 180.0) > SUDUT_JAHIT) {
                        $tolakJahit['tidak searah']++;
                        continue;
                    }
                    if (diPoligon(($u['x'] + $w['x']) / 2, ($u['y'] + $w['y']) / 2, $kisiPoligon,
                        $poligon, $poligonBesar)) {
                        $tolakJahit['masuk bidang']++;
                        continue;
                    }
                    if (diKoridorAir($kisiAir, $segmenAir, ($u['x'] + $w['x']) / 2,
                        ($u['y'] + $w['y']) / 2, $maju)) {
                        $tolakJahit['koridor air']++;
                        continue;
                    }
                    $calon[] = ['d' => $d, 'a' => $id, 'b' => $jd];
                }
            }
        }
    }
    usort($calon, static fn(array $p, array $q): int => $p['d'] <=> $q['d']);
    $parentJahit = range(0, max(0, count($jalurSum) - 1));
    $untai = $jalurSum;
    $anggota = [];
    foreach ($jalurSum as $i => $nd) {
        foreach ($nd as $n) {
            $anggota[$i][(int) $n] = true;
        }
    }
    $terjahit = [];
    $untaianSebelum = count($jalurSum);
    foreach ($calon as $c) {
        $ra = induk($parentJahit, (int) $ujng[$c['a']]['r']);
        $rb = induk($parentJahit, (int) $ujng[$c['b']]['r']);
        $na = (int) $ujng[$c['a']]['n'];
        $nb = (int) $ujng[$c['b']]['n'];
        if (isset($terjahit[$na]) || isset($terjahit[$nb])) {
            $tolakJahit['sudah terjahit']++;
            continue;
        }
        if (count(array_intersect_key($anggota[$ra], $anggota[$rb])) > 0) {
            $tolakJahit['berbagi simpul']++;
            continue;
        }
        $a = $untai[$ra];
        $b = $untai[$rb];
        if ((int) $a[0] === $na) {
            $a = array_reverse($a);
        }
        if ((int) $b[count($b) - 1] === $nb) {
            $b = array_reverse($b);
        }
        $untai[$ra] = array_merge($a, $b);
        $untai[$rb] = null;
        $anggota[$ra] += $anggota[$rb];
        unset($anggota[$rb]);
        gabung($parentJahit, $ra, $rb);
        $terjahit[$na] = true;
        $terjahit[$nb] = true;
        $jahitRuat++;
        $jahitPanjang += $c['d'];
    }
    if ($jahitRuat > 0) {
        $rantai = [];
        foreach ($untai as $nd) {
            if ($nd !== null) {
                $rantai[] = $nd;
            }
        }
        $jalurSum = $rantai;
    }
    $untaianSesudah = count($jalurSum);
} else {
    $untaianSebelum = count($jalurSum);
    $untaianSesudah = $untaianSebelum;
}
printf("jahit celah: %d ruas jahitan sepanjang %s m (celah > %s m dan <= %s m, beda arah majen <= %s"
    . " derajat) · untaian %d -> %d dari %d ujung buntu · ditolak %s\n", $jahitRuat,
    angkaNum($jahitPanjang, 1), angkaNum(RAGU_SUMBU, 1), angkaNum($jahitMaks, 1),
    angkaNum(SUDUT_JAHIT, 0), $untaianSebelum, $untaianSesudah, $ujngSum,
    json_encode($tolakJahit, JSON_UNESCAPED_UNICODE));

// 4. Satu sumbu per jalur.
$buangPendekSumbu = 0;
$fitur = [];
$pitaLebar = ['<=1 m' => 0, '1-2 m' => 0, '2-4 m' => 0, '4-6 m' => 0, '>6 m' => 0];
$jarakTepi = [];
$jarakHilang = 0;
foreach ($jalurSum as $nd) {
    $titik = [];
    foreach ($nd as $i) {
        $p = $titikSum[$i];
        if ($titik !== [] && hypot($p[0] - $titik[count($titik) - 1][0], $p[1] - $titik[count($titik) - 1][1])
            < 0.01) {
            continue;
        }
        $titik[] = $p;
    }
    $p = count($titik) > 1 ? panjang($titik) : 0.0;
    if ($p < MIN_PANJANG) {
        $buangPendekSumbu++;
        continue;
    }
    $beban = 0.0;
    $n = 0;
    $tepinya = [];
    $labelnya = [];
    foreach ($nd as $i) {
        $beban += (float) ($bebanSum[$i] ?? 0.0);
        $n += (int) ($jumlahSum[$i] ?? 0);
        foreach (array_keys($tepiSum[$i] ?? []) as $id) {
            $tepinya[(int) $id] = true;
        }
    }
    foreach ($tepinya as $id) {
        $l = $tepi[$id]['label'];
        if ($l !== null && $l !== '') {
            $labelnya[$l] = ($labelnya[$l] ?? 0) + 1;
        }
    }
    $lebar = $n > 0 ? $beban / $n : 0.0;
    foreach ($titik as $tp) {
        $dekat = jarakSegmen($kisiPasang, $segmenPasang, (float) $tp[0], (float) $tp[1],
            $lebarMaks + 2.0, SEL_PASANG);
        if ($dekat === null) {
            $jarakHilang++;
        } else {
            $jarakTepi[] = $dekat[0];
        }
    }
    $asal = array_values(array_unique(array_map(
        static fn(int $id): string => $tepi[$id]['asal'], array_keys($tepinya))));
    $cara = array_count_values(array_map(
        static fn(int $id): string => $tepi[$id]['cara'], array_keys($tepinya)));
    arsort($labelnya);
    $pita = match (true) {
        $lebar <= 1 => '<=1 m',
        $lebar <= 2 => '1-2 m',
        $lebar <= 4 => '2-4 m',
        $lebar <= 6 => '4-6 m',
        default => '>6 m',
    };
    $pitaLebar[$pita]++;
    $fitur[] = ['panjang' => $p, 'titik' => $titik, 'lebar' => $lebar,
        'tepinya' => array_map(static fn(int $id): string => $tepi[$id]['kunci'], array_keys($tepinya)),
        'tepi_id' => array_keys($tepinya),
        'label' => array_key_first($labelnya), 'asal' => count($asal) > 1 ? 'campur' : $asal[0],
        'cara' => $cara, 'pita' => $pita];
}
usort($fitur, static fn(array $a, array $b): int => $b['panjang'] <=> $a['panjang']);

// 5. Keluaran tepi. Baris yang tidak dipakai satu pun sumbu (tidak muncul di
// `tepi_all`) adalah tepian bidang tanah yang ikut terklaim; --tepi=terpakai
// membuangnya supaya lapis tepi jalan tidak terbaca sebagai peta bidang.
$idTerpakai = [];
foreach ($fitur as $f) {
    foreach ($f['tepi_id'] as $id) {
        $idTerpakai[(int) $id] = true;
    }
}
ksort($idTerpakai);
if ($tepiKeluar === 'terpakai' && $fitur === []) {
    echo 'PERINGATAN: tidak ada sumbu, jadi tidak ada baris yang bisa disebut terpakai;'
        . ' berkas tepi ditulis utuh (--tepi=semua).' . PHP_EOL;
    $tepiKeluar = 'semua';
}
$tepiTerbit = $tepiKeluar === 'terpakai' ? array_intersect_key($tepi, $idTerpakai) : $tepi;

// 5b. Siklus lepas. Baris yang di tahap klaim masih bersambung ke jaringan bisa
// menjadi keliling bidang tanah yang hanya menempel di satu simpul sesudah
// baris tak terpakai dibuang. Saringan ini berjalan pada himpunan terbit, bukan
// pada klaim; baris yang dibuang juga dicabut dari `tepi_all` setiap sumbu dan
// sumbu yang kehilangan seluruh rujukannya ikut dibuang. Geometri sumbu tidak
// dihitung ulang, sama seperti --tepi=terpakai.
$lepasKomponen = 0;
$lepasBuang = [];
$lepasPanjang = 0.0;
$lepasCara = [];
$lepasAsal = [];
$lepasSumbu = 0;
$lepasTersentuh = 0;
$lepasRujukan = 0;
if ($cincinKeluar === 'hapus') {
    [$lepasBuang, $lepasKomponen] = siklusLepasTepi($tepiTerbit);
    foreach ($lepasBuang as $id => $_) {
        $t = $tepi[$id];
        $lepasPanjang += (float) $t['panjang'];
        $lepasCara[$t['cara']] = ($lepasCara[$t['cara']] ?? 0) + 1;
        $lepasAsal[$t['asal']] = ($lepasAsal[$t['asal']] ?? 0) + 1;
    }
    if ($lepasBuang !== []) {
        $tepiTerbit = array_diff_key($tepiTerbit, $lepasBuang);
        $fiturSisa = [];
        foreach ($fitur as $f) {
            $tepinya = [];
            $idTetap = [];
            $tercabut = false;
            foreach ($f['tepi_id'] as $q => $id) {
                if (isset($lepasBuang[(int) $id])) {
                    $lepasRujukan++;
                    $tercabut = true;
                    continue;
                }
                $tepinya[] = $f['tepinya'][$q];
                $idTetap[] = (int) $id;
            }
            if ($tercabut) {
                $lepasTersentuh++;
            }
            if ($tepinya === []) {
                $lepasSumbu++;
                continue;
            }
            $f['tepinya'] = $tepinya;
            $f['tepi_id'] = $idTetap;
            $fiturSisa[] = $f;
        }
        $fitur = $fiturSisa;
    }
    ksort($lepasCara);
    ksort($lepasAsal);
    printf("saring siklus lepas: %d siklus menempel <= 1 simpul · %d baris dibuang (%s m; %s) ·"
        . " %d rujukan dicabut dari %d sumbu · %d sumbu tanpa rujukan ikut dibuang · tersisa %d baris tepi\n",
        $lepasKomponen, count($lepasBuang), angkaNum($lepasPanjang, 1),
        json_encode($lepasCara, JSON_UNESCAPED_UNICODE), $lepasRujukan, $lepasTersentuh, $lepasSumbu,
        count($tepiTerbit));
} else {
    echo 'saring siklus lepas: dimatikan (--cincin=simpan).' . PHP_EOL;
}

$daftar = [];
foreach ($fitur as $i => $f) {
    $daftar[] = [
        'type' => 'Feature',
        'properties' => [
            'kode' => sprintf('SUMBU-%04d', $i + 1),
            'sumber' => 'label-jalan',
            'panjang_m' => round($f['panjang'], 2),
            'lebar_m' => round($f['lebar'], 2),
            'jumlah_bagian' => count($f['titik']),
            'jumlah_tepi' => count($f['tepinya']),
            'tepi_all' => $f['tepinya'],
            'asal' => $f['asal'],
            'cara_tepi' => $f['cara'],
            'cara' => 'sumbu',
            'label' => $f['label'],
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $f['titik']],
    ];
}

$berkasTepi = $susunTepi($tepiTerbit);
$panjangTepiTerbit = array_sum(array_map(
    static fn(array $f): float => (float) $f['properties']['panjang_m'], $berkasTepi));
$panjangTepiBuang = 0.0;
$terpakaiBuang = 0;
$terpakaiPanjang = 0.0;
foreach ($tepi as $id => $t) {
    $p = round($t['panjang'], 2);
    if (!isset($idTerpakai[$id])) {
        $terpakaiBuang++;
        $terpakaiPanjang += $p;
        $panjangTepiBuang += $p;
    } elseif (isset($lepasBuang[(int) $id])) {
        $panjangTepiBuang += $p;
    }
}
if ($jejakPasang !== null) {
    $baris = ['kunci,bagi_pasang,panjang_m,asal,cara'];
    foreach ($tepiTerbit as $id => $t) {
        $m = $t['pasang'] ?? [];
        $baris[] = sprintf('%s,%.3f,%.2f,%s,%s', $t['kunci'],
            $m === [] ? 0.0 : array_sum($m) / count($m), (float) $t['panjang'], $t['asal'], $t['cara']);
    }
    if (file_put_contents($jejakPasang, implode(PHP_EOL, $baris) . PHP_EOL) === false) {
        galat('gagal menulis --jejak-pasang: ' . $jejakPasang);
    }
    printf("jejak pasang: %d baris terbit -> %s\n", count($tepiTerbit), $jejakPasang);
}

sort($jarakTepi);
$medan = static fn(array $a): float => $a === [] ? 0.0 : (float) $a[(int) floor(count($a) / 2)];
$panjangSum = array_sum(array_map(static fn(array $f): float => $f['panjang'], $fitur));
$lebarSum = $panjangSum > 0.0
    ? array_sum(array_map(static fn(array $f): float => $f['lebar'] * $f['panjang'], $fitur)) / $panjangSum
    : 0.0;
printf("sumbu: %d segmen · %s m · lebar timbang panjang %s m · pita lebar %s\n", count($fitur),
    angkaNum($panjangSum, 1), angkaNum($lebarSum, 2), json_encode($pitaLebar, JSON_UNESCAPED_UNICODE));
printf("       dibuang pendek (< %s m): %d · jarak titik sumbu ke tepi: median %s m, maksimum %s m"
    . " (%d titik diuji, %d tidak menemukan tepi dalam %s m)\n", angkaNum(MIN_PANJANG, 1),
    $buangPendekSumbu, angkaNum($medan($jarakTepi), 2),
    angkaNum((float) ($jarakTepi === [] ? 0.0 : max($jarakTepi)), 2), count($jarakTepi), $jarakHilang,
    angkaNum($lebarMaks + 2.0, 1));
printf("%-26s %5d fitur · %10s m · dibuang %d baris / %s m · %s\n", BERKAS_TEPI . '.geojson',
    count($berkasTepi), angkaNum($panjangTepiTerbit, 1), count($tepi) - count($tepiTerbit),
    angkaNum($panjangTepiBuang, 1), $terapkan ? 'ditulis' : 'uji kering');

if ($rinci) {
    foreach (array_slice($fitur, 0, 40) as $i => $f) {
        printf("  SUMBU-%04d %8s m · lebar %5s m · %2d titik · %3d tepi · %s\n", $i + 1,
            angkaNum($f['panjang'], 1), angkaNum($f['lebar'], 2), count($f['titik']),
            count($f['tepinya']), (string) ($f['label'] ?? '(tanpa label)'));
    }
}

if ($terapkan) {
    tulisBerkas(BERKAS_TEPI, $berkasTepi);
    tulisBerkas(BERKAS_SUMBU, $daftar);
    foreach ([BERKAS_TEPI, BERKAS_SUMBU] as $berkas) {
        $cek = geojsonBaca(DIR_LAPISAN . '/' . $berkas . '.geojson');
        $jenis = array_count_values(array_map(
            static fn(array $f): string => (string) ($f['geometry']['type'] ?? '?'), $cek));
        $ringkas = [];
        foreach ($jenis as $tipe => $n) {
            $ringkas[] = $tipe . ' ' . $n;
        }
        printf("validasi baca ulang %-22s %d fitur · %s\n", $berkas . '.geojson', count($cek),
            implode(', ', $ringkas));
    }
}
printf("%-26s %5d fitur · %10s m · %s\n", BERKAS_SUMBU . '.geojson', count($daftar),
    angkaNum($panjangSum, 1), $terapkan ? 'ditulis' : 'uji kering');

if (!$terapkan) {
    echo PHP_EOL . 'uji kering: tidak ada berkas yang ditulis; pakai --terapkan.' . PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------------------
// metadata.json
// ---------------------------------------------------------------------------

$berkasMeta = DIR_LAPISAN . '/metadata.json';
$meta = is_file($berkasMeta) ? (array) json_decode((string) file_get_contents($berkasMeta), true) : [];
if ($meta === []) {
    galat('metadata.json tidak dapat dibaca: ' . $berkasMeta);
}

$meta['layers'][JUDUL_TEPI] = [
    'features' => count($berkasTepi),
    'types' => ['line' => count($berkasTepi)],
    'file' => BERKAS_TEPI . '.geojson',
    'panjang_m' => (int) round($panjangTepiTerbit),
    'derived_from' => $deriveTepi
        ? 'kandidat 020100 (garis terbuka dan cincin poligon) serta cincin tabel bidang yang membawa label'
            . ' berawalan JALAN (kelas dibaca dari teks, bukan dari berkas asal) serta pita sejajarnya'
        : 'lapis ' . BERKAS_TERBIT . '.geojson yang sudah terbit, dibaca apa adanya sebagai tepi',
    'ambang' => ['benih_m' => $benihMaks, 'benih_yatim_m' => $benihYatim, 'pita_m' => PITA,
        'sudut_derajat' => SUDUT, 'putaran' => PUTARAN,
        'bagi_sejajar' => BAGI_SEJAJAR, 'bagi_koridor_kosong' => BAGI_KOSONG,
        'min_panjang_m' => MIN_PANJANG, 'langkah_sampel_m' => LANGKAH, 'bagi_ulang_cincin' => BAGI_ULANG,
        'eksklusif' => $eksklusif],
    'label' => $labelJalan,
    'permukaan_bidang' => ['sumber' => 'tabel bidang (status <> dihapus)', 'persil' => $jumlahPersil,
        'segmen_tepi' => count($segmenTepi)],
    'saring' => ['aturan' => $tepiKeluar, 'klaim_tahap1' => $jumlahTepiKlaim,
        'keliling_satu_baris' => ['aturan' => $cincinKeluar, 'tutup_m' => CINCIN_TUTUP,
            'toleransi_m' => TOLERANSI_TEPI, 'bagi_keliling_persil' => CINCIN_TUTUP_BAGI,
            'tertutup_diuji' => $tutupDiuji, 'dibuang' => $tutupBuang,
            'dibuang_panjang_m' => round($tutupPanjang, 1), 'cara' => $tutupCara,
            'asal' => $tutupAsal],
        'cincin' => ['aturan' => $cincinKeluar, 'simpul_bulat_desimal' => CINCIN_BULAT,
            'uji_dalam_m' => CINCIN_UJI, 'bagi_dalam_persil' => CINCIN_BAGI,
            'siklus_diuji' => $cincinDiuji, 'siklus_dibuang' => $cincinKomponen,
            'dibuang' => count($cincinBuang),
            'dibuang_panjang_m' => round($cincinPanjang, 1), 'cara' => $cincinCara, 'asal' => $cincinAsal],
        'pembatas_persil' => ['aturan' => $pembatasKeluar, 'offset_m' => PERSIL_OFFSET,
            'bagi_sampel' => PERSIL_BAGI, 'dibuang' => $pembatasBuang,
            'dibuang_panjang_m' => round($pembatasPanjang, 1), 'asal' => $pembatasAsal],
        'siklus_lepas' => ['aturan' => $cincinKeluar, 'simpul_persimpangan_derajat' => 3,
            'simpul_bulat_desimal' => CINCIN_BULAT, 'siklus' => $lepasKomponen,
            'dibuang' => count($lepasBuang), 'dibuang_panjang_m' => round($lepasPanjang, 1),
            'rujukan_dicabut' => $lepasRujukan, 'sumbu_tersentuh' => $lepasTersentuh,
            'sumbu_dibuang' => $lepasSumbu,
            'cara' => $lepasCara, 'asal' => $lepasAsal],
        'dibuang' => count($tepi) - count($tepiTerbit),
        'dibuang_panjang_m' => round($panjangTepiBuang, 1)],
    'asal' => array_count_values(array_column($tepiTerbit, 'asal')),
    'cara' => array_count_values(array_column($tepiTerbit, 'cara')),
];

$meta['layers'][JUDUL_SUMBU] = [
    'features' => count($daftar),
    'types' => ['line' => count($daftar)],
    'file' => BERKAS_SUMBU . '.geojson',
    'panjang_m' => (int) round($panjangSum),
    'derived_from' => 'rata-rata dua sisi tepi ' . BERKAS_TEPI . '.geojson yang saling berhadapan di dalam'
        . ' koridor kosong; satu garis per segmen antara dua ujung, persimpangan, atau ruas jahitan',
    'ambang' => ['lebar_min_m' => LEBAR_MIN, 'lebar_maks_m' => $lebarMaks,
        'lebar_yatim_m' => $lebarYatim,
        'sudut_pasang_derajat' => SUDUT_PASANG, 'bagi_pasang' => $bagiPasang, 'ragu_sisi_m' => RAGU_SISI,
        'ragu_sumbu_m' => RAGU_SUMBU, 'min_panjang_m' => MIN_PANJANG, 'sumber_tepi' => $sumberTepi,
        'jahit_m' => $jahitMaks, 'sudut_jahit_derajat' => SUDUT_JAHIT],
    'jahit' => ['ruas' => $jahitRuat, 'panjang_m' => round($jahitPanjang, 1),
        'ujung_buntu' => $ujngSum, 'untaian' => $untaianSebelum . ' -> ' . $untaianSesudah,
        'ditolak' => $tolakJahit],
    'sisi' => ['komponen' => $sisi, 'terpasang' => $terpasang, 'tunggal' => $tunggal,
        'tunggal_setengah' => $setengah, 'koridor_tertutup' => $koridorTertutup,
        'seberang_terdekat' => $pitaTunggal, 'tunggal_panjang_m' => (int) round($panjangTunggal),
        'koridor' => count($pasangan), 'uji_seberang_m' => SEBERANG_UJI,
        'yatim_terpasang' => $terbaiki, 'yatim_titik' => $titikTerbaiki,
        'yatim_untai' => count($jalur) - $untaiSebelum,
        'yatim_panjang_m' => round($panjangTerbaiki, 1),
        'yatim_tolak_air' => $tolakAirYatim],
    'lebar_m' => ['timbang_panjang' => round($lebarSum, 2), 'pita' => $pitaLebar],
    'verifikasi' => ['titik_diuji' => count($jarakTepi), 'tanpa_tepi' => $jarakHilang,
        'ke_tepi_median_m' => round($medan($jarakTepi), 2),
        'ke_tepi_maksimum_m' => round((float) ($jarakTepi === [] ? 0.0 : max($jarakTepi)), 2)],
    'cara' => ['sumbu' => count($daftar)],
];

$catatan = [];
foreach ((array) ($meta['notes'] ?? []) as $nn) {
    if (!str_starts_with((string) $nn, JUDUL_TEPI) && !str_starts_with((string) $nn, JUDUL_SUMBU)) {
        $catatan[] = $nn;
    }
}
$catatan[] = sprintf(
    '%s dan %s dibuat tools/garis-jalan-label.php pada %s. Tahap 1 mengklaim tepi dari label berawalan'
    . ' JALAN (benih <= %.1f m, pita <= %.1f m dengan beda arah <= %.0f derajat, koridor >= %.0f%% kosong'
    . ' terhadap gabungan cincin 020100 dan %d persil tabel bidang yang hanya dibaca). Tahap 2'
    . ' merata-ratakan dua sisi yang berhadapan <= %.1f m menjadi satu sumbu per segmen: %d baris tepi'
    . ' menyumbang titik tengah dan lahir %d segmen sumbu sepanjang %s m dari %d komponen sisi; %d baris'
    . ' (%s m) tidak punya sisi seberang sama sekali sehingga tidak bisa disumbukan. %d untaian dijahit'
    . ' dengan %d ruas sepanjang %s m (celah <= %.1f m, beda arah majen <= %.0f derajat) menjadi %d'
    . ' untaian. Koordinat sumbu baru, rata-rata dua tepi, bukan titik turunan 020100. Keduanya hanya'
    . ' lapisan referensi hasil turunan dan sudah dipasang sebagai pil di panel Peta Induk.',
    JUDUL_TEPI, JUDUL_SUMBU, date('c'), $benihMaks, PITA, SUDUT, BAGI_KOSONG * 100, $jumlahPersil,
    $lebarMaks, $terpasang, count($daftar), angkaNum($panjangSum, 1), $sisi, $tunggal,
    angkaNum($panjangTunggal, 1), $untaianSebelum, $jahitRuat, angkaNum($jahitPanjang, 1), $jahitMaks,
    SUDUT_JAHIT, $untaianSesudah
);
if ($tepiKeluar === 'terpakai') {
    $catatan[] = sprintf(
        JUDUL_TEPI . ': berkas %s hanya memuat baris yang menjadi bahan sebuah sumbu; dari %d baris'
        . ' sisa sesudah saringan, %d baris (%s m) tidak muncul di `tepi_all` satu pun sumbu sehingga'
        . ' dibuang 15 Nov 2026 atas permintaan "hapus garis tepi jalan yang membentuk peta bidang".'
        . ' Baris itu tepian bidang tanah yang ikut terklaim lewat benih atau pita, bukan sisi koridor'
        . ' jalan. Jumlah sumbu tidak berubah sebab penyaringan terjadi sesudah tahap 2.',
        BERKAS_TEPI . '.geojson', count($tepi),
        $terpakaiBuang, angkaNum($terpakaiPanjang, 1)
    );
}
if ($cincinKeluar === 'hapus' && $tutupBuang > 0) {
    $catatan[] = sprintf(
        JUDUL_TEPI . ': %d dari %d baris tepi yang titik awal dan akhirnya sama (%s m) dibuang karena'
        . ' >= %.0f%% titik sampel keliling SATU persil jatuh <= %.2f m dari baris itu: baris semacam'
        . ' ini keliling sebuah bidang tanah yang ditulis sebagai satu polilin, bukan tepi jalan. Uji'
        . ' per baris ini tidak memakai topologi, jadi tetap mengenali keliling yang bertemu keliling'
        . ' lain di satu simpul. Saringan berjalan sebelum saringan cincin topologis, sebelum saringan'
        . ' pembatas persil, dan sebelum tahap 2, jadi sumbu dibangun ulang tanpa baris itu.',
        $tutupBuang, $tutupDiuji, angkaNum($tutupPanjang, 1), CINCIN_TUTUP_BAGI * 100, TOLERANSI_TEPI
    );
}
if ($cincinKeluar === 'hapus' && $cincinBuang !== []) {
    $catatan[] = sprintf(
        JUDUL_TEPI . ': %d dari %d siklus sederhana (%d baris, %s m) dibuang karena titik %s m ke arah'
        . ' dalam siklus itu berada di dalam persil pada >= %.0f%% titik uji — siklus seperti itu'
        . ' keliling sebuah bidang tanah, bukan tepi jalan. Cabang yang menempel dipangkas lebih dulu,'
        . ' jadi keliling tetap dikenali walau ada garis lain yang bersambung padanya; siklus yang'
        . ' mengurung ruang kosong (dua tepi jalan yang bertemu di ujung gang) dibiarkan. Saringan'
        . ' berjalan sebelum saringan pembatas persil dan sebelum tahap 2, jadi sumbu dibangun ulang'
        . ' tanpa baris itu dan `tepi_all` tidak menunjuk baris yang hilang. Ujung baris dianggap'
        . ' satu simpul bila jaraknya <= %s m.',
        $cincinKomponen, $cincinDiuji, count($cincinBuang), angkaNum($cincinPanjang, 1),
        angkaNum(CINCIN_UJI, 1), CINCIN_BAGI * 100, angkaNum(10 ** -CINCIN_BULAT, 2)
    );
}
if ($cincinKeluar === 'hapus' && $lepasBuang !== []) {
    $catatan[] = sprintf(
        JUDUL_TEPI . ': %d baris terbit (%s m) dicabut karena membentuk %d siklus yang menempel ke'
        . ' jaringan jalan pada paling banyak satu simpul — keliling bidang tanah atau lingkaran'
        . ' buntu, bukan jalan lewat. Ujung baris dibulatkan %s m, daun dipangkas, lalu simpul yang'
        . ' pada inti bersambung >= 3 dibuang; komponen sisa yang setiap simpulnya bersambung 2'
        . ' adalah siklus lepas. Saringan berjalan SESUDAH --tepi=terpakai, jadi keliling yang baru'
        . ' terpisah dari jaringan sesudah baris tak terpakai dibuang ikut tertangkap. %d rujukan'
        . ' dicabut dari `tepi_all` dan %d sumbu yang kehilangan seluruh rujukannya ikut dibuang;'
        . ' geometri sumbu tidak dihitung ulang, sama seperti penyaringan --tepi=terpakai.',
        count($lepasBuang), angkaNum($lepasPanjang, 1), $lepasKomponen,
        angkaNum(10 ** -CINCIN_BULAT, 2), $lepasRujukan, $lepasSumbu
    );
}
if ($pembatasKeluar === 'hapus' && $pembatasBuang > 0) {
    $catatan[] = sprintf(
        JUDUL_TEPI . ': %d baris klaim tahap 1 (%s m) dibuang karena kedua sisinya, pada offset tegak'
        . ' lurus %.1f m, jatuh di persil yang berbeda pada >= %.0f%% titik sampel: garis itu pembatas'
        . ' dua bidang tanah, bukan tepi jalan. Saringan berjalan sebelum tahap 2, jadi sumbu dibangun'
        . ' ulang tanpa baris itu dan `tepi_all` tidak menunjuk baris yang hilang.',
        $pembatasBuang, angkaNum($pembatasPanjang, 1), PERSIL_OFFSET, PERSIL_BAGI * 100
    );
}
$meta['notes'] = $catatan;
$meta['derived_at'] = date('c');
if (file_put_contents($berkasMeta, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
    galat('gagal menulis metadata.json');
}
echo "metadata.json diperbarui.\n";
