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
                rapikan-garis-blok.php (larutkan/haluskan/sambung garis blok perkiraan), server.php (router dev)
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
15 layer DXF arsip `files/sig-pertanahan-gedong-lokal`, koordinat lokal), `bidang.php`, `sppt.php`,
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
