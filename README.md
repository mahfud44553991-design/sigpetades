# SIPETADES — SIG Pertanahan & PBB Desa Gedong

Sistem informasi pertanahan dan pajak bumi bangunan untuk tingkat desa: peta
bidang, Buku C Desa, objek pajak/SPPT, peralihan hak, dan pelaporan sengketa,
dengan satu basis data sebagai sumber kebenaran.

Ditulis sebagai PHP prosedural tanpa framework, dengan MariaDB sebagai penyimpan
geometri maupun data tabel. Tidak ada langkah pembangunan (`build`) dan tidak
ada dependensi Node — yang perlu dipasang hanya PHP, MySQL, dan Apache.

---

## 1. Cara kerja datanya

GeoJSON lapangan **dimuat sekali ke MariaDB**, lalu seluruh halaman dan endpoint
API hanya membaca tabel. File `.geojson` tidak dibaca ulang setiap permintaan.

```
blok_peta.geojson ─┐
bidang_tanah.geojson ├──[ tools/impor.php ]──► MariaDB (EPSG:4326) ──► halaman web + api/*.php
bidang_lain.geojson ┘                                                      ▲
_lama/sigtanahdesa.sql ────────────────────────────────────────────────────┘
files/Data C Desa/*.pdf ──(indeks)──► tabel buku_c ──► dokumen.php (proksi)
files/master peta/MASTER.dxf ──(batas, gg, nama, nis, nib, daftar, dxf-persil, kuning)──► tabel yang sama
```

Konsekuensinya: perubahan geometri dilakukan lewat basis data, dan berkas
GeoJSON hanya perlu diimpor ulang bila data lapangan memang diperbarui.

---

## 2. Kebutuhan

| Bagian | Minimum | Dipakai di sini |
| --- | --- | --- |
| PHP | 8.1 + ekstensi `pdo_mysql` | 8.2.12 (XAMPP) |
| MariaDB | 10.4 (fungsi ruangnya dipakai) | 10.4.32 (XAMPP) |
| Apache | `mod_rewrite` disarankan aktif | XAMPP |

Perintah geometri yang dipakai: `ST_GeomFromText(wkt, 4326)`, `ST_AsGeoJSON`,
`ST_AsText`, `ST_X`, `ST_Y`. Urutan koordinat selalu **bujur, lintang** seperti
GeoJSON. Attribute SRID tidak dipakai karena tidak tersedia di MariaDB 10.4 —
bila tabel dipindahkan ke server lain, sistem koordinatnya harus diperiksa ulang
secara manual.

---

## 3. Instalasi

### 3.1 Letakkan berkas

Salin seluruh folder ini ke `htdocs` XAMPP, misalnya
`D:\xampp\htdocs\sipetades`. Folder `files\`, `storage\`, `app\`, `sql\`,
`tools\`, dan `_lama\` harus ikut, karena `.htaccess` sudah menutupnya dari
HTTP.

### 3.2 Buat konfigurasi

```
copy app\config.example.php app\config.php
```

Isi kunci `db` pada `app/config.php`. Nilai environment menang atas isi berkas,
sehingga deployment bisa diubah tanpa menyentuh disk:

```
SIG_DB_HOST  SIG_DB_PORT  SIG_DB_NAME  SIG_DB_USER  SIG_DB_PASSWORD
```

`app/config.php` **jangan** ikut dibagikan atau di-commit — isinya kredensial.

### 3.3 Buat basis data

```
D:\xampp\mysql\bin\mysql.exe -u root < sql\01_skema.sql
```

`01_skema.sql` membuat database `sipetades_gedong` lalu membangun 18 tabel beserta
data acuan (dusun, jenis hak, jenis penggunaan, jenis peralihan, setelan desa).
Akun Super Admin dibuat terpisah sesudahnya — lewat `setup.php` atau
`02_akun_awal.sql`; lihat 3.5.

> Skema sengaja dibuat sebagai satu berkas utuh dan **jatuh bangun**:
> `01_skema.sql` mengandung `DROP TABLE` untuk semua tabel aplikasi.
> Menjalankannya ulang = menghapus seluruh data. Cadangkan dulu
> (bagian 9) kalau basis datanya sudah dipakai sungguhan.

### 3.4 Impor data lapangan

Dari shell:

```
D:\xampp\php\php.exe tools\impor.php semua
```

atau dari browser setelah masuk sebagai Super Admin: **Manajemen akun →
Pemasangan data** (`pasang.php`). Keduanya memakai `tools/lib-impor.php` yang
sama, jadi hasilnya identik.

Super Admin juga dapat menjalankan impor GeoJSON **Bidang tanah** dari panel
Daftar bidang. Aksi itu tidak mereset tabel: kode yang sudah ada dilewati.

Menu **Daftar bidang hasil digitasi (CSV)** juga tersedia di halaman Pemasangan
data. Letakkan CSV pada `files\data excel\` dengan kolom `id_bidang` dan
`geojson_boundary` (GeoJSON `Polygon`), lalu pilih berkas tersebut di menu.
Impor ini menambahkan kode baru tanpa menghapus bidang yang sudah ada; baris
duplikat dilewati, dan bidang baru ditandai `perlu_verifikasi` tanpa blok
sampai petugas menetapkannya.

Perintah yang tersedia:

```
php tools\impor.php semua        blok + bidang + objek pajak + indeks Buku C
php tools\impor.php blok         hanya blok_peta.geojson
php tools\impor.php bidang       bidang_tanah.geojson + bidang_lain.geojson
php tools\impor.php sppt [csv]   CSV SPPT (bawaan: berkas pertama di files\data excel)
php tools\impor.php sppt-dump    objek pajak dari _lama\sigtanahdesa.sql
php tools\impor.php buku-c       daftarkan isi folder files\Data C Desa
php tools\impor.php gg           kumpulkan penanda tanah gang dari MASTER.dxf
php tools\impor.php gang <berkas>  impor poligon gang menjadi Hak Pakai
php tools\impor.php gabung <asal> <tujuan>
                               pindahkan satu blok ke blok lain
php tools\impor.php batas [blok]
                               pasang garis luar blok dari MASTER.dxf
php tools\impor.php kira [blok...]  buat batas dari gabungan bidangnya
php tools\impor.php pindah <asal> <tujuan>
                               pindahkan bidang yang di luar batas blok asal
php tools\impor.php ubah-blok <blok> <kode...>
                               tentukan blok beberapa bidang tertentu
php tools\impor.php rapikan <blok...>
                               potong batas blok sampai ke tepi jalan OSM
php tools\impor.php duplikat     tandai bidang yang poligonnya kembar (lihat 3.9)
php tools\impor.php kembar-tepat tandai kembar yang lolos ST_Equals (lihat 3.9)
php tools\impor.php dxf-persil   pasang persil DXF yang flag-nya tak tertutup (lihat 3.19)
php tools\impor.php kuning       sejajarkan tepi bidang berpolyline kuning + pasang yang yatim (lihat 3.22)
php tools\impor.php luas         hitung ulang luas_m2 yang melenceng dari geometri (lihat 3.21)
php tools\impor.php koreksi-blok pindahkan bidang berblok buatan alat ke garis blok yang menaunginya (lihat 3.21)
php tools\impor.php taut-blok   tautkan setiap objek pajak ke blok peta dari NOP
php tools\impor.php nama        ambil nama pemegang dari label peta master (lihat 3.12)
php tools\impor.php nama-verifikasi
                               isi nama bidang perlu_verifikasi lintas lapisan (lihat 3.12)
php tools\impor.php nis         ambil NIS alas hak dari label peta master (lihat 3.13)
php tools\impor.php kode-nis    ganti kode DXF-... menjadi HM-<nis> (lihat 3.13)
php tools\impor.php nib         isi bidang.nib dari label lapisan NIB HM (lihat 3.14)
php tools\impor.php daftar      isi bidang.nomor_daftar dari lapisan 080201 (lihat 3.14)
php tools\impor.php hak-milik   tetapkan jenis hak Hak Milik bagi bidang perlu_verifikasi (lihat 3.15)
php tools\impor.php calon <blok> daftar bidang seblok dengan calon NOP terdekat
php tools\impor.php taut <kode> <nop>
                               jodohkan satu bidang dengan satu NOP (lihat 3.11)
```

Tambahkan `--reset` untuk mengosongkan tabel terkait sebelum impor. Perintah
`semua` — dan `php tools\impor.php` yang **tidak** menyebut perintah, yang berarti
`semua` — juga berhenti pada mode periksa tanpa `--terapkan`: rantai itu mengimpor ulang
`blok_peta.geojson` + `bidang_tanah.geojson` + `bidang_lain.geojson`, dan sejak
[§3.19](#319-persil-dxf-yang-flag-nya-tidak-pernah-ditutup) sumber geometri sejatinya
MASTER.dxf, jadi menjalankannya menimbun bidang lama di atas data yang benar.
Perintah
`gang`, `gabung`, `batas`, `kira`, `pindah`, `ubah-blok`, `rapikan`, `duplikat`,
`kembar-tepat`, `dxf-persil`, `kuning`, `luas`, `koreksi-blok`, `taut-blok`, `nama`, `nama-verifikasi`,
`nis`, `kode-nis`, `nib`, `daftar`, `hak-milik` dan `taut`
hanya memeriksa kecuali ditambah `--terapkan`. Perintah `nis`, `nib`, `daftar` dan
`hak-milik`
hanya menyentuh bidang `perlu_verifikasi` kecuali ditambah `--semua`. Perintah `batas` menerima `--tegas`
untuk memasang satu garis luar tanpa menyisipkan bidang, dan `kira` menerima
`--tepat` untuk mengikuti gabungan bidang tanpa penyangga sama sekali (lihat 3.8).
`dxf-persil` menerima `--celah=<meter>` (toleransi ujung poligon terbuka, bawaan 0,01 m), `--minimal=<m²>` (luas terkecil yang dipasang, bawaan 20 m²) dan `--tanpa-blok`
(pasang juga calon yang tidak menemukan bloknya, dengan `blok_id` kosong — lihat 3.19).
`kuning` menerima `--tarik=<meter>` (sejauh mana sebuah sudut boleh dipindahkan ke garis
tetangga, bawaan 0,15 m) dan `--master=<berkas>` — lihat 3.22.
`luas` menerima `--toleransi=<bagian>` (bawaan 0,05) — lihat 3.21.
Dijalankan tanpa argumen, `kira` berlaku ke semua blok. Perintah `batas` dan `gg`
membaca `files\master peta\MASTER.dxf`, kecuali ditambah
`--master=<berkas>`. Berkas **DWG** bisa dipakai langsung: ia dikonversi dulu
menjadi `files\master peta\<nama>-dari-dwg.dxf` dengan LibreDWG portabel di
`tools\vendor\libredwg\` (GPL-3, unduh `libredwg-*-win64.zip` dari
<https://github.com/LibreDWG/libredwg/releases> lalu isi folder itu). Konversi
dipakai ulang selama berkas aslinya tidak berubah, dan MASTER.dxf yang lama tidak
pernah tertimpa.

```
D:\xampp\php\php.exe tools\impor.php batas --master="files\master peta\MASTER.dwg"
```

### 3.5 Buat Super Admin pertama

Dua cara, pilih salah satu:

- **Lewat peramban** (disarankan) — buka `http://localhost/sipetades/setup.php`.
  Halaman ini hanya aktif selama belum ada akun Super Admin; begitu akun pertama
  dibuat, ia menutup dirinya.
- **Lewat SQL** — buat hash sendiri lebih dulu, lalu tempel ke `sql/02_akun_awal.sql`:

  ```
  D:\xampp\php\php.exe -r "echo password_hash('SandiRahasia12345', PASSWORD_DEFAULT);"
  D:\xampp\mysql\bin\mysql.exe -u root sipetades_gedong < sql\02_akun_awal.sql
  ```

  Berkas itu tidak berisi sandi apa pun. Selama variabel `@hash` di dalamnya
  masih kosong, skrip tidak menulis satu baris pun — jadi menjalankannya dua
  kali, atau sebelum hash diisi, aman. `INSERT ... WHERE NOT EXISTS` juga menjaga
  agar Super Admin yang sudah ada tidak pernah tertimpa.

Kata sandi minimal 12 karakter dengan huruf dan angka, disimpan sebagai hash
bcrypt. Tidak ada sandi bawaan di mana pun, jadi tidak ada yang perlu diganti
buru-buru — tetapi tetap pilih sandi yang tidak dipakai di tempat lain.

### 3.6 Peta gang (GG) menjadi Hak Pakai

Di `MASTER.dxf`, tanah gang **tidak** digambar sebagai poligon. Yang ada hanya
19 label teks `GG` dan `TANAH GG` pada layer `tn_nama` dan `BATAS DESA` —
titik penanda di tengah gang. Karena itu prosesnya dua langkah.

Langkah satu, kumpulkan penandanya:

```
D:\xampp\php\php.exe tools\impor.php gg
```

Skrip membaca `files\master peta\MASTER.dxf`, memindahkan tiap label ke WGS84,
lalu mencari nomor blok tempat titik itu jatuh (`ST_Contains` pada tabel
`blok`). Hasilnya dua berkas di `files\peta gang\`: `peta_gg.geojson` (titik,
sudah membawa `blok`, `nomor_blok`, dan `status_hak`) dan `peta_gg.csv`.
Basis data tidak diubah.

Titik yang tidak jatuh di dalam batas blok mana pun tetap diberi blok terdekat
plus jaraknya, ditandai `perlu_dicek = ya`, dan tidak langsung dipakai.

Langkah dua, digitasi bentuk gang dari titik acuan itu di QGIS atau AutoCAD,
simpan sebagai GeoJSON poligon, dan pastikan setiap feature punya properti:

```
id_bidang   kode unik, mis. GG-01
blok        kode blok hasil langkah satu, mis. "BLOK 4"
luas_m2     opsional
```

Lalu periksa dan terapkan:

```
D:\xampp\php\php.exe tools\impor.php gang "files\peta gang\gang-poligon.geojson"
D:\xampp\php\php.exe tools\impor.php gang "files\peta gang\gang-poligon.geojson" --terapkan
```

Tanpa `--terapkan` tidak ada satu baris pun yang ditulis. Berkas yang belum
lengkap (tanpa `id_bidang`, tanpa `blok`, blok tidak dikenal, atau geometri
bukan poligon) ditolak seluruhnya dengan daftar bagian yang salah.

Yang masuk ke basis data saat `--terapkan`: `bidang` baru bertipe `lain`,
bersumber `GG`, dan satu baris `hak` per bidang berisi `jenis_hak` **HP (Hak
Pakai)** dengan pemegang `Pemerintah Desa Gedong`. Nomor blok ikut terisi dari
kode blok pada feature. Impor ini menambah data, tidak menimpa bidang yang
sudah ada; menjalankannya dua kali tidak menghasilkan duplikat karena
`id_bidang` yang sama dilewati.

Sebelum menyalakan QGIS, ada baiknya mengecek apakah gangnya memang belum
terpetakan. Kartu **Penanda massal** di halaman Peta Induk ([§3.72](#372-penanda-massal-di-peta-induk-10-nov-2026))
dapat memuat 19 titik hasil langkah satu dan menurunkan bidangnya langsung dari
garis 020100 di sekitarnya. Diukur pada 10 Nov 2026: hanya **1** dari 19
penanda gang yang belum punya bidang (`DXF-020100-B92755`, 260,10 m²), **16**
sudah tertutup ≥95% bidang yang ada, dan **2** tidak menemukan muka tertutup.
Bidang hasil jalan ini bertipe `tanah` berstatus `perlu_verifikasi` — bukan
Hak Pakai `GG` seperti `impor.php gang` — jadi tetap perlu ditetapkan blok dan
jenis haknya.

### 3.7 Menggabungkan dua blok

Blok hasil perkiraan batas kadang ternyata bagian dari blok tetangga. Contoh
yang sudah dijalankan di Desa Gedong:

```
D:\xampp\php\php.exe tools\impor.php gabung 11 13          # periksa dulu
D:\xampp\php\php.exe tools\impor.php gabung 11 13 --terapkan
```

Dalam satu transaksi, `gabung` memindahkan seluruh `bidang` milik blok sumber
ke blok tujuan, menggabungkan batas keduanya (`ST_Union`, dipasang kembali
dengan SRID 4326 karena MariaDB 10.4 tidak punya `ST_SetSRID`), lalu membuang
baris blok sumber. Nomor blok sumber menjadi kosong — itu memang maksudnya,
blok yang sudah dihapus tidak boleh dipakai lagi.

Sebelum ada perubahan, salinan kedua blok (WKT batas, metadata, dan daftar
bidang) ditulis ke `storage\gabung-blok-<waktu>.json`, dan kejadian lengkapnya
masuk ke `audit_log`. Untuk membatalkan, baca berkas itu: pasang kembali WKT
blok tujuan, sisipkan baris blok sumber, lalu kembalikan `blok_id` bidang yang
terdaftar.

Batas blok tujuan dibiarkan berstatus `batas_tersedia` seperti semula walaupun
sebagian kecil wilayahnya kini berasal dari perkiraan; sumber perkiraannya ada
di berkas cadangan dan `audit_log`. Perlu diingat: `impor.php blok --reset`
membuat ulang blok dari `blok_peta.geojson`, sehingga blok yang sudah
digabungkan muncul kembali. Jalankan ulang `gabung` sesudah impor blok.

### 3.8 Batas blok dari peta dasar

Di `MASTER.dxf`, sebagian blok hanya digambar **garis luarnya saja** — tidak ada
batas tiap bidang di dalamnya. Garis luar itu ada pada lapisan `BATAS BLOK`.
Daftarkan dulu mana yang tersedia:

```
D:\xampp\php\php.exe tools\impor.php batas
```

Lapisan ini memuat 20 garis luar. Sebuah garis luar dikenali sebagai milik blok
yang paling banyak menampung bidang di dalamnya, jadi garis yang tidak sejalan
dengan sebaran bidang tidak ikut terpakai. Pasang untuk blok tertentu:

`MASTER.dwg` keluaran 19 Oktober 2026 (format AC1024) isinya **sama persis**
dengan `MASTER.dxf` yang sudah dipakai: jumlah maupun koordinat 9.471 poligon dan
26.112 label cocok satu-satu, dan 20 garis `BATAS BLOK`-nya identik. Satu-satunya
perbedaan ada pada objek non-gambar (1.668 `ACAD_PROXY_OBJECT` gaya Map 3D yang
tidak ikut terkonsversi), jadi tidak ada yang perlu diperbarui di peta dari
berkas itu. Verifikasinya ada di `_lama\scratch\sidik-dwg.php`.

Yang kecil di lapisan `BATAS BLOK` memang bukan garis utuh: BLOK 2 punya tiga
potongan (19.164 m², 18.274 m², 72.051 m²) yang bersama-sama hanya menampung 59
dari 213 bidangnya, dan BLOK 8, 19, 21, 22 tidak punya garis sama sekali.

```
D:\xampp\php\php.exe tools\impor.php batas 3 17 23 13 --terapkan
```

Batas yang dipasang = garis luar itu **digabung dengan bidang milik blok yang
jatuh di luarnya**, sehingga tidak ada bidang yang kehilangan blok. Bloknya
menjadi `batas_tersedia = 1`, `batas_perkiraan = 0`, dan `sumber_perkiraan`
mencatat asal-usulnya. Bidang milik blok lain yang jatuh di dalam garis luar
**tidak dipindahkan**; statusnya saja yang diubah menjadi
`perlu_verifikasi` supaya muncul di daftar kerja.

Bila tampilan peta lebih diutamakan daripada menampung semua bidang, tambahkan
`--tegas` supaya batasnya murni satu garis luar saja:

```
D:\xampp\php\php.exe tools\impor.php batas 3 --tegas --terapkan
```

Dengan `--tegas` tidak ada bidang yang disisipkan, sehingga batas blok tampil
sebagai satu garis tertutup (`ST_NumGeometries(geom) = 1`). Konsekuensinya,
bidang anggota yang berada di luar garis tetap berstatus anggota blok yang sama
tetapi tidak lagi terlihat di dalam batasnya. Angka bidang yang tertinggal di
luar itu dicetak pada mode periksa, jadi selalu cek dulu sebelum `--terapkan`.

Bila bidang yang tertinggal di luar itu memang milik blok tetangga, pindahkan
sekali saja dengan perintah `pindah`. Perintah ini mengambil seluruh bidang
anggota yang pusatnya berada di luar batas bloknya sendiri lalu mengubah
`blok_id`-nya:

```
D:\xampp\php\php.exe tools\impor.php pindah 3 9 --terapkan
```

Ini yang dipakai untuk BLOK 3: 41 bidang sisipan dipindah menjadi anggota
BLOK 9, sehingga BLOK 3 tinggal 299 bidang yang semuanya berada di dalam satu
garisnya. Batas BLOK 9 sendiri lalu dipasang dari garis luar `MASTER.dxf`
(234.930 m², satu bagian, menampung 165 bidang lamanya). Catatan penting: 41
bidang yang dipindah itu berada di **celah antara** kedua garis luar, jadi
pusatnya tidak jatuh di dalam garis BLOK 9 maupun BLOK 3. Bila ingin semuanya
terlihat ikut tergambar sebagai BLOK 9, jalankan `batas 9` tanpa `--tegas` —
batasnya menjadi 8 bagian dengan luas 284.125 m².

Bila yang perlu diluruskan hanya beberapa bidang, sebut kodenya satu per satu
lewat `ubah-blok`. Perintah ini tidak peduli posisi geometrisnya — dipakai
kalau penentuan blok sudah diputuskan petugas lapangan:

```
D:\xampp\php\php.exe tools\impor.php ubah-blok 7 DXF-020100-1028 HM-0430 --terapkan
```

Kode yang tidak ada dilaporkan sebagai `tidak_dikenal`, lengkap dengan dugaan
kode yang angka-angkanya cocok, jadi salah ketik tidak berubah menjadi
perubahan yang sunyi. Empat bidang `DXF-020100-1028`, `HM-0430`, `HM-0431` dan
`HM-0432` dengan cara ini dipindah dari BLOK 9 ke BLOK 7; keempatnya berada di
tepat luar batas BLOK 7 — `HM-0430` dan `HM-0432` menempelnya (0 m), sisanya
berjarak 12,8 m dan 22,5 m.

Blok yang tidak punya garis luar di peta dasar (8, 19, 21 dan 22) batasnya
dibuat dari gabungan seluruh bidang anggotanya. Awalnya gabungan itu diselubungi
15 m supaya kluster bidang yang tidak saling menyentuh tampak satu kesatuan:

```
D:\xampp\php\php.exe tools\impor.php kira 8 19 21 22 --terapkan
```

Dengan `--tepat` penyangganya nol, jadi batasnya persis mengikuti bentuk bidang
anggotanya; tanpa argumen perintah ini berlaku ke semua blok:

```
D:\xampp\php\php.exe tools\impor.php kira --tepat --terapkan
```

**Kondisi basis data sekarang (6 Nov 2026): 19 blok memakai garis luar MASTER.dxf
sebagai satu garis tutup** (`sumber_perkiraan = garis luar MASTER.dxf`,
`batas_tersedia = 1`, `ST_NumGeometries = 1`), dipasang dengan
`batas 3 15 25 17 18 7 9 5 4 16 6 14 12 26 24 20 10 13 23 --tegas --terapkan`
pada 19 Okt 2026. **BLOK 19, 21 dan 22 sudah dibentuk ulang menjadi satu bagian**
pada 6 Nov 2026 dengan penyangga yang dipotong batas blok resmi di sekelilingnya
(rinciannya di 3.8a). Anggota BLOK 19 / 21 / 22 sekarang **274 / 556 / 124**
bidang setelah 18 bidang di luar batas dipindahkan ke blok yang memuatnya (3.8b),
batas BLOK 21 diperluas memuat `G01684` (3.8g), lalu jalur antara BLOK 21 dan
BLOK 23 diisi sampai garis BLOK 23 (3.8h). **BLOK 21 ditetapkan sebagai batas
blok tersedia** pada 6 Nov 2026 14:13 (`batas_tersedia = 1`,
`batas_perkiraan = 0`, estimasi dikosongkan); **BLOK 19 dan BLOK 22 menyusul
pada 14:23**, sehingga **seluruh 24 blok** kini `batas_perkiraan = 0`.
**BLOK 2** masih berupa gabungan persis bidang anggotanya
(`sumber_perkiraan = gabungan bidang anggota`). BLOK 8 awalnya sama, lalu
batas geometrinya **dikosongkan** 7 Nov 2026 (`geom = NULL`, 3.8k) dan
**dibuat ulang sebagai batas perkiraan** pukul 12:24 (3.8l), lubang
1.558,48 m² di dalamnya **ditutup** pukul 12:37 (3.8m), lalu batas itu
**ditetapkan sebagai batas blok tersedia** pukul 13:18 (3.8o). Kini **24
blok** bergeometri dan **seluruhnya** `batas_tersedia = 1` /
`batas_perkiraan = 0`. Anggota BLOK 8 **272 bidang** setelah `G01129`
dipindahkan dari BLOK 9 (3.8n).

Alasan penyaringannya: garis luar hanya dipasang pada blok yang bidangnya ikut
tertutup ≥80 %. BLOK 2 tidak dipasang karena garis luar yang cocok ke blok itu
hanya memuat 25 dari 213 bidangnya (12 %) dan luasnya 58 % lebih kecil dari
gabungan bidangnya — jelas bukan batasnya. BLOK 8 memang tidak punya garis luar
di peta dasar; BLOK 19, 21 dan 22 pun tidak, jadi batasnya dibentuk dari bidang
anggota (3.8a).

BLOK 2 dan 8 **ditinggalkan dengan sengaja**, bukan terlewat: satu-satunya cara
otomatis yang tersedia untuk memaksanya jadi satu garis terbukti menambah tanah
yang tidak dimiliki bidang mana pun (lihat dua cara yang gagal di bawah). Bila
nanti garis nyanya sudah didigitasi dari peta lapangan, pasang lewat
`impor.php batas 2 8 --tegas --terapkan` seperti 19 blok lainnya.

Langkah sebelumnya, ketika kedua puluh empat blok sempat dijadikan gabungan
persis (348 bagian, tidak ada satu pun blok satu garis), masih bisa dikembalikan
per blok lewat `storage\batas-kira-<blok>-20261019-1827*.json`.

Perbandingan yang diukur sebelum memutuskan (`ST_NumGeometries` mengembalikan 0
untuk poligon tunggal, jadi "0 bagian" berarti satu garis tutup):

| aturan | total bagian | blok satu garis | luas | bidang di luar |
|---|---|---|---|---|
| gabungan persis, 0 m | 348 | 0 | 3.736.339 m² | 175 |
| gabungan + penyangga 3 m | 59 | 12 | 4.103.313 m² | 79 |
| gabungan + penyangga 5 m | 49 | 13 | 4.293.593 m² | 53 |
| gabungan + penyangga 8 m | 41 | 14 | 4.561.761 m² | 36 |

Sebagian bidang selalu tetap berada di luar batasnya: pusat bidang yang bentuknya
cekung sering jatuh di luar bidang itu sendiri, bukan hanya karena penyangganya
kurang lebar.

Dua cara lain untuk memaksa satu garis luar sudah diukur dan **gagal**, jadi
jangan dicoba lagi:

- **Cangkang cembung** (`ST_ConvexHull`, tersedia di MariaDB 10.4 dan instan)
  memang memberi satu garis untuk setiap blok dan hanya menyisakan 17 bidang di
  luar, tetapi luas seluruh blok naik dari 3.736.339 ke 6.360.027 m² — **bertambah
  70 %** tanah yang tidak dimiliki bidang mana pun. Terparah BLOK 17 (+289 %),
  BLOK 14 (+156 %), BLOK 9 (+149 %), BLOK 20 (+115 %).
- **Penutupan morfologi** (`ST_Buffer` keluar lalu masuk, mis. +15 m/−15 m) bukannya
  menyatukan bagian malah menghancurkannya: BLOK 14 dengan 16 bagian menghasilkan
  **2.630 bagian** dan WKT 497 KB. Busur hasil `ST_Buffer` MariaDB terlalu kasar,
  jadi penyangga masuk memotong pinggirannya sendiri jadi serpihan. Cara yang
  sama juga pernah menghasilkan 1.717 bagian pada BLOK 3.

Batas yang sudah terpasang masih bisa dirapikan supaya berhenti di **tepi
jalan**. MASTER.dxf tidak menyimpan bentuk jalan sama sekali — lapisan
`BATAS JALAN` hanya berisi 620 teks label tanpa geometri — jadi garis tengah
jalan diambil dari OpenStreetMap dan disimpan di
`files\master peta\jalan_osm.json` (Unduh dari Overpass API, bbox Desa Gedong,
`way["highway"]`, `out geom tags`). Tiap ruas diselubungi setengah lebar
menurut kelasnya (`primary` 5,5 m sampai `footway` 0,8 m), koridor hasilnya
dikurangkan dari batas blok:

```
D:\xampp\php\php.exe tools\impor.php rapikan 21 22 23 --terapkan
```

Yang terpotong hanya bagian koridor yang benar-benar masuk ke dalam blok, jadi
jalan di luar blok tidak mengubah apa pun. Serpihan hasil potongan di bawah
500 m² dibuang karena terlalu kecil untuk dianggap bagian blok. Hasilnya pada
pemakaian pertama:

| blok | ruas | terpotong | bagian | bidang di luar |
|---|---|---|---|---|
| BLOK 21 | 16 | 14.432 m² | 1 → 9 | 2 (sebelumnya 1) |
| BLOK 22 | 18 | 8.735 m² | 1 → 6 | 9 (sebelumnya 1) |
| BLOK 23 | 38 | 6.092 m² | 14 → 10 | 7 (sebelumnya 6) |

Blok 21 dan 22 menjadi beberapa bagian karena jaringan jalan memang memotong
sebaran bidangnya; menahan potongan hanya di pita tepi (15–25 m) justru
menghasilkan 41–139 serpihan, jadi koridor penuh yang dipakai. Kedelapan belas
bidang yang tinggal di luar batas baru sudah berstatus `perlu_verifikasi`
sebelumnya, jadi tidak ada bidang aktif yang kehilangan batas. `sumber_perkiraan`
menyebut asal sebelumnya lalu `+ tepi jalan OSM`.

Keenam perintah menulis cadangan sebelum mengubah apa pun —
`storage\garis-luar-<blok>-<waktu>.json`,
`storage\batas-kira-<blok>-<waktu>.json`,
`storage\pindah-<blok-asal>-ke-<blok-tujuan>-<waktu>.json`,
`storage\ubah-blok-ke-<blok>-<waktu>.json` atau
`storage\rapikan-jalan-<blok>-<waktu>.json` (berisi WKT batas lama, daftar bidang
sisipan, bidang yang ditandai, daftar bidang beserta `blok_id` lamanya, atau
ruas dan kelas jalan yang memotong) —
dan mencatat perubahannya ke `audit_log`.

### 3.8a BLOK 19, 21 dan 22 dibentuk ulang (6 Nov 2026)

Ketiga blok ini tidak punya garis luar di peta dasar, jadi batasnya berasal dari
bidang anggotanya. Ketika ribuan bidang lapisan `020100` diaktifkan 5 Nov 2026,
keanggotaan ketiga blok naik (365/485/135 bidang) sementara batasnya masih
gabungan bidang lama — akibatnya ratusan bidang berada di luar batasnya.
Perbaikannya dua tahap:

1. **Anggota dirapikan.** 79 bidang dipindahkan ke blok yang menaunginya:
   75 bidang yang titik tengahnya di dalam batas blok resmi lain
   (73 → BLOK 23, 2 → BLOK 20) dan 4 serpihan yang seluruh areanya di dalam
   BLOK 23/24. Sisanya menjadi 304 / 471 / 131 bidang (`impor.php ubah-blok`).
2. **Batas dibentuk ulang menjadi satu bagian.** Gabungan bidang anggota
   diselubungi penyangga 25 m (BLOK 19), 10 m (BLOK 21) dan 15 m (BLOK 22),
   lalu dipotong batas blok resmi yang bersinggungan supaya tepinya berimpit
   dengan garis blok sebelahnya. Bagian terbesar diambil sebagai satu-satunya
   bagian; BLOK 19 memerlukan 25 m karena kelompok bidangnya baru menyatu pada
   penyangga sebesar itu. Ketiga batas perkiraan juga saling dipotong — blok
   dengan penyangga lebih rapat memegang pita tanah yang diperebutkan — sehingga
   tidak ada tumpang-tindih di antara ketiganya.

| blok | anggota | penyangga | luas batas | berimpit dengan |
|---|---|---|---|---|
| BLOK 19 | 304 | 25 m | 196.958 m² | 14 (722 m), 18 (284 m), 20 (835 m), 23 (13 m), 24 (614 m) |
| BLOK 21 | 471 | 10 m | 183.106 m² | 20 (743 m) |
| BLOK 22 | 131 | 15 m | 84.293 m² | 23 (79 m) |

Bidang yang tetap di luar batas baru: BLOK 19 **9** bidang (gugus terpisah
±2.087 m², 146 m dari anggota terdekat), BLOK 22 **9** bidang, BLOK 21 **0**.
Sebagian besar sudah `perlu_verifikasi`; lima bidang aktif BLOK 19 dan satu
bidang aktif BLOK 22 ikut di luar karena bentuknya cekung (titik tengahnya sudah
jatuh di luar bidangnya sendiri sebelum penyangga dihitung). Dua di antaranya
kembar persis lintas blok — `DXF-020100-2562` = `DXF-020100-G01456` dan
`DXF-020100-2563` = `DXF-020100-G01455` — sehingga satu salinan selalu berada di
luar salah satu blok sampai petugas memilih salinan yang dibuang lewat
`impor.php duplikat <kode>`. Kedelapan belas bidang itu diselesaikan pada 3.8b.

Cadangan WKT lama per blok ada di `storage\batas-kira-BLOK-{19,21,22}-20261106-1115*.json`,
daftar pemindahan di `D:\backup\sigpetades\blok-20261106\rencana-pindah-20261106.tsv`,
dan WKT hasilnya di folder yang sama.

Catatan: perintah `kira` bawaan belum bisa dipakai untuk ketiga blok ini.
`wktGabung()` dapat mengembalikan `GEOMETRYCOLLECTION` dan
`wktKeMultipolygon()` menolaknya, sehingga `impor.php kira 19` berhenti dengan
"Batas blok harus poligon". Pembentukan ulang di atas memakai skrip sementara
yang membuang komponen garis/titik dari hasil gabungan.

### 3.8b Bidang di luar batas dipindahkan (6 Nov 2026)

Delapan belas bidang yang tercatat di luar batas pada 3.8a diselesaikan dengan
memindahkan bidang ke blok yang benar-benar memuatnya, bukan dengan memperbesar
batas. Untuk BLOK 19 jalan itu tidak mungkin: kesembilan bidangnya berada
**121,6 m** di seberang BLOK 20 (blok resmi) dan 100% di dalam batas BLOK 21,
jadi batas BLOK 19 tidak dapat meraihnya tanpa menembus BLOK 20.

- BLOK 19 → BLOK 21: 9 bidang (`DXF-020100-2925`, `-2939`, `-2941`, `-2944`,
  `G02014`, `G02028`, `G02179`, `G02184`, `G03188`), seluruh luasnya di BLOK 21.
- BLOK 22 → BLOK 21: 6 bidang (`DXF-020100-2562`, `-2563`, `HM-1313`,
  `HM-1314`, `HM-1320`, `HM-1343`); pusatnya di BLOK 21 dan 58–100% luasnya
  sudah di sana.
- Tetap di BLOK 22: 3 bidang (`DXF-020100-0011` 99,7%, `G02144` dan `HM-1410`
  100% luasnya di dalam BLOK 22). Titik tengah ketiganya jatuh di luar bidangnya
  sendiri karena bentuknya cekung, jadi angka "di luar" pada 3.8a adalah
  kekeliruan ukur titik tengah, bukan bidang yang benar-benar di luar.
- Sekalian dikoreksi: `DXF-020100-G02059` (100% di dalam BLOK 20 resmi, tetapi
  tercatat BLOK 21) dipindah ke BLOK 20; `HM-1321` (95% di BLOK 21, tercatat
  BLOK 22) dipindah ke BLOK 21. Suntingan manual petugas pukul 11:27 yang
  memindahkan `DXF-020100-G01684` dari BLOK 19 ke BLOK 21 dikembalikan ke
  BLOK 19 karena bidang itu 100% di dalam BLOK 19 dan 54 m dari BLOK 21
  (audit #2904 dan #2907). Petugas mengulangi pemindahan itu pukul 12:28
  (audit #2914); geometrinya lalu dirapikan dulu di 3.8f sebelum batas diputuskan.

Keanggotaan akhir: BLOK 19 **295**, BLOK 20 **364**, BLOK 21 **486**, BLOK 22
**124** bidang. Ketiga batas tetap **satu bagian** dan tidak bertumpang tindih
(0 m²), jadi jumlah bidang, hak, dan audit tidak berubah selain 5 baris audit
pemindahan (#2905–#2909).

Masih ada empat bidang yang sebagian kecilnya di luar batas bloknya (5–46%):
`HM-1313`/`HM-1314`/`HM-1320` (58–94% di BLOK 21) dan `G02459` (93,1% di
BLOK 22) melintang di garis batas karena batas mengikuti penyangga, bukan tepi
bidang. Dua bidang rusak (`G01708`, `G01451`) sudah diperbaiki di 3.8c, dan
`G02005` ternyata duplikat sehingga dihapus di 3.8d. Perbaikan keempat bidang
ini menuntut pembentukan ulang batas mengikuti tepi bidang — pekerjaan terpisah.

Cadangan: `D:\backup\sigpetades\blok-20261106\sebelum-pindah15-20261106.sql`
(diuji restore ke basis data sementara `uji_pindah15`: 8.104 bidang, 8.103 hak,
24 blok, 2.902 audit saat itu) dan `storage\ubah-blok-ke-BLOK-{19,20,21}-20261106-11*.json`.

### 3.8c Poligon rusak G01708 dan G01451 diperbaiki (6 Nov 2026)

Cincin kedua bidang ini berisi beberapa loop yang diulang dan saling menumpuk,
bukan sekadar bowtie: `DXF-020100-G01708` memuat loop `DXF-020100-2209` dua kali
ditambah `-2210` dan `-2203`, sedangkan `DXF-020100-G01451` memuat satu loop di
dalam loop lain. Karena itu luas gambarnya palsu — G01708 6.583,93 m² melebihi
convex hull-nya sendiri (5.698,16 m²). Geometri diganti dengan gabungan loop sah
(fill nonzero), lalu `luas_m2`, `pusat_lon`, dan `pusat_lat` disesuaikan:

- `G01708`: 6.583,93 → **4.339,14 m²** (cincin 12 titik)
- `G01451`: 746,53 → **576,45 m²** (cincin 14 titik)

Keduanya kini tidak melebihi convex hull dan tidak ada segmen bersilangan
(0 persilangan; `ST_Intersection(geom, geom)` sama dengan luas gambar). Audit
#2910 dan #2911; jumlah bidang, hak, dan blok tidak berubah.

Catatan duplikat: bentuk hasil perbaikan G01708 menutup persis `-2209` + `-2210`
+ `-2203` (nama pemegangnya pun gabungan BADI / MUHLISIN / NUR MIFTAHUL
HASANAH), sedangkan G01451 menimpa `HM-1411` + `G02145`. Keduanya karena itu
kandidat hapus pada pekerjaan duplikat berikutnya, belum dihapus karena menunggu
keputusan petugas.

Cadangan: `D:\backup\sigpetades\poligon-20261106\sebelum-perbaiki-poligon-20261106.sql`
(diuji restore ke `uji_poligon_20261106`: 8.104 bidang, 8.103 hak, 2.907 audit;
`ST_Equals` cocok untuk kedua bidang). Diff live vs restore: tepat 2 baris
berubah, 0 bertambah/hilang.

### 3.8d Duplikat G02005 dihapus (6 Nov 2026)

Audit kelima bidang straddler menunjukkan hanya `G02005` yang benar-benar
duplikat: tumpang **99,9% timbal-balik** dengan `HM-199D K3` (BLOK 23,
`perlu_verifikasi`, Hak Milik, pemilik SUWARDI) dan `DXF-020100-0383`
(tanpa pemilik). Empat lainnya — `HM-1313`, `HM-1314`, `HM-1320`, `G02459` —
tidak punya kembaran (tumpang maksimal 0,3% selain poligon desa) dan tetap
dipertahankan sebagai bidang unik.

`G02005` (id 22222, 3.371,99 m², BLOK 19) dihapus permanen; hak 23521 ikut
terhapus lewat cascade. Tidak ada rujukan NOP, laporan, peralihan, atau Buku C
(0 baris), dan pemilik SUWARDI sudah tercatat pada keeper. Audit #2912.
Total menjadi **8.103 bidang, 8.102 hak**; 3.794 objek pajak tidak berubah;
BLOK 19 tinggal 294 anggota (geometri blok tidak berubah).

Cadangan: `D:\backup\sigpetades\hapus-g02005-20261106\sebelum-hapus-g02005-20261106.sql`
(diuji restore ke `uji_hapus_g02005`: 8.104 bidang, 8.103 hak, 2.909 audit;
`ST_Equals` cocok). Diff live vs restore: tepat 1 bidang + 1 hak hilang,
0 baris baru.

### 3.8e Lidah BLOK 19 dipotong mengikuti tepi bidang (6 Nov 2026)

Penyangga 25 m pada 3.8a meninggalkan **lidah** di barat daya BLOK 19 yang mengisi
cekungan BLOK 20 resmi dan menyentuh BLOK 21 sepanjang 155,7 m. Setelah ditelusuri,
tumpang tindih dengan kedua blok itu memang **0 m²** — keduanya hanya berimpit —
tetapi bentuk lidah itu menutupi tanah kosong yang secara resmi milik BLOK 20.
Sesuai keputusan petugas, lidah dipotong **mengikuti tepi bidang anggota**:

1. Ambil kotak selatan lintang **-7,0926** (bujur 110,0600–110,0652).
2. Di dalam kotak, batas dibentuk dari gabungan **11 bidang anggota** yang
   berpotongan dengan kotak, lalu dipotong BLOK 20 dan BLOK 21.
3. Di luar kotak, batas lama dipertahankan; keduanya digabung menjadi satu
   `MULTIPOLYGON`.

Hasil: luas **196.958,37 → 190.045,32 m²** (susut 6.913,06 m², −3,5%), tetap
**satu bagian**, tumpang tindih **0 m²** dengan seluruh 23 blok lain. Kontak
BLOK 21 turun **155,7 → 14,2 m** (hanya di tempat `DXF-020100-G02004` memang
melintas), kontak BLOK 20 tetap 834,9 m. Tidak ada bidang anggota yang terpotong
>1 m²; satu-satunya bidang yang masih di luar batas adalah **G02004 77,89 m²**
(3,4%), yang memang sudah di luar sebelum pemotongan. Jumlah bidang, hak, blok,
dan SPPT tidak berubah (8.103 / 8.102 / 24 / 3.794; BLOK 19 tetap 294 anggota).

`sumber_perkiraan` BLOK 19 menjadi `gabungan bidang anggota; lidah dipotong`,
audit **#2913**. WKT sebelum dan sesudah tersimpan di
`storage\potong-lidah-BLOK-19-20261106-120939.json`. Dump pra-potong
(`lidah-20261106\sebelum-potong-lidah-20261106.sql`, dulu diuji restore ke
`uji_lidah_20261106`: 24 blok, 8.103 bidang, 8.102 hak, 3.794 SPPT, audit #2912,
`ST_Equals` BLOK 19 cocok) **dihapus atas permintaan petugas**; sebagai gantinya
dibuat dump kondisi sesudah pemotongan:
`D:\backup\sigpetades\kondisi-20261106-1222\sigpetades_gedong-20261106-1222.sql`
(9.382.391 byte; diuji restore ke `uji_kondisi_20261106`: 24 blok, 8.103 bidang,
8.102 hak, 3.794 SPPT, audit #2913, `ST_Equals` BLOK 19 cocok).

Manifest dump (berkasnya di luar repo, tidak di-commit):

| basis data | waktu dump | ukuran | SHA-256 |
|---|---|---|---|
| `sipetades_gedong` | 6 Nov 2026 12:22 | 9.382.391 byte | `560fddbbfde4e8a6b7fa67491ec336ecae9d1efb1edac55bfab284e981b099a5` |
| `sipetades_gedong` | 6 Nov 2026 12:32 | 9.383.585 byte | `ed67cc3c2b63085a5e769c27a7ac4dc6f832a942749e5220e09c84136e4b17f7` |

### 3.8f Poligon G01684 diperbaiki (6 Nov 2026)

Petugas memindahkan `DXF-020100-G01684` (RUKINEM / SD N I GEDONG) dari BLOK 19 ke
BLOK 21 lagi pada pukul 12:28 (audit #2914), padahal bidang itu **100% di dalam
BLOK 19 dan 53,7 m dari BLOK 21** — pemindahan 11:27 yang sama sudah dikembalikan
di 3.8b. Sebelum batas diputuskan, petugas meminta geometrinya dirapikan dulu.

Ternyata cincinnya **spiral**, bukan sekadar berulang: jalur dari titik bersama B
menuju cuping timur dilintasi **tiga kali** (dua maju, satu mundur) sehingga luas
yang dilaporkan kebetulan tetap benar — loop yang berlawanan arah saling
menghapus. Luas gambarnya 3.636,05 m², jadi `luas_m2` yang tercatat (3.636,31)
memang sesuai. Cincin dipecah menjadi loop sederhana (cuping timur 2.777,40 m²
dan cuping barat 858,65 m²), keduanya **tidak bertumpang** dan digabung menjadi
satu `POLYGON` 16 titik:

- luas tetap **3.636,05 m²**, `luas_m2` 3.636,31 → **3.636,05**;
- `ST_IsSimple` = 1, 0 segmen bersilangan, `ST_Intersection(geom, geom)` sama
  dengan luas gambar, dan luas ≤ convex hull (5.907,13 m²);
- bentuk gambar **tidak berubah** (`ST_SymDifference` lama vs baru = 0,00 m²);
- `pusat_lon`/`pusat_lat` 110,0645364 / -7,0924629; audit **#2915**.

Keanggotaan **tetap BLOK 21** (487 anggota; BLOK 19 293), karena keputusan batas
belum diambil. Bentuk yang sudah sah ini tetap 100% di dalam BLOK 19 dan tidak
menyentuh BLOK 21, jadi opsi yang tersisa: kembalikan ke BLOK 19, atau bentuk
ulang batas BLOK 21 mengikuti tepi bidang. Tumpang 23,6% dengan
`DXF-020100-0282` (RUKINEM, `perlu_verifikasi`) sudah ada sebelum perbaikan dan
tidak berubah.

Cadangan: `D:\backup\sigpetades\perbaikan-g01684-20261106\sebelum-perbaiki-g01684-20261106.sql`
(diuji restore ke `uji_g01684_20261106`: 24 blok, 8.103 bidang, 8.102 hak,
3.794 SPPT, audit #2914; `ST_Equals` G01684/BLOK 19/BLOK 21 cocok; 16/17
checksum tabel identik, `sppt` hanya beda urutan baris InnoDB — MD5 isi
deterministiknya sama). Diff live vs cadangan setelah perbaikan: tepat 1 baris
berubah. WKT sebelum/sesudah: `storage\perbaiki-poligon-G01684-20261106-123614.json`.

### 3.8g Batas BLOK 21 diperluas memuat G01684 (6 Nov 2026)

Setelah poligon G01684 sah di 3.8f, petugas memilih **membentuk ulang batas
BLOK 21** ketimbang mengembalikan bidang itu ke BLOK 19. Koridor 10 m dari bidang
ke BLOK 21 tidak bisa dipakai: koridor itu hanya memotong BLOK 19 (1.016,84 m²;
93,5 m garis tengahnya di dalam BLOK 19), dan setiap potongan selebar 6–12 m
memutus serpihan barat BLOK 19 dari badan utamanya karena **G01684 adalah satu-
satunya penyambungnya** — `BLOK 19 − G01684` sudah pecah menjadi tiga bagian
(168.779,7 + 17.629,5 + 0,0 m²).

Karena itu BLOK 21 menyerap sekaligus G01684, serpihan barat BLOK 19, dan bidang
tepi di antaranya. Bentuknya memakai tepi bidang yang sudah ada, tanpa garis
buatan baru:

- serapan **24.879,56 m²** = 21.265,58 m² dari BLOK 19 (G01684 3.636,05 +
  serpihan barat 17.629,53) + 2.525,13 m² tanah di luar blok mana pun +
  1.088,85 m² yang memang sudah di BLOK 21;
- BLOK 19 **190.045,32 → 168.779,74 m²** (−21.265,58), BLOK 21
  **183.106,09 → 206.896,80 m²** (+23.790,71);
- keduanya tetap **satu bagian** (`ST_NumGeometries` = 1, `ST_IsSimple` = 1;
  171 dan 1.045 titik), tumpang tindih **0 m²** dengan seluruh 23 blok lain;
- G01684 **100% di dalam BLOK 21**; sisa di luar hanya serpihan pembulatan
  koordinat 0,00009 m²;
- `sumber_perkiraan` BLOK 19 = `gabungan bidang anggota; lidah dipotong; barat
  ke BLOK 21`, BLOK 21 = `gabungan bidang anggota + penyangga 10 m; serapan
  G01684`; `jumlah_bidang_estimasi` 274 dan 520.

Keanggotaan dipindahkan dalam transaksi yang sama: **33 bidang** —
**19 dari BLOK 19** dan **14 dari BLOK 23** (beberapa di antaranya kembar:
`HM-199D K3`/`DXF-020100-0383`, `HM-134B`/`DXF-020100-0147`,
`HM-331B`/`DXF-020100-1511`, `HM-202D K3`/`DXF-020100-2168`). Semuanya ≥96,6%
luasnya di dalam area serapan; hanya `DXF-020100-G02045` berstatus `aktif`,
sisanya `perlu_verifikasi`. Anggota menjadi **274** (BLOK 19), **520** (BLOK 21),
dan **460** (BLOK 23); jumlah bidang, hak, blok, dan SPPT tidak berubah
(8.103 / 8.102 / 24 / 3.794). Tidak ada rujukan `bidang_id` di `objek_pajak`
untuk ke-33 bidang.

Pemeriksaan pascaperubahan: persentase setiap bidang BLOK 23 terhadap BLOK 21
**identik sebelum dan sesudah** (tidak ada bidang melintang baru); serpihan
bidang BLOK 20/22/25 (≤0,35%) yang dulu jatuh di lidah BLOK 19 kini jatuh di
BLOK 21 dengan persentase sama. Lima bidang BLOK 21 masih melintang di garis
batas seperti sebelumnya: `HM-1313` 58,1%, `HM-1314` 80,1%, `HM-1320` 94,1%,
`HM-1321` 95,5%, dan `HM-1201` 99,4% (terakhir sudah 99,4% di BLOK 21 sejak
sebelumnya, tercatat BLOK 23).

Audit **#2916–#2919** (dua baris `bidang` per blok asal, dua baris `blok`).
Uji asap API: `api/bidang.php?lapisan=blok` → HTTP 200, 24 fitur,
`estimasi_bidang` BLOK 19 = 274 dan BLOK 21 = 520 (hanya diuji di tingkat API,
bukan tangkapan layar browser).

Cadangan di `D:\backup\sigpetades\b21-g01684-20261106\` (di luar repo):

| berkas | waktu | ukuran | SHA-256 | uji restore |
|---|---|---|---|---|
| `sebelum-b21-g01684-20261106.sql` | 6 Nov 2026 13:01 | 9.384.918 byte | `065f4edfc0d6f45375d86949b32ddada40f7f5aaf66f61cf99e34bdcb8d1b4e2` | `uji_b21_20261106`: hitungan sama, `ST_Equals` kedua blok = 1 |
| `setelah-b21-g01684-20261106.sql` | 6 Nov 2026 13:07 | 9.386.419 byte | `7cf967a47268c251506e9f5f9d3a4a7cf3f80cfd6f0721722c47a2d205246518` | `uji_b21_after_20261106`: 21 tabel, 24 blok, 8.103 bidang, 8.102 hak, 3.794 SPPT, audit #2919, `ST_Equals` kedua blok = 1 |

Kedua basis data uji dihapus setelah pemeriksaan. WKT sebelum/sesudah dan daftar
33 bidang: `storage\ubah-batas-b21-G01684-20261106-130450.json`.

### 3.8h Jalur BLOK 21–BLOK 23 diisi sampai garis BLOK 23 (6 Nov 2026)

Di antara tepi timur BLOK 21 dan tepi barat BLOK 23 tersisa **jalur putih tanpa
blok seluas 15.795,40 m²** sepanjang ±470 m (lebar 0–40 m). Sekitar 30 bidang di
jalur itu tercatat sebagai anggota BLOK 23/BLOK 25, padahal 0 % luasnya berada di
blok aslinya. Petugas memilih **mengisi seluruh jalur** sehingga BLOK 21 menyentuh
garis resmi BLOK 23 dan tidak ada lagi bidang putih di antaranya.

Bentuk batasnya disusun dari irisan dua penyangga 60 m (BLOK 21 dan BLOK 23)
dikurangi kedua blok — jalur itu sendiri — lalu digabung dengan BLOK 21 dan
seluruh bidang yang terpotong tepi jalur, memakai `polygon-clipping` dan
dinormalisasi ulang:

- **bidang diserap utuh 36 baris** (34 dari BLOK 23, 2 dari BLOK 25; beberapa
  kembar: `HM-94B`/`DXF-020100-0083`/`G02423`, `HM-204D K3`/`DXF-020100-0341`/
  `G02422`, `HM-C41`/`DXF-020100-1459`/`G01674`). Bidang yang hanya terpotong
  sebagian ikut utuh mengikuti tepinya: `HM-1205` 78,1 %, `HM-1213` 50,7 %,
  `HM-1211` 27,1 %, `HM-1212` 11,8 %, `HM-1210` 90,6 %, `G01682` 84,3 %,
  `G01673` 92,7 %; sisanya ≥90 %. Penyerapan utuh menambah 3.090,38 m² di luar
  jalur (tepi bidang), jadi luas akhir 225.782,59 m² — bukan 222.692 m² seperti
  perkiraan awal.
- **6 bidang dikecualikan** karena memang berada di blok asalnya dan hanya
  menyentuh jalur <2 %: `HM-0883` (BLOK 24) 0,5 %, `HM-1097` 2,0 %, `HM-1096`
  0,9 %, `DXF-BIDANG-HM-01113` 0,4 %, `DXF-020100-G01958` 0,4 %,
  `DXF-020100-G01951` 0,4 %. Tepi jalur dipotong mengikuti tepi keenam bidang itu,
  jadi **0 m²** dari keenamnya masuk BLOK 21 dan tidak ada bidang melintang baru.

Hasil:

- BLOK 21 **206.896,80 → 225.782,59 m²** (+18.885,79; +9,13 %); **satu bagian**
  (`ST_NumGeometries` = 1, `ST_IsSimple` = 1, cincin luar 480 titik) dengan
  4 lubang seluas 1.228,56 m². Semula lubang itu disangka jari BLOK 23;
  pengukuran 6 Nov 14:00 membuktikan **tidak ada blok lain yang menutupinya**:
  lubang 1 (146,12 m²) seluruhnya `HM-1216`, lubang 2 (883,12 m²) `HM-1230`
  553,37 + `DXF-020100-2952` 223,45 + 106,30 m² kosong, lubang 3 (165,80 m²)
  dan lubang 4 (33,52 m²) kosong. Ketiga bidang itu tetap tercatat di blok
  asalnya (lihat tindak lanjut 14:04).
- tumpang tindih **0 m²** dengan seluruh 23 blok lain; kontak garis dengan
  BLOK 23 naik **85,2 → 268,2 m** (maksimum yang bisa dicapai karena sebagian
  tepi BLOK 23 menjorok dan sisa mulut jalur membuka ke tanah kosong).
- anggota BLOK 21 **520 → 556**, BLOK 23 **460 → 426**, BLOK 25 **395 → 393**;
  jumlah bidang, hak, blok, dan SPPT tidak berubah (8.103 / 8.102 / 24 / 3.794).
- `sumber_perkiraan` BLOK 21 = `jalur BLOK 23 diserap + bidang tepi; G01684`,
  `jumlah_bidang_estimasi` = 556, `maks_jarak_estimasi_m` dikosongkan (batas tidak
  lagi memakai penyangga).

Pemeriksaan pascaperubahan: 36/36 bidang yang dipindah ≥99,9 % di dalam BLOK 21;
keenam bidang yang dikecualikan tepat **0 m²**; 111 bidang blok lain sudah
menumpang BLOK 21 sebelum perubahan dan tidak bertambah selain serpihan
pembulatan koordinat (`HM-0884` 0,224 m², tiga bidang 0,01 m²). Semua anggota
tetap utuh — tidak ada bidang melintang baru.

Audit **#2920–#2922** (dua baris `bidang` per blok asal, satu baris `blok`). Uji
asap API: `api/bidang.php?lapisan=blok` → HTTP 200, 24 fitur,
`estimasi_bidang` BLOK 21 = 556 (hanya diuji di tingkat API, bukan tangkapan
layar browser).

**Tindak lanjut 14:04 — keempat lubang ditutup.** Petugas memilih "isi semua
lubang" karena lubang tampil sebagai bercak terang di dalam hijau BLOK 21.
Cincin dalam dibuang sehingga BLOK 21 menjadi satu poligon padat: 225.782,59 →
**227.011,16 m²** (+1.228,57), tetap **satu bagian**, `ST_IsSimple` = 1,
**0 lubang**, 0 m² tumpang tindih dengan 23 blok lain, dan tepi luar tidak
berubah (0 m² bagian lama hilang). `jumlah_bidang_estimasi` tetap 556;
keanggotaan tidak dipindah — `HM-1216` dan `HM-1230` tetap tercatat di BLOK 23
dan `DXF-020100-2952` tanpa blok, sesuai pilihan petugas (sama seperti 111
bidang lain yang sudah menumpang BLOK 21). `sumber_perkiraan` = `jalur BLOK 23
diserap + bidang tepi; G01684; lubang ditutup`. Audit **#2923**. Rollback:
`storage\isi-lubang-b21-20261106-140448.json` (WKT sebelum/sesudah).
Uji asap API ulang: HTTP 200, 24 fitur, `estimasi_bidang` BLOK 21 = 556.

**Tindak lanjut 14:13 — BLOK 21 ditetapkan sebagai batas blok tersedia.**
Petugas memutuskan batas padat ini menjadi batas resmi, jadi baris blok diubah:
`batas_tersedia` 0 → **1**, `batas_perkiraan` 1 → **0**, `sumber_perkiraan` =
`gabungan bidang anggota + jalur BLOK 23; ditetapkan`, `jumlah_bidang_estimasi`
556 → **NULL**, `maks_jarak_estimasi_m` tetap NULL. Geometri tidak berubah
(227.011,16 m², 0 lubang, 0 m² tumpang tindih). Audit **#2924**. Rollback:
`storage\batas-tersedia-b21-20261106-141302.json`. Uji asap API: HTTP 200,
24 fitur, BLOK 21 `batas_tersedia = true`, `batas_perkiraan = false`,
`estimasi_bidang = null`. Dengan ini tersisa **BLOK 19 dan BLOK 22** yang masih
`batas_perkiraan = 1`.

**Tindak lanjut 14:23 — BLOK 19 dan BLOK 22 ditetapkan sebagai batas blok
tersedia.** Kedua batas diperiksa dengan syarat yang sama seperti BLOK 21:
`ST_NumGeometries` = 1, `ST_NumInteriorRings` = 0, `ST_IsSimple` = 1, 0 m²
tumpang tindih dengan 23 blok lain, dan seluruh 274 anggota BLOK 19 utuh di
dalam batasnya. BLOK 22 mempunyai 112 dari 124 anggota utuh; 12 bidang melintang
93,1–99,8 % (`G02459` paling rendah), sebanding dengan 4 anggota BLOK 21 yang
juga melintang pada batas resminya. Baris blok diubah: `batas_tersedia` 0 → **1**,
`batas_perkiraan` 1 → **0**, `jumlah_bidang_estimasi` 274/131 → **NULL**,
`maks_jarak_estimasi_m` 25/15 → **NULL**, `sumber_perkiraan` =
`gabungan anggota; lidah dipotong; barat BLOK 21; ditetapkan` (BLOK 19) dan
`gabungan bidang anggota + penyangga 15 m; ditetapkan` (BLOK 22). Geometri tidak
berubah (168.779,74 dan 84.293,08 m², masing-masing satu bagian tanpa lubang).
Audit **#2925–#2926**. Rollback: `storage\tetapkan-b19-b22-20261106-142324.json`.
Uji asap API: HTTP 200, 24 fitur, kedua blok `batas_tersedia = true`,
`batas_perkiraan = false`, `estimasi_bidang = null`. **Seluruh 24 blok kini
berstatus batas tersedia** (`SUM(batas_perkiraan) = 0`).

Cadangan di `D:\backup\sigpetades\b21-b23-20261106\` (di luar repo):

| berkas | waktu | ukuran | SHA-256 | uji restore |
|---|---|---|---|---|
| `sebelum-b21-b23-20261106.sql` | 6 Nov 2026 13:48 | 9.386.069 byte | `6d109d5867e443bea27598b2a347ccb1e23070c9029130e0e5a36e81f95ed033` | `uji_b21b23_20261106`: 21 tabel, 24 blok, 8.103 bidang, 8.102 hak, 3.794 SPPT, audit #2919, luas BLOK 21 206.896,80 m², 520 anggota |
| `setelah-b21-b23-20261106.sql` | 6 Nov 2026 13:52 | 9.439.730 byte | `5fc1783097a5587b0e3a13cf6857772fba4dfc6700212e27dfcd5d32e9bf0007` | `uji_b21b23_after_20261106`: angka sama dengan live, audit #2922, luas 225.782,59 m², 556 anggota, `ST_Equals` BLOK 21 = 1 |

Kedua basis data uji dihapus setelah pemeriksaan. WKT sebelum/sesudah dan daftar
36 bidang: `storage\ubah-batas-b21-b23-20261106-135031.json`.

Cadangan tindak lanjut di `D:\backup\sigpetades\isi-lubang-b21-20261106\`:

| berkas | waktu | ukuran | SHA-256 | uji restore |
|---|---|---|---|---|
| `sebelum-isi-lubang-20261106.sql` | 6 Nov 2026 14:03 | 9.439.730 byte | `92fdbe54a46cb0bf731a7471cc3bf1da6c01ca27ab5b54ad13bea49b80b0ccfb` | `uji_isi_lubang_20261106`: 24 blok, 8.103 bidang, 8.102 hak, audit #2922, BLOK 21 225.782,59 m², 4 lubang, 556 anggota |
| `setelah-isi-lubang-20261106.sql` | 6 Nov 2026 14:05 | 9.436.790 byte | `1440f1be1cd9d941928ff4e2b9788f3dc6906c01dfb10a31ff00801521b2e89a` | `uji_isi_lubang_after_20261106`: 24 blok, 8.103 bidang, 8.102 hak, audit #2923, BLOK 21 227.011,16 m², 0 lubang, 556 anggota |

Kedua basis data uji tindak lanjut juga dihapus.

Cadangan penetapan status di `D:\backup\sigpetades\batas-tersedia-b21-20261106\`:

| berkas | waktu | ukuran | SHA-256 | uji restore |
|---|---|---|---|---|
| `sebelum-batas-tersedia-20261106.sql` | 6 Nov 2026 14:12 | 9.436.790 byte | `2ddec2b8d874861c3389a1c9fb846a913b94d6be0657ce1b43d6ca719d726bae` | `uji_batas_tersedia_20261106`: 24 blok, 8.103 bidang, 8.102 hak, audit #2923, BLOK 21 `0/1/556`, 227.011,16 m² |
| `setelah-batas-tersedia-20261106.sql` | 6 Nov 2026 14:13 | 9.437.246 byte | `39e8def37f698f895a78a1e63bbb94ce69b6269fa1af3daa932f436774aa665f` | `uji_batas_tersedia_after_20261106`: 24 blok, 8.103 bidang, 8.102 hak, audit #2924, BLOK 21 `1/0/NULL`, 227.011,16 m² |

Kedua basis data uji penetapan status juga dihapus.

Cadangan penetapan status BLOK 19/22 di
`D:\backup\sigpetades\batas-tersedia-b19-b22-20261106\`:

| berkas | waktu | ukuran | SHA-256 | uji restore |
|---|---|---|---|---|
| `sebelum-b19-b22-20261106.sql` | 6 Nov 2026 14:22 | 9.437.246 byte | `60c62173c7b54606e997a0f292549d818d1bffa86d6bcb7207999aa5091ac7f0` | `uji_b19b22_before_20261106`: 21 tabel, 24 blok, 8.103 bidang, 8.102 hak, 3.794 SPPT, audit #2924, BLOK 19/22 `0/1`, 168.779,74 / 84.293,08 m² |
| `sesudah-b19-b22-20261106.sql` | 6 Nov 2026 14:23 | 9.438.300 byte | `b59f9d98db580c7c4ba8a61799e08096986ceccc31c097acaf5c01352f07fd74` | `uji_b19b22_after_20261106`: 21 tabel, 24 blok, 8.103 bidang, 8.102 hak, 3.794 SPPT, audit #2926, 0 blok perkiraan, BLOK 19/22 `1/0/NULL`, luas sama |

Kedua basis data uji penetapan BLOK 19/22 juga dihapus.

### 3.8i Bidang BLOK 3 di luar geometrinya dipindah ke BLOK 8 (7 Nov 2026)

Petugas meminta bidang yang tercatat BLOK 3 tetapi berada **di luar geometri
BLOK 3** dipindahkan ke BLOK 8. Dari 519 anggota BLOK 3, **29 bidang** ternyata
0 % di dalam BLOK 3: semuanya menggerombol di lahan tanpa blok pada
110,0726–110,0763 BT / -7,0815 s.d. -7,0792 LS, di antara BLOK 3 (utara),
BLOK 7 (barat daya), BLOK 8 (selatan), dan BLOK 9 (barat). Kelompok ini hanya
**menyentuh tepi** BLOK 7/8/9 (jarak 0 m) tanpa tumpang 1 m² pun, dan berjarak
3,6 m dari BLOK 3. Ke-29 bidang (4 aktif, 25 perlu verifikasi; 54.743,63 m²)
dipindah dengan `UPDATE bidang SET blok_id = 19` dalam satu transaksi; geometri,
status, dan hak tidak disentuh. Anggota BLOK 3 519 → **490**, BLOK 8 239 →
**268**; total tetap 8.103 bidang, 8.102 hak, 24 blok, 3.794 SPPT. Audit
**#2944** (`objek_id='BLOK 3'`, daftar 29 kode di keterangan). Rollback:
`storage\pindah-blok3-ke-blok8-20261107.json`.

Catatan penting: karena kelompok ini berada di *lahan tanpa blok*, bidang-bidang
tersebut **tetap 0 % di dalam geometri BLOK 8** sesudah dipindah — penugasan ini
bersifat administratif sesuai keputusan petugas, bukan hasil pengukuran
kandung. Batas BLOK 3 dan BLOK 8 tidak diubah (187.414,62 dan 125.693,68 m²).
Sisa 17 bidang BLOK 3 yang terpotong tepi (89,5–99,9 % di dalam) **tidak**
dipindah karena masih tergolong anggota sah; yang paling mencolok
`DXF-020100-G00609` (89,5 %).

Cadangan di `D:\backup\sigpetades\blok3-luar-20261107\` (di luar repo):

| berkas | waktu | ukuran | SHA-256 | uji restore |
|---|---|---|---|---|
| `sebelum-pindah29-20261107.sql` | 7 Nov 2026 11:41 | 9.454.258 byte | `9c43e4e57a609d3a5e69ff20bff06e87189119276e77eff32a651d6399435b14` | `uji_b3luar_20261107`: 24 blok, 8.103 bidang, 8.102 hak, 3.794 SPPT, audit #2943, BLOK 3/8 519/239; `ST_Equals` seluruh bidang & blok = 1 |
| `sesudah-pindah29-20261107.sql` | 7 Nov 2026 11:42 | 9.458.010 byte | `9d7d41b7ed302157d40e967587cc87fb2d57cd37c13cec3d40587b77dc20ae21` | `uji_b3luar_after_20261107`: 24 blok, 8.103 bidang, 8.102 hak, 3.794 SPPT, audit #2944, BLOK 3/8 490/268; `ST_Equals` seluruh bidang = 1, `blok_id` identik |

Kedua basis data uji dihapus setelah pemeriksaan. Daftar 29 bidang:
`rencana-pindah29-20261107.tsv` di folder cadangan yang sama.

### 3.8j 25 poligon rusak BLOK 8 dirapikan (7 Nov 2026)

Pemeriksaan ulang BLOK 8 sesudah pemindahan di 3.8i menemukan **25 bidang**
bercincin menyilang diri (`ST_IsSimple = 0`), semuanya berstatus `aktif` dan
total tercatat **97.412,86 m²**. Cincinnya bukan spiral seperti `G01684` (3.8f),
melainkan beberapa cuping yang dihubungkan garis lompatan, sehingga setiap
bidang dipecah menurut cupingnya: **23 bidang menjadi satu `POLYGON`** (termasuk
`G00954` setelah bagian berluas 0 dibuang) dan **2 bidang memang multi-bagian**:

| bidang | bagian | luas per bagian (m²) | jarak antar-bagian |
|---|---|---|---|
| `DXF-020100-G00827` | 2 | 1.909,95 + 3.646,12 | 52,12 m |
| `DXF-020100-G01156` | 3 | 300,04 + 1.912,05 + 568,33 | 28,70 m |

Normalisasi dipilih dengan menggabungkan cincin apa adanya (`union`), bukan
memecah cincin menjadi loop lalu digabung: pemecahan loop membesarkan luas 7
bidang dan menambah bagian. Bentuk gambar dipertahankan — `ST_SymDifference`
lama vs baru **0,000 m²** untuk 24 bidang dan **0,001 m²** untuk `G00954`;
`ST_IsSimple = 1` untuk ke-25 bidang; **0 bidang rusak** tersisa di BLOK 8.
`luas_m2` disesuaikan ke luas gambar (97.412,86 → **97.405,02 m²**, −7,84 m² atau
0,008 %); `pusat_lon`/`pusat_lat` dihitung ulang dari centroid. Luas gambar
seluruh anggota BLOK 8 tidak berubah: **414.409,14 m²** (268 anggota; total
`luas_m2` 414.453,35 → 414.445,51 m²).

Karena kolom `bidang.geom` bertipe `POLYGON`, dua bidang multi-bagian tidak bisa
disimpan. Petugas memilih **mengubah tipe kolom menjadi `GEOMETRY`**. Uji di
basis data klon lebih dulu: `MODIFY geom MULTIPOLYGON` ditolak
(`ERROR 1366 Incorrect MULTIPOLYGON value`), sedangkan `MODIFY geom GEOMETRY`
berhasil **tanpa menulis ulang 8.103 nilai** dan indeks spasial
`spn_bidang_geom` tetap. Sesudah perubahan: **8.101 `POLYGON` + 2
`MULTIPOLYGON`**; `sql/01_skema.sql` ikut disesuaikan. Audit **#2945** mencatat
perubahan kolom (`objek='basis_data'`, `objek_id='bidang.geom'`) dan audit
**#2946–#2970** mencatat 25 bidang satu per satu. Uji asap
`api/bidang.php?lapisan=bidang`: HTTP 200, 6.000 fitur (batas bawaan), tipe
`Polygon` dan `MultiPolygon`.

Kedua bidang multi-bagian masih tercatat sebagai **kandidat duplikat** pada
`catatan` bawaannya dan tiap cupingnya menumpang bidang lain; penanganan
duplikat itu di luar perbaikan geometri ini. Di blok lain masih ada **317 bidang
rusak** yang belum disentuh (terbanyak BLOK 25 = 36, BLOK 2 = 35, BLOK 13 = 29,
BLOK 9 = 26).

Cadangan di `D:\backup\sigpetades\poligon-b8-20261107\` (di luar repo):

| berkas | waktu | ukuran | SHA-256 | uji restore |
|---|---|---|---|---|
| `sebelum-perbaiki-poligon-b8-20261107.sql` | 7 Nov 2026 11:46 | 9.458.010 byte | `e26e0a0bd3f97fd3b59fb7303105416414936a8d286cbc5318a3142d81e44b3d` | `uji_poligon_b8_20261107`: 8.103 bidang, 8.102 hak, 24 blok, 3.794 SPPT, audit #2944, BLOK 8 rusak 25; `ST_Equals` seluruh bidang & blok = 1 |
| `sesudah-perbaiki-poligon-b8-20261107.sql` | 7 Nov 2026 12:00 | 9.488.306 byte | `19d971cb0b0cb37e14a864a177b904a93cb681082bea9e25994ffad8c6d633a6` | `uji_poligon_b8_after_20261107`: 21 tabel, angka sama, audit #2970, BLOK 8 rusak 0, 2 `MULTIPOLYGON` + 8.101 `POLYGON`, 25 WKT identik dengan live |

Kedua basis data uji dihapus. Rollback geometri:
`storage\perbaiki-poligon-b8-20261107.json` (25 baris: WKT sebelum/sesudah,
luas, pusat, tipe). Urutan rollback: kembalikan 25 geometri dulu, baru
`ALTER TABLE bidang MODIFY geom POLYGON NOT NULL`. Rencana per bidang:
`rencana-perbaiki25-20261107.tsv` di folder cadangan yang sama.

### 3.8k Geometri batas BLOK 8 dikosongkan (7 Nov 2026)

Petugas meminta BLOK 8 tidak lagi digambar di peta. Pilihan yang diambil
**hanya mengosongkan batas**: baris `blok` tetap ada, seluruh **271 bidang**
(85 `aktif` + 186 `perlu_verifikasi`) dan **159 objek pajak** tetap menjadi
anggota BLOK 8, tetapi `blok.geom` diisi `NULL`. Tiga bidang terakhir masuk
lewat penyuntingan UI pukul 12.09–12.12 (audit **#2972–#2974**: `2519`,
`2949`, `G01185`), sehingga anggota naik dari 268 (3.8j) menjadi 271.
Penanda `batas_tersedia = 1`, `batas_perkiraan = 0` dan
`sumber_perkiraan = 'Pengesahan resmi dikonfirmasi pengguna'` dibiarkan:
lapisan peta melewati baris bergeometri kosong, jadi blok ini tidak tergambar
tanpa perlu mengubah penandanya.

Keadaan sebelum: `id = 19`, satu bagian, luas **125.693,68 m²**, WKT 7.862
karakter. Karena `blok.geom` bertipe `MULTIPOLYGON NOT NULL` dan memiliki
`SPATIAL KEY spn_blok_geom`, pengosongan menuntut perubahan skema:

```
ALTER TABLE blok DROP INDEX spn_blok_geom, MODIFY geom MULTIPOLYGON NULL;
```

`MODIFY ... NULL` ditolak `ERROR 1252 (All parts of a SPATIAL index must be
NOT NULL)` bila indeks masih ada, jadi indeks dilepas dalam `ALTER` yang sama.
Sesudahnya: kolom `NULL`-boleh, indeks spasial `blok` tidak ada lagi (tabel ini
hanya 24 baris, pencarian blok tetap memakai kunci primer/dusun), dan **23 blok
lain masih bergeometri**. `sql/01_skema.sql` disesuaikan
(`multipolygon DEFAULT NULL`, `SPATIAL KEY spn_blok_geom` dihapus).

Audit **#2975** (`objek='blok'`, `objek_id='19'`) menyimpan WKT dan luas
sebelum di `data_sebelum` serta penanda dan jumlah anggota di `data_sesudah`.
Uji asap: `api/bidang.php?lapisan=blok` HTTP 200 dengan **23 fitur** dan BLOK 8
tidak ada di daftar; `api/bidang.php?lapisan=bidang&limit=5` HTTP 200 dengan
6.000 fitur (batas bawaan); `peta.php` tetap mengalihkan ke halaman masuk bila
belum ada sesi. Jumlah data tidak berubah: **8.103 bidang, 8.102 hak, 24 blok,
3.794 objek pajak**. Kueri yang memakai `blok.geom` (mis. pencarian blok
terdekat di `app/data.php`) kini melewati BLOK 8 dan memakai blok lain
terdekat.

Cadangan di `D:\backup\sigpetades\blok8-tanpa-geometri-20261107\` (di luar repo):

| berkas | waktu | ukuran | SHA-256 | uji restore |
|---|---|---|---|---|
| `sebelum-kosongkan-geom-blok8-20261107.sql` | 7 Nov 2026 12:07 | 9.488.306 byte | `b9b17b3bda5614dda929245af58e7630867579b45e5dd60ef1e48beb2d97846f` | `uji_blok8_nogeom_20261107`: 8.103 bidang, 8.102 hak, 24 blok, audit #2970, BLOK 8 bergeometri, anggota 268 |
| `sesudah-kosongkan-geom-blok8-20261107.sql` | 7 Nov 2026 12:16 | 9.497.479 byte | `56545d6670b4ee86bb888a212d8c9faea9826549ee5823d96dbc0951ca8c537d` | `uji_blok8_nogeom_after_20261107`: 8.103 bidang, 8.102 hak, 24 blok, 1 blok tanpa geometri, anggota 271, 159 objek pajak, audit #2975, kolom `NULL`-boleh, indeks spasial 0 |

Kedua basis data uji dihapus. Rollback: `storage\kosongkan-geom-blok8-20261107.json`
berisi WKT sebelum. Urutannya isi geometri dahulu
(`UPDATE blok SET geom = ST_GeomFromText(<wkt>, 4326) WHERE id = 19`), baru
`ALTER TABLE blok MODIFY geom MULTIPOLYGON NOT NULL, ADD SPATIAL KEY
spn_blok_geom (geom)` — `ALTER` tidak bisa dipakai selama masih ada baris
`NULL`.

### 3.8l Batas perkiraan BLOK 8 dibuat (7 Nov 2026)

Sesudah batasnya dikosongkan di 3.8k, petugas meminta **geometri perkiraan**
untuk BLOK 8. Metodenya mengikuti pola BLOK 19/21/22 (3.8a): 271 bidang
anggota digabung, diselubungi **15 m**, lalu dipotong **23 batas blok lain**
supaya tidak menutupi blok resmi. Hasilnya **264.515,65 m²** dalam **1 bagian**
(`MultiPolygon`, `ST_IsSimple = 1`) dengan **1 lubang 1.558,48 m²** dan
**0 m² tumpang** dengan 23 blok. Lubang itu bukan milik blok lain (irisan dengan
ke-23 blok = 0 m²): ia rongga tertutup yang sudah ada di selubung bidang
anggota. Catatan: `ST_NumInteriorRings` pada `MULTIPOLYGON` mengembalikan `NULL`
di MariaDB 10.4, jadi angka lubang diukur lewat
`ST_NumInteriorRings(ST_GeometryN(geom,1))` — hitungan awal "0 lubang" keliru.
**238 bidang** utuh di dalam; **11 bidang** terpotong 0,57–3,52 m² (total
**21,05 m²**); 22 sisanya terpotong di bawah 0,5 m²; **0 pusat bidang** di luar. Lubang tersebut kemudian **ditutup**
pukul 12:37 atas permintaan petugas (3.8m).

Dua bentuk lain diukur dan tidak dipakai:

| kandidat | luas (m²) | bagian | tumpang blok lain (m²) |
|---|---|---|---|
| gabungan tepat bidang | 246.106,55 | 8 | 15,73 (`BLOK 9`) |
| selubung 15 m tanpa potong | 295.747,16 | 1 + 1 lubang | 31.232,14 pada 6 blok |
| **selubung 15 m dipotong** | **264.515,65** | **1 + 1 lubang** | **0** |

Penanda baris blok: `batas_tersedia = 0`, `batas_perkiraan = 1`,
`sumber_perkiraan = 'gabungan bidang anggota + penyangga 15 m; potong blok resmi'`
(59 karakter), `jumlah_bidang_estimasi = 271`, `maks_jarak_estimasi_m = 15`.
Audit **#2976**; rollback `storage\batas-kira-blok8-20261107.json` (isi
`geom = NULL` dan penanda resmi lama `batas_tersedia=1`, `batas_perkiraan=0`,
`sumber_perkiraan='Pengesahan resmi dikonfirmasi pengguna'`, estimasi `NULL`).
Pratinjau ketiga kandidat: `storage\pratinjau-batas-kira-b8-20261107.png`;
panel biru versi besar (1200×2009): `storage\pratinjau-batas-kira-b8-biru-20261107.png`
(dirender ulang sesudah lubang ditutup, 3.8m).

Uji asap `api/bidang.php?lapisan=blok`: HTTP 200, **24 fitur**, BLOK 08
`batas_perkiraan=true`, `batas_tersedia=false`, `estimasi_bidang=271`,
geometri `MultiPolygon` 1 bagian. Anggota **271 bidang / 159 objek pajak** dan
total **8.103 bidang / 8.102 hak / 24 blok / 3.794 objek pajak** tidak berubah.

Cadangan sesudah di `D:\backup\sigpetades\batas-kira-blok8-20261107\` (di luar
repo): `sesudah-batas-kira-blok8-20261107.sql`, 7 Nov 2026 12:24, 9.511.698
byte, SHA-256 `7a1fe2bdc5351879a7cf0bb3a4af247f33cceb82297fb9ad022639f84d38be98`,
diuji restore ke `uji_batas_kira_b8_20261107` (angka sama, audit #2976, luas
264.515,65 m², 1 bagian + 1 lubang, penanda perkiraan) lalu DB uji dihapus. Keadaan
sebelum perubahan ini adalah dump `sesudah-kosongkan-geom-blok8-20261107.sql`
di folder 3.8k yang sudah diuji restore.

### 3.8m Lubang BLOK 8 ditutup (7 Nov 2026)

Petugas meminta **"tutup lubang 1.558,48 m² di BLOK 8"** sesudah lubang itu
terlihat pada pratinjau 3.8l. Lubangnya bukan milik blok lain (irisan dengan
ke-23 blok = 0 m²), jadi penutupannya tidak menambah tumpang dengan blok mana pun.
Caranya: cincin luar bagian satu-satunya dipakai apa adanya, cincin dalam
dibuang, hasilnya tetap `MultiPolygon` satu bagian —

```sql
UPDATE blok
   SET geom = ST_GeomFromText(CONCAT('MULTIPOLYGON((', SUBSTRING(
                ST_AsText(ST_ExteriorRing(ST_GeometryN(geom,1))),11), '))'),4326),
       diubah_pada = NOW()
 WHERE id = 19 AND kode = 'BLOK 8';
```

Hasilnya **266.074,13 m²** (264.515,65 + 1.558,48), **1 bagian, 0 lubang**,
`ST_IsSimple = 1`, WKT 6.135 karakter, MD5 WKT `91e8a2880407d4ff22c957a40b3dfe42`.
Cincin luarnya **tidak berubah** (MD5 `4aab5f894ace230d880e4ecc967bb87f` sama
sebelum dan sesudah), **0 m² tumpang** dengan 23 blok, **271 pusat bidang**
anggota tetap di dalam, dan lubang lama kini terkurung penuh
(`ST_Contains(geom, lubang_lama) = 1`). Penanda perkiraan
(`batas_tersedia=0`, `batas_perkiraan=1`, `jumlah_bidang_estimasi=271`,
`maks_jarak_estimasi_m=15`) dan `sumber_perkiraan` dibiarkan seperti 3.8l.
Audit **#2977**; rollback `storage\lubang-b8-20261107.json` (WKT lama + SQL
pembatalan). Uji asap `api/bidang.php?lapisan=blok`: HTTP 200, **24 fitur**,
BLOK 08 `MultiPolygon` dengan `batas_perkiraan=true`, `batas_tersedia=false`,
`estimasi_bidang=271` (kini 272 setelah 3.8n, lalu dikosongkan saat batas
ditetapkan di 3.8o). Anggota **271 bidang / 159 objek pajak** dan total
**8.103 bidang / 8.102 hak / 24 blok / 3.794 objek pajak** tidak berubah.

Cadangan di `D:\backup\sigpetades\lubang-b8-20261107\` (di luar repo):
`sebelum-tutup-lubang-20261107.sql` 9.511.698 byte, SHA-256
`78420e5eed1e7737b79968fb7982510732a236825e18ef2fc0d07415d9bebe56`
(diuji restore ke `uji_lubang_b8_20261107`: audit #2976, 264.515,65 m²,
1 lubang) dan `sesudah-tutup-lubang-20261107.sql` 9.526.250 byte, SHA-256
`1b70cb3c608fafd433004f91a6687841a99322632e50e82385fd90ee7e84372a`
(diuji restore ke `uji_lubang_b8_after_20261107`: audit #2977, 266.074,13 m²,
0 lubang, 271 anggota); kedua DB uji dihapus. Pratinjau panel biru besar
`storage\pratinjau-batas-kira-b8-biru-20261107.png` sudah dirender ulang tanpa
lubang; pratinjau tiga kandidat tetap arsip keputusan 3.8l.

### 3.8n DXF-020100-G01129 dipindahkan ke BLOK 8 (7 Nov 2026)

Petugas melaporkan empat bidang "belum masuk" BLOK 8:
`DXF-020100-G01176`, `HM-0486`, `DXF-020100-G03307`, dan `DXF-020100-G01129`.
Pengukuran menunjukkan keempatnya memang berada di dalam geometri BLOK 8
(99,47–100 % luas), tetapi **tiga sudah menjadi anggota** — `G01176` (id 21886,
389,21 m²), `G03307` (id 22853, 2.113,32 m²), dan `HM-0486` (id 20104, status
`perlu_verifikasi`, 2.723,15 m²). Hanya **`G01129` (id 21864, `aktif`)** yang
masih tercatat BLOK 9 (id 6): 408,85 dari 411,03 m² (**99,47 %**) di dalam BLOK 8,
sedangkan di batas resmi BLOK 9 hanya **0,99 m²**; tidak ada blok lain yang
beririsan. Daftar/peta BLOK 8 karena itu memuat tiga bidang itu tetapi tidak
G01129 (lapisan tanah 137 bidang).

Atas izin tertulis pengguna, `blok_id` dipindah dan penghitung estimasi
diselaraskan dalam satu transaksi:

```sql
UPDATE bidang SET blok_id = 19, diubah_pada = NOW()
 WHERE id = 21864 AND kode = 'DXF-020100-G01129' AND blok_id = 6;
UPDATE blok SET jumlah_bidang_estimasi = 272, diubah_pada = NOW()
 WHERE id = 19 AND jumlah_bidang_estimasi = 271;
```

Audit **#2978** (bidang 21864) dan **#2979** (blok 19). Anggota BLOK 8
**271 → 272**, BLOK 9 **366 → 365**; geometri BLOK 8 tidak disentuh
(MD5 WKT tetap `91e8a2880407d4ff22c957a40b3dfe42`). Uji API
`api/bidang.php?lapisan=bidang&blok=BLOK 8`: lapisan `tanah` **137 → 138**
(memuat G01129 dengan `blok = "BLOK 8"`, `terpotong=false`), lapisan `lain`
tetap 134. Sisa 22 bidang lain yang pusatnya di dalam BLOK 8 tetap bukan
anggota (21 berstatus `perlu_verifikasi` dan `DXF-020100-G00819` yang hanya
48,57 % di dalam) — belum dipindah sesuai keputusan pengguna.

Cadangan di `D:\backup\sigpetades\bidang-blok8-20261107\` (di luar repo):
`sebelum-pindah-g01129-20261107.sql` 9.526.250 byte, SHA-256
`d9993356ba9156d746dd1f7e8341f989dbb94300bb3d2c78f4f0b28c7608b94b`
(diuji restore: audit #2977, 271 anggota B8, 366 anggota B9) dan
`sesudah-pindah-g01129-20261107.sql` 9.526.779 byte, SHA-256
`6b5d8b95fa7286b6ed921f49a00779836a737bba8e8733035bfca81b59f80355`
(diuji restore: audit #2979, `blok_id=19`, estimasi 272, 272/365 anggota);
kedua DB uji dihapus. SQL pembatalan ada di `rollback-g01129.json` pada folder
yang sama.

### 3.8o Batas BLOK 8 ditetapkan sebagai batas tersedia (7 Nov 2026)

Perintah petugas **"tetapkan blok 08"** dijalankan pukul 13:18, mengikuti
konvensi yang dipakai BLOK 19/21/22: flag dibalik **dan** kolom estimasi
dibersihkan, bukan hanya membalik penanda. Prasyarat diukur lebih dulu:
geometri **1 bagian, 0 lubang**, `ST_IsSimple = 1`, 266.074,13 m²,
**0 m² tumpang** dengan 23 blok lain, dan **272/272 anggota ≥99 % di dalam**
(semua pusat bidang di dalam). Transaksi:

```sql
UPDATE blok
   SET batas_tersedia = 1, batas_perkiraan = 0,
       jumlah_bidang_estimasi = NULL, maks_jarak_estimasi_m = NULL,
       sumber_perkiraan = 'gabungan bidang anggota + penyangga 15 m; ditetapkan',
       diubah_pada = NOW()
 WHERE id = 19 AND kode = 'BLOK 8'
   AND batas_tersedia = 0 AND batas_perkiraan = 1;
```

Geometri **tidak disentuh** (MD5 WKT tetap
`91e8a2880407d4ff22c957a40b3dfe42`, 266.074,13 m²); `sumber_perkiraan`
52 karakter. Audit **#2980**. Hasil: **24/24 blok** `batas_tersedia = 1`,
`SUM(batas_perkiraan) = 0`; uji asap `api/bidang.php?lapisan=blok` HTTP 200,
**24 fitur**, BLOK 08 `batas_tersedia=true`, `batas_perkiraan=false`,
`estimasi_bidang=null` — jadi garisnya tampil penuh (tidak putus-putus) dan
legenda "Batas ukur tersedia" berlaku. Catatan penting: garis yang ditetapkan
tetap hasil **selubung 15 m dipotong blok resmi**, bukan garis ukur dari DXF;
penetapan ini administratif dan tidak mengubah bentuk.

Cadangan di `D:\backup\sigpetades\batas-tersedia-b8-20261107\` (di luar repo):
`sebelum-tetapkan-b8-20261107.sql` 9.526.779 byte, SHA-256
`fefa2b2ba3407dc0ab15777fbcfd14ddb55274f3f3de936e34baa76e85a38aa1`
(diuji restore: flag `0/1`, estimasi 272, audit #2979) dan
`sesudah-tetapkan-b8-20261107.sql` 9.527.454 byte, SHA-256
`81ef402b5a94949143911b22a17bc0d69070b8243acd93c6337c55a72da36dc0`
(diuji restore: flag `1/0`, estimasi NULL, audit #2980, 24 tersedia / 0
perkiraan); kedua DB uji dihapus. SQL pembatalan ada di
`rollback-tetapkan-b8.json`.

### 3.9 Bidang kembar (status duplikat)

Sebagian bidang tercatat lebih dari satu kali dengan **poligon yang persis sama**.
Ini bukan akibat impor yang berjalan dua kali — berkas GeoJSON sumber memang sudah
memuatnya: `bidang_lain.geojson` mengulang kelompok poligon dengan nomor `DXF-*`
yang berbeda (misalnya `DXF-020100-1563` kembar dengan `DXF-020100-0021`), dan
`bidang_tanah.geojson` mengulang beberapa `HM-*` dengan nib dan luas yang sama.
Hitungannya per 19 Oktober 2026, diukur dengan sidik teks: **334 grup, 680 baris,
346 baris di antaranya berlebih**. Diukur dengan kesamaan geometri, angkanya naik
(lihat di bawah). Sidiknya ada di `_lama\scratch\sidik-kembar*.php`.

```
D:\xampp\php\php.exe tools\impor.php duplikat                     # periksa saja
D:\xampp\php\php.exe tools\impor.php duplikat --terapkan          # jalankan
D:\xampp\php\php.exe tools\impor.php duplikat HM-0423 --terapkan  # grup beda blok,
                                                                   # salinan ini dibuang
D:\xampp\php\php.exe tools\impor.php kembar-tepat                 # kembar yang lolos ST_Equals
D:\xampp\php\php.exe tools\impor.php kembar-tepat --terapkan
```

Perintah ini mengelompokkan bidang berdasar **`ST_Equals`** — identik secara
topologi, bukan sama sebagai teks. `GROUP BY` atas teks WKT tidak dipakai karena
kelompok itu dipotong `max_sort_length` 1024 bait, dan sidik teks
`MD5(ST_AsText(geom))` yang dipakai pertama kali (343 bidang, 19 Okt) ternyata
terlalu ketat: GeoJSON sumber ada yang menyimpan cincin yang sama dengan titik awal
atau arah putaran berbeda, sehingga bentuknya satu bidang tapi teksnya beda.
Pengukuran atas 3.700 bidang hidup (`_lama\scratch\kembaran-stequals.php`) menemukan
**43 grup kembar, 40 di antaranya lolos dari sidik teks** — semua beranggota dua
bidang, 39 tercatat di blok yang sama dan 3 sisanya adalah grup lama yang sudah
dilewati. `MBRIntersects` dipakai sebagai prasyarat murah supaya indeks ruang terpakai;
dengan itu seluruh pemeriksaan atas 3.700 bidang hidup selesai ±8 detik.

Dari setiap grup disisakan satu bidang hidup: yang berstatus aktif, lalu yang datanya
paling lengkap, lalu id terkecil. Sisanya diberi `status = 'duplikat'` dan catatan
yang menyebut kode pasangannya. Blok, nib dan luas tidak disentuh, jadi penandaan
ini bisa dibalik dari `storage\duplikat-bidang-<waktu>.json`.

`ST_Equals` masih punya satu buta: dua poligon yang menelan satu sama lain sampai
penuh tapi berbeda pada titik terakhirnya. `kembar-tepat` mencari itu dengan pengukur
wilayah, bukan pengukur topologi — irisannya harus >= 99% dari luas masing-masing dan
selisih luas tercatatnya <= 1 m² (`pasanganKembarTepat()`). Luas wilayahnya memakai
`ST_Area(geom)` di kedua sisi; geometri disimpan tanpa SRID sehingga satuannya derajat
persegi, dan membandingkannya dengan `luas_m2` membuat rasio itu selalu 0. Diukur
20 Okt 2026 atas seluruh tabel: **403 pasangan lolos, hanya 4 yang kedua duanya masih
hidup**. Tiga di antaranya ditandai duplikat (3.665 m² yang tercatat dua kali):

| ditandai | dibiarkan hidup | blok | identitas yang tinggal |
|---|---|---|---|
| `DXF-020100-0055` | `HM-134C` | BLOK 10 | nis 134C, daftar 02779 |
| `DXF-020100-2515` | `DXF-020100-0197` | BLOK 5 | daftar 04487, TRIMO JM |
| `DXF-020100-2479` | `HM-1830D` | BLOK 12 | nis 1830D, daftar 03658, RAMIDI |

Ketiganya bidang kosong melompong yang kembarannya sudah bersandar di nomor. Grup
keempat — `HM-0079` dengan `HM-0094`, dua-duanya `aktif`, dua-duanya nib 00989, sama-
sama 2.491 m² — **tidak pernah ditandai otomatis**: `aktif` artinya sudah dibaca orang,
dan memilih salah satunya adalah keputusan petugas, jadi perintah itu hanya
melaporkannya. Cadangannya `storage\kembar-tepat-bidang-<waktu>.json`. Batas 99% itu
juga yang menahan *kulit penutup*: `DXF-020100-0057` (31.991 m²) menjepit `HM-114C` +
`HM-164A` + `HM-1559D` di dalam satu poligon, tapi irisannya terhadap tiap anak hanya
sebagian kecil luas anak itu, jadi ia bukan kembar dan memang harus dikerjakan sebagai
bidang sendiri (lihat 3.17).

Empat hal yang perlu diketahui:

- **Grup yang anggotanya tercatat di blok berlainan tidak diputuskan otomatis.**
  Menyisakan satu anggotanya dengan aturan biasa berarti memindahkan bidang itu ke
  blok pasangan. Petugas yang memutuskan, dan kalau pilihannya sudah pasti, cukup
  sebut kode salinan yang dibuang: `duplikat HM-0423 HM-0424 HM-0425 --terapkan`.
  Grup itu baru terbuka kalau persis satu anggota tetap hidup; kalau tidak, ia tetap
  dilaporkan sebagai DILEWATI. Kode yang tidak ada di grup kembar mana pun dilaporkan
  sebagai PERINGATAN dan tidak ditandai apa pun. Geometri tidak ikut dipindah — yang
  berubah hanya status dan catatan.
  Tiga grup semacam itu diputuskan pada 19 Okt 2026 dengan BLOK 7 sebagai yang hidup:
  `HM-0423`/`HM-0424`/`HM-0425` (BLOK 9) dibuang, pasangannya `HM-0432`/`HM-0431`/
  `HM-0430` (BLOK 7) tetap. Bukunya: keenamnya poligon identik, NIB sama (01046,
  01493, M.11), nama pemegang sama, dan ketiganya `perlu_verifikasi`; keduanya bahkan
  jatuh di **luar** garis BLOK 7 maupun BLOK 9 (jarak ke garis BLOK 9 290-326 m, ke
  garis BLOK 7 0-23 m), nomor HM 0423-0425 adalah satu-satunya yang nyempil di bawah
  HM-0471 pada deret BLOK 9, dan satu-satunya catatan PBB atas nama tiga pemegangnya —
  `MULYO WIJOYO REBIN`, NOP `33240400080`**`070`**`0010` — menunjuk BLOK 7. Riwayat
  audit menjelaskan kenapa mereka terpisah: 17:08 perintah `pindah` membawa keenamnya
  dari BLOK 3 ke BLOK 9, lalu 17:16 keputusan petugas menarik `HM-0430/0431/0432` ke
  BLOK 7 — hanya salah satu salinan yang tersentuh. Rinciannya ada di
  `_lama\scratch\detail-kembar-ditahan.php` dan `_lama\scratch\jarak-kembar-ditahan.php`.
- **`duplikat` sudah disaring dari aplikasi, tapi belum dari laporan CSV.**
  `bidang_filter()` — satu gerbang untuk lapisan peta, daftar bidang, dan
  `api/bidang.php` — sekarang memakai `status NOT IN ('dihapus', 'duplikat')`, begitu
  pula kartu dasbor (`statistik_bidang()`) dan komposisi hak. Angka luas ikut turun dari
  4.169.068 m² menjadi **3.749.298 m²** dan peta menampilkan 3.654 bidang, bukan 4.043.
  Yang disembunyikan tidak hilang diam-diam: kartu "Bidang tercatat" menyebut
  `389 kembar tidak dihitung` sebagai tautan ke `bidang.php?status=duplikat`, dan status
  itu memang masih bisa dipilih di saringan. `tools\ekspor.php` sengaja tetap
  mengekspor semua baris — laporan punya kolom status sendiri.
- **Angka kembar di laporan CSV ikut berubah.** `tools\ekspor.php` membangun grup dari
  kedua pembanding — `ST_Equals` dan `pasanganKembarTepat()` — jadi
  `04-bidang-kembar.csv` melaporkan **374 grup / 764 baris** dan tiap barisnya punya
  kolom `Pembanding`: `ST_Equals` atau `ST_Equals + irisan penuh`. Sembilan baris
  memakai pembanding kedua, dan empat grup di antaranya tidak akan muncul kalau
  laporan hanya membaca `ST_Equals`. Laporan yang diekspor sebelum penandaan ini
  memakai hitungan lama (334 grup dari sidik teks, lalu 371 grup dari `ST_Equals`
  saja) dan sudah tidak cocok dengan isi
  basis data — ekspor ulang.
- **Kolom `Luas tanpa kembar` ikut bergerak.** Dengan 389 baris berstatus `duplikat`,
  `00-keterangan.csv` mencatat 4.169.068 m² tercatat lawan **3.749.298 m² tanpa
  kembar** — sama dengan angka dasbor, karena keduanya membuang status yang sama.

### 3.10 Ekspor laporan lengkap (CSV)

Untuk menyerahkan data ke desa atau memeriksa sendiri kelengkapan isinya, seluruh
basis data bisa ditulis menjadi tujuh berkas CSV:

```
D:\xampp\php\php.exe tools\ekspor.php
D:\xampp\php\php.exe tools\ekspor.php --tahun=2025
```

Hasilnya masuk ke `storage\laporan-<waktu>\`: `00-keterangan.csv` (angka pokok +
bagian mana yang masih kosong), `01-rekap-status.csv`, `02-rekap-blok.csv`
(menurut blok, termasuk luas garis batas dan berapa bagian garis itu),
`03-bidang.csv` (semua bidang: pemegang, hak, penggunaan, PBB, catatan),
`04-bidang-kembar.csv` (semua grup poligon identik), `05-komposisi-hak.csv`, dan
`06-belum-terpetakan.csv` — daftar kerja persil yang belum masuk peta, dua golongan
([§3.20](#320-sisa-yang-belum-terpetakan-diukur-dari-seluruh-lapisan-peta-master)): `A`
cincin MASTER.dxf tanpa baris bidang, `B` nomor register peta yang labelnya berdiri di
dalam garis blok tanpa poligon. Golongan `B` adalah pekerjaan digitasi, jadi berkasnya
sengaja dilengkapi bujur/lintang supaya bisa dibawa ke lapangan. Bagian itu satu-satunya
yang membaca peta master, jadi ia juga yang paling lama; susunannya dihasilkan
`daftarBelumTerpetakan()` di `tools\lib-impor.php`, tempat skrip pengukuran
`_lama\scratch\daftar-belum-terpetakan.php` mengambil angka yang sama.
Berkas ber-BOM dengan pemisah titik-koma sehingga langsung benar dibuka Excel
Indonesia. Perkakas ini hanya membaca; setiap jalanannya dicatat ke `audit_log`
sebagai ekspor. Namanya `ekspor.php`, bukan `laporan.php`, karena halaman laporan
warga sudah memakai nama itu di folder akar.

### 3.11 Menautkan NOP PBB ke bidang peta

Dua sisi data desa punya nomornya masing-masing dan **tidak punya kunci bidang yang
sama**. Yang terbukti sudah diuji:

| Dicoba | Hasil |
| --- | --- |
| `bidang.nib` (`M.580`, `00752`) vs persil di `objek_pajak.alamat_op` (`EX PSL 24B`) | 2 kecocokan dari 1.262 nib; garis dasar acaknya 2,4. Bukan kunci, hanya kebetulan. |
| `bidang_sppt_gedong` dan `asset_sppt` di dump lama (tabel jodoh NOP↔blok) | keduanya 0 baris — tidak pernah diisi |
| `bidang_pemetaan.nop` di dump lama | 4 baris contoh, `nop` NULL semua |
| luas bumi SPPT vs luas poligon | `luas_bumi_m2` NULL untuk 3.794 objek |
| nama pemegang hak vs nama wajib pajak | `hak.pemegang_nama` dulu kosong 4.043/4.043; nomornya diambil dari label peta (lihat 3.12), tapi nama yang sama dipakai banyak orang sehingga tidak pernah jadi kunci |

Yang **bisa** dipastikan justru tingkat blok. Pada 18 digit NOP, digit 12-14 berisi
kode blok kelipatan sepuluh (`020` = BLOK 2, `260` = BLOK 26), dan setiap nomor yang
muncul ada di peta:

```
D:\xampp\php\php.exe tools\impor.php taut-blok            # periksa dulu
D:\xampp\php\php.exe tools\impor.php taut-blok --terapkan # 3.794 objek tertaut
```

Perintah itu menambah kolom `objek_pajak.blok_id` bila belum ada (sekali `ALTER`,
lalu diisi), mencatat tiap blok ke `audit_log`, dan menulis cadangan JSON ke
`storage/`. Menjalankannya lagi tidak mengubah apa pun, jadi aman dipakai untuk
menyinkronkan setelah impor SPPT berikutnya — importer `sppt` dan `sppt-dump` sudah
mengisi kolom ini sendiri saat menyisipkan baris. Halaman `sppt.php` ikut dapat
saringan **Blok peta** dan kolom **Blok**, `api/sppt.php?blok=<id blok>` mengikuti.

Untuk jodoh sebidang per sebidang, alatnya membantu memilih, tidak memutuskan:

```
D:\xampp\php\php.exe tools\impor.php calon 7
D:\xampp\php\php.exe tools\impor.php taut HM-0414 332404000800700010 --terapkan
```

`calon` mencetak setiap bidang tanah yang belum tertaut di blok itu bersama tiga calon
NOP terdekat menurut nama (lihat 3.12), dan `taut` menolak NOP di luar kode wilayah
desa, menolak NOP yang sudah tertaut ke bidang lain, serta memperingatkan bila blok
pada NOP tidak sama dengan blok bidang — tetap jalan kalau memang begitu keadaannya.
Di web, tombol **Tautkan NOP** pada halaman bidang menampilkan daftar calon yang sama,
tersortir dengan angka kemiripan nama, jadi petugas tinggal mencentang sambil membuka
buku SPPT.

### 3.12 Nama pemegang dari label peta

Basis data sempat tidak punya satu pun nama pemegang — `hak.pemegang_nama` kosong
4.043/4.043 dan `buku_c.nama_pemegang` kosong 1.419/1.419 — padahal MASTER.dxf
menyimpan **4.409 label TEXT** pada lapisan `tn_nama`. Jadi namanya tidak hilang,
hanya belum diimpor:

```
D:\xampp\php\php.exe tools\impor.php nama            # periksa dulu
D:\xampp\php\php.exe tools\impor.php nama --terapkan # 1.282 bidang terisi saat pertama dijalankan
```

Titik sisip tiap label dipindahkan ke WGS84 dengan transformasi affine yang sama
seperti perintah `gg` (RMS 1,2 m), lalu dicari bidangnya dengan empat aturan, dari
yang paling pasti:

| Aturan | Kapan nama dipakai | Bidang terisi |
| --- | --- | --- |
| `label` | titik sisip jatuh di dalam poligon bidang hidup (`ST_Contains`; poligon terkecil kalau bertumpuk) | 1.243 |
| `tepi` | label tidak jatuh di mana pun, tapi kurang dari 2 m dari **satu-satunya** bidang hidup | 11 |
| `terdekat` | label tidak jatuh di mana pun dan tak lolos aturan tepi, tapi kurang dari 10 m dari satu bidang tanpa nama yang tidak bersaing dengan bidang hidup mana pun atas label itu, dan bidang itu hanya punya satu nama semacam ini. Sejak 2 November 2026 jarak disaring lewat kotak tepi bidang (bukan titik tengah) dan pesaing harus lebih jauh ≥1 m — lihat 3.32 | 4 |
| `kembaran` | bidang tidak berlabel, poligonnya identik (`ST_Equals`) dengan bidang hidup lain yang sudah bernama | 28 |

Label yang bukan nama orang disaring lebih dulu (`namaLayak`): di lapisan itu juga
tertulis `MUSHOLA`, `MASJID`, `AA`, `JALAN`, `TANAH DESA`, `BALAI DESA`, `PECAH 3
UTARA-SELATAN SAMA LUAS` dan catatan seperti `DULU SUDAH PERNAH DAFTAR`, termasuk
deretan jabatan desa (`BENGKOK KADES`, `BENGKOK KAUR KEUANGAN`) — `bengkok` di desa
ini memang bangunan kantor, bukan orang. Penyaringan itu berlaku untuk kedua pengimpor
nama dan pembandingannya memakai nilai yang sudah disaring, jadi anotasi lama tidak
dihitung sebagai "nama yang sudah ada": `HM-1415 MUSHOLA / SANTOSO` menjadi `SANTOSO`,
`HM-789D JALAN` boleh digantikan label sungguhan. Delapan bidang yang terlanjur
menyimpan anotasi demikian dibersihkan; lima di antaranya memang tanah fasilitas umum,
jadi namanya sekarang kosong. Beberapa bidang memuat dua nama (suami-istri) dan ditulis
dipisah ` / `. Kolom yang sudah terisi tidak ditimpa kalau isinya berbeda, dan keadaan
sebelum perubahan ditulis ke cadangan JSON di `storage/` plus satu baris `audit_log`;
kolom `catatan` bidang tidak pernah disentuh.

Batas lain dipasang setelah terlihat satu bidang kantor desa menampung 17 label
tetangganya: `NAMA_MAKS_BAGIAN = 4`. Bidang yang kebagian lebih dari empat label tidak
ditulis namanya sama sekali, hanya dilaporkan `! HM-0622: kebagian 17 label (maks 4)` —
gundukan semacam itu bukan satu pemegang, dan CSV-nya tidak bisa dibaca petugas.

Aturan `tepi` dan `kembaran` ada karena dua hal yang terukur di peta ini. Konversi
DXF sesedikit bergeser, sehingga 51 dari 82 bidang tanpa nama sebenarnya punya label
dalam radius 2 m — hanya duduk di atas garis tepi sehingga `ST_Contains` menolak, dan
nama baru dipakai kalau persaingannya nol. Dan 28 bidang lain ternyata kembaran
geometris bidang yang sudah bernama: bagiannya lolos penandaan `duplikat` karena
aturan waktu itu membandingkan teks WKT, bukan bentuk — lihat 3.9. Kembaran itu
dipakai sebagai sumber nama lebih dulu, lalu **40 pasangan yang identik secara
geometri ditandai `duplikat` pada 19 Oktober 2026**, diikuti tiga grup kembar beda
blok yang salinannya dipilih petugas (lihat 3.9). Cakupan nama hidup karena itu turun
dari 1.282/1.331 menjadi 1.255/1.302: 27 nama ikut tersisih bersama bidangnya, dan 47
bidang tanah yang sekarang hidup memang tidak berlabel. Aturan `terdekat`, yang
dipasang setelah penandaan itu, menaikkan cakupannya menjadi **1.259/1.302**; 43 bidang
hidup masih tanpa nama dan tidak ada lagi label peta yang bisa dipertanggungjawabkan
untuk mereka — sisanya pertanyaan lapangan, bukan algoritma.

#### Nama bidang perlu_verifikasi lintas lapisan

Cakupan 1.259/1.302 di atas hanya menghitung bidang `tipe = 'tanah'`. Padahal hampir
semua bidang berstatus `perlu_verifikasi` justru duduk di lapisan `020100` dan
ber-`tipe = 'lain'` — lihat 3.13 — sehingga perintah `nama` tidak pernah membaca
labelnya: 2.355 bidang `lain` tanpa satu pun nama. Untuk mereka ada `nama-verifikasi`,
aturan `label` saja (titik sisip di dalam poligon, `ST_Contains`, poligon terkecil kalau
bertumpuk) tanpa batasan `tipe`:

```
D:\xampp\php\php.exe tools\impor.php nama-verifikasi            # periksa dulu
D:\xampp\php\php.exe tools\impor.php nama-verifikasi --terapkan # 2.207 bidang terisi
```

Angka 19 Oktober 2026, sama di basis data uji dan produksi: sasaran 2.879 bidang
`perlu_verifikasi`; 4.302 label lolos penyaringan; 2.702 sasaran kebagian paling tidak
satu label; **2.207 terisi** (sisanya sudah bernama), 71 di antaranya memuat dua nama
atau lebih, dan 177 sasaran memang tidak berlabel. `berebut = 0` — tidak ada satu pun
nama yang ditimpa. Setelah itu cakupan seluruh bidang hidup naik dari 1.259/3.657
(34%) menjadi **3.464 dari 3.654 bidang hidup (94,8%)**. Angka itu berubah lagi sesudah
[§3.19](#319-persil-dxf-yang-flag-nya-tidak-pernah-ditutup) memasang 343 + 35 persil pemulihan
dan `nama-verifikasi` dijalankan ulang: kini **3.696 dari 4.045 (91,4%)** sesudah tiga persil
`HM` ([§3.20](#320-sisa-yang-belum-terpetakan-diukur-dari-seluruh-lapisan-peta-master)) ikut
terisi namanya. Tiga puluh lima
bidang ronde kedua semuanya kebagian nama, jadi jumlah yang tanpa nama tidak berubah: tetap
**349**, termasuk 159 dari 343 pendatang baru §3.19 ronde pertama:

| Tipe / status | Bidang hidup | Bernama |
| --- | --- | --- |
| `tanah` / `aktif` | 778 | 756 |
| `tanah` / `perlu_verifikasi` | 526 | 503 |
| `lain` / `perlu_verifikasi` | 2.728 | 2.424 |

Aturan `tepi` dan `terdekat` sengaja tidak dipakai di sini: keduanya ditakar untuk
bidang tanah dan belum pernah diukur untuk lapisan `020100`, jadi memakainya sekarang
sama dengan mengarang. Baris `hak` bidang-bidang ini (jenis `BLM` hasil digitasi) hanya
disentuh kolom `pemegang_nama`-nya; `jenis_hak_id` dan `catatan` dibiarkan, provenansi
namanya ada di `audit_log`.

Nama itu yang membuat calon tautan bisa diurutkan. Sudah diukur, bukan diasumsikan:

| Diuji | Hasil |
| --- | --- |
| Bidang yang namanya hanya cocok dengan satu NOP seblok | 324 bidang |
| Kontrol: nama diambil acak dari pool se-desa | 139 bidang |
| Pasangan menurut nama yang ternyata sama dengan pasangan menurut urutan baris | 3 dari 323 (0,9%) |

Baris terakhir itu yang penting: kecocokan nama **bukan** hasil menyamakan urutan
baris — kalau iya, angkanya harus 323. Angka 1 dan 2 pada kolom "Nama" di web ialah
skor kemiripan (`skor_nama`): 3 sama persis, 2 saling memuat, 1 satu unsur sama.
Skor hanya urutan; satu nama bisa dipakai beberapa wajib pajak dan label peta bisa
saja menempel ke bidang tetangga, jadi petugas yang menautkan. Tabel di atas diukur
sebelum 2.207 bidang `lain` bernama — menjalankan `calon` ulang sekarang bisa
menyusun urutan yang berbeda, tapi kesimpulannya (nama hanya urutan, pasangan tetap
keputusan manusia) tidak berubah.

### 3.13 NIS alas hak dari label peta

Bidang berstatus `perlu_verifikasi` kebanyakan tidak punya NIB sama sekali, padahal
petugas butuh pegangan saat mencocokkan ke daftar alas hak. Angka itu ada di peta:
MASTER.dxf memuat **2.851 TEXT** pada lapisan `tn_alashak` berbentuk `NIS : 217b`,
`NIS : A100`, dan sejenisnya.

```
D:\xampp\php\php.exe tools\impor.php nis            # periksa dulu
D:\xampp\php\php.exe tools\impor.php nis --terapkan # 1.817 bidang terisi
D:\xampp\php\php.exe tools\impor.php nis --terapkan --semua   # semua bidang hidup
```

**NIS bukan NIB.** 1.266 nilai `nib` di basis data tidak bersinggungan satu pun
dengan 2.696 NIS unik pada label (irisannya nol), jadi nomor ini disimpan pada
kolomnya sendiri, `bidang.nis`, dan tidak pernah menimpa `nib`. Kolom itu dibuat
otomatis oleh perintah ini pada basis data lama; pada pemasangan baru sudah ada di
`sql/01_skema.sql`.

Yang mengejutkan, labelnya sebagian besar bukan duduk di atas bidang `tanah`:

| Lapisan poligon | Bidang hidup | Label NIS jatuh di dalamnya |
| --- | --- | --- |
| `BIDANG HM` (`tipe = tanah`, bernib) | 1.302 | 148 |
| `020100` (`tipe = lain`, tidak bernib) | 2.355 | 1.995 |
| tidak ada poligon | — | 689 |

Kedua lapisan itu bukan salinan satu sama lain: dari 2.143 label yang kebagian
poligon, **nol** yang jatuh di kedua lapisan sekaligus, dan hanya 109 dari 2.355
pusat bidang `lain` yang jatuh di dalam sebuah bidang `tanah`. Jadi `020100` adalah
bagian desa yang belum tersentuh digitasi HM, bukan duplikatnya — dan aturan yang
membatasi diri ke `tipe = tanah` akan membuang 1.995 dari 2.143 nomor, bukan menjaga
kebenarannya.

Sisanya sama seperti impor lain: satu NIS per bidang, beberapa NIS ditulis dipisah
` / ` (184 bidang, contoh `C210 / 536D`), nilai yang sudah ada tidak ditimpa kalau
berbeda, dan angka sebelum perubahan masuk ke cadangan JSON di `storage/` serta satu
baris `audit_log`. Bawaan perintah hanya menyentuh bidang `perlu_verifikasi` —
1.817 dari 2.879 bidang sasaran, terdiri atas 1.772 bidang `lain` dan 45 bidang
`tanah`. NIS muncul di halaman bidang, di sematan peta, dan sebagai kolom `NIS`
pada `03-bidang.csv`; kolom pencarian di daftar bidang ikut membaca `nis`, jadi
`204B` menemukan HM-1235.

Dari 689 label yang titiknya tidak jatuh di poligon mana pun, **36 akhirnya
terpinjam** pada 20 Okt 2026 dengan aturan tepi `labelTepiNomor()` (lihat 3.14):
penyangga 2 m harus menyentuh tepat satu bidang sasaran yang belum punya NIS sendiri.
642 label ditolak, dan dua peminjaman dibatalkan lagi karena nomor yang sama
`222B` tertulis dua kali di peta dan menunjuk dua bidang (`DXF-020100-1977` dan
`DXF-020100-0317`) — tidak ada yang diisi, keduanya dilaporkan.

#### Kode bidang dari NIS

Kode sementara hasil digitasi (`DXF-020100-0453`) tidak berguna bagi petugas di
lapangan, jadi ada perintah untuk menggantinya dengan nomornya sendiri:

```
D:\xampp\php\php.exe tools\impor.php kode-nis            # periksa dulu
D:\xampp\php\php.exe tools\impor.php kode-nis --terapkan # 1.582 bidang berganti
```

Pola yang dipakai `HM-<nis>` apa adanya, termasuk akhiran ` K3` (`HM-148D K3`).
Hanya bidang dengan **satu** NIS **unik** yang diganti; yang lain sengaja dibiarkan
berkode lama dan penyebabnya dicetak satu per satu:

| Yang tidak diganti | Bidang | Sebab |
| --- | --- | --- |
| tanpa NIS | 800 | tidak ada nomor untuk dipakai |
| NIS lebih dari satu | 178 | `205B / 148D K3 / 149D K3` — memilih satu berarti menebak |
| NIS tercatat di dua bidang | 10 | `1071D` ada di DXF-020100-2132 **dan** -2133; `kode` punya UNIQUE |
| nomornya sudah jadi kode bidang lain | 2 | `HM-1074` dan `HM-1220` sudah dipakai bidang tanah `BIDANG HM` |

Setelah penukaran: 1.582 bidang `020100` berkode `HM-…`, 990 tetap `DXF-…`, dan 4.043
kode masih unik tanpa satu pun dobel. Semua foreign key menunjuk `bidang.id`, bukan
`kode`, jadi tidak ada relasi yang ikut berubah; `sumber` tetap `020100` sehingga
lapisan asalnya masih terbaca. **Ini bukan perubahan yang bertahan di atas impor
ulang** — `impor.php bidang` memberi kode DXF-nya kembali dari GeoJSON. Pasangan
lama→baru tersimpan di `storage/kode-nis-<waktu>.json` bila perlu dibaca balik.

### 3.14 Dua register nomor pada peta master

`nib` dan nomor yang ditulis lapisan `080201` **bukan register yang sama** — ini
terukur, bukan asumsi:

| Lapisan teks | Isi | Jumlah | Cocok dengan `nib` yang sudah terisi |
| --- | --- | --- | --- |
| `NIB HM` | `00828`, `M.254` | 1.657 | **1.264 cocok, 0 berbeda** |
| `080201` | `01823`…`05004` | 3.156 | 3 bidang memakainya bersama `nib`, ketiganya berbeda |

Jadi `NIB HM` memang sumber kolom `nib` (persil HM, 9..1526), sedangkan `080201`
adalah daftar nomor lain yang dipakai pada bagian desa hasil digitasi `020100` —
nomornya rapat ke nomor tetangga (bidang sebarat-setimur di BLOK 2 bernomor
1909-1984), nol nomornya yang dipakai dua bidang hidup, dan nol yang sama dengan
`nis`. Karena keduanya bisa menyebut bidang yang sama dengan nomor berbeda, `080201`
disimpan di kolom sendiri: `bidang.nomor_daftar`.

```
D:\xampp\php\php.exe tools\impor.php nib                # periksa dulu
D:\xampp\php\php.exe tools\impor.php nib --terapkan     # nib dari lapisan NIB HM
D:\xampp\php\php.exe tools\impor.php daftar             # periksa dulu
D:\xampp\php\php.exe tools\impor.php daftar --terapkan  # nomor_daftar dari 080201
```

Angka 19 Oktober 2026 di produksi, dari 2.879 bidang `perlu_verifikasi`: `nib`
bertambah **7** bidang dan berhenti di 520 — 2.366 di antaranya memang tidak punya
nomor di lapisan `NIB HM`, karena peta hanya menulis nomor persil HM. `nomor_daftar`
terisi **2.334** bidang (13 tipe tanah, 2.321 tipe lain), sisa 539 bidang tidak
kebagian label `080201` sama sekali. Setelah kedua perintah, `nib` seluruh tabel
berada di 1.442 bidang. Kedua perintah hanya mengisi kolom yang kosong; nilai yang
sudah ada tidak pernah ditimpa, keadaannya masuk cadangan JSON di `storage/` dan satu
baris `audit_log`, dan dijalankan ulang tetap 0 perubahan. Bidang yang kebagian dua
nomor berbeda dari satu lapisan tidak diisi, hanya dilaporkan — 6 bidang untuk
`080201`, nol untuk `NIB HM`:

```
! HM-A178: 01789 / 03019 — tidak diisi, periksa letak labelnya
```

Sebagian label tidak tertulis di tengah persilnya: kartu menggambar nomor dengan ujung
pena, jadi titiknya bisa jatuh beberapa puluh sentimeter di luar poligon yang ia
maksud. Aturan cadangannya `labelTepiNomor()` — sebuah nomor menganggur hanya dipinjam
kalau penyangga 2 m (`NOMOR_TOLERANSI_M`) menyentuh **tepat satu** bidang hidup yang
kolomnya masih kosong, nomor itu belum terpasang di bidang lain, dan bidang itu tidak
sudah punya labelnya sendiri. Uji kotak pembatas saja tidak cukup: `MBRIntersects`
meloloskan poligon yang tepinya 20 m jauhnya (terukur pada `080201` `01731` terhadap
`HM-1224`, 20,05 m), jadi jarak sebenarnya ikut diuji dengan `ST_Distance`. Atas 769
label `080201` dan 359 `NIB HM` yang menganggur, hasilnya 20 Okt 2026: **3 nomor
daftar terselamatkan** — `01800` ke `HM-1096` (1 m), `01816` ke `HM-0108` (0,26 m),
`03814` ke `DXF-020100-2258` (1,36 m) — dan **nol nib**. Yang ditolak bukan karena
jauh: `03215` berdiri 0,07 m dari `DXF-020100-0850` tapi 0,93 m juga dari
`DXF-020100-0074` yang nomornya sudah terpasang, dan `02813` titik jatuh di dalam
`HM-D689 K3` yang memegang dua nomor.

Petugas yang mencocokkan ke daftar desa sebaiknya memakai `nomor_daftar` untuk bidang
`020100` dan `nib` untuk persil HM; jangan menukar keduanya. Nomor daftar ikut
tampil di halaman bidang (dan bisa disunting di sana), di sematan peta, sebagai kolom
`Nomor daftar 080201` pada `03-bidang.csv`, dan dibaca juga oleh kolom pencarian di
daftar bidang.

### 3.15 Jenis hak bidang perlu_verifikasi

Bidang hasil digitasi lapisan `020100` masuk ke basis data tanpa keterangan jenis hak
dan diberi penanda sementara `BLM` ("Jenis hak tidak tercatat"). Atas keputusan
petugas, seluruh bidang `perlu_verifikasi` kini dicatat **Hak Milik (HM)**:

```
D:\xampp\php\php.exe tools\impor.php hak-milik            # periksa dulu
D:\xampp\php\php.exe tools\impor.php hak-milik --terapkan # 2.355 bidang jadi HM
```

Angka 20 Oktober 2026 di produksi: 2.354 baris `hak` berpindah dari `BLM` ke `HM` dan
1 baris dibuatkan karena bidangnya (`DXF-020100-0008`) belum punya baris hak sama
sekali. Setelah itu seluruh 2.879 bidang `perlu_verifikasi` ber-HM — 524 di antaranya
memang sudah HM sejak awal karena persil `BIDANG HM`. Yang **tidak** disentuh: 778
bidang `aktif`, 386 bidang `duplikat` (217 di antaranya masih `BLM` dan memang belum
selesai diverifikasi), dan semua baris yang sudah mencatat jenis sungguhan. Nomor
sertifikat, nomor hak pakai serta tautan Buku C pada baris yang diubah dibiarkan apa
adanya — nol di antaranya terisi, jadi tidak ada bukti tertulis yang ikut pindah.

Perintah ini hanya memindahkan jenis, tidak memutuskan apa pun tentang sertifikat:
kolom `Jenis hak` di halaman bidang, `05-komposisi-hak.csv` dan warna peta mengikuti
`hak.jenis_hak_id`, jadi HM di sini berarti "ditetapkan sebagai milik", bukan "sudah
bersertifikat". Jalankan ulang tetap 0 perubahan. **Ini tidak bertahan di atas impor
ulang**: `impor.php bidang` menyisipkan baris hak baru dengan penanda `BLM`
(`JENIS_HAK_DEFAULT`), jadi sesudah itu `hak-milik --terapkan` harus dijalankan lagi.
Keadaan sebelum perubahan ada di `storage/hak-milik-<waktu>.json` dan satu baris
`audit_log`.

Sejak 20 Okt 2026 perintah ini juga melewatkan bidang yang poligonya beranotasi
petugas (`GG`, `TANAH DESA`, `BENGKOK`, masjid, jalan) — lihat
[§3.18](#318-anotasi-petugas-di-atas-poligon-gg-tanah-desa-bengkok). Waktu aturan itu
belum ada, 35 bidang fasilitas ikut berpindah ke Hak Milik dan harus dibetulkan balik.
Label `GG` di atas persil yang punya nib, nis atau nomor daftar sendiri tidak
termasuk lewat: peta menggambar gang sebagai titik, jadi yang bergeser labelnya, bukan
status tanahnya.

### 3.16 Matikan mode debug

Setelah semuanya jalan, ubah `'debug' => true` menjadi `false` pada
`app/config.php`. Pemasangan ini sudah `false` sejak 20 Okt 2026 — dengan debug
hidup, layar galat 500 menuliskan pesan SQL mentah (termasuk nama tabel dan
kolom) ke peramban. Kembalikan ke `true` hanya sebentar saat mencari sebab
kegagalan.

### 3.17 Bidang yang masih tanpa identitas

Setelah `nis`, `nib`, `daftar`, `nama`, `nama-verifikasi`, `kode-nis`, `hak-milik`,
`duplikat` dan `kembar-tepat` dijalankan, sisa bidang hidup yang **tidak punya satu
pun** identitas (nib, nis, nomor daftar, nama pemegang) tinggal **10 dari 3.654**.
Daftar itu bisa dibaca ulang dengan `_lama\scratch\isi-sepuluh-sisa.php`; isinya
per 20 Okt 2026, dengan apa yang sebenarnya tertulis di atas poligonya:

Sepuluh itu tidak berubah sampai hari ini. Yang bertambah cuma dari
[§3.19](#319-persil-dxf-yang-flag-nya-tidak-pernah-ditutup): 37 dari 343 persil
pemulihan masih sama sekali kosong identitasnya (poligonnya berdiri tanpa label apa pun
di lapisan nomor), sehingga hitungannya kini **47 dari 4.045 bidang hidup** (sesudah seluruh
penulisan 20 Okt, [§3.21](#321-bidang-tanpa-blok-dan-dua-perintah-koreksi-data)). Tiga puluh lima
bidang pemulihan ronde kedua [§3.19](#319-persil-dxf-yang-flag-nya-tidak-pernah-ditutup)
tidak menambah daftar itu: 34 di antaranya memperoleh nomor daftar, 30 memperoleh NIS,
satu memperoleh nib, dan semuanya memperoleh nama.
| bidang | blok | luas | tertulis di atasnya | yang perlu dilakukan |
|---|---|---|---|---|
| `DXF-020100-0057` | 14 | 31.991 m² | `NIS : 114C/164A/1559D`, daftar `01533/02963/03819`, nama `BENGKOK CARIK, SUNIMAN, SUKATI` | **kulit penutup**, bukan persil: menelan penuh `HM-114C` (30.010) + `HM-164A` (1.269) + `HM-1559D` (713). Nomornya sudah tinggal di anaknya |
| `DXF-020100-0148` | 20 | 631 m² | `NIS : 143C/142C`, nama `MUHROJI, JUWARNO` | kulit penutup `HM-143C` (316) + `HM-142C` (315) |
| `DXF-020100-0153` | 23 | 1.982 m² | `NIS : 248B/124B`, nama `TUGIO, YUNIATI, GRACE NGARPIYAH`, catatan kartunya `PECAH 3 UTARA-SELATAN SAMA LUAS` | kulit penutup `HM-124B` (991) + `HM-248B` (991) |
| `DXF-020100-2572` | 4 | 12.259 m² | `GG` | **sudah dibaca** [§3.18](#318-anotasi-petugas-di-atas-poligon-gg-tanah-desa-bengkok): HP Hak Pakai + Fasilitas Umum; bentuk gangnya tetap menunggu digitasi `impor.php gang` |
| `DXF-020100-0039` | 14 | 532 m² | `BENGKOK BEKEL` | **sudah dibaca** §3.18: KDH milik pemerintah desa; NIS `1554D` ada tapi 9,8 m jauhnya |
| `DXF-020100-0011` | 22 | 36 m² | `TANAH DESA` | **sudah dibaca** §3.18: KDH, memang tidak berpemegang perorangan |
| `DXF-020100-0850` | 13 | 100 m² | (kosong) | label `080201` `03215` berdiri 0,07 m di sini tapi 0,93 m juga dari `DXF-020100-0074` yang sudah bernomor — dua bidang, satu label |
| `DXF-020100-1336` | 10 | 37 m² | (kosong) | `02813` 0,28 m, tapi titik labelnya jatuh di dalam `HM-D689 K3` yang memegang dua nomor (`02828` + `02813`) |
| `HM-1192` | 26 | 107 m² | (kosong) | `status aktif`; nib `01472` 0,82 m tapi sudah jadi milik `HM-1193`; `00814` masih bebas pada 3,47 m — di luar toleransi 2 m |
| `DXF-020100-0008` | 16 | 0 m² | (kosong) | serpihan tanpa luas, tidak ada label dalam 10 m; kandidat dibuang |

Tidak ada satu pun dari sepuluh ini yang bisa diselesaikan dengan aturan otomatis —
ketiganya yang pertama justru **jangan** ditandai duplikat, karena geometri induknya
memuat angka yang benar untuk beberapa persil sekaligus. Yang lain menunggu petugas
membaca buku letter C atau turun ke lapangan.

Satu hal yang harus dicek terpisah: `HM-0079` (YAMUJI) dan `HM-0094` (tanpa nama) di
BLOK 2 adalah kembar yang dua-duanya `aktif` dengan nib sama, `00989`, luas sama
pula 2.491 m². `kembar-tepat` menahannya dan hanya melaporkannya.

### 3.18 Anotasi petugas di atas poligon: GG, TANAH DESA, BENGKOK

`hak-milik` hanya membaca kolom jenis hak, tidak pernah membaca lapisan `tn_nama`.
Akibatnya bidang yang di atas poligonya tertulis `GG`, `TANAH DESA` atau `BENGKOK
KAUR KEUANGAN` ikut dipindah menjadi **Hak Milik** — padahal anotasi itulah bukti
bahwa tanahnya bukan milik orang. 20 Okt 2026 diukur dengan aturan yang sama seperti
impor nama (label dihitung untuk poligon hidup terkecil yang memuat titik labelnya):
**52 dari 3.654 bidang hidup** beranotasi petugas. Ukuran lepasnya bisa dibaca ulang
dengan `_lama\scratch\anotasi-fasilitas.php` (skrip itu memakai penyaring huruf ulang,
jadi ia menghitung 61 — `AA`, `AAA`, `PECAH` dan `DAFTAR` ikut terbawa; aturan alat hanya
memakai kata pada tabel di bawah).

```
D:\xampp\php\php.exe tools\impor.php hak-anotasi           # periksa dulu
D:\xampp\php\php.exe tools\impor.php hak-anotasi --semua   # termasuk bidang aktif
D:\xampp\php\php.exe tools\impor.php hak-anotasi --semua --terapkan
```

Pemetaannya ada di `ANOTASI_HAK` ([tools/lib-impor.php](tools/lib-impor.php)):

| anotasi | jenis hak yang ditetapkan | bidang | luas |
|---|---|---|---|
| `GG` (gang) — **hanya** bila bidangnya tidak punya nib, nis, nomor daftar dan tidak kebagian label nama | **HP Hak Pakai** | 1 | 12.259 m² |
| `TANAH DESA`, `BENGKOK` (termasuk bengkok KADUS / KAUR / BEKEL / LURAH / KESRA), `BALAI` | **KDH Milik Pemerintah Desa / Kas Desa** | 33 | 205.800 m² |

Penggunaan tanahnya ikut diisi **Fasilitas Umum** (`jenis_penggunaan_id` 7) pada 34
bidang itu — kolom yang sebelumnya kosong untuk seluruh 3.654 bidang hidup.

Yang **tidak** diubah alat:

* **16 bidang bermasjid, bermushola atau berlabel `JALAN`** (`ANOTASI_FASILITAS`).
  Masjid dan mushola di desa kebanyakan tanah wakaf, jalan punya ceritanya sendiri;
  keduanya tidak bisa dibaca dari label, jadi daftar itu hanya dilaporkan supaya
  petugas yang menetapkan jenisnya.
* **1 bidang** yang anotasi dan nama orang jatuh di poligon yang sama
  (`DXF-020100-0074`, `BENGKOK BEKEL KALIPLURU` + `KIRMAN`) — sama seperti 9 bidang
  lain pada daftar 20 Okt, label anotasi sudah ditolak `nama`, namanya yang menang.
* **1 bidang** yang label `GG`-nya mendarat di persil bernomor sendiri
  (`HM-128C`, `nis 128C` + nomor daftar `02710`) — dilaporkan sebagai
  `ditahan_gg_bergeser`, dan karena bidang ini sempat dipindah aturan lama, ia
  **dikembalikan ke Hak Milik** beserta penggunaan Fasilitas Umum yang dilepas
  (`akan_dipulihkan`). Lihat hasil audit di bawah.
* bidang yang baris `hak`-nya sudah mencatat jenis sungguhan selain HM/BLM/BLH, dan
  yang masih menyimpan nomor sertifikat, nomor hak pakai atau tautan Buku C. Nol di
  antara 34 sasaran punya bukti itu, dan **nol pula yang tertaut NOP**, jadi tidak ada
  satu SPPT pun yang jenisnya jadi bertentangan.

Bawaan perintah ini hanya menyentuh `perlu_verifikasi`, seperti `hak-milik`. Dengan
`--semua` ikut tiga bidang `aktif` yang bengkok (`HM-0622` BENGKOK KADES 41.050 m²,
`HM-0663` BENGKOK LURAH, `HM-0810` BENGKOK KADES CURUGSEWU). `HM-0663` masih
menyimpan nama `BA'I MUSTOFA / MISRI / SINI / YATIN SARNO` dari impor nama — itu
penggarap bengkok, bukan pemilik, dan nama itu sengaja tidak dihapus.

Hasil audit balik (20 Okt 2026, `_lama\scratch\audit-hak-anotasi.php`) — 35 bidang yang
dipindah perintah ini diperiksa satu per satu terhadap bukti yang tidak dibacanya:
nomor persil, nama, sertifikat, tautan Buku C, NOP, jumlah bidang hidup di dalamnya,
dan bentuk poligonya. **33 KDH berdiri**: 36 label satu keluarga `BENGKOK` memang jatuh
di dalam poligon, yang berpemegang hanya `HM-0663` dan keempat namanya itu penggarap
bengkok garap (`BA'I MUSTOFA / MISRI / SINI / YATIN SARNO`), jadi ia malah mendukung
KDH. **1 HP** (`DXF-020100-2572`, 12.259 m²) tetap: nol nib, nol nis, nol nomor daftar,
nol nama, nol NOP, dan hanya 38,9% dari kotak luarnya terisi tanah — bentuk yang wajar
untuk gang yang ikut terdigitasi. **1 HP dibatalkan**: `HM-128C` punya `nis 128C` dan
nomor daftar sendiri, bentuknya seluas persil biasa (rapuh 1,37 terhadap median bidang
hidup 1,22; isian kotak luarnya 54,9% terhadap median 53,9%), dan dari 15 label
`GG`/`TANAH GG` di peta hanya 2 yang mendarat di dalam poligon — 13 lainnya di ruang
terbuka di antara persil, karena peta master menggambar gang sebagai titik, bukan garis
([§3.6](#36-peta-gang-gg-menjadi-hak-pakai)). Label itu yang bergeser, bukan tanahnya
yang gang, jadi `GG` sejak itu hanya memutuskan bidang tanpa nomor. Nol dari 35 bidang
itu menyimpan nomor sertifikat, tautan Buku C, NOP, atau memuat bidang hidup lain di
dalamnya, sehingga tidak ada satu pun yang berubah karena bukti baru — hanya aturannya
yang diperketat.

Penjagaannya dipasang di dua arah: `hakMilikVerifikasi()` sekarang memanggil
`anotasiPetugas()` dan melewatkan bidang yang anotasinya menetapkan jenis (bengkok,
tanah desa, balai, gang pada bidang tanpa nomor) atau hanya beranotasi fasilitas umum,
selagi tidak ada nama orang yang bersaing, jadi sesudah `impor.php bidang` menyetel
ulang jenis ke `BLM` sekalipun, `hak-milik` tidak akan menjadikan gang dan bengkok Hak
Milik lagi. Persil bernomor seperti `HM-128C` justru dibiarkan lewat — ia memang harus
ber-HM. Angka kecualinya muncul sebagai `dikecualikan_beranotasi`. Jalankan ulang
`hak-anotasi` tetap 0 perubahan: 31 bidang terbaca sebagai `sudah_sesuai` (jenis dan
penggunaannya sudah benar, catatannya tidak ditulis ulang), bukan sebagai "keputusan
orang" seperti sebelum diaudit. Keadaan sebelum perubahan ada di
`storage/hak-anotasi-<waktu>.json` dan satu baris `audit_log` tiap kali menerapkan.

Ini berbeda dari [§3.6](#36-peta-gang-gg-menjadi-hak-pakai): di sana gang belum
digambar sama sekali dan `impor.php gg` mengumpulkan titik `GG` untuk didigitasi
menjadi bidang baru; yang di sini menangani label `GG` yang jatuh ke dalam poligon
yang **sudah ada** di basis data. Komposisi hak sesudah perubahan dan audit
(`05-komposisi-hak.csv` per 20 Okt, `storage\laporan-20261020-073251`): HM 3.792,
BLM 217 (semuanya `duplikat`), KDH 33, HP 1. Setelah 343 + 35 persil pemulihan
[§3.19](#319-persil-dxf-yang-flag-nya-tidak-pernah-ditutup) ikut ber-Hak Milik,
komposisi seluruh baris menjadi HM 4.183 · KDH 33 · HP 1 · BLM 217 (BLM tetap semuanya
`duplikat`), dan pada 4.045 bidang hidup: HM 4.011 · KDH 33 · HP 1 · BLM 0.

---

### 3.19 Persil DXF yang flag-nya tidak pernah ditutup

**Gejalanya.** Sebagian poligon peta master ada di layar AutoCAD tapi tidak pernah masuk
ke basis data, sehingga peta SIPETADES bolong-bolong di sela persil yang sudah tercatat.
Angka lebih dulu, sebab kemudian (`_lama\scratch\hitung-poligon-master.php` dan
`_lama\scratch\garis-terbuka-master.php`, keduanya hanya membaca):

- Importer **tidak** kehilangan apa pun dari yang dibacanya. `bidang_tanah.geojson` +
  `bidang_lain.geojson` membawa 4.043 fitur dan 4.043 baris masuk `bidang` (nol dilewati);
  lapisan `BIDANG HM` punya 1.471 ring tertutup dan 1.471 baris bersistem sumber
  `BIDANG HM`, luasnya berbeda 9 m² saja secara keseluruhan.
- Dari seluruh ring **tertutup** MASTER.dxf, cuma **11** yang tidak menemukan bidangnya,
  dan 7 baris basis data yang titik tengahnya tidak jatuh di dalam ring mana pun.
- Yang pada waktu itu belum terbaca sama sekali: lapisan `HM`, `020400`, `lb_lai` dan
  `BATAS BLOK` tidak pernah diumpankan ke `impor.php bidang`, dan `dxfPoligon()` hanya
  membaca `LWPOLYLINE`/`POLYLINE` — `LINE`, `ARC`, `CIRCLE` dan `INSERT` tidak pernah
  dijadikan poligon (276 + 4 entitas). Dari keempatnya hanya `HM` yang sudah ditangani:
  77 ringnya teruji, 74 sudah punya bidang, dan 3 persil sisangnya dipasang 20 Okt
  ([§3.20](#320-sisa-yang-belum-terpetakan-diukur-dari-seluruh-lapisan-peta-master)).
  `020400`, `lb_lai` dan 280 entitas garis/bulat masih seperti semula.

**Sebabnya.** `dxfPoligon()` menandai sebuah cincin tertutup **hanya** bila bit 1 dari
group code 70 terpasang. Di MASTER.dxf ada **1.892 `LWPOLYLINE` terbuka yang titik awal
dan titik akhirnya berjarak ≤ 1 cm** — 1.838 di antaranya di lapisan `020100`. Secara
geometri cincin itu tertutup; yang tidak pernah dipasang adalah flag-nya. Digitasi
2019 mengambil poligon dari perangkat GIS yang menutup sendiri, jadi yang hilang terjadi
di DXF, bukan di alat impor.

Kalau celah ≤ 1 cm itu ditutup, 1.876 ring terbentuk dan terbagi tiga:

| nasib | ring | keterangan |
|---|---|---|
| sama dengan persil yang sudah ada | 934 | memang sudah masuk, tidak ada yang dipulihkan |
| menumpang persil yang sudah ada | 507 | garis ganda/pecahan di atas bidang lama |
| **tidak menyentuh bidang mana pun** | **435** (310.992 m²) | 431 di `020100`, 4 di `BIDANG HM` |

Ke-435 calon itu semuanya berlabel di dalam poligon (`tn_nama`, `NIB HM` atau `080201` —
`_lama\scratch\cek-calon-bidang.php`), dan 384 di antaranya jatuh di dalam blok yang
sudah ada. Yang 51 berdiri di luar garis blok mana pun.

**Aturannya.** `impor.php dxf-persil` memasang calon itu dengan dua jaring penolakan,
jadi ia tidak bisa menimpa bidang lama:

1. dibaca semua `LWPOLYLINE`/`POLYLINE` di lapisan `BIDANG HM`, `020100` dan `HM`
   (`DXF_PERSIL_LAPISAN`) yang ringnya tertutup **atau** celah ujungnya ≤ `--celah`
   (bawaan 0,01 m), ≥ 3 titik;
2. dibuang yang titiknya masih di dekat titik nol affine (coretan, < 1.000 m) dan yang
   luasnya < `--minimal` (bawaan 20 m²) — serpihan penutupan morfologi yang pernah gagal
   di [§3.8](#38-batas-blok-dari-peta-dasar) tidak mungkin terulang dari sini;
3. ditolak bila **titik tengah atau salah satu sudutnya** sudah jatuh di dalam bidang
   hidup mana pun (kisi ±55 m, murah);
4. ditolak lagi dengan `ST_Intersection` bila ≥ 2 % luasnya menumpang bidang hidup
   (`DXF_PERSIL_TUMPANG`) — langkah 3 cuma penyaring, langkah 4 yang memutuskan;
5. calon yang saling menumpuk di antara dirinya sendiri disaring, yang terbesar dipakai;
6. blok diambil dari titik tengah yang jatuh di dalam garis luar blok — **tidak pernah**
   dikarang; kalau titik tengahnya di luar semua garis, ada satu jalan kedua: calon boleh
   dipakai bila ia **berbagi tepi** (≤ `DXF_PERSIL_TETANGGA_M` = 0,5 m) dengan bidang hidup
   yang seluruh tetangga tepinya anggota **satu** blok, dan blok itu bergaris
   `sumber_perkiraan = gabungan bidang anggota`. Jarak ke garis blok sengaja **bukan**
   bukti: garis dua blok yang berdempetan berjarak 1,1 m satu sama lain, jadi "dekat ke
   BLOK 8" tidak membedakan apa pun. Blok bergaris ukur (`garis luar MASTER.dxf`) tidak
   pernah bisa tumbuh dari sini — hanya petugas dengan garis ukur baru yang boleh mengubah
   wilayahnya. Yang tetap tidak dapat blok dilaporkan sebagai `calon_tanpa_blok` berikut
   alasannya, dan **tidak dipasang** — kecuali perintah itu ditambah `--tanpa-blok`:
   poligonya tetap masuk basis data dengan `blok_id` NULL, garis blok terdekat plus
   jaraknya ditulis di `catatan` sebagai petunjuk bagi petugas. Blok tetap tidak pernah
   dikarang dari kedekatan ([§3.21](#321-bidang-tanpa-blok-dan-dua-perintah-koreksi-data)).

Bidang baru masuk sebagai `perlu_verifikasi`, `sumber` = lapisan asalnya, kode meneruskan
nomor terbesar awalan yang ada (`DXF-020100-2573`), `catatan` menyebut celah ujungnya, dan
baris `hak`-nya berjenis `BLM` tanpa nama. Tidak ada nomor, nama, atau jenis yang
diarang: setelah memasang, jalankan lagi `nama-verifikasi`, `nib`, `daftar`, `nis` dan
`hak-milik` agar isinya datang dari label peta seperti biasa. Cadangan
`storage\dxf-persil-<waktu>.json` (berisi kode, lapisan, blok, luas, pusat tiap baris)
dibuat sebelum menulis, dan satu `audit_log` (`aksi = impor`) ditulis per blok yang
tersentuh.

```
D:\xampp\php\php.exe tools\impor.php dxf-persil                     # periksa
D:\xampp\php\php.exe tools\impor.php dxf-persil --terapkan          # pasang
D:\xampp\php\php.exe tools\impor.php dxf-persil --celah=0.05        # longgarkan celah
D:\xampp\php\php.exe tools\impor.php dxf-persil --tanpa-blok --terapkan   # pasang yg tak berblok
```

**Hasilnya (20 Okt 2026, produksi).** Ronde pertama memasang 343 persil, 262.050 m²,
rata-rata 764 m², terbesar `DXF-020100-2573` 44.508 m² (MARTOYO, BLOK 26), terkecil 21,9 m²;
tersebar di 16 blok — BLOK 12 124, BLOK 14 61, BLOK 25 60, BLOK 17 24, lalu sisanya kecil.
48 calon ditahan karena tidak jatuh di dalam blok mana pun.

Ronde kedua, sesudah aturan tepi dipasang, menyelesaikan 35 di antara 48 itu: **16.295 m²**
masuk sebagai BLOK 2 25 bidang, BLOK 19 5, BLOK 21 4, BLOK 8 1 — semuanya blok
`gabungan bidang anggota`, jadi tidak ada satu meter pun garis ukur yang digeser. Empat
belas sisanya menjadi sepuluh yang masih ditahan, dan setiap yang ditahan sekarang punya
alasan yang terbaca: **5** berbagi tepi dengan anggota BLOK 9/23/7 yang bergaris ukur,
**1** berbagi tepi dengan dua blok sekaligus (`BLOK 23 + BLOK 21`, tetangganya `HM-1229`
0,01 m dan `HM-198D K3` 0,09 m), **4** tidak berbagi tepi dengan bidang hidup mana pun.
Yang 5+1 itu menunggu keputusan petugas, bukan menunggu alat.

Dua hal yang ikut dibereskan sambil menjalankan aturan ini:

- **Aturannya bekerja bertingkat, bukan sekali jalan.** Sesudah 34 bidang terpasang,
  satu calon (`DXF-020100-2948`, 551,8 m²) baru bisa dipasang — tetangga tepinya
  (`DXF-020100-2926`) sendiri baru saja terpasang, jadi calon itu sebelumnya dilaporkan
  "tidak berbagi tepi dengan bidang hidup mana pun". Karena itu `dxf-persil` dijalankan
  berulang sampai `calon = 0`: hari ini 34 → 1 → 0, dan `calon_tanpa_blok` turun 48 → 14 → 10
  dan berhenti di situ.
- **`wktCincin()` ternyata buta terhadap MULTIPOLYGON.** Penjaga `preg_match_all(...) !== 1`
  membuat fungsi itu mengembalikan `[]` untuk setiap geometri multi-bagian, sehingga empat
  blok bergaris gabungan (BLOK 2/8/19/21/22) tidak pernah terlihat uji titik-tengah di PHP
  dan calonnya dilaporkan "blok tetangga tidak punya geometri". Sekarang cincin luar tiap
  bagian dipecah lewat `wktBagian()` dan uji titik berjalan atas seluruh bagian
  (`titikDiDalamBagian()`); lubang diabaikan. Dry-run berubah dari `calon=0 / ditahan=48`
  menjadi `calon=34 / ditahan=14` tanpa mengubah aturan lain.

Bidang yang bloknya datang dari aturan tepi mencatat itu di `catatan`-nya dan menyebutkan
perintah yang perlu dijalankan sesudahnya — `impor.php kira 2 8 19 21 --tepat --terapkan`
— supaya garis blok tumbuh menelan anggotanya. Empat batas itu memang lalu dipasang ulang:
BLOK 2 42 → 58 bagian (171.966 → 184.302 m²), BLOK 8 9 → 10 (164.691 → 165.115),
BLOK 19 14 → 19 (176.950 → 178.943), BLOK 21 35 → 39 (136.407 → 137.389). Bertambahnya
bagian itu bukan kerusakan: bagiannya mengikuti bidang yang belum pernah tersentuh garis.

Setelah kelima pengisi dijalankan pada 35 bidang baru: 35-35 bernama, 34 ber-nomor-daftar,
30 ber-NIS, 1 ber-nib, dan seluruhnya Hak Milik. Keadaan akhir `bidang` pada ronde itu:
**4.421 baris**
(778 `aktif` + 3.254 `perlu_verifikasi` + 389 `duplikat`), 4.032 bidang hidup, 4.027.643 m²
terpetakan, HM 3.998 · KDH 33 · HP 1 · BLM 0 pada yang hidup, dan tetap **47 dari 4.032**
bidang hidup yang tidak punya satu pun identitas ([§3.17](#317-bidang-yang-masih-tanpa-identitas)).

Diuji lebih dulu di `sipetades_uji` yang di-refresh dari produksi: 0 baris lama berubah
(dibandingkan kolom demi kolom dengan `NOT (a <=> b)` pada `bidang`, `hak` dan `blok`),
35 baru + 35 baris `hak` semuanya masuk BLOK 2/8/19/21, dan dijalankan berulang di uji
maupun di produksi memberi angka yang sama persis. Yang tertinggal dan belum tersentuh waktu
itu: sepuluh calon tanpa blok di atas, lapisan `HM` (sudah dipasang kemudian, lihat
paragraf penutup [§3.20](#320-sisa-yang-belum-terpetakan-diukur-dari-seluruh-lapisan-peta-master)),
`020400` dan `lb_lai` yang memang tidak pernah diimpor, dan 276 `LINE` + 4 `CIRCLE` yang tidak
bisa dibaca `dxfPoligon()`. Ukuran seluruh
lapisan untuk tiga hal itu — termasuk berapa yang benar-benar masih hilang — ada di
[§3.20](#320-sisa-yang-belum-terpetakan-diukur-dari-seluruh-lapisan-peta-master).

**Kecelakaan yang terjadi hari itu juga, dan penjaganya.** Satu `php tools\impor.php` tanpa
argumen berarti `semua`, dan `semua` menulis tanpa `--terapkan`: ia menyisipkan ulang
`blok_peta.geojson` + `bidang_tanah.geojson` + `bidang_lain.geojson` sehingga 1.582 bidang
GeoJSON lama hidup lagi di atas data MASTER.dxf (basis data naik ke 6.003 bidang, 25 blok
termasuk BLOK 11 yang sudah digabung ke BLOK 13) dan memaksa `namaPemegangDariMaster(true, …)`
menulis nama. Semuanya dibatalkan pada hari yang sama oleh
`_lama\scratch\batalkan-impor-semua.php` — 1.582 bidang + 1.582 baris `hak` + 1 baris `blok`
dihapus, cadangan penuh (WKT) di `storage\batalkan-semua-20261020-084857.json`, dump utuh
sebelum penghapusan di `storage\pemulihan-kecelakaan-impor-semua-20261020-0848.sql`. Setelah
pembatalan, diff baris-per-baris terhadap `storage\pemulihan-sebelum-dxf-persil-20261020-0835.sql`
menunjukkan satu-satunya selisih yang tersisa adalah yang direncanakan: +35 bidang, +35 baris
`hak`, dan 4 geometri blok. `case 'semua'` kini berhenti pada mode periksa tanpa `--terapkan`.

---

### 3.20 Sisa yang belum terpetakan, diukur dari seluruh lapisan peta master

**Gejalanya.** Setelah `dxf-persil` dua ronde, pertanyaan "masa masih ada yang belum terpetakan"
hanya bisa dijawab dengan membaca **setiap** lapisan MASTER.dxf, bukan dua lapisan yang dikenal
importer. Lima skrip hanya-membaca menghasilkan angka-angka ini:
`_lama\scratch\audit-belum-terpetakan.php` (nasib tiap cincin per lapisan),
`_lama\scratch\ukur-cincin-terbuka.php` (apa yang dilepas aturan celah 1 cm),
`_lama\scratch\ukur-celah-dalam-blok.php` (luas di dalam garis blok yang tidak diisi bidang apa pun),
`_lama\scratch\pilah-label-yatim.php` (label di luar poligon: nomor yang sudah tercatat vs yang tidak),
dan `_lama\scratch\daftar-belum-terpetakan.php` (daftar kerja; sejak 20 Okt susunan barisnya
dipindahkan ke `daftarBelumTerpetakan()` di `tools\lib-impor.php` dan ikut diekspor sebagai
`06-belum-terpetakan.csv` — jalanan 20 Okt 09:52 ada di `storage\laporan-20261020-095207\`,
10 baris golongan A + 522 baris golongan B; jalanan 20 Okt 10:29 di `storage\laporan-20261020-102934\`
tinggal 522 baris golongan B karena golongan A sudah dipasang
([§3.21](#321-bidang-tanpa-blok-dan-dua-perintah-koreksi-data)).)

**Angkanya.** 6.020 cincin layak uji di semua lapisan, lawan 4.032 bidang hidup non-duplikat:

| lapisan | diuji | sudah punya bidang | belum | m² belum | cincin dilepas sebelum diuji |
|---|---|---|---|---|---|
| `020100` | 4.430 | 4.416 | **14** | 12.808 | 3.397 terbuka, 19 coretan, 19 serpihan |
| `BIDANG HM` | 1.493 | 1.491 | **2** | 2.090 | 2 terbuka |
| `HM` | 77 | 74 | **3** | 6.223 | 1 terbuka |
| `BATAS BLOK` | 16 | 16 | 0 | 0 | 10 terbuka |
| `020400`, `lb_lai`, `010500` | 4 | 4 | 0 | 0 | 3 terbuka |

Jadi **19 cincin = 13 persil berbeda (15.248 m²)** yang masih tanpa bidang waktu bagian ini
diukur. Tiga di antaranya berdiri di lapisan `HM` dan **sudah dipasang hari itu juga**
(TUGIATI/`00737`, ARI MULYONO/NIB `01170`, JUWARIYAH SUPARDI dkk/`00865`) sehingga tersisa
**10 cincin / 10 persil = 9.025 m²**, semuanya `flag tertutup` dan semuanya berlabel:
`01766` HARI SUTANTO, `01778` JUWITO, `01883` RUSMI, `01887` SRI RUSWANTI, `01888` INDARWATI,
`01889` JUWARNI, `04280` SUYATI, `04321` AHMAD SODIKIN, dan dua ring `BIDANG HM` ber-NIB `M.58`
/`M.59`+`M.200`+`M.201`. Nol cincin yang "tertutup sebagian" — tidak ada kulit penutup yang
menumpang. Catatan metode: cincin raksasa seperti `010500` (6,7 km²) dan 16 `BATAS BLOK`
digolongkan "sudah punya bidang" karena titik tengahnya jatuh di dalam sebuah persil; itu
memang maksudnya — mereka bukan calon persil.
**Kesepuluh cincin itu akhirnya dipasang juga pada hari yang sama**, dengan `--tanpa-blok`,
jadi angka "tersisa 10" di atas sudah tidak berlaku — lihat
[§3.21](#321-bidang-tanpa-blok-dan-dua-perintah-koreksi-data).

Angka lama di [§3.19](#319-persil-dxf-yang-flag-nya-tidak-pernah-ditutup) perlu dikoreksi:
lapisan `HM` tidak kehilangan 61 ring / 58.310 m². Yang benar 77 ring teruji dan **74 di
antaranya sudah ada bidangnya**; yang benar-benar baru cuma 3 ring (6.223 m²) — dan itu
satu-satunya sisa dari lapisan yang tidak pernah diimpor. `020400`, `lb_lai` dan `030300`
tidak memberi apa pun.

**Yang menahan 13 itu dua macam.** Sepuluh di antaranya adalah daftar `dxf-persil` yang
sengaja ditahan (titik tengahnya tidak jatuh di dalam blok mana pun: lima minta garis ukur
BLOK 9/23/7 ditumbuhkan, satu berbagi tepi dengan BLOK 23 + BLOK 21 sekaligus, empat tidak
berbagi tepi dengan bidang hidup mana pun) — itu keputusan petugas, bukan alat, dan akhirnya
dilewati dengan memasang poligonnya tanpa blok ([§3.21](#321-bidang-tanpa-blok-dan-dua-perintah-koreksi-data)).
Tiga sisanya
ada di lapisan `HM`, yang waktu itu belum tercatat dalam `DXF_PERSIL_LAPISAN`: ketiga titik
tengahnya jatuh di dalam BLOK 8, BLOK 25 dan BLOK 2, jadi satu baris konstanta cukup untuk
memasangnya — dan baris itu memang ditambahkan pada hari yang sama.

**Celah 1 cm bukan penyebab yang tersisa.** Garis terbuka sebanyak 3.411 batang, dan talennya
dijadikan cincin paksa pada beberapa ambang:

| talen ditutup sampai | calon tanpa bidang | m² | yang berlabel di dalamnya |
|---|---|---|---|
| 5 cm | 0 | 0 | — |
| 2 m | 0 | 0 | — |
| 10 m | 2 | 43 | — |
| 50 m | 91 | 30.520 | 40 dari 89 cincin pita 10–50 m, dan labelnya `BATAS JALAN`/`BATAS PERAIRAN`/`BLOK`/`HITUNG BIDANG` |
| 250 m | 137 | 95.773 | — |

Sebaran panjang talen: 2.473 batang bercelah 10–50 m, 670 batang 50–250 m, 26 batang > 250 m.
Angka-angka itu bentuknya bukan persil: talen 30 m bukan bekuk bidang, dan label yang ikut
tertangkap adalah label tetangga. Melonggarkan `--celah` melewati 2 m hanya menghasilkan
geometri karangan, jadi sengaja tidak dilakukan. Cacah entitas mentah menutup kemungkinan lain: `TEXT` 25.903,
`LWPOLYLINE` 17.659, `POINT` 12.985 di lapisan `MAP`, `LINE` 354 (276 di `020100`), `IMAGE` 353,
`INSERT` 12, `CIRCLE` 4, `MTEXT` 1 — dan `POLYLINE` nol batang, jadi tidak ada yang terlewat dari
jenis entitas yang tidak bisa dibaca `dxfPoligon()`.

**Sedangkan bagian terbesar justru tidak digambar.** Di dalam 24 garis blok, bidang hidup
mengisi 4.011.914 m² dari 4.482.393 m² = **89,5%** (diukur ulang sesudah tiga persil `HM` masuk):

| blok | luas blok m² | terisi m² | sisa m² |
|---|---|---|---|
| BLOK 5 | 278.591 | 176.419 | 102.172 |
| BLOK 25 | 299.469 | 236.382 | 63.087 |
| BLOK 23 | 292.149 | 252.446 | 39.702 |
| BLOK 17 | 126.388 | 93.575 | 32.813 |
| BLOK 12 | 220.629 | 188.874 | 31.755 |
| BLOK 20 | 205.112 | 174.179 | 30.932 |
| sisanya (18 blok) | 3.060.055 | 2.890.039 | 170.016 |

Empat blok beranggota-gabungan (BLOK 8/19/21/22) sisanya 0–1 m² karena garisnya memang
ditumbuhkan dari anggotanya, dan BLOK 2 bernilai −3.111 m² karena ada bidang yang sedikit
keluar dari garis turunan itu. Angka di paragraf ini semuanya luas **gambar**; luas yang
**didaftarkan** di kolom `bidang.luas_m2` berbeda sedikit — 4.033.866 m² lawan 4.091.974 m²
(`_lama\scratch\uji-luas-dua-cara.php`), jadi jangan mencampur kedua satuan dalam satu
kalimat. Ukuran lama itu **tidak** mengalikan cosinus lintang sehingga selisihnya kelihatan
1,4 %; diukur dengan faktor yang sama seperti tempat lain (`luasWkt()`, cos 7,08°) selisihnya
**0,7 %** — 4.069.858 m² gambar lawan 4.042.892 m² terdaftar sesudah penulisan 20 Okt.
470.479 m² yang tersisa sebagian besar koridor: 512 dari
1.643 label yang berdiri di dalam blok tapi di luar poligon mana pun adalah nama jalan atau
air (`BATAS JALAN` 383, `BATAS PERAIRAN` 129 — `JALAN RAYA PATEAN`, `KALI SARI`, `Saluran`).

Bagian yang bukan koridor: **983 label bernomor** tidak berdiri di atas bidang, dan kalau
dikumpulkan per titik (radius 4 m) menjadi **522 loket di dalam garis blok** yang nomornya
tidak tercatat di baris hidup mana pun — 240 nomor `080201` (7 di antaranya sekloter dengan NIS),
250 NIS `tn_alashak`, 32 `NIB HM`.
Hanya 47 label bernomor yang nomornya ternyata sudah tercatat di baris hidup (080201 7,
`NIB HM` 5, NIS 35 — itu sekadar label yang meleset dari poligonya sendiri), dan 387 nama
`tn_nama` ikut yatim, termasuk 11 anotasi petugas
`HITUNG BIDANG = Blm Ada NIB`. Konsentrasi teratas: BLOK 25 (94), BLOK 2 (69), BLOK 23 (41),
BLOK 17 (40), BLOK 20 (33), BLOK 14 (32). Sensitivitas radius atas 983 label itu:
783 kelompok @2 m, 749 @4 m, 581 @8 m, 455 @15 m — jadi 522 itu taksiran tengah, bukan angka
pasti. Sesudah sepuluh cincin dipasang ([§3.21](#321-bidang-tanpa-blok-dan-dua-perintah-koreksi-data)),
`daftarBelumTerpetakan()` mengukur ulang: **954** label yatim (29 di antaranya sekarang berdiri
di atas bidang) dan loketnya **tetap 522** — kesepuluh cincin itu memang berdiri di luar garis
blok mana pun, jadi sejak awal kelompoknya tidak ikut dihitung sebagai loket.

**Kesimpulannya, tiga pekerjaan yang berbeda.** (1) Satu baris `DXF_PERSIL_LAPISAN` menambah
lapisan `HM` → 3 persil (6.223 m²) langsung bisa dipasang dengan aturan yang sudah ada —
**sudah dipasang hari itu juga**, lihat paragraf penutup bagian ini.
(2) Sepuluh calon yang ditahan menunggu keputusan garis blok — bukan alat; jalan tengahnya
dipakai hari itu juga, yaitu memasang poligonnya dan **membiarkan bloknya kosong**
([§3.21](#321-bidang-tanpa-blok-dan-dua-perintah-koreksi-data)). (3) ±522 loket
bernomor itu **tidak bisa dipulihkan alat apa pun**: MASTER.dxf tidak punya ring di sana, jadi
itulah digitasi yang belum dikerjakan petugas (CSV-nya dibuat untuk dibawa ke lapangan).
Sisi lain yang masih kosong dan bukan soal geometri: seluruh 3.794 `objek_pajak` sudah ber-`blok_id`
tetapi `bidang_id`-nya NULL semua — NOP belum pernah tertaut ke persil ([§3.11](#311-menautkan-nop-pbb-ke-bidang-peta)).

**Pekerjaan (1) sudah dipasang, 20 Okt 2026.** `HM` masuk ke `DXF_PERSIL_LAPISAN`
([tools/lib-impor.php:4921](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L4921), kode baru
`DXF-HM`, tipe `tanah`), dan tidak ada aturan lain yang disentuh. Dibuktikan lebih dulu di
`sipetades_uji` yang dibangun dari buangan produksi, lawan salinan acuan `sipetades_uji_sebelum`
dengan pembanding kolom-per-kolom `_lama\scratch\banding-uji.php` — `CHECKSUM TABLE` tidak dipakai
karena hasilnya berbeda antara dua salinan walau isinya sama: `bidang`, `hak` dan `blok` **0 baris
lama berubah**, 0 hilang, 3 `bidang` + 3 `hak` baru. Produksi lalu dikeringkan
(`calon=3`, `luas_m2=6222.9` — persis sama dengan uji), dipasang
(`storage\dxf-persil-20261020-093349.json`, satu `audit_log` per blok), dan dijalankan lagi
sampai `calon=0`; cincin tertutup yang terhitung naik dari 5.981 ke 5.984.

| kode | blok | luas | identitas yang datang dari label |
|---|---|---|---|
| `DXF-HM-0001` | BLOK 8 | 3.981,6 m² | nama `HERU SETIAWAN / JUWARIYAH SUPARDI / SUKARDI / SURIPTO`, NIS `941D K3 / 942D K3`, daftar `00865` |
| `DXF-HM-0002` | BLOK 25 | 1.365,5 m² | nama `ARI MULYONO`, NIB `01170` |
| `DXF-HM-0003` | BLOK 2 | 875,9 m² | nama `TUGIATI`, daftar `00737` |

Empat nama pada baris pertama bukan karangan: satu poligon memang berdiri di bawah empat
label (`nama-verifikasi` mencatatnya sebagai `nama_ganda=1`), jadi baris itu tetap
`perlu_verifikasi` dan harus dipecah petugas. Setelah memasang, garis blok turunan dibuat ulang
`kira 2 8 --tepat --terapkan`: BLOK 2 184.302 → 185.183 m² (58 bagian), BLOK 8 → 11 bagian
169.123 m². **BLOK 25 sengaja tidak ditumbuhkan** — garisnya `garis luar MASTER.dxf`, dan
aturan itu berlaku mutlak. Lima pengisinya berjalan sesudah itu: `nama-verifikasi` 3, `nib` 1,
`daftar` 2, `nis` 1, `hak-milik` 3.

Isi basis data sesudah seluruh penulisan 20 Okt: 4.434 baris `bidang` (778 `aktif` +
3.267 `perlu_verifikasi` + 389 `duplikat`), **4.045 hidup** dengan 4.042.892 m² terdaftar,
HM 4.011 · KDH 33 · HP 1, 3.696 bernama, dan tetap 47 bidang tanpa satu pun identitas.
**12 bidang hidup tidak punya blok** — sepuluh yang baru dipasang itu ditambah
`DXF-020100-0005` (H TONO, 6.984 m²) dan `HM-1235` (SUMARNI / TOMO) yang memang sudah kosong
sebelumnya. Yang tertinggal: ±522 loket menunggu digitasi
([§3.21](#321-bidang-tanpa-blok-dan-dua-perintah-koreksi-data)).

---

### 3.21 Bidang tanpa blok dan dua perintah koreksi data

**Soalnya.** Sepuluh cincin terakhir yang ditahan `dxf-persil` tidak bisa diberi blok dengan
jujur. Diukur satu per satu (`_lama\scratch\ukur-calon-tanpa-blok.php`, hanya membaca,
9.025,2 m² seluruhnya): **garis blok ukur terdekat berjarak 12,9–30 m** dari titik tengah
mereka, dan aturan tetangga tepi justru menunjuk bidang yang **dirinya sendiri** sudah tidak
jatuh di dalam garis blok yang tercatat di padanya. Jadi tidak ada satu pun aturan yang tersedia
waktu itu yang bisa menyebut bloknya; yang ada hanya petunjuk "garis ini paling dekat".

**Aturannya (`--tanpa-blok`).** `persilDariDxf()` ([tools/lib-impor.php:5202](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L5202))
menerima bendera kelima `$pasangTanpaBlok`. Kalau dipasang, calon yang gagal dua jaring blok
tidak dibuang lagi: poligonya masuk `bidang` dengan `blok_id` **NULL**, dan `garisBlokTerdekat()`
([tools/lib-impor.php:5596](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L5596)) menuliskan
garis terdekat plus jaraknya ke `catatan` sebagai alamat untuk petugas, lengkap dengan perintah
yang harus dijalankan sesudahnya — `impor.php ubah-blok <kode blok> <kode bidang> --terapkan`.
Angka jarak itu sengaja **tidak** ditulis ke `blok_id`: ia hanya perkiraan, dan dua blok yang
berdempetan hanya berjarak 1,1 m satu sama lain sehingga "dekat" tidak membedakan apa pun.
Auditnya tidak disebar ke blok mana pun, melainkan dikumpulkan di bawah kunci
`BLOK belum ditentukan`.

**Hasilnya (20 Okt 2026, produksi).** Dibuktikan lebih dulu di `sipetades_uji` (0 baris lama
berubah, 0 baris `blok` tersentuh, 10 `bidang` + 10 `hak` baru), baru ditulis
(`storage\dxf-persil-20261020-101605.json`; dump sebelum menulis
`storage\pemulihan-sebelum-dxf-persil-tanpa-blok-20261020-1018.sql`). Kesepuluhnya masuk
`perlu_verifikasi`, dan lima pengisi dijalankan sesudahnya sampai tuntas:

| kode | lapisan | luas | identitas dari label | garis blok terdekat di `catatan` |
|---|---|---|---|---|
| `DXF-020100-2949` | 020100 | 2.836,1 m² | JUWARNI, NIS `A114`, daftar `01889` | ada, disebut dengan jaraknya |
| `DXF-020100-2950` | 020100 | 695,0 m² | HARI SUTANTO, NIS `A127 / A128`, daftar `01766` | idem |
| `DXF-020100-2951` | 020100 | 680,9 m² | SRI RUSWANTI, NIS `112B`, daftar `01887` | idem |
| `DXF-020100-2952` | 020100 | 679,5 m² | JUWITO, NIS `C61`, daftar `01778` | idem |
| `DXF-020100-2953` | 020100 | 674,1 m² | INDARWATI, NIS `110B`, daftar `01888` | idem |
| `DXF-020100-2954` | 020100 | 588,1 m² | AHMAD SODIKIN, NIS `1642D K3`, daftar `04321` | idem |
| `DXF-020100-2955` | 020100 | 474,1 m² | SUYATI, NIS `1601D K3`, daftar `04280` | idem |
| `DXF-020100-2956` | 020100 | 307,3 m² | RUSMI, NIS `109B`, daftar `01883` | idem |
| `DXF-BIDANGHM-0003` | BIDANG HM | 1.635,2 m² | tiga nama (KRISTINA SUGIYARTI / RITA CHRISTINA / SOERAT BIN SAMAN) | idem |
| `DXF-BIDANGHM-0004` | BIDANG HM | 454,9 m² | AGUS S, NIB `M.58` | idem |

Sepuluh bernama, delapan ber-nomor-daftar, delapan ber-NIS, satu ber-nib, seluruhnya Hak Milik —
semuanya dari label peta, tidak ada yang dikarang. Tiga nama pada `DXF-BIDANGHM-0003` berarti
satu poligon berdiri di bawah tiga label: baris itu tetap harus dipecah petugas. Garis blok
turunan **tidak** ikut ditumbuhkan — kesepuluh bidang ini tidak berblok, dan `kira 19 21 --tepat`
sesudah seluruh koreksi di bawah melaporkan `dipasang=0 kurang=0`.

**Dua cacat data yang terbukti dari geometri.** `bidang.luas_m2` warisan GeoJSON adalah luas
**gambar**, bukan angka pendaftaran, dan `blok_id` warisan ada yang berasal dari perkiraan
otomatis yang belum pernah dikoreksi. Untuk keduanya dibuat satu perintah per kasus, semuanya
mode periksa dulu:

- `impor.php luas [--toleransi=<bagian>]` → `hitungUlangLuasBidang()`
  ([tools/lib-impor.php:1836](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L1836)). Luas tiap
  bidang dihitung ulang dari cincin di `geom` dengan `luasWkt()` dan dibandingkan dengan kolomnya;
  yang berbeda lebih dari `LUAS_TOLERANSI` (bawaan 0,05 = 5 %) ditimpa. `luas_m2 <= 0` **selalu**
  dianggap cacat, sekecil apa pun selisihnya terhadap gambarannya. Ronde ini menyentuh 4.045 bidang hidup dan
  memperbaiki **2**: `DXF-020100-0008` 0,00 → 0,02 m² dan `DXF-020100-2440` 5,00 → 5,27 m².
- `impor.php koreksi-blok` → `koreksiBlokPerkiraan()`
  ([tools/lib-impor.php:1960](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L1960)). Bidang
  dipindah hanya bila **lima** syaratnya sekaligus terbukti: statusnya masih `perlu_verifikasi`,
  `catatan`-nya bercorak mesin — `Blok diperkirakan otomatis%`, `Cincin MASTER.dxf lapis %` atau
  `Garis kuning (ACI 2)%`, dirangkum `catatanBlokMesin()` + `sqlCatatanBlokMesin()` di
  [tools/lib-impor.php:1915](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L1915)
  (keputusan petugas tidak pernah ditimpa, dan catatan kosong **tidak** ikut karena `pindah`
  dan `ubah-blok` tidak menulis catatan sehingga barisnya tak bisa dibuktikan bukan keputusan
  petugas), titik tengahnya sudah **keluar** dari garis bloknya sendiri, titik tengahnya jatuh di
  dalam **tepat satu** garis blok lain, dan garis tujuan itu bergaris ukur
  (`BLOK_KOREKSI_SUMBER = 'garis luar MASTER.dxf'`) yang menaungi ≥ `BLOK_KEPERCAYAAN_MIN`
  (90 %) anggotanya sendiri. Syarat terakhir yang menyelamatkan aturan ini: ada blok yang
  garisnya sendiri sudah tidak cocok dengan anggotanya, jadi garis semacam itu tidak boleh
  dipakai sebagai bukti. Kepercayaan tiap garis diukur dengan
  `_lama\scratch\ukur-mutu-garis-blok.php` (hanya membaca): BLOK 5/6/14/25 99 %, BLOK 4 98 %,
  BLOK 2 96 %, BLOK 20 95 %, BLOK 7 92 %, tetapi **BLOK 16 85 %**, **BLOK 18 83 %**,
  **BLOK 9 79 %** dan turunan **BLOK 8 79 %**, **BLOK 21 88 %** ada di bawahnya — angka yang
  tercetak di perintah sehari kemudian, sesudah enam bidang berpindah, sedikit lebih tinggi
  (BLOK 23 80 %, BLOK 21 89 %, BLOK 19 97 %). Garis di bawah 90 % tidak dipakai.
  Dari 1.213 bidang berkategori, **6** pindah dan **11** ditahan
  beserta alasannya yang tercetak:

  | kode | dari → ke | kepercayaan garis tujuan |
  |---|---|---|
  | `DXF-020100-0348` | BLOK 21 → BLOK 20 | 95 % |
  | `HM-1261` | BLOK 21 → BLOK 20 | 95 % |
  | `HM-172D K3` | BLOK 21 → BLOK 20 | 95 % |
  | `DXF-020100-2107` | BLOK 19 → BLOK 24 | 98 % |
  | `DXF-020100-2113` | BLOK 19 → BLOK 24 | 98 % |
  | `HM-199D K3` | BLOK 23 → BLOK 26 | 97 % |

  Sebelas yang ditahan: `DXF-020100-1434`, `-2435`, `-2564`, `HM-1381` (tujuannya BLOK 23, garisnya
  sendiri cuma menaungi 80 % anggotanya), `DXF-020100-1997` (BLOK 2 turunan), `DXF-020100-2555`,
  `HM-1343` (BLOK 21 turunan, 89 %), `HM-1201`, `HM-131D K3`, `HM-C82` (BLOK 19 turunan), dan
  `HM-1367` yang titik tengahnya duduk di dalam **dua** garis sekaligus (BLOK 21 + BLOK 23).

**Sasaran ronde pertama ternyata terlalu sempit.** `koreksi-blok` hanya melirik catatan
"Blok diperkirakan otomatis%", yaitu baris buatan `kira`, padahal persil yang cincinnya dipasang
`dxf-persil` dan `kuning` juga tidak pernah dipilih petugas. Ketika 219 bidang yang titik
tengahnya keluar dari garis bloknya diukur ulang tanpa filter itu
(`_lama\scratch\rinci-luar-blok.php`, hanya membaca; petanya
`_lama\scratch\peta-luar-blok.php`), ternyata **66** punya garis ukur yang benar-benar menaungi
titik tengahnya sementara perintah aslinya melaporkan `akan_dipindah=0`. Sejak saat itu tiga
corak catatan mesin ikut diperiksa dan `catatan` kosong sengaja dikeluarkan.

Ronde itu diuji lebih dulu pada salinan `sipetades_uji` (dump produksi
`storage\pemulihan-sebelum-koreksi-blok-20261020-1348.sql`, basis data kembar
`sipetades_uji_sebelum` untuk perbandingan): **1.603** bidang diperiksa, **57** pindah, **12**
ditahan, dicadangkan ke `storage\koreksi-blok-perkiraan-20261020-134952.json`.

- **57 pindah**: 31 → BLOK 12, 21 → BLOK 14, 4 → BLOK 15, 1 → BLOK 17; asalnya BLOK 9 dan
  BLOK 18 masing-masing 19 bidang, BLOK 16 12, BLOK 7 dan BLOK 15 masing-masing 3, BLOK 14 1.
- **12 ditahan**: sepuluh karena garis tujuannya turunan (BLOK 2/19/21, meskipun menaungi
  94–96 % anggotanya sendiri), `DXF-020100-2435` dan `-2564` karena BLOK 23 cuma menaungi 80 %.

Kolom-per-kolom dengan `sipetades_uji_sebelum`: **tepat 57 baris** `bidang` berubah, hanya
`blok_id` + `catatan` (+ `diubah_pada`); `geom`, `luas_m2`, `status`, `nib`, titik tengah,
tabel `hak` dan `blok` tidak tersentuh satu baris pun, 0 baris hilang. Kelima puluh tujuh titik
tengah sudah jatuh di dalam garis barunya dan di luar garis lamanya. Dijalankan ulang seketika:
`bidang_diperiksa` turun jadi 1.546 dan `akan_dipindah=0`, jadi perintahnya idempoten.
Kepercayaan garis yang bergerak ikut naik — BLOK 18 78,2 → **100,0 %**, BLOK 16 91,3 → 99,3 %,
BLOK 15 98,0 → 100,0 %, BLOK 9 75,7 → 83,1 %, BLOK 7 91,6 → 94,6 %, BLOK 14 98,1 → 99,2 %,
BLOK 12 97,5 → 97,8 %; BLOK 17 tetap 98,0 %.

Bug tampilan ikut ditemukan di ronde ini: baris kesimpulan memakai `$hasil + ['dipindah' => …]`
padahal `dipindah` sudah ada di `$hasil`, dan operator `+` tidak menimpa kunci yang sudah ada —
perintah ini memindah 57 bidang lalu mencetak `dipindah=0`. Sekarang `array_merge`.

**Ditulis ke produksi 20 Okt 13:59** (`storage\pemulihan-sebelum-koreksi-produksi-20261020-135921.sql`
+ `storage\koreksi-blok-perkiraan-20261020-135926.json`). `_lama\scratch\verif-koreksi-produksi.php`
(hanya membaca) membuktikan empat hal: lawan `sipetades_uji_sebelum` hanya `blok_id`, `catatan` dan
`diubah_pada` yang bergerak (57 pindah + 1 pembatalan di bawah), tabel `hak` dan `blok` nol; produksi
identik dengan `sipetades_uji` yang sudah diuji kecuali jam tulisnya; kelima puluh tujuh titik tengah
dinaungi garis blok barunya dan tidak ada yang masih duduk di dalam garis lamanya; dijalankan ulang →
`akan_dipindah=0`.

Bagian pembuktian yang tidak memakai alat itu sendiri: uji keda-laman diulang dengan
`ST_Contains` milik MariaDB, dan di situ **satu** perpindahan pagi hari (ronde 10:27) ternyata
tidak punya bukti — `HM-199D K3` tercatat pindah ke BLOK 26 padahal titik tengahnya 482 m dari
garis itu dan tidak jatuh di dalam garis blok mana pun. Bloknya dikembalikan ke BLOK 23 lewat
`impor.php ubah-blok 23 "HM-199D K3" --terapkan`, kalimat catatan yang salah ditulis ulang oleh
`_lama\scratch\batalkan-catatan-hm199d.php` (cadangan
`storage\batalkan-catatan-hm199d-20261020-141746.json`), dan sesudah itu **62/62** baris berkoreksi
hari ini lolos `ST_Contains`. Ukuran akhir dengan `ST_Contains`
(`_lama\scratch\ukur-luka-mariadb.php`): **173** dari 4.036 bidang hidup berblok masih tidak dinaungi
garis bloknya sendiri — 23 dinaungi tepat satu garis blok lain, 150 tidak dinaungi garis mana pun —
dan garis terlemah kini **BLOK 23 79,2 %** (167/211) serta **BLOK 9 83,1 %** (162/195); BLOK 16 dan 18
yang pagi tadi 85 %/78 % sudah keluar dari ekor lemah.

Sejak ronde itu `koreksiBlokPerkiraan()` menguji ulang tiap calon perpindahan dengan `ST_Contains`
sebelum menulis — `wktBagian()`/`titikDiDalamBagian()` membaca cincin sebagai bagian padat sehingga
lebih murah hati daripada MariaDB (162 vs 173 bidang terluka). Dijalankan pada salinan sebelum
koreksi: 57 calon, **0 diveto**, jadi gerbang ini tidak mengubah hasil 20 Okt — ia ada supaya kasus
`HM-199D K3` tidak bisa ditulis ulang.

Kedua perintah itu dicadangkan dulu ke `storage\luas-dari-geometri-<waktu>.json` dan
`storage\koreksi-blok-perkiraan-<waktu>.json`, dump penuhnya di
`storage\pemulihan-sebelum-koreksi-datan-20261020-1022.sql`, dan masing-masing meninggalkan
`audit_log`. Uji di salinan sementara sebelum menulis ke produksi: **tepat 8 baris** berubah
(2 `luas_m2`, 6 `blok_id` + `catatan`), 0 baris lain bergerak, dan produksi lalu dicocokkan
kolom-per-kolom dengan salinan yang sudah terbukti itu — selisihnya hanya `dibuat_pada` /
`diubah_pada`. Sesudah ditulis, `impor.php luas` dan `impor.php koreksi-blok` sama-sama
melaporkan `berbeda=0 / akan_dipindah=0` selain sebelas yang ditahan: alatnya sudah tidak
menemukan cacat lagi.

**Daftar kerja petugas yang tersisa, tidak diubah alat apa pun.**
`_lama\scratch\daftar-beda-blok.php` (hanya membaca) mengukur sisanya sesudah penulisan:
**63** bidang hidup masih berdiri di dalam satu garis blok lain daripada garis bloknya sendiri —
sebagian besar baris `Cincin MASTER.dxf` yang dipasang sebelum garis bloknya bergeser, dan
57 di antaranya sudah dikoreksi 20 Okt 13:59 (ronde di atas) — dan
**189** bidang titik tengahnya tidak jatuh di dalam garis blok mana pun (kesepuluh yang baru itu
termasuk di dalamnya). Selebihnya bukan soal geometri: `DXF-020100-0008` cuma 0,02 m² (serpihan,
mungkin bukan persil), 4 `nib` dan 10 `nis` dipakai lebih dari satu bidang hidup, `dusun_id`
kosong di seluruh 4.434 baris karena peta master tidak membawanya, `objek_pajak.bidang_id`
masih NULL semua ([§3.11](#311-menautkan-nop-pbb-ke-bidang-peta)), dan tabel `pemegang`
kosong padahal 3.696 bidang sudah bernama.

---

### 3.22 Garis kuning MASTER.dxf: penjajaran tepi dan lima bidang yatim

**Soalnya.** Peta master menggambar **51 poligon kuning** (ACI 2, code 62 = `2`) di seksi
`ENTITIES` — itu persil yang petugas digitasi ulang di atas peta lama, bukan hiasan lapisan.
Empat puluh enam di antaranya sudah punya padanan di basis data tapi tepinya tidak rapi: sudut
bidang tertinggal 0,00–0,09 m dari garis tetangga, bekas digitasi dua lapis. Lima garis kuning
lainnya tidak punya bidang sama sekali. `impor.php kuning`
([tools/impor.php:502](file:///D:/Master%20ku/sipetades/tools/impor.php#L502)) menutup dua-duanya
lewat `kuningMasterSelaraskan()` ([tools/lib-impor.php:6243](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L6243)).

**Aturannya.** Tiga hal yang membuat perintah ini tidak mengarang bentuk:

- Yang dibaca hanya `ENTITIES`; kuning di dalam `BLOCKS` berkoordinat lokal. Padanan dipilih dari
  **berapa bagian garis kuning yang terjelaskan oleh sebuah bidang**, bukan dari berapa besar
  bidang itu menumpang garis kuning — arah yang terakhir mudah tertipu bidang raksasa.
- Penjajaran (`KUNING_TARIK_M` = `KUNING_SUDUT_M` = 0,15 m,
  [tools/lib-impor.php:6010](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L6010)) hanya
  memindahkan sudut yang **sudah** jatuh ≤ jarak itu ke tepi tetangga. Celah digitasi ditutup;
  sudut baru tidak pernah diciptakan. `bidang.luas_m2` tidak disentuh karena itu angka
  pendaftaran — yang berubah hanya luas gambar.
- Garis kuning yatim dipotong dulu sampai pas ke tetangga (kepul kecil dibuang, cincin terbesar
  dipakai), lalu dipasang sebagai `perlu_verifikasi` berjenis sementara `BLM`; sisanya sama seperti
  sesudah `dxf-persil`: `nama-verifikasi`, `nib`, `daftar`, `hak-milik`. Kalau titik tengahnya tidak
  jatuh di dalam garis blok mana pun, `blok_id` dibiarkan NULL dan garis blok terdekat ditulis ke
  `catatan` sesuai [§3.21](#321-bidang-tanpa-blok-dan-dua-perintah-koreksi-data).

**Empat cacat yang terbukti di tengah jalan — semuanya sudah diperbaiki di pustaka.** Bagian ini
layak dibaca karena keempatnya tidak kelihatan dari laporan:

1. `wktPoligon()` membulatkan ke **6 desimal** (≈ 8–11 cm pada bujur). Penjajaran yang memindahkan
   sudut 5 cm lalu menulis hasilnya dengan pembulatan itu mengembalikan titik yang sama: dari 17
   bidang yang dilaporkan dijajarkan, hanya **12** yang `geom`-nya benar-benar berubah. Sekarang
   setiap jalur tulis kuning memakai `wktPoligonTepat()`
   ([tools/lib-impor.php:6139](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L6139))
   dengan `%.8F` (± 1 mm).
2. Titik tengah serahkan ke **`ST_PointOnSurface()`**, dan fungsi itu mengembalikan NULL pada **33**
   bidang basis data ini — sebagian di antaranya poligon sederhana yang sah. Karena pemanggilnya
   tidak memeriksa NULL, `DXF-HM-0007` pernah tertulis dengan `pusat_lon = pusat_lat = 0` (titik
   tengahnya 12,3 juta meter dari desanya). Sekarang titik tengah dihitung di PHP oleh
   `pusatCincin()` ([tools/lib-impor.php:6189](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L6189))
   — luas-bobot, lalu rata-rata sudut, lalu pusat kipas segitiga — dan **divalidasi jatuh di dalam
   cincinnya sendiri** sebelum dipakai; kalau tidak ada satu pun kandidat yang di dalam, bidangnya
   ditolak, bukan ditulis setengah jadi. `pusatWkt()`
   ([tools/lib-impor.php:6222](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L6222)) sekarang
   hanya membungkusnya dan mengembalikan `?array`.
3. Sepasang sudut berjarak 0,06 m membuat MariaDB menyerah pada poligon yang bentuknya baik.
   `cincinRapi()` ([tools/lib-impor.php:6110](file:///D:/Master%20ku/sipetades/tools/lib-impor.php#L6110))
   membuang sudut yang jaraknya ke sudut berikutnya di bawah `KUNING_SUDUT_M`; ronde ini membuang
   1 sudut pada `HM-0028`, `HM-0033` dan `HM-0139`.
4. `luas_lama` dilaporkan dari garis kuning, bukan dari geometri bidangnya sendiri, sehingga delta
   seluruh ronde tercetak 4,20 m² padahal 7,13 m².

**Hasilnya (20 Okt 2026, produksi).** Dibuktikan lebih dulu pada `sipetades_uji` yang baru saja
dirobohkan dan dibangun ulang dari keadaan bersih: **tepat 17 baris lama berbeda** (`geom`,
`pusat_lon`, `pusat_lat`, `diubah_pada`), **5 `bidang` + 5 `hak` baru**, **0 baris `blok` tersentuh**,
**0 hilang** (`_lama\scratch\banding-uji.php`, `_lama\scratch\kolom-uji.php`). Produksi lalu digulung
kembali ke dump sebelum penulisan (`storage\pemulihan-sebelum-kuning-master-20261020-1158.sql`) dan
dijalankan ulang dengan pustaka yang sudah dibetulkan; 22 baris terdampak produksi **sama persis**
dengan salinan uji kalau dicocokkan `ST_Equals()` dan nilai titik tengahnya satu per satu
(`_lama\scratch\cek-kuning-setelah.php`): 0 tumpang tindih > 0,5 m², baik pada 17 bidang yang
dijajarkan maupun pada 5 bidang baru, dan 0 titik tengah baru yang keluar dari bidangnya sendiri
(42 di antaranya sudah ada sebelumnya). Cadangan JSON `storage\kuning-master-20261020-125417.json` +
`storage\hak-milik-20261020-125423.json`, dump pengaman keadaan sebelum perbaikan
`storage\pemulihan-sebelum-betul-kuning-20261020-125351.sql`, 22 baris `audit_log`.

Bidang baru hasil lima garis kuning yatim — seluruhnya sudah dipindahkan ke Hak Milik oleh
`impor.php hak-milik --terapkan`, yang tepat mengusulkan kelima baris itu dan nol yang lain:

| kode | kuning | luas gambar | label peta | blok |
|---|---|---|---|---|
| `DXF-HM-0004` | #30 | 5.910,9 m² | MUKRI YAKUP, NIB `00774` | BLOK 9 |
| `DXF-HM-0005` | #41 | 238,4 m² | BASUKI, NIB `00965` | NULL — terdekat BLOK 2, 3,2 m |
| `DXF-HM-0006` | #47 | 2.640,2 m² | DUL CHOLIK NGAIDIN, NIB `00902` | BLOK 18 |
| `DXF-HM-0007` | #48 | 1.023,1 m² | SUSRI BISRI MUSTOFA, NIB `00659` | BLOK 17 |
| `DXF-KUNING-0001` | #51 | 214,5 m² | `M.170`, tanpa nama | NULL — terdekat BLOK 2, 5,8 m |

Tujuh belas bidang yang dijajarkan bergerak 0,00–3,31 m² (`HM-0028` paling besar 3,31 m², `HM-0675`
0,76 m², `HM-0046` 1,45 m², selebihnya di bawah 0,6 m²): `DXF-HM-0002`, `DXF-HM-0003`, `HM-0028`,
`HM-0033`, `HM-0034`, `HM-0046`, `HM-0052`, `HM-0082`, `HM-0127`, `HM-0128`, `HM-0139`, `HM-0209`,
`HM-0487`, `HM-0511`, `HM-0512`, `HM-0533`, `HM-0675`. Peta sebelum dan sesudah ada di
`storage\peta-kuning-20261020-sebelum-jajar.html` dan `storage\peta-kuning-20261020.html` (dibuat
ulang oleh `_lama\scratch\kuning-sesuaikan.php` → `kuning-yatim.php` → `kuning-peta.php`, semuanya
hanya membaca).

**Perintah ini satu ronde, dan itu bukan kelalaian.** Dua bidang yang berdempetan sama-sama
menarik sudut yang mereka bagi ke garis yang *tersimpan di tetangganya*, padahal garis tetangga
itu sendiri baru saja digeser pada ronde yang sama — jadi ronde kedua menariknya kembali.
Terukur di salinan: sesudah ronde pertama, ronde kedua masih menemukan 8 bidang (2,98 m²) dan
ronde ketiga membatalkan 5 di antaranya (`HM-0127` 572,37 → 572,18 lalu 572,37; `HM-0128` sama).
Karena itu produksi **ditinggalkan pada ronde pertama**, dan penjajaran membuang baris yang hasil
cincinnya identik dengan yang tersimpan (dibandingkan sebagai teks `wktPoligonTepat()` kedua sisi,
bukan sebagai angka: proyeksi ulang meninggalkan debu float ~1e-9° dan `ST_AsText()` menulis
`110.07181610` sebagai `110.0718161`, jadi perbandingan string langsung selalu kalah maupun
perbandingan ketat selalu menang). Sisa celah 0,001–0,063 m pada delapan tepi yang berdua memang
dibiarkan; kalau ada yang salah di sana, yang dibetulkan garis bloknya, bukan penjajarannya.

**Sisa yang tidak diubah perintah ini.** Kuning #52 pada lapisan `010500` adalah poligon **terbuka**
seluas 6,7 km² — garis keliling, bukan persil — jadi memang tidak boleh dipasang; sesudah ronde ini
tidak ada lagi garis kuning tertutup yang tanpa bidang, dan pengukuran ulang melaporkan 51 berpadan
dari 51. `DXF-HM-0005` dan `DXF-KUNING-0001` masih tanpa blok dan menunggu petugas. Empat puluh dua
bidang yang titik tengahnya duduk di luar poligonya sendiri, dan 32 bidang yang
`ST_PointOnSurface()`-nya NULL, adalah warisan geometri lama yang berdiri sendiri — tidak bertambah
satu pun karena penjajaran ini.

### 3.23 Tampilan web mengikuti gambar antarmuka

`Gambar Antarmuka.png` (di akar proyek) dipakai sebagai acuan rupa. Yang dikerjakan:

- **`beranda.php`** — halaman depan publik yang meniru susunan gambar itu: cap
  `PEMERINTAH DESA GEDONG / KECAMATAN PATEAN / KABUPATEN KENDAL` di kiri atas, judul
  dua warna ("Sistem Informasi Geografis" biru, "Pertanahan Desa" hijau), tagline,
  empat pintu layanan (Peta Interaktif, Data Bidang Tanah, Data Pemilik, Laporan &
  Statistik), dan pita biru miring di kanan bawah bertuliskan *Desa Gedong — Maju
  Desa, Sejahtera Masyarakat* (`clip-path`, bukan gambar). Panel kanan **bukan** hiasan:
  Leaflet di atas citra Esri dengan geometri asli satu blok penuh dari MariaDB, kartu
  info bidang, kompas, dan tombol zoom. Blok sampel dipilih yang anggotanya terbanyak
  (sekarang BLOK 2, 235 bidang ≈ 59 KB) supaya petakannya rapat. Halaman ini satu-satunya
  yang boleh dibaca tanpa akun, jadi geometrinya dibaca langsung dari basis data, bukan
  lewat `api/` yang akan ditutup untuk anonim (§7).
- **Palet** `assets/css/app.css` mengikuti gambar: hijau `#1f9d4d`, biru `#0f4c81`, biru
  tua `#0a2f4f`; radius kartu 14 px; font utama **Plus Jakarta Sans** dan tulisan tangan
  **Caveat** untuk tagline, dimuat dari Google Fonts seperti Leaflet. Warna lapisan Hak
  Milik / Hak Pakai di `peta.php` ikut disamakan agar legenda dan peta tidak berbeda rupa.
- **`assets/img/beranda-latar.jpg`** — latar foto langit+sawah untuk pahlawan beranda,
  dibuat dari gambar (bukan potongan berkas mockup), dikecilkan ke 1600 px dan dikompresi
  ke 178 KB memakai `php -d extension=gd`
  (ekstensi GD bawaan XAMPP dinonaktifkan di `php.ini`).
- **Navigasi** `app/layout.php` menambah item *Beranda* dan memendekkan label menu
  (`Peta`, `Bidang`, `Buku C`, `Akun`) supaya sembilan item muat tanpa menggulir; merek
  di kiri atas kini menuju `beranda.php`, dasbor pindah kunci menu `dasbor`.
  `sip_header()`/`sip_footer()` menerima opsi `penuh` untuk halaman yang wadahnya melebar
  sampai tepi layar.
- **`login.php`** memakai identitas yang sama. Latar halaman ini bukan foto hasil
  generate lagi, melainkan **potongan `Gambar Antarmuka.png`**: 32 % bawah gambar
  (punggungan gunung, desa, sawah terasering, daun hijau kiri, coretan biru "Desa Gedong"
  kanan) disimpan sebagai `assets/img/masuk-latar.jpg` 1672 × 301 px / 144 KB, lalu
  ditempel di dasar layar dengan gradien langit di atasnya. Ujung atas potongan
  dipudarkan 90 px ke warna `#cbe5f7` memakai `imagecolorallocatealpha()` — tanpa itu
  garis potongannya memotong punggungan dan terbaca sebagai garis horizontal. Kotak
  login sendiri putih pekat, jadi gambarnya tidak perlu disamarkan. Pada layar 561–900 px
  tinggi pita dikunci 240 px dan disandarkan ke kanan supaya tulisan "Desa Gedong" ikut
  terlihat; di bawah 560 px pita kembali selebar layar — tingginya menyusut jadi ±70 px,
  tapi memotongnya pada 240 px meninggalkan "Sejahtera Masyaraka…" yang terbaca rusak.
  Garis hijau di tepi atas kartu, tagline Caveat, dan taut
  "Kembali ke beranda" dipertahankan.

Verifikasi dilakukan di peramban pada 127.0.0.1:8099: beranda 235 path vektor, kartu info
berubah saat bidang lain diklik (`HM-0001` → `HM-0137`, 552 m², CASWADI), tombol zoom dan
pusat berfungsi, nol pesan konsol; dasbor, daftar bidang, dan peta ikut diperiksa. Halaman
masuk juga difoto ulang sesudah latarnya diganti: pita sawah menutupi sepertiga bawah layar,
batasnya dengan langit tidak terlihat sebagai garis potong, dan nol pesan konsol. Empat lebar
sekaligus (390/480/768/1280 px) diperiksa lewat bingkai uji `_lama/scratch/uji-viewport.html`
— berkas itu hanya untuk dibuka dari disk, sebab `_lama/` ditutup dari HTTP. Dari bingkai
itulah aturan 560 px di atas lahir: pada 390 px pita 240 px memotong merek jadi separuh kata.

Yang **tidak** ikut diubah: susunan kartu dasbor dan seluruh halaman kerja — hanya palet,
font, dan menu yang berubah di sana. Angka "perlu verifikasi" pada kartu ringkas beranda
sengaja diberi keterangan bahwa sebagian besar adalah lapisan bawaan obyek non-tanah
(§3.15), bukan temuan lapangan.

---

### 3.24 Halaman Pengaturan: cadangan, ekspor, impor, kosongkan

Menu **Dasbor** keluar dari deretan menu utama dan pindah jadi tautan kecil di
kanan atas (`index.php` tetap dipertahankan); tempatnya diisi **Pengaturan** —
`pengaturan.php`, hanya `super_admin`. Mesinnya dipisah ke `app/lib-pengaturan.php`
supaya halaman tetap berisi tampilan dan alur "yakin" saja. Empat kartu, satu layar:

1. **Cadangan basis data** — `mysqldump.exe` menulis seluruh 17 tabel ke
   `storage/cadangan/cadangan-<waktu>.sql`; geometri ikut, jadi satu berkas ini cukup
   untuk memindahkan desa ke komputer lain. Kredensial tidak pernah lewat baris perintah:
   `pengaturan_kredensial()` menaruh host/user/sandi di berkas `[client]` sementara (mode
   0600) yang dihapus lagi sesudah proses selesai. Pemulihan memakai `mysql.exe` dengan
   berkas cadangan di *stdin*, dan membuat `sebelum-pulihkan` lebih dulu supaya bisa dibalik.
   Kalau `mysqldump`/`mysql` tidak ketemu (dijajal di `db.bin` lalu jalur XAMPP/Laragon),
   sistem menolak dan menyuruh mengisi kunci `bin` di `app/config.php` — bukan diam-diam
   mencoba cara lain.
2. **Ekspor data** — seluruh tabel ke satu berkas JSON di `storage/ekspor/`, geometri
   sebagai WKB heks (`{"wkb":"0103…","srid":4326}`). Ditulis mengalir lewat `php://temp`
   supaya memori tidak ikut membengkak bersama 9,3 MB geometri. Laporan CSV untuk Excel
   tetap `php tools/ekspor.php`; berkas JSON ini khusus untuk dibaca kembali oleh kartu 3.
3. **Impor data** — unggah berkas hasil ekspor (batas `upload_max_filesize`, nama dibuat
   server, disimpan ke `storage/impor/`). Langkah pertama selalu **uji-kering**: tabel
   perbandingan "di berkas / sekarang" plus cap waktu dan nama basis data asal, dan berkas
   yang bukan hasil ekspor ditolak di sini sebelum ada satu pun pertanyaan yakin.
   Menerapkannya butuh kata kunci `PULIHKAN` diketik ulang, cadangan `sebelum-impor` dibuat
   otomatis, lalu seluruh tabel ditimpa dalam **satu transaksi** (`FOREIGN_KEY_CHECKS=0`
   supaya urutan tabel tidak relevan).
4. **Kosongkan data** — enam kelompok terpilih-sendiri (PBB, bidang+hak, buku C+peralihan,
   laporan, blok, jejak audit), masing-masing menunjukkan jumlah barisnya sekarang dan
   peringatan merah kalau akibatnya tidak jelas. Master data (`dusun`, `jenis_hak`,
   `jenis_penggunaan`, `jenis_peralihan`, `setelan`) dan tabel `pengguna` tidak ada di
   kelompok mana pun, jadi tidak mungkin ikut terhapus. Alurnya `HAPUS` → uji-kering →
   `HAPUS` lagi → cadangan wajib → `DELETE`. `kosongkan_jalankan()` menolak jalan kalau
   berkas cadangannya tidak benar-benar ada di cakram.

Penjagaan yang berlaku di semua kartu: `wajib_csrf()`, unduhan lewat `?unduh=`/`?unduh-ekspor=`
harus membawa token CSRF di kueri, `pengaturan_aman()` menyaring nama berkas dengan
`basename()` + pola + pengecekan `realpath` di dalam folder (jadi `?unduh=../../app/config.php`
membalas "Berkas tidak ditemukan."), dan setiap tindakan ditulis ke `audit_log`.

**Bukti lingkaran penuh** dijalankan di salian sekali-pakai `sipetades_uji`
(`_lama/scratch/uji-pengaturan.php`): 18.242 baris diekspor → seluruh tabel dikosongkan →
diimpor balik → **sidik jari sama** (jumlah baris + MD5 per baris, geometri lewat
`ST_AsText`); lalu dikosongkan lagi dan dipulihkan dari `.sql` langkah 3 → **sama juga**.
Empat cacat yang hanya muncul di uji itu:

- Kunci tabel ditulis `json_encode($tabel) . '":{"kolom":'` — satu tanda kutip berlebih
  membuat seluruh berkas ekspor tidak terbulat (`Syntax error`) padahal ukurannya normal.
- `/^([0-9A-Fa-f]{2})*$/` menembus `pcre.backtrack_limit` pada WKB heks 29.166 karakter
  milik BLOK 2, sehingga geometri yang sah dilaporkan "rusak". Ganti dengan
  `strlen() % 2` + `ctype_xdigit()`.
- `proc_open()` tidak mengembalikan *resource* pada indeks 0 kalau stdin dibuat dari
  `['file', …, 'r']` — `fclose($pipa[0])` melempar galat sesudah prosesnya sudah berjalan.
- Dua aksi uji-kering tidak memanggil `redirect()`, jadi jatuh ke
  `throw 'Aksi tidak dikenal'` dan hasilnya selalu didampangi peringatan merah yang palsu.

Tiga aturan CSS ikut dibetulkan karena halaman ini memunculkannya: `input { width: 100% }`
yang global membuat kotak centang kelompok melebar dan mendorong labelnya ke kanan
(`.grup-baris input` dikunci 16 px), sembilan menu tidak muat di 1280 px sehingga
"Pengaturan" terpotong (tingkat ≤1330 px memperkecil jarak dan ukuran huruf menu,
≤1180 px menyembunyikan nama pengguna), dan grid kartu runtuh jadi satu kolom di ≤1080 px.
Diperiksa di peramban pada 1232/768/390 px: nol elemen melewati lebar layar, uji-kering
impor dan pengosongan tampil tanpa pesan galat, dan basis data produksi tidak tersentuh
(`bidang` 4.439, `blok` 24, `laporan` 0 sebelum dan sesudah).

---

### 3.25 Garis "PTP" di peta utama: batas desa dari lapisan 010500

Pertanyaan "garis dengan nama PTP belum terpetakan" ternyata bukan soal bidang
yang tertinggal. `PTP` hanya muncul sebagai **teks**: 20 label pada lapisan
`BATAS DESA` di `MASTER-dari-dwg.dxf`, tanpa satu pun entitas garis bernama PTP.
Garis yang dimaksud adalah cincin lapisan `010500` — poligon tertutup 531 titik
seluas ±6,7 km² yang memang sejak §3.22 sengaja tidak pernah dipasang sebagai
persil. Jadi yang kurang bukan datanya, melainkan tampilannya: `peta-lama.php`
punya layer "010500 — garis luar" di koordinat DXF, sedangkan `peta.php` (WGS84,
dipakai sehari-hari) tidak menggambar batas desa sama sekali.

Yang dikerjakan:

- **`api/bidang.php`** mendapat cabang `?lapisan=batas`. Berkas
  `files/sig-pertanahan-gedong-lokal/data/dxf-layers/010500.geojson` dibaca,
  tiap titik meter lokal diterjemahkan `dxfKeWgs84()` dari `tools/lib-impor.php`,
  lalu dikirim sebagai satu `LineString` tertutup (532 titik, 6 desimal).
  Hasil terukur: lon 110,055172–110,085375, lat −7,099005…−7,064871 — seluruh
  4.396 bidang berada di dalamnya. Tidak ada tabel atau baris basis data yang
  disentuh. Bila berkas konversi belum ada, API menjawab 404 dengan pesan agar
  `tools/konversi-dxf-lapisan.php` dijalankan.
- **`peta.php`** menambah kotak centang **Batas desa** (bawaan aktif, tersimpan
  di `localStorage` seperti lapisan lain) dan garis putus-putus biru tua
  `#1f4e79` selebar 2 px tanpa isian, `interactive: false` supaya klik tetap
  jatuh ke bidang di bawahnya. Legenda mendapat contoh garis putus-putus
  "Batas desa (010500)". Lapisan ini tidak ikut `fitBounds` awal — pemusatan
  tetap mengikuti sebaran bidang dari saringan.
- Layer **BATAS BLOK** di `peta-lama.php` menampilkan 24 geometri batas dari
  tabel `blok`; 19 batas ukur memakai garis hijau solid dan 5 batas perkiraan
  memakai garis cokelat putus-putus. Kotak centang **Ruas acuan BATAS BLOK**
  menampilkan 72 fitur DXF mentah sebagai overlay oranye putus-putus untuk
  perbandingan. Ruas mentah adalah referensi saja dan tidak mengubah geometri
  atau status batas di basis data.

Berkas GeoJSON lapisan DXF berada di bawah `files/` yang di-.gitignore, jadi
pada salinan baru batas desa baru tampil setelah konversi DXF dijalankan sekali;
halaman hanya menampilkan pesan galat bila berkas belum ada, bukan gagal senyap.

---

### 3.26 Garis blok perkiraan dirapikan: larutkan, haluskan, sambung, potong tumpang

Blok hasil "kira" (`sumber_perkiraan =
'nearest-unmapped-parcel-within-distance-limit'`) mendapat garis dari batas
anggota bidangnya, bukan dari garis ukur. Karena itu bentuknya bergerigi,
terdiri dari puluhan bagian yang menempel tak beraturan, berlubang celah
antar-bidang, dan kadang cincinnya memotong diri sendiri. Aturan cakupannya:
**satu bagian per blok**, kecuali bagian yang benar-benar terpotong jalan.

```
php tools/rapikan-garis-blok.php 3 8 11 17 19 21 22 23   # uji kering
php tools/rapikan-garis-blok.php 3 8 11 17 19 21 22 23 --terapkan
```

Lima langkah, semuanya dijalankan ulang tiap kali (tanpa berkas antara):

1. **Larutkan** — MariaDB 10.4 tidak punya `ST_Union` agregat, jadi bagian
   di-union berpasangan dengan `ST_Union(a, b)` sampai satu geometri. Tidak ada
   `ST_Simplify`/`ST_IsValid` yang dipakai.
2. **Buang lubang kecil** — cincin dalam ≤500 m² dibuang, karena lubang itu
   hanya celah antar-bidang, bukan rongga.
3. **Haluskan** — Douglas–Peucker 1,5 m dengan jarak dihitung dalam meter
   (lon dikali 111.320·cos(lintang rata-rata blok)). Cincin hasilnya diuji
   perpotongan sesama sisi; bila memotong diri, toleransinya dibagi dua (tiga
   tingkat) lalu dikembalikan ke bentuk larutan bila tetap gagal.
4. **Sambung bagian** — pasangan bagian terdekat dihubungkan Prim (MST) dengan
   jembatan selebar 1,5 m sebagai poligon empat titik, jadi tidak memakai
   `ST_Buffer` (ujung bulatnya menambah ribuan titik). Batas bawaan 30 m
   (`--rapat=<m>`). Sebuah sambungan **tidak** dibuat bila garis hubungnya:
   - melintasi jalan OSM selebar **3 m atau lebih** (lebar = 2 × `setengah_m`
     di `JALAN_SETENGAH_M`), jadi blok tetap terpisah di tempat yang memang
     terpotong jalan; atau
   - memotong wilayah blok lain sepanjang > setengah jarak hubungnya (tanpa ini
     jembatan akan menabrak blok tetangga).

   Bagian yang terpisah 34–141 m tanpa jalan di antaranya dibiarkan terpisah dan
   dilaporkan, bukan dipaksa menyatu. Ambang ini diuji ulang pada geometri asli
   saat diturunkan menjadi ≥3 m: hanya ada beberapa ruas tepat 3 m di dekat
   BLOK 3, 11, dan 23, dan tidak satu pun dilintasi garis jembatan, jadi hasil
   terpasang tidak berubah.
5. **Potong tumpang** — bagian hasil penghalusan dan jembatan yang masuk ke garis
   blok lain dikurangi dengan `ST_Difference` berurutan, supaya tidak muncul
   tumpang tindih baru; `--biarkan-tumpang` mematikan langkah ini.

Hasil satu batch (cadangan
`storage/cadangan/sebelum-rapikan-blok-3-8-11-17-19-21-22-23-20261101-082141-43ee.sql`,
3,1 MB). Semua di bawah batas selisih luas 5%:

| Blok (id) | bagian | titik | lubang | luas (m²) | jembatan | lewati jalan/blok/jauh |
|---|---|---|---|---|---|---|
| 3 (18) | 26 → 5 | 428 → 500 | 0 → 0 | 222.246,4 → 222.296,3 (+0,02%) | 21 | 3 / 1 / 0 |
| 8 (19) | 16 → 2 | 367 → 223 | 5 → 1 | 164.657,4 → 164.602,2 (−0,03%) | 7 | 0 / 1 / 0 |
| 11 (20) | 2 → 1 | 24 → 16 | 0 → 0 | 5.178,6 → 5.172,2 (−0,12%) | 0 | 0 / 0 / 0 |
| 17 (21) | 26 → 6 | 394 → 281 | 1 → 0 | 72.749,9 → 72.995,1 (+0,34%) | 18 | 2 / 0 / 3 |
| 19 (22) | 22 → 5 | 504 → 296 | 4 → 0 | 176.911,7 → 176.947,9 (+0,02%) | 10 | 1 / 0 / 2 |
| 21 (23) | 47 → 6 | 754 → 467 | 4 → 2 | 136.376,4 → 136.320,0 (−0,04%) | 30 | 3 / 0 / 0 |
| 22 (24) | 22 → 6 | 359 → 240 | 2 → 0 | 61.256,3 → 61.281,2 (+0,04%) | 12 | 5 / 0 / 0 |
| 23 (25) | 27 → 8 | 740 → 505 | 19 → 3 | 258.806,5 → 259.206,9 (+0,15%) | 10 | 5 / 0 / 1 |
| **jumlah** | **188 → 39** | **3.570 → 2.528** | **35 → 6** | | **108** | **19 / 2 / 6** |

Cincin yang memotong diri 6 → 0 dan irisan yang dipotong 349,0 m². Anggota blok
dan tanda `batas_perkiraan` tidak berubah, hanya garis luarnya. Titik bisa
bertambah (BLOK 3: 428 → 500) karena jembatan dan normalisasi `ST_Union`
menambah verteks; yang dinilai rapi adalah bentuknya, bukan jumlah titiknya.
Garis tipis yang masih terlihat di dalam blok adalah lekuk batas yang mengikuti
celah antar-bidang asal (lebar ≤1 m), bukan cincin yang memotong diri —
independen dicek ulang 0 silang, dan menutupnya dengan closing 0,5 m hanya
menambah 0,02–0,19% luas, jadi dibiarkan.

Dua anomali data yang dibiarkan dan wajib dilaporkan, bukan diperbaiki diam-diam:

- **Bagian yang hanya bisa disambung lewat blok lain.** BLOK 3 punya bagian
  25,9 m dari badan utamanya, dengan 26,1 m garis hubung jatuh di wilayah
  BLOK 8; BLOK 8 punya bagian 24,1 m dengan 24,3 m di wilayah BLOK 17. Bagian
  terkecil BLOK 3 adalah kantong **443 m²** (bidang DXF-020100-2059) yang
  seluruhnya dikelilingi BLOK 8. Semuanya dibiarkan terpisah — kemungkinan besar
  bidangnya salah ditugaskan, jadi perlu dicek manual, bukan disambung paksa.
- **Bagian terpisah 34–141 m tanpa jalan.** BLOK 17 (38,5; 39,8; 141,3 m),
  BLOK 19 (88,6; 67,3 m), BLOK 23 (34,4 m). Tidak ada jalan ≥3 m di antaranya,
  jadi tidak disambung dan tidak dipotong; dilaporkan sebagai kandidat salah
  penugasan bidang.

**Batch kedua: 17 blok berbatas ukur (1 Nov 2026).** Atas permintaan
"terapkan perapian ke semua blok yang belum diproses", sisa blok yang belum
disentuh (2, 4–7, 9, 10, 12–16, 18, 20, 24–26) ikut dijalankan. Semuanya sudah
1 bagian, 0 lubang, dan 0 cincin memotong diri, jadi langkah sambung tidak
aktif; yang berubah hanya penghalusan (2.325 → 1.200 titik, −48%) dan potong
tumpang 170,2 m². Luas bergeser antara −0,13% dan +0,14%, semuanya di bawah
penjaga 5%. Karena ini garis ukur, toleransi 1,5 m menggeser tepi paling jauh
1,5 m — bisa dipulihkan dari cadangan
`storage/cadangan/sebelum-rapikan-blok-2-4-5-6-7-9-10-12-13-14-15-16-18-20-24-25-26-20261101-084328-1067.sql`
(3,1 MB) atau per blok dari jejak `storage/rapikan-blok-<id>-20261101-084329.json`.
Setelah dua batch: 25 blok, 56 bagian (39 di antaranya dari 8 blok perkiraan),
3.728 titik, tumpang antar-blok 1,34 m² (BLOK 21 × 23), dan 255 bidang yang titik
tengahnya di luar bloknya (turun dari 257).

**Batch ketiga: semua blok dipaksa 1 bagian (1 Nov 2026).** Atas permintaan
"terapkan semua garis batas blok menjadi 1 bagian", delapan blok perkiraan
dijalankan dengan mode baru `--satukan`:

```
php tools/rapikan-garis-blok.php 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 --satukan --terapkan
```

Mode ini mematikan uji jalan (jalan ≥3 m tidak lagi memisahkan), mematikan uji
wilayah blok lain, menaikkan batas jembatan ke 5.000 m, dan **melewati potong
tumpang** — kalau tumpang dipotong, koridor yang menembus blok tetangga ikut
hilang dan blok terbelah lagi. Hasilnya 25 blok × 1 bagian: 39 bagian tambahan
disambung dengan 31 pita 1,5 m (BLOK 3: 4, BLOK 8: 1, BLOK 17: 5, BLOK 19: 4,
BLOK 21: 5, BLOK 22: 5, BLOK 23: 7). Titik turun 3.728 → 3.492; luas naik
4.471.644,9 → 4.473.702,6 m² (+0,046%), tiap blok bergeser +0,07%…+0,73%.
BLOK 11 dan 17 blok ukur tidak disentuh karena sudah 1 bagian.

Harga yang dibayar: tumpang antar-blok naik dari 1,34 m² menjadi 472,07 m² pada
12 pasangan (terbesar BLOK 16 × 17 124,45 m², lalu BLOK 24 × 19 72,47 m² dan
BLOK 3 × 8 65,78 m²), karena koridor kini boleh menembus blok tetangga dan
pemotongan tumpang dimatikan. Koridor terpanjang adalah penyambung kantong 443 m²
BLOK 3 yang berada di lubang BLOK 8. Cadangan sebelum batch:
`storage/cadangan/sebelum-rapikan-blok-2-3-4-5-6-7-8-9-10-11-12-13-14-15-16-17-18-19-20-21-22-23-24-25-26-20261101-085218-8cfd.sql`
(3,1 MB); jejak tujuh blok yang berubah
`storage/rapikan-blok-<id>-20261101-085219.json`. Menjalankan ulang mode ini
melaporkan 25 blok "sudah rapi" tanpa mengubah apa pun. Bidang yang titik
tengahnya di luar bloknya turun 255 → 251.

Bug yang ikut diperbaiki: pita sambung untuk dua bagian yang jaraknya 0,0 m
dulu menghasilkan WKT kosong sehingga sambungan putus meski dilaporkan
"disambung" (BLOK 19 berhenti di 2 bagian). Sekarang titik terdekat yang sama
dipakai sebagai titik tengah pita pendek 0,6 × 1,5 m.

**Kantong 443 m² BLOK 3 dipindah ke BLOK 8 (1 Nov 2026).** Kantong itu bidang
`DXF-020100-2059` (id 2647, 443,1 m²) yang menempel pada 3 bidang BLOK 8
(jarak 0,00 m) dan hanya 1,09 m dari bidang BLOK 3; garis hubungnya ke badan
BLOK 3 menembus 26,1 m wilayah BLOK 8. Bidang dipindah dengan
`php tools/impor.php ubah-blok 8 DXF-020100-2059 --terapkan`.

Membangun ulang kedua batas dengan `impor.php kira 3 8 --tepat` **tidak dipakai**:
uji keringnya membengkakkan luas (BLOK 3 +7% menjadi 238.207 m², BLOK 8 +15%
menjadi 189.087 m²) karena garis blok lama tidak pernah dibangun dari seluruh
anggota yang sekarang. Jadi geometri dibedah langsung: bagian kantong dibuang
dari garis BLOK 3 hasil rapikan sebelumnya (5 → 4 bagian), parcelnya di-union ke
garis BLOK 8 (hasil `POLYGON` dibungkus `MULTIPOLYGON` karena tipe kolom), lalu
`rapikan-garis-blok.php 3 8 --satukan` dijalankan ulang. Cadangan per blok
`storage/kantong-443-<id>-*.json` dan cadangan penuh
`storage/cadangan/sebelum-kantong-443-20261101-085636-bfb2.sql`.

Hasil: BLOK 3 222.628,4 → 222.082,1 m² (−546,3; 1 bagian, 466 titik, cincin
memotong diri 1 → 0, hanya 3 jembatan lintas jalan), BLOK 8 164.722,8 → 165.184,7
m² (+461,9; 1 bagian, 180 titik, lubang tetap 1). Luas total 4.473.616,3 m².
Tumpang antar-blok turun 472,07 → 403,48 m²: BLOK 3 × 8 65,78 → 26,74 m² dan
BLOK 8 × 17 37,07 → 33,82 m²; sisa 26,74 m² itu setebal tepi hasil penghalusan,
bukan koridor lagi. Titik tengah bidang 2647 kini di dalam BLOK 8, dan jumlah
bidang yang titik tengahnya di luar bloknya tetap 251. Cadangan penuh sebelum
perapian ulang `storage/cadangan/sebelum-rapikan-blok-3-8-20261101-085856-a0a6.sql`,
jejak `storage/rapikan-blok-18-20261101-085856.json` dan
`storage/rapikan-blok-19-20261101-085856.json`.

**Tumpang BLOK 16 × 17 hilang: pulau bidang `DXF-020100-0641` pindah ke BLOK 8
(1 Nov 2026).** Tumpang 124,45 m² itu bukan tepi yang bergeser akibat
penghalusan, melainkan pita sambung 1,5 m sepanjang ±73 m yang dibuat
`--satukan` untuk menyambung **satu bidang** ke badan BLOK 17. Bidang itu
(1.444,4 m²) berjarak 230 m dari bagian BLOK 17 lainnya dengan BLOK 16 di
antaranya, sementara tepinya bersinggungan langsung dengan tiga bidang BLOK 8
(jarak 0,00 m) dan dua bidang tanpa blok. Pita yang sama juga menembus BLOK 8,
itulah tumpang BLOK 8 × 17 33,82 m².

Bidangnya dipindah dengan `php tools/impor.php ubah-blok 8 DXF-020100-0641
--terapkan`, lalu geometrinya disusulkan: garis BLOK 8 di-union dengan bidang
itu (165.184,7 → 166.592,2 m², tetap 1 bagian; garis lama hanya menaungi 2,6%
bidang karena batas perkiraan di situ memang kurang), dan BLOK 17 dibangun ulang
dari lima bagian lamanya tanpa pulau tersebut (73.531,8 → 71.568,0 m²,
5 bagian), lalu `rapikan-garis-blok.php 17 --satukan` menyambungnya dengan 4
pita menjadi 71.890,4 m², 237 titik. Cadangan penuh
`storage/cadangan/sebelum-tumpang-b16-17-20261101-090516-4cc4.sql`, cadangan
per blok `storage/tumpang-b16-17-19-*.json` dan `storage/tumpang-b16-17-21-*.json`,
`storage/ubah-blok-ke-BLOK-8-*.json`, serta
`storage/cadangan/sebelum-rapikan-blok-17-20261101-090638-d7be.sql` dan jejak
`storage/rapikan-blok-21-20261101-090639.json`.

Hasil: tumpang antar-blok 403,48 → 245,22 m² di 9 pasang; BLOK 16 × 17 dan
BLOK 8 × 17 keduanya 0. 25 blok tetap 1 bagian, luas total 4.473.616,3 →
4.473.382,4 m². Titik tengah bidang 0641 kini di dalam BLOK 8; jumlah bidang
bertitik tengah di luar bloknya tetap 251, bidang tanpa blok tetap 22. Sisa
tumpang terbesar saat itu BLOK 24 × 19 (72,47 m²) dan BLOK 7 × 3 (57,35 m²).

**Tumpang BLOK 24 × 19: garis BLOK 24 dipangkas (1 Nov 2026).** Koridor 1,5 m
sepanjang 47 m itu bukan data salah: BLOK 19 (batas **perkiraan**) memang
terbelah BLOK 24 (batas **ukur**) menjadi dua kantong sah, 45.715,1 m² dan
131.495,2 m²; letak kantongnya terbukti dari 6 bidang anggota yang titik
tengahnya di dalam BLOK 19, dan dua bidang BLOK 24 di bawah koridor juga sah
(100% di dalam garisnya). Empat kemungkinan sambungan BLOK 19 diukur: 2×4
menembus BLOK 24 65,8 m², 3×4 menembus BLOK 20 119,5 m², 1×4 dan 1×5 menembus
BLOK 24 128,7 m²; hanya 1×2, 2×3 dan 4×5 yang bebas. Jadi selama BLOK 19
dipaksa 1 bagian, tumpang itu tak terhindarkan. Petugas memilih **memangkas
garis BLOK 24** (bukan membelah BLOK 19, bukan membiarkan tumpang).

Garis BLOK 24 di-`ST_Difference` dengan irisan itu: 143.147,1 → 143.074,6 m²,
tetap 1 bagian, dan tumpang BLOK 24 × 19 72,47 → 0. Harga yang diterima: dua
bidang BLOK 24 (`DXF-BIDANGHM-0041`, `DXF-BIDANGHM-0367`) kini menyembul di luar
garisnya 79,3 m² (sebelumnya 9,6 m²; bertambah 69,7 m² setebal koridor).
Tumpang antar-blok 245,22 → 172,75 m² di 8 pasang, luas total 4.473.382,4 →
4.473.310,0 m², 25 blok tetap 1 bagian, bidang 4.397 (22 tanpa blok, 251
bertitik tengah di luar blok). Cadangan penuh
`storage/cadangan/sebelum-pangkas-b24-20261101-091336-967f.sql` dan cadangan
per blok `storage/pangkas-b24-16-20261101-091353.json`. Menjalankan ulang
`rapikan-garis-blok.php 19 --satukan` akan mengembalikan koridor itu, jadi BLOK
19 jangan dirapikan ulang tanpa memikirkan pangkasan ini.

**Tumpang BLOK 7 × 3: bulge penghalusan BLOK 3 dipangkas (1 Nov 2026).** Sisa
57,35 m² itu bukan pita sambungan: bentuknya sliver 3 verteks sepanjang ±100 m,
lebar 1,2 m di pangkal, dan muncul hanya pada bagian ke-2 BLOK 3 (jarak 0,00 m;
tiga bagian lain BLOK 3 berjarak ≥80 m). Sliver ini tepat sama dengan pita
ke-4 (57,4 m²) hasil `ST_Difference` garis BLOK 3 sekarang terhadap `sebelum`
jejak `storage/rapikan-blok-18-20261101-085856.json`, jadi asalnya penghalusan
ulang, bukan pergeseran data. Isinya bidang BLOK 7 `DXF-BIDANGHM-0025`
(52,85 m²) dan dua bidang BLOK 3 (4,51 m² dan 0,00 m²). Karena BLOK 7 berbatas
**ukur** dan BLOK 3 berbatas **perkiraan**, yang dipangkas adalah BLOK 3:
`ST_Difference(BLOK 3, BLOK 7)` → 222.082,1 menjadi 222.024,7 m², tetap
1 bagian (468 titik). Tumpang BLOK 3 × 7 57,35 → 0; irisan BLOK 3 dengan blok
lain 84,10 → 26,74 m² (tinggal BLOK 3 × 8). Tumpang antar-blok 172,70 → 115,35
m² di 6 pasang, luas total 4.473.310,0 → 4.473.252,6 m², 25 blok tetap
1 bagian, bidang 4.397 (22 tanpa blok, 251 bertitik tengah di luar blok).
Cadangan penuh `storage/cadangan/sebelum-pangkas-b3-20261101-091848-7aed.sql`
dan jejak `storage/pangkas-b3-18-20261101-091849.json`. Sama seperti BLOK 19,
`rapikan-garis-blok.php 3 --satukan` bisa memunculkan bulge baru di tepi BLOK 7,
jadi setelah dijalankan pangkasan ini perlu diulang.

**Tumpang BLOK 3 × 8 dipangkas (1 Nov 2026).** Kedua blok berbatas **perkiraan**,
jadi tidak ada garis ukur yang otomatis dianggap benar. Yang menentukan siapa
berhak atas 26,74 m² itu isi bidangnya: 21,80 m² (81,5%) ditutupi dua bidang
BLOK 8 (`DXF-020100-0897` 13,37 m² dan `DXF-020100-1536` 8,43 m²), sedangkan
bidang BLOK 3 hanya memakai 4,95 m² (`DXF-020100-2897` 3,08,
`DXF-BIDANGHM-0292` 1,36, `DXF-BIDANGHM-0417` 0,51). Wilayah itu milik BLOK 8,
jadi garis BLOK 3 yang dipangkas: `ST_Difference(BLOK 3, BLOK 8)` → 222.024,7
menjadi 221.998,0 m², tetap 1 bagian (473 titik). Tumpang BLOK 3 × 8 26,74 → 0,
dan karena pasangan itulah satu-satunya sisa, BLOK 3 kini tidak beririsan dengan
blok mana pun. Tumpang antar-blok 115,35 → 88,60 m² di 5 pasang; luas total
4.473.252,6 → 4.473.225,9 m²; 25 blok tetap 1 bagian; bidang 4.397 (22 tanpa
blok, 251 bertitik tengah di luar blok). Ongkosnya kecil: bidang yang menyembul
di luar garis BLOK 3 bertambah 4,95 m² (220 bidang, 21.439,9 → 21.444,9 m²).
Cadangan penuh `storage/cadangan/sebelum-pangkas-b3-b8-20261101-093821-0189.sql`
dan jejak `storage/pangkas-b3-b8-18-20261101-093822.json`. Sisa tumpang terbesar
saat itu BLOK 26 × 23 (24,30 m²).

**Tumpang BLOK 26 × 23: jembatan `--satukan` melintasi blok ukur (1 Nov 2026).**
Irisannya persegi panjang 1,5 m × 16 m (24,30 m²), bukan sliver penghalusan.
Jejak `storage/rapikan-blok-25-20261101-085219.json` menunjukkan BLOK 23 tadinya
8 bagian dan disatukan dengan 7 jembatan bertoleransi 1,5 m; salah satu jembatan
itu — penyambung kantong selatan 30.184 m² ke badan utara — melintasi BLOK 26.
BLOK 26 berbatas **ukur**, dan 22,90 m² (94,2 %) dari irisan ditutupi bidang
BLOK 26 `DXF-BIDANGHM-0848`, sedangkan tidak ada satu pun bidang BLOK 23 di
irisan itu. Dua arah pemangkasan diukur lebih dulu: memangkas BLOK 26 memecahnya
menjadi 2 bagian di leher selebar 16 m dan menambah penyembulan bidang 22,91 m²,
memangkas BLOK 23 tidak membebani bidang mana pun. Jadi garis BLOK 23 yang
dipangkas: `ST_Difference(BLOK 23, BLOK 26)` → 259.482,5 menjadi 259.458,2 m²,
irisan dengan BLOK 26 tepat 0,000000 m² dan kedua tepi hanya bersentuhan (0,00 m).

Konsekuensinya BLOK 23 kini **2 bagian**: kantong selatan 30.184 m² berisi 3
bidang (`DXF-BIDANGHM-0002` 12.532,15, `DXF-020100-0014` 10.000,16,
`DXF-BIDANGHM-0008` 7.299,55) terpisah 16,05 m dari badan utama. Itu keadaan
jujurnya — satu-satunya penghubung adalah jembatan buatan `--satukan`, dan
koridor pengganti di luar BLOK 26 memang tidak ada. Jadi 24 dari 25 blok
1 bagian dan BLOK 23 2 bagian (26 bagian total); target "semua 1 bagian" di
batch ketiga sengaja dilepas di titik ini karena hanya begitu tumpangnya bisa
nol tanpa memotong garis ukur.

Tumpang antar-blok 88,60 → 64,30 m² di 4 pasang (BLOK 26 × 23 hilang; sisanya
BLOK 21 × 23 20,29, BLOK 19 × 23 19,25, BLOK 21 × 22 14,55, BLOK 22 × 23
10,21); dengan ambang 0,01 m² masih terlihat satu serpihan lama 0,05 m² BLOK
16 × 8 yang tidak tersentuh pangkasan ini. Luas total 4.473.225,9 → 4.473.201,6
m²; bidang 4.397 (22 tanpa blok, 251 bertitik tengah di luar blok). Cadangan
penuh `storage/cadangan/sebelum-pangkas-b26-b23-20261101-094647-c4f3.sql` dan
jejak `storage/pangkas-b23-b26-25-20261101-094648.json`. Menjalankan ulang
`rapikan-garis-blok.php 25 --satukan` akan memasang lagi jembatan itu, jadi
BLOK 23 jangan dirapikan ulang tanpa memikirkan pangkasan ini.

**Tumpang BLOK 21 × 22 dipangkas (1 Nov 2026).** Kedua blok berbatas
**perkiraan**. Irisan 14,55 m² itu 28 verteks dalam 1 ring yang berkelok
sepanjang ±300 m, jadi tipisnya merata ±1,5 m di tepi bersama — sisa penghalusan,
bukan koridor. Arah ditentukan isi bidang: BLOK 21 menutupi 12,95 m²
(`DXF-BIDANGHM-0323` 6,55, `DXF-BIDANGHM-0123` 5,48, `DXF-BIDANGHM-0555` 0,61,
`DXF-BIDANGHM-0774` 0,31) melawan BLOK 22 hanya 1,50 m² (`DXF-BIDANGHM-0161`);
0,10 m² tidak bertuan. Dua arah pemangkasan diuji dan keduanya tetap 1 bagian,
jadi keputusannya murni cakupan bidang. Garis BLOK 22 dipangkas:
`ST_Difference(BLOK 22, BLOK 21)` → 61.513,1 menjadi 61.498,6 m², 1 bagian;
irisan BLOK 22 dengan BLOK 21 tepat 0,000000 m² dan sisa irisan BLOK 22 dengan
blok lain tinggal BLOK 22 × 23 (10,21 m², tidak berubah). Ongkosnya 1,50 m²:
97 bidang BLOK 22 kini menyembul 6.099,95 → 6.101,45 m², sepersepuluh ongkos
arah sebaliknya (BLOK 21 +12,95 m²). Tumpang antar-blok 64,35 → 49,80 m² di
4 pasang; luas total 4.473.201,6 → 4.473.187,0 m²; 24 dari 25 blok 1 bagian
(BLOK 23 tetap 2 bagian, 26 bagian total); bidang 4.397 (22 tanpa blok, 251
bertitik tengah di luar blok). Cadangan penuh
`storage/cadangan/sebelum-pangkas-b21-b22-20261101-095023-1535.sql` dan jejak
`storage/pangkas-22-24-20261101-095023.json`. Sisa tumpang terbesar sekarang
BLOK 21 × 23 (20,29 m²).

**Tumpang BLOK 21 × 23 dipangkas (1 Nov 2026).** Kedua blok berbatas
**perkiraan**; irisan 20,29 m² itu 33 verteks dalam 1 ring yang berkelok ±490 m,
jadi lagi-lagi sliver tipis di tepi bersama. Cakupan bidang hampir imbang:
BLOK 23 menutupi 10,47 m² (`DXF-020100-2192` 8,37 + `DXF-BIDANGHM-0763` 2,10)
melawan BLOK 21 9,23 m² (`DXF-020100-0791` 3,64, `DXF-BIDANGHM-0459` 2,68,
`DXF-BIDANGHM-0472` 2,21, `DXF-020100-1914` 0,60, `DXF-BIDANGHM-0822` 0,10);
0,59 m² tidak bertuan. Karena imbang, arahnya dipilih dari ongkos, bukan cakupan
mayoritas: memangkas BLOK 21 menambah penyembulan 9,23 m², memangkas BLOK 23
10,47 m², dan keduanya tidak menambah bagian (BLOK 21 tetap 1, BLOK 23 tetap 2).
Garis BLOK 21 dipangkas: `ST_Difference(BLOK 21, BLOK 23)` → 136.569,0 menjadi
136.548,7 m², 1 bagian, dan BLOK 21 kini tidak beririsan dengan blok mana pun.
Tumpang antar-blok 49,80 → 29,51 m² di 3 pasang; luas total 4.473.187,0 →
4.473.166,7 m²; 24 dari 25 blok 1 bagian (BLOK 23 tetap 2 bagian, 26 bagian
total); bidang 4.397 (22 tanpa blok, 251 bertitik tengah di luar blok). Ongkos:
235 bidang BLOK 21 menyembul 12.995,58 → 13.004,81 m². Cadangan penuh
`storage/cadangan/sebelum-pangkas-blok21-blok23-20261101-095144-131e.sql` dan
jejak `storage/pangkas-23-25-20261101-095144.json`. Sisa tumpang terbesar
sekarang BLOK 19 × 23 (19,25 m²), lalu BLOK 22 × 23 (10,21 m²).

**Tumpang BLOK 19 × 23 dipangkas (1 Nov 2026).** Irisan 19,25 m² (35 verteks,
1 ring, berkelok ±520 m) juga sliver tepi. Kedua blok berbatas **perkiraan**,
tetapi kali ini isi bidangnya jelas: satu bidang BLOK 23 `DXF-020100-1508`
menutupi 13,84 m² (72 %) melawan 5,31 m² dari tiga bidang BLOK 19
(`DXF-BIDANGHM-0759` 4,03, `DXF-BIDANGHM-0244` 0,99, `DXF-BIDANGHM-0247` 0,28);
0,11 m² tanpa bidang. Garis BLOK 19 dipangkas: `ST_Difference(BLOK 19, BLOK 23)`
→ 177.282,7 menjadi 177.263,5 m², 1 bagian, dan BLOK 19 kini tidak beririsan
dengan blok mana pun. Ongkos 5,31 m² (117 bidang, 16.468,94 → 16.474,25 m²;
satu bidang tambahan menyembul), sepertiga ongkos arah sebaliknya (BLOK 23
+13,84 m²). Tumpang antar-blok 29,51 → 10,26 m² di 2 pasang; luas total
4.473.166,7 → 4.473.147,5 m²; 24 dari 25 blok 1 bagian (BLOK 23 tetap
2 bagian, 26 bagian total); bidang 4.397 (22 tanpa blok, 251 bertitik tengah
di luar blok). Cadangan penuh
`storage/cadangan/sebelum-pangkas-blok19-blok23-20261101-095253-59be.sql` dan
jejak `storage/pangkas-22-25-20261101-095253.json`. Sisa tumpang tinggal
BLOK 22 × 23 (10,21 m²) dan serpihan lama BLOK 16 × 8 (0,05 m²).

**Tumpang BLOK 22 × 23 dipangkas (1 Nov 2026).** Pasangan terakhir yang berarti:
irisan 10,21 m², 14 verteks dalam 1 ring (kotak 84 × 149 m), dan kali ini
cakupannya sepihak — tiga bidang BLOK 23 menutupi 9,81 m² (96 %)
(`DXF-BIDANGHM-0485` 5,30, `DXF-BIDANGHM-0346` 4,22, `DXF-020100-1491` 0,30)
melawan 0,36 m² dari satu bidang BLOK 22 (`DXF-BIDANGHM-0031`); 0,03 m² tanpa
bidang. Garis BLOK 22 dipangkas: 61.498,6 menjadi 61.488,4 m², 1 bagian, dan
BLOK 22 kini bebas tumpang. Ongkosnya hanya +0,36 m² penyembulan (97 bidang,
6.101,45 → 6.101,81 m²) melawan +9,81 m² bila BLOK 23 yang dipangkas.
Tumpang antar-blok 10,26 → 0,05 m²; luas total 4.473.147,5 → 4.473.137,2 m²;
24 dari 25 blok 1 bagian (BLOK 23 tetap 2 bagian, 26 bagian total); bidang
4.397 (22 tanpa blok, 251 bertitik tengah di luar blok). Cadangan penuh
`storage/cadangan/sebelum-pangkas-blok22-blok23-20261101-095353-69be.sql` dan
jejak `storage/pangkas-24-25-20261101-095354.json`. Yang tersisa hanya serpihan
lama BLOK 16 × 8 sebesar 0,0465 m² (9 verteks, 1 ring) yang sudah ada sebelum
rangkaian pangkasan ini — praktis nol, tetapi belum bertuan.

**Serpihan BLOK 16 × 8 ditutup (1 Nov 2026).** Tumpang terakhir ini lain sifatnya:
bukan koridor `--satukan`, bukan pita sambung, melainkan sliver tipis hasil
penghalusan Douglas–Peucker 1,5 m di tepi bersama — irisan 0,0465 m² dengan
9 verteks dalam 1 ring, tetapi kotaknya 113 × 76 m (garisnya nyaris berimpit
sepanjang puluhan meter) dan jarak tepi kedua blok 0,00 m. Aturan yang dipakai
bukan lagi cakupan bidang, melainkan kewenangan garis: **BLOK 16 berbatas ukur**
(127.671,5 m²), **BLOK 8 berbatas perkiraan** (166.592,2 m²), jadi garis BLOK 8
yang dipangkas walaupun bidang BLOK 8 `DXF-020100-0496` justru menutupi seluruh
serpihan (0,0464 m²) sedangkan BLOK 16 tidak punya bidang di situ. Alat pangkas
dua blok secara bawaan memilih arah berkebalikan (cakupan bidang menunjuk
BLOK 16), jadi arahnya dipaksa dengan `--pangkas="BLOK 8"`. Hasil:
`ST_Difference(BLOK 8, BLOK 16)` → 166.592,2442 menjadi 166.592,1977 m², tetap
1 bagian (2 cincin, 193 verteks), irisan sisa 0,0000, dan tidak ada tumpang baru
dengan blok lain. Ongkosnya 0,0464 m² bidang `DXF-020100-0496` menyembul di luar
garis BLOK 8. Cadangan penuh
`storage/cadangan/sebelum-pangkas-blok16-blok8-20261101-095619-72d7.sql` dan
jejak `storage/pangkas-19-4-20261101-095619.json` (berkas jejak dinamai dengan
**id** blok: 19 = BLOK 8, 4 = BLOK 16).

Sesudah langkah ini **tumpang antar-blok nol**: 0 pasang pada ambang 0,01 m²
(sebelumnya 1 pasang/0,05 m²). Luas total 4.473.137,2 m², 26 bagian untuk
25 blok, dan 24 dari 25 blok tetap 1 bagian (BLOK 23 sengaja 2 bagian sejak
pangkasan BLOK 26 × 23); bidang 4.397 baris/4.396 aktif (22 tanpa blok, 251
bertitik tengah di luar blok). Rangkaian pangkasan tumpang yang dimulai dari
BLOK 16 × 17 (124,45 m²) berakhir di angka nol, dengan harga yang selalu
dilaporkan: penyembulan bidang tipis di tiap blok yang dipangkas.

Pengamanan alat: WKT diuji `ST_GeomFromText` sebelum cadangan dibuat, hasil kosong
atau selisih luas >5% membatalkan seluruh proses kecuali `--paksa`, dan
`--terapkan` selalu menulis cadangan penuh lebih dulu. Menjalankan ulang aman:
per blok diperiksa dulu apakah geometri di basis data masih sama persis dengan
`sesudah.wkt` pada jejak terbaru `storage/rapikan-blok-<id>-*.json`
(`ST_Equals`); bila sama, blok dilewati dan dilaporkan "sudah rapi". Tanpa
pemeriksaan itu, pemotongan tumpang setelah penghalusan memunculkan verteks baru
dan run kedua akan menggerus garis. `--paksa` melewati semua penjagaan.
Opsi percobaan: `--toleransi=<m>`, `--lubang-maks=<m²>`, `--rapat=<m>`,
`--abaikan-jalan` (sambung tanpa uji jalan), `--satukan` (paksa 1 bagian: tanpa
uji jalan/blok, batas 5.000 m, tanpa potong tumpang), `--rinci` (tampilkan
keputusan tiap sambungan beserta koordinat), `--keluaran=<berkas.geojson>` (calon
hasil untuk dilihat di peta). Setiap run yang menulis membuat cadangan penuh dan
jejak `storage/rapikan-blok-<id>-<waktu>.json` berisi geometri sebelum dan
sesudah (pulihkan dengan `geojsonKeWkt()` dari `tools/lib-impor.php`).

### 3.27 Identitas bidang hasil impor ulang: nama, NIS, NIB, nomor daftar, jenis hak

Angka di §3.12, §3.13, §3.14, §3.15 dan §3.17 berasal dari basis data **sebelum**
impor ulang 31 Oktober 2026 (3.654–4.045 bidang hidup). Setelah dataset sekarang
(4.397 bidang) dipasang, seluruh kolom identitas kembali kosong: `nib`, `nis` dan
`nomor_daftar` 0, `hak.pemegang_nama` 0, `pemegang` 0, dan jenis hak semua `BLM`.
Bagian ini mencatat pengisian ulangnya pada 1 November 2026, berurutan, setelah
cadangan penuh `storage/cadangan/sebelum-identitas-bidang-20261101-100004-9e84.sql`
(3.165.993 byte, 17 tabel) dibuat. Setiap perintah menulis cadangan JSON sendiri
dan satu baris `audit_log`.

| Perintah | Label dibaca | Bidang terisi | Catatan |
| --- | --- | --- | --- |
| `nama` | 4.302 (`tn_nama`) | 1.018 dari 1.055 bidang tanah hidup | 1.005 lewat aturan `label`, 10 `tepi`, 3 `terdekat`, 0 `kembaran` |
| `nama-verifikasi` | 4.302 (`tn_nama`) | 3.015 bidang tipe `lain` | 1.004 bidang tanah sudah didahului `nama`; 94 bidang memuat dua nama atau lebih; 377 sasaran tanpa label; 0 rebut |
| `nis` | 2.832 (`tn_alashak`) | 2.359 | 2.342 sasaran kebagian label, 17 dipinjam dari label tepi ≤2 m; 248 bidang memuat lebih dari satu NIS; 0 sengketa tepi |
| `nib` | 1.629 (`NIB HM`) | 1.393 | 45 bidang tidak diisi karena nomornya ganda, 28 label bukan nomor |
| `daftar` | 3.154 (`080201`) | 2.902 di `nomor_daftar` | 2.901 tepat di dalam, 1 dari label tepi; 17 tidak diisi karena nomor ganda |
| `hak-milik` | — | 4.350 bidang `BLM` → `HM` | 46 bidang dikecualikan karena tertumpuk anotasi petugas (gang, tanah desa, bengkok, masjid) dan tidak punya nama orang; 0 baris hak baru, 0 nomor sertifikat/Buku C yang tersentuh |

Hasil akhir: **4.033 dari 4.396 bidang hidup bernama (91,7 %)**, `nib` 1.393,
`nis` 2.359, `nomor_daftar` 2.902, dan jenis hak 4.350 Hak Milik + 46 "Jenis hak
tidak tercatat" (semuanya kulit penutup celah ujung peta yang juga beranotasi).
Status seluruh bidang tetap `perlu_verifikasi`; 363 bidang masih tanpa nama
(per 2 November 2026 tinggal 358: 326 objek non-tanah + 32 bidang tanah, lihat
3.32).
Nama disimpan di `hak.pemegang_nama`, bukan di tabel `pemegang` (masih 0 baris) —
tabel itu untuk identitas orang yang dipakai SPPT, jadi mengisinya menunggu data
wajib pajak. `sppt`, `objek_pajak`, `buku_c` dan `pembayaran` tetap 0.

Dua hal sengaja belum dijalankan. `kode-nis` (mengganti kode `DXF-…` menjadi
`HM-<nis>`) akan mengubah kunci yang dipakai seluruh tautan peta dan audit, jadi
butuh keputusan tersendiri. `nama` juga hanya menyentuh bidang `tipe = 'tanah'`;
bidang `lain` diurus `nama-verifikasi` dengan aturan `label` saja. Seperti
sebelumnya, API publik menyamarkan nama pemegang menjadi `"tersimpan"` lewat
`sembunyikan_pribadi()`, sehingga tampilan anonim hanya memperlihatkan nomor
bidang, luas, blok, NIS, NIB dan nomor daftar.

Enam cadangan per perintah ada di `storage/nama-pemegang-20261101-100013.json`,
`nama-verifikasi-20261101-100102.json`, `nis-bidang-20261101-100317.json`,
`nib-bidang-20261101-100453.json`, `nomor-daftar-20261101-100456.json` dan
`hak-milik-20261101-100459.json`; `audit_log` bertambah 6 baris (75 → 81).

### 3.28 Buku C dan data PBB 2026 dari berkas desa

Dua berkas desa yang sudah lama ada di `files/` akhirnya masuk ke basis data
pada 1 November 2026. **Penting: perintah `buku-c` dan `sppt` tidak punya mode
periksa** — keduanya langsung menulis, tidak seperti perintah `nama`, `nis`,
`nib`, `daftar` dan `hak-milik` yang bawaannya hanya melapor. Cadangan penuh
dibuat sebelum dan sesudahnya:
`storage/cadangan/sebelum-identitas-bidang-20261101-100004-9e84.sql` dan
`storage/cadangan/sesudah-pbb-buku-c-20261101-101408-42eb.sql` (5.016.530 byte).

**Buku C.** `php tools/impor.php buku-c` mendaftarkan isi `files/Data C Desa`
(1.419 berkas PDF, satu per surat) ke tabel `buku_c` — 1.419 baris, 0 dilewati.
Isinya baru indeks berkas: nama pemegang dan isi surat belum diekstrak, jadi
kolom `buku_c.nama_pemegang` masih kosong.

**SPPT/PBB 2026.** Sumbernya dua laporan "Daftar Penerimaan PBB" dari desa,
`files/data excel/Laporan Daftar Penerimaan PBB_11532820261015.xlsx` (Sudah
Bayar, 2.200 baris sheet) dan `..._11540120261015.xlsx` (Belum Bayar, 1.630
baris sheet). Importer hanya menerima CSV, jadi tiap sheet diekstrak (zip + XML,
tanpa pustaka luar) lalu ditulis ke `files/data excel/sppt-2026-sudah-bayar.csv`
(2.182 NOP) dan `sppt-2026-belum-bayar.csv` (1.612 NOP); 9 baris per berkas
tanpa NOP 18 digit adalah baris judul/total dan dilewati. Aturan konversi yang
dipakai: NOP bertitik dirapatkan menjadi 18 digit, tanggal `dd-mm-yyyy` diubah
ke ISO (`yyyy-mm-dd`) karena importer membuang format lain dan akan mengisi
tanggal hari ini untuk baris lunas, status `Terbayar`/`Belum` dibiarkan dan
keputusan Lunas diambil importer dari `terbayar >= ketetapan`, kolom `sisa`
tidak diimpor (dihitung database).

Hasil: **3.794 objek pajak + 3.794 SPPT tahun 2026, 0 baris dilewati**, semuanya
NOP 18 digit berawalan `3324040008`, dan semuanya sudah tertaut ke blok
(`taut-blok` melaporkan `sudah_benar=3.794`, `diisi=0`). Ringkasan uang:
ketetapan Rp216.117.829, terbayar Rp117.625.205, sisa Rp98.492.624; 2.182 lunas
(tanggal 15 Januari – 14 Oktober 2026) dan 1.612 belum bayar. Tabel
`pembayaran` tetap 0 karena laporan ini hanya memuat total per tahun, bukan
kuitansi; `peralihan`, `laporan` dan `pemegang` juga tetap 0.

Data pajak tidak bocor ke publik: `beranda.php` tidak memuat nama wajib pajak
(diuji dengan dua nama dari laporan, 0 kecocokan), `api/statistik.php` tetap
401, dan `api/bidang.php` menyamarkan `pemegang` menjadi `"tersimpan"` serta
mengosongkan `pbb`/`nop`.

### 3.29 Peta bidang: lapisan terpisah dan warna per blok

Halaman `peta.php` ditata ulang pada 1 November 2026. Sebelumnya 4.396
geometri digambar sekaligus dengan satu panggilan `L.geoJSON` per bidang,
semuanya berwarna merah putus-putus karena seluruh bidang berstatus
`perlu_verifikasi`. Akibatnya tiga hal: muatan awal ±2,6 MB, warna per mode
tidak pernah terlihat, dan persil tanah tenggelam di antara jalan serta
saluran.

**Dua lapisan, bukan satu.** `api/bidang.php` sudah memisahkan `tipe='tanah'`
dan `tipe='lain'`, jadi halaman kini memanggilnya dua kali dan menyimpan
hasilnya di `dataLapisan.tanah` / `dataLapisan.lain`. Obyek non-tanah
(3.341 dari 4.396 geometri; jalan, saluran, anotasi peta) bawaannya mati.
Muatan awal turun menjadi **622 KB untuk 4.396 bidang**; chip "Obyek
non-tanah" tidak lagi bermakna karena seluruh obyek kini bertipe `tanah`.
Tiap lapisan memakai satu
`L.geoJSON` dengan `onEachFeature`, bukan satu per bidang.

**Urutan gambar memakai pane**, bukan urutan `addLayer`: jalan 402, nama
sungai 403, batas desa 405, blok 410, obyek non-tanah 415, bidang tanah 420,
titik laporan 430. Pane batas desa
diberi `pointer-events: none` supaya garis keliling tidak menghalangi klik
bidang di bawahnya.

**Warna bawaan per blok.** Rona dihitung dari nomor blok dengan sudut emas
(`hue = (nomor × 137,508°) mod 360`) sehingga 25 blok tersebar di roda warna
tanpa dua blok bersebelahan mendapat warna hampir sama. Pilihan mode:
`blok` (bawaan), `tipe`, `hak`, `pbb`, `status`. Status `perlu_verifikasi`
tidak lagi memaksa merah di semua mode — pada mode status ia berwarna kuning
tua, dan legenda menuliskan jumlah tiap kategori dari fitur yang benar-benar
tampil.

Halaman **`peta-blok.php`** menampilkan khusus batas semua blok. Klik blok
untuk melihat status batas dan estimasi jumlah bidang; batas perkiraan memakai
garis putus-putus. Halaman peta bidang menyediakan tautan ke peta blok.

**Batas blok dan label.** Garis blok diukur tampil hijau tua solid, blok
perkiraan tampil cokelat putus-putus; nama blok dipasang sebagai `divIcon`
di titik tengah dan hanya terlihat pada zoom ≥ 14, bisa dimatikan lewat
"Tampilkan nama blok". Label kode bidang ditolak bila tampilan memuat lebih
dari 1.500 bidang (pesan muncul dan centang dibatalkan), supaya kanvas tidak
dipenuhi teks.

**Kesalahan lama yang ikut diperbaiki.** `L.circleMarker(f.geometry.coordinates.reverse(), …)`
pada lapisan laporan membalik array koordinat asli; sekarang dibaca dengan
indeks `[lat, lon]` tanpa mengubah data. Saringan juga selalu memuat ulang
lapisan yang tanda tangan filternya berubah (`kunciFilter`), jadi mengganti blok,
dusun, hak, atau status tidak menyisakan geometri lama.

**Batas data yang perlu diketahui.** Saat ditata, `objek_pajak.bidang_id` masih
NULL untuk seluruh 3.794 NOP, jadi mode warna `pbb` menampilkan semua bidang
sebagai "Tanpa SPPT". Sejak 128 NOP ditautkan pada 1 November 2026 (bagian
3.30) dan 48 lagi pada 2 November 2026 (bagian 3.33), mode itu mewarnai **176
bidang** dan sisanya tetap "Tanpa SPPT"; legenda
menghitung dari fitur yang tampil, tidak mengarang warna. Selain itu `dusun`
kosong pada semua bidang, sehingga baris "Dusun" di popup tampil "—".

**Uji yang sudah dilakukan** (sesi login pengelola): 4.396 bidang dan
25 label blok tampil saat muat; kelima mode warna dan legendanya
berganti sesuai hitungan; saringan BLOK 2 menyisakan 106 bidang tanah;
"Atur ulang" mengembalikan setelan bawaan; peta satelit memuat ubin Esri;
popup bidang menampilkan pemegang, luas, NIB, blok, dan tautan detail; tidak
ada galat di konsol. Tangkapan layar tidak bisa diambil karena peramban
dalam aplikasi berjalan tanpa permukaan tampak, jadi warna dan kerapatan
label belum diverifikasi secara visual — perlu dicek sekali di Chrome biasa.

Setelan peramban memakai kunci baru `sip.peta.pengaturan.v2`; setelan lama
(`v1`) sengaja diabaikan agar pengguna tidak mewarisi mode `bidang/blok/batas`
yang sudah tidak ada.

### 3.29a Peta bidang dibuka pada tampilan seluruh desa (7 Nov 2026)

Sebelumnya peta dibuka pada pusat/zoom bawaan (`peta_zoom_awal`, zoom 15), lalu
"ditimpa" oleh batas bidang yang termuat; hasil akhirnya bergantung pada
urutan muat blok dan bidang sehingga kadang hanya sebagian desa yang tampak.

Kini batas desa dihitung sekali di server dari `blok.geom` (min/max koordinat
GeoJSON semua blok) dan dikirim ke halaman lewat `sip_footer(['data' =>
['desa' => …]])`. Skrip peta menjalankan `fitBounds` atas batas itu begitu peta
dibuat, jadi halaman langsung terbuka pada seluruh desa tanpa menunggu API blok
maupun bidang. `batasSeluruhDesa()`/`pasangkanSeluruhDesa()` tetap dipakai
tombol "Seluruh desa" dan "Atur ulang"; saringan yang diterapkan tetap
memperbesar ke hasilnya.

Pusat/zoom yang disimpan tombol "Simpan tampilan peta" **tidak lagi dipulihkan
otomatis** saat halaman dibuka — pembukaan selalu seluruh desa. Nilai tersimpan
itu hanya dipakai bila preset dimuat (aksi eksplisit).

Verifikasi DOM (Chrome, jendela 429×440): setelah muat ulang berulang, data
`SIP.data.desa` berisi `[110.05517154, -7.0987192, 110.08537448, -7.07381737]`,
24/24 label blok berada di dalam peta dan skala menunjukkan 1 km; saringan
BLOK 2 mengecil ke skala 200 m; tombol "Seluruh desa" mengembalikan 24/24.

### 3.30 Tautan NOP PBB ke bidang: 128 pasangan nama persis

`tools/impor.php calon <blok>` sudah menyusun calon NOP seblok untuk tiap
bidang tanpa tautan, tetapi keputusan menautkan tetap milik petugas. Pada
1 November 2026 seluruh 25 blok dipindai sekali untuk melihat berapa yang bisa
dipastikan secara otomatis: dari **1.050 bidang tanah belum tertaut**, **273**
punya tepat satu kandidat, 102 dua kandidat, 176 tiga kandidat atau lebih, dan
499 tidak punya kandidat nama sama sekali. Dari 273 itu diambil yang paling
kuat saja — nama pemegang dan nama wajib pajak sama persis (skor 3) **dan** NOP
itu tidak menjadi kandidat tunggal bidang lain — hasilnya **128 pasangan**,
tersebar di 23 blok (terbanyak BLOK 3 = 14). 65 pasangan "nama termuat" (skor
2), 20 "satu unsur" (skor 1), dan 28 NOP yang menjadi kandidat tunggal 2–3
bidang (nama kembar) sengaja dibiarkan untuk ditinjau petugas.

Validasi luas belum bisa dipakai: laporan Daftar Penerimaan PBB tidak memuat
kolom luas bumi, jadi `objek_pajak.luas_bumi_m2` NULL untuk seluruh 3.794 NOP.
Pencocokan hanya lewat nama di blok yang sama — NOP digit 12–14 memuat nomor
blok, dan `calonTautanBlok()` hanya mengambil objek pajak seblok.

Hasil: **128 NOP tertaut, satu bidang satu NOP** (0 bidang dipakai dua NOP, 0
menunjuk bidang non-tanah atau nonaktif), `audit_log` bertambah 128 baris.
SPPT 2026 pada bidang yang tertaut: 80 Lunas dan 48 Belum Bayar, sehingga mode
warna **PBB** di peta kini benar-benar membedakan status. Cadangan penuh
`storage/cadangan/sebelum-taut-nop-20261101-110134-51aa.sql` (5.024.095 byte,
17 tabel), ringkasan 128 pasangan di
`storage/taut-nop-ringkas-20261101-110225.json`, dan satu jejak per tautan
`storage/taut-nop-20261101-110215-*.json` (128 berkas).

Sisa **879 bidang tanah** belum tertaut (927 sebelum 48 tautan pada 2 November
2026, bagian 3.33); 499 di antaranya tidak punya kandidat
nama, jadi harus dicocokkan petugas lewat layar bidang (kartu "Calon NOP")
atau `php tools/impor.php taut <kode-bidang> <nop> --terapkan`. Jangan
menjalankan penautan massal untuk skor di bawah 3: contoh nyatanya
"BENI SETIAWAN" sempat tercocokkan dengan NOP "BUDI SETIAWAN".

### 3.31 Tiga belas bidang tanpa blok dipasang, sembilan ditunda

Dua puluh dua bidang yang tersisa tanpa blok sejak 31 Oktober diperiksa ulang
pada 2 November 2026 dengan tiga bukti: luas irisan dengan tiap blok kandidat,
jarak garis bidang ke blok terdekat, dan blok tempat NOP bernama persis atau
bidang senama berada. Aturannya: pilih blok dengan irisan terbesar asalkan blok
itu juga terdekat dari titik tengah atau ditopang NOP/bidang senama; bila
bidang hanya menempel satu blok, blok itu dipakai kalau sama dengan blok
terdekat. **Tiga belas bidang dipasang**, sembilan ditunda karena buktinya
bertentangan.

| Blok | Bidang |
|------|--------|
| BLOK 8 | `DXF-020100-3230`, `DXF-020100-3249`, `DXF-BIDANGHM-0984` |
| BLOK 17 | `DXF-020100-3278` |
| BLOK 19 | `DXF-BIDANGHM-0981` |
| BLOK 20 | `DXF-020100-3186` |
| BLOK 21 | `DXF-020100-3187`, `DXF-020100-3203`, `DXF-BIDANGHM-0980` |
| BLOK 23 | `DXF-020100-3235`, `DXF-020100-3247`, `DXF-020100-3253`, `DXF-BIDANGHM-0985` |

Hasil: **9 bidang tanpa blok** (dari 22), total tetap 4.397 baris (4.387 aktif
ber-blok + 9 tanpa blok + 1 berstatus duplikat), `audit_log` bertambah 13.
Cadangan penuh `storage/cadangan/sebelum-blok-22-20261102-061528-5b3c.sql`
(5.087.542 byte, 17 tabel) dan jejak `ubah-blok` per bidang di `storage/`.

Sembilan yang **sengaja belum dipasang**, semuanya menunggu keputusan petugas:
`DXF-020100-3176` (terisolasi, 724 m dari blok terdekat), `3177` (menempel
BLOK 17 tetapi BLOK 25 hanya 3,4 m), `3181` (menempel 17, terdekat 18, tiga
bidang senama di 25), `3213` (menempel 3 dan 8; NOP persis serta lima bidang
senama di 8), `3242` (menempel 21, terdekat 23), `3275` (tumpang BLOK 8 hanya
4 m², NOP persis di BLOK 9), `3295` (menempel 21 dan 23, NOP persis di 23),
`3308` (menempel 19 dan 23, tanpa NOP/senama), dan `DXF-BIDANGHM-0987` (tidak
menempel blok mana pun, 8,3 m dari BLOK 21 dan 22).

### 3.32 Nama pemegang: lima bidang terbantu perbaikan saringan label

Dari 363 bidang tanpa nama, **326 adalah objek non-tanah** (`tipe = 'lain'`:
jalan, saluran, anotasi peta) yang memang tidak punya pemegang, dan hanya **37
bidang tanah**. Aturan label peta sudah habis (`akan_diisi = 0`), sedangkan NIS
dan NIB tidak menolong: dari 37 bidang itu hanya 2 punya NIS dan tidak ada satu
pun yang cocok dengan bidang bernama.

**Penyebabnya saringan awal yang salah acuan.** `namaTerdekat()` menyaring label
dengan kotak ±10 m di sekitar **titik tengah** bidang, padahal jarak 10 m
diukur dari **tepi poligon**. Pada bidang besar kedua ukuran itu jauh berbeda:
`DXF-BIDANGHM-0011` (5.389 m²) punya label ERIK 5,8 m dari tepinya tetapi 51 m
dari pusatnya, jadi label itu tidak pernah diperiksa. Sekarang saringan memakai
kotak bidang (`ST_Envelope`) yang dilebarkan 10 m. Aturan pesaing juga diberi
`NAMA_MARGIN_PESAING_M = 1,0` m: bidang lain yang jaraknya kurang dari 1 m dari
label dianggap sama berhak, karena konversi DXF ke WGS84 sendiri punya RMS
1,2 m. Periksa kering sebelum perbaikan: 0 bidang; sesudahnya: **5 bidang**.

Lima bidang yang diisi, semuanya lewat aturan `terdekat`:

| Bidang | Blok | Nama | Label (m) | Bidang lain terdekat (m) |
|--------|------|------|-----------|--------------------------|
| `DXF-BIDANGHM-0381` | 16 | ROHATI | 8,6 | 19,8 |
| `DXF-BIDANGHM-0536` | 21 | KUSBARYONO | 7,2 | 18,1 |
| `DXF-BIDANGHM-0749` | 20 | DWIYANTO | 4,9 | 7,0 |
| `DXF-BIDANGHM-0791` | 8 | KARTONO | 6,7 | 13,3 |
| `DXF-BIDANGHM-0792` | 20 | BUTUK S | 9,9 | 19,6 |

Hasil: bidang tanah bernama **1.018 → 1.023** dari 1.055, yang tanpa nama
**37 → 32** (total tanpa nama 363 → 358). Tidak ada nama lama yang ditimpa
(`berebut = 0`), tidak ada label yang diambil dari bidang bernama, dan perintah
kini idempoten (`akan_diisi = 0` bila diulang). `audit_log` bertambah satu baris
(#254). Cadangan penuh `storage/cadangan/sebelum-nama-5-20261102-062032-4166.sql`
(5.091.291 byte, 17 tabel) dan jejak `storage/nama-pemegang-20261102-062042.json`.

**32 bidang sisanya menunggu pemeriksaan lapangan**, bukan diisi otomatis:
20 bidang tidak punya label bebas dalam 10 m sama sekali; 11 bidang punya label
≤10 m tetapi bidang lain jelas lebih dekat (mis. `0880` PAINEM 7,2 m vs `0370`
4,3 m); `0794` beda jarak hanya 0,01 m dengan `0814` sehingga masuk margin; dan
`0011` justru punya **dua** label tanpa pesaing (ERIK 5,8 m dan SLAMET KATENG
7,0 m) sehingga harus ditanyakan mana pemegangnya, atau apakah bidang itu
memang tanah bersama. Daftarnya, lengkap dengan koordinat, luas, NIS/NIB,
jumlah NOP belum tertaut di bloknya, label terdekat, dan tautan peta:
`storage/nama-verifikasi-lapangan-20261102.csv` (32 baris) serta versi JSON
`storage/nama-verifikasi-lapangan-20261102.json`.

### 3.33 Tautan NOP gelombang kedua: 48 pasangan 1:1

Setelah gelombang pertama (bagian 3.30), 3.666 NOP masih belum tertaut dan 927
bidang tanah belum punya NOP. Uji ulang memakai aturan yang lebih ketat dari
gelombang pertama, seluruhnya harus terpenuhi:

- `nama_wp` sama persis dengan `hak.pemegang_nama` (huruf besar, spasi tepi
  dibuang);
- NOP dan bidang berada di **blok yang sama** (`objek_pajak.blok_id` =
  `bidang.blok_id`);
- bidang itu satu-satunya pemilik nama tersebut **di blok itu** dan **di
  seluruh desa**;
- bidang hanya punya **satu** kandidat NOP (1:1).

Saringan bertahap: 3.666 NOP → 435 nama unik global → 144 kandidat blok sama →
87 bidang → **48 bidang 1:1**. Sebagai validasi, dari 128 tautan lama 126 memang
bernama sama (dua sisanya beda sejak gelombang pertama, sengaja dibiarkan).

Hasil: `objek_pajak.bidang_id` terisi untuk **48 NOP baru**, total 128 → **176**
NOP tertaut, 176 bidang, **0 bidang dengan lebih dari satu NOP**, dan ke-48
tautan baru cocok nama sekaligus blok. Bidang tanah tanpa NOP **927 → 879**,
`audit_log` bertambah 48 baris (`aksi = ubah`, `objek = objek_pajak`). Cadangan
penuh `storage/cadangan/sebelum-taut-nop-48-20261102-065052-853b.sql`
(5.091.904 byte, 17 tabel) plus satu jejak JSON per NOP di `storage/`.

**39 bidang dengan 2–3 NOP (96 NOP) sengaja ditinggalkan** — kemungkinan NOP
ganda atau tanah yang terpecah, sehingga tidak boleh dipilih otomatis. Sisa
3.618 NOP tetap menunggu pencocokan petugas.

### 3.34 Semua obyek non-tanah dijadikan bidang tanah

Atas permintaan pengguna, seluruh bidang bertipe `lain` (jalan, saluran,
anotasi peta, dan obyek non-tanah lain) diubah menjadi `tipe='tanah'`.
Satu baris bertipe `lain` berstatus `dihapus` dibiarkan apa adanya.

Hasil: bidang tanah **1.055 → 4.396**, obyek non-tanah **3.341 → 0**
(hanya 1 `lain/dihapus` tersisa). Bidang tanpa nama **32 → 358** (karena
jalan/saluran/anotasi tidak punya pemegang), sehingga cakupan nama turun
dari 97% ke 23%. NOP tertaut tetap 176, jadi cakupannya kini 4%. Toggle
"Obyek non-tanah dimatikan" di peta tidak lagi bermakna karena lapisannya
kosong.

Cadangan `storage/cadangan/sebelum-lain-ke-tanah-20261102-065555-9d46.sql`
(5.110.392 byte, 17 tabel), satu baris `audit_log` (`aksi=ubah`, `objek=bidang`,
`objek_id='tipe'`).

**Catatan 7 Nov 2026.** Pernyataan "obyek non-tanah **3.341 → 0**" di atas tidak
lagi menggambarkan isi basis data: live kini memuat **4.318 baris `lain`**
(`dibuat_pada` 19 Okt 2.352, 20 Okt 384, 5 Nov 1.582, 6 Nov 1). Satu di antaranya
poligon batas blok yang sempat ikut bertipe `tanah`; koreksinya ada di §3.37.

### 3.35 Lapisan jalan dan nama sungai di peta (7 Nov 2026)

Petugas meminta peta menampilkan jalan dan sungai. MASTER.dxf tidak menyimpan
bentuk keduanya: lapisan `BATAS JALAN` berisi 620 label teks (626 titik) dan
`BATAS PERAIRAN` 279 label teks, tanpa satu pun garis. Jadi sumbernya dipisah:

- **Jalan** memakai unduhan Overpass yang sudah dipakai untuk memotong batas
  blok, `files/master peta/jalan_osm.json` (`way["highway"]`, 376 ruas, 5.626
  titik). `api/bidang.php?lapisan=jalan` mengubahnya menjadi `LineString`
  dengan atribut `kelas` dan `nama`; tebal garis mengikuti kelas (primary
  3,4 px sampai track 1,2 px).
- **Sungai** tetap dari label DXF
  `files/sig-pertanahan-gedong-lokal/data/dxf-layers/batas-perairan.geojson`,
  diterjemahkan ke WGS84 dengan `dxfKeWgs84()`. Dua label `SALURAN` dibuang
  karena koordinatnya milik sistem lain (x 526.789, y 1.431.976 — jauh di luar
  extent MASTER) dan dua label `JALAN` dibuang karena salah lapisan. Sisanya
  **275 titik**: 112 sungai (label `SUNGAI`/`KALI`) dan 163 saluran (termasuk
  salah tulis `SLURAN`). **Tidak ada garis tepi air** — yang tampil titik nama;
  nama lengkap muncul sebagai tooltip dan label permanen baru aktif pada
  zoom ≥ 16 supaya tidak bertumpuk.

Keduanya tampil di `peta.php` dan `peta-blok.php` lewat chip "Jalan" dan
"Sungai & saluran", bawaannya hidup, dan pilihannya tersimpan di peramban.
Pane-nya 402 dan 403, di bawah batas desa (405), blok (410), dan bidang (420)
supaya klik persil tidak terhalang.

**Bug lama ikut diperbaiki.** `gayaBidang` dipasang langsung sebagai `style`
pada `L.geoJSON`, padahal Leaflet 1.9.4 mengirim objek *feature*, bukan
`properties`. Akibatnya seluruh bidang tampil abu-abu `#9aa8a2` sampai kursor
melewatinya, walaupun legenda menjanjikan warna per blok. Kini
`style: (f) => gayaBidang(f.properties)`; di peramban, muatan awal menunjukkan
25 rona blok (sebelumnya 1) dan lebar jalan beragam 1,2–3,4 px.

Tidak ada perubahan basis data. Bila `jalan_osm.json` belum ada, endpoint
mengembalikan 404 dengan pesan agar berkas diunduh dari Overpass.

Verifikasi: `lapisan=jalan` 376 fitur, bbox 110,0391–110,1044 / −7,1188…−7,0595
sesuai berkas sumber; `lapisan=sungai` 275 fitur, bbox 110,0572–110,0807 /
−7,0988…−7,0772 (di dalam desa). Chip menampilkan angka yang sama; mematikan
chip mengeluarkan grup dari peta dan menyalakannya lagi memasukkannya kembali
tanpa memuat ulang data. Tangkapan layar tidak bisa diambil karena permukaan
peramban dalam aplikasi sedang tersembunyi, jadi pemeriksaan dilakukan lewat
DOM (jumlah `path` tiap pane, atribut `stroke`, warna isian, kelas zoom).

### 3.35a Jalan dipotong di tepi bidang, sungai disambung jadi garis (7 Nov 2026)

Dua keluhan lanjutan atas §3.35: garis jalan menembus bidang, dan "sungai"
hanya berupa titik nama. Keduanya dirapikan helper baru `app/peta-rapi.php`
yang hanya membaca basis data; `api/bidang.php` memanggilnya untuk lapisan
`jalan` dan `sungai`.

**Jalan dipotong di tepi bidang.** Setiap ruas OSM ditelusuri tiap 0,75 m;
potongan yang titik tengahnya di dalam bidang dibuang, sisanya disambung,
disederhanakan Douglas–Peucker 1,2 m, dan serpihan di bawah 6 m dibuang supaya
tidak menjadi bintik. Indeks bidang memakai grid 0,002° dan ray casting, lubang
poligon ikut dihitung. Hasil: 376 ruas 102,95 km menjadi **446 potongan
89,79 km** — jalan hanya tampil di sela dan tepi bidang. Atribut `id`,
`kelas`, `nama`, dan `bagian` tetap dikirim, jadi tooltip nama/kelas tidak
berubah.

**Sungai disambung jadi garis.** 275 label DXF dirangkai: dua tetangga terdekat
dalam 140 m, pohon rentang minimum Kruskal, lalu jalur terpanjang (diameter
pohon) sebagai urutan garis. Bila garis lurus antar label memotong bidang,
rutenya dicari dengan A* pada grid 2 m (margin 80 m, jalan memutar maksimal 3×
garis lurus + 40 m). Sesudah dihaluskan (Laplacian dua putaran; titik yang
masuk bidang ditolak), potongan yang masih menembus bidang dicari ulang. Hasil:
**11 garis (197 label, 10,25 km) + 6 titik lepas**. Sisa 19,6% sampel garis
masih di dalam bidang karena 14 label (5,1%) memang berada di dalam bidang dan
tidak ada geometri air sebagai acuan; garis tetap diarahkan lewat sela bidang.
Nama hanya dipakai bila ada label yang bukan kata umum (`SALURAN`, `SLURAN`,
`SUNGAI`, `KALI`, …): kini "KALI SARI" dan "KALI BLUKAR". Tooltip menampilkan
nama, jenis, dan panjang; label permanen aktif pada zoom ≥ 16.

**Simpanan sementara.** Hasil mahal disimpan di `storage/cache/`
(`peta-jalan-rapi.json`, `peta-sungai-rapi.json`) dengan kunci
`v3-<md5(jumlah|max(diubah_pada)|max(id) bidang)>` (sungai menambah mtime
berkas label); perubahan bidang atau naiknya `PETA_RAPI_VERSI` memaksa hitung
ulang. Terukur: sungai dari cache 0,045 dtk, jalan hitung segar 0,83 dtk.
Berkas cache tidak ikut git.

`muatSungai` di `peta.php` dan `peta-blok.php` kini menggambar `LineString`
(#087b83, 1,6 px) dan tetap menampilkan titik lepas; chip "Sungai & saluran"
menghitung 17 fitur. Verifikasi DOM: pane 402 berisi 446 `path`, pane 403
berisi 17 `path` + 2 label "KALI SARI"; pada zoom 16 kelas
`peta-dekat-sungai` aktif dan kedua label tampil. Tidak ada perubahan basis
data.

### 3.36 API preset peta: tabel `preset_peta` dipasang (7 Nov 2026)

Keluhan "galat `api/preset.php` 500" ternyata bukan kesalahan kode: tabel
`preset_peta` yang dipakai endpoint itu tidak pernah ada — baik di basis data
`sipetades_gedong` maupun di `sql/01_skema.sql` — sehingga setiap GET dan POST
berakhir di blok `catch` dan dijawab 500. Tabel kini dibuat lewat migrasi
idempoten `sql/03_preset_peta.sql` dan ikut didefinisikan di `sql/01_skema.sql`
(kini **18 tabel**).

Kolom: `id`, `nama` varchar(120), `data_json` json, `dibuat_oleh` (FK ke
`pengguna`, `ON DELETE SET NULL`), `dibuat_pada`, `diubah_pada`. Tidak ada data
peta yang berubah; satu-satunya perubahan data adalah tabel baru yang kosong.

Sebelumnya basis data dicadangkan penuh ke
`D:\backup\sigpetades\preset-peta-20261107\sipetades_gedong-sebelum-preset-20261107.sql`
(10,8 MB; SHA-256 `c9b1c61bab27b6dfa490b0752ad0099585bc4a6e0b621f763ef63a0995255211`,
tercatat juga di `SHA256.txt`). Uji restore ke database sementara
`sipetades_gedong_uji_20261107` cocok: 21 objek (17 tabel + 4 view) dengan
jumlah baris tabel kunci identik; database uji sudah dihapus.

Verifikasi browser (sesi masuk): `GET api/preset.php` menjawab 200
`{"preset":[]}`; "Simpan ke database" menulis satu baris (pembuat id 1);
muat ulang halaman menampilkan preset pada daftar; "Muat" menjawab
`Preset "Uji preset 7 Nov" dimuat.`; "Hapus" mengosongkan tabel dan daftar
kembali ke `— pilih preset —`. Tidak ada pesan galat di konsol. POST tanpa sesi
tetap 401 seperti mestinya. Baris uji sudah dihapus, tabel kembali kosong.

### 3.37 DXF-BATAS-BLOK-00050: batas blok, bukan bidang tanah (7 Nov 2026)

Satu baris `bidang` hasil impor layer peta induk `BATAS BLOK` — id **22968**,
`DXF-BATAS-BLOK-00050`, 124.589,12 m² — bertipe `tanah`, jadi ikut terhitung
sebagai bidang tanah meski geometrinya adalah garis luar **BLOK 17**: hanya pusat
BLOK 17 (blok id 21) yang jatuh di dalamnya, `blok_id` NULL, dan di dalamnya
berdiri 91 bidang tanah serta 107 obyek non-tanah.

Kolom `status` tidak punya nilai "batas blok" — enum-nya hanya `aktif`,
`perlu_verifikasi`, `duplikat`, `dihapus` — sedangkan pembeda tanah/non-tanah ada
di kolom `tipe` (`tanah`/`lain`). Koreksi karena itu dilakukan di `tipe`:
`tanah` → `lain`, `status` tetap `perlu_verifikasi`, dan `catatan` diisi
"Batas blok (BLOK 17), bukan bidang tanah; tipe dikoreksi dari tanah menjadi lain
pada 7 Nov 2026." `blok_id` sengaja tetap NULL supaya poligon batas tidak dihitung
sebagai anggota blok, dan baris `hak` id 27601 (jenis BLM, tanpa pemegang) tidak
disentuh.

Hasil: bidang tanah di peta **3.783 → 3.782**, obyek non-tanah **4.318 → 4.319**;
rinciannya `tanah/perlu_verifikasi` 536 → 535 dan `lain/perlu_verifikasi`
4.317 → 4.318. Jumlah baris `bidang` tetap 8.103, luas dan geometri tidak berubah
(MD5 `ST_AsBinary` = `ed54c82efe835747a9c0cb2222226879` sebelum dan sesudah).
`GET api/bidang.php?cari=DXF-BATAS-BLOK-00050` menjawab 200 dengan `tipe` `lain`.
Satu baris `audit_log` baru: id 2989, `aksi=ubah`, `objek=bidang`,
`objek_id='22968'`.

Cadangan penuh sebelum menulis:
`D:\backup\sigpetades\batas-blok-22968-20261107\sipetades_gedong-sebelum-batas-blok-20261107.sql`
(10.812.689 byte; SHA-256 `b797e847c9fa24724347f4142c6c1909c91ae52dac504fede3366bbfa8fea1e4`,
tercatat di `SHA256.txt`). Uji restore ke database sementara
`sipetades_restore_20261107` cocok: 18 tabel dengan jumlah baris identik dan MD5
geometri 22968 sama; database uji sudah dihapus.

### 3.38 Rongga BLOK 2 diisi 28 bidang dari objek non-tanah (7 Nov 2026)

Atas permintaan pengguna, rongga BLOK 2 (blok id 10) diukur lalu diisi dari
lapisan non-tanah. Luas blok ±256.425 m²; di dalamnya ada 262 bidang tanah
(248.481,63 m² kotor, 27 pasang saling tumpang ±31.918 m² sehingga cakupan unik
±213.000 m²). Sampel grid 5 m (10.099 titik di dalam blok) menemukan **1.710
titik kosong ≈43.300 m² (16,9%)** yang tidak tercakup bidang tanah mana pun.

Lapisan `lain` yang berpusat di blok berisi 141 objek (95.417,89 m²): **103 objek
(69.752 m²)** tertutup ≥95% oleh bidang tanah yang sudah ada (duplikat lapisan),
**38 objek (25.666 m²)** mengisi rongga, mengisi ±18.300 m² dari rongga itu.
Kedelapan-puluh delapan objek tersebut semuanya ber-`hak` (HM), 25 bernama,
0 NOP/SPPT. Sepuluh di antaranya berpasangan **persis** (`ST_Equals=1`, jarak
pusat 0,00 m) dengan baris BLM tanpa nama hasil impor 5 Nov 2026.

Yang diterapkan: **28 baris HM** (17 objek unik bernama, 10 baris HM berpasangan,
dan 1 baris HM tanpa nama ber-nomor daftar `01972`) diubah `tipe='lain'` →
`'tanah'`, `blok_id` → 10 (id 10057 semula NULL), `status` tetap
`perlu_verifikasi`, dan `catatan` ditambah "Dikonversi dari non-tanah ke bidang
tanah BLOK 2 pada 7 Nov 2026." Sepuluh baris BLM kembar **tidak disentuh** —
mengubah keduanya akan membuat bidang tanah ganda. Id yang dikonversi:
5967, 5970, 5978, 5983, 5984, 5990, 5992, 5993, 5997, 6004, 6344, 7974, 7982,
7997, 8043, 8044, 8045, 8433, 8438, 8439, 8442, 8446, 8448, 8449, 8462, 8463,
8464, 10057 (total 18.239,67 m²). Tidak ada geometri, luas, hak, NOP, atau SPPT
yang berubah.

Hasil: bidang tanah BLOK 2 **262 → 290**, non-tanah **138 → 111**; total live
bidang tanah **3.782 → 3.810** dan non-tanah **4.319 → 4.291**
(`tanah/perlu_verifikasi` 535 → 563, `lain/perlu_verifikasi` 4.318 → 4.290).
Audit: 28 baris `audit_log` (id 2990–3017), satu per bidang.
`GET api/bidang.php?cari=DXF-020100-2530` menjawab 200 dengan `tipe` `tanah`,
`blok` `BLOK 2`.

Cadangan penuh sebelum menulis:
`D:\backup\sigpetades\konversi-blok2-20261107\sipetades_gedong-sebelum-konversi-blok2-20261107.sql`
(10.813.168 byte; SHA-256 `fc8c5ae2b11abe5e2f30d25648509d18a0af41993525cb7a56e56492c9f8ab28`,
tercatat di `SHA256.txt`). Uji restore ke `sipetades_restore_20261107b` cocok:
18 tabel dengan jumlah baris identik dan MD5 geometri 5970/8044/22968 sama;
database uji sudah dihapus. Sisa 10 baris BLM kembar (7.426 m²) masih `lain` dan
menunggu keputusan terpisah; id 7511 (`DXF-020100-1997`, 55 m²) ber-`blok_id=6`
padahal pusatnya di BLOK 2 juga belum diputuskan.

### 3.39 Sepuluh bidang BLM kembar ikut dikonversi ke BLOK 2 (7 Nov 2026)

Atas permintaan pengguna, sepuluh baris BLM tanpa nama yang **persis** menimpa
(`ST_Equals=1`, jarak pusat 0,00 m) sepuluh baris HM hasil §3.38 juga diubah
`tipe='lain'` → `'tanah'`. `blok_id` sudah 10, `status` tetap `perlu_verifikasi`,
dan `catatan` ditambah "...; geometri identik dengan bidang HM pasangannya."
Id: 11804, 11801, 11811, 11816, 11825, 11822, 11820, 11830, 12089, 13106
(total 7.426,00 m²). Tidak ada geometri, luas, hak, NOP, atau SPPT yang berubah.

**Konsekuensi yang diterima:** BLOK 2 kini memuat **10 pasang bidang tanah
tumpang tindih persis** (HM bernama + BLM tanpa nama). Pasangan itu tetap
`ST_Equals=1` dan terhitung dua kali dalam luas kotor, sehingga 300 bidang tanah
BLOK 2 (25.665,67 m²) sama dengan **290 poligon unik**. Keduanya masih
`perlu_verifikasi` karena keputusan mana yang dipertahankan (tandai `duplikat`
atau hapus salah satu) belum diambil.

Hasil: bidang tanah BLOK 2 **290 → 300**, non-tanah **111 → 101** (69.109,13 m²);
total live bidang tanah **3.810 → 3.820** dan non-tanah **4.291 → 4.281**
(`tanah/aktif` 3.247, `tanah/perlu_verifikasi` 573, `lain/aktif` 1,
`lain/perlu_verifikasi` 4.280). Audit: 10 baris `audit_log`
(id 3021–3030), satu per bidang.
`GET api/bidang.php?cari=DXF-020100-0456` menjawab 200 dengan `tipe` `tanah`,
`blok` `BLOK 2`, `hak` `BLM`.

Cadangan penuh sebelum menulis:
`D:\backup\sigpetades\konversi-blm-blok2-20261107\sipetades_gedong-sebelum-konversi-blm-20261107.sql`
(10.832.784 byte; SHA-256 `544e4c8fe681feb4cf3440923e0f46ff1d87e621940a7b58a7edc0ab5e056046`,
tercatat di `SHA256.txt`). Uji restore ke `sipetades_restore_20261107c` cocok:
18 tabel dengan jumlah baris identik dan MD5 geometri 11804/13106/22968 sama;
database uji sudah dihapus.

Belum diputuskan: cara menyelesaikan 10 pasang duplikat persis di atas; id 7511
(`DXF-020100-1997`, 55 m²) yang ber-`blok_id=6` padahal pusatnya di BLOK 2; dan
±25.000 m² rongga BLOK 2 yang tidak punya poligon `lain` sebagai sumber.

### 3.40 Rongga BLOK 3 diisi 38 bidang dari objek non-tanah (7 Nov 2026)

Atas permintaan pengguna, rongga BLOK 3 (blok id 18, 188.262,91 m²) diukur dengan
cara yang sama seperti BLOK 2 lalu diisi dari lapisan non-tanah. BLOK 3 memuat 250
bidang tanah terpasang (139 `aktif` + 111 `perlu_verifikasi`) seluas 161.340,76 m²
kotor; 43 pasang saling tumpang ±11.850,24 m² sehingga cakupan unik ±149.491 m²
(79,4%). Sampel grid 5 m (7.524 titik di dalam blok) menemukan **1.502 titik kosong
≈37.550 m² (19,9%)**; 1.170 titik (≈29.250 m²) di antaranya sudah tertutup objek
non-tanah, 332 titik (≈8.300 m²) tidak punya poligon sumber.

Dari 242 objek `lain` yang berpusat di blok, **175 (≈124.164 m²) duplikat ≥95%**
bidang tanah yang sudah ada dan 67 berada di rongga. Dua objek di rongga
(6413 `HM-63B`, 12136 `DXF-020100-0899`) ternyata 99,8% berada di BLOK 5 sehingga
tidak dihitung. Dari 62 kandidat sisanya, **24 adalah kembar persis** baris BLM
tanpa nama (`ST_Equals=1`, MD5 geometri sama) dari 24 baris HM/KDH bernama,
sehingga tinggal **38 objek unik, 28.939,13 m²** (24 HM/KDH berpasangan + 14 HM
tunggal; 37 bernama + 1 tanpa nama; termasuk 1 KDH fasilitas umum 4.654,44 m²).
Ke-38 objek tidak saling tumpang dan tidak punya NOP/SPPT.

Yang diterapkan: **38 baris unik** diubah `tipe='lain'` → `'tanah'`; `blok_id`
tetap 18, `status` tetap `perlu_verifikasi`, dan `catatan` ditambah
"Dikonversi dari non-tanah ke bidang tanah BLOK 3 pada 7 Nov 2026." setelah
catatan lama "Blok diperkirakan otomatis, belum dicek lapangan." **24 baris BLM
kembar tidak disentuh** agar tidak lahir duplikat baru seperti di BLOK 2. Id yang
dikonversi: 5516, 5517, 5781, 5793, 6002, 6113, 6124, 6125, 6135, 6136, 6137,
6138, 6139, 6146, 6148, 6151, 6156, 6157, 6160, 6162, 6166, 6175, 6176, 6178,
6207, 6208, 7212, 7456, 7457, 7514, 7518, 7523, 7975, 7977, 7978, 7979, 8036,
8051. Tidak ada geometri, luas, hak, NOP, atau SPPT yang berubah
(MD5 geometri 6113 tetap `51cbdcef…`).

Hasil: bidang tanah BLOK 3 **250 → 288**, non-tanah **240 → 202**; total live
bidang tanah **3.820 → 3.858** (3.247 `aktif` + 611 `perlu_verifikasi`) dan
non-tanah **4.281 → 4.243** (1 `aktif` + 4.242 `perlu_verifikasi`); baris `bidang`
tetap 8.103 (termasuk 2 `tanah/dihapus`). Audit: 38 baris `audit_log`
(id 3036–3073), satu per bidang.
`GET api/bidang.php?cari=HM-370D` menjawab 200 dengan `tipe` `tanah`, `blok`
`BLOK 3`; pasangan BLM `DXF-020100-0599` tetap `tipe` `lain`.

Cadangan penuh sebelum menulis:
`D:\backup\sigpetades\konversi-blok3-20261107\sipetades_gedong-sebelum-konversi-blok3-20261107.sql`
(10.838.734 byte; SHA-256 `7f4cb16587e459068973538343be23ce547f3d96677cc4b9e41a85045b30514d`,
tercatat di `SHA256.txt`). Uji restore ke `sipetades_restore_20261107d` cocok:
22 objek (18 tabel + 4 view) dengan jumlah baris identik dan MD5 geometri
6113/5517/13106/22968 sama; database uji sudah dihapus.

Tindak lanjut: 24 baris BLM kembar kemudian ditandai `duplikat` (lihat §3.41).
Masih terbuka: 6413/12136 yang berpusat di BLOK 3 tetapi 99,8% di BLOK 5; dan
332 titik (≈8.300 m²) rongga BLOK 3 tanpa poligon sumber.

### 3.41 Dua puluh empat BLM kembar BLOK 3 ditandai duplikat (7 Nov 2026)

Atas permintaan pengguna, 24 baris BLM tanpa nama yang geometrinya identik dengan
24 bidang tanah hasil §3.40 diubah `status='perlu_verifikasi'` → `'duplikat'`.
`tipe` tetap `lain`, `blok_id` tetap 18, dan `catatan` ditambah "Ditandai
duplikat pada 7 Nov 2026; geometri identik dengan bidang tanah <kode>." Id:
11537, 11538, 11695, 11828, 11923, 11934, 11935, 11945, 11946, 11949, 11951,
11953, 11957, 11958, 11961, 11963, 11966, 11974, 11975, 11977, 12002, 12642,
12781, 12782 (20.462,96 m²). Ke-24 pasangan tetap `ST_Equals=1`; tidak ada
geometri, luas, hak, NOP, atau SPPT yang berubah.

Karena `bidang_filter()` di `app/data.php` menyembunyikan `duplikat` dan `dihapus`
secara default, ke-24 baris hilang dari peta, daftar, dan pencarian; tampil lagi
dengan `status=duplikat`. Hasil: non-tanah BLOK 3 **202 → 178
`perlu_verifikasi` + 24 `duplikat`**; global non-tanah **4.243 → 4.218
`perlu_verifikasi` + 24 `duplikat`** (`lain/aktif` tetap 1), bidang tanah tetap
3.858, total baris 8.103. Audit: 24 baris `audit_log` (id 3099–3122).

Uji API: `GET api/bidang.php?lapisan=bidang&blok=BLOK 3` tidak lagi memuat
id 11537; dengan `status=duplikat` id itu muncul. `cari=DXF-020100-0599` tanpa
filter menjawab `jumlah: 0`, dengan `status=duplikat` menjawab `jumlah: 1`
(`status` `duplikat`).

Cadangan penuh sebelum menulis:
`D:\backup\sigpetades\duplikat-blm-blok3-20261107\sipetades_gedong-sebelum-duplikat-blm-20261107.sql`
(10.859.597 byte; SHA-256 `a14f77d525175def7338bef0b3babc0444609e6c2dc0e98a254becd9c14e1e28`,
tercatat di `SHA256.txt`). Uji restore ke `sipetades_restore_20261107e` cocok:
22 objek (18 tabel + 4 view) dengan jumlah baris identik dan MD5 geometri
11923/6113 sama; database uji sudah dihapus.

Sisa di lapisan non-tanah BLOK 3 (178 baris): 60 pasang kembar lain (120 baris,
99.363,36 m²) yang berada di bawah bidang tanah lama dan 58 duplikat tunggal
≥95% (25.612,98 m²), belum ditandai; ditambah 6413/12136 milik BLOK 5 dan 332
titik (≈8.300 m²) rongga tanpa poligon sumber.

### 3.42 Enam puluh pasang kembar non-tanah BLOK 3 dihapus (7 Nov 2026)

Atas permintaan pengguna, 120 baris non-tanah BLOK 3 yang membentuk 60 pasang
geometri identik dihapus fisik (`DELETE FROM bidang`), bukan ditandai. Syarat
yang dipenuhi sebelum hapus: setiap pasangan `ST_Equals=1` dan
`MD5(ST_AsBinary(geom))` sama, tidak ada id yang muncul di lebih dari satu
pasangan, dan **99,29–99,99%** luas tiap baris sudah tertutup bidang tanah.
Komposisi `jenis_hak`: 60 BLM + 59 HM + 1 KDH; total luas poligon yang dihapus
99.363,36 m². Id: 5560, 5622, 5753, 5769, 5783, 5785, 5792, 6009, 6114, 6115,
6116, 6117, 6118, 6119, 6121, 6122, 6123, 6126, 6127, 6128, 6129, 6130, 6131,
6132, 6133, 6134, 6140, 6142, 6147, 6150, 6152, 6154, 6155, 6158, 6159, 6161,
6163, 6167, 6168, 6169, 6170, 6171, 6173, 6174, 6177, 6203, 6209, 6211, 6214,
6215, 6216, 6218, 6219, 6220, 6346, 7453, 7455, 7506, 7973, 8035, 11566, 11614,
11680, 11692, 11696, 11697, 11701, 11833, 11924, 11925, 11926, 11927, 11928,
11929, 11931, 11932, 11933, 11936, 11937, 11938, 11939, 11940, 11941, 11942,
11943, 11944, 11947, 11948, 11950, 11952, 11954, 11955, 11956, 11959, 11960,
11962, 11964, 11967, 11968, 11969, 11970, 11971, 11972, 11973, 11976, 12001,
12003, 12004, 12005, 12006, 12007, 12008, 12009, 12010, 12090, 12779, 12780,
12789, 13100, 13111.

Pemeriksaan dependensi: 120 baris `hak` terhapus lewat `ON DELETE CASCADE`;
`objek_pajak`, `laporan`, `peralihan`, dan `buku_c` tidak punya baris yang
menunjuk ke-120 bidang ini (semuanya `ON DELETE SET NULL`), dan setelah hapus
tidak ada `hak` yatim.

Satu-satunya nama yang akan hilang: 6147 `HM-521D` memegang "AGUS WANTORO /
JUMADI", sedangkan bidang tanah pasangannya 21564 hanya "JUMADI". Sebelum hapus,
`hak` id 22863 (bidang 21564) diubah `pemegang_nama` menjadi "AGUS WANTORO /
JUMADI" dan `catatan` ditambah "Alias pemilik dari poligon non-tanah 6147
HM-521D: AGUS WANTORO (geometri duplikat dihapus 2026-11-07)."; perubahan itu
punya baris audit sendiri.

Hasil: total bidang **8.103 → 7.983**; non-tanah BLOK 3 `perlu_verifikasi`
**178 → 58**; global non-tanah `perlu_verifikasi` **4.218 → 4.098** (`lain/aktif`
tetap 1), `duplikat` tetap 24, bidang tanah tetap 3.858, baris `hak` 8.101 →
7.981. Tidak ada `bidang` yang masih berpasangan identik di BLOK 3. Audit: 120
baris `hapus` (id 3131–3250) + 1 baris `ubah` hak (id 3130), `pengguna_id` NULL.

Uji API: lapisan `GET api/bidang.php?lapisan=bidang&maks=50000` memuat 7.957
fitur (3.858 tanah + 4.099 non-tanah = 26 baris `duplikat`/`dihapus`
tersembunyi); `cari=DXF-020100-0046` menjawab `jumlah: 0` karena barisnya sudah
tidak ada; `cari=DXF-020100-0002&status=duplikat` tetap `jumlah: 1`.

Cadangan penuh sebelum menulis:
`D:\backup\sigpetades\hapus-kembar-blok3-20261107\sipetades_gedong-sebelum-hapus-kembar-20261107.sql`
(10.873.812 byte; SHA-256 `0c842bc004df5044dc2250127c6b8ef27c98d50aadc90fe25c67d9db9f53472f`,
tercatat di `SHA256.txt`). Uji restore ke `sipetades_restore_20261107f` cocok:
22 objek (18 tabel + 4 view) dengan jumlah baris identik dan MD5 geometri blok
1/2/3/5/16/18–24 sama; database uji sudah dihapus.

Sisa non-tanah BLOK 3: 58 duplikat tunggal ≥95% (25.612,98 m²) yang belum
ditandai, ditambah 6413/12136 yang 99,8% berada di BLOK 5 dan 332 titik
(≈8.300 m²) rongga tanpa poligon sumber.

### 3.43 Dua puluh satu geometri unik BLOK 8 dijadikan bidang tanah (8 Nov 2026)

Audit BLOK 8 (blok id 19, 267.275,88 m², `batas_tersedia=1`) menemukan 272
bidang: 138 tanah (85 aktif + 53 perlu) dan 134 non-tanah (133 perlu + 1 aktif).
Tanah kotor 227.266,23 m² dengan 12 pasang tumpang tindih 17.760,63 m² (cakupan
unik ≈209.505 m² = 78,4%). Grid 5 m: 10.699 titik di dalam blok; 8.435 (78,8%)
tertutup tanah, 1.536 (≈38.400 m²) hanya non-tanah, dan 728 (≈18.200 m²)
kosong tanpa poligon.

Dari 134 non-tanah: 105 (36 BLM + 69 HM, 140.048,93 m²) tertutup tanah ≥95%;
29 kandidat rongga (9 BLM + 20 HM, 53.736,38 m²); di antaranya 8 BLM kembar
persis dari HM (`ST_Equals=1`, MD5 sama). Atas permintaan pengguna, 21 geometri
unik (20 HM + 12105 BLM, 38.122,06 m²) diubah `tipe='lain'` → `'tanah'`,
`blok_id` tetap 19, `status` tetap, dan `catatan` ditambah "Dikonversi dari
non-tanah ke bidang tanah BLOK 8 pada 8 Nov 2026." Id: 6369, 6373, 6380, 6389,
6392, 6393, 6395, 6396, 6398, 6752, 7458, 7460, 7461, 7481, 7485, 7494, 7498,
7500, 8033, 10050, 12105. Tidak ada geometri, luas, hak, NOP, atau SPPT yang
berubah. 10050 `DXF-020100-2949` adalah satu-satunya baris non-tanah `aktif`
(yang lain `perlu_verifikasi`), sehingga setelah konversi menjadi tanah `aktif`.

Hasil: bidang tanah BLOK 8 **138 → 159**, non-tanah **134 → 113** (44 BLM + 69
HM); global bidang tanah **3.858 → 3.879** (aktif 3.247 → 3.248, perlu 611 →
631), non-tanah perlu **4.218 → 4.078**, non-tanah aktif 1 → 0, `duplikat` tetap
24, total baris tetap 7.983. Audit: 21 baris `ubah` (id 3252–3272),
`pengguna_id` NULL.

Uji API: lapisan penuh `GET api/bidang.php?lapisan=bidang&maks=50000` memuat
7.957 fitur (3.879 tanah + 4.078 non-tanah); `blok=BLOK 8` memuat 272 fitur
(159 tanah + 113 non-tanah); `cari=DXF-020100-0855` menjawab `jumlah: 1`,
`tipe=tanah`, `blok=BLOK 8`.

Cadangan penuh sebelum menulis:
`D:\backup\sigpetades\konversi-blok8-20261108\sipetades_gedong-sebelum-konversi-blok8-20261108.sql`
(10.837.807 byte; SHA-256 `1a1381c8b81e0e4e6b79589453025f071e0a6d231dbb1d861ed3d4fde16d3d99`,
tercatat di `SHA256.txt`). Uji restore ke `sipetades_restore_20261108a` cocok:
22 objek (18 tabel + 4 view) dengan jumlah baris identik dan MD5 geometri blok
1/2/3/5/6/18–24 sama; database uji sudah dihapus.

Belum ditangani: 8 BLM kandidat kembar (12117, 12119, 12102, 12122, 12121,
12124, 12109, 12120; 15.614,30 m²) yang sekarang identik dengan bidang tanah
baru; 105 duplikat ≥95% (36 BLM + 69 HM); 6376 `HM-951D K3` (BLOK 6, 895,02 m²)
tetap non-tanah padahal 12105 kembarnya kini tanah; 8034 `DXF-020100-2520`
(BLOK 6, 268,72 m²) di rongga; serta ±18.200 m² rongga tanpa poligon sumber.

### 3.44 Delapan BLM kembar BLOK 8 ditandai duplikat (8 Nov 2026)

Atas permintaan pengguna, 8 baris BLM tanpa nama yang geometrinya identik dengan
bidang tanah hasil §3.43 diubah `status='perlu_verifikasi'` → `'duplikat'`.
`tipe` tetap `lain`, `blok_id` tetap 19, dan `catatan` ditambah "Ditandai
duplikat pada 8 Nov 2026; geometri identik dengan bidang tanah <kode>."
Pasangan (BLM → tanah): 12102 → 6373 `HM-945D K3`, 12109 → 6380 `HM-949D K3`,
12117 → 6389 `HM-939D K3`, 12119 → 6392 `HM-933D K3`, 12120 → 6393
`HM-932D K3`, 12121 → 6395 `HM-927D K3`, 12122 → 6396 `HM-930D K3`, 12124 →
6398 `HM-931D K3` (15.614,30 m²). Semua pasangan `ST_Equals=1`; tidak ada
geometri, luas, hak, NOP, atau SPPT yang berubah.

Karena `bidang_filter()` menyembunyikan `duplikat` secara default, ke-8 baris
hilang dari peta, daftar, dan pencarian; tampil lagi dengan `status=duplikat`.
Hasil: non-tanah BLOK 8 **113 → 105 `perlu_verifikasi` + 8 `duplikat`** (36 BLM
+ 69 HM perlu); global non-tanah `perlu_verifikasi` **4.078 → 4.070**,
`duplikat` **24 → 32**, bidang tanah tetap 3.879, total baris tetap 7.983.
Audit: 8 baris `ubah` (id 3283–3290).

Uji API: lapisan penuh 7.949 fitur (3.879 tanah + 4.070 non-tanah; 34 baris
tersembunyi); `blok=BLOK 8` 264 fitur (159 + 105); `cari=DXF-020100-0875` tanpa
filter menjawab `jumlah: 0`, dengan `status=duplikat` menjawab `jumlah: 1`.

Cadangan penuh sebelum menulis:
`D:\backup\sigpetades\duplikat-blm-blok8-20261108\sipetades_gedong-sebelum-duplikat-blm-blok8-20261108.sql`
(10.853.425 byte; SHA-256 `5caee4877afe60ae5c57d7fa132e2dc5ab981c9dbebae8bb272e6ac2d4a75575`,
tercatat di `SHA256.txt`). Uji restore ke `sipetades_restore_20261108b` cocok:
22 objek (18 tabel + 4 view) dengan jumlah baris identik dan MD5 geometri blok
1/2/3/5/6/18–24 sama; database uji sudah dihapus.

Sisa BLOK 8: 105 duplikat ≥95% (36 BLM + 69 HM) belum ditandai; 6376
`HM-951D K3` (BLOK 6) dan 8034 `DXF-020100-2520` (BLOK 6) di rongga; ±18.200 m²
rongga tanpa poligon sumber.

### 3.45 Seratus lima duplikat ≥95% BLOK 8 dihapus setelah lima nama digabung (8 Nov 2026)

Atas permintaan pengguna "hapus seluruh 105 baris setelah gabungkan 5 nama unik
ke bidang 17895", 105 baris non-tanah BLOK 8 dengan cakupan bidang tanah ≥95%
dihapus fisik (`DELETE FROM bidang`), bukan ditandai. Syarat yang dipenuhi:
setiap baris `status='perlu_verifikasi'`, `tipe='lain'`, `blok_id=19`, dan luas
tertutup ≥95% (rentang 99,21–199,94%; penjumlahan irisan dapat melebihi 100%
bila beberapa bidang tanah saling tumpang tindih). Dependensi hanya 105 baris
`hak` (`ON DELETE CASCADE`); `objek_pajak`, `buku_c`, `laporan`, dan `peralihan`
nol baris (`ON DELETE SET NULL`). Komposisi: 36 BLM (47.980,00 m²) + 69 HM
(91.458,17 m²), total 140.048,92 m². Id: 5518, 6195, 6196, 6197, 6198, 6199,
6200, 6201, 6374, 6377, 6378, 6379, 6381, 6382, 6383, 6384, 6385, 6386, 6387,
6388, 6390, 6391, 6394, 6397, 6399, 6400, 6549, 6550, 6556, 6558, 6559, 6560,
7405, 7459, 7462, 7463, 7464, 7465, 7466, 7467, 7468, 7469, 7470, 7471, 7472,
7473, 7474, 7475, 7476, 7477, 7478, 7479, 7480, 7482, 7483, 7484, 7486, 7487,
7488, 7489, 7490, 7491, 7492, 7493, 7495, 7496, 7497, 7499, 8445, 11993, 11994,
11995, 11996, 11997, 11998, 11999, 12101, 12103, 12104, 12106, 12107, 12108,
12110, 12111, 12112, 12113, 12114, 12115, 12116, 12118, 12123, 12125, 12205,
12236, 12237, 12238, 12239, 12240, 12241, 12242, 12243, 12245, 12246, 12247,
12783.

Struktur: 28 pasang kembar persis sesama non-tanah (56 baris; 28 HM bernama ↔
28 BLM DXF tanpa nama), satu baris (8445 HM SUTRISNO) kembar persis bidang tanah
22738 `DXF-020100-G03135`, sisanya duplikat dekat ≥95%. Dari 69 HM, 66 bernama
dan 3 tanpa nama (6383, 6391, 7497).

Lima nama hanya ada di baris yang dihapus dan menutup bidang tanah 17895
`DXF-020100-G00479` yang pemegangnya kosong: SUPARIYEM (7469), SUBUR (7476),
SARJO SARMIIN (7477), PUJIONO (7479), ADMO SUPAT (7490). Sebelum hapus, `hak`
id 19194 (bidang 17895) diubah `pemegang_nama` NULL → "SUPARIYEM / SUBUR /
SARJO SARMIIN / PUJIONO / ADMO SUPAT" dan `catatan` ditambah alias kelima
poligon; perubahan itu punya baris audit sendiri (id 3298). Empat nama lain
(SUPARI, SUPIYAH, RUKIYAH, MISRO) sudah ada di bidang BLOK 8 lain sehingga
tidak digabung.

Hasil: total bidang **7.983 → 7.878**; baris `hak` **7.981 → 7.876**; global
non-tanah `perlu_verifikasi` **4.070 → 3.965**, `duplikat` tetap 32; bidang
tanah tetap 3.879 (3.248 aktif + 631 perlu). BLOK 8 kini 159 tanah + 8
`duplikat` = 167 baris, tanpa non-tanah `perlu_verifikasi`. Audit: 105 baris
`hapus` (id 3299–3403) + 1 baris `ubah` hak (id 3298), `pengguna_id` NULL;
tidak ada `hak` yatim dan tidak ada lagi baris non-tanah BLOK 8 dengan cakupan
≥95%.

Uji API: lapisan penuh memuat 7.844 fitur (3.879 tanah + 3.965 non-tanah = 34
baris `duplikat`/`dihapus` tersembunyi); `cari=DXF-020100-1973` menjawab
`jumlah: 0`; `cari=DXF-020100-0859&status=duplikat` tetap `jumlah: 1`.

Cadangan penuh sebelum menulis:
`D:\backup\sigpetades\tandai-duplikat-105-blok8-20261108\sipetades_gedong-20261108.sql`
(10.859.245 byte; SHA-256 `a94443cd0d37ad8014e9be05ec130689719952452720104abef81633d3c7ef53`,
tercatat di `SHA256.txt`). Uji restore ke `sipetades_restore_20261108c` cocok:
22 objek (18 tabel + 4 view) dengan jumlah baris identik dan MD5 geometri per
blok sama; database uji sudah dihapus. Dump sesudah:
`sipetades_gedong-20261108-sesudah.sql` (10.829.595 byte; SHA-256
`d664c4736ea6167a07151e9c9f699bbdc6004acb355c471169a3223886afb3f2`).

Sisa BLOK 8: 6376 `HM-951D K3` (BLOK 6) dan 8034 `DXF-020100-2520` (BLOK 6)
di rongga; ±18.200 m² rongga tanpa poligon sumber.

### 3.46 Enam puluh tiga calon bidang DXF ditetapkan sebagai bidang tertutup (8 Nov 2026)

Atas permintaan pengguna — lalu ditegaskan tertulis "Ya, tetapkan 69 bidang
mandiri calon bidang DXF menjadi bidang tertutup di database SIGPETADES" —
layer `CALON BIDANG` MASTER.dxf (737 poligon hasil penutupan garis 020100 dengan
celah ujung ≤ 1 unit dan luas ≥ 20 unit²) diproses menjadi bidang.

Penyaringan berlapis sebelum menulis. Terhadap database hidup, 547 kandidat
sudah menjadi bidang dengan cakupan ≥ 95% (umumnya `DXF-020100-*` dan
`DXF-020100-G*`), dan 6 kandidat kembar persis di antara dirinya sendiri,
sehingga tersisa 184 kandidat baru. Dari 184 itu, 114 adalah lingkar majemuk
yang seluruh luasnya sudah tercakup 2–9 bidang hidup (cakupan 99,5–101,2%;
jumlah irisan bisa melebihi 100% karena bidang lama saling bertumpuk) dan 1
meragukan; keduanya **tidak** ditetapkan. Yang lolos adalah 69 kandidat
mandiri: cakupan bidang hidup ≤ 0,9%, tidak ada titik tengah bidang hidup di
dalamnya, dan tidak ada pasangan mandiri yang saling menumpang > 2%.
Kategori ini ditampilkan lebih dahulu sebagai pratinjau peta.

Saat penerapan, 6 dari 69 kandidat ditahan karena ternyata membungkus bidang
hidup dengan irisan nyaris nol (pita tipis di sekeliling persil, bukan bidang
berdiri sendiri):
`00218` (181,5 m²; membungkus `DXF-020100-0738`/`HM-A129`, 193 m²),
`00250` (553,7 m²; `DXF-020100-G00638`, 4.779,2 m²),
`00259` (513,6 m²; `DXF-020100-G02194`, 149,0 m²),
`00306` (347,2 m²; `DXF-020100-G02034`, 145,4 m²),
`00591` (498,0 m²; `HM-0052`, 402,0 m²), dan
`00610` (237,8 m²; `HM-0058`, 259,0 m²).
Keenamnya tetap sebagai kandidat, belum masuk `bidang`.

Hasil: **63 bidang** dibuat dalam satu transaksi, total **149.836,05 m²**
(terkecil 29,09 m²; terbesar 63.778,56 m²), id **22.969–23.031**. Semuanya
`tipe='tanah'`, `status='perlu_verifikasi'`, `sumber='peta-calon-bidang'`,
`blok_id` NULL (tanpa blok, sesuai pilihan), ditambah 63 baris `hak` dengan
jenis BLM. Total bidang **7.878 → 7.941**; baris `hak` **7.876 → 7.939**.
Audit id 3406 mencatat 63 kode, luas, dan sebaran per blok (terbanyak BLOK 25:
19 bidang, BLOK 2: 12, BLOK 21: 5, BLOK 23: 5, BLOK 8: 4).

Uji API: `api/bidang.php?lapisan=bidang&maks=200&cari=DXF-CALON-BIDANG`
menjawab `jumlah: 63`, `terpotong: false`. Tidak ada bidang baru yang beririsan
> 2% dengan bidang hidup (diuji ulang sebelum sisip).

Cadangan penuh sebelum menulis:
`D:\backup\sigpetades\calon-bidang-20261108\sipetades_gedong-20261108.sql`
(9.579.129 byte; SHA-256
`d118c7fc2831717575bab52257fb9823524d7ad92b2d127e4b950230418959cf`), diuji
restore ke `sipetades_restore_calon_20261108` — jumlah `bidang`, `hak`, `blok`,
`objek_pajak`, `sppt`, dan `audit_log` identik; database uji sudah dihapus.
Dump sesudah: `sipetades_gedong-20261108-sesudah.sql` (9.621.370 byte; SHA-256
`359fe76ddcca6130c1e29d5aae13fd8c67093d7c89d2a5ae56c849ed1c4b91ed`).

### 3.47 Enam kandidat tertahan ditetapkan menyusul (8 Nov 2026)

Atas permintaan pengguna "tambahkan juga 6 kandidat menjadi bidang baru",
keenam kandidat yang ditahan pada §3.46 ditetapkan sebagai bidang. Sebelum
menulis, keenamnya diperiksa ulang: belum ada di `bidang`, luas 181,52–553,70
m², dan irisan dengan bidang hidup hanya 0,27–1,54% (irisan terbesar satu bidang
2,66 m²) — sesuai dugaan bahwa bentuknya pita tipis yang mengelilingi persil
hidup, bukan bidang tumpang tindih.

Hasil: **6 bidang**, id **23.032–23.037**, total **2.331,76 m²**,
`tipe='tanah'`, `status='perlu_verifikasi'`, `sumber='peta-calon-bidang'`,
`blok_id` NULL, ditambah 6 baris `hak` BLM. Rincian: `00218` (181,52 m²;
mengelilingi `HM-A129` dan `DXF-020100-0738`), `00250` (553,70;
`DXF-020100-G00638`), `00259` (513,64; `DXF-020100-G02194`), `00306` (347,16;
`DXF-020100-G02034`), `00591` (497,98; `HM-0052`), dan `00610` (237,76;
`HM-0058`). Total bidang **7.941 → 7.947**; baris `hak` **7.939 → 7.945**.
Audit id 3407 (`aksi='impor'`, `objek_id='peta-induk-calon-bidang-6'`) mencatat
keenam id dan kode; baris itu sempat tercatat dengan `aksi` kosong karena
`'tambah'` bukan nilai enum `audit_log`, lalu diganti ke `'impor'`.

Dengan demikian seluruh 69 kandidat mandiri sudah masuk: 63 pada §3.46 dan 6 di
sini. Uji API: `api/bidang.php?lapisan=bidang&cari=DXF-CALON-BIDANG` menjawab
`jumlah: 69`, dan pencarian tiap kode `00218`–`00610` menjawab `jumlah: 1`.

Cadangan penuh: `D:\backup\sigpetades\calon-bidang-tambahan-6-20261108\`.
`sipetades_gedong-20261108-sebelum.sql` (10.882.778 byte; SHA-256
`591a559493a68cfe66beece2a0ef93de568515da965d7eda78d45a35a1998ba0`) diuji
restore ke `sipetades_restore_calon6_20261108` — jumlah `bidang`, `hak`, `blok`,
`objek_pajak`, `audit_log`, dan `pengguna` identik, MD5 daftar bidang per blok
dan total luas per blok sama; database uji sudah dihapus. Dump sesudah:
`sipetades_gedong-20261108-sesudah.sql` (10.888.290 byte; SHA-256
`134063615b0d73aa1f633b58ba2cd38e691f152ad8860f09422a4fd4b7a974c6`).

### 3.48 Enam puluh lima bidang calon diverifikasi menjadi aktif (8 Nov 2026)

Atas permintaan pengguna "ubah status seluruh bidang sumber 'peta-calon-bidang'
dari 'perlu_verifikasi' menjadi 'aktif'", **65 bidang** diubah dalam satu
transaksi. Empat bidang calon sudah aktif lebih dulu lewat UI — 22.974, 22.975,
22.986, 23.016 (audit 3409–3412) — sehingga seluruh **69** bidang
`sumber='peta-calon-bidang'` (id 22.969–23.037) kini berstatus `aktif`.

Status seluruh bidang sesudahnya: `aktif` **3.317**, `perlu_verifikasi`
**4.596**, `duplikat` **32**, `dihapus` **2** (total tetap 7.947). Audit id
**3413** (`aksi='ubah'`, `objek='bidang'`,
`objek_id='peta-calon-bidang-aktif-20261108'`) memuat 65 id pada
`data_sebelum` dan `data_sesudah`. Uji API:
`api/bidang.php?lapisan=bidang&maks=200&cari=DXF-CALON-BIDANG` menjawab
`jumlah: 69`, `terpotong: false`, dan seluruh fitur berstatus `aktif`.

Cadangan penuh: `D:\backup\sigpetades\calon-bidang-aktif-20261108\`.
`sipetades_gedong-20261108-sebelum.sql` (9.629.846 byte; SHA-256
`38ca1c41382a6f7865356ea0c75702f44334ac786b50f12382bebc20d7addf05`) diuji
restore ke `sipetades_restore_aktif_20261108` — jumlah `bidang` (7.947),
`hak` (7.945), `blok` (24), `objek_pajak` (3.794), `sppt` (3.794), dan
`audit_log` (3.353) identik; `CHECKSUM TABLE` sama kecuali `sppt` yang hanya
berbeda checksum fisik (dump datanya identik selain baris komentar nama
database). Database uji sudah dihapus. Dump sesudah:
`sipetades_gedong-20261108-sesudah.sql` (9.630.208 byte; SHA-256
`5199afab1ec4e22672f4c7e5d33a4a94476e2085b1268740d251022deeb0c33b`).

### 3.49 Pemegang 65 bidang calon diisi dari label DXF (8 Nov 2026)

Atas permintaan "terapkan jenis hak dan pemegang pada calon bidang aktif",
`hak.pemegang_nama` diisi untuk **65** dari 69 bidang `peta-calon-bidang`.
Nama diambil apa adanya dari label layer `tn_nama` MASTER.dxf yang titiknya
berada di dalam poligon kandidat (4.409 label; pencocokan titik-dalam-poligon
pada koordinat meter DXF). Jenis hak tidak diubah: seluruh 69 tetap **BLM**
("Jenis hak tidak tercatat", id 7), sesuai pilihan pengguna — hanya `00736`
yang memuat label NIB (`00774`), itu pun bukan bukti sertifikat.

Empat bidang dibiarkan kosong: `00191`, `00296`, `00643` (tidak ada label) dan
`00735` (label "MAKAM / GG", bukan nama orang). Nama disimpan tanpa
penyeragaman ejaan/kapital (mis. `Suraningdyah`, `Ut Uila`). Jumlah baris `hak`
tetap 7.945. Audit id **3414** (`aksi='ubah'`, `objek='hak'`,
`objek_id='peta-calon-bidang-pemegang-20261108'`) memuat peta 65 id→nama di
`data_sesudah` dan pemegang kosong di `data_sebelum`.

Cadangan penuh: `D:\backup\sigpetades\calon-bidang-hak-20261108\`.
`sipetades_gedong-20261108-sebelum.sql` (9.630.208 byte; SHA-256
`2c5dd6653ff4e52713cc24c09c988ea67e3b69b261f49f01b4f59a080d3ee440`) diuji
restore ke `sipetades_restore_hak_20261108` — jumlah `bidang` (7.947),
`hak` (7.945), `blok` (24), `objek_pajak` (3.794), `sppt` (3.794), dan
`audit_log` (3.354) identik, `CHECKSUM TABLE` sama untuk `bidang`, `hak`,
`blok`, `objek_pajak`, dan `audit_log` (`sppt` hanya berbeda checksum fisik,
isinya identik); database uji sudah dihapus. Dump sesudah:
`sipetades_gedong-20261108-sesudah.sql` (9.632.339 byte; SHA-256
`a7dbd94a7dd7c06a1429345433af15d19dc808c82c5382119e27ae7468a374de`).

### 3.50 Delapan bidang kosong BLOK 5 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 5 adalah `blok.id=1` (`nomor=5`). Di dalamnya ada **85 objek `tipe='lain'`**;
**73 objek** di antaranya sudah tertutup ≥95% oleh 32 bidang tanah aktif
berkode `DXF-020100-G…` (hasil penggabungan beberapa persil) sehingga **tidak
diubah**. Hanya **12 baris (8 bidang)** yang belum terplotong — tidak tertutup
bidang tanah mana pun — dan itulah yang ditetapkan:

- **4 HM** menjadi tanah, status tetap `perlu_verifikasi`: 6407 `HM-818D`
  (227 m²), 6412 `HM-77B` (196 m²), 6413 `HM-63B` (234 m²), 6417 `HM-A199`
  (846 m²).
- **4 DXF** menjadi tanah: 8007 `DXF-020100-2493` (2.002 m², tetap `aktif`),
  8009 `DXF-020100-2495` (1.542 m²), 8010 `DXF-020100-2496` (1.421 m²),
  8017 `DXF-020100-2503` (1.129 m²).
- **4 kembar persis** DXF dari pasangan HM di atas — 12132 `…-0893`,
  12135 `…-0898`, 12136 `…-0899`, 12140 `…-0903` — ditandai
  `status='duplikat'`, `tipe` tetap `lain` (mengikuti pola BLOK 3/8).

Total luas yang ditetapkan **7.598,00 m²**. Sesudahnya BLOK 5 berisi **63 bidang
tanah** (56 aktif, 7 perlu_verifikasi) dan **77 objek lain** (73 perlu_verifikasi,
4 duplikat) — total tetap 140. Global: bidang tetap **7.947**, tanah **3.958**,
status `aktif` **3.319**, `perlu_verifikasi` **4.590**, `duplikat` **36**,
`dihapus` **2**; `hak` tetap **7.945**. Audit id **3433** (`aksi='ubah'`,
`objek='bidang'`, `objek_id='blok5-kosong-20261108'`) memuat 12 baris
`data_sebelum` dan `data_sesudah`. Uji API
`api/bidang.php?lapisan=bidang&maks=5&cari=HM-818D` menjawab `tipe: tanah`,
`blok: BLOK 5`. Pemeriksaan tumpang tindih: tidak ada bidang baru yang beririsan
≥50% dengan bidang tanah lain.

Cadangan penuh: `D:\backup\sigpetades\blok5-kosong-20261108\`.
`sebelum.sql` (9.647.545 byte; SHA-256
`1975bd677aec5ad6aee9e074ea5ce5e2de39580fa1ad5f8c5f3b76fa6ca72509`) diuji
restore ke `sipetades_restore_blok5_20261108` — jumlah `bidang` (7.947),
`hak` (7.945), `blok` (24), `objek_pajak` (3.794), `sppt` (3.794), dan
`audit_log` (3.373) identik; `CHECKSUM TABLE` sama kecuali `sppt` (checksum
fisik; isinya identik). Dump sesudah `sesudah.sql` (9.650.103 byte; SHA-256
`c784d104e357dd85a70e2aad74529484d32fde0f0513c30729368d8b21a9a91b`) juga
diuji restore — `bidang` 7.947, `hak` 7.945, `audit_log` 3.375, bidang tanah
BLOK 5 = 63. Database uji sudah dihapus.

### 3.51 Tiga bidang kosong BLOK 6 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 6 adalah `blok.id=3` (`nomor=6`). Dari **257 objek `tipe='lain'`**,
**252 sudah tertutup ≥95%** oleh bidang tanah aktif sehingga tidak diubah.
Hanya **5 baris (3 bidang)** yang belum terplotong:

- 6621 `HM-160A` (2.153 m²) ≡ 12302 `DXF-020100-1107` (kembar persis):
  HM menjadi tanah, DXF ditandai `status='duplikat'` (`tipe` tetap `lain`).
- 6647 `HM-436D K3` (537 m²) ≡ 12324 `DXF-020100-1133` (kembar persis):
  perlakuan sama.
- 6648 `DXF-020100-1134` (386 m², tunggal) menjadi tanah.

Total luas **3.076,00 m²**; ketiganya tetap `perlu_verifikasi`. Sesudahnya
BLOK 6 berisi **180 bidang tanah** (177 aktif, 3 perlu_verifikasi) dan
**254 objek lain** (252 perlu_verifikasi, 2 duplikat) — total 434. Global:
`bidang` tetap **7.947**, `hak` tetap **7.945**, tanah **3.961**; status
`aktif` **3.319**, `perlu_verifikasi` **4.588**, `duplikat` **38**,
`dihapus` **2**. Audit id **3435** (`objek_id='blok6-kosong-20261108'`).
Uji API `api/bidang.php?lapisan=bidang&maks=5&cari=HM-160A` menjawab
`tipe: tanah`, `blok: BLOK 6`, hak HM; tidak ada bidang baru yang beririsan
≥50% dengan bidang tanah lain.

Cadangan penuh: `D:\backup\sigpetades\blok6-kosong-20261108\`.
`sebelum.sql` (9.650.103 byte; SHA-256
`040ad4b9b85db8a78185bc82df2c53c7d7812726fc2b2f42481bf79969e19aeb`) diuji
restore ke `sipetades_restore_blok6_20261108` — `bidang` 7.947, `hak` 7.945,
`audit_log` 3.375, tanah BLOK 6 = 177; `CHECKSUM TABLE` sama kecuali `sppt`
(checksum fisik; data identik). Dump sesudah `sesudah.sql` (9.651.179 byte;
SHA-256 `f68ee53421748e25ec6e3e2679b6f19c36403b1da083790e5ac0cf3355c3378d`)
juga diuji restore — `bidang` 7.947, `hak` 7.945, `audit_log` 3.376, tanah
BLOK 6 = 180. Database uji sudah dihapus.

### 3.52 Empat belas bidang kosong BLOK 7 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 7 adalah `blok.id=5` (`nomor=7`), berisi **211 bidang**: 68 `tanah` aktif
dan 143 `lain` berstatus `perlu_verifikasi`. Pola yang sama dengan BLOK 5/6
dipakai: hanya objek yang belum terplotong dijadikan tanah, sedangkan kembar
persis DXF ditandai duplikat. Hasilnya:

- **14 bidang `lain` → `tanah`**, status tetap `perlu_verifikasi`, total
  **25.982,00 m²**: 6358 `DXF-020100-0844` (3.155 m²), 6444 `HM-669D K3`
  (1.529 m²), 6447 `HM-831D K3` (423 m²), 6448 `HM-830D K3` (428 m²),
  6450 `HM-833D K3` (2.293 m²), 6453 `HM-834D K3` (721 m²), 6516 `HM-197A`
  (4.966 m²), 6522 `HM-826D K3` (7.151 m²), 6523 `HM-153A` (877 m²),
  6524 `HM-151A` (857 m²), 6526 `HM-152A` (863 m²), 6537 `HM-753D K3`
  (886 m²), 6538 `HM-754D K3` (928 m²), 8055 `DXF-020100-2541` (905 m²).
- **14 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  22.498,29 m²: 12164, 12167, 12168, 12169, 12172, 12207, 12213, 12214,
  12215, 12217, 12226, 12227 (kembar persis keeper HM di atas), ditambah
  6542 `DXF-020100-1028` dan 8229 `DXF-020100-2715`.

Audit id **3436** (`objek_id='blok7-kosong-20261108'`).

**Koreksi (audit id 3437).** Pemeriksaan cakupan awal hanya di dalam BLOK 7,
sehingga 6542 dan 8229 sempat dijadikan tanah. Verifikasi global sesudahnya
menunjukkan keduanya **100% tertutup bidang tanah aktif di blok lain**: 6542
oleh `DXF-020100-G01291` (id 18232) dan 8229 oleh `DXF-020100-G01156`
(id 18152), keduanya di `blok.id=19` yaitu **BLOK 8** (bukan "BLOK 19").
Keduanya dikembalikan menjadi `tipe='lain'`, `status='duplikat'` lewat satu
transaksi beraudit **3437** (`objek_id='blok7-kosong-koreksi-20261108'`).
Pelajaran: cakupan harus diperiksa lintas seluruh blok, bukan hanya blok
target.

Keadaan akhir BLOK 7: `tanah` **82** (68 aktif + 14 `perlu_verifikasi`),
`lain` **129** (115 `perlu_verifikasi` + 14 `duplikat`). Global: `bidang`
tetap **7.947**, `hak` tetap **7.945**, tanah **3.975**, lain **3.972**;
status `aktif` **3.319**, `perlu_verifikasi` **4.574**, `duplikat` **52**,
`dihapus` **2**. Uji API
`api/bidang.php?lapisan=bidang&maks=200&cari=HM-826D K3` menjawab
`jumlah: 1`, `terpotong: false`, `tipe: tanah`, `status: perlu_verifikasi`;
tidak ada dari 14 bidang yang beririsan ≥95% dengan bidang tanah lain di luar
kembarannya.

Cadangan penuh: `D:\backup\sigpetades\blok7-kosong-20261108\`.
`sebelum.sql` (9.651.179 byte; SHA-256
`5319001dbd1a7adfa9b555975680af525215a897c757001b73fc7dabbacaad1c`) diuji
restore ke `sipetades_restore_blok7_20261108` — `bidang` 7.947, `hak` 7.945,
`blok` 24, `objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.376, tanah
BLOK 7 = 68; `CHECKSUM TABLE` sama kecuali `sppt` (checksum fisik; data
identik). Dump sesudah `sesudah.sql` (9.658.892 byte; SHA-256
`dd284851a73283332894c5b2b03b63cb235b011c1ce5bcf162ae9553c8868740`) juga
diuji restore — `bidang` 7.947, `hak` 7.945, `audit_log` 3.379, isi `sppt`
identik. Database uji sudah dihapus.

### 3.53 Dua puluh tiga bidang kosong BLOK 9 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 9 adalah `blok.id=6` (`nomor=9`), berisi **365 bidang**: 128 `tanah`
aktif, 1 `lain` aktif (8034 `DXF-020100-2520`), dan 236 `lain`
`perlu_verifikasi`. Dari 237 objek `lain`, **193 sudah tertutup ≥95%** bidang
tanah sehingga dibiarkan; **44 terbuka** dan diproses dengan pola BLOK 5–7:

- **23 bidang `lain` → `tanah`**, total **18.384,00 m²**:
  - 21 HM (tetap `perlu_verifikasi`): 7369 `HM-1908D` (666 m²), 7385
    `HM-1755D` (956), 7386 `HM-1754D` (534), 7389 `HM-1756D` (1.242),
    7395 `HM-1750D` (810), 7397 `HM-1742D` (1.170), 7417 `HM-1740D`
    (1.308), 7418 `HM-1739D` (2.687), 7419 `HM-1738D` (915), 7432
    `HM-1717D` (1.037), 7433 `HM-1718D` (1.427), 7436 `HM-1708D` (891),
    7439 `HM-1704D` (118), 7440 `HM-1705D` (179), 7442 `HM-1713D` (409),
    7448 `HM-1700D` (416), 7450 `HM-1703D` (371), 7451 `HM-1702D` (540),
    7961 `HM-1928D` (991), 7964 `HM-1905D` (677), 7995 `HM-1751D` (494).
  - 2 DXF tunggal: 7449 `DXF-020100-1935` (278 m², tetap
    `perlu_verifikasi`) dan 8034 `DXF-020100-2520` (268 m², tetap `aktif`).
- **21 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  17.838,00 m², seluruhnya `ST_Equals` dengan keeper HM-nya: 12715, 12728,
  12729, 12732, 12737, 12739, 12752, 12753, 12754, 12765, 12766, 12767,
  12769, 12770, 12772, 12776, 12777, 12778, 13093, 13096, 13105
  (pasangan berurutan ≡ keeper di daftar HM di atas).

Audit id **3439** (`objek_id='blok9-kosong-20261108'`). Keadaan akhir BLOK 9:
`tanah` **151** (129 aktif + 22 `perlu_verifikasi`), `lain` **214** (193
`perlu_verifikasi` + 21 `duplikat`); total tetap 365. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **3.998**, lain **3.949**; status `aktif`
**3.319**, `perlu_verifikasi` **4.553**, `duplikat` **73**, `dihapus` **2**.
Uji API `cari=HM-1908D` menjawab `tipe: tanah`, `status: perlu_verifikasi`,
`blok: BLOK 9`; cakupan keeper di luar dirinya maksimum **0,54%**, dan 21/21
kembar tetap tertutup 100% oleh keeper-nya.

Catatan teknis: MariaDB 10.4 menolak rencana join yang memakai indeks spasial
`spn_bidang_geom` dengan galat 1207; pemeriksaan cakupan dijalankan dengan
`IGNORE INDEX (spn_bidang_geom)`, sedangkan query spasial satu tabel dan API
peta tetap normal.

Cadangan penuh: `D:\backup\sigpetades\blok9-kosong-20261108\`.
`sebelum.sql` (9.658.892 byte; SHA-256
`7d13f4ec84ad6e3a6b7889532029dfefd83ad284b8d29abaf6d3a0d6a5144091`) diuji
restore ke `sipetades_restore_blok9_20261108` — `bidang` 7.947, `hak` 7.945,
`blok` 24, `objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.379, tanah
BLOK 9 = 128; `CHECKSUM TABLE` sama kecuali `sppt` (checksum fisik; data
identik). Dump sesudah `sesudah.sql` (9.666.442 byte; SHA-256
`47b1aaf4680c6ae9371b5e4d0c4ae2035438549a2eeff8d2646b71bed3256623`) juga
diuji restore — `bidang` 7.947, `hak` 7.945, tanah BLOK 9 = 151, duplikat = 21,
`audit_log` 3.380, seluruh checksum termasuk `sppt` identik. Database uji
sudah dihapus.

### 3.54 Dua puluh bidang kosong BLOK 10 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 10 adalah `blok.id=17` (`nomor=10`), berisi **474 bidang**: 207 `tanah`
aktif, 1 `tanah` perlu_verifikasi, dan 266 `lain` `perlu_verifikasi`. Dari 266
objek `lain`, **239 sudah tertutup ≥95%** bidang tanah sehingga dibiarkan;
**27 terbuka** diproses:

- **20 bidang `lain` → `tanah`** (tetap `perlu_verifikasi`), total
  **13.689,00 m²**:
  - 7 HM: 5520 `HM-134C` (162 m²), 6338 `HM-D295 K3` (1.275), 6824
    `HM-D685 K3` (159), 6887 `HM-D281 K3` (2.390), 6902 `HM-213C` (343),
    6906 `HM-D459 K3` (360), 7956 `HM-1943D` (53).
  - 13 DXF tunggal: 6313 `DXF-020100-0799` (1.589), 6325
    `DXF-020100-0811` (11), 6332 `DXF-020100-0818` (3.345), 6336
    `DXF-020100-0822` (434), 6860 `DXF-020100-1346` (80), 6861
    `DXF-020100-1347` (126), 6862 `DXF-020100-1348` (164), 6885
    `DXF-020100-1371` (542), 6892 `DXF-020100-1378` (702), 6909
    `DXF-020100-1395` (204), 7038 `DXF-020100-1524` (1.523), 7952
    `DXF-020100-2438` (186), 7953 `DXF-020100-2439` (41).
- **7 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  4.742,00 m², seluruhnya `ST_Equals` dengan keeper HM-nya: 11539≡5520,
  12084≡6338, 12442≡6824, 12476≡6887, 12483≡6902, 12486≡6906,
  13091≡7956.

Audit id **3440** (`objek_id='blok10-kosong-20261108'`). Keadaan akhir BLOK 10:
`tanah` **228** (207 aktif + 21 `perlu_verifikasi`), `lain` **246** (239
`perlu_verifikasi` + 7 `duplikat`); total tetap 474. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.018**, lain **3.929**; status `aktif`
**3.319**, `perlu_verifikasi` **4.546**, `duplikat` **80**, `dihapus` **2**.
Uji API `cari=HM-134C` menjawab `tipe: tanah`, `blok: BLOK 10`, hak KDH;
cakupan keeper di luar dirinya maksimum **0,65%**, dan 7/7 kembar tetap
tertutup 100% oleh keeper-nya.

Cadangan penuh: `D:\backup\sigpetades\blok10-kosong-20261108\`.
`sebelum.sql` (9.666.442 byte; SHA-256
`b9b85d040ae8fb3e84b60c6b43ced6571505aee3bb0ef51ef7c80dd6957b400e`) diuji
restore ke `sipetades_restore_blok10_20261108` — `bidang` 7.947, `hak` 7.945,
`blok` 24, `objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.380, tanah
BLOK 10 = 208; `CHECKSUM TABLE` sama kecuali `sppt` (checksum fisik; data
identik). Dump sesudah `sesudah.sql` (9.671.353 byte; SHA-256
`12426ef21d66e37de8185c998ac7e4af8cc132756dc75ef633242720d987145a`) juga
diuji restore — `bidang` 7.947, `hak` 7.945, tanah BLOK 10 = 228, duplikat = 7,
`audit_log` 3.381, checksum sama kecuali `sppt`, isi `sppt` identik.
Database uji sudah dihapus.

### 3.55 Dua puluh tujuh bidang kosong BLOK 12 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 12 adalah `blok.id=14` (`nomor=12`). Saat analisis dimulai berisi 585
bidang; pengguna memindahkan 22200 `DXF-020100-G01973` ke BLOK 13
(`blok.id=12`, audit 3441) pada 12.46 sehingga tersisa **584 bidang**: 248
`tanah` aktif, 1 `tanah` dihapus, dan 335 `lain` `perlu_verifikasi`. Dari 335
objek `lain`, **298 sudah tertutup ≥95%** bidang tanah sehingga dibiarkan;
**37 terbuka** diproses:

- **27 bidang `lain` → `tanah`** (tetap `perlu_verifikasi`), total
  **18.087,84 m²**:
  - 9 HM: 6979 `HM-A57` (2.971 m²), 6984 `HM-A55` (2.916), 7001
    `HM-A139` (485), 7907 `HM-1814D` (1.494), 7915 `HM-1816D` (2.534),
    7917 `HM-1813D` (2.218), 7918 `HM-1815D` (2.101), 7924 `HM-1823D`
    (285), 7930 `HM-1832D` (633).
  - 18 DXF tunggal: 6276 `DXF-020100-0762` (11), 7933 `DXF-020100-2419`
    (192), 7935 `DXF-020100-2421` (376), 8249 (291,47), 8273 (163,06),
    8276 (149,01), 8281 (137,75), 8282 (132,07), 8290 (127,45), 8346
    (100,74), 8349 (100,72), 8359 (100,58), 8371 (100,37), 8379 (100,25),
    8383 (100,15), 8396 (99,58), 8417 (89), 8422 (79,64).
- **9 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  15.637,00 m², seluruhnya `ST_Equals` dengan keeper HM-nya: 12518≡6979,
  12522≡6984, 12535≡7001, 13057≡7907, 13061≡7915, 13063≡7917, 13064≡7918,
  13068≡7924, 13074≡7930.
- **1 pengecualian**: 8089 `DXF-020100-2575` (6.654,85 m²) kembar persis
  (`ST_Equals`) dengan 22728 `DXF-020100-G03113` yang berstatus `dihapus`.
  Geometrinya berupa batas luar besar yang memuat 60+ bidang tanah aktif di
  dalamnya; mengonversinya sama dengan menghidupkan kembali geometri yang
  sengaja dihapus, sehingga dibiarkan `lain`/`perlu_verifikasi`. Bidang 8349
  juga terletak 100% di dalam geometri 22728 yang dihapus, tetapi berdiri
  sendiri dan tetap dikonversi.

Audit id **3442** (`objek_id='blok12-kosong-20261108'`). Keadaan akhir BLOK 12:
`tanah` **276** (248 aktif + 27 `perlu_verifikasi` + 1 dihapus), `lain` **308**
(299 `perlu_verifikasi` + 9 `duplikat`); total tetap 584. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.045**, lain **3.902**; status `aktif`
**3.319**, `perlu_verifikasi` **4.537**, `duplikat` **89**, `dihapus` **2**.
Cakupan keeper di luar dirinya maksimum **0,61%**, dan 9/9 kembar tetap
tertutup 100% oleh keeper-nya. Uji API `lapisan=bidang&blok=BLOK 12` menjawab
**574 fitur**: 248 `tanah` aktif, 27 `tanah` `perlu_verifikasi`, 299 `lain`
`perlu_verifikasi` (1 dihapus dan 9 duplikat tersaring).

Cadangan penuh: `D:\backup\sigpetades\blok12-kosong-20261108\`.
`sebelum.sql` (9.672.384 byte; SHA-256
`2aebc39e67915f8a30d23caace5aa10a3e00d6c0b8a9455a9920b7d2f31b3e1b`) diuji
restore ke `sipetades_coret_blok12` — `bidang` 7.947, `hak` 7.945, `blok` 24,
`objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.382; jumlah baris dan
`CHECKSUM TABLE` seluruh tabel identik, termasuk `sppt`. Dump sesudah
`sesudah.sql` (9.678.952 byte; SHA-256
`be8ada65ef4e3290bd21c0577e012f2414ab9ec1de9f175a4c54cdd56a6ec89e`) juga
diuji restore — `audit_log` 3.383, tanah BLOK 12 = 276, duplikat = 9, seluruh
checksum identik. Database uji sudah dihapus.

### 3.56 Empat puluh bidang kosong BLOK 13 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 13 adalah `blok.id=12` (`nomor=13`), berisi **444 bidang**: 147 `tanah`
aktif, 1 `tanah` `perlu_verifikasi` (22200 `DXF-020100-G01973` yang
dipindahkan pengguna dari BLOK 12 pada 12.46), dan 296 `lain`
`perlu_verifikasi`. Dari 296 objek `lain`, **228 sudah tertutup ≥95%** bidang
tanah sehingga dibiarkan; **68 terbuka** diproses:

- **40 bidang `lain` → `tanah`** (tetap `perlu_verifikasi`), total
  **40.038,00 m²**:
  - 28 HM: 5554 `HM-110C` (6.391 m²), 5604 `HM-346B` (1.726), 5762
    `HM-142B` (231), 7775 `HM-1272D` (1.496), 7782 `HM-1344D` (589), 7787
    `HM-1334D` (1.277), 7788 `HM-1335D` (1.213), 7799 `HM-1316D` (105),
    7800 `HM-1315D` (142), 7803 `HM-1266D` (678), 7808 `HM-1319D` (1.146),
    7809 `HM-1318D` (198), 7817 `HM-1324D` (517), 7844 `HM-1254D` (1.971),
    7845 `HM-1252D` (1.928), 7846 `HM-1253D` (1.802), 7852 `HM-1247D`
    (430), 7853 `HM-1248D` (370), 7856 `HM-1232D` (2.127), 7860
    `HM-1239D` (388), 7865 `HM-1228D` (699), 7866 `HM-1227D` (1.010),
    7882 `HM-1214D` (355), 7883 `HM-1215D` (401), 7887 `HM-1221D` (605),
    7897 `HM-1278D` (390), 7900 `HM-1204D` (2.087), 7989 `HM-1311D`
    (1.159).
  - 12 DXF tunggal: 7790 `DXF-020100-2276` (350), 7805 (628), 7813 (537),
    7818 (362), 7819 (168), 7820 (375), 7828 (315), 7836 (1.010), 7847
    (1.116), 7858 (457), 7869 (3.028), 7876 (261).
- **28 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  31.431,00 m², seluruhnya `ST_Equals` dengan keeper HM-nya: 11561≡5554,
  11603≡5604, 11686≡5762, 12968≡7775, 12971≡7782, 12976≡7787, 12977≡7788,
  12985≡7799, 12986≡7800, 12989≡7803, 12991≡7808, 12992≡7809, 12998≡7817,
  13014≡7844, 13015≡7845, 13016≡7846, 13020≡7852, 13021≡7853, 13023≡7856,
  13025≡7860, 13030≡7865, 13031≡7866, 13040≡7882, 13041≡7883, 13043≡7887,
  13050≡7897, 13052≡7900, 13101≡7989.

Tidak ada pengecualian di BLOK 13: tidak ada objek terbuka yang kembar
dengan bidang `dihapus`. Pasangan kembar lain di blok ini yang kedua
anggotanya sudah tertutup ≥95% (mis. 5555↔11562) tidak disentuh.

Audit id **3445** (`objek_id='blok13-kosong-20261108'`). Keadaan akhir BLOK 13:
`tanah` **188** (147 aktif + 41 `perlu_verifikasi`), `lain` **256** (228
`perlu_verifikasi` + 28 `duplikat`); total tetap 444. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.085**, lain **3.862**; status `aktif`
**3.319**, `perlu_verifikasi` **4.509**, `duplikat` **117**, `dihapus` **2**.
Cakupan keeper di luar dirinya maksimum **0,52%**, dan 28/28 kembar tetap
tertutup 100% oleh keeper-nya. Uji API `lapisan=bidang&blok=BLOK 13` menjawab
**416 fitur**: 147 `tanah` aktif, 41 `tanah` `perlu_verifikasi`, 228 `lain`
`perlu_verifikasi` (28 duplikat tersaring).

Cadangan penuh: `D:\backup\sigpetades\blok13-kosong-20261108\`.
`sebelum.sql` (9.681.177 byte; SHA-256
`7e47c5bb4957aa3583185bcd25721a3846cf74c402104a55db5703fae2f58730`) diuji
restore ke `sipetades_coret_blok13` — `bidang` 7.947, `hak` 7.945, `blok` 24,
`objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.385; jumlah baris dan
`CHECKSUM TABLE` seluruh tabel identik, termasuk `sppt`. Dump sesudah
`sesudah.sql` (9.692.904 byte; SHA-256
`f928c5c01366400208f59e97b62fa6a5e07b75edd3c5ca770645fcbc91d56d15`) juga
diuji restore — `audit_log` 3.386, tanah BLOK 13 = 188, duplikat = 28, seluruh
checksum identik. Database uji sudah dihapus.

### 3.57 Tiga puluh delapan bidang kosong BLOK 14 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 14 adalah `blok.id=13` (`nomor=14`), berisi **249 bidang**: 96 `tanah`
aktif dan 153 `lain` `perlu_verifikasi`. Dari 153 objek `lain`, **112 sudah
tertutup ≥95%** bidang tanah sehingga dibiarkan; **41 terbuka** diproses:

- **38 bidang `lain` → `tanah`** (tetap `perlu_verifikasi`), total
  **37.466,14 m²**:
  - 3 HM: 5523 `HM-A46` (684 m²), 5531 `HM-1359D` (693), 7115 `HM-A38`
    (242).
  - 35 DXF tunggal: 8090 `DXF-020100-2576` (5.885,91 m²), 8101
    `DXF-020100-2587` (2.280,11), 8105 `DXF-020100-2591` (1.867,32), 8113
    `DXF-020100-2599` (1.703,33), 8115 `DXF-020100-2601` (1.644,37), 8117
    `DXF-020100-2603` (1.606,30), 8124 `DXF-020100-2610` (1.457,35), 8126
    `DXF-020100-2612` (1.438,28), 8128 `DXF-020100-2614` (1.398,79), 8131
    `DXF-020100-2617` (1.351,76), 8138 `DXF-020100-2624` (1.173,66), 8139
    `DXF-020100-2625` (1.157,60), 8140 `DXF-020100-2626` (1.143,74), 8151
    `DXF-020100-2637` (966,64), 8156 `DXF-020100-2642` (935,08), 8171
    `DXF-020100-2657` (785,97), 8177 `DXF-020100-2663` (764,66), 8182
    `DXF-020100-2668` (749,74), 8184 `DXF-020100-2670` (740,12), 8185
    `DXF-020100-2671` (730,69), 8187 `DXF-020100-2673` (723,00), 8193
    `DXF-020100-2679` (695,46), 8197 `DXF-020100-2683` (638,47), 8200
    `DXF-020100-2686` (628,91), 8201 `DXF-020100-2687` (623,09), 8204
    `DXF-020100-2690` (610,25), 8227 `DXF-020100-2713` (456,52), 8236
    `DXF-020100-2722` (390,01), 8239 `DXF-020100-2725` (362,42), 8242
    `DXF-020100-2727` (337,29), 8255 `DXF-020100-2740` (245,87), 8279
    `DXF-020100-2764` (141,86), 8296 `DXF-020100-2781` (119,20), 8424
    `DXF-020100-2909` (71,46), 8429 `DXF-020100-2914` (21,91).
- **3 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  **1.619,00 m²**, seluruhnya `ST_Equals` dengan keeper HM-nya: 11541
  `DXF-020100-0009` ≡ 5523, 11546 `DXF-020100-0017` ≡ 5531, 12598
  `DXF-020100-1601` ≡ 7115.

Tidak ada pengecualian di BLOK 14: 35 DXF tunggal tidak memiliki kembaran di
seluruh tabel — termasuk bidang berstatus `dihapus` — dan tidak ada objek
terbuka lain. Satu-satunya bidang `dihapus` yang beririsan adalah batas desa
16118, yang sudah dikecualikan dari perhitungan cakupan.

Audit id **3446** (`objek_id='blok14-kosong-20261108'`). Keadaan akhir BLOK 14:
`tanah` **134** (96 aktif + 38 `perlu_verifikasi`), `lain` **115** (112
`perlu_verifikasi` + 3 `duplikat`); total tetap 249. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.123**, lain **3.824**; status `aktif`
**3.319**, `perlu_verifikasi` **4.506**, `duplikat` **120**, `dihapus` **2**.
Cakupan keeper di luar dirinya maksimum **0,36%**, dan 3/3 kembar tetap
tertutup 100% oleh keeper-nya. Uji API `lapisan=bidang&blok=BLOK 14` menjawab
**246 fitur**: 96 `tanah` aktif, 38 `tanah` `perlu_verifikasi`, 112 `lain`
`perlu_verifikasi` (3 duplikat tersaring); `cari` `HM-A46` dan
`DXF-020100-2576` menjawab `tipe: tanah`, `status: perlu_verifikasi`.

Cadangan penuh: `D:\backup\sigpetades\blok14-kosong-20261108\`.
`sebelum.sql` (9.692.904 byte; SHA-256
`7b5afb25920f51faa25f70da4757d2621846859546ba6b5991d239d642c27862`) diuji
restore ke `sipetades_coret_blok14` — `bidang` 7.947, `hak` 7.945, `blok` 24,
`objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.386; jumlah baris dan
`CHECKSUM TABLE` seluruh tabel sama kecuali `sppt` (checksum fisik), dan isi
`sppt` diverifikasi identik lewat dump `--skip-extended-insert` (3.794 baris,
nol beda). Dump sesudah `sesudah.sql` (9.700.538 byte; SHA-256
`d4f9e07f64589fc3c3e3c50d50c803307b0d002e463ea5120d3ba430884ccccc`) juga
diuji restore — `audit_log` 3.387, tanah BLOK 14 = 134, duplikat = 3, dan
seluruh checksum identik termasuk `sppt`. Database uji sudah dihapus.

### 3.58 Tujuh belas bidang kosong BLOK 15 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 15 adalah `blok.id=9` (`nomor=15`), berisi **327 bidang**: 140 `tanah`
aktif dan 187 `lain` `perlu_verifikasi`. Dari 187 objek `lain`, **159 sudah
tertutup ≥95%** bidang tanah sehingga dibiarkan; **28 terbuka** diproses:

- **17 bidang `lain` → `tanah`** (tetap `perlu_verifikasi`), total
  **12.349,00 m²**:
  - 11 HM: 6726 `HM-836D K3` (365 m²), 6751 `HM-215C` (206), 6761
    `HM-837D K3` (226), 6776 `HM-881D K3` (747), 6791 `HM-894D K3` (791),
    6792 `HM-893D K3` (709), 6793 `HM-892D K3` (655), 6795 `HM-897D K3`
    (1.948), 6959 `HM-864D K3` (329), 7533 `HM-1696D` (799), 7771
    `HM-1695D` (1.715).
  - 6 DXF tunggal: 6805 `DXF-020100-1291` (1.362 m²), 6811
    `DXF-020100-1297` (1.200), 6812 `DXF-020100-1298` (277), 6913
    `DXF-020100-1399` (589), 7534 `DXF-020100-2020` (398), 8428
    `DXF-020100-2913` (33).
- **11 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  **8.490,00 m²**, seluruhnya `ST_Equals` dengan keeper HM-nya: 12376
  `DXF-020100-1212` ≡ 6726, 12394 `DXF-020100-1237` ≡ 6751, 12401
  `DXF-020100-1247` ≡ 6761, 12414 `DXF-020100-1262` ≡ 6776, 12426
  `DXF-020100-1277` ≡ 6791, 12427 `DXF-020100-1278` ≡ 6792, 12428
  `DXF-020100-1279` ≡ 6793, 12430 `DXF-020100-1281` ≡ 6795, 12505
  `DXF-020100-1445` ≡ 6959, 12795 `DXF-020100-2019` ≡ 7533, 12966
  `DXF-020100-2257` ≡ 7771.

Tidak ada pengecualian di BLOK 15: 6 DXF tunggal tidak memiliki kembaran di
seluruh tabel — termasuk bidang berstatus `dihapus` — dan tidak ada objek
terbuka lain. Satu-satunya bidang `dihapus` yang beririsan adalah batas desa
16118, yang sudah dikecualikan dari perhitungan cakupan.

Audit id **3447** (`objek_id='blok15-kosong-20261108'`). Keadaan akhir BLOK 15:
`tanah` **157** (140 aktif + 17 `perlu_verifikasi`), `lain` **170** (159
`perlu_verifikasi` + 11 `duplikat`); total tetap 327. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.140**, lain **3.807**; status `aktif`
**3.319**, `perlu_verifikasi` **4.495**, `duplikat` **131**, `dihapus` **2**.
Cakupan keeper di luar dirinya maksimum **0,29%**, dan 11/11 kembar tetap
tertutup 100% oleh keeper-nya. Uji API `lapisan=bidang&blok=BLOK 15` menjawab
**316 fitur**: 140 `tanah` aktif, 17 `tanah` `perlu_verifikasi`, 159 `lain`
`perlu_verifikasi` (11 duplikat tersaring); `cari` `HM-836D K3` dan
`DXF-020100-1291` menjawab `tipe: tanah`, `status: perlu_verifikasi`.

Cadangan penuh: `D:\backup\sigpetades\blok15-kosong-20261108\`.
`sebelum.sql` (9.700.538 byte; SHA-256
`c8ab5d21c5fae9d8b3615f8d5a5ebc40521ca174c012a9c699d4ddc72622854c`) diuji
restore ke `sipetades_coret_blok15` — `bidang` 7.947, `hak` 7.945, `blok` 24,
`objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.387; jumlah baris dan
`CHECKSUM TABLE` seluruh tabel sama kecuali `sppt` (checksum fisik), dan isi
`sppt` diverifikasi identik lewat dump `--skip-extended-insert` (3.794 baris,
nol beda). Dump sesudah `sesudah.sql` (9.705.569 byte; SHA-256
`c14287567f0c40deebcf7af59b820036710ef523e30a723d17235c15efc44688`) juga
diuji restore — `audit_log` 3.388, tanah BLOK 15 = 157, duplikat = 11; seluruh
tabel sama kecuali `sppt` (checksum fisik; isi identik). Database uji sudah
dihapus.

### 3.59 Tiga belas bidang kosong BLOK 16 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 16 adalah `blok.id=4` (`nomor=16`), berisi **273 bidang**: 124 `tanah`
aktif, 1 `tanah` `perlu_verifikasi` (5522, 0,02 m²), dan 148 `lain`
`perlu_verifikasi`. Dari 148 objek `lain`, **123 sudah tertutup ≥95%** bidang
tanah sehingga dibiarkan; **25 terbuka** diproses:

- **13 bidang `lain` → `tanah`** (tetap `perlu_verifikasi`), total
  **19.234,02 m²**:
  - 12 HM: 5515 `HM-129C` (790 m²), 5564 `HM-123C` (5.011), 5726
    `HM-229B` (1.854), 6654 `HM-420D K3` (2.120), 6681 `HM-326D K3`
    (2.984), 6685 `HM-328D K3` (322), 6686 `HM-327D K3` (443), 6719
    `HM-333D K3` (795), 6720 `HM-334D K3` (953), 6721 `HM-335D K3` (982),
    6722 `HM-337D K3` (2.483), 6749 `HM-227B` (497).
  - 1 DXF tunggal: 5522 `DXF-020100-0008` (0,02 m²).
- **12 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  **19.234,00 m²**, seluruhnya `ST_Equals` dengan keeper HM-nya: 11536
  `DXF-020100-0001` ≡ 5515, 11568 `DXF-020100-0050` ≡ 5564, 11668
  `DXF-020100-0212` ≡ 5726, 12329 `DXF-020100-1140` ≡ 6654, 12347
  `DXF-020100-1167` ≡ 6681, 12349 `DXF-020100-1171` ≡ 6685, 12350
  `DXF-020100-1172` ≡ 6686, 12371 `DXF-020100-1205` ≡ 6719, 12372
  `DXF-020100-1206` ≡ 6720, 12373 `DXF-020100-1207` ≡ 6721, 12374
  `DXF-020100-1208` ≡ 6722, 12392 `DXF-020100-1235` ≡ 6749.

Tidak ada pengecualian di BLOK 16: DXF tunggal 5522 tidak memiliki kembaran
di seluruh tabel — termasuk bidang berstatus `dihapus` — dan tidak ada objek
terbuka lain. Satu-satunya bidang `dihapus` yang beririsan adalah batas desa
16118, yang sudah dikecualikan dari perhitungan cakupan.

Audit id **3448** (`objek_id='blok16-kosong-20261108'`). Keadaan akhir BLOK 16:
`tanah` **138** (124 aktif + 14 `perlu_verifikasi`), `lain` **135** (123
`perlu_verifikasi` + 12 `duplikat`); total tetap 273. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.153**, lain **3.794**; status `aktif`
**3.319**, `perlu_verifikasi` **4.483**, `duplikat` **143**, `dihapus` **2**.
Cakupan keeper di luar dirinya maksimum **0,61%**, dan 12/12 kembar tetap
tertutup 100% oleh keeper-nya. Uji API `lapisan=bidang&blok=BLOK 16` menjawab
**261 fitur**: 124 `tanah` aktif, 14 `tanah` `perlu_verifikasi`, 123 `lain`
`perlu_verifikasi` (12 duplikat tersaring); `cari` `HM-129C` dan
`DXF-020100-0008` menjawab `tipe: tanah`, `status: perlu_verifikasi`.

Cadangan penuh: `D:\backup\sigpetades\blok16-kosong-20261108\`.
`sebelum.sql` (9.705.569 byte; SHA-256
`c27e55951d792ed8d9fe56477cbdb608502ad0e67bdf8071d8db74ecba5f01fa`) diuji
restore ke `sipetades_coret_blok16` — `bidang` 7.947, `hak` 7.945, `blok` 24,
`objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.388; jumlah baris dan
`CHECKSUM TABLE` seluruh tabel identik, termasuk `sppt`. Dump sesudah
`sesudah.sql` (9.710.018 byte; SHA-256
`f744771d72e2daa5b53a7022f3c2ecd2e757e0755e9fc77af2f11d4ea284153b`) juga
diuji restore — `audit_log` 3.389, tanah BLOK 16 = 138, duplikat = 12; seluruh
tabel sama kecuali `sppt` (checksum fisik), isi `sppt` identik lewat dump
`--skip-extended-insert` (3.794 baris, nol beda). Database uji sudah dihapus.

### 3.60 Dua puluh satu bidang kosong BLOK 17 ditetapkan menjadi tanah (8 Nov 2026)

BLOK 17 adalah `blok.id=21` (`nomor=17`), berisi **200 bidang**: 65 `tanah`
aktif, 26 `tanah` `perlu_verifikasi`, dan 109 `lain` `perlu_verifikasi`. Dari
109 objek `lain`, **81 sudah tertutup ≥95%** bidang tanah sehingga dibiarkan;
**28 terbuka** diproses:

- **21 bidang `lain` → `tanah`** (tetap `perlu_verifikasi`), total
  **17.338,85 m²**:
  - 7 HM: 5526 `HM-222C` (531 m²), 7174 `HM-A142` (604), 7175 `HM-B336`
    (552), 7197 `HM-1141D K3` (1.247), 7205 `HM-1163D K3` (401), 7206
    `HM-A12` (396), 7536 `HM-1189D K3` (1.949).
  - 14 DXF tunggal: 7177 `DXF-020100-1663` (2.285 m²), 8116
    `DXF-020100-2602` (1.638,36), 8135 `DXF-020100-2621` (1.247,06), 8164
    `DXF-020100-2650` (845,50), 8168 `DXF-020100-2654` (807), 8170
    `DXF-020100-2656` (792,03), 8203 `DXF-020100-2689` (610,70), 8205
    `DXF-020100-2691` (601,03), 8212 `DXF-020100-2698` (573,80), 8217
    `DXF-020100-2703` (542,63), 8228 `DXF-020100-2714` (454,58), 8230
    `DXF-020100-2716` (446,59), 8232 `DXF-020100-2718` (433,37), 8237
    `DXF-020100-2723` (381,20).
- **7 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  **5.680,00 m²**, seluruhnya `ST_Equals` dengan keeper HM-nya: 11542
  `DXF-020100-0012` ≡ 5526, 12626 `DXF-020100-1660` ≡ 7174, 12627
  `DXF-020100-1661` ≡ 7175, 12634 `DXF-020100-1683` ≡ 7197, 12637
  `DXF-020100-1691` ≡ 7205, 12638 `DXF-020100-1692` ≡ 7206, 12797
  `DXF-020100-2022` ≡ 7536.

Tidak ada pengecualian di BLOK 17: 14 DXF tunggal tidak memiliki kembaran di
seluruh tabel — termasuk bidang berstatus `dihapus` — dan tidak ada objek
terbuka lain. Satu-satunya bidang `dihapus` yang beririsan adalah batas desa
16118, yang sudah dikecualikan dari perhitungan cakupan.

Audit id **3449** (`objek_id='blok17-kosong-20261108'`). Keadaan akhir BLOK 17:
`tanah` **112** (65 aktif + 47 `perlu_verifikasi`), `lain` **88** (81
`perlu_verifikasi` + 7 `duplikat`); total tetap 200. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.174**, lain **3.773**; status `aktif`
**3.319**, `perlu_verifikasi` **4.476**, `duplikat` **150**, `dihapus` **2**.
Cakupan keeper di luar dirinya maksimum **0,30%**, dan 7/7 kembar tetap
tertutup 100% oleh keeper-nya. Uji API `lapisan=bidang&blok=BLOK 17` menjawab
**193 fitur**: 65 `tanah` aktif, 47 `tanah` `perlu_verifikasi`, 81 `lain`
`perlu_verifikasi` (7 duplikat tersaring); `cari` `HM-222C` dan
`DXF-020100-1663` menjawab `tipe: tanah`, `status: perlu_verifikasi`.

Cadangan penuh: `D:\backup\sigpetades\blok17-kosong-20261108\`.
`sebelum.sql` (9.710.018 byte; SHA-256
`90c39dd5eb28dfac658b23676f6f0222d71b23a7cd38a55614f4abc363bd486f`) diuji
restore ke `sipetades_coret_blok17` — `bidang` 7.947, `hak` 7.945, `blok` 24,
`objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.389; jumlah baris dan
`CHECKSUM TABLE` seluruh tabel sama kecuali `sppt` (checksum fisik), isi
`sppt` identik lewat dump `--skip-extended-insert` (3.794 baris, nol beda).
Dump sesudah `sesudah.sql` (9.715.147 byte; SHA-256
`32ca18b54f823f51b23c12c00ca530e9435c028fd645809db62c2483bf3e8b45`) juga
diuji restore — `audit_log` 3.390, tanah BLOK 17 = 112, duplikat = 7; jumlah
baris dan checksum **seluruh** tabel identik dengan live, termasuk `sppt`.
Database uji sudah dihapus.

### 3.61 Sisa 81 bidang lain BLOK 17 dituntaskan: 42 menjadi tanah dan 39 duplikat (9 Nov 2026)

BLOK 17 (`blok.id=21`) masih menyimpan 81 bidang `lain`/`perlu_verifikasi`
yang semuanya sudah tercakup ≥95% bidang tanah (minimum 98,12%). Atas
permintaan pengguna, seluruh 81 dituntaskan tanpa memunculkan bidang tanah
kembar persis:

- **42 bidang → `tipe='tanah'`** (status tetap `perlu_verifikasi`), total
  **49.830,75 m²**:
  - 29 HM: 5584 `HM-333B` (518,07 m²), 5755 `HM-1127D K3` (227,21), 6366
    `HM-160B` (2.306,38), 6557 `HM-920D K3` (1.455,37), 7176 `HM-155B`
    (1.206,81), 7179 `HM-A234` (348,63), 7180 `HM-88B` (513,35), 7181
    `HM-C14` (2.136,44), 7196 `HM-158B` (138,80), 7207 `HM-B56` (1.130,41),
    7501 `HM-153B` (876,48), 7502 `HM-1174D K3` (1.113,83), 7503
    `HM-1173D K3` (1.120,07), 7526 `HM-1169D K3` (629,83), 7537
    `HM-1190D K3` (2.814,20), 7538 `HM-1191D K3` (2.480,70), 7543
    `HM-1132D K3` (385,17), 7544 `HM-1133D K3` (336,84), 7545
    `HM-1135D K3` (224,09), 7546 `HM-1136D K3` (243,12), 7547
    `HM-1137D K3` (270,46), 7548 `HM-1138D K3` (212,63), 7549
    `HM-1128D K3` (699,57), 7550 `HM-1129D K3` (823,74), 7552
    `HM-1125D K3` (1.421,53), 7553 `HM-1126D K3` (1.598,62), 7556
    `HM-1121D K3` (5.629,69), 7663 `HM-1124D K3` (559,12), 7664
    `HM-1122D K3` (706,93).
  - 13 DXF tunggal: 5562 `DXF-020100-0048` (11.420,95 m²), 6360
    `DXF-020100-0846` (523,39), 7178 `DXF-020100-1664` (193,51), 7194
    `DXF-020100-1680` (309,00), 7195 `DXF-020100-1681` (117,13), 7208
    `DXF-020100-1694` (1.116,62), 7209 `DXF-020100-1695` (101,77), 7542
    `DXF-020100-2028` (469,43), 7551 `DXF-020100-2037` (594,78), 7665
    `DXF-020100-2151` (1.550,30), 7666 `DXF-020100-2152` (647,67), 8231
    `DXF-020100-2717` (450,74), 8264 `DXF-020100-2749` (207,37).
- **39 bidang → `status='duplikat'`** (`tipe` tetap `lain`), total
  **43.717,55 m²**:
  - 29 kembar DXF persis dari keeper HM di atas (masing-masing `ST_Equals`):
    11585 `DXF-020100-0070` ≡ 5584, 11681 `DXF-020100-0241` ≡ 5755, 12099
    `DXF-020100-0852` ≡ 6366, 12244 `DXF-020100-1043` ≡ 6557, 12628
    `DXF-020100-1662` ≡ 7176, 12629 `DXF-020100-1665` ≡ 7179, 12630
    `DXF-020100-1666` ≡ 7180, 12631 `DXF-020100-1667` ≡ 7181, 12633
    `DXF-020100-1682` ≡ 7196, 12639 `DXF-020100-1693` ≡ 7207, 12784
    `DXF-020100-1987` ≡ 7501, 12785 `DXF-020100-1988` ≡ 7502, 12786
    `DXF-020100-1989` ≡ 7503, 12790 `DXF-020100-2012` ≡ 7526, 12798
    `DXF-020100-2023` ≡ 7537, 12799 `DXF-020100-2024` ≡ 7538, 12802
    `DXF-020100-2029` ≡ 7543, 12803 `DXF-020100-2030` ≡ 7544, 12804
    `DXF-020100-2031` ≡ 7545, 12805 `DXF-020100-2032` ≡ 7546, 12806
    `DXF-020100-2033` ≡ 7547, 12807 `DXF-020100-2034` ≡ 7548, 12808
    `DXF-020100-2035` ≡ 7549, 12809 `DXF-020100-2036` ≡ 7550, 12810
    `DXF-020100-2038` ≡ 7552, 12811 `DXF-020100-2039` ≡ 7553, 12812
    `DXF-020100-2042` ≡ 7556, 12877 `DXF-020100-2149` ≡ 7663, 12878
    `DXF-020100-2150` ≡ 7664.
  - 10 DXF yang kembar persis dengan bidang `tanah` lama: 8095
    `DXF-020100-2581` ≡ 22799 `DXF-020100-G03236` (3.113,16 m²), 8096
    `DXF-020100-2582` ≡ 21507 `G00586` (3.024,37), 8133 `DXF-020100-2619`
    ≡ 21807 `G01024` (1.324,31), 8159 `DXF-020100-2645` ≡ 21468 `G00506`
    dan 22953 `G03411` (909,40), 8186 `DXF-020100-2672` ≡ 22801 `G03241`
    (740,68), 8188 `DXF-020100-2674` ≡ 22755 `G03186` (731,05), 8214
    `DXF-020100-2700` ≡ 21503 `G00578` (562,84), 8223 `DXF-020100-2709` ≡
    21804 `G01018` (509,59), 8234 `DXF-020100-2720` ≡ 22925 `G03383`
    (422,70), 8254 `DXF-020100-2739` ≡ 22954 `G03412` (251,36).

Poligon batas 22968 `DXF-BATAS-BLOK-00050` (`lain`/`aktif`) tidak disentuh.
Tidak ada bidang tanah kembar persis yang lahir dari langkah ini: 46/46
`duplikat` BLOK 17 tertutup 100% oleh bidang tanah.

Audit id **3454** (`objek_id='blok17-lain-20261109'`). Keadaan akhir BLOK 17:
`tanah` **154** (65 aktif + 89 `perlu_verifikasi`), `lain` **47** (46
`duplikat` + 1 batas blok aktif); 201 baris termasuk poligon batas (hitungan
200 pada §3.60 tidak memuat poligon itu). Global: `bidang` **7.947**, `hak`
**7.945**, tanah **4.216** (3.319 aktif + 895 `perlu_verifikasi` + 2
`dihapus`), lain **3.731** (1 aktif + 3.541 `perlu_verifikasi` + 189
`duplikat`); status `aktif` **3.320**, `perlu_verifikasi` **4.436**,
`duplikat` **189**, `dihapus` **2**. Uji API `lapisan=bidang&blok=BLOK 17`
menjawab **155 fitur**: 65 `tanah` aktif, 89 `tanah` `perlu_verifikasi`, dan
poligon batas `lain` aktif; 46 duplikat tersaring. `HM-333B` dan
`DXF-020100-1664` menjawab `tipe: tanah`, sedangkan `DXF-020100-2581`
tersaring sebagai duplikat.

Cadangan penuh: `D:\backup\sigpetades\blok17-lain-20261109\`.
`sebelum.sql` (9.716.890 byte; SHA-256
`516df8a83b777a1c338b967dde0c41498b45d5c7cc157fa6ce641a068a4e8fc1`) diuji
restore ke `sipetades_coret_b17sisa` — `bidang` 7.947, `hak` 7.945, `blok` 24,
`objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.394; jumlah baris dan
`CHECKSUM TABLE` seluruh tabel sama kecuali `sppt` (checksum fisik), isi
`sppt` identik lewat dump `--skip-extended-insert` (nol beda). Dump sesudah
`sesudah.sql` (9.730.903 byte; SHA-256
`6db6f6e2a51b87478446d14db1dba7096ce25b94ed69ec6238f7bd1390ec72dd`) juga
diuji restore ke `sipetades_coret_b17sisa2` — `audit_log` 3.395, tanah BLOK
17 = 154, duplikat = 46, audit 3454 ada; baris dan checksum sama (kecuali
checksum fisik `sppt`, isi identik). Kedua database uji sudah dihapus.

### 3.62 Dua bidang kosong BLOK 26 ditetapkan menjadi tanah (9 Nov 2026)

BLOK 26 adalah `blok.id=15`, berisi **284 bidang**: 203 `tanah` aktif, 3
`tanah` `perlu_verifikasi`, dan 78 `lain` `perlu_verifikasi`. Dari 78 objek
`lain`, **74 sudah tertutup ≥95%** bidang tanah; **4 terbuka** (2 pasang
HM↔DXF) diproses:

- **2 bidang `lain` → `tanah`** (tetap `perlu_verifikasi`), total
  **3.788,19 m²**: 7600 `HM-1499D K3` (2.987,63 m²) dan 7608 `HM-1108D`
  (800,56 m²).
- **2 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  **3.788,20 m²**, keduanya `ST_Equals` dengan keeper HM-nya: 12838
  `DXF-020100-2086` ≡ 7600 dan 12846 `DXF-020100-2094` ≡ 7608.

Tidak ada pengecualian: tidak ada objek terbuka yang kembar dengan bidang
`dihapus`, dan kedua pasangan tidak punya kembaran lain di seluruh tabel.

Audit id **3455** (`objek_id='blok26-kosong-20261109'`). Keadaan akhir BLOK 26:
`tanah` **208** (203 aktif + 5 `perlu_verifikasi`), `lain` **76** (74
`perlu_verifikasi` + 2 `duplikat`); total tetap 284. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.218**, lain **3.729**; status `aktif`
**3.320**, `perlu_verifikasi` **4.434**, `duplikat` **191**, `dihapus` **2**.
Kedua kembar tertutup 100% oleh keeper-nya. Uji API
`lapisan=bidang&blok=BLOK 26` menjawab **282 fitur**: 203 `tanah` aktif, 5
`tanah` `perlu_verifikasi`, 74 `lain` `perlu_verifikasi` (2 duplikat
tersaring); `HM-1499D K3` dan `HM-1108D` menjawab `tipe: tanah`.

Cadangan penuh: `D:\backup\sigpetades\blok26-kosong-20261109\`.
`sebelum.sql` (9.730.903 byte; SHA-256
`447d1c9f6e1121c0b791b14b994363f57625033f47c2285271a5ad50aed8907e`) diuji
restore ke `sipetades_coret_b26` — `bidang` 7.947, `hak` 7.945, `blok` 24,
`objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.395; jumlah baris dan
`CHECKSUM TABLE` seluruh tabel sama kecuali `sppt` (checksum fisik), isi
`sppt` identik lewat dump `--skip-extended-insert` (nol beda). Dump sesudah
`sesudah.sql` (9.732.015 byte; SHA-256
`41a817170754dcd362977f8c120f3ee4e29a5b83c73d086c332f829f6500a8c2`) juga
diuji restore ke `sipetades_coret_b26b` — `audit_log` 3.396, tanah BLOK 26 =
208, duplikat = 2, audit 3455 ada; baris dan checksum sama (kecuali checksum
fisik `sppt`, isi identik). Kedua database uji sudah dihapus.

### 3.63 Sisa 74 bidang lain BLOK 26 dituntaskan: 45 menjadi tanah dan 29 duplikat (9 Nov 2026)

BLOK 26 (`blok.id=15`) masih menyimpan 74 bidang `lain`/`perlu_verifikasi`
yang semuanya tercakup 98,68–100% oleh bidang `tanah` seri
`DXF-020100-G…`. Atas permintaan pengguna, seluruh 74 dituntaskan:

- **45 bidang → `tipe='tanah'`** (status tetap `perlu_verifikasi`), total
  **131.488,04 m²**:
  - 28 HM (70.860,57 m²): 5537 `HM-A246` (3.742,07 m²), 5550 `HM-A239`
    (3.665,43), 5599 `HM-A10` (3.340,53), 5687 `HM-A17` (1.024,60), 5688
    `HM-A18` (884,23), 5689 `HM-A213` (2.157,48), 5690 `HM-A3` (1.236,13),
    5696 `HM-B316` (1.648,29), 5697 `HM-B318` (1.313,09), 5765 `HM-150B`
    (896,29), 7121 `HM-218C` (1.003,54), 7601 `HM-1498D K3` (2.150,27),
    7602 `HM-1497D K3` (3.247,55), 7603 `HM-1496D K3` (2.946,27), 7604
    `HM-1501D K3` (1.362,13), 7605 `HM-1500D K3` (4.010,25), 7606
    `HM-1106D` (4.814,75), 7607 `HM-1107D` (3.436,98), 7610 `HM-1103D`
    (1.101,49), 7611 `HM-1110D` (963,16), 7612 `HM-1104D` (10.180,64),
    7615 `HM-1098D` (1.288,99), 7616 `HM-1093D` (1.687,01), 7617
    `HM-1095D` (2.845,02), 7618 `HM-1094D` (2.077,18), 7946 `HM-1097D`
    (1.417,20), 7947 `HM-1096D` (3.283,59), 7967 `HM-1092D` (3.136,40).
  - 17 DXF tunggal (60.627,47 m²): 5536 `DXF-020100-0022` (543,14 m²), 5680
    `DXF-020100-0166` (2.040,28), 5698 `DXF-020100-0184` (1.339,10), 7000
    `DXF-020100-1486` (115,54), 7609 `DXF-020100-2095` (1.764,59), 7613
    `DXF-020100-2099` (532,16), 7614 `DXF-020100-2100` (1.377,45), 7619
    `DXF-020100-2105` (1.592,29), 7620 `DXF-020100-2106` (1.353,57), 7667
    `DXF-020100-2153` (3.436,77), 8065 `DXF-020100-2551` (437,04), 8066
    `DXF-020100-2552` (399,34), 8087 `DXF-020100-2573` (45.147,89), 8261
    `DXF-020100-2746` (217,79), 8302 `DXF-020100-2787` (112,72), 8303
    `DXF-020100-2788` (110,50), 8309 `DXF-020100-2794` (107,31).
- **29 bidang → `status='duplikat'`** (`tipe` tetap `lain`), total
  **70.981,15 m²**:
  - 28 kembar DXF persis dari keeper HM di atas (masing-masing `ST_Equals`):
    11550 `DXF-020100-0023` ≡ 5537, 11558 `DXF-020100-0036` ≡ 5550, 11598
    `DXF-020100-0085` ≡ 5599, 11640 `DXF-020100-0173` ≡ 5687, 11641
    `DXF-020100-0174` ≡ 5688, 11642 `DXF-020100-0175` ≡ 5689, 11643
    `DXF-020100-0176` ≡ 5690, 11648 `DXF-020100-0182` ≡ 5696, 11649
    `DXF-020100-0183` ≡ 5697, 11688 `DXF-020100-0251` ≡ 5765, 12602
    `DXF-020100-1607` ≡ 7121, 12839 `DXF-020100-2087` ≡ 7601, 12840
    `DXF-020100-2088` ≡ 7602, 12841 `DXF-020100-2089` ≡ 7603, 12842
    `DXF-020100-2090` ≡ 7604, 12843 `DXF-020100-2091` ≡ 7605, 12844
    `DXF-020100-2092` ≡ 7606, 12845 `DXF-020100-2093` ≡ 7607, 12847
    `DXF-020100-2096` ≡ 7610, 12848 `DXF-020100-2097` ≡ 7611, 12849
    `DXF-020100-2098` ≡ 7612, 12850 `DXF-020100-2101` ≡ 7615, 12851
    `DXF-020100-2102` ≡ 7616, 12852 `DXF-020100-2103` ≡ 7617, 12853
    `DXF-020100-2104` ≡ 7618, 13086 `DXF-020100-2432` ≡ 7946, 13087
    `DXF-020100-2433` ≡ 7947, 13098 `DXF-020100-2453` ≡ 7967.
  - 1 DXF kembar bidang tanah lama: 8297 `DXF-020100-2782` ≡ 22748
    `DXF-020100-G03159` (`tanah`/`aktif`, 120,58 m²).

Tidak ada pengecualian bidang `dihapus`: tidak satu pun dari 74 objek kembar
dengan bidang berstatus `dihapus`, dan 31/31 `duplikat` BLOK 26 tertutup
100% oleh bidang tanah.

Audit id **3457** (`objek_id='blok26-lain-20261109'`). Keadaan akhir BLOK 26:
`tanah` **253** (203 aktif 208.823,96 m² + 50 `perlu_verifikasi` 165.536,46
m²), `lain` **31** (semua `duplikat`, 74.769,34 m²); total tetap 284.
Global: `bidang` **7.947**, `hak` **7.945**, tanah **4.263** (3.319 aktif +
942 `perlu_verifikasi` + 2 `dihapus`), lain **3.684** (1 aktif + 3.463
`perlu_verifikasi` + 220 `duplikat`); status `aktif` **3.320**,
`perlu_verifikasi` **4.405**, `duplikat` **220**, `dihapus` **2**. Uji API
`lapisan=bidang&blok=BLOK 26` menjawab **253 fitur** (203 `tanah` aktif + 50
`tanah` `perlu_verifikasi`); 31 duplikat tersaring. `HM-A246`, `HM-1092D`,
`DXF-020100-0022`, dan `DXF-020100-2573` menjawab `tipe: tanah`, sedangkan
`DXF-020100-0023` dan `DXF-020100-2782` tersaring sebagai duplikat.

Cadangan penuh: `D:\backup\sigpetades\blok26-lain-20261109\`.
`sebelum.sql` (9.732.015 byte; SHA-256
`2b141e4f6e52f968fac2148dd76955356d1ddd2192c6cde9357aa924b1d691e8`) diuji
restore ke `sipetades_coret_b26c` — seluruh 18 tabel jumlah baris dan
`CHECKSUM TABLE` sama termasuk `sppt`, isi `sppt` identik 3.794 baris lewat
dump `--skip-extended-insert` (nol beda); `audit_log` 3.397. Dump sesudah
`sesudah.sql` (9.745.017 byte; SHA-256
`30f58be8f1f428ba003bf0e68f90361014320d3a84e7eed8f97c170031e6c1d9`) diuji
restore ke `sipetades_coret_b26d` — `audit_log` 3.398, audit 3457 ada, tanah
BLOK 26 = 253, duplikat = 31; baris dan checksum sama termasuk `sppt`. Kedua
database uji sudah dihapus.

### 3.64 Sisa objek terbuka BLOK 25 dituntaskan: 45 menjadi tanah dan 13 duplikat (9 Nov 2026)

BLOK 25 (`blok.id=7`) berisi 393 bidang: 181 `tanah` aktif, 3 `tanah`
`perlu_verifikasi`, dan 209 `lain` (`perlu_verifikasi`, tanpa duplikat). Dari
209 objek `lain`, **154 sudah tertutup ≥95%** bidang tanah; **58 terbuka**
diproses:

- **45 bidang `lain` → `tanah`** (status tetap `perlu_verifikasi`), total
  **50.003,59 m²**:
  - 13 HM: 7075 `HM-1484D K3` (895,16 m²), 7166 `HM-C33` (647,14), 7199
    `HM-A21` (1.115,45), 7200 `HM-A229` (564,50), 7210 `HM-A240` (3.728,17),
    7241 `HM-126B` (880,47), 7539 `HM-1401D K3` (1.033,20), 7569
    `HM-1485D K3` (468,80), 7583 `HM-1463D K3` (557,51), 7586
    `HM-1466D K3` (2.416,57), 7587 `HM-1451D K3` (2.297,83), 7596
    `HM-1476D K3` (719,02), 8085 `HM-1486D K3` (296,93).
  - 32 DXF tunggal: 5538 `DXF-020100-0024` (566,92), 5727
    `DXF-020100-0213` (721,94), 5728 `DXF-020100-0214` (266,59), 7076
    `DXF-020100-1562` (1.166,87), 7162 `DXF-020100-1648` (2.318,52), 7163
    `DXF-020100-1649` (1.269,07), 7164 `DXF-020100-1650` (621,04), 7167
    `DXF-020100-1653` (992,37), 7187 `DXF-020100-1673` (198,65), 7201
    `DXF-020100-1687` (807,56), 7557 `DXF-020100-2043` (121,07), 7567
    `DXF-020100-2053` (871,97), 7570 `DXF-020100-2056` (451,52), 7579
    `DXF-020100-2065` (506,53), 7594 `DXF-020100-2080` (1.787,19), 7595
    `DXF-020100-2081` (246,77), 8061 `DXF-020100-2547` (2.068,12), 8093
    `DXF-020100-2579` (4.265,28), 8103 `DXF-020100-2589` (2.043,88), 8110
    `DXF-020100-2596` (1.786,39), 8120 `DXF-020100-2606` (1.514,55), 8122
    `DXF-020100-2608` (1.504,06), 8130 `DXF-020100-2616` (1.377,39), 8144
    `DXF-020100-2630` (1.091,31), 8172 `DXF-020100-2658` (795,61), 8173
    `DXF-020100-2659` (789,93), 8178 `DXF-020100-2664` (775,22), 8179
    `DXF-020100-2665` (774,18), 8190 `DXF-020100-2676` (721,43), 8192
    `DXF-020100-2678` (712,58), 8196 `DXF-020100-2682` (660,90), 8209
    `DXF-020100-2695` (587,43).
- **13 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  **15.620,76 m²**, semuanya `ST_Equals` dengan keeper HM-nya: 12595
  `DXF-020100-1561` ≡ 7075, 12621 `DXF-020100-1652` ≡ 7166, 12635
  `DXF-020100-1685` ≡ 7199, 12636 `DXF-020100-1686` ≡ 7200, 12640
  `DXF-020100-1696` ≡ 7210, 12643 `DXF-020100-1727` ≡ 7241, 12800
  `DXF-020100-2025` ≡ 7539, 12816 `DXF-020100-2055` ≡ 7569, 12825
  `DXF-020100-2069` ≡ 7583, 12828 `DXF-020100-2072` ≡ 7586, 12829
  `DXF-020100-2073` ≡ 7587, 12835 `DXF-020100-2082` ≡ 7596, 13117
  `DXF-020100-2571` ≡ 8085.

Tidak ada pengecualian: tidak ada objek terbuka yang kembar dengan bidang
`dihapus`, dan tidak ada kembar lain di luar 58 objek terbuka. 13/13
`duplikat` tertutup 100% oleh keeper-nya; 154 objek `lain` yang sudah
tercakup tidak diubah.

Audit id **3458** (`objek_id='blok25-kosong-20261109'`). Keadaan akhir BLOK 25:
`tanah` **226** (180 aktif + 46 `perlu_verifikasi`), `lain` **167** (154
`perlu_verifikasi` + 13 `duplikat`); total tetap 393. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.308** (3.319 aktif + 987
`perlu_verifikasi` + 2 `dihapus`), lain **3.639** (1 aktif + 3.405
`perlu_verifikasi` + 233 `duplikat`); status `aktif` **3.320**,
`perlu_verifikasi` **4.392**, `duplikat` **233**, `dihapus` **2**. Uji API
`lapisan=bidang&blok=BLOK 25` menjawab **380 fitur** (180 `tanah` aktif + 46
`tanah` `perlu_verifikasi` + 154 `lain` `perlu_verifikasi`); 13 duplikat
tersaring. `HM-A240` dan `DXF-020100-0024` menjawab `tipe: tanah`, sedangkan
`DXF-020100-1561` dan `DXF-020100-2571` tersaring sebagai duplikat.

Cadangan penuh: `D:\backup\sigpetades\blok25-kosong-20261109\`.
`sebelum.sql` (9.745.017 byte; SHA-256
`aee9ddf3b8e029b5f9e33e25c0f8d7254fd9bba1eeac684eb041218ce0e4beec`) diuji
restore ke `sipetades_coret_b25` — seluruh 18 tabel jumlah baris dan
`CHECKSUM TABLE` sama termasuk `sppt`, isi `sppt` identik 3.794 baris lewat
dump `--skip-extended-insert` (nol beda); `audit_log` 3.396. Dump sesudah
`sesudah.sql` (9.755.434 byte; SHA-256
`49362217e56bbcba6a9575842b92c082ad0b2ae95a698b22d4818149879007ed`) diuji
restore ke `sipetades_coret_b25b` — `audit_log` 3.399, audit 3458 ada, tanah
BLOK 25 = 226, duplikat = 13; baris dan checksum sama termasuk `sppt`. Kedua
database uji sudah dihapus.

### 3.65 Sisa objek terbuka BLOK 23 dituntaskan: 32 menjadi tanah dan 24 duplikat (9 Nov 2026)

BLOK 23 (`blok.id=25`) berisi 423 bidang: 90 `tanah` aktif, 123 `tanah`
`perlu_verifikasi`, dan 210 `lain` (`perlu_verifikasi`, tanpa duplikat). Dari
210 objek `lain`, **154 sudah tertutup ≥95%** bidang tanah; **56 terbuka**
diproses:

- **32 bidang `lain` → `tanah`** (status tetap `perlu_verifikasi`), total
  **46.316,50 m²**:
  - 24 HM: 5532 `HM-A118` (1.647,39 m²), 5533 `HM-A117` (1.636,30), 5665
    `HM-136B` (847,99), 5668 `HM-250B` (1.679,08), 5684 `HM-124B`
    (1.004,94), 5685 `HM-248B` (1.005,06), 6234 `HM-232D` (1.712,34), 6256
    `HM-227D` (2.365,19), 7019 `HM-A11` (2.696,40), 7021 `HM-A9` (718,38),
    7022 `HM-A77` (221,33), 7631 `HM-1054D` (1.355,67), 7632 `HM-1053D`
    (912,14), 7633 `HM-1052D` (2.240,29), 7635 `HM-1061D` (2.988,31), 7638
    `HM-1058D` (625,33), 7641 `HM-1085D` (721,13), 7643 `HM-1055D`
    (2.160,75), 7658 `HM-1049D` (2.929,11), 7661 `HM-1081D` (2.441,21),
    7662 `HM-1082D` (1.847,02), 7672 `HM-1077D` (3.299,43), 7674
    `HM-1117D` (2.233,05), 7678 `HM-1112D` (935,68).
  - 8 DXF tunggal: 5667 `DXF-020100-0153` (2.009,99), 7636
    `DXF-020100-2122` (1.476,43), 7640 `DXF-020100-2126` (726,58), 7655
    `DXF-020100-2141` (501,86), 7660 `DXF-020100-2146` (410,10), 7677
    `DXF-020100-2163` (299,79), 8079 `DXF-020100-2565` (186,99), 8225
    `DXF-020100-2711` (481,24).
- **24 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  **40.223,52 m²**, semuanya `ST_Equals` dengan keeper HM-nya: 11547
  `DXF-020100-0018` ≡ 5532, 11548 `DXF-020100-0019` ≡ 5533, 11625
  `DXF-020100-0151` ≡ 5665, 11627 `DXF-020100-0154` ≡ 5668, 11638
  `DXF-020100-0170` ≡ 5684, 11639 `DXF-020100-0171` ≡ 5685, 12019
  `DXF-020100-0720` ≡ 6234, 12035 `DXF-020100-0742` ≡ 6256, 12549
  `DXF-020100-1505` ≡ 7019, 12551 `DXF-020100-1507` ≡ 7021, 12552
  `DXF-020100-1508` ≡ 7022, 12859 `DXF-020100-2117` ≡ 7631, 12860
  `DXF-020100-2118` ≡ 7632, 12861 `DXF-020100-2119` ≡ 7633, 12863
  `DXF-020100-2121` ≡ 7635, 12864 `DXF-020100-2124` ≡ 7638, 12865
  `DXF-020100-2127` ≡ 7641, 12866 `DXF-020100-2129` ≡ 7643, 12874
  `DXF-020100-2144` ≡ 7658, 12875 `DXF-020100-2147` ≡ 7661, 12876
  `DXF-020100-2148` ≡ 7662, 12883 `DXF-020100-2158` ≡ 7672, 12885
  `DXF-020100-2160` ≡ 7674, 12888 `DXF-020100-2164` ≡ 7678.

Tidak ada pengecualian: tidak ada objek terbuka yang kembar dengan bidang
`dihapus`, dan tidak ada kembar lain di luar 56 objek terbuka. 24/24
`duplikat` tertutup 100% oleh keeper-nya; 154 objek `lain` yang sudah
tercakup tidak diubah.

Audit id **3459** (`objek_id='blok23-kosong-20261109'`). Keadaan akhir BLOK 23:
`tanah` **245** (90 aktif + 155 `perlu_verifikasi`), `lain` **178** (154
`perlu_verifikasi` + 24 `duplikat`); total tetap 423. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.340** (3.319 aktif + 1.019
`perlu_verifikasi` + 2 `dihapus`), lain **3.607** (1 aktif + 3.349
`perlu_verifikasi` + 257 `duplikat`); status `aktif` **3.320**,
`perlu_verifikasi` **4.368**, `duplikat` **257**, `dihapus` **2**. Uji API
`lapisan=bidang&blok=BLOK 23` menjawab **399 fitur** (90 `tanah` aktif + 155
`tanah` `perlu_verifikasi` + 154 `lain` `perlu_verifikasi`); 24 duplikat
tersaring. `HM-A118`, `HM-1054D`, `DXF-020100-0153`, dan `DXF-020100-2711`
menjawab `tipe: tanah`, sedangkan `DXF-020100-0018` dan `DXF-020100-2164`
tersaring sebagai duplikat.

Cadangan penuh: `D:\backup\sigpetades\blok23-kosong-20261109\`.
`sebelum.sql` (9.755.434 byte; SHA-256
`3ef93147ef2be55ed5086d43ba246304bf9104338f33d4b1ee05c2dd2f7a7198`) diuji
restore ke `sipetades_coret_b23` — seluruh 18 tabel jumlah baris dan
`CHECKSUM TABLE` sama termasuk `sppt`, isi `sppt` identik 3.794 baris;
`audit_log` 3.399. Dump sesudah `sesudah.sql` (9.765.094 byte; SHA-256
`4a687f430aaf4cb9b66d29774af9ebfc0258d860dbf21d6a64b229038cf0eb10`) diuji
restore ke `sipetades_coret_b23b` — `audit_log` 3.400, audit 3459 ada, tanah
BLOK 23 = 245, duplikat = 24; baris dan checksum sama kecuali checksum fisik
`sppt`, isi `sppt` identik 3.794 baris lewat dump `--skip-extended-insert`
(nol beda). Kedua database uji sudah dihapus.

### 3.66 Sisa objek terbuka BLOK 19 dituntaskan: 22 menjadi tanah dan 19 duplikat (9 Nov 2026)

BLOK 19 (`blok.id=22`) berisi 274 bidang: 67 `tanah` aktif, 15 `tanah`
`perlu_verifikasi`, dan 192 `lain` (`perlu_verifikasi`, tanpa duplikat). Dari
192 objek `lain`, **151 sudah tertutup ≥95%** bidang tanah; **41 terbuka**
diproses:

- **22 bidang `lain` → `tanah`** (status tetap `perlu_verifikasi`), total
  **24.822,41 m²**:
  - 19 HM: 5585 `HM-145C` (598,75 m²), 5692 `HM-B42` (798,38), 7688
    `HM-1783D` (2.393,58), 7689 `HM-1781D` (1.435,10), 7691 `HM-1777D`
    (833,77), 7693 `HM-1782D` (1.284,66), 7695 `HM-1774D` (627,55), 7701
    `HM-1779D` (2.496,26), 7709 `HM-1792D` (1.303,15), 7720 `HM-1846D`
    (947,92), 7721 `HM-1847D` (1.030,03), 7722 `HM-1848D` (1.339,94), 7748
    `HM-1862D` (537,92), 7749 `HM-1867D` (934,56), 7750 `HM-1898D`
    (535,65), 7759 `HM-1860D` (1.669,43), 7760 `HM-1859D` (765,81), 7940
    `HM-1881D` (581,52), 7942 `HM-1799D` (2.355,00).
  - 3 DXF tunggal: 7694 `DXF-020100-2180` (580,97), 7696 `DXF-020100-2182`
    (564,07), 7699 `DXF-020100-2185` (1.208,39).
- **19 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  **22.468,98 m²**, semuanya `ST_Equals` dengan keeper HM-nya: 11586
  `DXF-020100-0071` ≡ 5585, 11645 `DXF-020100-0178` ≡ 5692, 12893
  `DXF-020100-2174` ≡ 7688, 12894 `DXF-020100-2175` ≡ 7689, 12896
  `DXF-020100-2177` ≡ 7691, 12897 `DXF-020100-2179` ≡ 7693, 12898
  `DXF-020100-2181` ≡ 7695, 12901 `DXF-020100-2187` ≡ 7701, 12909
  `DXF-020100-2195` ≡ 7709, 12920 `DXF-020100-2206` ≡ 7720, 12921
  `DXF-020100-2207` ≡ 7721, 12922 `DXF-020100-2208` ≡ 7722, 12947
  `DXF-020100-2234` ≡ 7748, 12948 `DXF-020100-2235` ≡ 7749, 12949
  `DXF-020100-2236` ≡ 7750, 12957 `DXF-020100-2245` ≡ 7759, 12958
  `DXF-020100-2246` ≡ 7760, 13080 `DXF-020100-2426` ≡ 7940, 13082
  `DXF-020100-2428` ≡ 7942.

Tidak ada pengecualian: tidak ada objek terbuka yang kembar dengan bidang
`dihapus`, dan tidak ada kembar lain di luar 41 objek terbuka. 19/19
`duplikat` tertutup 100% oleh keeper-nya; 151 objek `lain` yang sudah
tercakup tidak diubah.

Audit id **3460** (`objek_id='blok19-kosong-20261109'`). Keadaan akhir BLOK 19:
`tanah` **104** (67 aktif + 37 `perlu_verifikasi`), `lain` **170** (151
`perlu_verifikasi` + 19 `duplikat`); total tetap 274. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.362** (3.319 aktif + 1.041
`perlu_verifikasi` + 2 `dihapus`), lain **3.585** (1 aktif + 3.308
`perlu_verifikasi` + 276 `duplikat`); status `aktif` **3.320**,
`perlu_verifikasi` **4.349**, `duplikat` **276**, `dihapus` **2**. Uji API
`lapisan=bidang&blok=BLOK 19` menjawab **255 fitur** (67 `tanah` aktif + 37
`tanah` `perlu_verifikasi` + 151 `lain` `perlu_verifikasi`); 19 duplikat
tersaring. `HM-145C`, `HM-1783D`, `DXF-020100-2180`, dan `DXF-020100-2185`
menjawab `tipe: tanah`, sedangkan `DXF-020100-0071` dan `DXF-020100-2428`
tersaring sebagai duplikat.

Cadangan penuh: `D:\backup\sigpetades\blok19-kosong-20261109\`.
`sebelum.sql` (9.765.094 byte; SHA-256
`ad425c0640eaefc6e3e8a8cce63cc9f26cc7f0325ba241510bb67899ec4ac44a`) diuji
restore ke `sipetades_coret_b19` — seluruh 18 tabel jumlah baris dan
`CHECKSUM TABLE` sama termasuk `sppt`, isi `sppt` identik 3.794 baris;
`audit_log` 3.400. Dump sesudah `sesudah.sql` (9.772.198 byte; SHA-256
`12deb763c299676e670e1b85680dd476b7579a7468f7128ce7d94abb8a84ce6b`) diuji
restore ke `sipetades_coret_b19b` — `audit_log` 3.401, audit 3460 ada, tanah
BLOK 19 = 104, duplikat = 19; baris dan checksum sama kecuali checksum fisik
`sppt`, isi `sppt` identik 3.794 baris lewat dump `--skip-extended-insert`
(nol beda). Kedua database uji sudah dihapus.

### 3.67 Sisa objek terbuka BLOK 21 dituntaskan: 11 menjadi tanah dan 6 duplikat (9 Nov 2026)

BLOK 21 (`blok.id=23`) berisi 556 bidang: 200 `tanah` aktif, 120 `tanah`
`perlu_verifikasi`, dan 236 `lain` (`perlu_verifikasi`, tanpa duplikat). Dari
236 objek `lain`, **219 sudah tertutup ≥95%** bidang tanah; **17 terbuka**
diproses:

- **11 bidang `lain` → `tanah`** (status tetap `perlu_verifikasi`), total
  **10.570,33 m²**:
  - 6 HM: 5596 `HM-338B` (138,38 m²), 5871 `HM-145D K3` (272,68), 5897
    `HM-199D K3` (3.420,37), 7275 `HM-121D K3` (171,67), 7287 `HM-106D K3`
    (315,24), 7680 `HM-1118D` (4.544,39).
  - 5 DXF tunggal: 7681 `DXF-020100-2167` (231,57), 8073 `DXF-020100-2559`
    (395,29), 8216 `DXF-020100-2702` (555,48), 8450 `DXF-020100-2934`
    (289,52), 8454 `DXF-020100-2938` (235,74).
- **6 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  **8.862,73 m²**, semuanya `ST_Equals` dengan keeper HM-nya: 11595
  `DXF-020100-0082` ≡ 5596, 11734 `DXF-020100-0357` ≡ 5871, 11750
  `DXF-020100-0383` ≡ 5897, 12664 `DXF-020100-1761` ≡ 7275, 12671
  `DXF-020100-1773` ≡ 7287, 12890 `DXF-020100-2166` ≡ 7680.

Tidak ada pengecualian: tidak ada objek terbuka yang kembar dengan bidang
`dihapus`, dan tidak ada kembar lain di luar 17 objek terbuka. 6/6 `duplikat`
tertutup 100% oleh keeper-nya; 219 objek `lain` yang sudah tercakup tidak
diubah.

Audit id **3461** (`objek_id='blok21-kosong-20261109'`). Keadaan akhir BLOK 21:
`tanah` **331** (200 aktif + 131 `perlu_verifikasi`), `lain` **225** (219
`perlu_verifikasi` + 6 `duplikat`); total tetap 556. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.373** (3.319 aktif + 1.052
`perlu_verifikasi` + 2 `dihapus`), lain **3.574** (1 aktif + 3.291
`perlu_verifikasi` + 282 `duplikat`); status `aktif` **3.320**,
`perlu_verifikasi` **4.343**, `duplikat` **282**, `dihapus` **2**. Uji API
`lapisan=bidang&blok=BLOK 21` menjawab **550 fitur** (200 `tanah` aktif + 131
`tanah` `perlu_verifikasi` + 219 `lain` `perlu_verifikasi`); 6 duplikat
tersaring. `HM-338B`, `HM-1118D`, `DXF-020100-2167`, dan `DXF-020100-2938`
menjawab `tipe: tanah`, sedangkan `DXF-020100-0082` dan `DXF-020100-2166`
tersaring sebagai duplikat.

Cadangan penuh: `D:\backup\sigpetades\blok21-kosong-20261109\`.
`sebelum.sql` (9.772.198 byte; SHA-256
`0deed5450869985e1d640d0733160e2d0441f3ec848208cc0706ae5613775d33`) diuji
restore ke `sipetades_coret_b21` — seluruh 18 tabel jumlah baris sama,
checksum sama kecuali checksum fisik `sppt`; isi `sppt` identik 3.794 baris
lewat dump `--skip-extended-insert` (nol beda); `audit_log` 3.401. Dump
sesudah `sesudah.sql` (9.775.343 byte; SHA-256
`64904aa1b5a157731cfb2c7cca95dc66f3426517038309118c8048281865ef4e`) diuji
restore ke `sipetades_coret_b21b` — `audit_log` 3.402, audit 3461 ada, tanah
BLOK 21 = 331, duplikat = 6; baris dan checksum sama kecuali checksum fisik
`sppt`, isi `sppt` identik 3.794 baris (nol beda). Kedua database uji sudah
dihapus.

### 3.68 Sisa objek terbuka BLOK 20 dituntaskan: 6 menjadi tanah dan 6 duplikat (9 Nov 2026)

BLOK 20 (`blok.id=11`) berisi 364 bidang: 150 `tanah` aktif, 1 `tanah`
`perlu_verifikasi`, dan 213 `lain` (`perlu_verifikasi`, tanpa duplikat). Dari
213 objek `lain`, **201 sudah tertutup ≥95%** bidang tanah; **12 terbuka**
diproses:

- **6 bidang `lain` → `tanah`** (status tetap `perlu_verifikasi`), total
  **5.241,81 m²**, semuanya HM: 5952 `HM-75D K3` (711,47 m²), 5953
  `HM-74D K3` (2.153,81), 5956 `HM-78D K3` (655,57), 5957 `HM-77D K3`
  (941,02), 5958 `HM-76D K3` (447,48), 7769 `HM-1840D` (332,46).
- **6 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), total
  **5.241,81 m²**, semuanya `ST_Equals` dengan keeper HM-nya: 11791
  `DXF-020100-0438` ≡ 5952, 11792 `DXF-020100-0439` ≡ 5953, 11795
  `DXF-020100-0442` ≡ 5956, 11796 `DXF-020100-0443` ≡ 5957, 11797
  `DXF-020100-0444` ≡ 5958, 12964 `DXF-020100-2255` ≡ 7769.

Tidak ada pengecualian: tidak ada objek terbuka yang kembar dengan bidang
`dihapus`, dan tidak ada kembar lain di luar 12 objek terbuka. 6/6 `duplikat`
tertutup 100% oleh keeper-nya; 201 objek `lain` yang sudah tercakup tidak
diubah.

Audit id **3462** (`objek_id='blok20-kosong-20261109'`). Keadaan akhir BLOK 20:
`tanah` **157** (150 aktif + 7 `perlu_verifikasi`), `lain` **207** (201
`perlu_verifikasi` + 6 `duplikat`); total tetap 364. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.379** (3.319 aktif + 1.058
`perlu_verifikasi` + 2 `dihapus`), lain **3.568** (1 aktif + 3.279
`perlu_verifikasi` + 288 `duplikat`); status `aktif` **3.320**,
`perlu_verifikasi` **4.337**, `duplikat` **288**, `dihapus` **2**. Uji API
`lapisan=bidang&blok=BLOK 20` menjawab **358 fitur** (150 `tanah` aktif + 7
`tanah` `perlu_verifikasi` + 201 `lain` `perlu_verifikasi`); 6 duplikat
tersaring. `HM-75D K3` dan `HM-1840D` menjawab `tipe: tanah`, sedangkan
`DXF-020100-0438` dan `DXF-020100-2255` tersaring sebagai duplikat.

Cadangan penuh: `D:\backup\sigpetades\blok20-kosong-20261109\`.
`sebelum.sql` (9.775.343 byte; SHA-256
`77bae5e809539dc8c8ab97a7036094cabe1dd49ff1922ba4cc3670206235617d`) diuji
restore ke `sipetades_coret_b20` — seluruh 18 tabel jumlah baris sama,
checksum sama kecuali checksum fisik `sppt`; isi `sppt` identik 3.794 baris
lewat dump `--skip-extended-insert` (nol beda); `audit_log` 3.402. Dump
sesudah `sesudah.sql` (9.777.573 byte; SHA-256
`482e5948c7511f1f92df4b5e9fe11df3cd60842fffc78c265dd6f5821db3545c`) diuji
restore ke `sipetades_coret_b20b` — `audit_log` 3.403, audit 3462 ada, tanah
BLOK 20 = 157, duplikat = 6; baris dan checksum sama termasuk `sppt`. Kedua
database uji sudah dihapus.

### 3.69 Sisa objek terbuka BLOK 24 dituntaskan: 4 menjadi tanah dan 1 duplikat (9 Nov 2026)

BLOK 24 (`blok.id=16`) berisi 386 bidang: 183 `tanah` aktif, tanpa `tanah`
`perlu_verifikasi`, dan 203 `lain` (`perlu_verifikasi`, tanpa duplikat). Dari
203 objek `lain`, **198 sudah tertutup ≥95%** bidang tanah; **5 terbuka**
diproses:

- **4 bidang `lain` → `tanah`** (status tetap `perlu_verifikasi`), total
  **1.584,77 m²**: 7129 `HM-970D K3` (922,28 m²), 7149 `DXF-020100-1635`
  (18,36), 7554 `DXF-020100-2040` (24,07), 7555 `DXF-020100-2041` (620,06).
- **1 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), **922,28
  m²**: 12607 `DXF-020100-1615` ≡ 7129 `HM-970D K3` (`ST_Equals`).

Tidak ada pengecualian: tidak ada objek terbuka yang kembar dengan bidang
`dihapus`, dan tidak ada kembar lain di luar 5 objek terbuka. 1/1 `duplikat`
tertutup 100% oleh keeper-nya; 198 objek `lain` yang sudah tercakup tidak
diubah.

Audit id **3463** (`objek_id='blok24-kosong-20261109'`). Keadaan akhir BLOK 24:
`tanah` **187** (183 aktif + 4 `perlu_verifikasi`), `lain` **199** (198
`perlu_verifikasi` + 1 `duplikat`); total tetap 386. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.383** (3.319 aktif + 1.062
`perlu_verifikasi` + 2 `dihapus`), lain **3.564** (1 aktif + 3.274
`perlu_verifikasi` + 289 `duplikat`); status `aktif` **3.320**,
`perlu_verifikasi` **4.336**, `duplikat` **289**, `dihapus` **2**. Uji API
`lapisan=bidang&blok=BLOK 24` menjawab **385 fitur** (183 `tanah` aktif + 4
`tanah` `perlu_verifikasi` + 198 `lain` `perlu_verifikasi`); 1 duplikat
tersaring. `HM-970D K3`, `DXF-020100-1635`, dan `DXF-020100-2041` menjawab
`tipe: tanah`, sedangkan `DXF-020100-1615` tersaring sebagai duplikat.

Cadangan penuh: `D:\backup\sigpetades\blok24-kosong-20261109\`.
`sebelum.sql` (9.777.573 byte; SHA-256
`547b5d8557648be10e2b92d0a0fcd1bab47ac8f3d1b8417473487308d67f76f3`) diuji
restore ke `sipetades_coret_b24` — seluruh 18 tabel jumlah baris dan
`CHECKSUM TABLE` sama termasuk `sppt`; `audit_log` 3.403. Dump sesudah
`sesudah.sql` (9.778.673 byte; SHA-256
`06fd1b08f07c7e1d5be305cd6ea7ff6c6a6a6e0aca877ce4306bc50c0834dee4`) diuji
restore ke `sipetades_coret_b24b` — `audit_log` 3.404, audit 3463 ada, tanah
BLOK 24 = 187, duplikat = 1; baris dan checksum sama termasuk `sppt`. Kedua
database uji sudah dihapus.

### 3.70 Sisa objek terbuka BLOK 22 dituntaskan: 2 menjadi tanah dan 1 duplikat (9 Nov 2026)

BLOK 22 (`blok.id=24`) berisi 124 bidang: 24 `tanah` aktif, 79 `tanah`
`perlu_verifikasi`, dan 21 `lain` (`perlu_verifikasi`, tanpa duplikat). Dari 21
objek `lain`, **18 sudah tertutup ≥95%** bidang tanah; **3 terbuka** diproses:

- **2 bidang `lain` → `tanah`** (status tetap `perlu_verifikasi`), total
  **488,37 m²**: 5525 `DXF-020100-0011` (36,78 m²), 7244 `HM-5D K3`
  (451,59).
- **1 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), **451,59
  m²**: 12645 `DXF-020100-1730` ≡ 7244 `HM-5D K3` (`ST_Equals`).

Tidak ada pengecualian: tidak ada objek terbuka yang kembar dengan bidang
`dihapus`, dan tidak ada kembar lain di luar 3 objek terbuka. 1/1 `duplikat`
tertutup 100% oleh keeper-nya; 18 objek `lain` yang sudah tercakup tidak
diubah.

Audit id **3464** (`objek_id='blok22-kosong-20261109'`). Keadaan akhir BLOK 22:
`tanah` **105** (24 aktif + 81 `perlu_verifikasi`), `lain` **19** (18
`perlu_verifikasi` + 1 `duplikat`); total tetap 124. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.385** (3.319 aktif + 1.064
`perlu_verifikasi` + 2 `dihapus`), lain **3.562** (1 aktif + 3.271
`perlu_verifikasi` + 290 `duplikat`); status `aktif` **3.320**,
`perlu_verifikasi` **4.335**, `duplikat` **290**, `dihapus` **2**. Uji API
`lapisan=bidang&blok=BLOK 22` menjawab **123 fitur** (24 `tanah` aktif + 81
`tanah` `perlu_verifikasi` + 18 `lain` `perlu_verifikasi`); 1 duplikat
tersaring. `DXF-020100-0011` dan `HM-5D K3` menjawab `tipe: tanah`, sedangkan
`DXF-020100-1730` tersaring sebagai duplikat.

Cadangan penuh: `D:\backup\sigpetades\blok22-kosong-20261109\`.
`sebelum.sql` (9.778.673 byte; SHA-256
`c33963fe5210b77363e846954eccf65d06258117b4a06670b56d3ff9b02ef732`) diuji
restore ke `sipetades_coret_b22` — seluruh 18 tabel jumlah baris dan
`CHECKSUM TABLE` sama termasuk `sppt`; `audit_log` 3.404. Dump sesudah
`sesudah.sql` (9.779.405 byte; SHA-256
`86bd2faa6d6ea897d149a4ae3de41ffe97c382cae85087f6d0e8b76a1dc89718`) diuji
restore ke `sipetades_coret_b22b` — `audit_log` 3.405, audit 3464 ada, tanah
BLOK 22 = 105, duplikat = 1; baris dan checksum sama termasuk `sppt`. Kedua
database uji sudah dihapus.

### 3.71 Sisa objek terbuka BLOK 18 dituntaskan: 1 menjadi tanah dan 1 duplikat (9 Nov 2026)

BLOK 18 (`blok.id=8`) berisi 92 bidang: 75 `tanah` aktif, tanpa `tanah`
`perlu_verifikasi`, dan 17 `lain` (`perlu_verifikasi`, tanpa duplikat). Dari 17
objek `lain`, **15 sudah tertutup ≥95%** bidang tanah; **2 terbuka** diproses:

- **1 bidang `lain` → `tanah`** (status tetap `perlu_verifikasi`), **4.519,25
  m²**: 6367 `HM-118C`.
- **1 kembar DXF → `status='duplikat'`** (`tipe` tetap `lain`), **4.519,25
  m²**: 12100 `DXF-020100-0853` ≡ 6367 `HM-118C` (`ST_Equals`).

Tidak ada pengecualian: tidak ada objek terbuka yang kembar dengan bidang
`dihapus`, dan tidak ada kembar lain di luar 2 objek terbuka. 1/1 `duplikat`
tertutup 100% oleh keeper-nya; 15 objek `lain` yang sudah tercakup (minimal
99,23%) tidak diubah.

Audit id **3465** (`objek_id='blok18-kosong-20261109'`). Keadaan akhir BLOK 18:
`tanah` **76** (75 aktif + 1 `perlu_verifikasi`), `lain` **16** (15
`perlu_verifikasi` + 1 `duplikat`); total tetap 92. Global: `bidang`
**7.947**, `hak` **7.945**, tanah **4.386** (3.319 aktif + 1.065
`perlu_verifikasi` + 2 `dihapus`), lain **3.561** (1 aktif + 3.269
`perlu_verifikasi` + 291 `duplikat`); status `aktif` **3.320**,
`perlu_verifikasi` **4.334**, `duplikat` **291**, `dihapus` **2**. Uji API
`lapisan=bidang&blok=BLOK 18` menjawab **91 fitur** (75 `tanah` aktif + 1
`tanah` `perlu_verifikasi` + 15 `lain` `perlu_verifikasi`); 1 duplikat
tersaring. `HM-118C` menjawab `tipe: tanah`, sedangkan `DXF-020100-0853`
tersaring sebagai duplikat.

Cadangan penuh: `D:\backup\sigpetades\blok18-kosong-20261109\`.
`sebelum.sql` (9.779.405 byte; SHA-256
`77d1b498fbb886f8b2de16b4b19ed966356cdb65ab3eed98988feb94eee85a90`) diuji
restore ke `sipetades_coret_b18` — seluruh 18 tabel jumlah baris dan
`CHECKSUM TABLE` sama termasuk `sppt`; `audit_log` 3.405. Dump sesudah
`sesudah.sql` (9.779.952 byte; SHA-256
`2afdabff5bfcc490690ba1c573c47d913e041f9adb19863d88f453f2f3487a8e`) diuji
restore ke `sipetades_coret_b18b` — `audit_log` 3.406, audit 3465 ada, tanah
BLOK 18 = 76, duplikat = 1; baris 18 tabel sama, checksum sama kecuali `sppt`
(isi `sppt` dibuktikan identik 3.794 baris lewat dump
`--skip-extended-insert`, `diff` nol baris). Kedua database uji sudah dihapus.

### 3.72 Penanda massal di Peta Induk (10 Nov 2026)

Halaman **Peta Induk** punya kartu baru, "Penanda massal". Petugas menyalakan
"Mode banyak penanda", klik beberapa titik, lalu satu permintaan server
menurunkan geometri untuk semuanya. Yang belum ada bidangnya digambar hijau,
yang kembar digambar kotak oranye beserta kode pasangannya, yang gagal hanya
muncul di daftar. "Simpan semua" (hanya Super Admin) menulis seluruh batch
dalam satu transaksi sebagai `perlu_verifikasi` tanpa blok — sama seperti
penanda tunggal.

Batasnya 150 penanda per permintaan. Titik pada tempat yang sama (pembulatan
sentimeter) dibuang sebelum dihitung; dua penanda yang jatuh di muka yang sama
juga dianggap satu. Sesi hanya menyimpan daftar titiknya, jadi geometri selalu
dihitung ulang dari layer saat menyimpan, tidak pernah diambil dari klien.

Aksi baru di `api/penanda-bidang.php`: `batch-pratinjau`, `batch-simpan`,
`batch-bersihkan`. Penurunan geometrinya pindah ke `tools/lib-penanda.php`
sehingga halaman, CLI, dan proses massal memakai rumus yang sama;
`siapkanGeometri()` dan `duplikatBidang()` ikut pindah ke pustaka itu.

**Diperlukan, bukan hiasan.** Jalur penanda lama menghabiskan 0,28 detik per
titik — 150 titik berarti 42 detik dan satu permintaan browser pasti putus.
Dua perbaikan dibuat di pustaka bersama: indeks batas luar tiap fitur supaya
uji titik-dalam hanya dijalankan pada fitur yang mungkin memuat penanda, dan
petak kisi pada pemotongan garis supaya uji potong-per-potong tidak lagi
mengurus semua pasangan segmen dalam jendela.

| Ukuran (150 titik pusat bidang) | Sebelum | Sesudah |
|---|---|---|
| seluruh penurunan | 42,1 s | 5,0 s |
| per titik | 0,281 s | 0,034 s |
| langkah poligon tertutup–rangkaian garis | 43,3 s | 0,7 s |
| langkah muka garis (kantong terbuka) | 39,4 s | 4,1 s |

Hasilnya tidak berubah: `php _lama/scratch/bukti-indeks-batas.php`
membandingkan 379 titik (19 penanda gang, 120 pusat bidang, titik digeser 3,5 m
dan 70 m, termasuk yang di luar semua bidang) antara jalur berindeks dan jalur
tanpa indeks — **0 perbedaan** jenis maupun kode. Bahan batch (`020100`: 16.322
fitur, 3.432 rangkaian garis tertutup) disusun sekali, 0,39 detik.

Ukuran pada 19 penanda gang (`files/peta gang/peta_gg.geojson`, dibaca lewat
`wgs84KeDxf()`): **1 bidang baru** (`DXF-020100-B92755`, 260,10 m²), **16
kembar**, **2 gagal**. Angka itu sama sebelum dan sesudah optimasi; 13 di
antaranya diambil dari rangkaian garis dan 1 dari muka garis baru.

Uji ujung-ke-ujung memakai database kercil `sipetades_gedong_uji` (skema
ditambah 7.950 baris `bidang`, 24 `blok`, 7.947 `hak`, `pengguna`, `dusun`,
`jenis_hak`, `jenis_penggunaan` salinan DB hidup), bukan basis data produksi:

- `batch-pratinjau` 24 titik (19 gang + 3 pusat bidang + 1 jauh di luar + 1
  titik ganda) → dijawab 23 penanda, `terpangkas 1`, **1 baru · 19 kembar · 3
  gagal**, `boleh_simpan` benar.
- `batch-simpan` dengan token itu → **1 bidang** `DXF-020100-B92755` id
  **23041** `status=perlu_verifikasi`, `sumber=peta-020100`, 260,10 m², pusat
  110,071509/-7,092539; satu `hak` id **27674** jenis **BLM**; satu `audit_log`
  `aksi=buat` dengan `data_sesudah.massal=true`. `dilewati` kosong.
- Token dipakai ulang → 410 "Pratinjau massal tidak ditemukan" (sesi dibersihkan
  setelah menyimpan, jadi tidak ada penyisipan ganda).
- `batch-pratinjau` diulang sesudah simpan → **0 baru · 20 kembar · 3 gagal**,
  `boleh_simpan` salah: bidang yang barusan dibuat terbaca sebagai kembar.

Dijalankan lewat `SIG_DB_NAME=sipetades_gedong_uji php
_lama/scratch/uji-batch-penanda.php pratinjau` lalu `... simpan <sid> <token>`.
Harness itu menyusun sendiri salinan API uji (baris bootstrap-nya dibuang karena
bootstrap dimuat lebih dulu) dan menghapusnya saat proses selesai, jadi tidak ada
bekas di dalam berkas proyek.

Database kercil dan salinan API-nya sudah dihapus; **tidak ada satu baris pun
yang ditulis ke `sipetades_gedong`** pada pekerjaan ini. Diperiksa ulang sesudah
pembersihan: `bidang` **7.950** baris (3.321 `aktif`, 4.475 `perlu_verifikasi`,
150 `duplikat`, 4 `dihapus`), `max(bidang.id)` **23040**, `audit_log` **3.413**
baris dengan `max(id)` **3472** — tidak ada `aksi=buat` baru. Angka itu memang
sudah bergeser dari keadaan yang dicatat
[§3.71](#371-sisa-objek-terbuka-blok-18-dituntaskan-1-menjadi-tanah-dan-1-duplikat-9-nov-2026)
(9 Nov: `bidang` 7.947) karena pekerjaan sesi lain setelahnya, bukan karena
bagian ini. Antarmukanya sendiri belum dicoba lewat browser karena akun uji
sudah dihapus dan halaman ini meminta login Super Admin.

---

### 3.73 Garis batas jalan, batas perairan dan lain-lain diturunkan dari 020100 (10 Nov 2026)

Permintaan kerjanya: buat geometri untuk **garis batas jalan**, **batas
perairan** dan **Lain-lain / lb_lai** dari layer `020100 — bidang & garis
(16.322)`. Hambatannya, tiga layer bernama itu di DXF hampir tidak punya
geometri sendiri:

| Layer DXF | Fitur | Geometri yang tersedia |
|---|---|---|
| `020100` | 16.322 | 13.681 garis terbuka · 2.626 poligon tertutup · 5 teks · 10 titik |
| `BATAS JALAN` | 626 | 620 teks + 6 titik sisip — **tidak ada satu garis pun** |
| `BATAS PERAIRAN` | 279 | 279 teks, tanpa rotasi — **tidak ada satu garis pun** |
| `lb_lai` | 19 | 17 garis + 2 poligon |

Jadi label "BATAS JALAN 3,00" dan "KALI BLUKAR" hanyalah tulisan di atas
gambar; bentuk jalurnya hidup di `020100` bersama seluruh bidang tanah.
Satu-satunya jalan adalah menambatkan garis `020100` ke label-label itu, dan itu
dibuat oleh `tools/garis-batas-020100.php` (CLI, baca `020100.geojson` +
metadata + tabel `bidang`, tulis tiga GeoJSON).

**Caranya.** Tiap garis `020100` disampel tiap 2 m dengan arah lokal.

1. **Benih** — garis yang punya titik sampel sedekat `benih_m` ke sebuah label
   ikut jenis itu. Radiusnya dibedakan: 2 m untuk jalan (label ditulis di tepi
   perkerasan) dan 8 m untuk perairan, karena 279 label air itu tidak pernah
   punya rotasi dan penulisannya di tengah alur, bukan di tepinya.
2. **Pita** (2 putaran) — garis tak berlabel ikut diklaim bila ≥ 50 % titik
   sampelnya sejajar (beda arah ≤ 25°) dengan garis terklaim dalam 15 m, **dan**
   ≥ 60 % titik tengah koridor di antara keduanya tidak jatuh di dalam bidang.
3. **Eksklusif** — garis yang merebut dua jenis diambil oleh label terdekat, jadi
   satu garis hanya masuk satu layer.
4. **Lain-lain** — garis yang tidak diklaim dan bukan pengulangan tepi bidang
   (`rasio_tepi_bidang < 0,3`). Garis < 2 m dibuang.

Aturan ke-2 itulah yang menyelamatkan pekerjaannya. Tanpa penyaring koridor
terhadap permukaan bidang, pita menyerap **9.690 dari 13.681 garis**: kisi bidang
tanah hampir selalu sejajar dalam 15 m, jadi "sejajar" sendirian tidak
membedakan bahu jalan dari pagar kebun. Permukaan yang dipakai sebagai penyaring
adalah **7.949 poligon `bidang`** (`status <> 'dihapus'`, dibaca lewat
`ST_AsGeoJSON`) → 83.276 segmen tepi → 151.965 petak kisi 5 m.

| | Benih | Pita | Ditolak: tidak sejajar | Ditolak: koridor diisi bidang |
|---|---|---|---|---|
| jalan (626 label) | 839 | 2.363 | 9.992 | 864 |
| perairan (279 label) | 925 | 1.238 | 11.028 | 414 |

**Hasilnya** tiga berkas di
`files/sig-pertanahan-gedong-lokal/data/dxf-layers/` — koordinat lokal DXF, sama
seperti `020100.geojson`, jadi tidak melewati konversi WGS84:

| Berkas | Fitur | Panjang | Rinci cara | Ukuran berkas |
|---|---|---|---|---|
| `garis-jalan.geojson` | 3.015 | 125.444 m | pita1 1.704 · pita2 546 · benih 765 | 1.093.718 B |
| `garis-perairan.geojson` | 1.579 | 102.237 m | benih 732 · pita1 693 · pita2 154 | 629.354 B |
| `garis-lain.geojson` | 41 | 7.517 m | bebas 41 | 21.052 B |

**Tuntasan pemeriksaan.** Seluruh garis terbuka terpakai dan tidak ada yang
dihitung dua kali: 4.594 diklaim + 41 lain-lain + 396 terlalu pendek + 8.650
sekadar mengulang tepi bidang = **13.681**, sesuai jumlah garis di sumbernya
(`_lama/scratch/cek-tuntasan-garis.php`, tumpang tindih indeks: tidak ada).
`_lama/scratch/cek-lapisan-garis.php` memastikan tiap berkas FeatureCollection
valid, 0 geometri cacat, dan koordinatnya di dalam bentang peta; 96,1 % panjang
jalan dan 94,7 % panjang perairan benar-benar menempel pada tepi bidang, yang
memang harapan untuk batas yang mengikuti kadastrum.

**Verifikasi visual.** PHP GD (`php -d extension=gd`) mencetak potongan PNG per
klaster label lewat `_lama/scratch/gambar-garis-020100.php`: benih hijau, pita1
biru, pita2 cyan, perairan ungu, lain-lain oranye, label kotak merah. Di klaster
jalan barisan garis mengikuti deretan "JALAN RAYA"/"JALAN GANG" dan di kawasan
Kali Sari radius 8 m itu mengambil **kedua tepian** alur, bukan satu sisi saja —
perairan naik dari 1.404 menjadi 2.163 garis sebelum ekslusivitas.

**Sajian di Peta Induk.** `api/peta-lama.php` mengenal 19 kunci lapisan
(sebelumnya 16); `peta-lama.php` mendapat tiga centang mati secara bawaan —
*Garis batas jalan* (teal `#0f766e`), *Garis batas perairan* (biru `#1d4ed8`),
*Garis 020100 tanpa label* (cokelat putus-putus `#a16207`) — lengkap dengan
jumlah fitur dari metadata dan tiga entri legenda. Skrip halaman diperiksa
sintaksisnya dengan mengeluarkan heredok `JS` lalu `node --check`; berkas masih
utuh 1.146 baris CRLF.

Layer ini sengaja **tidak** dipasang di panel layer Peta Bidang (`peta.php`).
Di sana jalan dan sungai sudah diwakili label DXF (`lapisan=jalan`/`sungai`),
dan menambah tiga lapis garis hasil impor kadastrum hanya memperberat tampilan
yang dipakai petugas untuk membaca bidang. Geometrinya pun harus dikonversi
dulu ke WGS84 lewat `dxfKeWgs84()`. Perubahan pada `peta.php` di hari yang lain
([§3.74](#374-batas-blok-jadi-pil-di-panel-lapisan-peta-peta-bidang-10-nov-2026))
tidak menyentuh ketiga kunci lapisan itu. Waktu itu panel Peta Bidang masih
menampilkan garis OSM (`osm-jalan`/`osm-sungai`) sebagai pembanding; kedua lapis
itu kemudian dihapus seluruhnya ([§3.75](#375-semua-garis-osm-dihapus-dari-peta-bidang-10-nov-2026)).

**Basis data tidak disentuh.** Alat ini hanya membaca. Diperiksa sesudah
pekerjaan: `bidang` **7.950** baris (3.321 `aktif`, 4.475 `perlu_verifikasi`,
150 `duplikat`, 4 `dihapus`), `max(bidang.id)` **23040**, `blok` **24** —
identik dengan [§3.72](#372-penanda-massal-di-peta-induk-10-nov-2026). Satu
baris `audit_log` bertambah (id 3473) dan itu hanya `login` Super Admin
10 Nov 15:58, bukan tulisan geometri.

**Yang diketahui dan sengaja dibiarkan.** (1) Corridor panjang tanpa label tidak
masuk jalan/perairan dan jatuh ke lain-lain; misalnya pasangan indeks 1232/1233
sepanjang 2,26 km dan 1,95 km yang saling sejajar, dengan 0 label perairan dalam
900 m (`_lama/scratch/cek-label-perairan.php`) — kemungkinan jalan inspeksi kali
yang belum dinamai. (2) Pita putaran ke-2 sesekali melompati bidang yang memang
belum ada barisnya di `bidang`. (3) `LAIN-020100-0037` (331 m, X≈260.366) berada
di luar `extent` metadata; itu bukan galat turunan — 4 dari 16.322 fitur `020100`
asal memang sudah keluar bentang itu (sumber X 259.950–264.665, `extent`
min_x 261.327). Semua ambang dan hitungan ini ikut tertulis di `metadata.json`
pada kunci `GARIS BATAS JALAN 020100`, `GARIS BATAS PERAIRAN 020100` dan
`GARIS LAIN 020100`, supaya bisa diulang dan diotak-atik.

---

### 3.74 Batas blok jadi pil di panel Lapisan peta Peta Bidang (10 Nov 2026)

Pada **Peta Bidang** (`peta.php`) batas blok selama ini selalu tergambar dan
tidak bisa dimatikan: `aktifkan('blok', true)` dipanggil setiap muat halaman,
sedangkan kunci `setelan.blok` cuma dipakai `gambarLegenda()` untuk menampilkan
atau menyembunyikan baris "Batas blok ukur". Panel *Lapisan peta* hanya berisi
pil Bidang tanah, Obyek non-tanah, Jalan, Sungai, Garis jalan OSM dan Garis
sungai OSM.

Sekarang ada pil **Batas blok** (`#tampilBlok`, hijau `#14603a`, angka **24**
dari `count($blok)`) di antara Obyek non-tanah dan Jalan. Pil itu benar-benar
mengendalikan lapisannya:

- `terapkanSetelan()` mengisi mencentang pil dari `setelan.blok` lalu memanggil
  `aktifkan('blok', setelan.blok)` dan `muatBlok()` hanya bila kelompoknya masih
  kosong — sama seperti laporan/jalan/sungai/OSM.
- Ganti centang → simpan setelan, pasang/copot `grup.blok`, muat malas bila
  perlu, perbarui legenda.
- Baris `aktifkan('blok', true)` dan `muatBlok()` buta pada bagian awal halaman
  dihapus. Permintaan `lapisan=blok` tetap satu kali per muat seperti sebelumnya,
  tetapi sekarang hilang sama sekali saat pilnya mati — sebelumnya lapisan ini
  selalu diminta dan selalu terpasang apa pun yang terjadi.

Yang ikut hilang saat pil dimatikan adalah **nama blok** (`BLOK 5` dan
sejenisnya), karena label itu memang ditambahkan ke `grup.blok` oleh
`muatBlok()`. Kotak "Label nama blok" di kartu Tampilan tetap mengatur
tumpang-tindih label pada zoom jauh dan tidak berubah makna. Tombol "Seluruh
desa" memakai `batasGrup(grup.blok)` sebagai acuan pemusatan; kalau blok
dimatikan, ia jatuh ke batas bidang yang tampil, persis perilaku lama saat
saringan kosong.

Tidak ada perubahan di sisi server: `lapisan=blok` pada `api/bidang.php` sudah
ada dan kunci `blok` memang ikut tersimpan dalam preset basis data (preset
menyimpan seluruh objek `setelan`, tanpa penyaringan kunci). Diperiksa:
`php -l peta.php` bersih, heredok `JS` (952 baris) lolos `node --check`, dan
`blok` di basis data masih **24** baris dengan geometri lengkap semuanya
(`batas_perkiraan = 0` untuk 24 baris, sehingga 24 bidang batas tergambar).

**Diuji diklik di peramban** (login Super Admin, `http://localhost/sigpetades/peta.php`):

| Langkah | Pil | `path` di `pane-blok` | label `BLOK n` | baris legenda | permintaan `lapisan=blok` |
|---|---|---|---|---|---|
| muat awal | hidup | 24 | 24 | ada | 1 |
| klik matikan | mati | 0 | 0 | hilang | 1 (tidak ambil ulang) |
| klik nyalakan | hidup | 24 | 24 | ada | 1 |
| muat ulang dalam keadaan mati | mati | 0 | 0 | hilang | **0** |
| klik nyalakan setelah muat ulang | hidup | 24 | 24 | ada | 1 (muat malas) |

Lapisan bidang tanah tidak ikut terpengaruh (4.173 `path` pada `pane-tanah`
tetap sama di semua langkah), dan 4 dari 24 ruas batas blok memang memotong
jendela tampilan saat itu — hilang pada tangkapan layar sesudah pil dimatikan.
`setelan.blok` tertulis ke localStorage setiap pergantian. Satu-satunya galat
konsol adalah tile OSM 400 pada zoom 20, tidak berhubungan dengan perubahan ini.

---

### 3.75 Semua garis OSM dihapus dari Peta Bidang (10 Nov 2026)

Permintaan: **hapus semua garis OSM**. Yang dimaksud adalah dua pil di panel
*Lapisan peta* **Peta Bidang** (`peta.php`) — *Garis jalan OSM* (oranye
`#ff6b00`) dan *Garis sungai OSM* (biru muda `#00bcd4`) — beserta seluruh
mesin di belakangnya. Sebelum dihapus, keduanya sedang hidup pada localStorage
pemakai (`osmJalan: true`, `osmSungai: true`) dan menyumbang **978 garis** /
**18.809 titik** di atas peta.

**Yang dilepas.**

| Bagian | Isi |
|---|---|
| `peta.php` HTML | dua `<label class="chip-lapisan">` (`#tampilOSMJalan`, `#tampilOSMSungai`) — 8 baris |
| `peta.php` JS | `BAWAAN.osmJalan/osmSungai`, `PANES.osmJalan/osmSungai` (z-index 414), `grup.osmJalan/osmSungai`, `muatOSMJalan()`/`muatOSMSungai()` (38 baris), dua `addEventListener('change')`, dua `aktifkan('osm…')` + dua muat malas di `terapkanSetelan()`, dua `aktifkan` + dua muat buta di blok awal |
| `api/bidang.php` | cabang lapisan `osm-jalan` dan `osm-sungai` — 95 baris, termasuk permintaan ke `https://overpass-api.de/api/interpreter` (`way["highway"]` / `way["waterway"]` dalam bbox `-7.11,110.04,-7.07,110.10`) dan penulis cache 7 hari. Berkas 362 → **267 baris** |
| `storage/cache/` | `osm-jalan.geojson` (374.533 B, 832 garis) dan `osm-sungai.geojson` (192.079 B, 146 garis) dipindah ke `_lama/osm-cache-2026-11-10/` supaya masih bisa diambil lagi; keduanya tidak dilacak git |

Heredok `JS` `peta.php` turun dari 952 menjadi **880 baris**; `git diff
--numstat` mencatat `api/bidang.php` 0 tambah / 95 kurang.

**Kunci lama ikut dibersihkan.** `bacaSetelan()` dulu menormalkan objek setelan
di dalam dirinya sendiri, sedangkan jalur preset memakai
`Object.assign({}, BAWAAN, preset.data)` yang tidak disaring. Keduanya sekarang
lewat satu helper `rapikanSetelan(isi)` yang juga membuang
`osmJalan`/`osmSungai`. Tanpa itu, localStorage lama dan preset basis data —
yang menyimpan seluruh objek `setelan` tanpa penyaringan kunci — akan menuliskan
kunci mati itu kembali setiap kali simpan. Karena `bacaSetelan()` hanya membaca,
kunci hilang pada **penulisan berikutnya**, bukan saat halaman dimuat (diukur
pada tabel di bawah).

**Yang sengaja tetap ada.** (1) Basemap `tile.openstreetmap.org` dan
attribution `© OpenStreetMap` — itu peta dasar, bukan garis. (2) Lapisan label
DXF `lapisan=jalan` (**620** titik) dan `lapisan=sungai` (**279** titik) pada
Peta Bidang, dan ketiga lapis garis turunan kadastrum di Peta Induk
([§3.73](#373-garis-batas-jalan-batas-perairan-dan-lain-lain-diturunkan-dari-020100-10-nov-2026)).
(3) `files/master peta/jalan_osm.json` (353.959 B, unduhan Overpass 19 Okt)
beserta pemakainya: `tools/lib-impor.php` memotong batas blok ke tepi jalan
berdasarkan berkas itu ([§3.8](#38-batas-blok-dari-peta-dasar),
[§3.35a](#335a-jalan-dipotong-di-tepi-bidang-sungai-disambung-jadi-garis-7-nov-2026))
dan `tools/rapikan-garis-blok.php` memakai ruas OSM yang sama untuk menolak
menyambung garis yang dipotong jalan. Alat-alat itu
tidak pernah memanggil `api/bidang.php?lapisan=osm-jalan`, jadi menghapus cabang
API tidak mematahkannya — ini yang diperiksa lebih dulu sebelum memotong.
(4) `attribution: '&copy; OSM'` pada `pecah-bidang.php` dan
`penggabungan-bidang.php` juga hanya teks attribution basemap. (5)
`files/sig-pertanahan-gedong-lokal/public/index.php` arsip, bukan kode aktif.

**Diuji di peramban** (login Super Admin, muat ulang tanpa cache pada
`http://localhost/sigpetades/peta.php`):

| Hal | Sebelum | Sesudah |
|---|---|---|
| Pil di *Lapisan peta* | 8 — termasuk Garis jalan OSM, Garis sungai OSM | **6** (Bidang tanah 4.173 · Obyek non-tanah 3.623 · Batas blok 24 · Jalan · Sungai · Titik laporan 0) |
| `#tampilOSMJalan` / `#tampilOSMSungai` di DOM | ada | **tidak ada** |
| Pane Leaflet khusus | 9 (…`pane-osmJalan` 832 `path`, `pane-osmSungai` 146 `path`) | **7**, tanpa pane OSM |
| Permintaan `lapisan=osm-*` per muat | 2 | **0** |
| Permintaan lapisan lain per muat | — | `batas` 1 · `bidang` 2 · `jalan` 2 · `sungai` 2 (semua seperti sebelumnya) |
| `api/bidang.php?lapisan=osm-jalan` | 200, GeoJSON 832 garis | 200 daftar ringkas bidang (`jumlah` 7.796) — nilai `lapisan` tak dikenal jatuh ke cabang default, perilaku lama untuk nilai apa pun yang tidak dikenali |
| `lapisan=jalan` / `lapisan=sungai` (label DXF) | 620 / 279 fitur | **620 / 279** fitur, tetap `Point` |
| localStorage `sip.peta.pengaturan.v2` | `osmJalan: true, osmSungai: true` | kunci hilang setelah satu simpan; `blok: false`, `lain: true`, `kepekatan: 45` tidak berubah |
| Pesan konsol | — | **0** |

Pengujian kunci mati di localStorage dilakukan dengan menyalakan lalu
mematikan pil *Titik laporan* (dua kali `simpanSetelan()`), sehingga keadaan
pemakai kembali persis seperti semula. `php -l peta.php` dan `php -l
api/bidang.php` bersih, kedua berkas masih utuh CRLF (1.128 dan 267 baris),
heredok `JS` lolos `node --check`, dan tangkapan layar Peta Bidang tidak
menampilkan lagi garis oranye/cyan OSM di atas bidang.

**Basis data tidak disentuh.** Tidak ada tabel, baris, atau preset yang
diubah; preset lama yang menyimpan `osmJalan`/`osmSungai` tetap tersimpan dan
hanya diabaikan (kuncinya dibuang saat dimuat).

---

### 3.76 Semua obyek non-tanah dihapus permanen dari tabel bidang (10 Nov 2026)

Permintaan: **hapus obyek non-tanah yang berbentuk bidang tanah saja**.
Kandidatnya seluruh baris `bidang.tipe = 'lain'` — poligon hasil impor layer
DXF kadastrum `020100` plus satu baris batas blok — bukan hanya yang
berstatus `perlu_verifikasi`. Angka pengukuran disampaikan lebih dulu
(236 · 697 · 3.088 · 3.623 · 3.773 baris, tergantung saringan yang dipakai),
lalu pilihan jatuh pada **semua 3.773 baris**, dihapus dengan **`DELETE`
permanen** dan bukan `status = 'dihapus'`, dengan konsekuensi yang sudah
dihitung lebih dulu: **3.772 baris `hak` ikut hilang** karena
`hak.bidang_id` memakai `ON DELETE CASCADE`. Persetujuan terakhir diberikan
di dalam percakapan ("ya, hapus").

**Yang terhapus.**

| Saringan | Baris |
|---|---|
| `sumber = '020100'` (poligon turunan layer DXF kadastrum) | 3.772 |
| `sumber = 'peta-batas-blok'` | 1 |
| `status = 'perlu_verifikasi'` | 3.622 |
| `status = 'duplikat'` | 150 |
| `status = 'aktif'` | 1 |
| jumlah luas | 4.202.818,02 m² (420,28 ha) |

**Sebelum dan sesudah.**

| Hal | Sebelum | Sesudah |
|---|---|---|
| `bidang` | 7.950 baris — 4.177 `tanah` + 3.773 `lain` | **4.177** baris, seluruhnya `tanah` |
| Bidang yang lolos saringan aplikasi | 7.796 (4.173 tanah + 3.623 non-tanah) | **4.173** |
| `SUM(luas_m2)` | 16.913.617,87 m² | **12.710.799,85 m²** |
| `hak` | 7.947 | **4.175** |
| Pil *Obyek non-tanah* di Peta Bidang | 3.623 | **0** |
| `blok` · `buku_c` · `objek_pajak` · `pengguna` | 24 · 1.419 · 3.794 · 1 | tidak berubah |

Angka "sebelum" untuk luas dan `hak` adalah penjumlahan balik dari keadaan
sesudah (`12.710.799,85 + 4.202.818,02` dan `4.175 + 3.772`); keduanya
dicocokkan dengan bek penuh sebelum eksekusi.

**Tabel lain tidak ikut berubah.** Diukur dari isi bek sebelum hapus lewat
`_lama/scratch/dampak-hapus-non-tanah.php` — dump dibuat tanpa
`--complete-insert`, jadi posisi kolom `bidang_id` diambil dari
`information_schema` tabel hidup.

| Tabel | Aturan FK | Baris yang menunjuk obyek non-tanah |
|---|---|---|
| `buku_c` | `SET NULL` | **0** dari 1.419 baris; kolom `bidang_id` memang sudah NULL seluruhnya sebelum hapus |
| `objek_pajak` | `SET NULL` | **0** dari 3.794 baris (3.618 di antaranya NULL) |
| `laporan`, `peralihan` | `SET NULL` | tabel kosong, 0 baris |
| `hak` | `CASCADE` | **3.772** baris terhapus; 2.029 di antaranya menyimpan nama pemegang, 1.138 nama unik |

Tabel `pemegang` kosong (0 baris) sebelum dan sesudah, jadi nama pada 2.029
baris `hak` itu hanya teks bebas di dalam baris yang ikut terhapus — tidak ada
master data yang hilang, tetapi nama-nama tersebut tidak bisa diambil lagi dari
tabel lain.

**Cara pengerjaan.** (1) `mysqldump` penuh →
`sebelum-hapus-non-tanah-20261110-192909.sql` (9.911.863 B,
21 tabel). (2) Bek baris saja untuk dua tabel terdampak →
`baris-non-tanah-20261110-192909.sql` (3.936.769 B; 3.773
INSERT `bidang` dan 3.772 INSERT `hak`); `--single-transaction` wajib, tanpa
itu `--where` bersubquery kena `ERROR 1100` ("Table 'bidang' was not locked
with LOCK TABLES"). (3) Kering: `START TRANSACTION`, `DELETE`, ukur
`ROW_COUNT()`, lalu `ROLLBACK`. (4) Nyata: satu transaksi
`DELETE FROM bidang WHERE tipe = 'lain'` dengan hasil `bidang_terhapus` 3.773,
`bidang_sisa` 4.177, `hak_sisa` 4.175, kemudian `COMMIT`.

**Verifikasi.**

- `SELECT COUNT(*) FROM bidang WHERE tipe = 'lain'` → **0**.
- Kunci asing yatim → **0** baris di `hak`, `buku_c`, `objek_pajak` yang
  menunjuk id di luar `bidang`.
- `v_ringkasan_sistem`: `jumlah_bidang` 4.177 = `jumlah_bidang_tanah` 4.177,
  `jumlah_hak_tercatat` 4.175, `jumlah_blok` 24, `jumlah_objek_pajak` 3.794,
  `jumlah_buku_c` 1.419.
- Peramban (login Super Admin, muat ulang tanpa cache pada
  `http://localhost/sigpetades/peta.php`): `pane-tanah` **4.173** `<path>`,
  `pane-lain` 0, `.leaflet-interactive` 4.173; `?lapisan=bidang&maks=6000&tipe=lain`
  mengembalikan 0 fitur dan `tipe=tanah` mengembalikan 4.173 fitur
  (2.746.242 B); kepala peta "Sumber: 4.173 bidang tanah · 24 blok".
- Satu jebakan pengujian dicatat supaya tidak dianggap regresi: pane tanah
  sempat terlihat kosong karena `localStorage` masih menyimpan `bidang: false`
  dari uji pil sebelumnya, bukan karena data hilang. Setelah pil *Bidang tanah*
  dinyalakan lagi, seluruh poligon tergambar.

**Kode tidak diubah.** Skema, halaman, dan API dibiarkan seperti adanya: kolom
`tipe` tetap punya nilai `lain`, pil *Obyek non-tanah* dan saringan
`?tipe=lain` pada `api/bidang.php` tetap ada (hasilnya sekarang selalu kosong),
dan `tools/lib-impor.php` tetap memetakan layer `020100` ke
`['tipe' => 'lain', 'kode' => 'DXF-020100']`.

**Cara memulihkan.** `mysql ... < sebelum-hapus-non-tanah-20261110-192909.sql`
mengembalikan seluruh basis data ke keadaan 10 Nov 19:29:09; bila yang
dibutuhkan hanya obyek non-tanahnya,
`mysql ... < baris-non-tanah-20261110-192909.sql` menambahkan kembali 3.773
baris `bidang` dan 3.772 baris `hak` tanpa menyentuh data lain. **Lokasi
Kedua berkas itu sekarang `D:/cadangan-sigpetades/2026-11-13/`** — dipindah dari
`storage/cadangan/` pada 13 Nov 2026 atas permintaan user, jadi tetap di luar repo
(tidak pernah dilacak git) dan sekaligus di luar document root: tidak ada URL yang
menjangkaunya, bahkan kalau `.htaccess` bocor. Integritasnya terukur sebelum dan
sesudah pindah, MD5 `257b1febc53e174f5a3283463a31fb3e` dan
`efbcd31c8008fcbfa92ae43262de026c`. Delapan dump berstempel 8 Nov yang ada di
folder itu bersamaan (enam cacat 188-189 KB dengan 10 INSERT vs 25 DROP, dua penuh
9.903.763 B) dihapus pada hari yang sama, 20.942.060 B dibebaskan, sehingga di
antara berkas-berkas itu keadaan tertua yang tersisa adalah 10 Nov 19:29. Kesimpulan
"keadaan 8 Nov tidak ada lagi" ternyata salah: satu dump penuh 8 Nov 13:42 dan dua
salinan ekspor JSON 8 Nov 13:41 masih utuh di `storage/impor/`, yaitu folder tempat
kartu Impor menyimpan berkas unggahan — angka dan MD5-nya dicatat di §9.
Alternatif ketiga —
mengimpor ulang layer `020100` lewat `tools/impor.php` — membentuk kembali baris
`tipe = 'lain'`, tetapi dengan id dan kode baru sehingga tautan lama tidak ikut
pulih.

---

### 3.77 Garis turunan 020100 dipecah jalan / selokan / perairan (10 Nov 2026)

Permintaan: **buatkan garis semua jenis — jalan, selokan, sungai/batas perairan —
dari sumber 020100, bidang & garis (16.322)**. Sebelumnya penurunan 10 Nov (§3.73)
hanya memakai 13.681 garis terbuka dan menyamakan saluran kecil dengan
sungai; keluarannya dua kelas air dalam satu berkas.

**Yang diukur lebih dulu.** Sensus geometri sumber
(`_lama/scratch/sensus-020100.php`): 13.681 garis terbuka + 2.626 poligon tertutup
+ 15 obyek lain = 16.322 entitas. Lalu `_lama/scratch/ukur-kelas-garis.php`
mengukur jarak tiap label ke garis terdekat dan jarak antara dua tepi sejajar
yang mengapit celah kosong di kiri-kanannya, dengan kandidat garis *dan* sisi
poligon sekaligus:

| kelas label | jumlah | <= 2 m | median jarak | median dua tepi |
| --- | --- | --- | --- | --- |
| JALAN | 622 | 79 % | 1,1 m | 2,3 m |
| SALURAN / IRIGASI | 165 | 93 % | 0,5 m | 1,8 m |
| SUNGAI / KALI | 112 | 15 % | 2,8 m | 5,3 m |

Angka itu yang memecah air menjadi dua kelas: tulisan selokan ditumpangkan
*di atas* garisnya dan diapit dua tepi yang hampir berhimpit (median 1,8 m),
sedangkan nama sungai ditulis di tengah alur yang lebarnya lima meter lebih
(median 5,3 m). Maka benih selokan 2 m (sama dengan jalan) dan pita selokan
8 m, bukan 15 m — radius selebar jalan membuat pita saluran melompat ke
tepi jalan di sebelahnya. Kelas dibaca dari **teks** label, bukan dari berkas
tempat label disimpan, karena nama saluran tersimpan di berkas `BATAS PERAIRAN`
dan dua label "JALAN ..." nyasar ke berkas yang sama.

**Kandidat dari poligon.** 2.626 cincin poligon 020100 ikut diperebutkan sesuai
permintaan "bidang & garis", meskipun hanya 25 dari 899 label (20 jalan, 3
selokan, 2 sungai) yang geometri terdekatnya sebuah sisi poligon. Cincin diuji
ke kisi garis yang *sudah tergambar*: garis terbuka 020100 plus cincin yang
lebih dulu dipakai, diurut dari keliling terbesar sehingga dua poligon
bertetangga tidak menghasilkan tepi yang sama dua kali. Yang >= 50 % titik
sampelnya sudah tergambar dibuang.

Satu keputusan penting: **sisi poligon tabel bidang tidak dipakai sebagai
penyaring duplikasi**. `_lama/scratch/cincin-vs-bidang.php` mengukur kedua dasar
itu pada 2.626 cincin: terhadap sisi bidang 2.332 cincin punya rasio 1,00,
terhadap garis terbuka 2.362 — praktis sama, karena baris `bidang` hasil impor
adalah poligon 020100 yang sama. Kalau tepi bidang ikut dihitung, sumber
"bidang" menjadi nol manfaat: hanya 17 cincin lolos. Dengan dasar garis tergambar
saja, 29 cincin yang benar-benar baru (kedua rasio < 0,5; 3.762 m keliling) ikut
masuk dan 38 cincin terpakai.

**Angka sebelum dan sesudah** (sebelum = cadangan
`_lama/garis-020100-sebelum-20261110/`, sesudah = keluaran tool hari ini,
jalan 28 s):

| berkas | sebelum | sesudah | panjang sebelum → sesudah |
| --- | --- | --- | --- |
| `garis-jalan.geojson` | 3.015 | 3.144 | 125.444 → 129.269 m |
| `garis-selokan.geojson` | — (belum ada) | 783 | — → 49.949 m |
| `garis-perairan.geojson` | 1.579 | 542 | 102.237 → 39.235 m |
| `garis-lain.geojson` | 41 | 74 | 7.517 → 11.847 m |
| **total** | **4.635** | **4.543** | **235.198 → 230.300 m** |

Perpindahan indeks per berkas (`_lama/scratch/cek-empat-kelas-garis.php`): jalan
+176 indeks baru dan −47 (26 pindah ke selokan, 12 ke perairan, 9 tidak lagi
terklaim); perairan +31 dan −1.068 (731 pindah ke selokan, 96 ke jalan, 3 ke
lain-lain, 238 tidak terjaring lagi karena pita selokan menyempit dari 15 m
menjadi 8 m); lain-lain +33 dan tidak ada yang hilang. Jumlah lapisan air
sendiri turun dari 1.579 fitur menjadi 1.325 — bukan kehilangan, tetapi 731
indeks yang kini tersimpan di berkasnya sendiri.

Akuntansi kandidat: 13.719 = 13.681 garis terbuka + 38 cincin, terbagi menjadi
4.469 terklaim per kelas + 74 lain-lain + 396 terlalu pendek + 8.780 mengulang
tepi bidang. Jangkar label 899 titik berteks (jalan 622, selokan 165, perairan
112); 6 titik label tanpa teks di berkas yang sama tidak dipakai. Kontribusi
cincin poligon pada keluaran akhir 28 fitur: 5 jalan, 3 selokan, 2 perairan,
18 lain-lain.

**Kode yang berubah.** `tools/garis-batas-020100.php` — konstanta `PITA` tunggal
diganti `JENIS` (per kelas: pola teks, radius benih, radius pita, berkas hasil),
tambah `kelasLabel()`, `bacaLabel()` tanpa argumen (label dari berkas
`batas-jalan` + `batas-perairan` digabung lalu diklasifikasi dari teks), kisi
`$kisiUlang`/`$segmenUlang` khusus penyaring cincin, tahap penerimaan cincin
sebelum tahap pita, properti `asal` (`garis` atau `bidang`) di setiap fitur, dan
blok `ambang`/`kandidat`/`label`/`asal` per lapisan di `metadata.json`. Tidak ada
perubahan di `tools/lib-impor.php` maupun pustaka geometri.

**Sajian di Peta Induk.** `api/peta-lama.php` menerima kunci `garis-selokan`;
`peta-lama.php` menambah centang *Garis selokan / saluran (783)* di antara jalan
dan perairan, warnanya `#0ea5e9` dengan tebal 1,4 — dibedakan dari jalan
`#0f766e`, perairan `#1d4ed8`, dan lain-lain `#a16207` putus-putus. Teks
penjelasan di bawah centang diganti mengikuti angka kandidat yang baru.

**Verifikasi.** `php -l` bersih untuk tool, API, dan halaman; blok nowdoc `JS`
halaman lolos `node --check` (796 baris isi JS, setelah
`_lama/scratch/ekstrak-js.php` dibetulkan agar membuang tag `<script>` yang ikut
di dalam nowdoc). Tabel bukti di atas adalah hasil jalan ulang
`ukur-kelas-garis.php` sesudah scratch itu dikoreksi: kelas label dibaca dari
teks huruf besar, sama seperti `kelasLabel()` di tool, sehingga 622/165/112 dan
dua tepi 2,3/1,8/5,3 m kini cocok dengan angka yang dicetak tool.
`cincin-vs-bidang.php` mengulang pengukuran penyaring cincin dan menghasilkan
angka yang dikutip di paragraf sebelumnya (2.362 terhadap garis terbuka, 2.332
terhadap sisi bidang, 29 cincin benar-benar baru).
`cek-lapisan-garis.php`: keempat berkas
FeatureCollection valid, `cacat 0`, jumlah fitur sama persis dengan metadata,
semua koordinat masih lokal DXF dan di dalam extent sumber — kecuali dua titik
`garis-lain` di X 260.366 yang memang sudah ada di keluaran sebelum perubahan.
`cek-empat-kelas-garis.php`: **tidak ada satu pun indeks 020100 yang muncul di
dua kelas** (eksklusivitas label terdekat terjaga), dan distribusi `cara` masuk
akal (jalan 764 benih / 1.806 pita-1 / 574 pita-2). Belum diuji dengan klik di
peramban: halaman memerlukan login Super Admin dan akun uji sudah dihapus, jadi
verifikasi bersifat statis — `curl` hanya membuktikan pagar tetap berdiri
(`peta-lama.php` 302 ke login, `api/peta-lama.php?lapisan=garis-selokan` 401).
Higienitas berkas terjaga: README seluruhnya CRLF dan tool seluruhnya LF,
tanpa tab, dan tanpa aksara di luar skrip Latin — Han, Kana, Hangul, dan
Sirilik semuanya nol di kedua berkas. Kalimat "tanpa karakter non-Latin"
harus dibaca begitu, sebab README memang memuat glif diagram dan tanda
matematika: 728 kemunculan dari 13 aksara, yaitu U+2192 (316), U+2261 (221),
U+2500 (69), U+2265 (57), U+2212 (20), U+2264 (17), U+2248 (14), U+25BA (5),
U+2194 (4), U+2518 (2), dan U+2510, U+251C, U+25B2 masing-masing satu.

**Basis data tidak disentuh.** Tabel `bidang` hanya dibaca sebagai permukaan
(4.176 poligon, 56.315 segmen tepi); tidak ada satu baris pun yang ditulis.
Empat berkas GeoJSON hasil penurunan lama ada di
`_lama/garis-020100-sebelum-20261110/` sebagai titik gulung-back — dipulihkan
dengan menyalinnya kembali dan menghapus `garis-selokan.geojson`.

### 3.78 Nama lapisan di panel peta disederhanakan (10 Nov 2026)

Permintaan: **rapikan semua label agar lebih sederhana**. Yang diubah hanya teks
yang tampil pada tiga halaman peta: 20 centang lapisan + 1 centang titik dan
legenda `peta-lama.php` (Peta Induk), legenda `peta-blok.php`, dan satu entri
legenda `peta.php` (Peta Bidang). Kunci lapisan, atribut `data-lapisan`, `id`,
warna, gaya Leaflet, dan isi berkas GeoJSON tidak disentuh — JS hanya membaca
`k.dataset.lapisan`, jadi pergantian teks aman.

Pola baru: nama pendek tanpa kode DXF dan tanpa keterangan ganda; keterangan
lapisan asal dipindah ke atribut `title` (20 centang) sehingga penelusur
kembali ke berkas DXF masih bisa, tetapi tidak lagi menjejali label. Item dalam
satu kelompok melepas kata yang diulang oleh judul kelompoknya — "Garis batas
jalan" di bawah keterangan "Garis turunan" cukup "Jalan".

| kunci | sebelum | sesudah |
| --- | --- | --- |
| `020100` | 020100 — bidang & garis | Bidang & garis (16.322) |
| `tampilTitikLama` | Titik tengah bidang | Titik tengah |
| `batas-blok` | Batas blok | Blok ukur (24) |
| `batas-blok-mentah` | Ruas acuan BATAS BLOK | Blok mentah (72) |
| `batas-perairan` | Teks batas perairan | Label perairan (279) |
| `tn-nama` | Nama pada peta | Label nama (4.409) |
| `lb-lai` | Lain-lain / lb_lai | Lain-lain (19) |
| `bidang-hm` | Bidang HM | Bidang (1.496) |
| `hm` | Garis HM | Garis (95) |
| `optimalisasi-hm` | Optimalisasi HM | Optimalisasi (15) |
| `garis-jalan` | Garis batas jalan | Jalan (3.144) |
| `garis-selokan` | Garis selokan / saluran | Selokan (783) |
| `garis-perairan` | Garis batas perairan | Sungai (542) |
| `garis-lain` | Garis 020100 tanpa label | Tanpa label (74) |
| `010500` | 010500 — garis luar | Garis luar desa (1) |
| `020400` | 020400 | Sisa 020400 (2) |
| `calon-bidang` | Calon bidang — garis hampir tertutup | Calon bidang (737) |
| `nib-hm` | NIB HM | Label NIB (1.657) |
| `batas-desa` | Batas desa | Label batas desa (36) |
| `blok` | Blok | Label blok (24) |
| `batas-jalan` | Batas jalan | Label jalan (626) |

Judul kelompok ikut diringkas: `Bidang kadastrum` baru di atas, `Bidang berhak
(HM)` → `Bidang berhak`, `Garis turunan 020100` → `Garis turunan`, `Label &
batas` → `Label teks`, `Belum terpetakan` tetap, dan `Lainnya` baru untuk dua
layer yang sebelumnya nyempil di bawah keterangan garis turunan (`010500` dan
`020400`). Paragraf panjang delapan baris di bawah centang garis turunan
dipangkas menjadi tiga baris dan menunjuk §3.77
untuk perhitungan lengkap; angka kandidatnya (13.719 = 13.681 + 38) tidak
berubah.

Legenda Peta Induk menjadi 22 entri dan cocok dengan nama centang: entri
**garis selokan `#0ea5e9` ditambahkan** — lapisannya sudah ada sejak §3.77
tetapi tidak pernah muncul di legenda. `Ruas DXF mentah (referensi)` → `Blok
mentah`, `Teks perairan / nama` → `Label perairan & nama`, `Bidang batch kembar
(dilewati)` → `Batch kembar`, tiga entri garis turunan kehilangan akhiran
`(turunan)`. Di `peta-blok.php`, `Jalan (OpenStreetMap)` → `Jalan` karena yang
dimuat `api/bidang.php?lapisan=jalan` sejak 10 Nov adalah label DXF `BATAS
JALAN`, bukan garis OSM; `Batas blok perkiraan (garis putus-putus)` → `Blok
perkiraan`. Di `peta.php`, `Batas desa (33240400080)` → `Batas desa`.

**Verifikasi.** `_lama/scratch/cek-label-peta.php`: 20 centang, tidak ada nama
kembar, semua kunci `$jumlah()` dikenal metadata. `_lama/scratch/cek-render-label.php`
merender panel tanpa login dengan stub `$meta`/`e()`/`angka()` — keluarannya
teks plus angka di atas, tanpa peringatan PHP — dan fragmen yang sama difoto
`chrome --headless --screenshot` dengan `assets/css/app.css`: label pendek,
enam judul kelompok, dan legenda 22 swatch (termasuk biru muda selokan) tampil
rapi; `.cek` memakai `display:flex` sehingga baris sumber tambahan di dalam
`<label>` tidak mengubah tata letak. `cek-lapisan-garis.php` tetap
memuaskan keempat lapis garis (whitelist, centang, gaya, legenda "ada"; 20
centang = 20 kunci whitelist API). `php -l` bersih dan `node --check` lolos
untuk ketiga halaman (796 / 878 / 168 baris JS). Grep pada seluruh `*.php` tidak
menemukan sisa teks label lama. Higienitas ikut diukur ulang: `README.md` 6.009
baris dan keempat berkas PHP yang disentuh (`peta-lama.php` 1.170, `peta.php`
1.128, `peta-blok.php` 228, `api/peta-lama.php` 124) seluruhnya CRLF di working
tree, `tools/garis-batas-020100.php` 733 baris LF, tab nol di semuanya, dan
aksara di luar skrip Latin nol; catatan glif diagram README ada di §3.77.
Yang belum diuji hanyalah klik centang di halaman asli (butuh login Super
Admin). Basis data dan berkas data tidak disentuh.

---

### 3.79 Lapis Jalan disambung menurut topologinya: 3.144 menjadi 1.981 baris (12 Nov 2026)

Permintaan kerjanya: **perbaiki lagi garis jalan pada layer Jalan (3.144)
berdasarkan layer Bidang & garis (16.322)**. Yang diperbaiki bukan klasifikasinya
— [§3.73](#373-garis-batas-jalan-batas-perairan-dan-lain-lain-diturunkan-dari-020100-10-nov-2026)
sudah memilih serpihan `020100` dengan benar — melainkan cara lapis itu
disimpan: satu baris untuk **satu** entitas sumber, padahal satu sisi jalan di
digitasi menjadi beberapa serpihan. Diukur dulu pada berkas hasil commit
(`_lama/scratch/ukur-topologi-tmp.php`, dijalankan lalu dihapus; simpul = ujung
yang berjarak ≤ 0,35 m, ambang `TOLERANSI_TEPI` si pembangkit):

| | angka |
|---|---|
| baris | 3.144 · 10.898 titik · 129.268,93 m |
| simpul (0,35 m) | 3.032 |
| derajat simpul | 1 → 928 · **2 → 1.532** · 3 → 404 · 4 → 137 · 5 → 31 |
| median / rata-rata panjang baris | 23,81 m / 41,12 m |
| baris kembar bentuk (deviasi ≤ 0,5 m) | 6 pasang di bahan baku |

Angka **1.532** itu sebabnya lapis ini sukar dibaca: dua potong yang berbagi
satu titik tetap dua baris, `kode`-nya mengikuti urutan serpihan berkas sumber,
dan tidak ada satu baris pun yang mewakili satu koridor utuh.

**Alatnya** `tools/rapikan-garis-jalan.php` (CLI; uji kering bawaan,
`--terapkan` untuk menulis, `--rinci` untuk daftar, `--keluaran=` untuk calon;
ambang `--toleransi=`, `--belok=`, `--duplikat-maks=`). Empat aturannya, dan
yang ke-4 itu bukan hiasan:

1. **Simpul.** Ujung baris yang berjarak ≤ 0,35 m dihitung satu titik. Tidak
   ada koordinat yang digeser; dua ujung yang tidak bertemu tetap dua garis.
2. **Kembar.** Dua garis terbuka yang berbagi **kedua** simpul dan rata-rata
   selisih titik tengah segmennya ≤ 0,5 m adalah tepi yang sama, digambar dua
   kali; yang lebih sedikit titiknya (lalu lebih pendek) dibuang. Enam pasang
   kena pada bahan baku, semuanya berselisih 0,00–0,03 m: `#2365↔#759`,
   `#774↔#2346`, `#2671↔#1115`, `#2119↔#2131`, `#2175↔#2252`, `#2377↔#2510`;
   satu pasang lagi baru muncul sesudah barisnya disambung (aturan 3) dan
   dibahas di bawah. Sepasang tepi sejajar sebuah jalan (1–15 m) tidak ikut
   terbuang karena selisih bentuknya jauh di atas ambang itu.
3. **Sambungan.** Simpul yang tepat memegang **dua** baris menyatukan keduanya
   selama belokan di simpul itu ≤ 40°. Simpul persimpangan (derajat ≥ 3) tidak
   disentuh, cincin tidak dipecah. Histogram belokan di 1.503 simpul berderajat
   2 yang sempat dinilai — 0° 996 · 10° 111 · 20° 32 · 30° 13 · 40° 14 · 50° 15
   · 60° 18 · 70° 30 · 80° 108 · 90° 101 · 100° 28 · sisanya 37 — punya lembah
   di 30–50° dan puncak di 70–90°, jadi ambang 40° jatuh di lembah itu: belokan
   kecil adalah lanjutan koridor, puncak 70–90° adalah sudut jalan yang memang
   harus tetap terputus.
4. **Verifikasi.** Setiap baris asal harus cocok titik-demi-titik dengan
   entitas `020100`-nya lewat properti `indeks`; setiap titik hasil harus pernah
   menjadi titik salah satu baris asal; panjang total hanya boleh berkurang
   sebesar kembar yang dibuang.

| | sebelum | sesudah |
|---|---|---|
| baris | 3.144 | **1.981** (389 di antaranya kedua ujungnya jatuh di simpul yang sama; dihitung dengan koordinat persis, 385 benar-benar tertutup) |
| panjang | 129.268,93 m | 129.175,09 m |
| titik | 10.898 | 9.737 |
| simpul (0,35 m) | 3.032 | 1.876 |
| derajat 1 / 2 / 3 / 4 / 5 | 928 / 1.532 / 404 / 137 / 31 | 941 / 372 / 396 / 136 / 31 |
| median / rata-rata / maksimum | 23,81 / 41,12 / 1.268,03 m | 41,12 / 65,21 / 1.630,48 m |
| ukuran berkas | 1.184.649 B | 963.370 B |
| komponen terhubung | 540 | 540 |

**Dijalankan dua kali, diuji untuk ketiga kalinya.** Putaran pertama membuat
1.155 sambungan dan membuang 6 baris kembar (3.144 → 1.983). Pada hasil itu
muncul satu kembar yang sebelumnya tidak terlihat: `JALAN-020100-0028`
(rangkaian baris asli #90 + #91, 24,88 m) ternyata berbagi kedua simpul dengan
satu garis tunggal asli #2824 (indeks sumber 12492, 25,22 m) dengan deviasi
0,042 m — sebelumnya ujung-ujungnya dipegang empat baris berbeda sehingga
aturan "kedua simpul" belum terpenuhi. Putaran kedua membuang #2824 dan
menyambung satu simpul derajat 2 lagi (belok 1,3°): **1.981 baris**. Uji ketiga
melaporkan 0 kembar dan 0 sambungan, dan berkas yang ditulis ulang identik
bita-demi-bita (MD5 `afcf58404e15881c9240f2cfa73cbebe` sebelum dan sesudah) —
jadi angka itu titik tetapnya. Salinan cadangan dan jejak uji ketiga dibuang
karena tidak berisi pekerjaan apa pun; yang tersisa tinggal bekasnya sebagai
penghitung `putaran` 3 di blok `dirapikan` `metadata.json`.

Sambungan dibuat **1.156** (1.155 + 1); 351 simpul berderajat 2 ditolak pada
putaran pertama dan 345 pada keadaan akhir karena belokannya > 40°. Jumlah
simpul turun persis sebesar sambungan yang terjadi (3.032 − 1.156 = 1.876).
Simpul berderajat satu naik 928 → 941 karena tujuh baris kembar yang dibuang
meninggalkan ujung pasangannya sendirian. Dari 1.156 sambungan, **1.147**
membuang satu titik (dua baris bertemu di koordinat yang sama persis);
sembilan sisanya mempertahankan kedua titik sumber dan menarik ruas penghubung
hairline — itu satu-satunya garis baru yang dibuat alat ini.

**Tuntasan pemeriksaan.** 3.144 dari 3.144 baris bahan baku cocok penuh dengan
entitas `020100`-nya, 0 sebagian, 0 tanpa `indeks`; pada lapis akhir 1.981 dari
1.981 baris juga cocok penuh — uji keanggotaan titik dipakai terhadap gabungan
`indeks_all`, sebab satu baris hasil bisa mewakili beberapa entitas sumber.
**0** titik hasil yang bukan titik baris asal. Neraca titik: 10.898 − 1.147
(satu titik tiap sambungan yang presisi) − 14 (tujuh baris kembar × dua titik)
= 9.737. Diperiksa terpisah pada tingkat segmen
(`_lama/scratch/ukur-sisi-tmp.php`, dijalankan lalu dihapus): bahan baku 6.035
bentuk sisi berbeda, hasil 6.039; **9** sisi hasil tidak ada di bahan baku —
ruas jembatan antara dua salinan simpul yang bergetar, panjangnya 0,017–0,262 m
dengan total 1,254 m, di baris 22, 43, 195, 207, 236, 680, 922, 1182 dan 1314;
**5** sisi bahan baku tidak lagi ada, semuanya tepi tunggal baris kembar yang
dibuang (`#774`, `#2175`, `#2365`, `#2377`, `#2824`) — dua korban lain
(`#2119`, `#2671`) geometrinya identik dengan pasangannya (deviasi 0,000 m)
sehingga sisinya tetap terbaca. Neraca panjang: 129.268,93 − 95,08 (kembar)
= 129.173,85 ditambah jembatan 1,254 m = 129.175,10, sedangkan geometri mentah
hasilnya 129.175,0929 m — selisih 0,011 m, sama dengan pembulatan dua angka
setiap angka neraca itu sendiri. Jumlah properti `panjang_m` per baris memberi
129.175,13, yaitu 0,04 m lebih besar, karena tiap baris dibulatkan sendiri. `jumlah_bagian` seluruh
hasil berjumlah 3.137 = 3.144 − 7, sebarannya 1 bagian 1.539 baris · 2 → 193 ·
3 → 95 · 4 → 48 · 5 → 32 · 6 → 28 · sampai maksimum 16. `kode` dinomori ulang
0001–1981 runtut dan unik, urut menurut `indeks` sumber terkecil tanpa satu
penurunan pun. Sudut belokan ke-1.156 sambungan: median 2,6°, rata-rata 4,8°,
terbesar 39,8°; delapan di antaranya ≥ 35° dan **tidak ada** yang mencapai
ambang 40°.

**Simpul yang sudah dikenal dari review halaman per halaman** diuji ulang
terhadap cadangan sebelum rapikan (`_lama/scratch/cek-belok-tmp.php`, dijalankan
lalu dihapus) supaya keputusan alat bisa dibandingkan dengan pengamatan mata;
sudutnya diukur pada bahan baku. #499↔#501 belok 91,0° (derajat 2) dan
#501↔#500 89,5° (derajat 2) → **dibiarkan**, sesuai dugaan bahwa rantai itu
berbelok siku; ketiganya tetap tiga baris tunggal (`JALAN-020100-0337` 27,85 m,
`-0338` 28,19 m, `-0339` 39,90 m). #253↔#254 98,7° dan #254↔#255 83,8°
sama-sama di simpul **derajat 3** → dua alasan untuk tidak menyambung, dan
keempatnya memang keluar sebagai empat baris tunggal (`-0160` 44,71 m, `-0161`
44,22 m, `-0162` 60,04 m, `-0163` 107,21 m). #502↔#505 90,2° di simpul empat
baris 263.470,29 · 715.778,76 → tidak disentuh (`-0340` 23,01 m, `-0343`
14,41 m). #325↔#326 bahkan tidak berbagi simpul (ujungnya berselang 1,06 m).
Yang seharusnya menyatu juga cocok, dan tiap sambungan bisa dibaca angkanya di
jejak: rantai yang dulu tercatat terputus-putus #323+#322+#321 (119,16 + 17,59
+ 71,09 m) tersambung dengan belokan 0,4° dan 7,8°, lalu bertambah dua anggota
lagi (#808 20,79 m, #811 30,47 m; 0,3° dan 1,3°) menjadi satu baris
`JALAN-020100-0216` 259,11 m lima bagian; #497+#498 (20,20 + 20,63 m) menyeret
#167 (22,45 m, belokan 1,0° dan 0,5°) menjadi `JALAN-020100-0078` 63,28 m.
Rantai paling panjang hasil semuanya `JALAN-020100-0977`: 16 baris asli
(#1468–#1476, #1483, #1484, #1493, #1946–#1948, #2185) menjadi 766,36 m lewat
15 sambungan bersudut 0,0°–24,0°. Yang lebih pendek tapi dulu mencolok:
`-0166` #259+#681+#682 = 50,15 m (0,2° dan 3,5°), `-0168` #261+#683+#2336 =
72,70 m (4,4° dan 5,1°), `-0879` #1336+#1676 = 32,20 m (8,4°), `-1443`
#2379+#2620 = 25,33 m (0,2°).

**Cincin tidak dipecah, dan aturannya memang tidak pernah menutup dua cincin
jadi satu.** Lima baris
`asal: bidang` — satu-satunya yang sumbernya cincin poligon, bukan garis
terbuka — adalah baris asli #3140–#3144 (indeks 4383, 5065, 14265, 11936,
12901; 195,7 / 181,38 / 117,03 / 84,95 / 65,84 m). Kelimanya tertutup sejak
awal dan keluar apa adanya sebagai satu bagian di `-0690`, `-0799`, `-1720`,
`-1589` dan `-1710`. Dua cincin lain yang pernah dicurigai berlebih, #2918
(267,95 m) dan #2919 (157,09 m), juga tetap baris tunggal.

**Yang sengaja tidak dikerjakan.** Celah digitasi tidak dijembatani dan tidak
ada geometri yang dikarang, jadi sisa fragmentasi dilaporkan apa adanya: 941
ujung bergantung, jaraknya ke ujung bergantung terdekat terpita 85 ≤ 2 m · 327
2–10 m · 482 10–50 m · 47 > 50 m. Komponen tetap 540 — menyambung di dalam
satu komponen tidak mengurangi jumlahnya, dan alat ini memang tidak pernah
menyambung dua komponen. `garis-selokan`, `garis-perairan` dan `garis-lain`
tidak disentuh sehingga pembagian kelas hasil
[§3.77](#377-garis-turunan-020100-dipecah-jalan--selokan--perairan-10-nov-2026)
tetap eksklusif.

**Sajian di Peta Induk tidak diubah.** Panel membaca angka dari metadata
(`peta-lama.php:20` → `api/peta-lama.php:44`), jadi centang *Jalan* kini
menampilkan **Jalan (1.981)** tanpa satu baris kode pun berubah; grep pada
`*.php` di luar `_lama/` hanya menemukan angka 3.144 di blok dokumen alat baru
yang memang menggambarkan bahan bakunya. `metadata.json` ikut diperbarui:
`features` 1.981, `panjang_m` 129.175, `cara` pita1 1.036 · benih 568 · pita2
377 (diwarisi bagian terpanjang), `asal` garis 1.976 · bidang 5, plus blok
`dirapikan` yang **akumulatif**: `putaran` 3, `pertama_pada` 10:10:53,
`sebelum_baris` 3.144 dan `sebelum_panjang_m` 129.268,93 (keadaan bahan baku,
bukan hasil putaran sebelumnya), `duplikat_dibuang` 7, `duplikat_panjang_m`
95,08, `sambungan` 1.156, `jembatan_m` 1,254 — sedangkan `cincin` 389,
`ujung_bergantun` 941, `pita_jarak`, `simpul_ditolak_belok` 345 dan
`belokan_dinilai` menggambarkan keadaan sesudah putaran terakhir, seperti yang
dijelaskan kunci `catatan` di dalam blok itu sendiri. Akumulasi hanya
diteruskan kalau ambangnya sama; ganti `--belok=` lalu jalankan ulang, maka
bloknya dimulai dari nol. Properti baru tiap fitur: `indeks_all` (daftar
entitas sumber yang sudah disatukan), `jumlah_bagian`, `dirapikan`. Pada baris
hasil sambungan, `rasio_tepi_bidang` dan `jarak_label_m` adalah rata-rata
tertimbang panjang dan `cara`/`label` diwarisi bagian terpanjang — karena
`jarak_label_m` punya dua makna (`cara = benih`: jarak ke label; `pita1`/`pita2`:
lebar koridor ke tetangga sejajar), nilai campuran tidak boleh dibaca sebagai
salah satunya. Contoh yang terukur: `-0977` (16 bagian) berjarak 1,88 m,
`-0216` (5 bagian) 1,12 m, `-0078` (3 bagian) 4,87 m.

**Basis data tidak disentuh** — alat ini bahkan tidak membuka koneksi (grep
`db_` pada `tools/rapikan-garis-jalan.php` kosong); satu-satunya bahan baca di
luar lapis itu adalah `020100.geojson` untuk verifikasi. Cadangan bahan baku
1.184.649 B di `garis-jalan-sebelum-rapikan-20261112-101053.geojson`,
hasil antara (1.983 baris, 964.081 B) di
`garis-jalan-sebelum-rapikan-20261112-102800.geojson` — keduanya ditulis ke
`storage/cadangan/` oleh alat itu (perilaku bawaannya tidak berubah) dan dipindah
ke `D:/cadangan-sigpetades/2026-11-13/` pada 13 Nov 2026, MD5
`17b2f2eae32f4346eb00692f6ebdcd73` dan `732cb4a4e421cc5b905a9c48906fd209` sama
sebelum dan sesudah pindah; jejak
lengkap 253.097 B dan 2.153 B di `storage/rapikan-jalan-20261112-101053.json`
dan `...-102800.json`, masing-masing berisi daftar sambungan beserta bagian,
sudut dan titik yang dibuang. Higienitas: alat 747 baris LF tanpa tab (0 CRLF),
`php -l` bersih; `README.md` seluruhnya CRLF sebelum dan sesudah bagian ini;
skrip ukur hanya hidup sebentar di `_lama/scratch/` dan sudah dihapus — folder
itu tidak ikut ke dalam repo; aksara di luar skrip Latin nol di keduanya selain
glif matematika (≤ ≥ ≈ −) yang sudah dipakai luas di dokumen ini — termasuk
dua aksara Mandarin yang menyelinap di paragraf "Tuntasan pemeriksaan" ketika
draf ini ditulis dan dikoreksi di tempat sebelum bagian ini dianggap selesai.

---

### 3.80 Garis Jalan dua tahap dari label: tepi lalu sumbu (13 Nov 2026)

Permintaan kerjanya: **Buatkan tools membuat garis turunan Jalan berdasarkan
Label jalan dari layer Bidang kadastrum Bidang & garis**. Dua keputusan bentuk
keluaran yang diambil: **tepi dan sumbu keduanya** — satu alat, dua tahap, satu
berkas per tahap, bisa dipilih lewat flag — dan **sumber geometrinya
kedua-duanya**, lapis berkas `020100` *dan* tabel `bidang`, bukan salah satunya.

**Alatnya** `tools/garis-jalan-label.php` (CLI, 1.287 baris; uji kering bawaan,
`--terapkan` baru menulis):

| flag | arti |
|---|---|
| `--tahap=tepi\|sumbu\|semua` | bawaan `semua` |
| `--sumber-tepi=turun\|terbit` | `turun`: tepi hasil tahap 1; `terbit`: lapis Jalan terbit `garis-jalan.geojson` dipakai apa adanya dan tahap 1 dilewati |
| `--lebar=M` | lebar koridor maksimum tahap 2, bawaan `8,0` m |
| `--bagi-pasang=F` | bagian titik sampel sebuah baris yang harus berpasangan; bawaan `0` = tiap titik tengah yang lolos semua syarat ikut terpakai |
| `--eksklusif` | label jalan harus yang terdekat; kandidat yang label terdekatnya kelas lain dibuang |
| `--rinci` | cetak 40 sumbu terpanjang |

**Bahan yang dibaca.** Tabel di bawah adalah isi `020100.geojson` dan hasil
`SELECT` biasa pada `bidang` — tidak ada satu baris pun yang ditulis, dan
tidak ada skrip pengubah geometri yang dilibatkan.

| bahan | yang terbaca | yang dipakai |
|---|---|---|
| `020100.geojson` | 13.681 garis terbuka + 2.626 cincin poligon | 13.681 garis + 38 cincin (2.588 cincin ≥ 50 % titiknya sudah tergambar sebagai garis lain) |
| tabel `bidang` (dibaca saja) | 4.173 persil `status <> 'dihapus'`, 4.176 cincin (2 persil MultiPolygon) | 2 cincin jadi kandidat tepi; 4.171 dibuang karena mengulang |
| gabungan untuk uji koridor | 56.315 segmen tepi pada kisi 5 m · 185.309 petak permukaan | sisi kiri-kanan sebuah jalan selalu berhadapan ruang kosong, sisi bidang selalu berhadapan bidang lain |
| `batas-jalan` + `batas-perairan` | 899 label (jalan 622 · selokan 165 · perairan 112) | hanya teks berawalan `JALAN` yang jadi benih; kelas dibaca dari TEKS, bukan dari berkasnya |

**Tahap 1 — tepi (`garis-jalan-tepi.geojson`).** Aturannya disengaja identik
dengan kelas jalan pada
[§3.73](#373-garis-batas-jalan-batas-perairan-dan-lain-lain-diturunkan-dari-020100-10-nov-2026)
supaya hasilnya sebanding: benih = kandidat yang punya titik sampel ≤ 2,0 m dari
label jalan, pita = kandidat yang ≥ 50 % titik sampelnya punya segmen terklaim
sejenis ≤ 15 m dengan beda arah ≤ 25° **dan** tengah koridornya ≥ 60 % tidak
berada di dalam bidang, dua putaran, garis < 2,0 m dibuang.

Angka-angka di bawah adalah keadaan radius benih **2,0 m**. Bawaan alat ini
sendiri dinaikkan menjadi 3,0 m pada 14 Nov (§3.82), jadi tabel itu tidak lagi
menggambarkan berkas yang terbit; `--benih=2` mencetaknya ulang secara persis.

| | angka |
|---|---|
| baris tepi | **3.323** · 11.969 titik · 143.106,1 m · 1.299.654 B |
| `cara` | pita1 1.891 · pita2 590 · benih 842 |
| `asal` | garis 3.316 · cincin-020100 6 · cincin-bidang 1 |
| calon yang ditolak | tolak sejajar 9.993 · tolak koridor 805 · pendek < 2 m 396 |
| benih berlabel kelas lain | 34 — tetap diklaim (bawaan), baru dibuang bila `--eksklusif` |

Kolom-kolom penolakan itu **tidak boleh dijumlahkan**: 9.993 + 805 + 396 +
3.323 = 14.517, lebih besar daripada 13.721 calon, karena sebuah kandidat yang
kandas pada putaran pertama masih bisa terklaim pada putaran kedua (dan
sebaliknya). Tiap kolom sendiri sudah bebas dari hitung ulang — kuncinya
dikumpulkan dalam himpunan (`$tolakSejajar` dan `$tolakKoridor` di
`tools/garis-jalan-label.php:933` dan `:934`, diisi di `:962` dan `:966`)
sehingga calon yang tertolak di kedua putaran hanya muncul sekali
di kolom itu. Angka 34 yang disebut terakhir bukan penolakan: pada bawaannya
ia tetap menjadi benih, dan justru itulah selisih 842 − 808 benih antara jalan
bawaan dan jalan `--eksklusif` di bawah.

Enam baris `asal cincin-020100` menutup daftar dan lima di antaranya cocok
persis dengan lima baris cincin yang sudah dikenal dari
[§3.79](#379-lapis-jalan-disambung-menurut-topologinya-3144-menjadi-1981-baris-12-nov-2026):
indeks 4383 · 5065 · 14265 · 11936 · 12901 menjadi `JALAN-020100-0690`, `-0799`,
`-1720`, `-1589` dan `-1710` dengan panjang 195,70 · 181,38 · 117,03 · 84,95 ·
65,84 m. Dua baris penutup lainnya justru **tidak** ada di lapis terbit dan
termasuk 186 sumber tambahan itu: cincin 020100 indeks 1950 (61,06 m) dan
cincin tabel bidang indeks 19338 (169,90 m) — yang kedua memang satu-satunya
baris `sumber: tabel-bidang`.

**Hasilnya superset dari lapis terbit, bukan penggantinya.** Diperiksa dengan
mencocokkan properti `kunci` tiap baris baru (`G:`/`P:` untuk entitas `020100`,
`B:` untuk cincin tabel bidang) terhadap gabungan `indeks_all` lapis Jalan
terbit 1.981 baris hasil
[§3.79](#379-lapis-jalan-disambung-menurut-topologinya-3144-menjadi-1981-baris-12-nov-2026)
(`_lama/scratch/cek-tepi-vs-terbit.php`, masih ada): **3.137 dari 3.137 indeks
sumber terbit ada di bahan baru (100,0 %)**, 1.981 baris terbit utuh
(0 sebagian, 0 hilang), dan bahan baru kelebihan **186 sumber** (185 entitas
`020100` + 1 cincin tabel bidang) sepanjang 13.932,2 m. Selisih itu punya
sebab yang bisa disebut: `tools/garis-batas-020100.php` mengklaim satu garis
untuk **satu** kelas sehingga tepi yang juga terjaring selokan atau perairan
keluar dari lapis Jalan, sedangkan alat ini hanya memandang Jalan dan tidak
memiliki kelas pesaing; satu tambahan lagi memang berasal dari tabel bidang,
yang tidak pernah dibaca sebagai kandidat oleh si pembangkit.

**Tahap 2 — sumbu (`garis-jalan-sumbu.geojson`).** Empat langkah, semuanya
topologi plus satu rata-rata koordinat:

1. ujung tepi yang berjarak ≤ 1,0 m dihitung simpul yang sama, dan potongan
   yang berbagi simpul digabung menjadi satu **sisi** lewat union-find — dua
   baris yang sebenarnya satu sisi jalan tidak boleh berpasangan satu sama
   lain;
2. tiap titik sampel sisi A dicari sisi B terdekat dengan syarat B bukan
   sisinya sendiri, lebar koridor ≥ 0,25 m dan ≤ `--lebar`, beda arah ≤ 25°,
   dan tengah antara keduanya tidak berada di dalam bidang;
3. titik tengah semua pasangan direnggutkan pada ragum 1,2 m; titik tengah dari
   sisi kiri dan kanan sebuah jalan lurus jatuh di tempat yang sama sehingga
   keduanya menyatu jadi satu rantai, dan koordinat tiap simpul hasil adalah
   **rata-rata** seluruh titik yang masuk ke simpul itu;
4. rantai diputus pada simpul berderajat ≠ 2 (ujung buntu dan persimpangan),
   jalur < 2,0 m dibuang, sisanya dinomori dari yang terpanjang.

| | angka |
|---|---|
| komponen sisi | 501 |
| baris tepi yang menyumbang titik tengah | 2.444 dari 3.323 |
| baris tanpa sisi seberang sama sekali | 879 (24.184,3 m) |
| sisi sejajar tapi koridornya selalu di dalam bidang | 151 |
| pasangan sisi berbeda · untai titik tengah | 587 · 2.444 |
| rangka sumbu | 13.940 simpul · 19.140 batang · 645 simpul berderajat ≥ 3 |
| **segmen sumbu** | **1.816** · 15.291 titik · 30.805,2 m · 1.152.331 B |
| lebar timbang panjang | 3,07 m · pita lebar ≤ 1 m 57 · 1–2 m 395 · 2–4 m 792 · 4–6 m 478 · > 6 m 94 |
| lebar per segmen | median 3,02 m · terkecil 0,43 m · terbesar 7,96 m |
| panjang per segmen | median 8,46 m · terbesar 285,79 m (`SUMBU-0001`, 137 titik, 9 tepi) · 118 untai < 2 m dibuang |
| jarak tiap titik sumbu ke tepi terdekat | median 1,37 m · maksimum 4,00 m · 15.291 titik diuji · 0 tidak menemukan tepi dalam 10,0 m |

Maksimum 4,00 m itu bukan kebetulan melainkan plafon geometrisnya: sebuah titik
tengah hanya lahir dari sepasang tepi yang berjarak ≤ `--lebar` 8 m, jadi ia
tidak pernah lebih jauh dari 4 m dari salah satunya. Diagnostik sisi tunggal
ikut dilaporkan dan **tidak** mengubah hasil: dari 879 baris tak berpasangan,
sisi sejajar terdekatnya berada pada ≤ 2 m 19 · 2–4 m 52 · 4–8 m 28 · 8–15 m
206, dan **574 baris tidak punya sisi sejajar sama sekali** dalam jangkauan uji
15 m — itu bagian jalan yang di digitasi memang hanya digambar satu garis, jadi
tidak ada yang bisa dirata-ratakan.

Ada 176 segmen (2.946,4 m, 9,6 % panjang sumbu) yang `jumlah_tepi`-nya 1: titik
tengahnya datang dari satu sisi saja, karena titik tengah sisi seberangnya pada
jalur yang sama masuk ke simpul renggutan lain (selisihnya melewati ragum 1,2 m, atau
sisi seberang itu pasangan utamanya adalah sisi yang berbeda). `lebar_m`-nya
tetap terukur dan geometrinya tetap di tengah koridor; yang berkurang hanya
bukti silang dua sisinya.

**Kenapa `--bagi-pasang` bawaannya 0.** Ambang per baris membuat cakupan sumbu
bergantung pada seberapa pendek baris tepinya, bukan pada geometri jalan.
Diukur pada bahan yang sama, 13 Nov 2026 (tiga modus terakhir uji kering, tidak
menyentuh berkas):

| modus | baris tepi terpakai | segmen sumbu | panjang sumbu |
|---|---|---|---|
| bawaan (`0`) | 2.444 dari 3.323 | 1.816 | 30.805,2 m |
| `--bagi-pasang=0,50` | 1.773 | 1.428 | 26.951,0 m |
| `--sumber-tepi=terbit` (1.981 baris tepi terbit) | 1.304 | 1.189 | 24.316,5 m |
| `--eksklusif` | 3.170 baris tepi | 1.737 | 29.225,9 m |

Angka `--eksklusif` turun 153 baris tepi, bukan 34 seperti jumlah penolakannya,
karena sebuah benih yang ditolak pada putaran pertama tidak lagi bisa
menarik pita di putaran kedua. Pada ambang 0,50 yang 1.550 baris tanpa pasangan
itu terbagi menjadi **671 yang kandas karena ambangnya sendiri** dan 879 yang
memang tidak punya sisi seberang — angka 879 itu sama persis dengan jumlah
baris tak berpasangan pada bawaan di atas, jadi ambang 0,50 hanya membuang
baris yang separuh panjangnya sudah menemukan pasangan.

**Koma desimal sempat diabaikan alat ini.** `--bagi-pasang` dan `--lebar`
dibaca dengan `(float) substr(...)`, dan `("0,50")` adalah `0.0` di PHP, jadi
tiga baris pertama tabel di atas semula dihasilkan angka yang sama persis
walaupun ambangnya ditulis berbeda. Sekarang keduanya lewat `angkaFlag()`
(`tools/garis-jalan-label.php:213`, `function angkaFlag`) yang menukar koma
menjadi titik lalu menolak nilai yang bukan angka dengan pesan
`GAGAL: --bagi-pasang membutuhkan angka`
— sehingga `0,50`, `0.50` dan `0,5` setara, dan nilai sampah tidak lagi
berarti "pakai bawaan". Bawaannya sendiri tidak tersentuh perubahan ini.

**Titik tetap.** `--terapkan` dijalankan tiga kali (10:46, 10:51 dan 11:13,
jalan ketiga sesudah koreksi koma di atas) dan kedua berkas GeoJSON-nya identik
bit:
SHA-256 `54f30b9909866794283ded5ab8b11fec2433300862ce636f91bf640a38fc60f5`
untuk `garis-jalan-tepi.geojson` dan
`57a027dcd451be2c48a4738a6ef967c3158e4e737d7a1ac4fd0e38a21682fe2f` untuk
`garis-jalan-sumbu.geojson` pada ketiga kalinya — yang berubah di antara tiga
jalan itu hanya kalimat catatan dan `derived_at` di `metadata.json`, jadi
urutan dan angkanya tidak bergantung pada waktu jalan maupun pada dua suntingan
perbaikan teks yang disisipkan di antaranya.

**Properti tiap fitur.** Tepi: `kode` `TEPI-0001`…`TEPI-3323`, satu baris per
sumber, dinomori menurut urutan bahan (3.316 garis terbuka naik menurut
`indeks`-nya tanpa satu penurunan pun, lalu 6 cincin `020100` dan 1 cincin
bidang di belakang), disertai `kunci`, `sumber` (`020100` / `tabel-bidang`),
`indeks`, `panjang_m`, `rasio_tepi_bidang`, `asal`, `cara`, `jarak_label_m` dan
`label`. Perhatian: `jarak_label_m` punya **dua makna** menurut `cara` — jarak
ke label untuk `benih`, lebar koridor ke tetangga sejajar untuk
`pita1`/`pita2` — sama seperti di
[§3.79](#379-lapis-jalan-disambung-menurut-topologinya-3144-menjadi-1981-baris-12-nov-2026).
Sumbu: `kode` `SUMBU-0001`…`SUMBU-1816` **urut panjang menurun** (0 naik di
antara 1.177 perbandingan bertetangga), `sumber` `label-jalan`, `panjang_m`,
`lebar_m`, `jumlah_bagian` (titik), `jumlah_tepi`, `tepi_all` (daftar `kunci`
tepi yang ikut), `asal`, `cara_tepi`, `cara` = `sumbu`, `label`.
`metadata.json` mendapat dua blok lapisan baru, `GARIS JALAN TEPI DARI LABEL`
(`ambang`, `label`, `permukaan_bidang`, sebaran `asal` dan `cara`) dan
`GARIS JALAN SUMBU DARI LABEL` (`ambang`, `sisi`, `lebar_m` beserta pita
lebarnya, `verifikasi`); catatan penolakan tahap 1 tidak ikut ditulis ke sana,
hanya ke keluaran CLI. Satu entri `notes` akar ditulis ulang tiap jalan
(ditumpuk, bukan ditambah) beserta `derived_at`-nya; kedua berkas itu
**tidak** mengubah `features` lapis mana pun yang sudah ada.

**Yang sengaja tidak dikerjakan.** Tidak ada verifikasi titik-demi-titik terhadap
sumber seperti pada si perapian, sebab koordinat sumbu memang **baru** —
rata-rata dua tepi, dan itulah seluruh isi tahap 2; yang dilaporkan sebagai
gantinya adalah jarak tiap titik sumbu ke tepi terdekat. Celah digitasi tidak
dijembatani. `garis-selokan`, `garis-perairan` dan `garis-lain` tidak disentuh,
dan `garis-jalan.geojson` terbit juga tidak diubah: ini lapisan baru di
sebelahnya, bukan perbaikan atas yang lama. Pada saat bagian ini ditulis
**keduanya belum dipasang di panel Peta Induk** — grep pada seluruh `*.php` di
luar `_lama/` hanya menemukan nama berkasnya di dalam alat itu sendiri
(`tools/garis-jalan-label.php:116` dan `:117`, `BERKAS_TEPI` dan `BERKAS_SUMBU`),
jadi belum ada satu pun halaman atau API yang menayangkannya, dan belum ada
satu pun yang dilihat di browser.
Yang ada baru pemeriksaan tingkat berkas:
keduanya dibaca ulang setelah ditulis dan seluruh 5.139 fitur bertipe `LineString`
dengan koordinat numerik.

**Dipasang di panel Peta Induk (13 Nov, sesudah paragraf di atas).** Dua kunci
masuk ke daftar putih `api/peta-lama.php`: `garis-jalan-tepi` dan
`garis-jalan-sumbu`, masing-masing menunjuk berkas bernama sama di folder
`dxf-layers`. Jalan keluar tetap lewat daftar putih, jadi parameter `lapisan`
tidak pernah menyusun path secara bebas dan folder `files/` tetap ditolak
Apache. Di `peta-lama.php` sebuah kelompok baru bertanda "Turunan Jalan dua
tahap" diberi dua pil tepat di bawah kelompok "Garis turunan": judulnya
`Layer GARIS JALAN TEPI DARI LABEL` dan `Layer GARIS JALAN SUMBU DARI LABEL`
(kunci `metadata.json` yang sudah ditulis alat ini), teks pendek "Tepi jalan
(3.323)" dan "Sumbu jalan (1.816)" sesuai konvensi §3.78 — kedua angka itu dihitung
dari `metadata.json` saat halaman dimuat, jadi yang terbaca adalah 1.721 untuk
sumbu pada 14 Nov pagi, 1.961 sejak ambang 3 m diadopsi (§3.82), dan 1.856 sejak
celah antaruntaian dijahit (§3.83) — gaya garis `#14b8a6`
lebar 1,1 dan `#9d174d` lebar 2,2, serta dua swatch legenda. Saat pertama
dipasang keduanya masih mati bawaan, seperti semua lapis turunan lain; atas
permintaan 14 Nov keduanya kini `checked`, jadi tepi dan sumbu ikut tergambar
begitu halaman selesai memuat (urutan muat: 020100, tepi, sumbu, baru calon
bidang; tambahan berkas pada saat itu 1.299.654 B tepi dan 1.152.331 B sumbu,
ukuran hari ini di §3.82). Tetap hanya ada di panel Peta
Induk — Peta Bidang tidak disentuh (§3.75). Muat halaman tidak berubah:
`pasang()` di naskah JS sudah memetakan kunci `data-lapisan` ke API yang sama
dan lingkaran awal hanya memanggil yang tercentang.

Verifikasinya masih tingkat statis, karena halaman minta login Super Admin dan
akun uji sudah dihapus (§3.72): `php -l` bersih pada kedua berkas, `node --check`
bersih atas 800 baris JS nowdoc halaman, dan `_lama/scratch/cek-pasang-lapis-jalan.php`
mencetak 22 kunci daftar putih = 22 kunci `data-lapisan` di panel, kedua berkas
GeoJSON ada, cabang gaya dan swatch legenda ditemukan untuk keduanya, jumlah
fitur nyata sama dengan yang dibaca panel dari `metadata.json` (3.323 dan 1.816,
seluruhnya `LineString`; panjang terukur 143.106,1 m dan 30.805,2 m — keduanya
diukur ulang hari ini langsung dari berkasnya, dan angka sumbu itu keadaan 13 Nov,
lihat §3.82). Perubahan
ini semula dibiarkan di ruang kerja tanpa commit, karena perintahnya "pasang
langsung tanpa melalui git"; commit berikutnya yang memuat bagian ini
mengikutkannya ke `main`.

**Higienitas.** Alat 1.287 baris, LF semua (0 CRLF), 0 tab, `php -l` bersih, dan
aksara di luar Latin-1 di dalamnya cuma tiga tanda pisah (U+2014) pada blok
dokumennya — ambangnya ditulis `<=` dan `>=`, bukan glif perbandingan. Satu
`parse error` (kurung kurawal tak tertutup pada blok `validasi baca ulang`)
timbul pada jalan `--terapkan` pertama hari ini, diperbaiki, lalu seluruh angka
di atas diukur dari jalan sesudahnya. `README.md` masih CRLF seluruhnya, 0 LF
lepas dan 0 tab; sebelum bagian ini dimulai berkasnya mempunyai 6.248 pemisah
baris, 383.755 B di indeks blob dan 390.003 B sebagai berkas kerja CRLF (jumlah
sesudahnya sengaja tidak dikutip karena paragraf ini sendiri
masih akan disunting), dan aksara di luar Latin-1-nya hanya tanda baca dan glif
yang sudah dipakai luas di dokumen ini
(`– — … → ↔ − ≈ ≠ ≡ ≤ ≥` serta garis kotak U+2500…); nol han, hiragana,
katakana, hangul dan form penuh di kedua berkas — diukur per codepoint dengan
`pindai-glif-20261113.php`, yang juga memergoki satu glif Mandarin yang
menyelinap ke paragraf `jumlah_tepi` di atas dan dikoreksi di tempat sebelum
bagian ini dianggap selesai. Skrip penghitungnya tinggal di `_lama/scratch/`
dan tidak ikut ke repo: `cek-tepi-vs-terbit.php` (cocokkan superset),
`ringkas-sumbu-20261113.php` (kualitas sumbu) dan `pindai-glif-20261113.php`
(glif + baris, kini menerima lebih dari satu berkas dan mencetak daftar codepoint
asing lengkap dengan jumlahnya).

### 3.81 Blok diambil otomatis dari titik pusat bidang penanda (13 Nov 2026)

Cabang `fitur/simpan-bidang-garis-baru` diminta masuk ke `main`. Sebelum ada
yang digerakkan, cabang itu dibaca dengan `git merge-tree --write-tree` saja
(ruang kerja tidak disentuh): tiga berkas konflik
(`api/penanda-bidang.php`, `peta-blok.php`, `peta-lama.php`) dan satu
regresi yang tidak terlihat dari daftar nama — `api/bidang.php` hasil
penggabungan kembali memuat tiga rujukan `jalan_osm`, jadi menggabungkan
cabang akan menghidupkan kembali garis jalan OSM yang dihapus pada §3.75.
Sisanya sudah lewat zaman: `garis-baru` dan potongan kantong terbuka sudah ada
di `main`, penyederhanaan Jalan/Sungai sekarang tinggal di `app/peta-rapi.php`,
dan cabang tidak membawa apa-apa lagi yang belum. Yang benar-benar hilang
cuma satu perilaku: **blok ditetapkan otomatis dari titik pusat bidang**.
Keputusan user: perilaku itu dipindahkan, cabang tidak digabungkan.

**Yang dipasang** `blokPusatBidang()` di `api/penanda-bidang.php` — satu
pencarian per bidang, dipakai dua jalur simpan:

```sql
SELECT id, kode
  FROM blok
 WHERE geom IS NOT NULL
   AND batas_perkiraan = 0
   AND ST_Contains(geom, POINT(?, ?))
 ORDER BY ST_Area(geom) ASC
 LIMIT 1
```

Titik yang diuji adalah pusat yang sama dengan yang ditulis ke
`pusat_lon`/`pusat_lat`, hasil `siapkanGeometri()`, jadi blok yang tercatat
memang blok tempat bidang itu berada menurut basis data — bukan dugaan lain
dari klien. Bila tidak ada yang memuat, `blok_id` tetap `NULL` dan kalimat
catatan tidak berubah ("perlu verifikasi dan penetapan blok"). Yang berubah
hanya jalur tulis: `sisipBidangPenanda()` menyertakan kolom `blok_id`,
`catatanPenanda()` menyisipkan `; blok BLOK 8 dari titik pusat bidang`, dan
`audit_log.data_sesudah.blok` ikut terisi di jalur tunggal maupun massal.
Balasan `simpan` kini berbunyi "Bidang baru disimpan sebagai perlu verifikasi
di BLOK 8." dan `batch-simpan` mengembalikan `blok` per baris. Tidak ada baris
lama yang ditafsirkan ulang: `UPDATE` tidak dijalankan di mana-mana.

**Yang diukur**, semuanya pada salinan snapshot 10 Nov 2026
(`sebelum-hapus-non-tanah-20261110-192909.sql` dipulihkan ke basis data baru
`sipetades_gedong_uji`; `D:\xampp\...\mysql.exe`, 14 s, 988 baris dump). Basis
data hidup tidak pernah dibuka: dua skrip penghitung memakai DSN PDO yang
nama basis datanya ditulis tetap, dan skrip penguji API berhenti di baris
pertama kalau `SIG_DB_NAME` tidak menunjuk ke kercil.

| yang diukur | angka |
|---|---|
| baris `blok` dan isinya | 24; 24-24 berpoligon; 0 berbatas perkiraan, jadi penyaring `batas_perkiraan = 0` belum bekerja di data ini |
| pasangan blok yang saling bersentuh (`ST_Intersects`) | 24 — tapi sentuhan tepi, bukan tumpang tindih muka |
| pusat bidang yang jatuh di 0 / 1 / >= 2 blok | 44 / 7.902 / 0 dari 7.946 bidang berpusat, jadi urutan `ST_Area` tidak pernah yang memutuskan |
| kesepakatan dengan `blok_id` yang sudah tersimpan | 7.772 sama dari 7.886 (98,6 %); 114 beda dan pada semuanya blok tersimpan tidak memuat pusat bidangnya sendiri — warisan penetapan lama sebelum geometri blok sekarang |
| bidang yang belum punya blok dan kini akan terisi | 59 dari 60 sampel |
| ujian ujung ke ujung lewat API | 6 bidang disimpan (1 `simpan`, 5 `batch-simpan`): 5 dapat `blok_id` (BLOK 8, 21, 3, 23, 5), 1 tetap `NULL`; catatan dan `audit_log` sesuai bentuk di atas |

Cabang itu sendiri dibiarkan apa adanya di `origin`: tidak digabung, tidak
dihapus, dan tidak ada satu pun hunk-nya yang diambil selain yang di atas.
Verifikasi peramban belum dilakukan — teks balasan `simpan` hanya dilihat dari
keluaran CLI. Skrip penguji tinggal di `_lama/scratch/` dan tidak ikut ke repo:
`uji-blok-pusat.php` (keadaan blok + sampel kesepakatan), `uji-blok-pusat2.php`
(peta `id`-`kode` blok, cacah blok per titik, kesepakatan menyeluruh) dan
`uji-blok-penanda.php` (menjalankan `api/penanda-bidang.php` sungguhan terhadap
kercil lewat salinan tanpa baris bootstrap, pola §3.72).

**Tuntasan pemeriksaan.** Setelah bagian ini ditulis, kedua berkas yang
bersangkutan diukur ulang: `README.md` 416.401 B / 6.640 baris, 6.639 di
antaranya dipisah CRLF dan nol baris yang hanya berpisah LF, nol tab;
`api/penanda-bidang.php` 19.989 B / 535 baris seluruhnya berpisah LF (sama
seperti sebelum disentuh) dan nol tab. Nol han, hiragana, katakana, hangul dan
form penuh pada keduanya — aksara di luar Latin-1 di README tetap tanda baca
dan glif matematika yang sudah dipakai luas di dokumen ini, sedangkan berkas
API hanya sepasang tanda kutip lengkung yang memang sudah ada. Angka ini
dipindaikan sendiri dengan `pindai-glif-20261113.php`, bukan diambil dari
pesan perkakas lain. Ukuran `README.md` di atas hanya berlaku sampai paragraf
pemasangan dua lapis turunan di §3.80 ditulis pada hari yang sama; jumlah sesudah
itu sengaja tidak dikutip lagi karena paragraf ini sendiri masih akan disunting.

### 3.82 Sumbu jalan tidak lagi melewati selokan dan sungai (14 Nov 2026)

Laporan user: "garis sumbu jalan banyak yang terputus dan ada yang masuk wilayah
sungai / saluran". Kedua gejala diukur terpisah lebih dulu dengan
`_lama/scratch/ukur-sumbu-retak-air.php` (baru, hanya membaca berkas terbit; ia
menggabung tiga uji: celah antarujung sumbu, titik sumbu yang jatuh di antara dua
tepi air, dan kelas label di titik sumbu), baru alatnya diubah.

**Gejala masuk-air lebih dulu, dan gejala itu memang salah alat.** Pada keluaran
13 Nov (1.816 segmen / 30.805,2 m / 1.152.331 B) terukur **1.394 dari 15.291 titik
sumbu (9,1 %) berada di antara dua tepi air**, panjang yang terseret **3.160,6 m
(10,3 %)**, dan 38 segmen benar-benar memotong garis air (77 potongan). Yang
terseret itu bukan jalan yang kebetulan dekat saluran: **775 dari 909 tepi** yang
dipakai rantai-rantai bermasalah itu berimpit <= 0,6 m dengan sebuah garis
selokan/perairan, hanya 134 yang tidak. Jadi yang terjadi adalah sebuah baris 020100
yang merupakan **tepi selokan** ikut diklaim sebagai tepi jalan pada tahap 1 —
wajar, karena jalan raya berjalan di sisi saluran dan label `JALAN` berjarak <= 2 m
dari garis itu — lalu pada tahap 2 **kedua tepi selokan itu dipasangkan satu sama
lain**: sejajar, lebarnya 0,5-3,3 m (masuk `--lebar` 8 m), dan titik tengah di
antara keduanya **tidak berada di dalam bidang mana pun**. Uji permukaan itulah
satu-satunya rem yang dimiliki tahap 2, dan rem itu sudah tidak bisa menahan air
sejak 10 Nov: 3.773 obyek `tipe='lain'` — termasuk persil sungai dan saluran —
dihapus permanen dari tabel `bidang` (§3.74), sehingga koridor air kini tampak
"kosong" bagi alat ini.

**Aturan baru di tahap 2.** Sebuah pasangan tepi ditolak bila titik tengah koridornya
terjepit di antara dua tepi air yang **sejajar dengan arah pasangan itu**:
`bacaTepiAir()` memuat `garis-selokan.geojson` (783) + `garis-perairan.geojson` (542)
= 1.325 baris / 4.667 segmen ke kisi `SEL_PASANG`, dan `diKoridorAir()` menuntut dua
baris air **berbeda** berada di sisi yang berlawanan, masing-masing <= `RAGU_AIR`
3,5 m, dengan beda arah <= `SUDUT_AIR` 25 derajat terhadap arah pasangan. Syarat
sejajar itu yang menjaga persimpangan jalan dengan saluran tetap utuh: di mulut
pelabuhan air tepinya memotong, tidak menjepit. Pasangannya di `pasanganTerdekat()`
(tepat sesudah uji bidang) dan di cabang diagnosis `seberangTerdekat()`, sehingga
cacah "sisi sejajar tapi koridornya tertutup" ikut menyebut air. Versi pertama
menyimpulkan sisi dari arah segmen airnya sendiri; karena dua tepi satu selokan bisa
digitasi berlawanan arah, kedua tepi itu masuk keranjang sisi yang sama dan aturan
nyaris tidak bekerja — versi itulah yang menghasilkan 1.697 segmen pada percobaan
pertama hari ini. Setelah sisi diukur terhadap arah pasangan, baris acuan tiap
kandidat ditandai agar satu baris air tidak dihitung sebagai dua tepinya sendiri.

| keadaan sumbu | sebelum (13 Nov) | sesudah (14 Nov) |
| --- | --- | --- |
| segmen / panjang | 1.816 / 30.805,2 m | **1.721 / 28.571,1 m** |
| titik sumbu | 15.291 | 14.210 |
| titik terjepit dua tepi air | 1.394 (9,1 %) | **27 (0,2 %)** |
| panjang terseret | 3.160,6 m (10,3 %) | **189,5 m (0,7 %)** |
| segmen memotong garis air | 38 (77 potongan) | 24 (31 potongan) |
| pita lebar "<=1 m" / "1-2 m" | 57 / 395 | 14 / 369 |
| lebar timbang panjang | 3,07 m | 3,16 m |

Yang tersisa 189,5 m, dan yang terbesar adalah satu rantai `SUMBU-0210` 36,2 m di
dalam `SALURAN` — di sana arah pasangan menyimpang lebih dari 25 derajat dari
tepi airnya, jadi ia lolos dengan sah menurut aturan itu. Sisa potongan air yang
memotong (24 segmen) adalah persimpangan, memang tidak boleh dibuang.

**Yang diukur lalu ditolak.** (1) Aturan "label jalan harus lebih dekat daripada
label air di titik tengah": pada keluaran lama ia menolak 17 dari 27 titik yang
tersisa (63,0 %) tetapi juga 1.542 titik yang **bukan** koridor air (10,9 %), karena
622 label jalan harus menopang 143.106,1 m tepi — terlalu jarang untuk menjadi syarat
titik per titik. (2) `--eksklusif` (label jalan harus terdekat, tahap 1) justru
memperburuk cakupan: tepi 3.323 → 3.170 baris dan sumbu 30.805,2 m → 29.225,9 m.
(3) `--lebar=15` dengan aturan air baru: sumbu 2.015 segmen / 31.688,3 m dan cakupan
naik 39,9 % → 44,3 %, tetapi pita lebar ">6 m" melompat 91 → 352 dan panjang yang
terseret naik 0,7 % → 1,4 % — yang didapat bukan sambungan jalan, melainkan pasangan
silang ke koridor sebelah. Bawaan `--lebar` karenanya tetap 8,0 m.

**Gejala terputus: sebagian besar bukan salah alat.** Panjang sumbu sekarang 39,9 %
dari setengah panjang tepinya (28.571,1 m terhadap 71.553,0 m): rata-rata hanya dua
dari lima sisi jalan yang menemukan pasangan. Dari 3.442 ujung sumbu, 589 tidak punya
lanjutan mana pun dalam 20 m, dan 902 pasang ujung yang **searah** (<= 30 derajat di
kedua sisi) saling menunggu pada jarak 1,2-20 m — 112 di antaranya cuma 1,2-3 m,
211 di 3-6 m, 187 di 6-10 m, 391 di 10-20 m. Penyebab yang dilaporkan alat sendiri,
dan inilah yang menentukan: **574 sisi tidak punya sisi sejajar sama sekali sampai
15 m**, 206 sisi hanya punya yang berjarak 8-15 m (di luar `--lebar`), dan 196 sisi
punya pasangan sejajar tapi koridornya selalu tertutup bidang/air. 948 baris tepi
(26.740,9 m) tidak menyumbang satu titik tengah pun. Angka 574 itu artinya
digitasi DXF hanya menggambar satu sisi jalan; sumbu di ruas tersebut tidak bisa
dihitung dari bahan yang ada, dan alat ini tidak akan mengarangnya. Yang bisa
dilakukan tanpa mengarang — menaikkan `--lebar` — sudah diukur dan ditolak di atas;
menjahit celah 1,2-3 m antaruntaian yang sejajar ketika itu belum dikerjakan dan
jadi kandidat berikutnya kalau memang diminta; kandidat itu dikerjakan di §3.83.
Catatan lain dari pengukuran ini: `label` tepi
hanya berisi **tiga** teks berbeda (`JALAN` 133.065,7 m, `Jalan` 9.818,9 m, `JALAN
DUSUN` 221,6 m), jadi rujukan per nama jalan tidak mungkin dibuat dari lapis ini.

**Label yang belum punya garis, dan radius benih.** Menjawab laporan kedua,
`_lama/scratch/ukur-label-tanpa-garis.php` (baru, hanya membaca) memeriksa 622
label jalan satu per satu: **453 punya sumbu <= 4 m, 169 tidak**. Yang penting,
tidak satu pun dari 169 itu kehilangan bahan digitasi — jarak terjauh sebuah
label ke garis 020100 mana pun hanya **6,32 m**, dan mediannya **0,91 m** untuk
label yang tercakup tetapi **2,11 m** (p90 4,06 m) untuk yang tidak. Angka itu
menunjuk ke `BENIH` 2,0 m: label yang ditulis di tengah jalan berjarak sekitar
setengah lebar jalan dari tepinya, sehingga barisnya tidak pernah jadi benih dan
tidak ikut tumbuh lewat pita. Karena itu `--benih=M` dipasang sebagai flag;
pada saat paragraf ini ditulis bawaannya masih 2,0 m (= nilai si pembangkit) dan
`--benih=2` mencetak ulang keluaran terbit secara persis (3.323 baris tepi /
143.106,1 m, 1.721 segmen sumbu / 28.571,1 m). Sapuan uji kering 3-8 m, panjang sumbu terhadap panjang
tepi: 32.678,7 m terhadap 174.714,9 m · 34.686,0/191.932,2 · 35.129,1/200.554,6
· 35.508,2/207.551,6 · 36.356,8/224.663,5. Kenaikan terbesar ada di 2→3 m
(+14,4 %) dan melandai sesudah 4 m (4→5 m hanya +1,3 %), sedang yang buruk ikut
naik monoton: pita lebar ">6 m" 91 → 137 → 179 → 181 → 195 → 197 dan sisi tanpa
sejajar 574 → 722 → 814 → 866 → 925 → 990. Jadi ambang lain **belum diterapkan dan
keputusannya belum diambil**; angka-angka itu baru geometri uji kering, dan pintu
mutu untuk dua kandidat pertama dilaporkan di bawah, sementara keputusan akhirnya
ada di paragraf penutup bagian ini.

**Pintu mutu 3 m dan 4 m: berkas ditulis, diukur, lalu dikembalikan.** Kedua
ambang itu di-`--terapkan` sungguhan, lalu diukur ulang dengan
`ukur-label-tanpa-garis.php` dan `ukur-sumbu-retak-air.php`; keluarannya tersimpan
di `_lama/scratch/terapkan-{3,4}.txt`, `label-{3,4}.txt` dan `retak-{3,4}.txt`,
sedangkan kolom bawaan diukur ulang pada 14 Nov dari berkas hidup ke
`label-2-ulang.txt` dan `retak-2-ulang.txt`.

| ambang benih | tepi baris / m | sumbu segmen / m | label berpunya sumbu (dari 622) | sisi tanpa sejajar <= 15 m | sumbu berlebar > 6 m | titik sumbu di koridor air | panjang terseret | segmen memotong air |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 2,0 m (bawaan ketika tabel ini ditulis) | 3.323 / 143.106,1 | 1.721 / 28.571,1 | **453** | 574 | 91 (5,3 %) | 27 dari 14.210 (0,2 %) | 189,5 m (0,7 %) | 24 (31 potongan) |
| 3 m (bawaan sejak paragraf penutup bagian ini) | 3.974 / 174.714,9 | 1.961 / 32.678,7 | **502** | 722 | 137 (7,0 %) | 33 dari 16.151 (0,2 %) | 212,7 m (0,7 %) | 26 (33 potongan) |
| 4 m | 4.327 / 191.932,2 | 2.077 / 34.686,0 | **516** | 814 | 179 (8,6 %) | 39 dari 16.958 (0,2 %) | 258,8 m (0,7 %) | 31 (40 potongan) |

Yang dibeli naiknya ambang: **+49 label pada 3 m dan +63 pada 4 m**, dan air tidak
kembali bocor — porsinya **identik di ketiga tingkat**, 0,2 % titik dan 0,7 %
panjang, padahal panjang sumbu naik 14,4 % lalu 21,4 % terhadap keadaan bawaan;
panjang terseret dalam angka mutlak ikut naik (189,5 → 212,7 → 258,8 m) semata
karena totalnya yang membesar. Yang memburuk adalah lebar pasangan: sumbu
berlebar > 6 m naik 91 → 137 → 179 (5,3 % → 8,6 % segmen) dan sisi tanpa
pasangan sejajar naik 574 → 722 → 814, jadi sebagian dari panjang tambahan itu
adalah pasangan yang lebih longgar, bukan ruas jalan yang sebelumnya tidak ada.
Cakupan sumbu terhadap setengah panjang tepi justru **turun** 39,9 % → 37,4 % →
36,1 % karena tepi tumbuh lebih cepat daripada sumbu (71.553,1 → 87.357,5 →
95.966,1 m). Segmen yang memotong garis air naik 24 → 26 → 31; yang 24 di
keadaan bawaan sudah dipastikan persimpangan, sedangkan yang di 3-4 m **belum
diklasifikasikan satu per satu**. 5, 6 dan 8 m **tidak diujikan** ke pintu mutu: hanya
geometri uji keringnya yang diukur, dan kurva panjangnya sudah melandai (4→5 m
hanya +1,3 %), jadi tidak ada alasan menulis berkasnya untuk tiga angka itu.

Setelah pengukuran selesai, **ketiga berkas hidup dikembalikan** dari
`_lama/cadangan-label-2026-11-14/` dan SHA-256-nya cocok satu per satu
(`54f30b990986…` tepi, `fc8c258757e6…` sumbu, `3654c697b0a4…` `metadata.json`),
lalu `_lama/scratch/cek-pasang-lapis-jalan.php` dijalankan ulang dan tetap bersih
(22 kunci daftar putih = 22 kunci panel, 3.323 dan 1.721 fitur cocok dengan
metadata, pil aktif bawaan 4). Jadi keadaan terbit **pada saat pintu mutu itu
ditutup** tetap `--benih=2`, dan tabel di atas adalah bahan keputusan, bukan
changelog. Bahan itu memang dipakai pada hari yang sama: 3 m diadopsi sebagai
bawaan, dan berkas terbitnya tercatat di paragraf penutup bagian ini. Kalau ambang
dinaikkan, `metadata.json` ikut berubah sehingga teks pil panel ikut berubah
sendiri tanpa menyunting `peta-lama.php`, dan seluruh angka bagian ini harus
diukur ulang dari berkas yang baru — itulah yang dilakukan di bawah.

**Verifikasi dan higienitas.** `php -l` bersih; dua kali `--terapkan` berturutan
mencetak berkas identik, SHA-256 `garis-jalan-sumbu.geojson` `fc8c258757e6…`,
sedangkan `garis-jalan-tepi.geojson` **tidak berubah** (`54f30b990986…`, sama seperti
sebelum aturan ini) karena tahap 1 tidak disentuh — ini bukti bahwa yang berubah hanya
tahap 2. `_lama/scratch/cek-pasang-lapis-jalan.php` dijalankan ulang dan tetap bersih:
22 kunci daftar putih = 22 kunci panel, `fitur nyata 1721 · metadata.json 1721 · cocok`,
`pil aktif bawaan : 4`. `metadata.json` ditulis ulang oleh alat, jadi teks pil
berubah sendiri — saat paragraf ini ditulis membacakan "Sumbu jalan (1.721)",
1.961 sejak paragraf penutup bagian ini — tanpa menyunting `peta-lama.php`; ukuran berkas sumbu
1.081.235 B (sebelumnya 1.152.331 B), sehingga angka di §3.80 itu keadaan 13 Nov.
Keluaran 13 Nov dicadangkan di `_lama/cadangan-sumbu-2026-11-14/`
(`garis-jalan-sumbu-sebelum-20261114.geojson`, 1.152.331 B, SHA-256 `57a027dcd451…`).
Alat jadi 1.399 baris / 56.273 B, LF semua, 0 tab, aksara di luar Latin-1 tetap tiga
U+2014; nol han, hiragana, katakana, hangul dan form penuh terukur per codepoint
dengan `pindai-glif-20261113.php` — satu glif han sempat lolos ke sebuah komentar
naskah hari ini, dihapus, lalu berkasnya dipindai ulang. **Kedua lapis ini masih
belum dilihat di peramban** (halaman minta login Super Admin, akun uji terhapus — §3.72).

**Panjang lapis ini punya dua angka dan keduanya benar.** Dihitung ulang dari
koordinat (cara alat sendiri dan cara seluruh angka di atas): tepi **143.106,086 m**
dan sumbu **28.571,139 m**. Dijumlah dari properti `panjang_m` per fitur — cara
`cek-pasang-lapis-jalan.php`, yang karena itu mencetak `143106.2` dan `28571.2`:
**143.106,170 m** dan **28.571,220 m**. Selisihnya 0,084 m dan 0,081 m, dan sumbernya
terukur: nilai per fitur dibulatkan saat ditulis, sehingga penjumlahan 3.323 (tepi)
dan 1.721 (sumbu) angka yang sudah terpotong tidak lagi sama dengan panjang
sebenarnya — untuk tepi, 109 properti tersimpan bulat, 462 dengan satu desimal,
2.752 dengan dua desimal atau lebih; untuk sumbu 19 / 168 / 1.534. Keempat angka
itu diukur dari berkas `--benih=2`; pada berkas 3 m **tanpa jahitan** (keadaan
terbit sebelum §3.83) polanya berulang — hitung ulang dari koordinat
**174.714,914 m** dan **32.678,704 m**, jumlah dari properti **174.714,990 m** dan
**32.678,750 m**, selisih 0,076 m dan 0,046 m, sebaran desimal 127 / 560 / 3.287
dan 21 / 187 / 1.753. Keadaan yang terbit kini (3 m + jahitan 3 m, 1.856 segmen)
diukur ulang hari ini dengan skrip yang sama: tepi tidak bergerak
(**174.714,914** dan **174.714,990 m**) sedangkan sumbu **32.941,117 m** dari
koordinat dan **32.941,180 m** dari properti, selisih 0,063 m, sebaran desimal
19 / 182 / 1.655 — `cek-pasang-lapis-jalan.php` mencetak `174715.0` dan
`32941.2` karena ia memakai cara kedua. Angka di bagian ini selalu memakai
yang dari koordinat.

**Ditetapkan: bawaan alat kini `BENIH` 3,0 m dan berkas terbit ikut berganti
(14 Nov, beberapa giliran sesudah pintu mutu).** Konstanta dinaikkan 2,0 → 3,0 m
di `tools/garis-jalan-label.php:137` (`const BENIH`) dengan alasan terukur di
docblock-nya; dua rujukan bawaan yang lain di berkas itu (daftar opsi baris 16
dan dok tahap 1 baris 62) ikut disunting supaya tidak lagi menyebut 2,0 m.
`php -l` bersih, lalu alat dijalankan **tanpa flag**. Yang terbit pada giliran itu,
ketika penyatuan celah sumbu belum ada (§3.83 mengubah segmen dan panjangnya):
tepi **3.974 baris · 174.714,9 m ·
1.553.169 B** dan sumbu **1.961 segmen · 32.678,7 m · 1.231.890 B**; `metadata.json`
17.685 B membawa `"benih_m": 3` sehingga teks pil panel berubah sendiri tanpa
menyunting `peta-lama.php`. `--terapkan` dijalankan dua kali berturutan: kedua
berkas GeoJSON **identik byte per byte** (`061b692f0e83…` tepi, `437d7d36a68c…`
sumbu), hanya `metadata.json` yang berganti hash karena di dalamnya ada cap waktu
`derived_at` (12:00:25 pada jalan kedua, hash `ba14f8d8c24a…`). Semua angka yang
baru diukur itu cocok satu per satu dengan kolom "3 m" pada tabel pintu mutu:
502 dari 622 label punya sumbu (120 tidak), 33 dari 16.151 titik sumbu
di koridor air (0,2 %), 212,7 m panjang terseret (0,7 %), 26 segmen memotong garis
air (33 potongan), cakupan sumbu 37,4 % terhadap setengah panjang tepi.
`_lama/scratch/cek-pasang-lapis-jalan.php` tetap bersih: 22 kunci daftar putih =
22 kunci panel, 3.974 dan 1.961 fitur cocok dengan `metadata.json`, pil aktif bawaan
4, dua swatch legenda ada. Cadangan 2 m utuh dan tidak disentuh
(`_lama/cadangan-label-2026-11-14/`: `54f30b990986…` tepi, `fc8c258757e6…` sumbu,
`3654c697b0a4…` metadata — SHA-256 diperiksa lagi sesudah sapuan), jadi keadaan lama
masih bisa dicetak ulang lewat `--benih=2` atau dikembalikan dengan menyalin berkas
itu. Yang **tidak** berubah: ambang 4 m tetap tidak diambil, dan kedua lapis ini
tetap belum dilihat di peramban (§3.72). Keluaran sapuan tersimpan di
`_lama/scratch/adopsi-3m.txt` dan `adopsi-3m-ulang.txt`; alat jadi 1.416 baris /
57.263 B, LF semua, 0 tab, dan kedua berkas hasil di `/files/` tetap di luar git
seperti §3.80. Keadaan yang ditulis paragraf penutup itu sendiri sudah berganti
beberapa giliran kemudian: celah antaruntaian dijahit (§3.83), sehingga angka
1.961 segmen tidak lagi menjadi isi berkas terbit.

### 3.83 Jahit celah antara dua ujung buntu sumbu (14 Nov 2026)

Perintah "terapkan" yang kedua tidak menyebut sasarannya, dan dua pembacaan yang
sama-sama sah masih terbuka (menjahit celah versus menaikkan benih ke 4 m), jadi
sasaran ditanyakan lebih dulu; yang dipilih adalah kandidat terakhir §3.82 —
menjahit celah 1,2-3 m antara dua untaian sumbu yang sejajar. Yang dikerjakan
bukan menambah bahan digitasi dan bukan pula mengarang ruas baru: satu ruas garis
pendek ditambahkan di antara dua ujung buntu, sehingga sebuah ruas jalan yang
terdigitasi terputus kembali menjadi satu SUMBU.

**Aturannya ada di tahap 2.** `tools/garis-jalan-label.php` menerima tiga
konstanta baru (`JAHIT` 3,0 m baris 175, `SUDUT_JAHIT` 25,0 derajat baris 176,
`JAHIT_MAKS` 20,0 m baris 183 sebagai plafon flag), satu flag `--jahit=M`
(0 mematikan penyambungan, angka di luar 0-20 ditolak), dan satu blok "3b" di
baris 1245 yang berjalan sesudah rantai dipecah pada simpul berderajat != 2
tetapi sebelum fitur disusun. Sebuah jahitan diterima hanya bila: kedua ujung
berderajat 1 (ujung yang jatuh di persimpangan tidak disentuh, karena putus di
situ memang disengaja), keduanya berada di untaian berbeda, jaraknya lebih dari
`RAGU_SUMBU` 1,2 m dan tidak lebih dari 3,0 m, arah majen kedua ujung menunjuk
satu sama lain tidak lebih dari 25 derajat, dan titik tengah ruas jahitan lolos
kedua uji koridor yang sudah dipakai pasangan tepi — tidak berada di dalam
bidang, tidak berada di antara dua tepi air yang sejajar. Kandidat diurutkan
berdasarkan jarak menaik lalu diambil dengan union-find, sehingga satu ujung
hanya sekali dipakai dan dua untaian yang berbagi simpul tidak pernah disambung:
jahitan tidak pernah menutup lingkaran dan tidak pernah memotong persimpangan.
`--jahit=0` mencetak ulang keluaran §3.82 secara persis (1.961 segmen /
32.678,7 m), jadi flag mati dan flag bawaan terbedakan tanpa menebak.

**Yang terukur pada bahan 3 m.** Dari 2.105 untaian ada 1.474 ujung buntu; 113
pasangan diterima dengan panjang jahitan seluruhnya 250,0 m, dan 110 pasangan
ditolak karena tidak searah — nol ditolak karena masuk bidang, nol karena
koridor air, nol karena ujungnya sudah terjahit, nol karena kedua untaian
berbagi simpul. Untaian 2.105 menjadi 1.992 dan keluaran menjadi **1.856 segmen
· 32.941,1 m**; jalur pendek yang dibuang (< 2,0 m) turun 144 → 136 dan pita
lebar ">6 m" turun 137 → 132 karena serpihan yang tadinya berdiri sendiri kini
ikut ruas yang panjang.

`_lama/scratch/cek-jahitan-sumbu.php` (baru) membandingkan berkas hidup dengan
cadangan keadaan 3 m dan mendaftar ruas yang sama sekali tidak ada di berkas
lama: 121 ruas, total 262,4 m — 113 di antaranya jahitan dan 8 sisanya bagian
dari untaian yang dulu dibuang pendek. Semua 121 ruas <= 3,0 m (24 di 1,2-2 m,
97 di 2-3 m, nol di atas 3 m), dan belokan di kedua ujung setiap jahitan
berada di 0-21 derajat (median 2,2°, maksimum 20,9°, nol yang melewati 30°):
yang disambung memang lurus, bukan dua jalan yang kebetulan berdekatan.
Selisih panjang 262,4 m = 250,0 m jahitan + 12,4 m panjang untaian yang
kembali terpakai, jadi kedua hitungan itu cocok.

| ukuran | 3 m tanpa jahitan (terbit sebelumnya) | 3 m + jahit 3,0 m (terbit kini) |
| --- | --- | --- |
| segmen sumbu | 1.961 | **1.856** |
| panjang sumbu (koordinat) | 32.678,704 m | **32.941,117 m** |
| panjang sumbu (jumlah properti) | 32.678,750 m | 32.941,180 m |
| untaian simpul | 2.105 | 1.992 |
| segmen yang ikut terputus karena celah sejajar | 1.491 dari 1.961 | **1.342 dari 1.856** |
| celah sejajar 1,2-20 m, pasangan tak berurutan sejati | 2.191 | **1.973** |
| … yang jaraknya cuma 1,2-3 m | 251 | **146** |
| … 1,2-3 m dan salah satu ujungnya di simpul (titik fitur lain <= 1,2 m) | 140 | 140 |
| … 1,2-3 m di pita arah 25-30 derajat · melibatkan untaian cincin | 4 · 2 | 4 · 2 |
| … 1,2-3 m yang tidak terhalang aturan mana pun (kandidat jahit) | 105 | **0** |
| cakupan sumbu terhadap setengah panjang tepi | 37,4 % | **37,7 %** |
| titik sumbu di koridor air | 33 dari 16.151 (0,2 %) | 33 dari 16.167 (0,2 %) |
| panjang terseret air | 212,7 m (0,7 %) | 212,7 m (0,6 %) |
| segmen memotong garis air | 26 (33 potongan) | 26 (33 potongan) |
| label jalan berpunya sumbu (dari 622) | 502 | 502 |

**Koreksi cara membaca angka §3.82.** `ukur-sumbu-retak-air.php` mencetak
"pasang ujung" sebagai `intdiv(cacah, 2)` padahal loop-nya sudah melewati
`$jd <= $id`, jadi setiap pasangan tak berurutan hanya ditemui satu kali dan
angka yang tercetak itu separuh dari yang sebenarnya. Karena itu 1.095 dan 125
yang dikutip §3.82 adalah 2.191 dan 251 pasangan sejati. Pemeriksa baru
`_lama/scratch/ukur-celah-tanpa-bagi.php` menghitung ulang tanpa pembagian,
sebelah-menyebelah pada berkas cadangan dan berkas hidup, dan angka lamanya
cocok persis dua kali lipat. Pembanding yang jujur untuk 113 jahitan adalah 251
pasangan sejati di kelas 1,2-3 m, bukan 125.

**Sisa 146 pasangan terjelaskan semua.** `_lama/scratch/cek-sisa-celah.php`
(baru) memakai aturan pasangan yang sama lalu menggolongkan setiap pasangan di
kelas 1,2-3 m: 140 pasangan punya sedikitnya satu ujung yang titik fitur lain
berada dalam 1,2 m (= `RAGU_SUMBU`), jadi ujung itu memang berdiri di simpul dan
putus di situ disengaja; 4 pasangan berada di pita arah 25-30 derajat yang
diterima penguji lama tetapi ditolak `SUDUT_JAHIT`; 2 pasangan melibatkan untaian
yang menutup sendiri (titik awal = titik akhir), dan berkas sumbu memang berisi
21 fitur cincin. Yang tanpa alasan: nol. Ketiga golongan mencetak angka yang sama
persis di berkas lama (140, 4, 2) dan jumlah cincin juga tidak berubah (21), jadi
jahitan tidak menyentuh satu pun pasangan di luar kelas yang boleh dijahit.
Pasangan kelas 1,2-3 m berkurang 251 - 146 = 105, sedangkan jahitan yang diterima
113: alat memandang 2.105 untaian sebelum 144 untaian pendek dibuang, sehingga
ia juga melihat ujung-ujung yang tidak pernah muncul di berkas terbit.

**Plafon di atas 3,0 m diukur lalu tidak diambil (perintah "pertahankan 3 m").**
Sapuan uji kering `--jahit=4/6/8` (keluaran `_lama/scratch/jahit-sapu-lebar.txt`,
tidak ada berkas yang ditulis) menunjukkan bahwa plafon yang lebih longgar hampir
tidak menambah cakupan sambil memanjangkan garis buatan:

| ambang | ruas jahitan | panjang jahitan | rata-rata ruas | segmen sumbu | panjang sumbu | cakupan | pendek dibuang | ">6 m" | ditolak: searah / air / sudah terjahit |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 3,0 m (terbit) | 113 | 250,0 m | 2,21 m | 1.856 | 32.941,1 m | 37,7 % | 136 | 132 | 110 / 0 / 0 |
| 4,0 m | 127 | 301,7 m | 2,38 m | 1.844 | 32.996,1 m | 37,8 % | 134 | 129 | 187 / 0 / 1 |
| 6,0 m | 190 | 603,6 m | 3,18 m | 1.786 | 33.305,8 m | 38,1 % | 129 | 123 | 308 / 1 / 3 |
| 8,0 m | 234 | 898,7 m | 3,84 m | 1.745 | 33.606,3 m | 38,5 % | 126 | 121 | 419 / 1 / 7 |

Yang memutuskan adalah kolom rata-rata ruas. Lebar koridor timbang panjang tidak
bergerak (3,27 m di keempat ambang), tetapi rata-rata satu jahitan naik dari
2,21 m ke 3,84 m, jadi di 6 m dan 8 m sebuah jahitan sepanjang itu rata-rata
sama dengan lebar jalan yang disambungnya — yang bertemu kemudian bukan lagi dua
ujung satu ruas yang terputus. Manfaatnya tinggal 0,1 sampai 0,8 persen poin
cakupan karena ruas jahitan memang pendek, dan dua golongan penolakan yang di
3,0 m kosong (konflik greedy "sudah terjahit" dan satu "koridor air") baru
muncul di atasnya. `JAHIT` 3,0 tetap bawaan; flag `--jahit` tetap menerima
0-20 m supaya keputusan ini bisa diuji ulang tanpa menyunting alat.

**Verifikasi dan higienitas.** `php -l` bersih dan `--terapkan` dijalankan dua
kali berturutan: `garis-jalan-sumbu.geojson` **identik byte per byte**
(`dcbfe2d8ab07…`, 1.203.946 B, sebelumnya 1.231.890 B), sedangkan
`garis-jalan-tepi.geojson` sama sekali tidak berubah (`061b692f0e83…`,
1.553.169 B) — bukti bahwa tahap 1 tidak disentuh. `metadata.json` 17.685 →
18.351 B dan hash-nya memang berganti tiap jalan karena cap waktu `derived_at`
(`c18bcaf7f593…` lalu `eb2186acbf6c…`, 12:46:25); blok `ambang` lapisan sumbu
kini membawa `"jahit_m": 3` dan `"sudut_jahit_derajat": 25`, plus blok `jahit`
berisi 113 ruas / 250 m / riwayat penolakan. Teks pil panel mengikuti jumlah
fiturnya sendiri, 1.856, tanpa menyunting `peta-lama.php`;
`_lama/scratch/cek-pasang-lapis-jalan.php` tetap bersih: 22 kunci daftar putih =
22 kunci panel, 3.974 tepi dan 1.856 sumbu cocok dengan `metadata.json`, pil
aktif bawaan 4, dua swatch legenda ada. Keadaan 3 m tanpa jahitan dicadangkan
utuh di `_lama/cadangan-jahit-2026-11-14/` (`061b692f0e83…` tepi, `437d7d36a68c…`
sumbu, `ba14f8d8c24a…` metadata), jadi ia bisa dikembalikan dengan menyalin
berkas itu atau cukup dengan `--jahit=0`. Keluaran sapuan tersimpan di
`_lama/scratch/jahit-uji-kering.txt`, `jahit-uji-kering-b.txt`,
`jahit-terapkan.txt`, `jahit-sapu.txt`, `jahit-daftar.txt`,
`jahit-celah-bersih.txt` dan `jahit-sisa-celah.txt`, plus sapuan plafon
`jahit-sapu-lebar.txt`; pemeriksa yang dipakai
adalah `cek-jahitan-sumbu.php`, `ukur-celah-tanpa-bagi.php`, `cek-sisa-celah.php`
dan `cek-dua-pasang.php` (semuanya di `_lama/scratch/`, di luar git). Alat jadi
1.599 baris / 65.587 B, LF semua, 0 tab, nol han-hiragana-katakana-hangul-form
penuh, aksara di luar Latin-1 tetap tiga U+2014. Yang **tidak** berubah: ambang
benih tetap 3,0 m (benih 4 m tetap tidak diambil, dan plafon jahit 4-8 m juga
tidak — lihat paragraf di atas), 120 label masih tanpa sumbu, dan
kedua lapis ini tetap belum dilihat di peramban (§3.72).

### 3.84 Periksa ulang jahitan tanpa memakai alat (14 Nov 2026)

Permintaan "perlihatkan semua hasil garis sumbu jalan yang sudah diperbaiki"
disusul perintah "commit perubahan ini". Tidak ada satu pun berkas hasil yang
ditulis pada giliran itu: `--jahit` tidak dijalankan, dan ketiga berkas
turunan masih bernomor hash sama seperti §3.83 (tepi `061b692f0e83…` 1.553.169
B, sumbu `dcbfe2d8ab07…` 1.203.946 B, metadata `eb2186acbf6c…` 18.351 B),
begitu pula alatnya (1.599 baris / 65.587 B). Yang dikerjakan adalah mengulang
pengukuran jahitan **tanpa memakai `tools/garis-jalan-label.php`** — berkas
hidup dibandingkan dengan cadangan keadaan 3 m di
`_lama/cadangan-jahit-2026-11-14/` — lalu membuat hasilnya bisa dilihat di
peramban.

**Dua cara pencocokan gagal, satu bekerja.** Cara pertama membandingkan
properti `tepi_all` antara dua keadaan dan melaporkan 1.623 dari 1.856 fitur
"berubah" dengan selisih jumlah panjang negatif — tidak mungkin, sebab satu
kode tepi `G:NNNN` boleh ikut lebih dari satu untaian, jadi hubungannya
banyak-ke-banyak dan tidak bisa dipakai sebagai kunci. Cara kedua mencari
barisan titik untaian lama di dalam untaian baru dan hanya menemukan 30 dari
113 sambungan, karena sebagian untaian terbit berjalan arah sebaliknya
sementara urutan titik tidak tahan pembalikan. Yang bekerja hanya
membandingkan himpunan **pasangan titik bertetangga tanpa urutan**: kunci dua
koordinat yang dibulatkan 3 desimal lalu ditata menaik. Sebuah ruas di berkas
baru disebut ruas baru bila pasangnya tidak pernah muncul di berkas lama, dan
ruas baru di pita (1,2 ; 3,0] m ialah jahitan. Pemeriksanya
`_lama/scratch/tampil-sumbu-diperbaiki.php` (258 baris / 9.860 B, LF semua, 0
tab, baca saja — tidak menyentuh berkas hidup maupun basis data).

| ukuran | berkas terbit, pemeriksaan ulang | pembanding di §3.83 |
| --- | --- | --- |
| sebelum jahit: untaian · titik · panjang koordinat | 1.961 · 16.151 · 32.678,704 m | keadaan 3 m tanpa jahitan |
| sesudah: untaian · titik · panjang koordinat | 1.856 · 16.167 · 32.941,117 m | 1.856 · 32.941,117 m |
| ruas jahitan terdeteksi | 113 | 113 ruas / 250,0 m |
| untaian yang membawa sedikitnya satu jahitan | 98 (4.314,195 m koordinat · 4.314,210 m properti) | — |
| ruas baru | 121, total 262,4 m | 121 ruas / 262,4 m (`cek-jahitan-sumbu.php`) |
| pita panjang tiap ruas baru: 1,2-2,0 · 2,0-3,0 · di atas 3,0 m | 24 · 97 · 0 | 24 · 97 · 0 |
| ruas baru di luar pita (1,2 ; 3,0] m | 0 | — |
| selisih jumlah untaian pada berkas | 1.961 − 1.856 = **105** | 2.105 → 1.992 = **113** di rangka |

**Yang baru di sini: 105 bukan 113, dan delapan lokasi dua ruas — keduanya
terjelaskan.** Alat menghapus 113 untaian di tahap rangka, tetapi berkas
terbit hanya turun 105 karena delapan untaian pendek (< 2,0 m) yang semula
dibuang ikut kembali; itu golongan yang sama dengan penurunan 144 → 136 di
§3.83. Sisa 121 − 113 = 8 ruas baru adalah ruas milik delapan untaian itu,
bukan jahitan: pada kedelapan lokasinya (dicetak `--rinci`) jahitan tergambar
lewat satu titik sisipan yang sama sekali tidak ada di berkas lama, sehingga
satu sambungan menjadi dua segmen. Karena itu jarak dua ujung untaian yang
tersambung di delapan lokasi itu 3,40-3,94 m — melewati `JAHIT` 3,0 m —
sementara setiap ruas yang tergambar tetap tidak lebih dari 3,0 m (nol di atas
3,0 m pada tabel di atas). Ini memperketat cara membaca kalimat "Semua 121
ruas <= 3,0 m" di §3.83: yang dibatasi 3,0 m ialah panjang tiap ruas gambar,
bukan rentang ujung-ke-ujung, dan kalau dua angka itu disebut, sebut keduanya
dengan nama yang berbeda. Hal yang sama berlaku untuk panjang: 250,0 m ialah
jarak simpul-ke-simpul di rangka, 262,4 m ialah panjang ruas seperti
tergambar, dan selisihnya 12,4 m adalah untaian pendek yang kembali terpakai —
persis seperti perhitungan §3.83, tidak ada yang perlu dibetulkan di sana.

**Tampilan peramban.** Koordinat kedua lapis turunan masih meter DXF lokal
(mis. 261877,85 / 715114,99) dan `peta-lama.php` memang menampilkannya dengan
`L.CRS.Simple` tanpa peta dasar, jadi berkas GeoJSON-nya tidak bisa langsung
dibuka di peta web. Flag `--geojson` pada skrip itu menerapkan affine
`const DXF_KE_WGS84` dari `tools/lib-impor.php:1245`; koefisiennya disalin
inline ke skrip, sebab `require` berkas itu akan ikut menarik
`app/bootstrap.php` beserta kredensial dan koneksi PDO. Keluarannya
`_lama/scratch/sumbu-dijahit-20261114.geojson` (95.329 B, 211 fitur: 98
untaian dan 113 jahitan) dan fragmen Leaflet yang dihasilkan
`_lama/scratch/tampil-visual.php` (112.268 B; untaian merah, jahitan kuning
putus-putus, tabel 98 baris yang bisa diklik). Keduanya di `_lama/scratch/`,
jadi tetap di luar git. Ini bukan panel aplikasi: kedua lapis di Peta Induk
masih belum dilihat di peramban (§3.72), dan paragraf ini satu-satunya bahan
terlacak yang bisa di-commit karena semua keluaran giliran tadi berada di
direktori yang diabaikan git.

### 3.85 Berapa mahalnya "sambungkan semua sumbu jalan" (14 Nov 2026)

Perintah "sambungkan semua sumbu jalan" dijawab dengan mengukur lebih dulu,
karena "semua" punya dua tafsir yang biayanya jauh berbeda: membesarkan plafon
jahitan, atau memaksa seluruh jaringan menjadi satu potongan. Sasaran
ditanyakan; yang dipilih **tahan 3 m, laporkan saja**, jadi tidak ada satu pun
berkas hasil yang berubah — tepi tetap `061b692f0e83…` 1.553.169 B, sumbu
`dcbfe2d8ab07…` 1.203.946 B, metadata `eb2186acbf6c…` 18.351 B, dan `JAHIT`
masih 3,0 m di baris 175.

**Jaringan kini 588 potongan.** Dari berkas terbit: 1.856 untaian, 16.167
titik untaian, 14.563 posisi unik (pembulatan 3 desimal), 21 cincin. Potongan
terbesar hanya 707,6 m pada 79 ruas = 2,1 % panjang lapis; enam terbesar
707,6/79 · 482,6/36 · 481,9/22 · 369,6/22 · 346,8/30 · 329,9/20 (meter/ruas).
Sebanyak 272 komponen terdiri dari satu ruas saja dan 153 komponen panjangnya
di bawah 20 m.

**Pecahan ini bukan artefak simpul yang hampir sama.** Menganggap dua posisi
sebagai satu simpul kalau jaraknya di bawah toleransi T (single linkage) tidak
menghubungkan satu potongan pun sampai T = 1,0 m, dan hanya satu pada 1,2 m:

| T | simpul aktif | komponen |
| --- | --- | --- |
| 0 · 0,001 … 1,0 m | 14.563 · 14.555 | 588 |
| 1,2 m | 14.509 | 587 |
| 2,0 m | 12.984 | 552 |
| 3,0 m | 641 | 476 |

**Biaya menyambungkan, per plafon jarak.** Kandidatnya pasangan ujung dari dua
komponen berbeda; kolom "sambungan" diambil serakah dari jarak menaik dengan
union-find, jadi ia adalah batas bawah jumlah ruas dan batas atas manfaatnya.
Penyaringnya lebih longgar daripada `--terapkan` (hanya jarak + uji arah
`SUDUT_JAHIT` 25 derajat, tanpa syarat kedua ujung berderajat 1 dan tanpa uji
koridor bidang/air), sehingga angka di kolom itu tidak akan persis muncul di
keluaran alat:

| plafon | sambungan | garis baru | rata-rata | potongan tersisa | ditolak uji arah |
| --- | --- | --- | --- | --- | --- |
| 3,0 m (kini) | 5 | 11,1 m | 2,22 m | 583 | 87 |
| 4,0 m | 18 | 58,1 m | 3,23 m | 570 | 163 |
| 6,0 m | 83 | 371,6 m | 4,48 m | 505 | 308 |
| 8,0 m | 134 | 718,0 m | 5,36 m | 454 | 443 |
| 12,0 m | 210 | 1.451,9 m | 6,91 m | 378 | 734 |
| 20,0 m | 263 | 2.278,0 m | 8,66 m | 325 | 1.734 |
| 40,0 m | 357 | 4.976,6 m | 13,94 m | 231 | 7.366 |

**"Semua tersambung" hanya sampai dengan mengarang garis.** Kalau uji arah
dilepas dan plafon dinaikkan sampai 400 m, 587 sambungan (tepat
`komponen − 1`) menghimpun jaringan jadi satu potongan: 7.530,5 m garis baru =
22,9 % panjang lapis sumbu, rata-rata 12,83 m per sambungan, terjauh 222,54 m.
Pada plafon 40 m tanpa uji arah pun sisanya masih 35 potongan. Karena itu
perintah "sambungkan semua" tidak dikerjakan: yang membedakan sambungan jalan
dari garis karangan justru uji arah dan ambang 3 m, dan keduanya harus dilepas
untuk memenuhi perintah itu. Yang benar-benar kurang ada di bahan digitasi —
gurat DXF berhenti sebelum persimpangan — bukan di ambang turunan; memindahkan
pekerjaan itu ke alat hanya akan menyembunyikannya.

Pemeriksa: `_lama/scratch/ukur-biaya-sambung-semua.php` (sapuan plafon,
union-find, uji arah) dan `_lama/scratch/ukur-komponen-toleransi.php`
(toleransi simpul), keluaran di `_lama/scratch/sambung-semua-25derajat.txt`,
`sambung-semua-tanpaarah.txt`, `komponen-toleransi.txt`; keduanya baca saja.
Satu bug sempat keluar dan sudah dibetulkan sebelum angka di atas dicetak:
`uasort` mempertahankan kunci lama, jadi `$urut[0]` setelah itu bukan elemen
terbesar — komponen terbesar sempat terbaca 1 ruas 296 m; `usort`
memperbaikinya dan verifikasi silangnya adalah daftar enam terbesar di atas.
Yang tidak berubah: `JAHIT`, `SUDUT_JAHIT`, `BENIH`, kedua berkas turunan, dan
120 label yang masih tanpa sumbu (keadaan dua yang terakhir itu berganti di
§3.86).

### 3.86 Sumbu untuk label yatim dan Jalan Raya Patean (14 Nov 2026)

Perintah "buatkan garis sumbu jalan raya patean dan semua label jalan yang
belum dibuat" mengerjakan dua hal sekaligus, dan dua-duanya keluar sebagai
ambang baru di `tools/garis-jalan-label.php`. Tolak ukurnya keadaan §3.83:
dari 622 label jalan, 502 berjarak <= 4,0 m dari sebuah untaian sumbu dan 120
tidak; JALAN RAYA PATEAN 10,08 m dari sumbu terdekat. Sesudah kedua ambang
dipasang dan keluaran ditulis ulang: 533 label tercakup, 89 masih tanpa sumbu,
Patean 1,16 m.

**Kenapa Patean gagal.** Flag diagnostik baru `--tanya=x,y[,radius]` mencetak,
untuk tiap baris tepi di sekitar sebuah titik, sisi tempat baris itu bergabung
dan pemenang pasangan pada tiga radius. Untuk label di
(261.713,59 / 715.372,20) keluar empat baris: G:5989 dan G:8014 (sisi 2679,
keduanya `benih-yatim`), G:8527 (sisi 2801, `benih`) dan B:19338 (sisi 2679,
`benih`, 169,9 m panjangnya). Koridor Patean lebar 9,51 m — sampel di ruas itu
menemukan seberang pada 9,51 m ke sisi 2801 — tetapi B:19338 menang suara di
koridor lain: sisi 2025 dengan 24 dari 80 titik sampel. Ronde pertama hanya
memilih SATU pasangan per baris, jadi ruas 9,51 m itu tidak pernah menyumbang
titik tengah, dan memperlebar ambang untuk seluruh baris tidak mengubahnya:
barisnya sudah terpasang. Yang harus diganti adalah butir yang ditanyakan,
bukan barisnya.

**Ambang pertama: `BENIH_YATIM` 15,0 m.** Tahap 1 memberi benih hanya kepada
baris tepi yang punya sampel <= `BENIH` 3,0 m dari label, sehingga label yang
guratnya lebih jauh dari 3,0 m tidak pernah menghasilkan bahan sama sekali.
Benih
ronde kedua sekarang hanya ditanyakan kepada label yang sama sekali tidak
mendapat benih, jadi ia tidak bisa merebut baris milik label lain. Terukur: 12
dari 59 label semacam itu kini mendapat bahan — 294 baris tepi, tersebar pada
jarak bulat 3 m:15, 4 m:13, 5 m:14, 6 m:15, 7 m:18, 8 m:15, 9 m:11, 10 m:14,
11 m:12, 12 m:25, 13 m:72, 14 m:70. Batas atas flag ini `PITA` 15,0 m.

**Ambang kedua: `LEBAR_YATIM` 10,0 m.** Ronde ini dikerjakan dua kali dan
versi pertama dibuang. Versi pertama menanyakan **baris** yang di ronde 1
tidak mendapat pasangan sama sekali, dengan radius lebih lebar: 109 dari
1.430 baris (4.353,2 m) terpasang, keluaran 1.948 segmen / 35.351,4 m dan
522 label tercakup, sedangkan Patean hanya mundur 10,08 → 9,63 m — persis
seperti yang diperkirakan diagnosis di atas, karena baris yang sudah menang suara tidak pernah ditanya
ulang. Versi yang dipakai menanyakan **titik sampel**: hanya sampel yang di
ronde 1 tidak berpasangan dicari ulang, suara mayorotas tiap baris dibiarkan,
dan tiap kelompok >= 2 sampel yang jatuh di sisi seberang yang sama menjadi
untaian baru. Pada radius yang sama: 852 titik sampel berpasangan, 207 untaian
baru, 37 baris yang semula tanpa pasangan kini terpasang (1.668,6 m), keluaran
2.034 segmen / 36.903,5 m, 533 label tercakup.

Bahan tahap 1 ikut berubah hanya karena ambang pertama: 3.974 baris /
174.714,9 m tanpa `BENIH_YATIM`, 4.367 baris / 194.159,5 m dengannya; tiga
baris terakhir pada tabel memakai bahan yang sama.

| ambang yatim | sumbu segmen / m | ">6 m" | label <= 4 m | Patean |
| --- | --- | --- | --- | --- |
| mati (= §3.83) | 1.856 / 32.941,1 | 132 | 502 | 10,08 m |
| benih 15 saja | 1.926 / 35.088,0 | 167 | 521 | 10,08 m |
| benih 15 + lebar 10 (dipakai) | 2.034 / 36.903,5 | 261 | 533 | 1,16 m |
| benih 15 + lebar 12 (ditolak) | 2.200 / 39.880,9 | 428 | tidak diukur | — |

**Kenapa 10,0 m.** Yang dicari ialah ambang bulat terkecil yang melampaui
koridor Patean 9,51 m; 9,5 m justru tidak cukup. Langkah berikutnya ke
12,0 m menambah 166 segmen / 2.977,4 m dan menaikkan cacah sumbu
berkoridor "> 6 m" dari 261 menjadi 428; tambahan cakupannya di radius itu
tidak diukur lagi karena ukuran kualitas tersebut sudah cukup untuk
menolaknya. Validasi flag menolak `--lebar-yatim` yang <= `--lebar` atau
> `SEBERANG_UJI` 15,0 m.

**Keadaan terbit.** `files/sig-pertanahan-gedong-lokal/data/dxf-layers/`:
tepi 4.367 fitur / 194.159,5 m, 1.705.632 B, `870430f7bce9…`; sumbu 2.034
fitur / 36.903,5 m, 1.311.108 B, `ced69ef6f5fe…`; metadata 18.658 B. Lebar
timbang panjang naik 3,27 → 3,61 m dan jarak maksimum titik sumbu ke tepi
4,00 → 5,00 m; keduanya harga yang dibayar untuk tambahan 178 segmen. Keadaan
§3.83 dicadangkan di `_lama/cadangan-yatim-2026-11-14/` (tepi
`061b692f0e83…`, sumbu `dcbfe2d8ab07…`).

Kedua ambang sekarang menjadi bawaan alat, bukan flag: `BENIH_YATIM = 15.0`
dan `LEBAR_YATIM = 10.0`. Tiga pemeriksaan keluaran: (a) `--benih-yatim=0
--lebar-yatim=0` mencetak ulang berkas §3.83 sampai identik byte, diuji
sebelum dan sesudah tahap 2 ditulis ulang; (b) jalan dengan flag 15/10
menghasilkan byte yang sama dua kali berturut-turut; (c) jalan tanpa flag apa
pun, setelah konstanta dinaikkan, juga menghasilkan `870430f7bce9…` dan
`ced69ef6f5fe…` — hanya `derived_at` di metadata yang bergerak. Angka "3.973
titik tertolak uji air" pada baris `lebar yatim` ialah cacah penolakan ronde
kedua saja dan nilai cacah itu dikembalikan lagi sebelum dicetak, sehingga
5.607 pada baris `koridor` hanya memuat ronde pertama.

**Yang belum.** 89 label masih tanpa sumbu dalam 4,0 m.
`_lama/scratch/pilah-sisa-label.php` menggolongkan sisanya dari berkas terbit:
0 label tanpa bahan — semuanya sudah punya baris tepi dalam 15,0 m, jadi sisa
pekerjaannya seluruhnya ada di tahap 2. Menurut jarak ke sumbu terdekat: 4-6 m
14 label, 6-10 m 19, 10-20 m 26, > 20 m 18, dan tidak ada sumbu dalam 60 m 12.
Ambang "4,0 m" di situ ialah jari uji pemeriksa, bukan syarat alat. Satu hal
lain ikut keluar: 2 dari 622 label berawalan JALAN ternyata tersimpan di
`batas-perairan.geojson`, bukan `batas-jalan.geojson`; pemeriksa itu membaca
keduanya supaya sama dengan keluaran alat.

Pemeriksa: `--tanya` (diagnostik di dalam alat),
`_lama/scratch/cek-sumbu-label.php` (lebar koridor per label),
`_lama/scratch/cek-label-yatim.php` (banding berkas sebelum/sesudah),
`_lama/scratch/pilah-sisa-label.php` (golongan sisa),
`_lama/scratch/banding-metadata-tanpa-waktu.php` (metadata tanpa
`derived_at`), `_lama/scratch/cek-pasang-lapis-jalan.php` (panel; hasil
"bersih"). Keluaran
tersimpan sebagai `tanya-patean*.txt`, `yatim-*.txt`, `lebar-yatim-*.txt` (ini
ronde kedua versi baris yang dibuang), `final-1.txt`, `final-2.txt`,
`terapkan-konstanta.txt`, `pilah-sisa.txt`. Yang tidak disentuh: basis data,
berkas DXF sumber, dan dua lapis garis 020100. Keadaan tepi 4.367 baris di
atas dipangkas ke 2.872 baris di §3.87.

### 3.87 Tepi jalan dipangkas ke baris yang jadi bahan sumbu (15 Nov 2026)

Perintah "hapus garis tepi jalan yang membentuk peta bidang dengan
mempertahankan garis tepi jalan saja" menyasar ISI lapis tepi, bukan geometri
sumbu. Satu-satunya kolom yang mungkin memisahkan, `rasio_tepi_bidang`, tidak
bisa dipakai: nilainya 1 pada 4.265 dari 4.367 baris. Penanda yang dipakai
karena itu bukan atribut baris melainkan jejak pemakaian: sebuah baris tepi
adalah tepi jalan kalau `kunci`-nya muncul di `tepi_all` sebuah sumbu.

Dua saringan diukur pada lapis terbit (`_lama/scratch/calon-tepi-jalan.php`).
A = `kunci` muncul di `tepi_all`: 2.872 baris / 148.023,7 m. B = baris yang
punya satu titik sampel <= 8,0 m dari sebuah untaian sumbu, termasuk sisi
koridor yang kalah suara: 605 baris / 20.580,6 m. A+B = 3.477 baris /
168.604,3 m, dan 890 baris / 25.555,3 m tidak masuk keduanya. Yang dipakai A
saja: B menyimpan 605 baris yang tidak menjadi bahan satu pun sumbu, jadi
saringan itu belum menyelesaikan keluhan yang jadi alasan perintahnya.

Alat mendapat flag `--tepi=terpakai|semua` dengan bawaan `terpakai`.
Penyaringan terjadi SESUDAH tahap 2, sebab hanya di situ `tepi_all` sudah
terbentuk; berkas tepi karenanya ditulis di ujung bersama sumbu, bukan lagi
sebelum pemasangan. `kode` dinomori ulang `TEPI-0001` sampai `TEPI-2872`
berurutan, sedangkan `kunci` tidak berubah sehingga seluruh rujukan `tepi_all`
tetap sah. `--tepi=semua` mengembalikan keadaan §3.86 dan diwajibkan bersama
`--tahap=tepi`, yang tidak punya sumbu untuk menentukan baris terpakai.
Metadata mendapat blok `saring` (aturan, klaim tahap 1, cacah dan panjang yang
dibuang) dan satu kalimat catatan. Kalimat itu sengaja dibuka dengan judul
lapis, sebab pembersih catatan hanya menyaring awalan itu; catatan yang dibuka
dengan kata lain akan menumpuk satu salinan tiap kali alat dijalankan.

**Keadaan terbit.** tepi 2.872 fitur / 148.023,7 m, 1.173.914 B,
`7a60dbcb0013…`; sumbu tidak berubah 2.034 fitur / 36.903,5 m, 1.311.108 B,
`ced69ef6f5fe…`; metadata 19.346 B. Yang dibuang 1.495 baris / 46.135,9 m.
Keadaan §3.86 dicadangkan di `_lama/cadangan-tepi-2026-11-15/` (tepi
`870430f7bce9…`, sumbu `ced69ef6f5fe…`, metadata 18.658 B). Cacah pil "Tepi
jalan" di Peta Induk ikut turun ke 2.872 tanpa perubahan kode, sebab pil itu
membaca `layers[...].features` dari metadata.

Empat pemeriksaan: (a) sesudah penulisan tepi dipindah ke ujung,
`--tepi=semua` mencetak ulang kedua berkas sampai identik byte —
`870430f7bce9…` dan `ced69ef6f5fe…`; (b) jalan bawaan tiga kali berturut-turut
menghasilkan `7a60dbcb0013…` dan `ced69ef6f5fe…` yang sama; (c) metadata
tetap 19.346 B dengan 13 catatan, 2 di antaranya berawalan judul lapis —
bukti catatan baru menggantikan, bukan menumpuk; (d)
`cek-saring-tepi.php` membaca kedua berkas terbit: 2.872 `kunci` unik di tepi,
2.872 unik di gabungan `tepi_all` (7.192 rujukan), 0 baris tepi tanpa sumbu
dan 0 `kunci` sumbu tanpa baris tepi. Panjang di blok `saring` dijumlah dari
`panjang_m` yang dibulatkan per baris supaya bisa dicocokkan langsung dengan
berkas; jumlah mentah klaim tahap 1 tetap 194.159,5 m seperti §3.86.

### 3.88 Sisa 89 label: 45 tanpa sumbu dekat teksnya (15 Nov 2026)

Sisa §3.86 diukur ulang dan dipisah menurut bahannya
(`_lama/scratch/kelas-sisa-label.php`, radius uji 4,0 m): 44 label sebenarnya
sudah punya sumbu 4,46–14,88 m dari teksnya, jadi yang di luar 4,0 m hanyalah
jari uji pemeriksa; 13 label tidak punya bahan terpakai sama sekali (sumbu
terdekat 21,87–182,96 m); 32 label bersumbu jauh 15,13–244,27 m.

45 label terakhir (13 + 32) ditanyakan satu per satu memakai flag baru
`--tanya-berkas=BERKAS`, yang membaca satu titik per baris —
`x,y[,radius][,nama]`, radius bawaan 20 m, baris berawalan `#` diabaikan —
lalu digolongkan `_lama/scratch/rangkum-tanya-berkas.php`: 25 titik hanya
punya satu komponen sisi sehingga tidak ada sisi seberang untuk dipasangkan,
17 titik punya sisi sejajar tetapi koridornya selalu jatuh di dalam bidang
tanah, 2 titik menang suara di koridor lain sehingga koridor lokalnya
tertutup, dan 1 titik punya koridor kosong tetapi tidak terpasang.

Kesimpulannya bukan soal ambang: 25 dari 45 tidak punya kandidat pasangan
sama sekali sebab `pasanganTerdekat()` sengaja melewati segmen dari komponen
sisi yang sama (aturan 1 tahap 2), dan 17 sisanya kalah oleh uji permukaan
bidang. Menutupnya berarti mengubah aturan pemasangan — memasangkan dua tepi
dari satu komponen sisi, atau melonggarkan uji permukaan — dan itu belum
dikerjakan. Keluaran tersimpan sebagai `kelas-sisa-label.txt`,
`tanya-yatim-b.txt` (45 titik masukan) dan `tanya-yatim-b.log` (39.749 B).

### 3.89 Pil Jalan di panel kini berisi garis tepi (15 Nov 2026)

Perintah "terbitkan garis tepi jalan pada layer jalan di panel peta" dijawab
dengan mengganti isi pil, bukan menambah pil lain. Kunci `garis-jalan` di
`api/peta-lama.php` kini menunjuk `garis-jalan-tepi.geojson`; kunci
`garis-jalan-tepi` dihapus karena isinya pindah ke kunci itu, sedangkan
`garis-jalan-sumbu` tetap. Daftar putih dan panel sama-sama 21 kunci.

Di `peta-lama.php` pil itu tetap bernama pendek "Jalan (2.872)" — angka dibaca
dari `GARIS JALAN TEPI DARI LABEL` di `metadata.json` — tetapi `title`-nya
kini `Layer GARIS JALAN TEPI DARI LABEL` dan pil itu `checked`, menggantikan
centang bawaan pil tepi yang lama. Gaya garis memakai warna Jalan `#0f766e`
dengan lebar 1,1 seperti tepi sebelumnya. Pil "Tepi jalan" dan swatch legenda
`#14b8a6` dihapus; swatch "Garis jalan" `#0f766e` tetap dan sekarang mewakili
isi itu. Catatan kelompok diperbarui: Jalan berasal dari alat label, sedangkan
Selokan, Sungai, dan Tanpa label tetap dari penurunan 020100 (§3.77). Kelompok
"Turunan Jalan dua tahap" tinggal berisi pil Sumbu jalan. Pil aktif bawaan
tetap empat: 020100, garis-jalan, garis-jalan-sumbu, calon-bidang.

`garis-jalan.geojson` (1.981 baris / 129.175,1 m, hasil §3.79) tidak lagi
ditawarkan panel, tetapi berkasnya tetap di disk dan tetap menjadi bahan
`--sumber-tepi=terbit` di alat label. Pemeriksaan statis: `php -l` bersih pada
kedua berkas, `node --check` bersih atas 799 baris JS halaman, dan
`_lama/scratch/cek-pasang-lapis-jalan.php` mencetak 21 kunci daftar putih = 21
kunci `data-lapisan`, berkas yang dilayani 2.872 fitur `LineString` = angka
`metadata.json` (148.023,7 m), sumbu 2.034, kedua cabang gaya dan swatch
legenda ditemukan, hasil "bersih". Halaman sudah dilihat di browser pada
sesi Super Admin 15 Nov 2026: 21 pil, 20 swatch legenda, permintaan API
sesuai ukuran berkas, dan cuplikan piksel kanvas menunjukkan warna
`#0f766e` saat pil Jalan hidup lalu hilang saat dimatikan, tanpa galat
konsol.

### 3.90 Garis jalan juga muncul di Peta Bidang (15 Nov 2026)

Perintah "terapkan garis jalan pada panel peta" dijawab di `peta.php`, panel
Peta Bidang yang memakai WGS84. Sebelumnya pil Jalan di sana hanya menaruh
620 label teks dari lapisan DXF; kini di bawah label itu tergambar 2.872
garis tepi yang sama dengan pil Jalan Peta Induk (§3.89).

`api/bidang.php` dapat cabang baru `lapisan=garis-jalan`. Berkasnya
`files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-jalan-tepi.geojson`
yang bermeter lokal DXF, jadi tiap titik diterjemahkan `dxfKeWgs84()` dari
`tools/lib-impor.php` seperti cabang `batas`, dibulatkan 7 desimal, dan
properti bawaan dipangkas jadi `kode` + `panjang_m`. Galat jika berkas tidak
ada menyuruh menjalankan `tools/garis-jalan-label.php`. Cabang ini terbuka
tanpa sesi, sama seperti `batas`; tidak ada data pribadi di dalamnya.

Isi balasan diukur lewat `curl` ke localhost: HTTP 200, 632.249 byte,
86 ms, 2.872 `LineString`, 11.633 titik, 0 kode kosong, 0 titik cacat,
semua koordinat di rentang Jawa Tengah, dan jumlah `panjang_m` 148.023,7 m
— sama dengan angka `metadata.json`.

Di halaman, `muatJalan()` kini menarik `garis-jalan` dan `jalan` bersamaan
(`Promise.all`), mengosongkan `grup.jalan`, menggambar garis lebih dulu,
lalu label. Garis digambar pada `L.canvas({ pane: 'pane-jalan',
padding: 0.4 })` tersendiri supaya pane tidak menumpuk 2.872 `path` SVG;
label tetap `divIcon` sehingga menimpa kanvas. Gayanya `#0f766e`, lebar
1,1, kepekatan 0,85, `interactive: false`. Pane jalan diberi
`pointer-events: none` agar klik pemilihan bidang tidak terhalang garis.

Dua pemanggil `muatJalan()` (dari `terapkanSetelan()` dan dari inisialisasi
halaman) sempat berlomba dan memuat tiap lapis dua kali — terlihat dua
permintaan `lapisan=garis-jalan` identik di daftar jaringan. Diganti satu
janji bersama (`janjiJalan` + `muatJalanSekali()`): setelah perbaikan tiap
lapis diminta sekali, tercatat 632.549 B untuk `garis-jalan`, 72.791 B
untuk label, 13.652 B untuk `batas`.

Legenda ikut berubah: baris "Garis jalan" muncul hanya saat `setelan.jalan`
hidup, memakai aturan baru `.legenda i.contoh-garis-jalan` di
`assets/css/app.css` (tinggi 2 px, `#0f766e`), dan swatch pil Jalan
`#555` diganti `#0f766e`. Handler pil Jalan juga memanggil `gambarLegenda()`
seperti pil Blok, karena sebelumnya legenda tidak ikut menyegar saat pil
dimatikan.

Pemeriksaan: `php -l` bersih pada `api/bidang.php` dan `peta.php`,
`node --check` bersih atas 909 baris JS halaman. Di browser sesi Super
Admin pane `pane-jalan` ber-z 412, `pointer-events: none`, berisi 1 kanvas
1.386×932 dan 620 label; 17.201 piksel `#0f766e` terhitung pada muat
pertama. Mematikan pil membuat 0 piksel teal, 0 label, dan baris legenda
hilang; menyalakannya lagi memulihkan 17.424 piksel teal, 620 label, dan
baris legenda. Konsol bersih, tanpa galat.

Sejak §3.92 isi lapis ini 1.951 baris; angka 2.872 di atas adalah keadaan
saat pemasangan, dan 2.685 di §3.91 adalah keadaan sesudah saringan
pembatas persil.

### 3.91 Garis pembatas persil dibuang dari lapis tepi (15 Nov 2026)

Perintah "hapus garis jalan yang membentuk garis bidang tanah" menunjuk
cacat yang bisa diukur. Sebagian baris tepi hasil klaim tahap 1 bukan sisi
jalan: garisnya berimpit dengan sisi dua bidang tanah yang kebetulan
berada di dalam pita label. Ukur 15 Nov pada lapis terbit sebelum
perubahan (2.872 baris): tiap baris disampel tiap 2 m, lalu dua titik
0,8 m tegak lurus di kiri dan kanan diuji ke poligon tabel bidang.
197 baris (13.200,9 m, 8,9 % panjang) punya persil BERBEDA di kedua sisi
pada >= 80 % sampel; 2.545 baris (83,7 %) hanya satu sisinya di dalam
persil, dan 130 (7,4 %) tidak menyentuh persil. Asal ke-197 baris: 194
garis terbuka 020100, 2 cincin 020100, 1 cincin bidang. Pratinjau
Leaflet 2.872 garis (teal), 197 calon (merah), dan garis bidang (biru)
menunjukkan calon merah duduk tepat di batas dua persil, bukan di jalan.

Saringan dipasang di `tools/garis-jalan-label.php` sebagai
`--pembatas-persil=hapus|simpan`, bawaan `hapus` sejak 15 Nov;
`--pembatas-persil=simpan` mengembalikan keadaan §3.87/§3.89. Saringan
berjalan SESUDAH tahap 1 dan SEBELUM tahap 2. Sampel memakai `sampel()`
yang sudah ada dan id persil dibaca `idPersil()` dari kisi permukaan yang
sama (`$kisiPoligon` + `$poligon`), dengan dua peta kecil
`$poligonBidang`/`$besarBidang` yang menautkan indeks poligon ke id
bidang, jadi tidak ada salinan cincin kedua. Ambangnya `PERSIL_OFFSET`
0,8 m dan `PERSIL_BAGI` 0,8. Karena tahap 2 membaca `$tepi` yang sama,
sumbu dibangun ulang tanpa baris itu dan `tepi_all` tidak menunjuk baris
yang hilang.

Hasil pada bahan 15 Nov: dari 4.367 baris klaim tahap 1, 1.113 baris
(40.700,8 m; 1.111 `garis` dan 2 `cincin-020100`) dibuang, tersisa
3.254. Saringan `--tepi=terpakai` lalu membuang 569 baris (17.917,2 m)
yang tidak dipakai sumbu, sehingga `garis-jalan-tepi.geojson` memuat
2.685 baris / 135.541,5 m (sebelumnya 2.872 / 148.023,7 m). Sumbu menjadi
2.065 segmen / 36.514,4 m (sebelumnya 2.034 / 36.903,5 m): jumlah segmen
naik 31 dan panjang turun 389,1 m karena pasangan tepi disusun ulang
tanpa baris pembatas. Berkasnya 1.095.582 B dan 1.309.575 B.

Verifikasi. Penyaring bebas `_lama/scratch/daftar-pembatas-persil.php`
(ditulis terpisah dari alat, cincin MultiPolygon diuji per bagian)
mencetak 197 baris pada berkas lama dan 0 baris pada berkas baru.
Perbandingan `kunci` antara berkas lama dan baru: ke-197 baris pembatas
hilang semua; 21 baris bukan-pembatas (687,6 m) juga hilang dan 31 baris
baru (1.406,3 m) muncul, keduanya akibat sumbu dibangun ulang sebab
berkas tepi terbit hanya memuat baris yang jadi bahan sumbu.
`_lama/scratch/cek-saring-tepi.php`: 2.685 kunci di berkas = 2.685 kunci
di `tepi_all`, 0 tepi tanpa sumbu, 0 sumbu tanpa tepi, 2.065 sumbu /
36.514,5 m / 6.772 rujukan. Dua kali `--terapkan` menghasilkan berkas
identik (SHA-256 tepi `9ac85b61…`, sumbu `cb4103c8…`). Panel Peta Induk
membaca angka baru dari `metadata.json` (Jalan 2.685, Sumbu jalan 2.065);
`api/bidang.php?lapisan=garis-jalan` mengembalikan 2.685 `LineString`,
135.541 m, 589.948 B, HTTP 200. Di Peta Bidang kanvas `pane-jalan`
berisi 16.505 piksel teal (sebelumnya 17.201) dan 620 label, konsol
bersih, legenda tidak berubah.

Bekas berkas sebelum perubahan: `_lama/cadangan-pembatas-persil-2026-11-15/`
(tepi `7a60dbcb0013…`, sumbu `ced69ef6f5fe…`, metadata 19.346 B).
`metadata.json` kini punya blok `saring.pembatas_persil` (`aturan`,
`offset_m`, `bagi_sampel`, `dibuang`, `dibuang_panjang_m`, `asal`) dan
satu catatan yang menjelaskan saringan sebelum tahap 2. Saringan cincin
§3.92 mengubah isi lapis ini lagi menjadi 1.951 baris / 1.512 segmen.

### 3.92 Keliling bidang yang lepas dari jaringan dibuang (15 Nov 2026)

Perintah yang sama ("lapisan peta jalan masih terlihat garis peta bidang
tanah") menunjukkan sisa cacat yang lolos dari §3.91: baris keliling
bidang yang di tahap klaim masih menempel pada baris lain, sehingga
saringan klaim tidak melihatnya sebagai komponen tertutup. Saringan
`--tepi=terpakai` lalu membuang baris tak terpakai, dan justru
pelapisan itu memutus baris cincin dari jaringan: sesudahnya sisa
keliling bidang tinggal menempel di satu simpul atau sama sekali lepas,
dan lapis tepi terbaca sebagai peta bidang.

Saringan cincin di `tools/garis-jalan-label.php` kini tiga lapis,
semuanya di bawah `--cincin=hapus|simpan` (bawaan `hapus`; `simpan`
mengembalikan keadaan §3.91). Lapis (a) per baris: baris yang ujungnya
sama (<= 5 cm, `CINCIN_TUTUP`) lalu >= 90 % titik sampel keliling SATU
persil jatuh <= 0,35 m darinya adalah keliling bidang yang ditulis
sebagai satu polilin. Lapis (b) topologis di aras klaim: ujung
dibulatkan 1 cm (`CINCIN_BULAT`), baris yang berbagi ujung dianggap
bersambung, komponen yang setiap simpulnya berderajat 2 dibuang. Lapis
(a) perlu lebih dulu karena keliling persil yang bertemu keliling lain
di satu simpul membentuk komponen berderajat 4, dan komponen semacam itu
tidak lagi sederhana.

Lapis (c), `siklusLepasTepi()`, berjalan SESUDAH `--tepi=terpakai` pada
himpunan yang akan diterbitkan. Daun dipangkas berulang, lalu tiap
simpul berderajat >= 3 dicabut bersama sisinya, sehingga tersisa
komponen terpencil; komponen yang semua simpulnya berderajat 2 adalah
siklus yang menempel di <= 1 simpul. Barisnya dibuang dari berkas
terbit, rujukannya dicabut dari `tepi_all`, sumbu yang kehabisan
rujukan ikut dibuang, dan geometri sumbu lain TIDAK dihitung ulang
(sama seperti `--tepi=terpakai`). Komponen terpencil yang punya ujung
buntu (sisa digitasi) dibiarkan, begitu pula lingkaran yang menempel di
>= 2 persimpangan: bentuk itu juga bentuk jalan, dan memotongnya
berarti memotong persimpangan.

Ukur 15 Nov. Lapis (a): 582 baris tertutup diperiksa, 417 menutup
>= 90 % keliling satu persil (54.977,9 m) dan dibuang. Lapis (b): 67
dari 70 siklus sederhana mengurung persil, 253 baris (10.102,2 m)
dibuang. Keduanya menyisakan 3.697 baris klaim sebelum saringan
pembatas persil §3.91. Lapis (c) pada 2.088 baris / 1.515 sumbu: 98
siklus, 137 baris (13.037,2 m; benih 73, benih-yatim 9, pita1 49, pita2
6), 349 rujukan dicabut dari 290 sumbu, 3 sumbu kehabisan rujukan.
Hasil akhir `garis-jalan-tepi.geojson` 1.951 baris / 71.093,8 m
(sebelumnya 2.088 / 84.131,0 m) dan `garis-jalan-sumbu.geojson` 1.512
segmen / 27.176,3 m (sebelumnya 1.515 / 27.206,8 m; setelah §3.91
2.065 / 36.514,4 m).

Verifikasi. `_lama/scratch/cek-saring-cincin.php`: 1.951 kunci di berkas
tepi = 1.951 kunci unik di `tepi_all`, 0 tepi tanpa sumbu, 0 sumbu tanpa
tepi, 4.196 rujukan. `_lama/scratch/ukur-kupas-lepas.php` pada berkas
baru mencetak 0 siklus lepas; sisa 7 komponen terpencil bukan siklus
(19 baris, 395,3 m). Dua kali `--terapkan` menghasilkan tepi
`0393f97d…` dan sumbu `f4ef3eef…` identik; `metadata.json` hanya
berbeda pada waktu pembuatan. `api/bidang.php?lapisan=garis-jalan`
HTTP 200, 1.951 `LineString`, 392.966 B. Gambar banding
`_lama/scratch/banding-lepas.png` dan `banding-zoom.png` (SEBELUM 2.088
baris, SESUDAH 1.951) pada kotak yang sama: keliling bidang hilang,
lingkaran jalan dan jaringannya utuh.

Ditolak setelah diukur: saringan "baris menutup >= 80 % keliling
persil" (659 baris, 207 sumbu jadi tanpa rujukan), saringan "siklus
yang dalamnya persil" (728 baris, 125 sumbu kosong), dan ambang bagian
pasangan per baris (sisi jalan asli yang panjang ada di 0,5-0,9). Ketiga
saringan itu menghapus jalan, bukan hanya keliling bidang. Cacat yang
tersisa dan diketahui: keliling bidang yang menempel di >= 2
persimpangan tidak bisa dibedakan dari lingkaran jalan tanpa memotong
jaringan.

Bekas sebelum perubahan: `_lama/cadangan-siklus-lepas-2026-11-15/`
(tepi `7efc5af1…`, sumbu `08244068…`, metadata `9ce295bd…`).
`metadata.json` punya blok `saring.keliling_satu_baris`, `saring.cincin`
dan `saring.siklus_lepas`; blok terakhir memuat `aturan`,
`simpul_persimpangan_derajat`, `simpul_bulat_desimal`, `siklus`,
`dibuang`, `dibuang_panjang_m`, `rujukan_dicabut`, `sumbu_tersentuh`,
`sumbu_dibuang`, `cara`, dan `asal`. Diagnostik `--jejak-pasang=BERKAS`
menulis CSV bagian pasangan tiap baris terbit.

### 3.93 BLOK 22 dirapikan ke garis luar bidang anggotanya (15 Nov 2026)

Geometri BLOK 22 sebelumnya penyangga 15 m atas gabungan bidang anggota
(84.860,8 m²), bukan garis luar persil, sehingga gambar blok melebar
ke jalan dan ke blok tetangga. Permintaan pemilik data: rapikan sesuai
garis luar keseluruhan bidang tanah, jadikan satu bagian, lalu tutup
semua celah jalan yang masuk ke dalam blok.

`_lama/scratch/tutup-celah.php` berangkat dari gabungan 104 bidang
anggota (63.901,3 m²). Pasangan titik tepi satu cincin yang berhadapan
(talibusur <= 12 m, titik tengah di luar poligon, busur >= 15 m,
tambalan 0,5 sampai 2.000 m², tidak memotong cincin lain) ditambal
dengan `ST_Union` dan diulang sampai tidak ada pasangan. Sesudahnya
Douglas-Peucker 0,2 m, buang tancap, lalu potong terhadap blok lain.

Ukur 15 Nov. 27 celah jalan ditutup (talibusur 0,53-10,53 m; sekitar
3.629 m²). `ST_Union` MariaDB 10.4 menyisakan tiga tancap selebar nol
(26,3 m; 70,0 m; 8,1 m) yang tampak sebagai garis menembus persil;
`rapiBuangTancap()` membuang titik yang dua tetangganya <= 0,5 m.
Calon akhir: 1 bagian, 113 titik, 0 lubang, 67.520,4 m² (-20,4%);
irisan dengan blok lain 0,00 m². Bidang anggota yang jatuh di luar
blok 113,8 m² (0,169%), semuanya serpihan tepi utara yang terpotong
garis BLOK 21 (blok itu sendiri masih perkiraan).

Kesejajaran ke garis tepi jalan diukur
`_lama/scratch/ukur-sejajar-calon.php`: dari 1.444,4 m keliling, 718
sampel tiap 2 m, 236 (32,9%) jatuh <= 0,25 m dari baris tepi, median
6,64 m, terjauh 94,62 m di cuping tenggara yang tidak bertepi jalan.
Jadi tepi utara dan selatan menempel jalan, sisi lain mengikuti batas
belakang persil, bukan digeser ke jalan.

Diterapkan 15 Nov 2026: cadangan penuh
`sebelum-rapikan-blok-22-20261115-192524-f073.sql` (7.900.354 byte),
jejak `storage/rapikan-blok-24-20261115-192525.json`, lalu `UPDATE
blok` id 24 (`geom` dan `sumber_perkiraan` menjadi "gabungan bidang
anggota + tutup celah jalan; ditetapkan"). Baca ulang: satu bagian,
0 lubang, 67.520,4 m², selisih tulis-baca 0,00 m². Gambar banding
`_lama/scratch/blok22-terpasang.png`.

Ditetapkan sebagai batas terpasang 15 Nov 2026: `batas_tersedia` 1,
`batas_perkiraan` 0. Garis lama dibuat `tools/buat-geometri-blok22.php`
(selubung cembung titik pusat bidang, dipotong tepi BLOK 7 dan BLOK 16,
digeser 0,0003 derajat ke luar, `UPDATE blok` tanpa cadangan); berkas
itu dihapus karena tidak dirujuk berkas lain, dan geometri lamanya
masih terbaca di cadangan penuh 15 Nov serta di kunci `sebelum.geojson`
jejak `storage/rapikan-blok-24-20261115-192525.json`.

Calon antara juga dikeluarkan dari `_lama/scratch/`: satu
`blok22-mentah.geojson`, empat `kandidat-t*.geojson`, dua
`kandidat-tertutup*.geojson`, dan tiga gambar `blok22.*`, semuanya
dipindah ke `_lama/cadangan-garis-lama-blok22-2026-11-15/` bersama
salinan sumber alat yang dihapus. Yang tetap di `_lama/scratch/`:
`blok22-terpasang.geojson`, `blok22-terpasang.png`, dan
`kandidat-tertutup-03.geojson` sebagai calon yang terpasang.

### 3.94 BLOK 21: celah ditutup pada batas yang sudah terpasang (15 Nov
2026)

BLOK 21 (id 23) tidak dirapikan dengan cara BLOK 22. Batas terpasangnya
sudah mengikuti tepi jalan, bukan penyangga: dari 184 titik tepi, jarak ke
larutan 334 bidang anggota berjarak median 5,18 m (90,2% <= 10 m, terjauh
60,00 m). Karena itu 26.515,5 m² blok tanpa bidang anggota bukan tanah yang
belum diklaim, melainkan margin jalan selebar beberapa meter ditambah gang
antar baris persil; satu poligonnya 24.011,1 m² dengan 30 cincin dalam.
Gambar banding `_lama/scratch/blok21-terpasang.png`: bidang abu, margin
tanpa persil merah muda, garis lama biru putus.

Percobaan menyusun ulang dari persil tetap dijalankan sebagai pembanding dan
hasilnya ditolak. Ke-334 bidang larut menjadi GeometryCollection (36 poligon
+ 3 LineString degenerat) sehingga `tools/rapikan-garis-blok.php` berhenti;
`rapiPoligonDariGeoJson()` sekarang hanya mengumpulkan anggota poligonal.
Dengan `--dari-bidang --paksa` hasilnya 202.230,3 m² dan 35 jembatan, lalu
`tutup-celah.php` menambah 60 tambalan menjadi 217.003,6 m². Calon itu
sempat ditolak karena satu potongan 13.231,5 m² tanpa persil di sekitar
110,059474,-7,096356 dan 11.934,1 m² di sisi lain yang hilang. §3.95
menunjukkan potongan itu jaringan gang dalam blok, sehingga jalur persil
kemudian dipakai ulang.

Yang diterapkan: `tutup-celah.php` pada batas terpasang, lima celah
(talibusur 2,48-11,94 m; 39,2 / 23,5 / 49,9 / 43,9 / 19,4 m²; seluruhnya
175,9 m²), Douglas-Peucker 0,2 m, tancap dibuang, potong blok tetangga.
Hasil: satu bagian, 0 lubang, 0 cincin memotong diri, 479 menjadi 183 titik
tepi, 228.541,6 menjadi 228.654,9 m² (+113,2 m²; +0,05%), keliling 2.520,6
menjadi 2.446,3 m, irisan dengan blok lain 0,00 m². Bidang anggota yang
jatuh di luar blok 386,1 menjadi 304,5 m² (0,150%; terbesar 268,1 m² di
110,058870,-7,095998).

Kesejajaran ke garis tepi jalan diukur
`_lama/scratch/ukur-sejajar-calon.php`: 1.163 sampel tiap 2 m, 570 (49,0%)
<= 0,25 m dari baris tepi (sebelum 40,9%), median 0,31 m (sebelum 1,00 m),
terjauh 68,98 m di cuping timur laut yang tidak bertepi jalan. Cuping tipis
di ujung timur laut itu sudah ada pada garis lama dan tidak diubah.

Diterapkan 15 Nov 2026: cadangan penuh
`sebelum-rapikan-blok-21-20261115-195525-1394.sql` (7.891.898 byte), jejak
`storage/rapikan-blok-23-20261115-195526.json`, lalu `UPDATE blok` id 23
(`geom` dan `sumber_perkiraan` menjadi "batas blok lama + tutup celah jalan;
ditetapkan"). `batas_tersedia` 1 dan `batas_perkiraan` 0 sudah terpasang
sebelum ini dan tidak diubah. Baca ulang: satu bagian, 0 lubang, 183 titik,
228.654,9 m², selisih tulis-baca 0,00 m².

Dua catatan pengukuran. `ST_Centroid` MariaDB 10.4 mengembalikan NULL untuk
GeometryCollection hasil `ST_GeomFromGeoJSON` berkas FeatureCollection,
sehingga luas terbaca +0,77% kalau lintang pusat dipakai untuk konversi
derajat ke meter; skrip ukur di sini memakai satu lintang acuan dari
`blok.geom` (-7,0941). Angka "irisan blok lain" keluaran `tutup-celah.php`
(57,0 m²) juga bukan irisan nyata: `ST_Difference` menormalkan titik cubit
sehingga luas berkurang; irisan terukur lewat `ST_Intersection` 0,001 m²
dengan BLOK 22 sebelum dan 0,00 m² sesudah.

Bidang yang pusatnya di dalam BLOK 21 tetapi blok_id-nya lain atau kosong
tetap 15 baris (8 bidang ±4.993,6 m² milik BLOK 20/22/23, 7 bidang
±1.855,7 m² tanpa blok). Itu urusan penetapan blok bidang, bukan geometri
blok, jadi tidak diubah di sini.

### 3.95 BLOK 21 disusun ulang dari 333 persil, mulut jalannya ditutup (15
Nov 2026)

§3.94 hanya menutup lima celah kecil pada garis lama, sedangkan garis lama
itu sendiri berjarak median 5,18 m di luar larutan persil. Permintaan
pemilik data: garis luar keseluruhan bidang tanah, satu bagian, dan celah
jalan yang masuk ditutup. Jalur persil yang sempat ditolak di §3.94 karena
potongan 13.231,5 m² tanpa persil kini dipakai ulang; potongan itu ternyata
jaringan gang dalam blok (pemeriksaannya di bawah).

Data hidup bergerak selama pengerjaan. 146 bidang dibuat 15 Nov pukul
14:56-14:59; satu di antaranya, DXF-020100-05996 (id 23180, 36,5 m²),
sempat menjadi anggota BLOK 21 lalu pindah ke blok 24 pukul 20:11:44
(terbaca pada cadangan pukul 20:07). Anggota BLOK 21 karena itu 334
menjadi 333, dan calon dibangun ulang dari 333 bidang terkini.

`tools/rapikan-garis-blok.php --dari-bidang --paksa` (toleransi 0,2 m,
rapat 30 m, abaikan jalan) atas 333 bidang menghasilkan dua bagian
202.500,3 m²; jembatan pita 1,5 m menyatukannya menjadi satu bagian
202.454,9 m² (777 titik). `tutup-celah.php` lalu menambal 78 pasangan titik
tepi (talibusur 0,44-11,63 m; total 14.650,3 m²; terbesar 1.921,3 m²),
disusul Douglas-Peucker 0,2 m, buang tancap, dan potong blok tetangga.

Hasil: satu bagian, 0 lubang, 144 titik, 217.111,1 m². Naik 14.656,2 m²
dari calon berjembatan 202.454,9 m², sepadan dengan jumlah tambalan
14.650,3 m²; selisih 5,9 m² berasal dari tambalan yang saling menimpa dan
penyederhanaan.

Pemeriksaan. Irisan dengan blok lain 0,00 m². Bidang anggota yang jatuh di
luar garis 22,40 m² (0,011%), terbesar 3,53 m² pada HM-1316. Sisa luas yang
bukan persil 14.769,3 m² dalam 40 komponen; komponen terbesar 13.831 m²
dengan 684 titik dan 14 cincin, kotak pembatas 672 x 490 m terisi 4,2%,
jadi bentuk jaringan koridor, bukan cuping padat. Ini memperbaiki klaim
§3.94.

Diterapkan 15 Nov 2026: cadangan penuh
`sebelum-rapikan-blok-21-20261115-201724-7cd1.sql` (7.899.092 byte), jejak
`storage/rapikan-blok-23-20261115-201725.json`, lalu `UPDATE blok` id 23
(`geom` dan `sumber_perkiraan` menjadi "garis luar bidang anggota + tutup
celah jalan; ditetapkan"). Bendera `batas_tersedia` 1 dan
`batas_perkiraan` 0 tidak berubah. Baca ulang: satu bagian, 0 lubang,
144 titik, 217.111,1 m², selisih tulis-baca 0,00 m².

Gambar banding `_lama/scratch/banding-blok21.png`: panel kanan dibaca
langsung dari `blok.geom`, panel kiri dari jejak pukul 19:55 (228.541,6 m²,
garis lama + lima tambalan §3.94). Calon yang tersisa di `_lama/scratch/`:
`blok21-dari-bidang-02.geojson` (dua bagian 202.500,3 m²),
`blok21-dari-bidang-02-satu-b.geojson` (berjembatan 202.454,9 m²), dan
`blok21-dari-bidang-tertutup-b.geojson` (terpasang).

### 3.96 BLOK 19 dirapikan ke garis luar 105 persilnya (15 Nov 2026)

BLOK 19 (id 22) sebelumnya selubung kasar: seluruh 105 bidang anggotanya
ada di dalam, tetapi tepinya rata-rata 7,1 m di luar garis luar persil,
sehingga blok memuat 19.745,0 m² tanpa persil. Sumber lama:
"gabungan anggota; lidah dipotong; barat BLOK 21; ditetapkan".

Calon disusun dengan resep §3.93/§3.95. `tools/rapikan-garis-blok.php 19
--dari-bidang --paksa --toleransi 0,2` melebur 105 bidang menjadi satu
bagian 266 titik, 147.194,3 m², dengan 9 jembatan; toleransi 1,5 ditolak
karena memangkas 555,74 m² persil sendiri. `tutup-celah.php` (talibusur
maksimum 12 m, toleransi 0,2, id 22) menambah 24 tambalan mulut gang
(talibusur 0,50-11,88 m; 2.979,7 m²), membuang 6 tancap, lalu
Douglas-Peucker 0,2 m.

Calon akhir: satu bagian, 0 lubang, 93 titik, 150.174,1 m² (-19.745,1 m²;
-11,62%). Irisan dengan blok lain 0,00 m². Persil anggota di luar calon
2,43 m² (terbesar 0,40 m² `DXF-020100-G02477`); bukan-persil di dalam
3.020,3 m² dalam 14 komponen, yaitu jaringan gang dalam blok. Keliling
1.964,4 m; 44,9% panjang tepi <= 0,25 m dari baris tepi jalan, median
4,03 m, terjauh 114,32 m di cuping timur laut 110.068339,-7.088891.

Pita yang dibuang mengelilingi blok, lebar median 7,1 m (p10 3,3; p90
18,5; terjauh 26,0 m; 96,8% panjang <= 20 m); sisi utara paling lebar
(median 16,8 m), barat paling sempit (4,1 m). Tidak ada persil di pita,
hanya serpihan tetangga: HM-0849 (blok 16) 40,59 m², tiga persil blok 8
seluruhnya 10,35 m², dan 2,4 m² persil blok 22. Persil raksasa
`DXF-010500-00001` (6,79 km², status `dihapus`) menutupi pita tetapi
bukan persil nyata.

Jarak ke tetangga berubah karena BLOK 21 (§3.95) dan BLOK 22 (§3.93) juga
kini garis luar persil: BLOK 14 0 menjadi 9,99 m, BLOK 18 0 menjadi
1,72 m, BLOK 20 0 menjadi 3,20 m, BLOK 24 0 menjadi 2,96 m, BLOK 21 0
menjadi 41,81 m. Celah itu koridor jalan dan bahu antara blok, bukan
galat.

Diterapkan 15 Nov 2026: cadangan penuh
`sebelum-rapikan-blok-19-20261115-204246-a055.sql` (7.888.847 byte),
jejak `storage/rapikan-blok-22-20261115-204247.json`, lalu `UPDATE blok`
id 22 (`geom` dan `sumber_perkiraan` menjadi "garis luar bidang anggota +
tutup celah jalan; ditetapkan"). Bendera `batas_tersedia` 1 dan
`batas_perkiraan` 0 tidak berubah. Baca ulang: satu bagian, 0 lubang,
93 titik, 150.174,1 m², selisih tulis-baca 0,00 m².

Gambar `_lama/scratch/banding-blok19.png`: kiri dari jejak 20:42, kanan
dari `blok.geom`. Calon di `_lama/scratch/`: `blok19-dari-bidang.geojson`
(toleransi 1,5), `blok19-t02.geojson` (sebelum tutup celah),
`blok19-t02-tumpang.geojson`, dan `blok19-tertutup.geojson` (terpasang).
Alat baca saja: `ukur-selisih-blok19.php`, `ukur-calon-blok19.php`,
`banding-blok19.php`; penulis `terapkan-blok19.php`.

### 3.97 BLOK 10 dirapikan ke garis luar 228 persilnya (16 Nov 2026)

BLOK 10 (id 17) sebelumnya dua bagian: 244.734,8 m² dalam 267 titik dan
serpihan 163,4 m² (24 x 16 m, pusat 110.070623,-7.085475), total
244.898,2 m²; sumber lama "MASTER.dxf + 474 geometri bidang BLOK 10"
dengan 228 persil anggota. Kasusnya lebih ringan daripada §3.96: tepi
terpasang sudah mengikuti garis luar persil, jarak titik calon ke tepi
lama median 0,0 m (p90 1,5 m), jadi hasilnya perapian, bukan koreksi
selubung.

Resep §3.93/§3.95 dipakai apa adanya. `tools/rapikan-garis-blok.php 10
--dari-bidang --paksa --toleransi 0,2` memberi tiga bagian, 671 titik,
235.995,8 m², 15 jembatan, dan 2 jembatan dilewati karena koridornya
jalan permukiman 6 m. Dua gugus persil (7.586,7 dan 9.386,7 m²)
terpisah 2,18-2,44 m oleh jalan itu, jadi `--abaikan-jalan` dipakai
untuk menyatukannya: satu bagian, 677 titik, 236.002,7 m², 17
jembatan. `tutup-celah.php` (talibusur maksimum 12 m, toleransi 0,2,
id 17) lalu menambah 57 tambalan (talibusur 0,46-11,76 m; 8.101,2 m²;
terbesar 1.038,4 m² di 110.066044,-7.084869), membuang tancap, dan
Douglas-Peucker 0,2 m.

Calon akhir: satu bagian, 0 lubang, 157 titik, 244.107,1 m² (-791,1 m²;
-0,32%). Irisan dengan blok lain 0,00 m². Keliling 4.091,5 menjadi
3.601,7 m (-489,8 m; -12,0%). Serpihan lama tidak dibuang: titik
pusatnya kini berada di dalam satu bagian itu, sebab tambalan mulut
jalur menyambungnya ke badan utama.

Pita yang dibuang 1.310,7 m² dalam 20 komponen (terbesar 1.030,0 m²,
201 x 123 m, pusat 110.067934,-7.084686; lalu 142,8 m², 72,4 m², dan
26,3 m²), sedangkan 519,6 m² justru ditambahkan di mulut jalur, jadi
selisih bersihnya -791,1 m². Isi pita hanya persil hantu
`DXF-010500-00001` (status `dihapus`) seluas 1.310,70 m² dan 19 persil
anggota 20,33 m²; tidak ada persil tetangga yang tertelan. Persil
anggota di luar garis 18 persil, 20,33 m², 5 di antaranya di atas 1 m²
(15,60 m²), terbesar 7,45 m² `DXF-020100-G00895`.

Jaringan gang di dalam blok menyusut dari 9.149,3 m² dalam 27 komponen
menjadi 8.378,6 m² dalam 35 komponen. Jarak ke tetangga hanya bergerak
di BLOK 15 (0,00 menjadi 3,72 m; sebelumnya bersentuhan), BLOK 13
(61,43 menjadi 62,14 m), dan BLOK 8 (136,22 menjadi 136,37 m); BLOK 9
tetap 2,51 m dan BLOK 14 tetap 3,59 m.

Diterapkan 16 Nov 2026 pukul 06:33: cadangan penuh
`sebelum-rapikan-blok-10-20261116-063347-bf2f.sql` (7.888.292 byte),
jejak `storage/rapikan-blok-17-20261116-063348.json`, lalu `UPDATE
blok` id 17 (`geom` dan `sumber_perkiraan` menjadi "garis luar bidang
anggota + tutup celah jalan; ditetapkan"). Bendera `batas_tersedia` 1
dan `batas_perkiraan` 0 tidak berubah. Baca ulang: satu bagian, 0
lubang, 157 titik, 244.107,1 m², selisih tulis-baca 0,00 m².

Gambar `_lama/scratch/banding-blok10-terpasang.png`: kiri dari jejak
06:33, kanan dari `blok.geom`. Calon di `_lama/scratch/`:
`blok10-dari-bidang-02.geojson` (tiga bagian),
`blok10-dari-bidang-satu.geojson` (677 titik),
`blok10-tertutup.geojson` (tiga bagian; ditolak penutup celah karena
bukan satu bagian), dan `blok10-tertutup-satu.geojson` (terpasang).
Alat baca saja: `ukur-selisih-blok10.php`, `ukur-blok10-terpasang.php`,
`cek-blok10-terpasang.php`, `cek-bagian-blok10.php`, dan
`banding-blok10.php`; penulis `terapkan-blok10.php`.

### 3.98 BLOK 13 dirapikan ke garis luar 188 persilnya (16 Nov 2026)

BLOK 13 (id 12) satu bagian, 2.413 titik, 238.728,9 m² dengan 188
persil anggota (147 aktif, 41 perlu_verifikasi). Sumber lamanya tertulis
"Geometri digabungkan menjadi 1 bagian dengan buffer 3.0E-5 (" — 60
aksara, terpotong di tengah kalimat — dan bentuknya memang selubung:
garis terpasang berjalan sejajar garis luar persil pada jarak 4,4 m
(p10 4,4; p90 4,8; terjauh 14,3 m di sisi barat), sehingga pita
18.035,9 m² di luar persil ikut menjadi bagian blok. Akibatnya garis
lama juga menumpang tetangga: BLOK 12 seluas 1.644,31 m² dan BLOK 14
seluas 171,63 m². Ini kasus koreksi selubung, bukan perapian seperti
§3.97.

Resep §3.93/§3.95 dipakai apa adanya. `tools/rapikan-garis-blok.php 13
--dari-bidang --paksa --toleransi 0,2` memberi dua bagian, 647 titik,
214.985,1 m², 18 jembatan, dan satu jembatan dilewati karena koridornya
jalan `unclassified` 6 m (celah 1,4 m di 110.062839,-7.087339). Dengan
`--abaikan-jalan` gugus itu disatukan: satu bagian, 650 titik,
214.987,3 m², 19 jembatan, irisan blok lain 1,3 m² dipotong.
`tutup-celah.php` (talibusur maksimum 12 m, toleransi 0,2, id 12) lalu
menambah 65 tambalan (talibusur 0,50-9,21 m; median 2,01 m; 5.702,6 m²;
terbesar 1.031,7 m² di 110.062886,-7.085765, koridor gang bermulut
3 m), membuang 8 tancap, dan Douglas-Peucker 0,2 m.

Calon akhir: satu bagian, 0 lubang, 0 cincin memotong diri, 203 titik,
220.692,8 m² (-18.036,3 m²; -7,55%), keliling 4.018,3 m dari
sebelumnya 4.017,7 m. Irisan dengan blok lain 0,00 m², dan calon
seluruhnya berada di dalam garis lama (arah sebaliknya 0,00 m²).

Pita yang dibuang berisi 15.728,2 m² persil hantu `DXF-010500-00001`
(status `dihapus`), 2.148,6 m² persil milik BLOK 12, 165,8 m² persil
milik BLOK 14, dan 19,0 m² persil BLOK 13 sendiri (gerus Douglas-
Peucker). Persil anggota yang tinggal di luar garis 19 persil, 19,05 m²,
terbesar 5,46 m² `DXF-020100-G01900`; pada garis lama 0 persil. Isi
bukan-persil di dalam blok turun dari 21.404,7 m² dalam 20 komponen
menjadi 5.552,5 m² dalam 35 komponen (terbesar 5.148,0 m² di
110.061991,-7.087565, jaringan gang).

Jarak ke tetangga: BLOK 12 menempel (0,10 menjadi 0,00 m; irisan
1.644,31 menjadi 0,00), BLOK 14 0,00 menjadi 3,02 m (irisan 171,63
menjadi 0,00), BLOK 20 4,02 menjadi 8,46 m, BLOK 10 62,14 menjadi
66,58 m, BLOK 19 86,42 menjadi 90,86 m, BLOK 15 127,42 menjadi 131,85
m. Dua persil ber-`blok_id` 14 (BLOK 12), yaitu `DXF-020100-G01449`
(5.420,2 m² di atas `HM-0810`) dan `DXF-020100-G01970` (1.582,6 m² di
atas `DXF-020100-G03347`), bertumpang di persil BLOK 13; itu duplikat
persil di data sumber, bukan akibat garis blok, jadi tidak diubah.

Diterapkan 16 Nov 2026 pukul 06:49: cadangan penuh
`sebelum-rapikan-blok-13-20261116-064950-cc6e.sql` (7.886.617 byte),
jejak `storage/rapikan-blok-12-20261116-064951.json`, lalu `UPDATE
blok` id 12 (`geom` dan `sumber_perkiraan` menjadi "garis luar bidang
anggota + tutup celah jalan; ditetapkan"). Bendera `batas_tersedia` 1
dan `batas_perkiraan` 0 tidak berubah. Baca ulang: satu bagian, 0
lubang, 204 koordinat (203 titik unik), 220.693,0 m², selisih
tulis-baca 0,00 m², dan `ST_Equals` dengan calon bernilai 1.
Angka 220.692,8 / 220.693,0 / 220.692,9 m² adalah satu bentuk yang sama
pada tiga dasar lintang (titik cincin, pusat blok sebelum dan sesudah
tulis); selisihnya 0,2 m² ke bawah.

Gambar `_lama/scratch/banding-blok13-terpasang.png`: kiri dari jejak
06:49, kanan dari `blok.geom`. Calon di `_lama/scratch/`:
`blok13-dari-bidang.geojson` (dua bagian),
`blok13-dari-bidang-satu.geojson` (650 titik), dan
`blok13-tertutup-satu.geojson` (terpasang); jejak tambalan di
`tutup-celah-jejak-blok13.txt`. Alat baca saja: `ukur-selisih-blok13.php`,
`ukur-isi-blok13.php`, `cek-blok13-terpasang.php`, `banding-blok13.php`,
dan `banding-blok13-terpasang.php`; penulis `terapkan-blok13.php`.

### 3.99 BLOK 13 disusulkan setelah satu persil pindah blok (16 Nov 2026)

Lima menit setelah garis §3.98 ditulis, pukul 06:54:19, Super Admin
memindahkan persil `DXF-020100-G01484` (148,41 m², status aktif) dari
`blok_id` 14 (BLOK 12) ke `blok_id` 12 (BLOK 13) lewat halaman bidang;
audit_log 3642 mencatat data sebelum dan sesudahnya. Persil itu seluruhnya
berada di luar garis yang terpasang, jadi hitungan anggota berubah dari
188 persil/19 di luar garis (19,05 m²) menjadi 189 persil/20 di luar
garis (167,46 m²) tanpa satu titik pun berubah pada `blok.geom`.

Resep yang sama diulang dari kumpulan persil sekarang, bukan dari garis
lama. Union `--dari-bidang --paksa --toleransi=0,2 --abaikan-jalan`
memberi satu bagian, 651 titik, 215.135,7 m², 19 jembatan, irisan blok
lain 1,3 m² dipotong; `tutup-celah.php` (12 m, 0,2, id 12) menambah 65
tambalan, membuang 8 tancap, lalu Douglas-Peucker 0,2 m. Calon baru: satu
bagian, 0 lubang, 205 koordinat (204 titik unik), 220.841,4 m².

Selisih terhadap garis §3.98 hanya satu arah: `terpasang - calon` 0,00 m²
dalam 0 komponen, sedangkan `calon - terpasang` 148,4 m² dalam satu
komponen 15 x 16 m di 110.059739,-7.086102 yang isinya persis persil
G01484 (irisan 148,41 m²). Tidak ada bagian lain yang bergeser, dan jarak
ke tetangga tetap: BLOK 12 0,00 m (menempel, irisan 0,00), BLOK 14 3,02
m, BLOK 20 8,46 m, BLOK 19 90,86 m, BLOK 10 66,58 m.

Diterapkan 16 Nov 2026 pukul 06:59: cadangan penuh
`sebelum-rapikan-blok-13-20261116-065916-8cc9.sql` (7.851.423 byte),
jejak `storage/rapikan-blok-12-20261116-065917.json`, lalu `UPDATE blok`
id 12 dengan `sumber_perkiraan` yang sama. Baca ulang: satu bagian, 0
lubang, 205 koordinat, 220.841,4 m², selisih tulis-baca 0,00 m²,
`ST_Equals` dengan calon bernilai 1; bendera 1/0 tidak berubah; irisan
blok lain 0,00 m². Sesudahnya anggota 189 persil dengan 19 persil di luar
garis (19,05 m², terbesar 5,46 m² `DXF-020100-G01900`), dan G01484 kini
0,00 m² di luar garis.

Calon `_lama/scratch/blok13-tertutup-satu-ulang.geojson`; jejak tambalan
`tutup-celah-jejak.txt` dengan salinan lama
`tutup-celah-jejak-sebelum-ulang-blok13.txt`. `ukur-selisih-blok13.php`
kini memilih pita yang tidak kosong (arah `calon - terpasang` bila
`terpasang - calon` nol) supaya tidak galat pada `jarakM(null)`, dan
`cek-g01484-blok13.php` mengukur persil pindahan terhadap garis terpasang
maupun calon.

### 3.100 BLOK 8 dipangkas dari kerah penyangga 15 m (16 Nov 2026)

Garis BLOK 8 (id 19) adalah "gabungan bidang anggota + penyangga 15 m":
satu bagian, 215 koordinat (214 titik unik), 267.877,5 m², dengan
`batas_tersedia` 1 dan `batas_perkiraan` 0. Kumpulan anggotanya 163
persil (90 aktif, 73 perlu_verifikasi). Kerah 15 m itu menempel persil di
barat dan timur (median 0,0 m), tetapi menonjol sampai 15,4 m di utara
dan 8,0 m di selatan (median selatan 3,4 m, p90 keseluruhan 6,1 m).

Resep yang sama dengan §3.97 dan §3.98: union persil anggota
(`--dari-bidang --paksa --toleransi=0,2`, tanpa `--abaikan-jalan`)
memberi satu bagian, 372 titik, 256.580,8 m², 8 jembatan, irisan blok
lain 16,4 m² dipotong. `tutup-celah.php` (12 m, 0,2, id 19) menambah 23
tambalan (talibusur terpanjang 10,82 m), membuang 30 tancap, memangkas
39,6 m² terhadap garis blok lain, lalu Douglas-Peucker 0,2 m.

Calon akhir: satu bagian, 0 lubang, 135 koordinat (134 titik unik),
262.231,6 m² (-5.647,2 m²; -2,11%). Selisihnya satu arah: `terpasang -
calon` 5.647,2 m² dalam 16 komponen (terbesar 2.620,0; 2.150,4;
871,4 m²), sedangkan `calon - terpasang` 1,5 m² dalam 12 komponen
nyaris nol.

Pita yang dibuang berisi 5.647,17 m² persil hantu `DXF-010500-00001`
(status `dihapus`), 871,6 m² persil BLOK 6 (`DXF-020100-G00819` 498,63
ditambah `DXF-020100-G03332` 372,76), 14,5 m² persil BLOK 4, 6,2 m²
BLOK 5, 1,1 m² BLOK 1, 1,9 m² BLOK 18, dan 5,9 m² persil BLOK 8 sendiri
(gerus Douglas-Peucker). Persil anggota yang tinggal di luar garis naik
dari 11 persil/8,99 m² menjadi 22 persil/13,72 m², terbesar 3,55 m²
`DXF-020100-G00825`; kenaikan itu wajar karena garis kini mengikuti tepi
persil, bukan kerah yang menutupi tonjolan kecil, dan totalnya tinggal
13,72 m². Isi bukan-persil di dalam blok turun dari 10.149,5 m² dalam
26 komponen menjadi 5.404,5 m² dalam 34 komponen (terbesar 5.307,1 m² di
110.075136,-7.082810, jaringan gang).

Jarak ke tetangga: BLOK 3 0,00 menjadi 3,64 m, BLOK 5 0,00 menjadi
2,36 m, BLOK 15 0,00 menjadi 4,41 m; BLOK 7, 9, dan 16 tetap menempel
(0,00 m); BLOK 17 31,19 menjadi 46,19 m, BLOK 18 36,70 menjadi 51,68 m,
BLOK 6 101,99 m, BLOK 10 136,37 m.

Diterapkan 16 Nov 2026 pukul 07:10: cadangan penuh
`sebelum-rapikan-blok-8-20261116-071012-2e80.sql` (7.854.295 byte),
jejak `storage/rapikan-blok-19-20261116-071013.json`, lalu `UPDATE blok`
id 19 (`geom` dan `sumber_perkiraan` menjadi "garis luar bidang anggota +
tutup celah jalan; ditetapkan"). Bendera 1/0 tidak berubah. Baca ulang:
satu bagian, 0 lubang, 135 koordinat, 262.231,8 m², selisih tulis-baca
0,00 m², `ST_Equals` dengan calon bernilai 1, dan irisan blok lain
0,00 m². Angka 262.231,6 dan 262.231,8 m² adalah satu bentuk yang sama
pada dua dasar lintang (pusat blok sebelum dan sesudah tulis).

Calon `_lama/scratch/blok8-tertutup-satu.geojson`, union
`blok8-dari-bidang.geojson`, jejak tambalan `tutup-celah-jejak.txt`,
gambar banding `banding-blok8-calon.png`. Alat baca saja:
`ukur-selisih-blok8.php`, `ukur-isi-blok8.php`,
`cek-blok8-terpasang.php`, `banding-blok8.php`; penulis
`terapkan-blok8.php`.

### 3.101 Tepi sungai: lapis Sungai disambung menjadi 126 rantai (20 Nov 2026)

Lapis Sungai (`garis-perairan.geojson`, lihat §3.77) sudah memuat tepi alur
hasil penurunan layer 020100, tetapi setelah pembersihan cincin bidang dan
ujung menggantung bentuknya masih 275 baris · 12.352,30 m dalam 49 komponen:
bekas jahitan memutus tepian menjadi potongan pendek (median baris 22,32 m;
127 baris di bawah 20 m), dan tiap potongan berdiri sendiri di peta. Untuk
dipakai sebagai "tepi sungai" potongan itu perlu disatukan lagi per tepian,
tanpa menggambar ulang apa pun.

Pil baru **Tepi sungai** di kelompok Garis turunan (tidak tercentang) menyajikan
hasilnya, dan lapis Sungai tetap menjadi bahan mentahnya. Alat
`tools/tepi-sungai.php` menyambung baris dengan aturan berikut.

- Simpul: ujung garis yang berjarak ≤ 0,35 m satu sama lain dianggap satu titik.
  Ujung yang menempel di **tengah** ruas garis lain juga dijadikan simpul: titik
  proyeksinya disisipkan ke garis itu secara kolinear, sehingga bentuk tidak
  berubah. Ada 108 ujung seperti itu.
- Dari satu simpul rantai melanjutkan ke garis yang arahnya paling lurus dengan
  arah datang, asal belokannya ≤ 90°. Ini cukup untuk menyusuri belokan sungai,
  tetapi menolak putar balik; di simpang tiga, putar balik bisa memindahkan
  rantai ke tepi cabang lain.
- Garis yang sudah tertutup menjadi rantai sendiri. Rantai di bawah 1 m dibuang
  karena tak tampak: 14 buah (semua bernomor `PERAIRAN-SAMBUNG`, sisa lingkaran
  jahitan), seluruhnya 2,78 m.

Hasilnya **126 rantai · 12.349,52 m**. Panjangnya persis panjang bahan dikurangi
lingkaran mikro yang dibuang (12.352,30 − 2,78), jadi penyambungan tidak
menambah atau menghilangkan garis. Cakupannya juga tepat: 261 baris bahan
dipakai sekali, tidak ada yang terpakai dua kali atau tidak terpakai selain 14
lingkaran mikro itu. Verteks sisipan yang ternyata berimpit (jarak di bawah
1 mm) dibuang saat menulis supaya tidak ada ruas panjang nol; sepuluh rantai
tetap tertutup penuh dan itu memang garis tertutup dari bahannya. Rantai
terpanjang 895,15 m (14 baris bahan), rata-rata 98,0 m, median 38,23 m, p90
278,78 m; 75 rantai berasal dari satu baris saja, sedangkan 11 rantai di bawah
5 m dan 39 di bawah 20 m.

Nilai `--sudut` mengubah jumlah rantai tetapi tidak mengubah geometri, hanya
pemenggalan:

| `--sudut` | rantai | median |
| --- | --- | --- |
| 60° | 152 | 34,25 m |
| **90° (bawaan)** | **126** | **38,23 m** |
| 120° | 103 | 46,28 m |
| 150° | 99 | 45,84 m |

90° dipilih karena belokan yang lebih tajam dari itu di jaringan ini justru
banyak yang berupa hairpin sah — tepi kiri, ujung alur, lalu tepi kanan — dan
menerimanya membuka peluang rantai berpindah tepi di persimpangan. Belokan di
atas 90° yang tersisa di hasil akhir tinggal 13% (130 dari 985), semuanya
hairpin di ujung alur.

Lapis disajikan `api/peta-lama.php?lapisan=garis-tepi-sungai` dan digambar teal
`#0e7490` tebal 2,1 (Sungai biru `#1d4ed8` tebal 1,7), supaya keduanya bisa
dibandingkan dengan menyalakan pil bergantian. Metadata lapis tercatat di
`layers.GARIS TEPI SUNGAI 020100` dengan blok `tepi_sungai` (toleransi simpul,
sudut lanjut, jumlah sisipan, rantai mikro dibuang, sebelum/sesudah).

```
php tools/tepi-sungai.php                      # uji kering, cetak angka
php tools/tepi-sungai.php --keluar=pratinjau.geojson
php tools/tepi-sungai.php --sudut=120 --terapkan
```

`--terapkan` menulis `garis-tepi-sungai.geojson` dan memperbarui metadata,
setelah mencadangkan keduanya ke `_lama/cadangan-tepi-sungai-YYYYMMDD/`.
Berkas hasil tidak di-force-add ke git karena bisa dibuat ulang dari
`garis-perairan.geojson` yang sudah terlacak.

Lapis ini tetap berupa garis, bukan poligon sungai: kedua tepi masih bertemu di
ujung alur (hairpin) dan mulut hilirnya terbuka. Bila kelak dibutuhkan poligon
badan air, itu pekerjaan tersendiri — lapis ini hanya menyatukan garis yang
sudah ada.

---

### 3.102 Titik tengah Bidang HM: satu titik per bidang berhak (20 Nov 2026)

Lapis BIDANG HM (`bidang-hm.geojson`) berisi **1.496 fitur** — 1.494 poligon
bidang tertutup dan 2 garis terbuka (indeks 459 dan 460, potongan rangkaian
yang ujungnya tidak bertemu). Pil **Titik tengah** yang sudah ada di
kelompok Bidang kadastrum hanya menandai poligon tertutup 020100 dan dihitung
di peramban; bidang HM tidak punya titik serupa. Alat
`tools/titik-tengah-bidang-hm.php` menurunkan lapis titik baru
`titik-tengah-bidang-hm.geojson` dengan aturan berikut.

- Poligon memakai **titik berat badan** (centroid luas shoelace pada meter DXF
  lokal), bukan rata-rata titik sudut, supaya bidang berbentuk L tetap dapat
  titik di badannya. Satu bidang cekung (indeks 1.190, 3.187,58 m²) titik
  beratnya jatuh di luar ring, jadi dipakai **garis pindai** mendatar pada
  beberapa ketinggian kotak batas dan diambil pertengahan rentang dalam yang
  terlebar; pertengahan kotak batas tidak terpakai sama sekali.
- Garis terbuka memakai **titik tengah menurut panjang** garis dan diberi
  sifat `terbuka: true`, `pusat: "tengah-garis"`.

Hasilnya **1.496 titik**, tepat satu per fitur (tidak ada indeks hilang atau
tergandakan), semuanya berupa `Point`. Pemeriksaan ulang terpisah: **0** titik
poligon jatuh di luar ring bidangnya sendiri; luas seluruh poligon
1.458.682,18 m². Rincian cara titik: 1.493 `titik-berat`, 1 `garis-scan`,
2 `tengah-garis`.

Pil **Titik tengah (1.496)** di kelompok Bidang berhak (tidak tercentang)
menyajikan lapis ini lewat `api/peta-lama.php?lapisan=titik-tengah-bidang-hm`.
Titiknya hijau `#1f7a5c` radius 2,6 tanpa interaksi, senada dengan garis bidang
HM, dan hanya muncul bila pilnya dinyalakan. Metadata lapis tercatat di
`layers.TITIK TENGAH BIDANG HM` dengan blok `titik_tengah_bidang` (aturan,
sebelum/sesudah, jumlah di luar ring).

```
php tools/titik-tengah-bidang-hm.php                      # uji kering, cetak angka
php tools/titik-tengah-bidang-hm.php --keluar=pratinjau.geojson
php tools/titik-tengah-bidang-hm.php --terapkan
```

`--terapkan` menulis `titik-tengah-bidang-hm.geojson` dan memperbarui
metadata, setelah mencadangkan keduanya ke
`_lama/cadangan-titik-tengah-bidang-YYYYMMDD/`. Berkas hasil tidak di-force-add
ke git karena bisa dibuat ulang dari `bidang-hm.geojson` yang sudah terlacak.

---

### 3.103 Jalan 5 sumbu digabung ke lapis Jalan (21 Nov 2026)

Pil **Jalan 5 sumbu** menampilkan draf alat yang sama dengan
`--sumbu=rapi --maks=5 --tutup=40` (`garis-jalan-dari-sumbu-panjang.geojson`,
**52 fitur · 8.628,41 m**, satu cincin tertutup). Draf itu bertumpang tindih
dengan lapis Jalan dan sebagian besar geometrinya sudah ada di sana: **33 dari
52 fitur** utuh tertutup lapis Jalan. Karena itu geometrinya digabung ke
`garis-jalan-dari-sumbu.geojson` lalu pilnya dicabut, bukan dipertahankan
sebagai lapis kedua.

Penggabungan memakai aturan de-duplikasi, bukan salin-tempel:

- Sepanjang tiap baris 5 sumbu disampel tiap 0,5 m; sampel yang jaraknya
  ≤ 0,35 m dari sembarang ruas Jalan dianggap sudah tertutup.
- Yang tersisa ditulis sebagai potongan yang tersusun dari **verteks asli**,
  ditambah dua titik potong di batas tertutup/terbuka (186 verteks untuk
  1.856,28 m). Tidak ada verteks yang digeser atau diratakan.
- Potongan di bawah 2 m dibuang: **46 rentang · 40,65 m**. Dua puluh lima di
  antaranya berukuran 1–2 m; 22 berujung ≤ 0,6 m dari Jalan di kedua sisi —
  salinan tipis akibat sampel di batas, bukan jalan baru — dan 3 sisanya
  berujung 1,0–2,0 m di satu sisi. Ambang ini sejalan dengan rantai mikro yang
  dibuang di §3.101.

Hasil gabungan: **67 potongan · 1.856,28 m** (median 10,50 m, terpendek
2,00 m, terpanjang 165,00 m), sehingga Jalan menjadi **3.911 fitur ·
92.877,55 m** (sebelumnya 3.844 · 91.021,27 m). Kode potongan `TUTUP-0001`
sampai `TUTUP-0067` (unik, tidak ada yang kembar), dengan sifat
`cara: "gabung-5-sumbu"`, `dari` (kode baris 5 sumbu asal), `sumbu`, `sisi`,
`panjang_m`, `jumlah_titik`, dan `label: "JALAN"`.

Pemeriksaan sambungan ujung potongan ke Jalan: median 0,20/0,22 m dan p90
0,50/0,51 m untuk ujung awal/akhir, jadi hampir semuanya menempel. Empat
potongan berujung jauh, semuanya sisa cincin yang memang tidak ada di Jalan:
tiga potongan berujung 2,7–7,4 m dari Jalan dan satu ekor 99,05 m yang
ujungnya 97,95 m dari Jalan
(tepi kanan sumbu `JAHIT-0001` yang tidak masuk jalur semua-sumbu).

Pil **Jalan 5 sumbu** dihapus dari `peta-lama.php` beserta blok hitung
jumlahnya dan cabang gayanya (ungu `#7e22ce`), dan kunci
`garis-jalan-panjang` dicabut dari daftar putih `api/peta-lama.php` (sekarang
menjawab 404). Berkas `garis-jalan-dari-sumbu-panjang.geojson` tidak dihapus,
melainkan dipindahkan bersama salinan lapis Jalan lama ke
`_lama/cadangan-jalan-5sumbu-20261121/` (berstempel `20261121-032007`). Kedua
lapis Jalan tidak punya entri di `metadata.json`, jadi metadata tidak berubah.

Catatan: menjalankan ulang `tools/garis-jalan-dari-sumbu.php --terapkan` akan
menulis ulang lapis Jalan dari sumbu dan **membuang** 67 potongan `TUTUP`;
salurannya tetap ada di cadangan di atas bila perlu digabung lagi.

---

### 3.104 Tepi sungai digabung ke lapis Sungai (21 Nov 2026)

Panel Peta Induk punya dua pil air: **Sungai** (`garis-perairan.geojson`,
**275 baris · 15.013,57 m**) dan **Tepi sungai** (`garis-tepi-sungai.geojson`,
**143 rantai · 15.010,91 m**). Rantai Tepi sungai bukan garis baru: alat
`tools/tepi-sungai.php` hanya menyambung baris-baris Sungai menurut simpul,
tanpa menggeser geometri (§3.101). Karena itu penggabungannya diukur dulu,
bukan disalin-tempel seperti §3.103.

Uji tutup: sepanjang 143 rantai disampel tiap 0,5 m, dan sampel yang jaraknya
≤ 0,35 m dari sembarang ruas Sungai dianggap sudah tertutup. Hasilnya
**30.598 sampel, tertutup 15.010,91 m, tak tertutup 0,00 m** — tidak ada satu
meter pun geometri baru yang bisa ditambahkan. Selisih panjang −2,65 m berasal
dari 21 cincin mikro yang dibuang saat rantai tepi dibentuk; lapis Sungai
masih memuatnya. Dengan begitu lapis Sungai **tetap 275 baris · 15.013,57 m**
dan tidak ada fitur yang ditulis ulang.

Pil **Tepi sungai** dihapus dari `peta-lama.php` beserta cabang gayanya (teal
`#0e7490`), keterangan yang menyebut "126 rantai (12.349,52 m)" di paragraf
petunjuk diganti kalimat penggabungan, dan kunci `garis-tepi-sungai` dicabut
dari daftar putih `api/peta-lama.php` (sekarang menjawab 404). Entri metadata
`GARIS TEPI SUNGAI 020100` dihapus; jejaknya dicatat sebagai blok
`gabung_tepi_sungai` di bawah `GARIS BATAS PERAIRAN 020100` (sumber berkas,
jumlah baris/panjang yang dipensiunkan, dan angka uji tutup). Berkas
`garis-tepi-sungai.geojson` dan salinan metadata sebelum suntingan dipindahkan
ke `_lama/cadangan-gabung-tepi-sungai-20261121/` (berstempel `20261121-034453`).

Alat `tools/tepi-sungai.php` sengaja tidak dihapus: masih bisa dipakai
menurunkan rantai secara luring, tetapi keluarannya tidak lagi disajikan.
Menjalankannya dengan `--terapkan` akan membuat ulang berkas dan entri
metadata itu tanpa pil yang menampilkannya.

Permintaan berikutnya mengganti isi lapis Sungai dengan rantai itu (§3.105).

---

### 3.105 Lapis Sungai disajikan sebagai 143 rantai (21 Nov 2026)

Permintaan singkat "pasang sekarang" (pilihan yang dipakai: rantai menjadi isi
Sungai) menindaklanjuti §3.104. Lapis Sungai tidak lagi menyajikan 275 baris
pecahan, melainkan **143 rantai · 15.010,91 m** — geometri yang sama dengan
bekas lapis Tepi sungai, tidak satu titik pun digeser. Perubahannya hanya
bentuk penyajian: satu garis per tepian, bukan potongan-potongan.

- Sumber rantai: `_lama/cadangan-gabung-tepi-sungai-20261121/garis-tepi-sungai-
  20261121-034453.geojson` (143 fitur).
- Properti per baris dibawa ke rantai: `garis_sumber` (daftar kode baris 275),
  `jumlah_baris`, `label_sumber` (mis. `["SUNGAI"]`, `["KALI SARI"]`),
  `jarak_label_m` (terdekat di antara baris sumber), dan `cara_baris` (hitungan
  cara asal). `kode` tetap `TEPI-SUNGAI-0001` sampai `TEPI-SUNGAI-0143`.
- Panjang dihitung ulang dari koordinat: total 15.010,91 m, terpanjang
  895,15 m, median 56,72 m. Selisih 0,34 m terhadap jumlah `panjang_m` lama
  hanya pembulatan dua desimal per rantai; seluruh 143 rantai berlabel, dan
  tidak ada kode baris sumber yang hilang.
- Berkas 275 baris dan metadata sebelum suntingan dicadangkan ke
  `_lama/cadangan-garis-perairan-275-20261121/` (stempel `20261121-095335`).
- Metadata `GARIS BATAS PERAIRAN 020100` kini `features` 143, `panjang_m`
  15.010,91, `cara` `{"rantai-tepi": 143}`, `asal`
  `{"garis-perairan-275-baris": 143}`, `label` 143; jejaknya di blok
  `pakai_rantai_tepi`. Blok riwayat 275 baris (`dirapikan`, `buang_cincin`,
  `pangkas_gantung`, `lengkapi_label`, `sambung_bidang`, `sambung_tepi`)
  dibiarkan sebagai keterangan bahan yang kini ada di cadangan.
- Pil berubah menjadi **Sungai (143)**; gaya biru `#1d4ed8` tebal 1,7 tidak
  berubah. API `?lapisan=garis-perairan` menjawab 200 dengan 143 fitur
  `LineString` berkode `TEPI-SUNGAI-0001..0143`.

Catatan alat: `tools/lengkapi-garis-perairan.php`, `tools/rapikan-garis-jalan.php
--lapis=perairan`, dan `tools/tepi-sungai.php` membaca berkas ini, jadi
menjalankannya kini memasukkan rantai sebagai bahan. Pelengkapan label akan
menambah baris berskema lama (kode `PERAIRAN-LENGKAP-*`), sehingga berkas bisa
berisi campuran rantai dan baris; kalau perlu kembali ke bentuk 275 baris,
cadangan di atas yang dipakai.

---

### 3.106 Jalan, Selokan, dan Sungai tampil sejak awal (21 Nov 2026)

Perintah "tampilkan layer jalan, sungai dan selokan pada panel peta" dijawab
dengan menjadikan ketiga pil itu aktif secara bawaan. `garis-jalan` memang sudah
`checked`; kini centang bawaan ditambahkan pada `garis-selokan` (689) dan
`garis-perairan` (143). Tidak ada perubahan data, gaya, atau API: warna
`#0f766e` Jalan, `#0ea5e9` Selokan, dan `#1d4ed8` Sungai tetap, ketiga lapis
tetap bisa dimatikan lewat centang.

Catatan di bawah panel diperbarui menjadi "Bidang kadastrum, kedua lapis turunan
Jalan, Selokan, Sungai, calon bidang, titik obyek, dan bidang manual dimuat
lebih dulu; sisanya mati secara bawaan agar peta tetap ringan." Bawaan aktif
kini delapan pil.

Diperiksa di browser pada sesi Super Admin: ketiga kotak tercentang, API
menjawab 200 untuk `garis-jalan`, `garis-selokan`, dan `garis-perairan`, garis
biru kedua lapis air tampak di kanvas bersama garis teal Jalan, konsol tanpa
pesan, dan `php -l peta-lama.php` bersih.

---

### 3.107 Lapis Selokan di Peta Bidang (21 Nov 2026)

Perintah "belum ada layer selokan pada Peta bidang tanah desa" dijawab di dua
berkas: `api/bidang.php` dan `peta.php` (halaman berjudul "Peta bidang tanah
desa"). Sebelumnya halaman itu hanya punya lapis Jalan (garis tepi) dan label
Sungai; garis selokan hanya ada di Peta Induk.

- API mendapat cabang baru `?lapisan=garis-selokan`. Isinya
  `files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-selokan.geojson` —
  689 baris, 50.798,5 m, kelas SALURAN turunan `tools/garis-batas-020100.php`.
  Koordinatnya bermeter lokal DXF, jadi tiap titik diterjemahkan
  `dxfKeWgs84()` seperti `garis-jalan`; tiap fitur membawa `kode` dan
  `panjang_m`. Jawaban: 200, 689 LineString.
- Chip **Selokan** (swatch `#0ea5e9`) diletakkan antara Jalan dan Sungai,
  bawaan aktif. Pilihannya disimpan sebagai `setelan.selokan` di
  localStorage dan preset peta; preset lama tanpa kunci itu otomatis memakai
  bawaan aktif karena `rapikanSetelan()` menambal nilai boolean.
- Garis digambar di pane baru `pane-selokan` (z 414, di atas jalan 412 dan label
  sungai 413) lewat kanvas tersendiri, gaya `#0ea5e9` tebal 1,4 opasitas 0,95.
  Pane itu tidak menerima klik supaya klik bidang tetap mendarat di pane tanah.
  Legenda diberi butir "Garis selokan" yang ikut muncul/hilang bersama chip.

Diperiksa di browser pada sesi Super Admin: chip tercentang, permintaan
`lapisan=garis-selokan` menjawab 200 dengan 689 fitur, `pane-selokan` berisi
satu kanvas dan bergaris biru di atas peta, legenda "Garis selokan" hilang saat
chip dimatikan lalu kembali saat dinyalakan, konsol tanpa pesan. `php -l` bersih
pada kedua berkas PHP dan `node --check` bersih atas JS halaman.

---

### 3.108 Sumbu sungai dijahit jadi 57 polilin lalu dipasang di dua peta (21 Nov 2026)

Dua perintah berurutan — "jahit semua garis sumbu sungai" lalu "pasang lapis
jahit di peta" — dijawab lewat alat baru `tools/jahit-sumbu-sungai.php` dan
empat berkas: dua API, panel Peta Induk, dan metadata lapis.

Bahan jahitan adalah `files/sig-pertanahan-gedong-lokal/data/dxf-layers/
garis-sungai-label.geojson`: 72 rantai sumbu (5.693,2 m) turunan
`tools/garis-sungai-label.php` dari label SUNGAI/KALI/BENGKONG di celah persil.
Diukur dengan klaster simpul 0,35 m, **tidak ada dua rantai yang berbagi
simpul**: 144 ujung semuanya berderajat 1 dan jaringannya 72 komponen. Jahitan
karena itu mustahil hanya dengan menempelkan ujung yang sudah bersimpul;
sebagian celah harus dijembatani garis lurus.

Aturan jahit mengikuti `app/sumbu-rapi.php` dan `tools/jahit-sumbu-blok.php`,
tetapi **baris bahan dijahit utuh** (bukan dipecah per ruas) supaya tidak ada
verteks yang dibuang dan panjang tidak menyusut:

- Ujung berjarak ≤ 0,35 m satu simpul; rantai yang kedua ujungnya menjadi simpul
  sama adalah cincin dan ditulis apa adanya.
- Di simpul derajat 2 kedua rantai selalu disambung; di simpul bercabang
  pasangan paling lurus (lanjutan ≤ 40°) didahulukan dan satu rantai dipakai
  sekali per simpul.
- Dua ujung bebas pada baris berbeda disambung bila arah sambungannya menerus
  (≤ 40°) di kedua ujung dan jaraknya ≤ `--celah` (bawaan 15 m); ujung berjarak
  ≤ `--rapat` (bawaan 1 m) disambung tanpa syarat arah. Pasangan terpendek
  didahulukan, satu ujung sekali.
- Jembatan yang memotong rantai yang disambungnya ditolak.

Ambang 15 m + 1 m itu ambang lapis air
(`tools/rapikan-garis-jalan.php --lapis=perairan --celah=15 --rapat=1`). Ujung
yang lebih jauh biasanya dua alur sungai berbeda, bukan satu alur putus, jadi
dibiarkan dua garis.

Hasil `--terapkan` (21 Nov 12:07): **57 polilin · 5.752,6 m · 3.524 titik**.
Ada **15 jembatan · 59,4 m**, terpanjang 14,3 m (4 di antaranya > 5 m), dan 0
jembatan ditolak karena memotong. Polilin terpanjang 536,7 m menyatukan 4
rantai. Sisa **114 ujung bebas** dibiarkan terpisah: 2 ujung ≤ 2 m, 14 ujung
2–10 m, 32 ujung 10–50 m, 22 ujung > 50 m — tidak ada garis panjang yang
dikarang untuk menutupnya.

Alat memverifikasi dirinya: 3.524 titik keluaran semuanya titik bahan (kunci
koordinat 6 desimal), panjang keluaran sama dengan panjang bahan ditambah
jumlah jembatan (selisih 0,0 m), dan setiap ruas > 5 m adalah jembatan (ruas
bahan terpanjang 4,66 m; hanya 4 jembatan melewati 5 m). Berkas keluaran
`garis-sungai-jahit.geojson` (152.652 bita) **tidak ikut git**; bahan
`garis-sungai-label.geojson` tidak disentuh.

Pemasangan lapis jahit:

- `api/bidang.php?lapisan=garis-sungai` membaca berkas jahit dan jatuh ke berkas
  label bila belum ada; properti `label` yang berupa larik digabung koma pada
  jawaban.
- `api/peta-lama.php` memetakan `garis-sungai` ke `garis-sungai-jahit.geojson`;
  kunci itu tetap di `LAPISAN_BOLEH_KOSONG` sehingga clone tanpa berkas tetap
  menjawab 200 kosong.
- `peta-lama.php` menampilkan pil **Sumbu sungai (57)**, angkanya dari kunci
  metadata baru `GARIS SUMBU SUNGAI JAHIT` (features 57, `panjang_m` 5753,
  blok `ambang` dan `penyambungan`, `derived_from` 72 rantai). Paragraf
  petunjuk menyebut 57 polilin hasil jahit 72 rantai dengan 15 jembatan celah
  59,4 m.
- `peta.php` tidak diubah: chip Sungai memang sudah memanggil
  `lapisan=garis-sungai`, jadi kini menggambar 57 polilin putus-putus
  `#1d4ed8` di kanvas `pane-sungai`.

Pemeriksaan. CLI dengan sesi pengguna aktif: kedua API menjawab 57 fitur ·
3.524 titik; Peta Induk berjumlah 5.752,6 m. Browser sesi Super Admin: pil
"Sumbu sungai (57)" tercentang, piksel `#1e3a8a` di kanvas Peta Induk 841 → 1
saat pil dimatikan → 841 lagi setelah dinyalakan lagi; kanvas `pane-sungai`
Peta Bidang berisi 3.672 piksel tak transparan dengan warna dominan `#1d4ed8`;
konsol tanpa galat. `php -l` bersih pada `tools/jahit-sumbu-sungai.php`,
`api/bidang.php`, `api/peta-lama.php`, dan `peta-lama.php`.

Catatan alat: uji kering adalah bawaan, `--terapkan` yang menulis;
`--celah`/`--rapat` mengubah ambang, `--rinci` mencetak setiap polilin,
`--keluaran` mengganti nama berkas. Menjalankan ulang lalu menulis ke nama yang
sama langsung mengganti berkas yang disajikan kedua peta.

---

### 3.109 Simpul pertemuan menutup 57 polilin jadi 41 (21 Nov 2026)

Keluhan "sumbu sungai masih banyak yang terputus" diukur ulang, bukan langsung
ditambal dengan garis panjang. Tiga temuan:

1. **Ujung berkerumun.** Sesudah 15 jembatan celah, 114 ujung bebas tetap
   berderajat 1. Diagnosa per pasangan ujung (arah keluar, sudut, jarak)
   menunjukkan 30 ujung berjarak 2–15 m satu sama lain, tetapi hampir semua
   pasangan itu **belok > 40°** sehingga aturan celah searah menolaknya. Itu
   pertemuan cabang dan belokan sungai, bukan alur yang lurus terputus.
2. **Bentang tanpa label.** Lubang 80–240 m yang tersisa berisi puluhan ruas
   tepi perairan di dekatnya, tetapi **tidak ada satu pun label sungai** di
   sepanjang bentang itu. Sumbu diturunkan dari label, jadi di situ datanya
   memang berhenti; menjembatani 80 m berarti mengarang ruas melintasi persil
   tanpa dasar.
3. **Pasangan lurus jarak jauh tidak didukung tepi.** Dari 27 pasangan ujung
   ≤ 50 m, 5 lurus (≤ 40°), tetapi 4 di antaranya punya garis penghubung yang
   **tidak berdekatan dengan tepi perairan** (dukungan 0–15%). Hanya satu
   pasangan 16,0 m yang garis penghubungnya 100% berdekatan dengan tepi —
   sedikit di atas ambang celah 15 m, tetapi menaikkan `--celah` ke 16 untuk
   satu pasangan tidak sepadan.

Karena itu yang ditambal hanya kelompok (1), lewat aturan baru **simpul
pertemuan** `--simpul` (bawaan 10 m, 0 mematikan):

- Ujung bebas yang tersisa dikerumunkan dengan single-linkage: dua ujung masuk
  satu kerumunan bila jaraknya ≤ 10 m; rantai kerumunan tidak dibatasi 10 m
  (ujung A–B dan B–C boleh satu kerumunan walau A–C 18 m).
- Tiap kerumunan disatukan dengan pohon rentang minimum, **setiap ruas
  jembatan dibatasi ≤ 10 m** dan tidak boleh menyambung dua ujung dari rantai
  yang sama. Tiga ujung di satu pertemuan tetap terhubung tanpa mengarang titik
  baru: jembatan hanya memakai ujung-ujung bahan.
- Jembatan yang memotong badan salah satu rantai yang disambungnya ditolak.
- Kasus khusus cincin: kerumunan berisi tepat dua ujung dari satu rantai
  panjang (≥ 10× jarak ujungnya) ditutup. Pada data ini satu-satunya kandidat
  (SUNGAI-LABEL-0003, 291,2 m, ujung 4,18 m) justru ditolak karena talikusur
  penutupnya memotong badannya sendiri, jadi tidak ada cincin baru.

Hasil `--terapkan` (21 Nov 12:35): **41 polilin · 5.834,4 m · 3.524 titik**
(sebelumnya 57 polilin · 5.752,6 m). 15 jembatan celah 59,4 m ditambah **16
jembatan simpul 81,8 m**, terpanjang 9,6 m, 1 ditolak memotong. Polilin
terpanjang 640,1 m menyatukan 4 rantai. Sisa **82 ujung bebas** (dari 114): 0
ujung ≤ 2 m, 0 ujung 2–10 m, 27 ujung 10–50 m, 24 ujung > 50 m. Jadi tidak ada
lagi ujung yang berdekatan ≤ 10 m; yang tersisa adalah kelompok (2) dan (3) di
atas.

Verifikasi alat diperbarui: 3.524 titik keluaran semuanya titik bahan, panjang
keluaran = 5.693,25 m bahan + 59,37 m celah + 81,79 m simpul (beda 0,0 m), dan
setiap ruas > 5 m adalah jembatan (10 ruas, 83,4 m). Kunci `metadata.json`
`GARIS SUMBU SUNGAI JAHIT` kini features 41, `panjang_m` 5834, `ambang.
simpul_pertemuan_m` 10, dan `penyambungan` memuat `simpul`/`simpul_m`/
`simpul_terpanjang_m`/`simpul_ditolak_memotong`. Pil Peta Induk menjadi **Sumbu
sungai (41)**; `api/peta-lama.php`, `api/bidang.php`, dan paragraf petunjuk
`peta-lama.php` ikut menyebut 41 polilin, 15 jembatan celah, dan 16 jembatan
simpul.

Berkas 57 polilin dicadangkan di
`_lama/scratch/garis-sungai-jahit-57-bek.geojson`. Berkas tersaji tetap
`garis-sungai-jahit.geojson` dan tetap tidak ikut git.

### 3.110 Tiga celah disusur dari dua tepi perairan (21 Nov 2026)

Sisa 82 ujung bebas §3.109 diuji: bisakah garis tengahnya diturunkan dari
lapis tepi perairan (`garis-perairan.geojson`, 143 rantai) alih-alih dari
label? Jawabannya **tidak secara umum**, tetapi tiga celah bisa.

**Tepi perairan terlalu sepihak untuk jadi sumbu.** Sepanjang 605 titik sampel
jalur sumbu yang ada, hanya 208 (34,4%) punya pasangan tepi kiri-kanan yang
sah (masing-masing ≤ 15 m, sejajar, lebar alur 3–60 m); 45,8% tidak punya tepi
kedua dalam 25 m. Tepi terdekat berjarak median 5,7 m (10% 2,1 m, 90% 17,5 m):
< 2 m 48 sampel, 2–5 m 231, 5–10 m 199, 10–15 m 55, 15–25 m 39, 25–50 m 28,
> 50 m 4. Jadi lapis ini menandai satu sisi alur, bukan dua.

**Celah yang ada.** 82 ujung bebas membentuk 37 pasangan ≤ 150 m (1.674,1 m:
23 pasangan ≤ 50 m = 521,4 m, 14 pasangan 50–150 m = 1.152,7 m) dan 8 ujung
yatim yang ujung lainnya 70–241 m (alur berbeda). Tiap pasangan disampel
lurus: hanya 1 yang lolos (dukungan ≥ 80%, lompatan ≤ 6 m, ujung trek ≤ 12 m).
Sampel lurus menghukum alur yang membelok — pasangan 26 dukungan 100% tetapi
titik tengahnya melompat 7 m, sedangkan pasangan 2 dukungan 29% justru bisa
dilewati. Karena itu penentu akhirnya **penyusur koridor**: langkah 2 m
mengikuti titik tengah pasangan tepi, arah boleh membelok ≤ 40° per langkah,
dikoreksi 60% ke titik tengah, berhasil bila sampai ≤ 3 m dari ujung B tanpa
tersangkut > 3 langkah dan tanpa menjauh > 100 m.

Hasil penyusur: **3 dari 37 pasangan tersusur** — pasangan 2 (celah 11,2 m,
susuran 9,9 m, lebar alur 14,6 m), 12 (23,1 m, 21,2 m, 5,0 m), dan 26 (59,0 m,
59,4 m, 4,5 m). Gagal: 26 pasangan koridor putus di langkah 3 (tidak ada
pasangan tepi), 6 arah berbalik (6, 13, 17, 24, 25, 31), 2 setengah jalan
(11 berhenti di langkah 12, 27 di langkah 15).

**Alat baru** `tools/jahit-sumbu-tepi.php` (uji kering bawaan, `--terapkan`
menulis; `--celah` bawaan 150 m). Jejak susuran dipakai apa adanya, ditambah
satu ruas penutup ≤ 3 m ke ujung B (terpanjang 2,9 m), lalu dua polilin
digabung urut: rantai menuju A, jejak A–B, rantai dari B. Titik sambung yang
berimpit dibuang. Sudut sambung di A dan B wajib ≤ 90° supaya tidak lahir
huruf U; hasilnya 76/36, 39/37, dan 20/40 derajat. Menjalankan alat dua kali
tidak menambah apa-apa karena celah yang sudah tersambung tidak lagi punya
dua ujung bebas.

**Hasil `--terapkan` (21 Nov 12:55): 38 polilin · 5.930,5 m · 3.569 titik**
(dari 41 polilin · 5.834,4 m · 3.524 titik). Susuran 3 konektor 96,1 m
(90,5 m jejak + 5,6 m ruas penutup). Verifikasi: 3.524 titik bahan tetap utuh
dan berurutan di keluarannya, 45 titik baru semuanya titik jejak susuran
(terjauh 8,3 m dari tepi, batas 15 m), panjang = 5.834,4 + 96,1 = 5.930,5 m
tepat (beda 0,0 m), tidak ada ruas karangan lain. Sisa **76 ujung bebas**:
0 ujung ≤ 2 m, 0 ujung 2–10 m, 25 ujung 10–50 m, 23 ujung > 50 m.

**Penyajian.** Berkas tersaji tetap `garis-sungai-jahit.geojson` (tidak ikut
git). Pil Peta Induk menjadi **Sumbu sungai (38)** dari kunci `metadata.json`
`GARIS SUMBU SUNGAI JAHIT` (features 38, `panjang_m` 5930.5, blok baru `tepi`
berisi 3 konektor + `sesudah_tepi`); paragraf petunjuk `peta-lama.php`,
`api/peta-lama.php`, dan `api/bidang.php` ikut menyebut 38 dan tiga celah
tersusur. Diuji browser: kedua API menjawab 200/38 fitur; piksel `#1e3a8a`
Peta Induk 2.094 → 0 saat pil dimatikan → 2.040 saat dinyalakan lagi; kanvas
`pane-sungai` Peta Bidang 1.643 piksel → 0 → 1.708; konsol tanpa galat.

**Cadangan.** Berkas 41 polilin dan `metadata.json` sebelum perubahan ada di
`_lama/cadangan-sumbu-tepi-20261121/`.

**Yang masih terbuka.** 25 celah 10–50 m dan 23 celah > 50 m tidak bisa
ditutup dengan tepi perairan: 34 dari 37 pasangan gagal disusur, dan yang
gagal bukan karena sambungan algoritme, melainkan karena di bentang itu tidak
ada dua tebing yang mengapit alur. Menambalnya tetap berarti mengarang ruas,
atau menurunkan ulang sumbu di bentang yang tidak berlabel.

### 3.111 Sumbu baru dari dua tepi perairan (21 Nov 2026)

Sisa 76 ujung bebas §3.110 tidak bisa ditutup dengan menyusuri koridor dari
ujung ke ujung. Jalan lain yang diukur: menurunkan sumbu di bentang yang
**kedua tepinya sudah ada** tetapi belum pernah ditelusuri karena tidak ada
label. Bahan tepinya `garis-perairan.geojson` (143 rantai, 15.010,9 m).

**Ukuran potensi.** Tiap 5 m sepanjang tiap rantai tepi dicari satu segmen
lain yang sejajar (≤ 25°), berhadapan tegak lurus, selebar 3–20 m: 1.290
sampel punya pasangan; 436 (33,8%) berjarak lebih dari 10 m dari sumbu tersaji;
dipadatkan 10 m tinggal 137 titik (lebar median 8,1 m) di sekitar 20 kerumunan.

**Alat baru** `tools/sumbu-dari-dua-tepi.php` (uji kering bawaan, `--terapkan`
menulis berkas + entri metadata; `--cetak`, `--rinci`, `--tanya=X,Y` untuk
memeriksa). Penyeed: tiap 5 m tepi bank dicari segmen bank lain yang sejajar
≤ 25°, berhadapan (|cos| ≥ 0,7), lebar 3–20 m; titik tengah dua titik nyata itu
dipakai bila tidak jatuh di dalam poligon bidang dan lebih dari 10 m dari sumbu
tersaji. Penyeed dipadatkan satu per sel 10 m (3.155 sampel → 309 mentah → 89
sel; 24 diproses, 65 dilewati karena ≤ 10 m dari rantai). Penelusuran berjalan
2 m per langkah dengan koridor kiri-kanan **dua tepi perairan saja**; lebar
3–20 m, kedua sisi setimbang, titik tengah keluar persil, dan rantai berhenti
bila tidak ada pasangan, bergeser > 3,5 m, masuk persil, atau menyentuh rantai
diterima.

**Dua cacat uji pertama, dan aturannya.** Versi pertama memakai semua tepi
(garis 020100 + tepi persil) sebagai dinding koridor. Hasilnya terlihat wajar
di angka (21 rantai, 1.286,7 m) tetapi gambar perbesaran menunjukkan satu
rantai 226,5 m menyusuri sambungan sawah yang lurus di tengah bidang. Koridor
lalu dibatasi ke dua tepi perairan saja. Versi kedua masih melahirkan rantai
304,2 m yang bolak-balik di antara dua titik berjarak 1,05 m di (262373,
715183): tiap langkah "maju" 2 m, pasangan yang ditemukan tetap dua titik yang
sama. Dua aturan menutupnya: langkah yang kembali ke titik ≤ 0,6 m yang baru
dilalui menghentikan penelusuran (`bolak-balik`), dan rantai yang panjangnya
lebih dari 10× sebaran kotaknya dibuang (`melipat`).

**Hasil `--terapkan` (21 Nov 13:30): 18 rantai · 635,4 m · lebar 3,0–16,3 m**
(rata-rata 7,1 m). Berhenti: bolak-balik 31, tanpa sisi 7, menempel rantai 7,
arah buntu 3; 6 penyeed gagal karena rantai < 5 m. Sebaran jarak ke sumbu lama:
hanya 2 rantai punya sampel ≤ 3 m (5% dan 8%); sebagian besar rantai adalah
alur yang selama ini tidak punya sumbu sama sekali, bukan celah di antara dua
polilin yang ada.

**Jahit ulang.** `tools/jahit-sumbu-sungai.php` mendapat `--masuk=a,b` supaya
bahan label dan dua tepi dapat digabung; `sumber` di metadata berkas kini
menyebut kedua berkas. Bahan 90 rantai 6.328,7 m (72 label 5.693,2 + 18 dua
tepi 635,5) dijahit jadi 49 polilin 6.499,1 m dengan 23 jembatan celah 76,5 m
(terpanjang 14,3) dan 19 jembatan simpul 93,9 m (terpanjang 9,6; 1 ditolak
memotong). Lalu `tools/jahit-sumbu-tepi.php` menyusur 4 dari 40 pasangan celah
(112,5 m celah, 116,8 m susuran: 11,2/9,9, 19,1/18,4, 23,1/21,3, 59,0/59,4 —
lebar 14,6, 11,7, 5,0, 4,5 m), menghasilkan
**45 polilin · 6.615,9 m · 3.925 titik** (dari 38 · 5.930,5 · 3.569).

**Verifikasi.** 3.868 titik bahan tetap utuh dan berurutan; 0 koordinat baru
selain titik bahan dan jejak susuran; panjang = 6.499,1 + 116,8 = 6.615,9 m
tepat (beda 0,0 m); titik jejak terjauh 8,3 m dari tepi (batas 15 m). Uji
kering alat dua tepi dan jahit tepi keduanya idempoten setelah diterapkan
(0 dari 36 pasangan tersisa). Sisa **90 ujung bebas**: 0 ≤ 2 m, 0 2–10 m, 31
10–50 m, 29 > 50 m.

**Penyajian.** `metadata.json` kunci `GARIS SUMBU SUNGAI JAHIT` menjadi
features 45, `panjang_m` 6615.9, dengan blok `dua_tepi` (18 rantai, 635,4 m) di
samping blok `tepi` (4 konektor). Berkas bahan mendapat entri sendiri
`SUMBU SUNGAI DARI DUA TEPI` (18 fitur, 635 m). Pil Peta Induk menjadi
**Sumbu sungai (45)**; paragraf `peta-lama.php`, `api/peta-lama.php`, dan
`api/bidang.php` ikut menyebut 45 polilin, 23+19 jembatan, dan 4 celah
tersusur. Diuji browser: kedua API menjawab 200/45 fitur; piksel `#1e3a8a`
Peta Induk 0 saat pil dimatikan → 785 saat dinyalakan; kanvas `pane-sungai`
Peta Bidang 0 → 1.790 (label sungai 279); konsol tanpa galat.

**Cadangan.** Berkas 38 polilin dan `metadata.json` sebelum perubahan ada di
`_lama/cadangan-sumbu-sungai-20261121-1335/`.

**Batasnya.** Yang lahir hanya alur yang punya dua tebing nyata berseberangan
3–20 m. Dari 40 celah yang masih bisa disusur tepi, 36 gagal karena koridor
putus atau arah berbalik; bentang tanpa tebing kedua tetap tidak bisa diberi
sumbu tanpa mengarang titik.

### 3.112 Sumbu sungai bisa digambar dengan alat Garis manual (21 Nov 2026)

**Lapis keempat.** Alat "Garis manual" di Peta Induk sebelumnya melayani Sumbu
jalan, Garis luar desa, dan Bidang; sekarang menerima `lapis=garis-sungai`
(`api/garis-manual.php`). Garis yang digambar tersimpan di berkas sendiri
`garis-sungai-manual.geojson` dengan kode `SUNGAI-M0001` dan seterusnya, label
bawaan `SUNGAI`. Aksinya sama dengan lapis garis lain: `simpan`, `potong`,
`hapus`, `hapus-terakhir`; minimal dua titik, panjang minimal 1 m, maksimal
2.000 titik per garis.

**Penyajian.** `api/peta-lama.php?lapisan=garis-sungai` dan
`api/bidang.php?lapisan=garis-sungai` menyajikan hasil turunan (45 polilin
`garis-sungai-jahit.geojson`) lalu menambahkan garis manual. Berkas manual
tetap terpisah supaya tidak terhapus saat alat penurunan atau penjahit
dijalankan ulang — jahitan hanya memakai bahan label dan dua-tepi. Lapis yang
berkas turunannya belum ada (clone tanpa data CLI) tetap menjawab 200 dengan
garis manual saja. Angka pil Peta Induk kini dihitung, bukan dari metadata:
`id="jumlahSumbuSungai"` dengan `data-turunan` 45 ditambah jumlah garis
manual, jadi mula-mula (45) lalu (46) sesudah satu garis disimpan. Garis manual digambar dengan gaya yang sama (putus-putus `#1e3a8a`)
dan tetap bisa dipilih dengan klik di dekatnya, lalu dipotong atau dihapus.
Kanvas `pane-sungai` di Peta Bidang ikut menerima garis manual karena chip
Sungai memanggil `api/bidang.php?lapisan=garis-sungai`.

**Uji.** Berkas manual uji satu garis 3 titik diletakkan sementara: kedua API
menjawab 46 fitur, pil 46, `data-tersimpan-sungai` 1; berkas dihapus lagi →
45 fitur, pil 45, dataset 0. Panel: memilih "Sumbu sungai" mengubah petunjuk
menjadi "Klik peta untuk menambah titik pada lapis Sumbu sungai", placeholder
label `SUNGAI`, dan saat alat dimatikan menjadi "Sumbu sungai tersimpan: 1."
Konsol tanpa galat. Basis data tidak disentuh.

### 3.113 26 garis Sumbu sungai manual dijahit ke sumbu asli (21 Nov 2026)

**Bahannya.** Setelah lapis keempat dipasang (§3.112), 26 garis digambar tangan
lewat alat Garis manual: `SUNGAI-M0001` sampai `SUNGAI-M0026`, 2.862,4 m,
paling pendek 11,7 m (M0015) dan paling panjang 413,8 m (M0023, 32 titik).
Garis-garis itu bukan hiasan di atas sumbu lama: dari 52 ujungnya, 33 berjarak
≤0,5 m dan 39 ≤1 m dari segmen `garis-sungai-jahit.geojson`, dan urutan
kodenya berpindah dari satu polilin turunan ke polilin berikutnya — jadi
fungsinya menambal bentang yang belum punya sumbu, bukan menggambar ulang
alur yang sudah ada.

**Jahit ulang dari bahan mentah.** `tools/jahit-sumbu-sungai.php` dijalankan
dengan tiga bahan, bukan dua:
`--masuk=garis-sungai-label,garis-sungai-dua-tepi,garis-sungai-manual
--celah=17 --simpul=10`. Ambang celah 17 m dipilih karena jarak terlebar
antar-ujung manual yang masih satu alur 16,5 m (M0024–M0025); dengan bawaan
15 m M0025 tetap jadi polilin yatim. Hasilnya 30 polilin, 9.454,4 m: 52
jembatan celah 169,5 m (terpanjang 16,4 m) dan 20 jembatan simpul pertemuan
93,5 m. Selanjutnya `tools/jahit-sumbu-tepi.php` menyusur 3 dari 20 celah
lewat koridor dua tepi perairan (celah 101,3 m, susuran 105,5 m), jadi berkas
terbit **27 polilin, 9.559,9 m, 4.115 titik**.

**Verifikasi.** Ke-26 kode `SUNGAI-M` ada di dalam polilin hasil dan tidak ada
satu pun polilin yang hanya berisi garis manual. Uji yang dibawa alat tetap
berlaku: 0 titik keluaran yang bukan titik bahan, 0 koordinat karangan selain
jejak susuran, selisih panjang bahan+jembatan 0,3 m (hanya dari simpul yang
disatukan), dan tidak ada ruas panjang di luar jembatan maupun bahan. Sisa
setelah jahitan: 54 ujung bebas (0 ≤2 m, 0 2–10 m, 16 10–50 m, 23 >50 m).
Ujung terjauh M0025 berjarak 261,8 m dari sumbu mana pun dan dibiarkan bebas —
menjembataninya berarti mengarang alur melintasi persil.

**Dua perbaikan alat.** Pemeriksaan "ruas >5 m hanya boleh jembatan" menganggap
semua bahan beruas pendek, padahal garis manual digambar dengan ruas 10–40 m;
sekarang ruas bahan ikut sah, dicocokkan dengan toleransi simpul 0,35 m karena
penyatuan simpul bisa menggeser ujung rantai. Properti `label` yang berupa
larik (seperti di berkas jahit lama) juga tidak lagi memicu "Array to string
conversion" — jangan memberi berkas hasil jahit sebagai bahan, pakai bahan
mentahnya.

**Penyajian dan retensi.** `garis-sungai-manual.geojson` dihapus setelah
dijahit (cadangan:
`_lama/cadangan-sumbu-sungai-manual-20261121-1414/`), karena isinya sudah
menjadi bagian berkas terbit; membiarkannya akan menggambar garis yang sama
dua kali. Jalur penyajian manual di `api/peta-lama.php` dan `api/bidang.php`
tetap ada supaya garis baru yang digambar sesudah ini langsung tampil.
`metadata.json` kunci `GARIS SUMBU SUNGAI JAHIT` diperbarui: features 27,
`panjang_m` 9.559,9, ambang celah 17, blok `manual` 26 rantai, dan blok `tepi`
3 konektor. Pil Peta Induk "Sumbu sungai (27)", paragraf panel, doc-block
`api/peta-lama.php` dan `api/bidang.php` ikut menyebut 27 polilin.

**Uji hidup.** Kedua API menjawab 200/27 fitur; pil 27 dengan `data-turunan`
27 dan `data-tersimpan-sungai` 0; kanvas `pane-sungai` Peta Bidang 0 → 470
piksel sesudah diperkecil, Peta Induk 2.730 piksel warna `#1e3a8a`; konsol
tanpa galat aplikasi. Basis data tidak disentuh.

---

### 3.114 Garis tepi sungai diturunkan dari 27 sumbu yang sudah terbentuk (21 Nov 2026)

**Pertanyaannya dibalik.** Setelah sumbu sungai menjadi 27 polilin (§3.113),
lapis Sungai masih memuat SEMUA tepi perairan (143 rantai, 15.010,9 m) —
termasuk alur yang tidak punya sumbu — sehingga tidak ada daftar tepi yang
menyertai sumbu. `tools/sumbu-dari-dua-tepi.php` bergerak dari tepi ke sumbu;
alat baru `tools/tepi-sungai-dari-sumbu.php` bergerak dari sumbu ke tepi.

**Bahan dan aturan.** Tepi diambil dari `garis-perairan.geojson` (hasil
`tools/tepi-sungai.php`), bukan dari garis karangan. Tiap 2 m sepanjang sumbu
dicari satu segmen tepi terdekat di kiri dan di kanan yang sejajar (beda arah
≤25°), berjarak 0,4–12 m dari sumbu, dan titik tengah sumbu-tepi TIDAK di
dalam poligon bidang (4.329 cincin, dibaca saja dari tabel `bidang`); tanpa
syarat terakhir, tepi seberang sebidang tanah ikut terklaim di tikungan.
Segmen yang diklaim dipotong menurut klaim yang bersambung di sepanjang sumbu,
jadi baris keluaran memakai verteks rantai tepi asli ditambah dua titik
proyeksi sampel ujungnya: **0 titik karangan, 0 jembatan antarrantai** — tepi
yang putus di mulut anak sungai tetap putus. Potongan < 1 m dibuang.

**Hasil.** `php -d memory_limit=1024M tools/tepi-sungai-dari-sumbu.php
--terapkan` menulis `garis-tepi-sungai-dari-sumbu.geojson`: **142 baris ·
8.366,9 m · 744 titik** (kiri 65 baris/3.965,1 m, kanan 77 baris/4.401,8 m),
dari 581 potongan mikro yang dibuang. **26 dari 27 sumbu** mendapat tepi,
**19 di antaranya bertepi dua sisi**. Cakupan terkuat: JAHIT-0021 96,2%,
JAHIT-0022 91,2%, JAHIT-0006 91,0% (kiri 686,6 m/kanan 725,9 m); sumbu
terpanjang JAHIT-0001 (1.505,7 m) mendapat 964,2 m kiri dan 968,6 m kanan
(64,0% dua sisi setara). Cakupan terlemah: JAHIT-0018 (79,3 m) tanpa tepi sama
sekali — gabungan label 0002 dan garis manual M0019 berjalan di bentang yang
memang tidak punya tepi perairan dalam 12 m; JAHIT-0010 0,7%, JAHIT-0007 3,5%,
JAHIT-0011 hanya sisi kiri (118,5 m), JAHIT-0009 hanya sisi kanan (37,5 m).

**Berkas dan properti.** Satu LineString per baris: `kode` TEPI-SUMBU-0001..
0142, `cara` tepi-dari-sumbu, `sumbu` (kode sumbu asal), `sisi` kiri/kanan,
`panjang_m`, `jarak_rata_m`/`jarak_min_m`/`jarak_maks_m`, `bahan` (kode rantai
tepi asal), dan `sampel`. `metadata.json` mendapat kunci `GARIS TEPI SUNGAI
DARI SUMBU`: features 142, `panjang_m` 8.367, ambang koridor, blok `sumbu`
(27 polilin, bertepi 26, dua sisi 19), dan blok `bahan` (143 rantai). Berkas
lama dicadangkan lebih dulu ke `_lama/cadangan-garis-tepi-sungai-dari-sumbu-<stempel>/`
kalau alat dijalankan ulang.

**Verifikasi.** Tiap titik keluaran diuji terhadap 1.086 segmen tepi: 0 titik
berjarak > 5 mm dari tepi asli, 0 baris yang `panjang_m`-nya beda > 5 cm dari
panjang geometrinya, 142 kode unik, dan titik terjauh dari sumbu asalnya
12,39 m (koridor 12 m ditambah panjang segmen tepi di antara dua sampel).
Basis data tidak ditulis.

**Disajikan.** Sejak 21 Nov 2026 berkas ini punya pil dan kunci API sendiri
(§3.116); isinya kini versi rapi §3.115 (167 baris). Lapis Sungai tetap 143
rantai seperti §3.105.

### 3.115 Tepi sungai dirapikan sejajar sumbu, tanpa cincin (21 Nov 2026)

**Masalahnya.** Turunan §3.114 masih memuat 744,9 m segmen yang menyimpang
>25° dari arah sumbu (195,8 m di antaranya >45°), 6 baris yang verteksnya
berpindah sisi sumbu, dan 3 baris yang posisinya mundur di sepanjang sumbu.
Segmen seperti itu adalah bahan mentah bagi lengkung balik dan cincin.
`tools/rapikan-tepi-sungai.php` memangkas semuanya tanpa memindahkan titik.

**Aturan.** Tiap verteks ditempelkan ke sumbu asalnya (jarak, posisi `s` di
sepanjang sumbu, sisi). Segmen dipertahankan hanya bila kedua ujungnya di sisi
yang diklaim, `s` tidak mundur (toleransi `--geser=0,25` m), beda arah
terhadap arah sumbu di `s` ≤25°, dan jarak 0,4–12 m. Baris dipotong di setiap
segmen yang gagal, tanpa jembatan karangan; potongan <1 m dibuang. Karena
baris tidak boleh memuat `s` yang mundur, cincin tertutup tidak mungkin
terbentuk; pemeriksa internal menolak menulis kalau masih ada cincin, pindah
sisi, silang diri, atau segmen >25°.

**Hasil.** `php -d memory_limit=1024M tools/rapikan-tepi-sungai.php --terapkan`
mengubah 142 baris/8.366,9 m/744 titik menjadi **167 baris · 7.477,0 m · 698
titik** (kiri 79 baris, kanan 88 baris), jadi 889,9 m dibuang dan hanya 1
potongan yang gugur karena <1 m. Baris bertambah karena baris bermasalah
dipecah, bukan digabung. Terpendek 1,0 m, median 30,7 m, terpanjang 394,5 m.

**Verifikasi.** Pemeriksa terpisah: cincin 0, balik arah 0, pindah sisi 0,
silang diri 0, panjang >25° 0,0 m dan >45° 0,0 m. Tiap titik masih berada di
tepi asli (0 titik >5 mm), 0 panjang properti yang beda dari geometri, 167
kode unik TEPI-SUMBU-0001..0167, titik terjauh dari sumbu 11,96 m. Basis data
tidak ditulis.

**Berkas dan properti.** Tiap baris membawa `dari` (kode TEPI-SUMBU asal
§3.114), `dipotong_m`, dan `rapi` {sudut_derajat, min_m}. `metadata.json` pada
kunci `GARIS TEPI SUNGAI DARI SUMBU` kini features 167, `panjang_m` 7.477, dan
blok `rapi` {alat, sudut, min, geser, sebelum 142/8.366,9, sesudah 167/7.477,0,
`dibuang_m` 889,9, periksa semua nol}. Berkas dan metadata lama dicadangkan ke
`_lama/cadangan-garis-tepi-sungai-dari-sumbu-20261121-143810/`. Lapis ini
**disajikan** lewat pil Tepi sungai (§3.116).

### 3.116 Lapis Tepi sungai disajikan di Peta Induk (21 Nov 2026)

**API.** `api/peta-lama.php` mendapat kunci `lapisan=garis-tepi-sungai` yang
melayani `garis-tepi-sungai-dari-sumbu.geojson` (167 LineString). Berkasnya
turunan CLI yang tidak ikut git, jadi kunci ini masuk `LAPISAN_BOLEH_KOSONG`:
kalau berkas belum ada, jawabannya koleksi kosong, bukan 404, dan pil tetap
bisa dicentang. Doc-block dan komentar daftar putih diperbarui.

**Panel.** Di kelompok Garis turunan, tepat sesudah pil Sungai, ada pil
**Tepi sungai (167)** (`data-lapisan="garis-tepi-sungai"`, tercentang bawaan).
Tooltip-nya menyebut layer `GARIS TEPI SUNGAI DARI SUMBU`, alat penurun
`tools/tepi-sungai-dari-sumbu.php`, dan alat rapinya. Gaya gambar: biru
`#3b82f6`, tebal 1,3, garis utuh; legenda mendapat swatch **Tepi sungai** di
antara Garis sungai dan Sumbu sungai. Paragraf panel menyebut angka 167 dan
README §3.114-§3.115.

**Pemeriksaan.** `php -l` bersih pada kedua berkas. Di browser (sesi Peta
Induk): `GET api/peta-lama.php?lapisan=garis-tepi-sungai` menjawab 200 dengan
167 fitur `LineString`, contoh properti `TEPI-SUMBU-0001`/`SUNGAI-JAHIT-0001`
lengkap dengan blok `rapi`; permintaan lapis muncul sekali saat halaman siap
(23 ms) dan info panel "Layer DXF siap"; pil tercentang, 1 kanvas Leaflet, dan
tidak ada galat konsol.

### 3.117 Tepi sungai dirombak: dipasangkan per segmen tepi, 9.367,9 m (22 Nov 2026)

**Keluhan dan ukurannya.** Setelah §3.114-§3.116 lapis Tepi sungai masih dinilai
belum rapi dan banyak tepi belum terbentuk. Pengukuran atas berkas tersaji
(167 baris/7.477,0 m) menunjukkan ia hanya memuat 52,0% bahan tepi (7.810,3 dari
15.010,9 m); 1.820,7 m tepi yang berjarak ≤30 m dari sumbu dan ≤25° masih tak
terklaim, dan barisnya menyimpan 29 belokan >30° (maksimum 60,8°) serta 17 baris
<5 m. Akarnya ada pada cara lama: sumbu disampel tiap 2 m, tepi terdekat
diklaim, lalu ujung potongan ditempeli titik proyeksi sampel. Titik proyeksi itu
tidak berada di verteks tepi, sisa ruas di antara sampel hilang, dan potongan
dapat memanjang melewati ruas tepi yang arahnya menyimpang.

**Cara baru.** `tools/tepi-sungai-dari-sumbu.php` dirombak menjadi pemasangan per
SEGMEN tepi: titik tengah tiap segmen rantai tepi dicarikan sumbu terdekat
(jarak, posisi `s`, arah, sisi), lalu segmen dipakai bila jaraknya 0,4-15 m,
beda arahnya ≤30°, dan sumbu+sisinya tetap dengan segmen sebelumnya. Baris
diputus di setiap segmen yang gagal (termasuk `s` yang berbalik >1 m), jadi
tidak ada jembatan; potongan <1 m dibuang. Semua verteks keluaran adalah verteks
rantai tepi asli — tidak ada titik proyeksi dan tidak ada titik karangan. Uji
titik tengah-di-luar-bidang tetap dipakai (4.329 cincin, dibaca saja); pada data
ini tidak ada segmen yang ditolak olehnya. Baris yang memotong dirinya sendiri
dibelah di titik potong nyata dan lekuk tertutupnya dibuang; pemeriksa akhir
menolak menulis bila masih ada cincin, sisi berbalik, silang diri, atau segmen
>30°.

**Hasil.** `php -d memory_limit=1024M tools/tepi-sungai-dari-sumbu.php
--terapkan`: **123 baris · 9.367,9 m · 769 titik** (kiri 60 baris/4.343,1 m,
kanan 63 baris/5.024,8 m) — 1.890,9 m lebih panjang daripada berkas 167 baris
dan 44 baris lebih sedikit, karena baris kini mengikuti ruas tepi yang utuh.
23 dari 27 sumbu mendapat tepi (18 bertepi dua sisi). Bahan terpakai 9.367,9 m
dari 15.010,9 m (62,4%); di dalam koridor 0,4-15 m ada 10.356,9 m, sisa 989,0 m
(sudut 921,5 · pindah sumbu/sisi 65,6 · potongan pendek/lekuk 1,9). Di luar
koridor 4.459,1 m dan 195,0 m menempel sumbu <0,4 m (sumbu digambar di atas
tepi).

**Verifikasi.** Pemeriksa terpisah (`_lama/scratch/verifikasi-tepi-rantai.php`):
cincin 0, silang diri 0, sisi salah 0, segmen menyimpang >30° 0, jarak maksimum
14,69 m, sumbu tak dikenal 0. Dari 769 titik, hanya 1 bukan verteks rantai tepi
— titik potong lekuk silang 0,2 m di TEPI-SUMBU-0088. Basis data tidak ditulis.
Belokan antarsegmen di dalam baris kini mengikuti tikungan tepi yang nyata:
14 belokan >60° (maksimum 89,5°), semuanya pada ruas panjang 4–36 m di tikungan
alur, bukan spike digitasi. Yang dijaga adalah tiap segmen ≤30° terhadap sumbu
dan tidak ada cincin/silang diri; bentuk tepi tidak dihaluskan supaya tetap
menempel pada garis asli.

**Berkas dan metadata.** Cadangan berkas 167 baris + metadata:
`_lama/cadangan-garis-tepi-sungai-dari-sumbu-20261122-130837/`. Metadata
`GARIS TEPI SUNGAI DARI SUMBU`: features 123, `panjang_m` 9.368, ambang
{jarak 0,4-15 m, sudut 30°, geser 1 m, min 1 m, uji persil}, `bahan`
{terpakai_m 9.367,9, persen 62,4, dalam_koridor_m 10.356,9, rapat_sumbu_m 195,
luar_koridor_m 4.459,1, tolak}, `rapi` semua nol, `permukaan_bidang` 4.329
cincin. Properti baris: `kode`, `cara`, `sumbu`, `sisi`, `panjang_m`,
`jarak_rata_m`/`jarak_min_m`/`jarak_maks_m`, `bahan`, `titik`, dan `segmen`.

**Penyajian.** Pil **Tepi sungai (123)** mengambil jumlah dari metadata, jadi
teksnya berubah sendiri; tooltip dan doc-block API diperbarui (123 baris,
≤30°). Gaya biru `#3b82f6`, tebal 1,3 tidak berubah.
`tools/rapikan-tepi-sungai.php` tidak lagi dipakai dalam rangkaian ini —
keluarannya sudah memenuhi aturan rapi sejak lahir; alat itu tinggal sebagai
catatan §3.115.

**Sisa yang jujur.** 4.459,1 m tepi berada >15 m dari sumbu mana pun: itu alur
yang belum punya sumbu, bukan tepi yang gagal dipasangkan. Menaikkan koridor ke
20 m/35° hanya menambah 222,2 m (129 baris/9.590,1 m) dan mulai mengklaim tepi
alur tetangga, jadi bawaan tetap 15 m/30°. Kalau sisa itu perlu menjadi tepi,
langkahnya menambah sumbu (mis. menurunkan sumbu dari pasangan tepi), bukan
melonggarkan koridor.

### 3.118 Lapis Saluran: garis dan nama saluran di Peta Bidang (22 Nov 2026)

**Permintaan.** "Tambahkan layer saluran pada lapisan peta." Di data desa,
saluran adalah kelas yang sama dengan **Selokan**: `garis-selokan.geojson`
memuat 689 garis yang semuanya berlabel SALURAN (542), IRIGASI (106), SALURAN
IRIGASI (25), atau Saluran (16); tidak ada label SELOKAN tersendiri. Nama
salurannya tersimpan terpisah di layer `BATAS PERAIRAN` (165 titik label:
SALURAN 136, IRIGASI 11, SALURAN IRIGASI 9, Saluran 8, SLURAN 1).

**Yang ditambahkan.** Di panel **Lapisan peta** Peta Bidang (`peta.php`) ada pil
baru **Saluran** sesudah Selokan, tercentang bawaan. Pil ini menggambar ulang
689 garis yang sama dengan warna lebih tua `#0369a1` (tebal 1,7, kanvas sendiri
`pane-saluran` di atas selokan) **beserta 165 nama saluran** dari layer BATAS
PERAIRAN. Pil Selokan tetap ada dan tidak berubah. Nama saluran itu kini tidak
lagi digambar oleh pil Sungai (sebelumnya ikut terpasang di sana meskipun
tersembunyi oleh CSS), jadi tiap nama muncul satu kali; pil Sungai menyisakan
114 label sungai/kali.

**Teknis.** Tidak ada API atau berkas baru: `lapisan=garis-selokan` dan
`lapisan=sungai` dipakai bersama. Satu unduhan `garis-selokan` dan satu unduhan
label perairan dipakai oleh pil Selokan, Saluran, dan Sungai lewat janji
bersama, jadi menyalakan pil Saluran tidak menambah permintaan. Setelan
tersimpan di `localStorage` kunci `sip.peta.pengaturan.v2` (`saluran`), ikut
dalam tombol Atur ulang; legenda mendapat butir **Saluran & nama** dengan
contoh garis `#0369a1`. Label memakai kelas `.label-saluran` (biru `#075985`,
8,5 px, latar putih tipis) dan tampil pada semua tingkat zoom.

**Pemeriksaan.** `php -l peta.php` bersih. Di browser: pil Saluran tercentang,
165 elemen `.label-saluran` dan 114 `.label-sungai` terpasang, kanvas
`pane-saluran` ada di atas `pane-selokan`, legenda memuat "Saluran & nama";
permintaan `garis-selokan` dan `sungai` masing-masing sekali; melepas pil
menghapus semua label saluran dan butir legenda, memasangnya kembali tanpa
permintaan jaringan baru; 0 galat konsol. Peta Induk dan Peta Blok tidak
diubah.

### 3.119 Tepi saluran berpasangan dari tebing nyata 020100 (22 Nov 2026)

**Permintaan.** "Buatkan garis tepi semua saluran." Bahan `garis-selokan.geojson`
hanya memuat satu garis per saluran (689 baris/50.798,5 m) sehingga tidak jelas
apakah garis itu tebing atau sumbu. Karena itu dibuat lapis turunan baru, tanpa
mengubah lapis Saluran yang tersaji.

**Pilihan yang disetujui.** "Tepi nyata berpasangan": tiap garis saluran disalin
apa adanya sebagai tebing acuan; tebing lawan diklaim dari garis nyata
`020100.geojson` yang sejajar di seberang; saluran tanpa pasangan nyata tetap
satu tebing; tidak ada geometri offset karangan. Ukuran pendahulu: 114 dari 689
saluran sudah punya dua baris di `garis-selokan` sendiri, 445 punya garis
sejajar dalam 8 m di 020100, dan 244 tidak punya.

**Alat dan aturan.** `tools/tepi-saluran.php`. Tiap segmen kandidat 020100
diproyeksikan ke garis saluran terdekat lalu diterima bila jaraknya 0,4-4,0 m
(`--lebar`), beda arahnya <= 25 derajat (`--sudut`), posisinya tetap di satu sisi
(kiri/kanan), jarak sepanjang saluran tidak mundur lebih dari 1 m (`--geser`),
dan koridornya tidak menyentuh poligon bidang hidup (tabel `bidang`, status
selain `dihapus`; 4.329 cincin WGS84 dipetakan ke meter DXF). Rentetan per
(saluran, sisi) ditutup pada celah <= 1 m, lekuk silang diri dibuang, potongan
< 1 m dibuang. Semua verteks keluaran adalah verteks asli bahan, bukan titik
proyeksi. Opsi lain: `--lebar-min`, `--min`, `--tanpa-persil`, `--keluar`,
`--rinci`, `--terapkan` (tanpa `--terapkan` hanya uji kering).

**Hasil terpasang.** `files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-tepi-saluran.geojson`
memuat **1.061 fitur = 689 acuan (50.798,5 m) + 372 pasangan (8.253,5 m)**,
total 59.052,0 m. Sebanyak **101 dari 689 saluran berpasangan** (10 di antaranya
dapat pasangan di kedua sisi), 588 tetap satu tebing karena tidak ada garis nyata
lawan dalam 4 m. Ruas pasangan: 146 kiri (3.655,6 m) + 226 kanan (4.597,8 m),
berisi 1.014 titik; lebar koridor median 2,2 m (ukur mandiri per segmen: median
1,9 m, 0,46-4,00 m). Koridor 4 m memuat 11.806,2 m bahan; terpakai 8.253,4 m
(69,9%), sisanya ditolak karena sudut 1.267,1 m, persil 1.305,8 m, dan pindah
sisi 976,4 m. Pemeriksaan rapi: cincin 0, silang diri 0, sisi berbalik 0, segmen
menyimpang 0; 3,5 m potongan pendek dibuang.

**Pemeriksaan.** Skrip bebas `_lama/scratch/verifikasi-tepi-saluran.php`
menyatakan 0 pasangan memakai verteks bukan bahan, 0 jarak di luar 0,4-4,0 m,
0 sudut di atas 25 derajat, 0 sisi salah, 0 cincin/silang, 0 acuan berbeda dari
`garis-selokan`, dan 101 saluran berpasangan. Jendela 160 m di
SELOKAN-020100-0278 dan 140 m di SELOKAN-020100-0472 diperiksa secara visual:
tebing lawan mengikuti garis nyata di seberang saluran, bukan garis karangan.

**Sifat data.** Ruas pasangan berupa potongan (rata-rata 3,68 ruas per saluran
berpasangan), bukan satu garis menerus, karena tebing 020100 terputus di persil;
lapis ini tidak menutup penuh 588 saluran satu tebing. Berkas ini **belum
disajikan** — tidak ada pil di peta dan tidak ada API. Metadata kunci
`GARIS TEPI SALURAN` sudah ditulis di `metadata.json`; lapis Saluran dan
Selokan yang tersaji tidak diubah. Cadangan sebelum menulis:
`_lama/cadangan-garis-tepi-saluran-20261122-133534/`.

### 3.120 Label nama lapisan peta disembunyikan (22 Nov 2026)

**Permintaan.** "Sembunyikan label pada lapisan peta."

**Yang diubah.** Di panel **Lapisan peta** Peta Bidang (`peta.php`) tidak lagi
dibuat penanda nama untuk **Jalan** (626 label layer BATAS JALAN), **Saluran**
(165 nama dari BATAS PERAIRAN), dan **Sungai** (114 nama). Garis dan pilnya tetap
ada. Legenda berubah dari "Saluran & nama" menjadi "Saluran", dan `title` pil
Saluran tidak lagi menyebut 165 nama. `peta.php` juga berhenti meminta
`lapisan=jalan` dan `lapisan=sungai` untuk label, jadi pemuatan peta meminta
berkas lebih sedikit; kedua endpoint tetap ada karena dipakai Peta Blok.

**Teknis.** Tiga loop `L.marker(L.divIcon(...))` di `muatJalanSekali`,
`muatSaluran`, dan `muatSungai` dihapus, begitu pula pembantu `ambilLabelPerairan`
dan pola `POLA_SALURAN`. Gaya `.label-jalan`, `.label-saluran`, `.label-sungai`
beserta aturan `peta-dekat-sungai` (yang tak pernah dipasang) dibuang dari
`assets/css/app.css`. Label sungai sejak sebelumnya memang tak pernah tampil
karena kelas itu tidak dipasang, tetapi penandanya tetap dibuat. Label bidang
dan nama blok tidak diubah; keduanya tetap diatur dari panel Saring peta.

**Pemeriksaan.** `php -l peta.php` bersih. Di browser dengan sesi login dan muat
ulang paksa: 0 elemen `.label-jalan`, `.label-saluran`, dan `.label-sungai`;
legenda memuat "Saluran"; permintaan jaringan hanya `preset`, `garis-jalan`,
`garis-selokan`, dan `batas` (tanpa `lapisan=jalan`/`lapisan=sungai`); 0 galat
atau peringatan konsol. Data peta tidak berubah.

---

### 3.121 Garis saluran dipisahkan dari lapis Jalan (22 Nov 2026)

**Permintaan.** "Pisahkan garis yang membentuk lapisan jalan dengan saluran."

**Temuan.** Lapis **Jalan** di Peta Bidang menyajikan `garis-jalan-tepi.geojson`
(2.014 baris, 72.504,8 m). Sebagian barisnya memakai geometri yang sama dengan
lapis **Saluran** (`garis-selokan.geojson`, 689 fitur, 50.798,5 m):

- 66 baris (4.671,5 m) memakai indeks rantai 020100 yang sama dengan saluran;
  rantai-rantai itu tersebar di 56 fitur saluran;
- 8 baris lagi (482,5 m) bukan indeks yang sama, tetapi geometrinya penggalan
  utuh satu garis saluran (deret verteks sama, arah maju atau balik).

**Pilihan yang disetujui.** "Buang dari Jalan": ruas saluran dibuang dari lapis
Jalan, sedangkan lapis Saluran tidak diubah, sehingga satu garis hanya tampil di
satu lapis.

**Alat.** `tools/pisah-saluran-dari-jalan.php` (uji kering bawaan, `--terapkan`
untuk menulis, `--rinci` untuk daftar baris). Aturan buang: `indeks` termasuk
`indeks_all` saluran, atau geometri baris adalah penggalan utuh garis saluran.
Tiap verteks baris yang dibuang karena indeks wajib ada di verteks saluran; bila
tidak, alat berhenti tanpa menulis. Sebelum menulis, berkas dan `metadata.json`
dicadangkan ke `_lama/cadangan-jalan-tanpa-saluran-<stempel>/`.

**Hasil.** `garis-jalan-tepi.geojson` kini **1.940 baris, 67.350,8 m** (dibuang
74 baris, 5.154,0 m). Lapis Saluran tetap 689 fitur. Entri metadata
`GARIS JALAN TEPI DARI LABEL` mendapat blok `pisah_saluran` (fitur_dibuang 74,
panjang 5.154,0 m, sebab rantai 66/4.671,5 m dan penggalan 8/482,5 m).
Cadangan pangkal 2.014 baris: `_lama/cadangan-jalan-tanpa-saluran-20261122-135737/`.

**Sifat data.** Masih ada 8 baris jalan yang semua verteksnya milik saluran
tetapi geometrinya bukan penggalan utuh (bukan garis yang sama), jadi
dipertahankan. Peta Induk tidak terpengaruh: lapis Jalan di sana menyajikan
`garis-jalan-dari-sumbu.geojson` (3.911 baris).

**Pemeriksaan.** `php -l tools/pisah-saluran-dari-jalan.php` bersih. Hasil berkas:
0 baris dengan indeks saluran, 0 penggalan tersisa; 1.940 baris yang tinggal
verbatim sama dengan berkas pangkal (0 geometri berubah); API
`lapisan=garis-jalan` menjawab 1.940 fitur. Di browser dengan muat ulang paksa:
permintaan `garis-jalan` dan `garis-selokan` masing-masing sekali, 0 galat konsol.

---

### 3.122 Tepi saluran dirapikan: tebing kembar dibuang, potongan dijahit (22 Nov 2026)

**Permintaan.** "Rapikan semua garis tepi saluran."

**Temuan.** Lapis **Tepi saluran** (`garis-tepi-saluran.geojson`, 1.061 baris,
59.052,0 m: 689 acuan + 372 pasangan, §3.119) menyimpan dua jenis kotoran:

- **156 baris pasangan** (3.237,6 m) bergeometri kembar — deret verteksnya sama
  dengan baris lain, maju atau balik, karena satu tebing nyata 020100 diklaim
  oleh lebih dari satu saluran atau lebih dari satu potongan. Selain itu **15
  verteks ganda** (dua titik berurutan sama) tersebar di 11 baris acuan.
- **372 potongan pasangan** tersebar di **111 grup saluran+sisi**, rata-rata
  3,68 ruas per saluran; tebing yang sama terpecah tiap kali 020100 terputus di
  persil.

**Aturan yang dipakai.** Semua keluaran tetap memakai verteks bahan; tidak ada
koordinat karangan.

1. Tebing kembar dibuang di seluruh lapis (kunci deret titik 3 desimal, maju
   atau balik), sehingga satu tebing hanya digambar sekali.
2. Dalam satu grup saluran+sisi, potongan dijahit bila ujungnya berimpit
   (≤ 0,05 m) tanpa syarat arah; bila tidak berimpit, celah ≤ 1 m boleh
   dijembatani asalkan arah ujungnya menerus (≤ 40°) dan ruas jembatan tidak
   memotong ruas lain. Jembatan hanya menyambung ujung bahan, tidak menambah
   titik.
3. Jahitan ditolak bila hasilnya jadi cincin, menyilang diri sendiri, atau
   jembatannya memotong ruas lain. Baris acuan tidak pernah dijahit ke baris
   pasangan, dan acuan tidak pernah dijahit satu sama lain (689 baris acuan
   tetap satu baris per saluran).

**Alat.** `tools/rapikan-tepi-saluran.php` (uji kering bawaan, `--terapkan`
untuk menulis, `--rinci` untuk daftar; ambang `--toleransi=0.05 --celah=1
--belok=40`). Sebelum menulis, berkas dan `metadata.json` dicadangkan ke
`_lama/cadangan-tepi-saluran-rapi-<stempel>/`. Alat berhenti tanpa menulis bila
hasil masih memuat geometri kembar, silang diri, cincin bertambah, atau titik
yang bukan titik bahan, dan bila total panjang tidak seimbang dengan panjang
bahan unik + panjang jembatan.

**Hasil.** `garis-tepi-saluran.geojson` kini **850 baris, 55.815,2 m**:

- 156 baris kembar (3.237,6 m) dibuang; 15 verteks ganda dirapatkan;
- 53 sambungan ujung berimpit + 2 jembatan celah (0,8 m) dijalankan; ditolak
  2 cincin dan 10 silang diri;
- 689 baris acuan (50.798,5 m) + 161 baris pasangan (5.016,7 m); 4.026 titik;
- pasangan per sisi: kiri 57 baris/1.971,9 m, kanan 104 baris/3.044,8 m;
- saluran bertebing lawan 101 → **100**: tebing 8,45 m milik
  SELOKAN-020100-0290 (kiri) dan SELOKAN-020100-0604 (kanan) adalah satu
  dinding yang sama, jadi sesudah dedup hanya digambar sekali dan 0604 menjadi
  satu tebing;
- lebar median 2,47 m; cincin 164 (sama dengan bahan), silang diri 0, kembar 0,
  titik asing 0; kode `TEPI-SALURAN-####` unik dan berurutan.

Metadata `GARIS TEPI SALURAN` diperbarui (features 850, panjang_m 55.815,
acuan/pasangan) dan mendapat blok `dirapikan` (ambang, jumlah kembar, sambungan,
jembatan, baris/p panjang sebelum dan sesudah, stempel terapan). Lapis ini
**tetap belum disajikan** — tidak ada pil atau API. Cadangan sebelum menulis:
`_lama/cadangan-tepi-saluran-rapi-20261122-141309/`.

**Pemeriksaan.** `php -l tools/rapikan-tepi-saluran.php` bersih. Skrip
pemeriksa bebas membandingkan berkas pangkal dan hasil: 850 baris = metadata,
55.815,2 m, `cara` acuan 689/pasangan 161, sisi acuan 689/kiri 57/kanan 104,
kode unik dan berurutan, 4.026 titik, 0 geometri kembar, cincin 164 = 164,
0 titik asing. Ke-689 baris acuan sama deret verteks uniknya dengan pangkal;
selisih yang tampak pada 11 baris hanyalah 15 verteks ganda yang dibuang.

---

### 3.123 Tepi saluran disajikan di Peta Bidang (22 Nov 2026)

**Permintaan.** "Tampilkan pada live lapisan peta."

**Yang diubah.** Panel **Lapisan peta** Peta Bidang (`peta.php`) mendapat pil
**Tepi saluran** (`b` 850, centang bawaan menyala), berwarna biru gelap
`#0c4a6e`, digambar di pane sendiri di atas garis Saluran. Pil ini membaca
lapis yang baru dirapikan (§3.122), bukan berkas saluran. Legenda menampilkan
butir "Tepi saluran" bila pil menyala. Pilihan pil ikut tersimpan di
localStorage dan preset database seperti pil lain; preset lama tanpa kunci
`tepiSaluran` memakai bawaan menyala.

**API.** `api/bidang.php?lapisan=garis-tepi-saluran` menyajikan
`garis-tepi-saluran.geojson` (850 fitur, 55.815,2 m) dengan terjemahan
`dxfKeWgs84()` seperti lapis garis lain. Properti tiap fitur: `kode`, `cara`
(`acuan`/`pasangan`), `sisi`, `saluran`, dan `panjang_m`.

**Sifat data.** Ke-689 baris acuan memakai geometri yang sama dengan garis
Saluran, jadi dengan kedua pil menyala garis itu tampak menebal; 161 baris
pasangan adalah tebing nyata di seberang saluran (57 kiri/104 kanan). Unduhan
dipakai bersama bila dua pemanggil memuat serentak.

**Pemeriksaan.** `php -l peta.php` dan `php -l api/bidang.php` bersih. API
menjawab 200, 850 fitur, koordinat WGS84. Di browser dengan muat ulang paksa:
pil "Tepi saluran 850" ada dan menyala, legenda memuat "Tepi saluran", pane
`pane-tepi-saluran` berkanvas, permintaan `lapisan=garis-tepi-saluran` hanya
sekali, 0 galat konsol. Mematikan lalu menyalakan pil menyimpan `false`/`true`
di localStorage tanpa mengunduh ulang.

### 3.124 Garis Jalan yang menjadi tebing saluran dipindah ke lapis Saluran (22 Nov 2026)

**Permintaan.** "Ambil garis jalan yang terbentuk pada label saluran kedalam
lapisan saluran." Karena "terbentuk pada label saluran" bisa berarti beberapa
himpunan berbeda, diukur dulu tiga calon: baris Jalan terdekat label saluran,
baris yang verteksnya tersarang di garis saluran, dan baris yang segmennya sudah
diklaim tebing lawan. Pilihan yang disetujui: pindahkan baris yang **seluruh**
segmennya sudah menjadi pasangan tebing saluran (`cara=pasangan`, §3.122), bukan
baris sebagian.

**Aturan.** Satu baris Jalan dipindah bila setiap segmennya (dibulatkan 3
desimal, arah maju atau balik) ada pada segmen fitur `cara=pasangan`
`garis-tepi-saluran.geojson`. Geometri disalin verbatim, tanpa titik baru; satu
baris hanya ada di satu lapis. Induk fitur = kode saluran yang menyumbang segmen
terbanyak.

**Hasil.** 33 baris (1.031,9 m) pindah. Jalan 1.940 menjadi 1.907 baris
(66.318,9 m). Lapis Saluran 689 menjadi 722 baris. Ada 17 saluran induk;
terbanyak `SELOKAN-020100-0278` (15 baris). Masih ada 45 baris Jalan yang hanya
**sebagian** segmennya terklaim; itu tetap di Jalan.

**Berkas dan alat.** `tools/pindah-jalan-ke-saluran.php` (uji kering bawaan,
`--terapkan`) menulis `garis-saluran-dari-jalan.geojson` (33 fitur, kode
`SALURAN-JALAN-0001`..`0033`, properti `kode`, `dari_jalan`, `saluran`, `sisi`,
`panjang_m`, `label`, `cara`) lalu mencabut baris yang sama dari
`garis-jalan-tepi.geojson`. Cadangan
`_lama/cadangan-jalan-ke-saluran-20261122-144018/` (Jalan 1.940 baris +
metadata). `metadata.json`: blok Jalan mendapat `ambil_ke_saluran`, blok baru
`GARIS SALURAN DARI JALAN`, dan blok Tepi saluran mendapat catatan
`saluran_tambahan`.

**Mengapa berkas terpisah.** `garis-selokan.geojson` juga dibaca pil **Selokan**;
menambah 33 baris ke berkas itu akan mengubah Selokan juga. Berkas terpisah
dengan gabungan di klien menjaga Selokan tetap 689.

**Penyajian.** `api/bidang.php?lapisan=garis-saluran-dari-jalan` menyajikan 33
fitur WGS84 lewat `dxfKeWgs84()`. `muatSaluran()` di `peta.php` memuat dua berkas
serentak (`Promise.all`) lalu menggambar satu FeatureCollection 722 baris; pil
Saluran berganti judul "722 garis saluran: 689 turunan 020100 + 33 baris Jalan
yang menjadi tebing saluran". Pil Selokan tidak berubah dan tetap 689; tooltip
Tepi saluran menyebut "689 acuan (sama dengan garis selokan 020100)".

**Pemeriksaan.** `php -l api/bidang.php` dan `php -l peta.php` bersih. API: 33
fitur; `garis-selokan` 689, `garis-jalan` 1.907, `garis-tepi-saluran` 850.
Ke-33 geometri salinan byte-identik dengan baris Jalan di cadangan; 0 baris Jalan
yang masih terklaim penuh; kode `SALURAN-JALAN-*` unik. Di browser dengan muat
ulang paksa: kedua permintaan (`garis-selokan` + `garis-saluran-dari-jalan`)
terkirim sekali, pane saluran berkanvas, 0 galat konsol.

### 3.125 Ujung garis yang terputus disambung (22 Nov 2026)

**Permintaan.** "garis-jalan, garis-selokan, garis-saluran-dari-jalan,
garis-tepi-saluran, garis tepi sungai belum rapi masih banyak yang terputus."
Enam lapis diperiksa: Jalan Peta Bidang, Jalan Peta Induk, Selokan,
Saluran dari Jalan, Tepi saluran, dan Tepi sungai. Pilihan keagresifan yang
disetujui: **rapat 2 m + celah 3 m**.

**Sebab terputus.** Sebagian besar ujung bebas berhenti 0,05–2 m di depan
**badan** garis lain (tegak lurus ke segmennya), bukan di ujung garis itu,
sehingga penggabungan simpul biasa tidak melihatnya. Sisanya adalah dua ujung
baris berbeda yang saling berhadapan dengan arah menerus tetapi berselisih
≤3 m. Ujung yang bertemu tegak lurus atau sejajar tidak dihitung celah.

**Aturan.** Semuanya tambahan di ujung, tanpa memindahkan titik lama:

1. ujung bebas ≤2 m dari segmen garis lain ditempel lewat **proyeksi tegak
   lurus** ke segmen itu, bila sudut tempel ≤45 derajat;
2. dua ujung bebas baris berbeda dengan jarak ≤3 m dijembatani garis lurus
   bila arah sambungannya menerus ≤30 derajat di kedua ujung;
3. ujung yang sudah ≤0,05 m dari garis lain dianggap menempel (tidak diubah);
4. sambungan yang membentuk cincin baru atau memotong segmen lain ditolak,
   begitu pula sambungan yang memotong jembatan lain yang sudah diterima.

Karena itu titik baru hanya proyeksi tegak lurus atau ujung baris pasangan,
tidak ada koordinat karangan. Jumlah baris, urutan, dan `kode` tidak berubah;
cincin (pangkal = ujung) tidak disambung. Sambungan dicatat di properti baris
`sambungan_ujung` dan `sambungan_ujung_m`.

**Hasil.**

| lapis | baris | panjang sebelum → sesudah | sambungan | komponen | ujung bebas |
|---|---:|---|---:|---:|---:|
| Jalan Peta Bidang | 1.907 | 66.318,9 → 66.351,1 m (+32,2) | 32 (19 tempel + 13 jembatan) | 469 → 442 | 1.020 → 921 |
| Jalan Peta Induk | 3.911 | 92.877,5 → 93.892,7 m (+1.015,2) | 1.151 | 1.841 → 750 | 6.002 → 1.603 |
| Selokan | 689 | 50.798,5 → 50.804,3 m (+5,8) | 4 tempel | 134 → 130 | 280 → 192 |
| Saluran dari Jalan | 33 | 1.031,9 m (tetap) | 0 | tetap | 38 tetap |
| Tepi saluran | 850 | 55.815,2 → 55.835,2 m (+20,0) | 19 (17 tempel + 2 jembatan) | 181 → 164 | 420 → 304 |
| Tepi sungai | 123 | 9.367,9 → 9.382,1 m (+14,2) | 10 (9 tempel + 1 jembatan) | 102 → 92 | 205 → 185 |

Lapis Jalan Peta Induk dijalankan **dua putaran** sampai titik tetap; angka
1.151 adalah jumlah sambungan kedua putaran, sedangkan panjang dan titik diukur
dari bahan sebelum putaran pertama. Ke-38 ujung Saluran dari Jalan semuanya
bertemu sejajar/tegak lurus (sudut >45 derajat), jadi lapis itu memang tidak
berubah. Sisa ujung bebas pada Jalan Peta Induk masih 1.603; putaran terakhir
menolak 1.599 ujung karena arah, bukan karena jarak — memaksanya akan membuat
tikungan tegak lurus yang bukan koridor jalan.

**Berkas dan alat.** `tools/sambung-ujung-garis.php` (uji kering bawaan,
`--terapkan`; opsi `--lapis=`, `--rapat=`, `--celah=`, `--sudut=`, `--belok=`,
`--toleransi=`, `--rinci`). Cadangan per lapis di
`_lama/cadangan-sambung-ujung-<lapis>-20261122-174410/` (Jalan Peta Induk juga
`...-174428/` untuk putaran kedua). `metadata.json` mendapat blok
`disambung_ujung` pada empat lapis yang punya judul panel (Jalan Peta Bidang,
Selokan, Tepi saluran, Tepi sungai); Jalan Peta Induk tidak punya blok metadata.
Angka `panjang_m` tingkat atas keempat lapis ikut diperbarui, dan blok Tepi
saluran menghitung ulang `panjang_acuan_m` (50.801) serta `panjang_pasangan_m`
(5.034,1). Berkas yang berubah: `garis-jalan-dari-sumbu.geojson` dan
`garis-selokan.geojson` (terlacak git), `metadata.json` (terlacak), serta
`garis-jalan-tepi.geojson`, `garis-tepi-saluran.geojson`, dan
`garis-tepi-sungai-dari-sumbu.geojson` (di luar git). `garis-saluran-dari-jalan.geojson`
tidak disentuh.

**Pemeriksaan.** Putaran ketiga melapor 0 sambungan baru pada keenam lapis
(titik tetap). Tiap lapis memverifikasi jumlah cincin tidak berubah, semua
titik baru cocok dengan titik proyeksi/ujung pasangan, dan selisih panjang sama
dengan panjang sambungan (≤0,05 m). Audit berkas hasil: 1.907/3.911/689/33/850/123
baris, panjang 66.351,1 / 93.892,7 / 50.804,3 / 1.031,9 / 55.835,2 / 9.382,1 m,
`|panjang_m − panjang terukur|` ≤0,005 m, 0 sambungan silang. Titik kembar
berurutan pada Jalan Peta Bidang (39) dan Selokan (15) sudah ada di bahan dan
tidak bertambah. JSON bolak-balik byte-identik, jadi tidak ada perubahan format.
API `api/bidang.php` menjawab 200 untuk `garis-jalan` (1.907),
`garis-selokan` (689), `garis-saluran-dari-jalan` (33), dan
`garis-tepi-saluran` (850); Peta Induk memuat `garis-jalan` dan
`garis-tepi-sungai` 200. Di browser dengan muat ulang paksa kedua peta: 0 galat
konsol, pane lapis tetap ada.

### 3.126 Lapis Sungai (143) dicabut dari Peta Induk (22 Nov 2026)

**Permintaan.** "hapus layer Sungai (143)." Angka 143 adalah jumlah rantai
lapis Sungai yang dipasang Peta Induk; Peta Bidang tidak mencantumkan angka
pada pil Sungai-nya.

**Yang dihapus.** Tiga tempat di Peta Induk dan API-nya:

1. pil `data-lapisan="garis-perairan"` (label "Sungai (143)") beserta
   centang bawaannya di panel Lapisan peta, sehingga jumlah pil 31 → 30;
2. cabang gaya `garis-perairan` (`#1d4ed8`, tebal 1,7) di `gayaLapisan()`
   dan butir legenda "Garis sungai";
3. kunci `garis-perairan` di daftar putih `LAPISAN_PETA_LAMA`, sehingga
   `api/peta-lama.php?lapisan=garis-perairan` menjawab 404 "Lapisan peta lama
   tidak dikenal". Catatan panel dan kepala berkas API diperbarui: lapis itu
   tidak lagi dipasang, bukan berkasnya dihapus.

**Yang tetap.** `garis-perairan.geojson` (143 rantai, 15.010,9 m) tetap di
disk dan tetap bahan turunan Tepi sungai serta Sumbu sungai; alat
`tepi-sungai.php`, `tepi-sungai-dari-sumbu.php`, `sumbu-dari-dua-tepi.php`,
`jahit-sumbu-tepi.php`, dan `sambung-ujung-garis.php` membacanya. Pil "Tepi
sungai" (123) dan "Sumbu sungai" (27) tidak berubah. Peta Bidang (`peta.php`)
tidak disentuh: pil "Sungai" di sana tanpa angka, dan lapisnya disajikan
`api/bidang.php`.

**Pemeriksaan.** `php -l` bersih untuk `peta-lama.php` dan
`api/peta-lama.php`. Panel dirender ulang lewat sesi CLI: 30 pil,
`garis-perairan` tidak ada, sedangkan `garis-sungai` dan
`garis-tepi-sungai` tetap ada. `api/peta-lama.php?lapisan=garis-perairan`
menjawab galat "Lapisan peta lama tidak dikenal" (404), `lapisan=garis-selokan`
tetap 200 berisi FeatureCollection. Halaman dan API tanpa login tetap 302 dan
401 seperti sebelumnya. Sesi browser tidak login saat pemeriksaan, jadi
pemuatan peta di peramban tidak diuji ulang; tidak ada JS yang berubah selain
penghapusan satu cabang gaya dan satu butir legenda.

---

### 3.127 Menu ArcToolbox: katalog alat (22 Nov 2026)

**Permintaan.** "bisakah membuatkan menu ArcToolbox sesuai kebutuhan web ini".
Dua pilihan yang disetujui: halaman Digitasi diubah menjadi hub ArcToolbox
(tanpa halaman baru), dan tahap pertama hanya katalog alat yang sudah ada
(tanpa operasi analisis baru, tanpa tulis basis data).

**Isi halaman.** `digitasi.php` kini membangun katalog dari satu larik PHP,
jadi angka di ringkasan, lencana kelompok, dan daftar isi selalu ikut berubah
bila alat ditambah. Tiga kartu lama (Digitasi Obyek Baru, Pecah Bidang,
Penggabungan Bidang) dan kotak Informasi diganti; ketiga alat itu tetap ada
di kelompok "Digitasi bidang".

| kelompok | web | CLI |
|---|---:|---:|
| Digitasi bidang | 4 | 0 |
| Peta & penanda | 3 | 0 |
| Lapis garis 020100 | 0 | 13 |
| Jahit & rapikan garis | 0 | 8 |
| Bidang & turunannya | 0 | 5 |
| Blok peta | 0 | 2 |
| Impor, konversi & ekspor | 0 | 3 |
| Data & administrasi | 6 | 0 |
| **jumlah** | **13** | **31** |

Alat web ditampilkan sebagai kartu tertaut (Digitasi Obyek Baru, Pecah Bidang,
Penggabungan Bidang, Daftar Bidang, Peta Bidang, Peta Induk, Peta Blok, e-SPPT,
Buku C, Peralihan Hak, Laporan, Pengaturan, Akun). Alat CLI ditampilkan dengan
nama, keterangan, dan satu baris perintah yang diambil dari kepala berkas atau
pembaca argumen alat itu sendiri.

**Lencana peringatan.** Dua alat CLI menulis tanpa mode uji kering dan diberi
lencana merah **Menulis langsung**: `garis-batas-020100.php` (menimpa seluruh
berkas lapis turunan) dan `konversi-dxf-lapisan.php` (menimpa GeoJSON DXF).
Enam alat diberi lencana kuning **Menulis basis data**: `bidang-dari-garis-tertutup.php`,
`bidang-dari-batas-desa.php`, `polygonize-garis-terbuka.php`, `assign-dusun.php`,
`rapikan-garis-blok.php`, dan `impor.php`. Alat lain memakai `--terapkan`
sebagai satu-satunya jalan menulis.

**Tidak didaftarkan.** Skrip sekali pakai dan pustaka tidak masuk katalog:
`buat-geometri-blok*.php`, `convex-hull-cluster*.php`, `cek-geojson-asli.php`,
`fit-transform-dxf.php`, `analisis-geometri-blok14-15.php`,
`detail-geometri-blok14-15.php`, `rapikan-geometri-blok14-15*.php`,
`gabungkan-geometri-blok10.php`, `rapikan-blok10.php`,
`impor-ulang-blok14-15.php`, `impor-geometri-blok.php` (jalur berkas
tertanam), `impor-bidang-hak-pakai.php`, `lib-*.php`, dan `server.php`.
Alat yang sudah digantikan juga tidak didaftarkan: `tepi-sungai.php` dan
`rapikan-tepi-sungai.php`, keduanya digantikan `tepi-sungai-dari-sumbu.php`.
Keduanya tetap ada di `tools/` sebagai riwayat.

**Menu dan tautan.** `app/layout.php` mengganti label menu "Digitasi" menjadi
"ArcToolbox" (berkas dan kunci aktif `digitasi` tidak berubah, jadi sorotan
menu di halaman digitasi tetap bekerja). Tautan kembali di `digitasi-baru.php`,
`pecah-bidang.php`, dan `penggabungan-bidang.php` kini berbunyi "Kembali ke
ArcToolbox".

**Tidak diubah.** Tidak ada operasi analisis baru, tidak ada API baru, tidak
ada penulisan basis data. Gaya memakai palet dan kelas yang sudah ada
(`.kartu`, `.lencana`, `sip_judul`), ditambah kelas berawalan `toolbox-` yang
dilingkup di halaman ini: daftar isi lengket di kiri, kartu per kelompok, baris
alat, dan blok perintah. Di bawah 980 px tata letak menjadi satu kolom.

**Pemeriksaan.** `php -l` bersih untuk `digitasi.php`, `app/layout.php`, dan
tiga halaman digitasi. Halaman dirender lewat sesi CLI: 8 bagian, 44 baris alat
(13 web + 31 CLI), 31 baris perintah, 0 tautan halaman hilang, 0 berkas alat
hilang, semua anchor daftar isi menunjuk id yang ada, dan 0 karakter CJK.

Di peramban dengan sesi login: halaman terbuka sebagai "ArcToolbox", menu
menyorot "ArcToolbox", 6 permintaan aset 200, 0 pesan konsol. Klik daftar isi
"Lapis garis 020100" mendarat tepat 86 px di atas judul bagian (1195 - 86 =
1109), dan tautan kembali di `digitasi-baru.php` berbunyi "Kembali ke
ArcToolbox". Tangkapan layar 1440 px memperlihatkan daftar isi lengket di kiri
dan kartu kelompok di kanan; di 850 px tata letak menjadi satu kolom dengan
daftar isi dua kolom di atas konten.

### 3.128 ArcToolbox: Table of Contents (Layers) (22 Nov 2026)

**Permintaan.** "tambahkan juga menu Table of Contents (TOC) / Layers".
Bagian baru di `digitasi.php` mendaftar lapis yang tampil di tiga peta, dengan
warna legenda, kode lapis DXF, jumlah fitur, dan asal angkanya.

**Isi.** Satu kartu "Table of Contents (Layers)" berisi tiga peta dan 39 baris
lapis (Peta Bidang 9, Peta Induk 26, Peta Blok 4), dikelompokkan seperti panel
lapis masing-masing peta: Bidang kadastrum, Bidang berhak, Garis turunan,
Turunan sumbu jalan, Lainnya, Obyek manual, Belum terpetakan, Label teks.
Navigasi kiri mendapat blok "Table of Contents" di atas daftar kelompok, dengan
tautan ke ketiga peta dan hitungan barisnya. Tiap judul peta punya tautan
"Buka peta".

**Sumber angka.** Angka dihitung saat halaman dibuka, bukan ditulis tetap:

- Basis data: jumlah bidang aktif dan tanah (kueri yang sama dengan Peta
  Bidang), jumlah blok, blok perkiraan, bidang bersumber `garis-manual`, dan
  laporan. Baris Titik laporan hanya muncul bila pengguna berhak mengelola.
- `metadata.json` DXF: `features` dan `types.polygon`/`types.line` per kunci
  lapis (020100, BIDANG HM, HM, CALON BIDANG, label teks, dan lainnya).
- Berkas GeoJSON: `garis-jalan-tepi.geojson` 1.907, `garis-selokan.geojson`
  689, `garis-saluran-dari-jalan.geojson` 33 (Saluran = 689 + 33 = 722),
  `garis-tepi-saluran.geojson` 850, `garis-sungai-jahit.geojson` 27,
  `batas-jalan.geojson` 626, `batas-perairan.geojson` 279, ditambah berkas
  manual sumbu, batas desa, sungai, dan titik obyek bila ada. Berkas yang
  tidak ada ditampilkan sebagai "—", bukan 0.

Nilai yang terbaca 22 Nov: Bidang tanah 4.326, Obyek non-tanah 0, blok 24,
laporan 0; Peta Induk 2.626 poligon dan 13.681 garis 020100, Bidang HM 1.496,
Sumbu jalan 1.828 (1.535 bahan + 293 garis manual), Titik obyek "—" (berkas
penanda belum ada), Bidang manual 2, Calon bidang 737; Peta Blok 24 blok ukur,
0 blok perkiraan, 626 label jalan, 279 label perairan.

**Tata letak.** TOC berada di kolom isi, di atas kartu kelompok, sehingga
daftar navigasi kiri tetap lengket sejak awal. Daftar lapis memakai kolom
`auto-fill` minimal 240 px (empat kolom di 1440 px, satu kolom di ponsel).
Agar baris perintah CLI yang panjang tidak lagi melebarkan halaman, kolom grid
diberi `minmax(0, 1fr)` dan `min-width: 0`; di lebar 500 px `scrollWidth`
turun dari 537 px menjadi 485 px.

**Tidak diubah.** Tidak ada API baru, tidak ada penulisan basis data, dan tidak
ada perubahan pada ketiga halaman peta. Angka TOC mengikuti data hidup; bila
lapis berubah, angkanya ikut berubah tanpa menyunting halaman.

**Pemeriksaan.** `php -l` bersih. Render sesi CLI: 39 baris lapis, 13 subkelompok,
4 anchor TOC (toc-lapisan, toc-bidang, toc-induk, toc-blok), 12 tautan anchor
seluruhnya menunjuk id yang ada, 0 pesan PHP, 0 karakter CJK, 0 id kembar.
Di peramban dengan sesi login, 0 pesan konsol; klik "Peta Induk" mendarat tepat
86 px di atas judulnya (scroll-margin). Tangkapan layar diperiksa di 1440 px,
861 px, dan 500 px (lebar minimum Chrome headless).

### 3.129 ArcToolbox: Katalog berkas workspace (22 Nov 2026)

**Permintaan.** "tambahkan juga panel Katalog: Mengelola folder, file spasial
(SHP, geodatabase), dan metadata langsung dari dalam workspace".

**Tahap.** Panel dibuat sebagai katalog baca-saja. Isi folder, ukuran, waktu
ubah, jumlah fitur, dan metadata dibaca saat halaman dibuka. Aksi yang mengubah
isi disk (unggah, ubah nama, pindah, hapus) belum dibuat karena memerlukan
konfirmasi tersendiri; dengan begitu angka di panel selalu mencerminkan berkas
yang benar-benar ada.

**Isi.** Satu kartu "Katalog (Workspace)" berisi 11 folder dalam tiga kelompok
dan 136 berkas. Ringkasan di atas kartu: 11 folder, 58 berkas spasial, 30 entri
metadata, 209,9 MB, lalu hitungan per jenis: GeoJSON 55, CAD 3, Shapefile 0,
Geodatabase 0, Tabel 7, JSON 56, SQL 15, KML 0. Jenis yang belum ada tetap
ditampilkan sebagai 0 (diredupkan), jadi ketiadaan SHP atau geodatabase terlihat
langsung, bukan disembunyikan.

| kelompok | folder | jalur | berkas | terbaru |
|---|---|---|---:|---|
| Data terbit | Lapis DXF 020100 | `files/sig-pertanahan-gedong-lokal/data/dxf-layers` | 33 (15,3 MB) | 22 Nov 2026 17:44 |
| Data terbit | Peta induk CAD | `files/master peta` | 4 (37,8 MB) | 19 Okt 2026 19:08 |
| Data terbit | Peta gang | `files/peta gang` | 2 (12 KB) | 19 Okt 2026 19:15 |
| Data terbit | Akar workspace | `sigpetades/` | 5 (2,4 MB) | 5 Nov 2026 08:42 |
| Data terbit | Unggahan aplikasi | `files/sig-pertanahan-gedong-lokal/uploads` | 0 (0 B) | — |
| Cadangan & ekspor | Cadangan lapis | `storage/cadangan` | 30 (87,2 MB) | 20 Nov 2026 16:38 |
| Cadangan & ekspor | Ekspor basis data | `storage/ekspor` | 2 (21,0 MB) | 16 Nov 2026 20:31 |
| Cadangan & ekspor | Impor & dump SQL | `storage/impor` | 4 (39,9 MB) | 8 Nov 2026 14:35 |
| Cadangan & ekspor | Catatan alat | `storage` | 51 (5,3 MB) | 20 Nov 2026 16:38 |
| Cadangan & ekspor | Cache turunan | `storage/cache` | 1 (460 KB) | 20 Nov 2026 14:30 |
| Tabel pendukung | Tabel SPPT & pemetaan | `files/data excel` | 4 (602 KB) | 1 Nov 2026 10:10 |

Pemindaian hanya satu tingkat per folder; subfolder didaftarkan sebagai baris
sendiri. `_lama/` (arsip riwayat) tidak dihitung. Berkas non-spasial seperti
PDF dan skrip diabaikan, termasuk 815 MB arsip `files/Data C Desa`.

**Rincian berkas.** Tiap kartu folder punya blok lipat "Lihat N berkas" berisi
nama, kode DXF (bila ada), jumlah fitur, ukuran, dan waktu ubah. Untuk folder
lapis DXF, kode dan jumlah fitur diambil dari `metadata.json`; berkas yang tidak
punya entri ditampilkan "—". Pencocokan nama dinormalkan, jadi kunci `tn_nama`
tetap menemukan `tn-nama.geojson`. Tepat 2 GeoJSON belum punya entri:
`garis-jalan-dari-sumbu.geojson` dan `garis-sumbu-manual.geojson`.

**Metadata.** Blok "Metadata konversi DXF" menampilkan isi `metadata.json`:
sumber `MASTER-dari-dwg.dxf`, koordinat lokal tanpa CRS geografis, rentang
X 261.327,5–264.664,7 dan Y 714.930,0–718.807,8, waktu konversi 31 Okt 2026
17:01, turunan terakhir 22 Nov 2026 17:44, 30 entri lapis, dan 2 GeoJSON tanpa
entri. Peringatan asli ditampilkan utuh (jangan ditafsirkan sebagai EPSG:4326),
dan 22 catatan konversi dapat dibuka dari blok lipat.

**Tata letak.** Navigasi kiri mendapat blok "Katalog" berisi satu tautan
"Katalog berkas" dengan hitungan 136. Kartu folder memakai kolom ringkasan
(berkas, ukuran, waktu ubah), lencana jenis, dan daftar lipat agar halaman tidak
memanjang oleh 136 baris. Baris berkas memakai empat kolom; di bawah 620 px
kolom waktu disembunyikan. Di lebar 500 px `scrollWidth` 485 px, tidak ada
gulir mendatar.

**Tidak diubah.** Tidak ada API baru, tidak ada penulisan basis data, dan tidak
ada berkas data yang diubah. Panel hanya membaca `scandir`, `filesize`,
`filemtime`, dan `metadata.json`. Angka berubah sendiri bila alat menulis lapis
baru; tidak ada daftar berkas yang ditulis tetap di halaman.

**Pemeriksaan.** `php -l` bersih. Render sesi CLI: 11 kartu folder, 136 baris
berkas, 10 blok "Lihat N berkas" ditambah 1 blok 22 catatan, 30 entri metadata,
2 GeoJSON tanpa entri, 0 pesan PHP, 0 karakter CJK. Di peramban dengan sesi
login (lebar 846 px): 0 pesan konsol, 11 kartu, 136 baris, metadata tampil,
tidak ada gulir mendatar; klik tautan "Katalog berkas" mendarat tepat 86 px di
atas judul kartu. Tangkapan layar statis diperiksa di 1440 px, 500 px, dan
daftar 33 berkas lapis DXF dalam keadaan terbuka.

### 3.130 Data View & Layout View di Peta Bidang (22 Nov 2026)

**Permintaan.** "tambahkan juga Data View & Layout View: Beralih antara mode
pengolahan data spasial dan mode perancangan tata letak peta sebelum diekspor".
Alih-alih dua halaman baru, kedua mode dipasang langsung di `peta.php` karena
perancangan tata letak butuh peta hidup dan tombol ekspor; ArcToolbox
(`digitasi.php`) mendapat bagian "Tampilan" yang menjelaskan kedua mode dan
menautkan keduanya.

**Data View.** Mode bawaan, sama seperti sebelumnya: peta interaktif penuh,
pil lapisan, saringan, daftar bidang, dan legenda hidup. Tidak ada perilaku
lama yang diubah.

**Layout View.** Badan halaman mendapat kelas `tampilan-layout`. Pil lapisan,
legenda hidup, panel saringan, daftar bidang, kepala kartu, dan kontrol zoom
disembunyikan; geser dan perbesar peta tetap jalan. Di atas peta muncul lembar
A4 lanskap berbingkai dengan bingkai peta persegi, panah utara di kanan atas,
legenda di kiri bawah, dan blok judul di kanan bawah: "Pemerintah Desa
Gedong" (dibaca dari `setelan()`), "PETA BIDANG TANAH", kecamatan dan
kabupaten, lalu Skala, Tanggal, Sumber, serta jumlah bidang yang terbaca.
Legenda lembar disalin dari legenda hidup setiap kali legenda digambar ulang,
jadi isinya selalu sama dengan Data View. Skala batang bawaan Leaflet tetap
tampil di kiri bawah.

**Skala.** Penyebut dihitung dari `156543.03392 x cos(lintang) / 2^zoom` (meter
per piksel), dikali 3.779,5276 piksel per meter pada 96 dpi, lalu dibulatkan ke
ratusan terdekat dan ditampilkan sebagai "± 1 : N". Skala diperbarui setiap
`moveend`; pada lintang Gedong (sekitar 7° LS) zoom 15 terbaca ± 1 : 17.900 dan
zoom 18 ± 1 : 2.200.

**Beralih.** Dua tombol `#modeData` dan `#modeLayout` (dengan `aria-pressed`)
berada di bilah "Tampilan" di atas peta, disertai kalimat keterangan mode aktif.
Mode juga bisa dibuka lewat alamat `peta.php?tampilan=layout`, sehingga tautan
dari ArcToolbox langsung mendarat di Layout View. Mode tidak disimpan ke
`localStorage`: kunci `sip.peta.pengaturan.v2` tetap hanya menyimpan titik
pandang peta, agar membuka halaman selalu mulai dari Data View.

**Ekspor.** Tombol "Ekspor PDF" hanya tampil di Layout View dan memanggil dialog
cetak peramban. Gaya cetak memakai `@page { size: A4 landscape; margin: 9mm }`,
peta setinggi 188 mm, dan hanya kartu peta yang tercetak (bilah atas, judul,
sidebar, pil, legenda hidup, dan tombol mode disembunyikan). Pengguna memilih
"Simpan sebagai PDF" di dialog cetak; tidak ada pustaka ekspor tambahan.

**Responsif.** Di bawah 620 px keterangan mode disembunyikan, tinggi peta
dalam Layout View dinaikkan ke 560 px, legenda lembar menjadi satu kolom di
kiri atas (lebar maksimal 62 % atau 300 px, tinggi maksimal 44 %), blok judul
di kanan bawah (lebar maksimal 70 % atau 320 px), dan panah utara pindah ke
kanan atas agar tidak menutupi peta.

**Tidak diubah.** Tidak ada API baru, tidak ada penulisan basis data, dan
tidak ada berkas data yang diubah. Ekspor hanya membaca tampilan yang sudah ada
di peramban. `peta.php` menjadi 1.405 baris (+97/−1), `digitasi.php` 1.192
baris (+77), dan `assets/css/app.css` 813 baris (+109).

**Pemeriksaan.** `php -l` bersih. Skrip sebaris 47.764 B diperiksa dengan
`--check` (Electron sebagai Node) keluar 0. Di peramban dengan sesi login:
0 pesan konsol, kedua tombol berganti dua arah, legenda lembar tersalin (3
entri pada sesi uji), tombol ekspor hanya muncul di Layout View, skala berubah
saat peta digeser, dan tidak ada gulir mendatar (846/861 px dan 485/500 px).
PDF dari Chrome headless: 1 halaman, MediaBox 841,92 x 594,96 pt (A4 lanskap),
UI tersembunyi, legenda/judul/panah utara/skala/atribusi tampil. ArcToolbox:
16 id tanpa kembar, 14 anchor menunjuk id yang ada, 0 karakter CJK pada ketiga
berkas.

### 3.131 ArcToolbox: Measure, alat ukur jarak & luas (22 Nov 2026)

**Permintaan.** "lanjut README §3.131", melanjutkan seri ArcToolbox sesudah
Table of Contents, Katalog, dan Data View/Layout View. Alat ArcMap yang belum
ada di web ini adalah **Measure**: klik bidang sudah membuka popup rincian
(identify), tampilan sudah bisa disimpan sebagai preset (bookmark), tetapi
belum ada cara mengukur jarak dan luas tanpa perangkat GIS.

**Isi peta.** Bilah Tampilan di `peta.php` mendapat tombol **Ukur** (dengan
`aria-pressed`). Saat dinyalakan, panel oranye muncul berisi pilihan
**Jarak**/**Luas**, kalimat hasil, tombol **Hapus titik**, dan **Tutup**.
Klik peta menambah titik: titik digambar lingkaran putih bergaris oranye,
ruas sebagai garis putus-putus, dan pada mode Luas poligon tertutup diisi
oranye tipis. Escape menutup alat. Panel dan tombol Ukur disembunyikan di
Layout View, dan berpindah ke Layout View otomatis menutup alat.

**Hitungan.** Jarak tiap ruas memakai rumus bola Leaflet (`peta.distance`),
lalu dijumlahkan; hasil di bawah 1 km ditulis dalam meter satu desimal,
di atasnya dalam kilometer dua desimal. Luas memakai kelebihan bola
(Chamberlain-Duquette) dengan jari-jari 6.371.008,8 m, sehingga persil
ratusan meter tidak melengkung seperti proyeksi bidang datar; hasil ditulis
dalam m² dan hektare, disertai keliling (termasuk ruas penutup). Format angka
memakai `Sip.angka` (pemisah desimal koma). Pada sesi uji tiga titik
menghasilkan jarak 212,1 m untuk dua ruas, dan mode Luas menghasilkan
5.536 m² (0,55 ha) dengan keliling 355,1 m.

**Selama mengukur.** Popup bidang dimatikan lewat CSS
(`#wadahPeta.mode-ukur #peta .leaflet-interactive` dan
`.leaflet-marker-icon` ber-`pointer-events: none`), jadi klik tidak membuka
popup atau memindahkan peta ke bidang; klik ganda untuk zoom dimatikan
sementara dan dinyalakan lagi saat alat ditutup. Geser dan perbesar peta
tetap jalan, karena hanya penanganan klik pada objek yang dimatikan. Titik
ukur tidak disimpan: menutup alat mengosongkan ukuran.

**ArcToolbox.** Navigasi kiri mendapat blok "Peta" berisi "Alat ukur" dengan
hitungan 2 (Jarak dan Luas), dan bagian baru `#alat-ukur-peta` berisi dua
kartu: "Garis berantai" dan "Poligon tertutup", ditambah catatan bahwa
tombol Ukur hanya aktif di Data View. Tautan "Buka Peta Bidang" mengarah ke
`peta.php`.

**Tidak diubah.** Tidak ada API baru, tidak ada penulisan basis data, dan
tidak ada berkas data yang diubah. `peta.php` menjadi 1.542 baris (+137),
`digitasi.php` 1.245 baris (+53), dan `assets/css/app.css` 844 baris (+31).

**Pemeriksaan.** `php -l` bersih. Skrip sebaris 53.108 B diperiksa dengan
`--check` (Electron sebagai Node) keluar 0. Di peramban dengan sesi login
(lebar 846 px): tombol Ukur menyalakan panel, tiga klik peta menghasilkan
"Jarak 212,1 m · 3 titik (2 ruas)" lalu "Luas 5.536 m² (0,55 ha) · keliling
355,1 m · 3 titik", 0 popup terbuka, 0 pesan konsol, dan tanpa gulir
mendatar. Hapus titik mengembalikan kalimat petunjuk; Escape menutup panel
dan melepas `aria-pressed`; di Layout View tombol Ukur ber-`display: none`.
Tangkapan layar statis 500 px: `scrollWidth` 485 px, panel ukur melipat
tombol di baris pertama dan hasil selebar baris di bawahnya. ArcToolbox: 17
id tanpa kembar, 15 anchor menunjuk id yang ada, 0 karakter CJK pada ketiga
berkas.

### 3.132 ArcToolbox: Go To XY, menuju koordinat (22 Nov 2026)

**Permintaan.** "lanjut README §3.132", melanjutkan seri ArcToolbox sesudah
Table of Contents, Katalog, Data View/Layout View, dan Measure. Alat ArcMap
yang belum ada sekarang adalah **Go To XY**: peta bisa digeser dan diperbesar,
bidang bisa dicari lewat nama, tetapi belum ada cara langsung menuju posisi
lintang/bujur ketika koordinatnya sudah diketahui dari luar aplikasi.

**Isi peta.** Bilah Tampilan di `peta.php` mendapat tombol **Go To XY**
(dengan `aria-pressed`) di samping tombol Ukur. Saat dinyalakan, panel biru
muda muncul berisi isian **Lintang** dan **Bujur**, tombol **Menuju**,
**Tutup**, kalimat pesan `aria-live`, dan baris **Pusat peta** yang membaca
koordinat tengah peta. Kedua isian menerima koma maupun titik sebagai
pemisah desimal, dan Enter di dalam isian sama dengan menekan Menuju. Escape
atau Tutup menyembunyikan panel; penanda tujuan tetap tampil sampai halaman
dimuat ulang. Panel dan tombolnya disembunyikan di Layout View, dan
berpindah ke Layout View otomatis menutup panel.

**Validasi.** Isian kosong dijawab "Isi lintang dan bujur lebih dahulu.";
lintang bukan angka atau di luar -90 sampai 90 dijawab "Lintang harus angka
antara -90 dan 90."; bujur di luar -180 sampai 180 dijawab "Bujur harus angka
antara -180 dan 180.". Masukan yang sah memusatkan peta dengan `setView` dan
zoom paling sedikit 17, memasang penanda lingkaran merah berjari-jari 7
dengan popup "Tujuan: <lintang>, <bujur>" (lima desimal), lalu menulis
"Menuju <lintang>, <bujur>.". Baris Pusat peta diperbarui pada setiap
`moveend`, jadi koordinat terbaca ulang sesudah digeser atau diperbesar.
Membuka alat ukur menutup panel koordinat dan sebaliknya, supaya klik peta
tidak berebut fungsi; keduanya memakai format angka `Sip.angka` (desimal
koma).

**ArcToolbox.** Navigasi kiri blok "Peta" mendapat butir kedua "Menuju
koordinat" dengan hitungan 1, dan bagian baru `#menuju-koordinat` berisi satu
kartu "Langsung ke derajat desimal": isian lintang/bujur, batas nilai,
perilaku penanda, dan catatan bahwa tombol hanya tampil di Data View. Tautan
"Buka Peta Bidang" mengarah ke `peta.php`.

**Tidak diubah.** Tidak ada API baru, tidak ada penulisan basis data, dan
tidak ada berkas data yang diubah. `peta.php` menjadi 1.643 baris (+105/-2),
`digitasi.php` 1.286 baris (+41), dan `assets/css/app.css` 878 baris (+34).

**Pemeriksaan.** `php -l` bersih. Blok JS `peta.php` (1.310 baris, 56.721 B)
diperiksa dengan `--check` (Electron sebagai Node) keluar 0. Uji perilaku di
Chrome headless atas hasil render CLI tanpa sesi login, lebar 1.280 px: panel
terbuka, tiga pesan kesalahan muncul berurutan untuk isian kosong, lintang
"abc", dan bujur "200"; masukan "-7,0432" dan "110,0631" menghasilkan
"Menuju -7,04320, 110,06310." dengan Pusat peta "-7,04320, 110,06310",
`aria-pressed` menjadi true, dan 0 galat skrip. Menyalakan Ukur menyembunyikan
panel koordinat, dan sebaliknya. Di Layout View tombol dan panel
ber-`display: none`; kembali ke Data View tombol tampil lagi. Tanpa gulir
mendatar: `scrollWidth` 1.249 px pada jendela 1.264 px, dan 485 px pada
jendela 500 px (tangkapan layar memperlihatkan isian melipat dua baris dan
pesan selebar panel). Pemeriksaan yang sama di peramban dengan sesi login
(lebar 861 px) memberi hasil identik: "Menuju -7,04320, 110,06310.", Pusat
peta terbaca, tombol dan panel ber-`display: none` di Layout View, 0 pesan
konsol, tanpa gulir mendatar (`scrollWidth` 846 px). ArcToolbox: 16 anchor
navigasi, 0 menunjuk id yang hilang, 0 id kembar, 0 karakter CJK pada keempat
berkas.

### 3.133 ArcToolbox: navigasi peta, seleksi & tabel atribut (22 Nov 2026)

**Permintaan.** Daftar kemampuan ArcMap yang belum ada di web ini: Add Data
(SHP, raster/citra, basemap, CSV XY), Navigasi Peta (Zoom In, Zoom Out, Pan,
Full Extent, Previous/Next Extent), dan Seleksi & Tabel Atribut (memilih fitur
di peta, Select by Attribute, Attribute Table). Setelah ditimbang, yang
dikerjakan lebih dulu **navigasi dan atribut**; Add Data ditunda karena SHP
dan raster membutuhkan pustaka baru dan basemap sudah punya pemilih sendiri.

**Isi peta.** Bilah **Navigasi** muncul di bawah bilah Tampilan pada
`peta.php`, berisi delapan tombol: Zoom In, Zoom Out, Pan, Full Extent,
Sebelum, Sesudah, Pilih, dan Tabel atribut. Tombol memakai bentuk pil yang
sama dengan bilah Tampilan; Pan menyala secara bawaan.

**Riwayat pandangan.** Setiap `moveend` mencatat pusat dan tingkat zoom, jadi
zoom, geser, pemusatan ke bidang, dan Full Extent semuanya masuk riwayat.
Catatan yang sama tidak ditulis dua kali, dan melangkah dengan Sebelum/Sesudah
tidak menambah catatan baru. Riwayat menyimpan 60 pandangan terakhir; setelah
melangkah mundur, pandangan baru memotong cabang maju. Tombol nonaktif bila
tidak ada langkah tersisa.

Pemuatan pertama tidak dihitung sebagai langkah: pemusatan otomatis ke seluruh
bidang menjadikan pandangannya titik awal, bukan tujuan tombol Sebelum. Bila
pandangan tersimpan sudah ada, peta dipasang tanpa animasi supaya pandangan itu
tercatat sekali, bukan dua kali (pusat lama lalu pusat baru).

**Full Extent.** Batas dihitung dari geometri yang sedang tampil. Bila bidang
belum termuat, batas blok dan lapis garis yang aktif dipakai sebagai cadangan;
bila tidak ada apa pun, peta kembali ke pusat bawaan desa. Perpindahan memakai
animasi halus tanpa memuat ulang data.

**Pan.** Tombol Pan menyalakan atau mematikan penarikan peta
(`dragging.enable()`/`disable()`). Saat dimatikan, kursor peta menjadi panah
biasa dan klik bidang tidak berpindah pandangan. Zoom dan tombol navigasi lain
tetap bekerja.

**Seleksi.** Mode **Pilih** mengubah klik bidang menjadi menandai atau
melepas pilihan: garis merah tebal, isi merah muda, dan bidang diangkat ke
depan. Selama mode ini menyala, popup bidang tidak dibuka. Jumlah terpilih
tampil pada tombol Pilih. Seleksi hanya penyorotan di peramban; mengganti
saringan memuat ulang bidang dan membersihkan pilihan.

**Tabel atribut.** Tombol **Tabel atribut** membuka kartu baru berisi kolom
Pilih, Kode, Blok, Hak, Pemegang, Luas (m²), dan Status untuk bidang pada
saringan aktif. Maksimal 500 baris pertama ditampilkan dan catatan kaki
menyebutkan sisanya. Klik baris memusatkan peta ke bidang itu (paling jauh
tingkat 19 karena ubin OSM berhenti di sana); centang memakai jalur seleksi
yang sama dengan klik peta. **Pilih semua baris** memilih seluruh baris yang
tampil, **Bersihkan pilihan** melepasnya, dan **Tutup** menyembunyikan kartu.
Tabel tidak dapat disunting; isinya mengikuti Saring peta yang sudah ada.

**ArcToolbox.** Navigasi kiri mendapat dua butir di kelompok Peta: "Navigasi
peta" berisi 5 (Zoom In, Zoom Out, Pan, Full Extent, Previous/Next Extent) dan
"Seleksi & tabel atribut" berisi 2. Dua bagian baru: `#navigasi-peta` dengan
empat kartu dan `#seleksi-atribut` dengan dua kartu. Catatan bagian menyebut
bahwa Select by Attribute sepadan dengan kartu Saring peta, dan Add Data untuk
SHP, raster, atau basemap baru belum tersedia.

**Tidak diubah.** Tidak ada API baru, tidak ada penulisan basis data, dan
tidak ada berkas data yang diubah. `peta.php` menjadi 1.941 baris (+298),
`digitasi.php` 1.410 baris (+124), dan `assets/css/app.css` 916 baris (+38).

**Pemeriksaan.** `php -l` bersih pada kedua halaman. Blok JS `peta.php`
(1.527 baris) lolos `--check`. Di peramban dengan sesi login (lebar 861 px):
8 tombol navigasi, Sebelum dan Sesudah nonaktif saat halaman selesai dimuat;
Zoom Out mengubah skala 1 : 35.800 menjadi 1 : 71.700, Sebelum kembali ke
35.800, Sesudah maju lagi ke 71.700; Full Extent memindahkan pusat peta;
Pan melepas dan memasang `tanpa-geser`; klik bidang dalam mode Pilih
menghasilkan "Pilih · 1", 0 popup; tabel menampilkan 4.326 bidang dan 500
baris pertama; centang, Pilih semua (500), dan Bersihkan bekerja; saringan
POLINDES menyusutkan tabel menjadi 1 bidang/1 baris dan membersihkan pilihan;
klik baris memindahkan pusat dari -7,08193, 110,07027 ke -7,08654, 110,07504.
Di Layout View bilah Navigasi dan kartu atribut ber-`display: none`; kembali ke
Data View bilah tampil lagi. 0 pesan konsol setelah pemusatan dibatasi tingkat
19 (sebelumnya ubin z20 menjawab 400). Tanpa gulir mendatar: `scrollWidth`
846 px pada jendela 861 px, dan 485 px pada jendela 500 px (tangkapan layar
memperlihatkan bilah Navigasi melipat dua baris dan tabel bergulir sendiri
tanpa menimpa catatan kaki). Pemeriksaan statis 500 px: 0 galat skrip.
ArcToolbox: 18 anchor navigasi, 0 menunjuk id yang hilang, 20 id unik,
0 karakter CJK pada ketiga berkas.

---

### 3.134 Analisis spasial dasar di Peta Induk: Clip, Buffer, Intersect, Union, Dissolve (23 Nov 2026)

**Permintaan.** Panel peta lama ditambah **Analisis Spasial Dasar** ala
ArcToolbox: Clip (memotong lapis input dengan batas poligon lapis lain,
misalnya jalan atau sungai menurut batas administrasi), Buffer (zona
penyangga jarak tertentu di sekitar titik, garis, atau poligon), Intersect &
Union (mengiris atau menggabungkan fitur tumpang tindih antar-lapis beserta
atributnya), dan Dissolve (menyatukan fitur bernilai atribut sama).

**Pendekatan.** Alat dipasang di kartu **Analisis spasial** pada
`peta-lama.php` (Peta Induk, CRS.Simple). Hitungan berjalan seluruhnya di
peramban memakai **Turf.js 7.2.0** yang disalin ke
`assets/js/turf.min.js` (563.590 B, lisensi MIT, komentar sumber
dipertahankan). Lapis diambil dari `api/peta-lama.php` yang sudah ada lalu
disimpan di cache per kunci selama halaman terbuka; tidak ada API baru,
tidak ada penulisan basis data, dan tidak ada berkas data yang diubah.

Turf menghitung buffer di atas bola bumi, sedangkan koordinat DXF berupa
meter lokal (x sekitar 263.000). Karena itu tiap himpunan fitur digeser ke
titik pusat lalu diskalakan `1/111320` sebelum masuk Turf, dan hasilnya
dikembalikan ke meter DXF. Tanpa penggeseran lokal, dissolve zona buffer
gagal menyelesaikan cincin keluaran; dengan penggeseran, hasilnya utuh.
Uji luas buffer titik 10 m menghasilkan nisbah 0,9999 terhadap luas
lingkaran.

**Clip.** Lapis input boleh titik, garis, atau poligon; lapis pemotong wajib
punya poligon. Pasangan dicari lewat kotak batas dulu. Poligon yang seluruh
verteks luarnya berada di dalam pemotong disalin apa adanya; poligon yang
terpotong diiris, garis dipotong dengan `lineSplit` (dengan cadangan uji
titik tengah bila garis seluruhnya di dalam), dan titik disaring dengan
`booleanPointInPolygon`. Atribut fitur input dibawa ke hasil.

**Buffer.** Zona dibuat per fitur dengan jarak 0,1 sampai 5.000 meter
(`steps: 8`). Opsi **Gabung zona** menjalankan dissolve atas seluruh zona;
bila gabungan gagal karena tumpangan terlalu rapat, hasil per fitur tetap
ditampilkan disertai catatan.

**Intersect & Union.** Hanya untuk lapis poligon. Pasangan dari kotak batas
dihitung dulu; lebih dari 20.000 pasangan ditolak dengan pesan agar lapis
diperkecil. Intersect mengambil irisan tiap pasangan; Union menambahkan
selisih A−B dan B−A serta menyalin fitur tanpa tumpangan. Atribut kedua
lapis digabung, dan nama yang bertabrakan diberi awalan `B_`.

**Dissolve.** Pilihan atribut dibaca dari 200 fitur pertama lapis terpilih,
dengan `dxf_handle` dikecualikan; pilihan pertama `(seluruh fitur)`
menggabungkan semua. Keluaran Turf memecah MultiPolygon menjadi Polygon,
sehingga hasil berupa N bagian; bagian yang menutupi wilayah terpisah
memang tidak bisa disatukan menjadi satu poligon.

**Hasil.** Seluruh alat menampilkan hasil sebagai lapis ungu sementara
(legenda **Hasil analisis spasial**), memusatkan peta ke batas hasil,
mengisi jumlah fitur, luas, dan panjang, serta mengaktifkan **Unduh
GeoJSON** (nama `analisis-<alat>-YYYYMMDD.geojson`, `crs: LOCAL:DXF`) dan
**Bersihkan hasil**. Hasil hilang saat halaman dimuat ulang. Tombol Jalankan
dinonaktifkan selama hitungan berjalan dan teks kemajuan diperbarui tiap
100–200 fitur/pasangan.

**ArcToolbox.** Navigasi kiri mendapat kelompok **Analisis** berisi satu
butir "Analisis spasial dasar" dengan jumlah 4, dan `digitasi.php` mendapat
bagian `#analisis-spasial` berisi empat kartu (Clip, Buffer, Intersect &
Union, Dissolve) dengan tautan "Buka Peta Induk". Tautan itu menuju
`peta-lama.php`, bukan `peta.php`, karena alatnya hidup di Peta Induk.

**Perbaikan saat uji.** Daftar atribut Dissolve semula terisi dua kali bila
lapis diganti cepat karena dua pemuatan berjalan bersamaan; pemuatan kini
membuang hasil lama dan hanya menulis bila lapisnya belum berganti.

**Pemeriksaan.** `php -l` bersih pada kedua halaman. Blok JS `peta-lama.php`
(2.420 baris) lolos `--check` dan memuat `window.turf` sebelum IIFE.
Di peramban (lebar 845 px, salinan halaman dengan sesi login):
Clip Jalan (3.911 rantai) dengan 010500 menghasilkan 3.907 fitur/90.196,88 m
dalam 2,5 detik; Buffer 12 m atas Blok ukur dengan Gabung menghasilkan
1 bagian/5.096.949,33 m² dalam 0,4 detik; Intersect Calon bidang × Bidang HM
menghasilkan 398 fitur/42,31 m² dalam 0,8 detik; Intersect Bidang × 010500
menghasilkan 2.624 fitur/2.808.303,26 m² dalam 26,4 detik; Union Calon
bidang × Bidang HM 2.911 fitur/2.489.399,70 m² dalam 1,2 detik; Dissolve
Bidang seluruh fitur 433 bagian/2.563.384,24 m² dalam 0,7 detik, dengan
atribut `entity` hasil sama karena seluruh fitur satu nilai; Dissolve Bidang
HM menurut `layer` 476 bagian/1.327.935,75 m² dalam 0,5 detik. Dissolve
Calon bidang ditolak dengan pesan cincin keluaran gagal diselesaikan karena
cincinnya saling bertumpang; pesan itu tampil di kartu, bukan galat konsol.
Bersihkan hasil mengembalikan hitungan ke tanda pisah dan menonaktifkan
kedua tombol. 0 pesan konsol; 0 karakter CJK pada tiga berkas yang disunting
(`peta-lama.php` 3.124 baris, +719/−1; `digitasi.php` 1.493 baris, +83;
`assets/js/turf.min.js` berkas baru 563.590 B).

### 3.135 Layout & ekspor peta di Peta Bidang: Simbol garis, elemen lembar, PDF/PNG/JPG, proyek JSON (23 Nov 2026)

**Permintaan.** Peta Bidang diminta mendapat tiga kelompok kemampuan ala
ArcMap. Pertama **Symbology & Labels**: mengatur warna, ketebalan garis,
simbol kategori, dan label teks pada fitur peta. Kedua **Insert (elemen
layout)**: menambahkan Title, North Arrow, Scale Bar/Text, dan Legend saat
berada di Layout View. Ketiga **Save & Export Map**: menyimpan proyek
sebagai dokumen peta atau mengekspor hasil akhir ke PDF, PNG, atau JPG.

**Pendekatan.** Semua alat dipasang di `peta.php` tanpa API baru dan tanpa
menulis basis data. Warna, tebal, dan kepekatan garis disimpan di setelan
peramban yang sudah ada (`sip.peta.pengaturan.v2`), sehingga ikut tersimpan
di preset basis data dan di berkas proyek. Ekspor gambar memakai pustaka
**html2canvas 1.4.1** (lisensi MIT, 198.689 B, sha256
`e87e550794322e574a1fda0c1549a3c70dae5a93d9113417a429016838eab8cb`) yang
disalin ke `assets/js/html2canvas.min.js`; tidak ada CDN dan tidak ada
permintaan jaringan tambahan selain ubin peta yang sudah dimuat.

**Symbology & labels.** Kartu **Tampilan peta** mendapat tabel
`#simbolGaris` berisi tujuh baris lapis garis: Batas blok, Batas desa, Jalan,
Selokan, Saluran, Tepi saluran, dan Sungai. Tiap baris berisi pemilih warna
(`#rrggbb`), penggeser tebal 0,4–4 px (langkah 0,1), dan penggeser kepekatan
10–100% (langkah 5). Bawaan: batas blok `#14603a`/2/95, batas desa
`#1f4e79`/2,4/100 putus `8 5`, jalan `#0f766e`/1,1/85, selokan
`#0ea5e9`/1,4/95, saluran `#0369a1`/1,7/95, tepi saluran `#0c4a6e`/1,2/90,
sungai `#1d4ed8`/2/95 putus `9 5`. Nilai yang tidak sah dikembalikan ke
bawaan oleh `rapikanGaris` (warna wajib heksadesimal enam angka, tebal
0,4–4, kepekatan 10–100). Perubahan langsung mengubah gaya lapis aktif lewat
`setStyle`, dan legenda peta menampilkan garis contoh dengan tinggi, warna,
serta pola putus yang sama (`butirGaris`). Lapis bidang mengikuti baris
batas blok. Pilihan isi label (`#labelIsi`: pemegang, kode, luas) hanya
aktif bila centang **Label bidang** menyala; teks label mengikuti pilihan
itu (`teksLabel`).

**Insert (elemen lembar).** Saat mode **Layout View** menyala, bilah
**Lembar** muncul di atas peta dengan isian Judul (80 aksara) dan Subjudul
(140 aksara) serta tiga centang: Arah utara, Legenda, dan Skala batang.
Kotak judul di lembar peta memakai nilai itu; subjudul bawaan menyebut nama
desa. Skala batang dihitung ulang setiap peta digeser atau diperbesar
(`perbaruiSkalaBatang`): angka bulat 1/2/5×10ⁿ yang panjangnya paling dekat
110 px, digambar empat ruas berselang-seling dengan label 0, tengah, dan
ujung; teks skala di kotak judul ikut berubah (uji 1:2.200 pada zoom 18,
1:600 setelah diperbesar). Legenda lembar tetap memakai butir yang sama
dengan legenda peta. Elemen hanya tampil di Layout View dan disembunyikan di
Data View serta saat dicetak bila centangnya dimatikan.

**Save & export.** Tombol **PNG** dan **JPG** merasterkan seluruh wadah peta
(`#wadahPeta`: ubin peta dasar, bidang, garis, label, legenda, kotak judul,
skala batang, arah utara) dengan `scale: 2`, latar putih, dan `useCORS:
true`. Ubin OpenStreetMap diuji pada zoom 18 dan lolos CORS sehingga kanvas
tidak ternoda. Selama ekspor kedua tombol dinonaktifkan; kegagalan
ditampilkan lewat `Sip.pesan`, bukan galat konsol. Nama berkas
`peta-gedong-YYYYMMDD-HHMM.png` atau `.jpg` (JPG mutu 0,92). Tombol **PDF**
membuka dialog cetak peramban; pilih *Simpan sebagai PDF* dengan ukuran A4
lanskap memakai aturan cetak §3.130. Dokumen **.mxd** ArcGIS tidak bisa
dibuat peramban, jadi proyek disimpan sebagai JSON: **Unduh proyek
(.json)** menghasilkan `proyek-peta-gedong-YYYYMMDD-HHMM.json` berisi
`aplikasi: SIPETADES`, `jenis: proyek-peta`, `versi: 1`, `dibuat`, `tampilan`
(lat, lon, zoom), dan `setelan` (seluruh pengaturan termasuk `garis` dan
`layout`). **Muat proyek** membuka pemilih berkas, menolak berkas yang
`jenis`-nya bukan `proyek-peta`, lalu merapikan setelan, menerapkannya,
memulihkan pandangan, dan memuat ulang bidang.

**ArcToolbox.** Navigasi kiri `digitasi.php` mendapat kelompok **Layout**
berisi satu butir "Layout & ekspor peta" dengan jumlah 3, dan bagian baru
`#layout-ekspor` berisi tiga kartu (Symbology & labels, Insert (elemen
lembar), Save & export map) dengan tautan **Buka Layout View** menuju
`peta.php?tampilan=layout`. Catatan penutup menegaskan tidak ada data desa
yang diubah.

**Pemeriksaan.** `php -l` bersih pada kedua halaman. Blok JS `peta.php`
(1.877 baris) lolos `--check`. Di peramban (lebar dalam 861 px, salinan
halaman dengan sesi login): Data View memuat 4.326 bidang dan 12 ubin;
ekspor PNG menghasilkan 1.540×1.108 px/917.278 B dan JPG 1.540×1.108
px/285.611 B, masing-masing sekitar 10,6 dan 11,4 detik; hasil PNG diperiksa
mata dan memuat ubin, bidang, garis, legenda, kotak judul, skala batang,
serta arah utara. Skala batang terukur empat ruas 21 px dengan label
0/25 m/50 m pada zoom 18. Proyek JSON terukur 1.574 B; setelah judul dan
warna jalan diubah, memuat berkas itu memulihkan judul, warna `#ff0000`,
dan pandangan dalam 0,25 detik, lalu bidang termuat ulang (4.326 path).
Bilah Lembar tidak meluber pada lebar 260, 320, dan 440 px; tabel simbol
sempat meluber 2 px pada baris Batas desa ("2,4"/"100%") setelah kolom
dipadatkan, lalu kolom dirapikan sehingga terukur 242/242 px pada kartu
280 px (batas minimum bilah samping) dan 772/772 px pada kartu 808 px.
Setelan peramban dipulihkan ke keadaan semula setelah uji; 0 pesan konsol.
0 karakter CJK pada empat berkas yang disunting (`peta.php` 2.375 baris,
+458/−24; `digitasi.php` 1.562 baris, +69; `assets/css/app.css` 966 baris,
+50; README ditambah satu bagian), ditambah satu berkas baru
`assets/js/html2canvas.min.js`.

---

### 3.136 Label blok tiga digit di seluruh tampilan (23 Nov 2026)

Kode blok kini dibaca pengguna sebagai tiga digit ("BLOK 2" tampil
"BLOK 002", "BLOK 26" tampil "BLOK 026"). Nilai di tabel `blok` tidak
diubah: `kode` dan `nama` tetap "BLOK 2".."BLOK 26", dan nilai formulir,
parameter `blok=BLOK 2`, serta pembanding alat CLI tetap memakai kode
asli. Pemformatan hanya di lapisan tampilan lewat `blok_tiga_digit()`
(`app/helpers.php`): pola "BLOK <angka>" dirapatkan menjadi tiga digit,
teks lain (mis. "Tanpa blok") dibiarkan.

Tempat yang ikut berubah: pilihan saring Blok dan legenda per blok di
`peta.php`, label serta tooltip lapis blok dan properti `blok` GeoJSON
(`api/bidang.php`), popup dan tabel atribut, kolom Blok di `bidang.php`
(detail, formulir, saring, tabel, CSV), `sppt.php` (detail, saring,
tabel, CSV), pilihan blok perkiraan dan pesan fokus `peta-lama.php`,
kandidat blok di popup peta, serta `blok_nama`/`label` pada
`api/cari_bidang.php` dan `api/cari_bidang_terdekat.php` dan
catatan/respons `api/penanda-bidang.php`.

**Pemeriksaan.** `php -l` bersih pada sembilan berkas PHP. Bantuan diuji:
"BLOK 2" menjadi "BLOK 002", "BLOK 26" menjadi "BLOK 026", "BLOK 002"
tetap, "Tanpa blok" tetap. `api/bidang.php?lapisan=blok` mengembalikan
24 fitur "BLOK 002".."BLOK 026"; saringan lama `blok=BLOK 2` tetap
menemukan bidang (100 baris, batas muat bawaan) dengan properti `blok`
"BLOK 002". Di peramban: 24 label blok, legenda per blok ("BLOK 002
320", dan seterusnya), dan 4.326 baris tabel atribut memakai tiga digit;
pilihan saring bernilai `BLOK 2` dengan teks `BLOK 002`. CSV `bidang.php`
dan `sppt.php` memuat "BLOK 003"/"BLOK 012". 0 pesan konsol; setelan
peramban dipulihkan setelah uji. 0 karakter CJK pada sembilan berkas PHP
dan README.

---

### 3.137 Kode bidang standar: kode desa + blok + urut baris dari barat laut (23 Nov 2026)

Setiap bidang tanah kini punya kode baku berformat
`3324042008-BLOK3-URUT4`, mis. `3324042008-002-0005`: nomor kode desa
menurut Kemendagri (33.24.04.2008), kode blok tiga digit, lalu nomor
urut empat digit di dalam blok itu. Nomor dibaca baris demi baris: baris
paling utara dulu, dan di dalam satu baris nomor maju dari barat ke timur
(`pusat_lon` menaik); habis satu baris, nomor lanjut ke baris berikutnya
di selatan. Baris dihitung dari selisih lintang terhadap bidang paling
utara blok dengan tinggi `BARIS_URUT_LAT` = 0,00045 derajat (±50 m);
pemutus seri kode internal. Jadi `0001` jatuh di
ujung barat baris paling utara. Bidang berstatus `dihapus` dan `duplikat`
tidak ikut dinomori. Bidang tanpa blok tidak punya kode standar dan tetap
memakai kode internalnya.

Angka `3324042008` adalah kode desa Kemendagri, bukan awalan NOP
(`setelan.kode_wilayah_nop` = `3324040008`); keduanya sengaja dibedakan
lewat konstanta `KODE_DESA` di `app/helpers.php`.

Kode ini **hanya dihitung di lapisan tampilan**, tidak ada kolom atau
baris database yang berubah. Tiga pembantu di `app/helpers.php`:

- `urut_bidang_per_blok()` — satu kueri baca untuk semua bidang hidup
  ber-blok, diurutkan sesuai aturan di atas lalu diberi nomor 1..n per
  blok; hasilnya di-cache statis per permintaan.
- `kode_bidang_standar(int $id)` — menyusun `KODE_DESA-` + blok tiga
  digit + urut empat digit, kosong bila bidang tanpa blok.
- `id_dari_kode_standar(?string $kode)` — kebalikannya, dipakai pencarian
  supaya kode standar utuh langsung menjadi `b.id = ?`.

Karena nomor diturunkan dari data saat halaman dibuka, kode bisa bergeser
bila ada bidang baru di blok yang sama (susunan baris berubah) atau
bila status bidang menjadi `dihapus`/`duplikat`. Kode internal
(`DXF-...`, `MANUAL-B...`) tetap tersimpan dan tetap ditampilkan sebagai
"Kode internal" supaya rujukan lama tidak hilang.

**Tempat yang memakai kode standar.** `bidang.php`: judul halaman dan
detail memakai kode standar, baris "Kode bidang" diikuti "Kode internal",
teks tautan daftar memakai kode standar dengan kode internal kecil di
bawahnya, bantuan pencarian, dan CSV dua kolom pertama ("Kode bidang",
"Kode internal"). `api/bidang.php`: properti `kode` (standar, jatuh ke
kode internal bila tanpa blok) + `kode_internal` baru, dan `kode_standar`
pada daftar ringkas; saringan `cari` menerima kode standar utuh.
`api/cari_bidang.php` dan `api/cari_bidang_terdekat.php`: `kode` standar +
`kode_internal`, label hasil memakai kode standar. `peta.php`: contoh
pencarian, daftar bidang, popup (baris "Kode internal" bila berbeda), dan
tabel atribut (kode internal kecil). `sppt.php`: kolom serta CSV "Bidang
tertaut"; `buku-c.php`, `laporan.php`, `peralihan.php`: teks tautan bidang.

**Pemeriksaan.** 4.326 bidang hidup; 4.275 ber-blok di 24 blok mendapat
kode standar (4.275 kode unik, 0 bentrok, bolak-balik kode ke id 0 salah)
dan 51 bidang tanpa blok memakai kode internal. BLOK 002 berisi 320 bidang
(`0001`..`0320`): `0001` = `DXF-020100-2932` (bujur 110,0639772 / lintang
-7,0745291, ujung barat baris utara), lalu `0002` `DXF-020100-2917`,
`0003` `DXF-020100-G00641`, `0004` `HM-0005`, `0005`
`DXF-020100-0453` (1.012,00 m², perlu verifikasi), `0010`
`DXF-020100-G00642`; jadi contoh pengguna `3324042008-002-0005` kini
menunjuk id 11801 `DXF-020100-0453`. Di peramban `peta.php`: pencarian
kode standar menyaring menjadi "1 tanah · 0 obyek", daftar samping
"3324042008-002-0005 / Jenis hak tidak tercatat / 1.012 m²", popup
memuat kode standar dan baris "Kode internal DXF-020100-0453", tabel
atribut memuat kode kecil yang sama, 0 galat JS (hanya ubin OSM 400 di
zoom 20). Halaman detail `bidang.php?id=11801` menampilkan
`3324042008-002-0005` beserta "Kode internal DXF-020100-0453". CSV
`bidang.php` berkolom 23 dengan dua kolom kode; ekspor BLOK 2 = 390
baris; baris ke-2 `3324042008-002-0005;DXF-020100-0453;...`. CSV
`sppt.php` 3.794 baris, kolom "Bidang tertaut" terisi 176 = tepat jumlah
SPPT tertaut bidang di database. `php -l` bersih pada 11 berkas PHP;
0 karakter CJK pada berkas yang diubah.

---

## 4. Berkas SPPT dari Excel

Importer CSV menerima kolom berikut (nama kolom dibersihkan dari spasi dan tanda
baca, lalu dicocokkan tanpa huruf besar-kecil):

```
nop, nama_wp, alamat_wp, alamat_op, dusun, luas_bumi, luas_bng,
njop, pajak_ditetapkan, terbayar, status_bayar, tanggal_bayar,
tahun_pajak, pemungut
```

Varian yang biasa tertulis di lembar desa juga diterima: `nop_pbb`,
`nama_pemilik`, `pbb_terutang`, `tagihan_pbb`, `njop_pbb`, `tgl_bayar`.
Kolom `nop` wajib ada.

`*.xlsx` tidak dibaca langsung — simpan sheet sebagai CSV (UTF-8) ke
`files\data excel\` lebih dulu, baru jalankan `php tools\impor.php sppt`.

Perintah `sppt` (dan `buku-c`) **langsung menulis tanpa mode periksa**, tidak
seperti `nama`, `nis`, `nib`, `daftar` atau `hak-milik`. Cadangkan basis data
dulu; `--reset` membuang seluruh isi `pembayaran`, `sppt` dan `objek_pajak`
sebelum impor. Lihat [§3.28](#328-buku-c-dan-data-pbb-2026-dari-berkas-desa)
untuk contoh pemakaian pada laporan PBB 2026.

Aturan baris: NOP harus 18 digit dan diawali `kode_wilayah_nop` dari tabel
setelan, kalau tidak barisnya dilewati dan dilaporkan sebagai galat. Tahun pajak
yang kosong diisi dari setelan `tahun_pbb_aktif`. Kolom `sisa` pada tabel `sppt`
dihitung sendiri oleh database (`pajak_ditetapkan - terbayar`), jadi tidak perlu
diisi.

---

## 5. Menjalankan saat pengembangan tanpa Apache

Server bawaan PHP mengabaikan `.htaccess`, jadi pakai router yang menutup folder
terlindungi:

```
D:\xampp\php\php.exe -S 127.0.0.1:8099 -t . tools\server.php
```

Tanpa `tools\server.php`, berkas di `app\`, `sql\`, `tools\`, `storage\`,
`files\`, dan `_lama\` **bisa diambil lewat URL**. Router itu meniru aturan
Apache (404 untuk folder tertutup, 404 untuk `.sql .md .txt .json .geojson .csv`
dan sejenisnya). Jangan pernah mengandalkan `php -S` langsung untuk host yang
terbuka jaringan.

---

## 6. Struktur folder

```
app/            bootstrap, config, db, auth+CSRF, helper, layout, query data
  data.php      satu-satunya tempat SQL pembacaan (dipakai halaman dan API)
  lib-pengaturan.php  mesin kartu Pengaturan: mysqldump/mysql, ekspor-impor JSON, kosongkan
api/            endpoint JSON (bidang, sppt, buku-c, laporan, statistik, preset, peta-lama)
assets/css/     app.css — seluruh gaya, tanpa framework
assets/img/     beranda-latar.jpg — latar foto beranda (1600 px, 178 KB)
                masuk-latar.jpg — pita bawah Gambar Antarmuka.png, latar halaman masuk (1672 px, 144 KB)
assets/js/      app.js — helper `Sip.*`, modal, tab, konfirmasi formulir
sql/            01_skema.sql (struktur + data acuan), 02_akun_awal.sql (Super Admin pertama, tanpa sandi bawaan),
                03_preset_peta.sql (migrasi tabel preset peta untuk basis data yang sudah berjalan)
tools/          impor.php (CLI: impor + gabung blok), lib-impor.php (pustaka impor),
                ekspor.php (laporan lengkap CSV),
                fit-transform-dxf.php (turunkan ulang transformasi DXF),
                konversi-dxf-lapisan.php (DXF → GeoJSON untuk peta-lama.php),
                rapikan-garis-blok.php (larutkan/haluskan/sambung garis blok perkiraan),
                garis-batas-020100.php (turunkan garis batas jalan/perairan/lain-lain dari 020100), server.php (router dev)
*.geojson       blok_peta, bidang_tanah, bidang_lain di akar — sumber geometri
files/          arsip pindai Buku C, lembar data excel, master peta (DXF), peta gang
files/peta gang/ hasil `impor.php gg` (titik penanda) + poligon gang siap impor
storage/        unggahan pengguna (lampiran laporan), cadangan gabung-blok/ubah-blok/
                duplikat, dan luaran `ekspor.php` di laporan-<waktu>/
                cadangan/  berkas .sql hasil kartu Cadangan (+ sebelum-impor/-pulihkan/-kosongkan)
                ekspor/    berkas JSON hasil kartu Ekspor
                impor/     berkas yang diunggah ke kartu Impor, namanya dibuat server
_lama/          berkas sistem sebelumnya — diarsipkan, tidak dipakai
```

Halaman di akar: `beranda.php` (halaman depan publik, satu-satunya yang boleh dibaca
tanpa akun), `index.php` (dasbor perangkat desa), `peta.php`, `peta-blok.php` (peta batas blok),
`peta-lama.php` (penampil
19 layer DXF arsip `files/sig-pertanahan-gedong-lokal`, koordinat lokal), `bidang.php`, `sppt.php`,
`buku-c.php`, `peralihan.php`, `laporan.php`, `pengaturan.php` (cadangan/ekspor/impor/
kosongkan, hanya Super Admin), `pengguna.php`, `pasang.php`,
`dokumen.php`, `login.php`, `logout.php`, `setup.php`. Akar situs (`/`) ditulis ulang
ke `beranda.php`, baik pada `.htaccess` maupun `tools/server.php`.

Pada `peta-lama.php`, Super Admin dapat memilih satu dari delapan layer sumber
untuk membuat bidang tanah. Semua poligon pada layer pilihan diimpor, termasuk
batas desa (`010500`) dan batas blok. Khusus layer `020100`, garis yang ujungnya
tersambung disusun menjadi poligon tertutup; garis yang tidak membentuk bidang
tertutup dilewati. Konversi koordinat DXF ke WGS84 berdasarkan transformasi
perkiraan, sehingga setiap bidang baru berstatus **perlu verifikasi**, belum
memiliki blok, dan memakai jenis hak sementara BLM. Kode impor stabil dan
bidang bergeometri hampir sama dilewati saat impor ulang.

---

## 7. Peran dan hak akses

| Peran | Lihat data & peta | Ubah data lapangan | Catat laporan warga | Akun, setelan, impor |
| --- | --- | --- | --- | --- |
| `super_admin` | ya | ya | ya | ya |
| `admin` | ya | ya | ya | tidak |
| `kewilayahan` | ya | tidak | ya | tidak |
| `baca` | ya | tidak | ya | tidak |

Artinya peran `kewilayahan` dan `baca` untuk saat ini sama: keduanya pembaca.
Nomor telepon pelapor hanya dibuka untuk `admin`/`super_admin`, dan pada API
nilai itu diganti string `tersimpan` untuk peran lain.

Aturan yang dijaga di tingkat kode, bukan hanya antarmuka:

- Super Admin aktif terakhir tidak bisa dinonaktifkan, diturunkan, atau dihapus.
- Akun tidak bisa menghapus atau menurunkan dirinya sendiri.
- Gagal login 5 kali mengunci akun 15 menit; kunci manual juga tersedia dan
  bisa dibuka lewat `pengguna.php`.
- Setiap perubahan data memanggil `audit()` — siapa, objek apa, nilai sebelum
  dan sesudah.
- Empat tindakan yang boleh mengubah seluruh tabel (cadangan, pemulihan, impor,
  pengosongan) hanya ada di `pengaturan.php`, dan halaman itu sendiri
  `wajib_masuk([PERAN_SUPER])`; peran lain menerima 403 sebelum satu baris pun
  dibaca (§3.24).

---

## 8. Keamanan

Yang sudah diterapkan, dan jangan dilonggarkan:

- `.htaccess` di akar menutup `app|sql|tools|storage|_lama` lewat
  `RedirectMatch 404`, menolak ektensi berkas data, dan mematikan indeks folder.
  `app/`, `sql/`, `tools/`, `files/`, `storage/` masing-masing juga punya
  `Require all denied` sendiri supaya tetap tertutup bila `mod_rewrite` mati.
- Pindai Buku C tidak pernah disajikan lewat path publik. Browser hanya mengirim
  `dokumen.php?buku-c=142`; halaman itu memeriksa sesi, lalu mencari berkas dari
  `baseline()` saja dan menjaga `realpath()` tetap di dalam folder arsip, sehingga
  traversal (`../`) tidak mungkin. Respons dikirim dengan `nosniff` dan
  `Cache-Control: private, no-store`, dan pengambilannya dicatat di audit.
- Semua SQL memakai *prepared statement*; filter pencarian meng-escape `%` dan
  `_` sebelum `LIKE`.
- Setiap POST butuh `wajib_csrf()`; token dikirim sebagai `csrf_token` atau
  header `X-CSRF-Token` untuk panggilan API.
- Nomor telepon pelapor diganti string `tersimpan` pada API untuk peran di luar
  pengelola.
- `api/statistik.php` wajib masuk. Angka PBB, peralihan, dan laporan adalah data
  internal desa; beranda publik menghitung sendiri ringkasan yang boleh tampil,
  jadi tidak ada pemanggil yang kehilangan akses.
- Lapisan `api/bidang.php` dan daftar ringkasnya tetap bisa dibaca tanpa masuk,
  tetapi nama pemegang, NOP, nomor sertifikat, dan status PBB diganti `tersimpan`
  atau dikosongkan lewat `sembunyikan_pribadi()` di `app/helpers.php` (1 Nov
  2026). Beranda publik juga tidak lagi ikut menanam nama pemegang ke HTML:
  `SIP_HERO` hanya membawa kode, luas, status, jenis hak, penggunaan, dan
  geometri bidang sampel, sedangkan kolom "Pemilik" berbunyi "tersedia untuk
  petugas". Data pribadi baru akan terisi saat petugas menautkan Buku C, jadi
  penutupan ini bersifat pencegahan.
- Pembuatan laporan tidak memerlukan JavaScript: `laporan.php` memproses
  formulirnya di sisi PHP. Seperti halaman lainnya, melaporkan masalah tetap
  butuh akun masuk — laporan tidak diterima dari pengunjung anonim.

Yang masih harus dilakukan pemilik sistem:

- Ganti `app/config.php` dari contoh, lalu pastikan isinya tidak bocor ke
  manapun.
- **Buat akun resmi perangkat desa lewat `pengguna.php`, lalu hapus akun uji.**
  Sandi lama peninggalan pengujian (`Sipetades2026!dev`) dan pasangan
  `admin_gedong` di `_lama/setup.php` sudah tidak berlaku: pada 20 Okt 2026 akun
  Super Admin satu-satunya (`superadmin`) dirotasi ke sandi acak yang hanya
  disampaikan langsung, dan `password_verify()` memastikan kedua sandi lama
  ditolak. Sandi acak itu sendiri juga harus diganti begitu akun resmi ada.
- Matikan `debug` — sudah `false` di pemasangan ini (lihat §3.16); nyalakan
  kembali hanya saat mencari sebab galat. Kalau sistem dibuka dari internet,
  pasang HTTPS supaya cookie sesi dikirim dengan flag `Secure`.
- **Salin berkas cadangan keluar dari disk produksi.** Halaman Pengaturan
  menulisnya ke `storage/cadangan/` di cakram yang sama dengan basis data, jadi
  unduh dan simpan di flashdisk atau komputer lain.

---

## 9. Cadangan dan pemulihan

```
D:\xampp\mysql\bin\mysqldump.exe -u root --no-tablespaces --databases sipetades_gedong > cadangan.sql
D:\xampp\mysql\bin\mysql.exe -u root < cadangan.sql
```

Geometri ikut ter-*dump* sebagai WKT, jadi cadangan ini sudah mencakup peta.
Berkas pindaian Buku C berada di luar database — folder `files\Data C Desa`
harus ikut dicadangkan, karena tabel `buku_c` hanya menyimpan namanya.

Dua baris di atas adalah cara manual; cara harian ada di halaman **Pengaturan**
(§3.24) — kartu 1 membuat dan memulihkan berkas `.sql` yang sama lewat
`mysqldump`/`mysql`, dan kartu 2–3 memindahkan data sebagai satu berkas JSON
beserta geometrinya. Keduanya sudah diuji lingkaran penuh di salinan
`sipetades_uji`: data dikosongkan lalu diimpor/dipulihkan balik, hasilnya identik
baris per baris.

**Retensi `storage/`: simpan semua, tidak ada yang dihapus otomatis** (keputusan
pemilik sistem, 1 Nov 2026). Setiap `cadangan_buat()`, `cadanganBlok()`, dan
jejak `rapikan-blok-<id>-*.json` dibiarkan menumpuk karena semuanya bisa dipakai
untuk menelusuri perubahan geometri. Keadaannya saat keputusan diambil: 142 MB —
`storage/cadangan/` 63 MB (20 berkas `.sql`), jejak JSON 11 MB (230 berkas), dua
dump pemulihan 20 Okt 11,9 MB di akar `storage/`, dan `storage/ekspor/` 9,3 MB.
Konsekuensinya cakram produksi akan terus bertambah (sesi perapian blok 1 Nov
menambah ±3 MB per blok), jadi yang perlu dijaga: salin berkas cadangan keluar
disk secara berkala dan pantau sisa ruang. Kalau kelak dibutuhkan pemangkasan,
buat alatnya dengan mode uji kering bawaan dan **pindahkan** berkas lama ke
arsip, jangan menghapus.

**23 rujukan cadangan lama di README sudah tidak ada di disk (ditemukan 13 Nov
2026).** Pindai seluruh nama berkas dump yang disebut README (pola
`sebelum-*`/`sesudah-*` berstempel `2026MMDD-HHMMSS-xxxx.sql`) menghasilkan 23
berkas unik pada 24 sebutan, semuanya berprefix `storage/cadangan/`, dan
**nol** di antaranya masih ada: sembilan belas berkas berstempel 1 Nov dan empat
berstempel 2 Nov. Sebaran sebutannya §3.26 (16), §3.27 (1), §3.28 (2), §3.30–§3.34
(satu per bagian); `sebelum-identitas-bidang-20261101-100004-9e84.sql` adalah satu
berkas yang disebut dua kali — di §3.27 dan §3.28 — jadi 24 sebutan itu memang
hanya 23 berkas. Semuanya terletak sebelum §3.76. Tidak ada satu pun yang hilang
karena operasi 13 Nov — ketika `storage/cadangan/` dibuka hari itu isinya hanya
delapan dump 8 Nov, pasangan 10 Nov 19:29, dan dua geojson lapis Jalan; penelusuran
seluruh docroot dan tiga tingkat ke bawah di `D:/` juga tidak menemukan salinannya
di tempat lain. Jadi angka-angka di §3.26–§3.34 tetap sah sebagai riwayat pekerjaan
— titik amannya memang pernah dibuat — tetapi **kembali ke keadaan 1–2 Nov tidak
mungkin lagi**; titik aman penuh paling tua yang masih ada kini hanyalah keadaan 8
Nov 13:41–13:42 di `storage/impor/` (dua paragraf di bawah) dan keadaan 10 Nov 19:29
di luar document root (lihat §3.76).

**Pindai seluruh rujukan berkas, hari yang sama: 156 token tidak ditemukan.**
Cara yang sama dipakai untuk semua rujukan berkas di dalam backtick README — nama
telanjang, path relatif docroot, maupun path absolut Windows; pola ber-`*` atau
kuranglebih dan URL dikecualikan. Hasilnya 287 token unik pada 466 sebutan: 81 token
(116 sebutan) ada tepat di path yang ditulis, 50 token (119 sebutan) tidak ada di
path itu tetapi nama berkasnya ditemukan di tempat lain dalam docroot, dan **156
token (231 sebutan) tidak ditemukan sama sekali**. Perinciannya: 73 token (76
sebutan) ditulis berawalan `storage\` — 30 dump pola `sebelum-*`/`sesudah-*`, 37
jejak JSON, 2 PNG pratinjau, 2 HTML, satu CSV dan satu SQL — 62 token (134 sebutan)
berupa nama telanjang (21 dump, 11 `.sql` lain, 10 `.json`, 8 `.csv`, 4 `.geojson`,
sisanya `.tsv`, `.txt`, `.wkt`, `.xlsx`, `.ini`, `.php`), 17 token (17 sebutan) path
absolut di bawah `D:\backup\sigpetades\` (13 dump, 3 `.sql`, 1 `.tsv`), dan 4 token
(4 sebutan) berawalan folder lain: tiga berkas `_lama/scratch/` berekor `-tmp.php`
yang di dalam README memang dinyatakan "dijalankan lalu dihapus", plus satu
`lidah-20261106\sebelum-potong-lidah-20261106.sql` tanpa awalan folder. Salah satu
dari empat `.geojson` nama telanjang itu sebenarnya bukan path: token `.geojson` di
baris 16 hanyalah sebutan ekstensi. Folder `D:\backup` — sumber 54 sebutan, semuanya
`D:\backup\sigpetades\…`, tempat dump sesi 6–8 Nov disimpan beserta `SHA256.txt` —
**tidak ada** di mesin ini: `is_dir` false, akar `D:/` berisi 28 entri dan tidak satu
pun bernama `backup`, dan glob `D:/*backup*`, `D:/*/backup*`, `D:/**/SHA256.txt`
maupun `D:/*.sql` semuanya kosong. Dua kandidat lain, `E:/` dan `F:/`, juga `is_dir`
false sehingga tidak bisa diperiksa. Yang diukur hanyalah cakram mesin ini pada
13 Nov 2026, jadi angka 156 itu bukan bukti datanya musnah — hanya bukti salinannya
tidak ada di sini. Pencocokannya juga kasar: token diambil apa adanya dari dalam
backtick, sehingga yang membawa perintah di depan path (`php tools/…`, `GET api/…`,
`?unduh=…`) ikut terhitung. Semua angka paragraf ini mengukur README pada keadaan
commit `866a1a7` (6.248 baris) — sebelum paragraf ini dan dua paragraf sesudahnya
ditulis — dan tidak dihitung ulang sesudahnya, karena teks baru itu sendiri
menambah rujukan berkas. Ketiga skrip penghitungnya masih ada di `_lama/scratch/`:
`ringkas-readme-20261113.php` (klasifikasi token), `rinci-tidakada-20261113.php`
(perincian per awalan dan ekstensi) dan `hitung-impor-20261113.php` (jumlah baris
per tabel yang dibaca dari berkas `.sql`).

**Keadaan 8 Nov 13:41–13:42 ternyata masih utuh, di `storage/impor/`.** Kesimpulan
semula — bahwa tidak ada salinan penuh yang mendahului 10 Nov — salah, dan kedua
tempatnya (§3.76 dan paragraf di atas) sudah dibetulkan. Penyebabnya: folder tempat
kartu Impor menyimpan berkas unggahan, yang namanya dibuat server sebagai
`<stempel-unggah>-<hash>-<nama-asli>` oleh `sql_impor_simpan()` dan padanannya untuk
JSON, tidak pernah ikut dibersihkan. Isinya empat berkas, 41.814.341 B.

1. `20261108-135556-cdfc66d2-cadangan-20261108-134223-8e7e.sql`, 9.715.994 B: dump
   lengkap 18 tabel (`CREATE TABLE`) dan empat view (`v_bidang`, `v_rekap_dusun`,
   `v_ringkasan_sistem`, `v_statistik_pbb`, bagian *temporary* dan *final* keduanya
   ada) dengan 20 pernyataan `INSERT`. Jumlah baris terbaca dari berkasnya: `bidang`
   7.947, `hak` 7.945, `objek_pajak` 3.794, `sppt` 3.794, `audit_log` 3.392, `buku_c`
   1.419, `blok` 24, `setelan` 13, `dusun` 10, `pengguna` 1; `laporan`, `pembayaran`,
   `pemegang`, `peralihan` dan `preset_peta` tanpa baris. Keadaan ini mendahului
   penghapusan 3.773 obyek non-tanah 10 Nov, jadi memulihkannya berarti mengembalikan
   basis data ke 7.947 baris `bidang` (yang dipakai peta kini 4.177).
2. `20261108-134926-d0bf998a-sipetades-20261108-134100-1b0d.json` dan
   `20261108-135005-2b088941-sipetades-20261108-134100-1b0d.json`: dua unggahan
   byte-identik dari satu ekspor JSON, masing-masing 15.954.390 B, MD5 keduanya
   `fe14f61658c06ba7942c7d1a7db28fd6`. Header-nya `perangkat = SIPETADES`,
   `basis = sipetades_gedong`, `dibuat = 2026-11-08T13:41:00+07:00`, `versi = 1`;
   `tabel` memuat 18 entri `kolom` + `baris`, total 28.363 baris (`bidang` 7.947
   lengkap dengan WKT-nya, `hak` 7.945). Pemulihannya lewat kartu 3 di Pengaturan,
   bukan lewat `mysql`.
3. `20261108-143550-52ca6e09-sebelum-impor-sql-20261108-135608-159d.sql`, 189.567 B:
   salinan unggahan dari dump cacat yang menguras basis data 8 Nov (10 `INSERT`
   melawan 25 `DROP`, sama dengan ciri enam dump cacat yang dilaporkan di §3.76).
   Isinya justru satu-satunya salinan dalam bentuk berkas yang ditemukan di cakram
   ini untuk tiga tabel cadangan: `bidang_duplikat_geom_backup` 207 baris,
   `bidang_duplikat_backup` 169 dan `bidang_blok3_outside_backup` 50, selain
   `audit_log` 4 baris. **Jangan pernah diimpor ke basis data mana pun.**

Keempatnya tidak terjangkau URL langsung: permintaan berkasnya 403 dan permintaan
daftar foldernya 404, diukur 13 Nov lewat Apache lokal. Antarmuka juga tidak
menawarkannya — gerbang `?unduh=` hanya membuka folder `cadangan` dan `ekspor`
(`pengaturan.php` baris 34 dan 38), dan aksi *impor-terapkan* hanya berlaku pada
berkas yang baru diunggah pada sesi yang sama (`$_SESSION['impor_siap']` dan
`['sql_impor_siap']`, baris 119–121 serta 148–150). Memakai salinan 8 Nov itu
berarti menyalinnya dari cakram, lalu mengunggahnya ulang lewat kartu Impor atau
menjalankan `mysql` dari shell. Untuk pemangkasan kelak, folder ini harus
diperlakukan sebagai cadangan, bukan sebagai sisa unggahan.

**Status retensi per 13 Nov 2026, pengingat saat membaca paragraf-paragraf di
atas.** Delapan dump 8 Nov di `storage/cadangan/` dihapus permanen (20.942.060 B
dibebaskan) dan sisa isi folder yang sama dipindah keluar document root ke
`D:/cadangan-sigpetades/2026-11-13/` (lima entri: dua dump 10 Nov + dua geojson
lapis Jalan = 15.997.362 B, dan `.dump-err.txt` 0 B; MD5 dicocokkan sebelum dan
sesudah pindah). Folder `storage/cadangan/` kini 0 berkas, sehingga kartu *Pulihkan*
di Pengaturan (§3.24) menampilkan daftar kosong — `cadangan_daftar()` hanya men-glob
folder itu. Angka 142 MB / 63 MB / 20 berkas `.sql` / 230 jejak JSON pada paragraf
1 Nov itu keadaan saat keputusan diambil, bukan keadaan kini. Yang terukur hari ini:
`storage/` seluruhnya 37 berkas, 55.907.103 B (53,3 MB) — akar 32 berkas 4.363.125 B,
`ekspor/` 1 berkas 9.729.637 B, `impor/` 4 berkas 41.814.341 B (paragraf di atas),
`cache/` dan `cadangan/` kosong. Akar `storage/` menampung 30 jejak JSON 1.489.863 B
— 28 `rapikan-blok-*.json` berstempel 5 Nov dan dua `rapikan-jalan-*.json` berstempel
12 Nov — ditambah dua dump sebagian 5 Nov yang isinya hanya tabel `blok` dan
`bidang`: `sebelum-gabung-blok11-20261105.sql` (1.436.713 B) dan
`sebelum-hapus-blok-kecil-20261105.sql` (1.436.549 B). Dua dump pemulihan 20 Okt
11,9 MB yang disebut paragraf 1 Nov sudah tidak ada di akar (`storage/*20261020*`
mencocokkan nol berkas); dari 20 Okt yang tersisa hanya ekspor JSON
`storage/ekspor/sipetades-20261020-174305-0fdd.json` 9.729.637 B, jadi angka 9,3 MB
untuk `ekspor/` masih benar sampai sekarang.

---

## 10. Pemecahan masalah

**"Konfigurasi belum ada."** `app/config.php` belum dibuat. Salin dari
`app/config.example.php`.

**Impor GeoJSON lambat atau terpotong.** Poligon bidang besar; naikkan
`max_execution_time` dan `memory_limit` di `php.ini`, atau jalankan lewat shell
yang tidak mengenal batas waktu Apache. Dari browser, `pasang.php` sudah memanggil
`set_time_limit(0)`, tetapi batas *timeout* Apache tetap berlaku.

**Blok atau bidang dilewati semua.** Impor bersifat *idempoten* berdasarkan
kode: jalankan dengan `--reset` (atau centang "Kosongkan tabel terkait") bila
GeoJSON-nya memang versi baru.

**Bidang seperti tidak ada di peta.** Centang lapisan **Bidang tanah** (kotak
layers kanan bawah) lalu terapkan saringan — setelah **Terapkan** peta langsung
memusat ke bidang yang cocok, jadi blok kecil (BLOK 14: 54 bidang, 6 di antaranya
bidang tanah) tetap terlihat. Jumlah bidang hasil saringan terbaca di kartu
"Bidang pada tampilan ini"; kalau angkanya benar tetapi petanya kosong, geser
tampilan manual atau klik salah satu nama bidang di daftar itu.

**Semua baris SPPT dilewati.** Cek `kode_wilayah_nop` pada tabel setelan terhadap
18 digit NOP; baris di luar kode wilayah sengaja dibuang dan muncul sebagai galat
di ringkasan impor.

**`Folder arsip tidak ada` saat indeks Buku C.** Kunci
`path.arsip_buku_c` di `app/config.php` menunjuk ke folder yang tidak ada di
mesin itu.

**Peta kosong.** Pastikan `ST_AsGeoJSON(geom)` tidak `NULL` pada tabel
`bidang`/`blok`, dan `peta_lat_pusat`, `peta_lon_pusat`, `peta_zoom_awal` sudah
benar di halaman pengaturan.

**Tautan PDF Buku C memberi 404.** Berkas harus bernama persis
`<nomor>.pdf` di folder arsip, karena `nomor` Buku C sekaligus dipakai sebagai
nama berkas dan tidak boleh diubah dari halaman web.

**`api/preset.php` menjawab 500.** Tabel `preset_peta` belum ada. Jalankan
`D:\xampp\mysql\bin\mysql.exe -u root sipetades_gedong < sql\03_preset_peta.sql`
(aman diulang; hanya membuat tabel itu, tidak menyentuh tabel lain).
