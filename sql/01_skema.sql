-- ============================================================================
-- SIPETADES — SIG Pertanahan & PBB Desa Gedong
-- Skema basis data (sumber kebenaran tunggal)
-- Target: MariaDB 10.4+ (XAMPP), InnoDB, utf8mb4
--
-- Semua kolom geometry disimpan sebagai EPSG:4326 (lintang/bujur WGS84,
-- urutan koordinat [bujur, lintang] sama seperti GeoJSON). Attribute SRID
-- tidak dipakai karena tidak tersedia di MariaDB 10.4 — jangan pindahkan
-- tabel ke server lain tanpa memeriksa ulang sistem koordinatnya.
--
-- Cara pakai:  mysql -u root < sql/01_skema.sql
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = "+00:00";
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
SET autocommit = 0;

CREATE DATABASE IF NOT EXISTS `sipetades_gedong`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sipetades_gedong`;

-- ----------------------------------------------------------------------------
-- 1. TABEL HAPUS DULU (urut terbalik supaya foreign key tidak mengganggu)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_log`, `pembayaran`, `sppt`, `objek_pajak`,
  `laporan`, `peralihan`, `buku_c`, `hak`, `pemegang`, `bidang`, `blok`,
  `dusun`, `jenis_hak`, `jenis_peralihan`, `jenis_penggunaan`, `setelan`,
  `pengguna`;

-- ----------------------------------------------------------------------------
-- 2. PENGGUNA & HAK AKSES
-- ----------------------------------------------------------------------------
CREATE TABLE `pengguna` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `username`        varchar(50)  NOT NULL,
  `password_hash`   varchar(255) NOT NULL,
  `nama_lengkap`    varchar(100) NOT NULL,
  `jabatan`         varchar(60)  NOT NULL DEFAULT '',
  `peran`           enum('super_admin','admin','kewilayahan','baca') NOT NULL DEFAULT 'baca',
  `is_active`       tinyint(1)   NOT NULL DEFAULT 1,
  `gagal_login`     smallint(6)  NOT NULL DEFAULT 0,
  `terkunci_sampai` datetime     DEFAULT NULL,
  `terakhir_login`  datetime     DEFAULT NULL,
  `dibuat_pada`     timestamp    NOT NULL DEFAULT current_timestamp(),
  `diubah_pada`     timestamp    NOT NULL DEFAULT current_timestamp()
                                 ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pengguna_username` (`username`),
  KEY `idx_pengguna_peran` (`peran`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
  `id`          bigint(20) NOT NULL AUTO_INCREMENT,
  `waktu`       timestamp  NOT NULL DEFAULT current_timestamp(),
  `pengguna_id` int(11)    DEFAULT NULL,
  `aksi`        enum('buat','ubah','hapus','impor','ekspor','login','login_gagal','logout','kunci','buka_kunci') NOT NULL,
  `objek`       varchar(60)  NOT NULL DEFAULT '',
  `objek_id`    varchar(64)  NOT NULL DEFAULT '',
  `keterangan`  text DEFAULT NULL,
  `data_sebelum` json DEFAULT NULL,
  `data_sesudah` json DEFAULT NULL,
  `ip`          varchar(45)  NOT NULL DEFAULT '',
  `user_agent`  varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_audit_waktu` (`waktu`),
  KEY `idx_audit_objek` (`objek`,`objek_id`),
  KEY `idx_audit_pengguna` (`pengguna_id`,`waktu`),
  CONSTRAINT `fk_audit_pengguna` FOREIGN KEY (`pengguna_id`)
    REFERENCES `pengguna` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. MASTER DATA
-- ----------------------------------------------------------------------------
CREATE TABLE `dusun` (
  `id`     smallint(6)  NOT NULL AUTO_INCREMENT,
  `nama`   varchar(40)  NOT NULL,
  `kode`   char(3)      NOT NULL DEFAULT '',
  `urutan` smallint(6)  NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dusun_nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jenis_hak` (
  `id`        smallint(6) NOT NULL AUTO_INCREMENT,
  `kode`      varchar(8)  NOT NULL,
  `nama`      varchar(80) NOT NULL,
  `urutan`    smallint(6) NOT NULL DEFAULT 0,
  `aktif`     tinyint(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jenis_hak_kode` (`kode`),
  UNIQUE KEY `uq_jenis_hak_nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jenis_penggunaan` (
  `id`     smallint(6) NOT NULL AUTO_INCREMENT,
  `nama`   varchar(60) NOT NULL,
  `aktif`  tinyint(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jenis_penggunaan_nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jenis_peralihan` (
  `id`     smallint(6) NOT NULL AUTO_INCREMENT,
  `nama`   varchar(60) NOT NULL,
  `aktif`  tinyint(1)  NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jenis_peralihan_nama` (`nama`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `setelan` (
  `kunci`         varchar(64) NOT NULL,
  `nilai`         text DEFAULT NULL,
  `keterangan`    varchar(255) NOT NULL DEFAULT '',
  `diubah_oleh`   int(11) DEFAULT NULL,
  `diubah_pada`   timestamp NOT NULL DEFAULT current_timestamp()
                            ON UPDATE current_timestamp(),
  PRIMARY KEY (`kunci`),
  CONSTRAINT `fk_setelan_pengguna` FOREIGN KEY (`diubah_oleh`)
    REFERENCES `pengguna` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. WILAYAH PEMETAAN
-- ----------------------------------------------------------------------------
CREATE TABLE `blok` (
  `id`                   int(11) NOT NULL AUTO_INCREMENT,
  `kode`                 varchar(16) NOT NULL,
  `nomor`                smallint(6) DEFAULT NULL,
  `nama`                 varchar(80) NOT NULL DEFAULT '',
  `dusun_id`             smallint(6) DEFAULT NULL,
  `geom`                 multipolygon NOT NULL,
  `batas_tersedia`       tinyint(1) NOT NULL DEFAULT 1,
  `batas_perkiraan`      tinyint(1) NOT NULL DEFAULT 0,
  `sumber_perkiraan`     varchar(60) DEFAULT NULL,
  `jumlah_bidang_estimasi` int(11) DEFAULT NULL,
  `maks_jarak_estimasi_m`  int(11) DEFAULT NULL,
  `dibuat_pada`          timestamp NOT NULL DEFAULT current_timestamp(),
  `diubah_pada`          timestamp NOT NULL DEFAULT current_timestamp()
                                     ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blok_kode` (`kode`),
  SPATIAL KEY `spn_blok_geom` (`geom`),
  KEY `idx_blok_dusun` (`dusun_id`),
  CONSTRAINT `fk_blok_dusun` FOREIGN KEY (`dusun_id`)
    REFERENCES `dusun` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. PEMEGANG HAK (daftar orang/badan hukum)
-- ----------------------------------------------------------------------------
CREATE TABLE `pemegang` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `nama`       varchar(150) NOT NULL,
  `nik`        char(16) DEFAULT NULL,
  `jenis`      enum('perorangan','badan_hukum','pemerintah','kas_desa') NOT NULL DEFAULT 'perorangan',
  `alamat`     varchar(200) DEFAULT NULL,
  `telepon`    varchar(20)  DEFAULT NULL,
  `catatan`    text DEFAULT NULL,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  `diubah_pada` timestamp NOT NULL DEFAULT current_timestamp()
                            ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pemegang_nama` (`nama`),
  UNIQUE KEY `uq_pemegang_nik` (`nik`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. BIDANG TANAH (poligon hasil digitasi)
-- ----------------------------------------------------------------------------
CREATE TABLE `bidang` (
  `id`                  int(11) NOT NULL AUTO_INCREMENT,
  `kode`                varchar(32) NOT NULL COMMENT 'ID dari hasil digitasi, mis. HM-0001 / DXF-020100-0001',
  `tipe`                enum('tanah','lain') NOT NULL DEFAULT 'tanah',
  `nib`                 varchar(20) DEFAULT NULL COMMENT 'Nomor identifikasi bidang pada label peta, mis. M.558 — sumbernya lapisan `NIB HM`',
  `nis`                 varchar(120) DEFAULT NULL COMMENT 'Angka pada label `NIS : ...` lapisan tn_alashak peta master, beberapa dipisah " / "',
  `nomor_daftar`        varchar(20) DEFAULT NULL COMMENT 'Nomor pada label lapisan `080201` peta master — register lain, bukan nib',
  `blok_id`             int(11) DEFAULT NULL,
  `dusun_id`            smallint(6) DEFAULT NULL,
  `jenis_penggunaan_id` smallint(6) DEFAULT NULL,
  `luas_m2`             decimal(12,2) DEFAULT NULL,
  `status`              enum('aktif','perlu_verifikasi','duplikat','dihapus') NOT NULL DEFAULT 'aktif',
  `sumber`              varchar(40) NOT NULL DEFAULT 'master',
  `catatan`             text DEFAULT NULL,
  `geom`                geometry NOT NULL,
  `pusat_lon`           decimal(10,7) DEFAULT NULL,
  `pusat_lat`           decimal(10,7) DEFAULT NULL,
  `dibuat_pada`         timestamp NOT NULL DEFAULT current_timestamp(),
  `diubah_pada`         timestamp NOT NULL DEFAULT current_timestamp()
                                    ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bidang_kode` (`kode`),
  KEY `idx_bidang_blok` (`blok_id`),
  KEY `idx_bidang_dusun` (`dusun_id`),
  KEY `idx_bidang_tipe_status` (`tipe`,`status`),
  KEY `idx_bidang_nib` (`nib`),
  SPATIAL KEY `spn_bidang_geom` (`geom`),
  CONSTRAINT `fk_bidang_blok` FOREIGN KEY (`blok_id`)
    REFERENCES `blok` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bidang_dusun` FOREIGN KEY (`dusun_id`)
    REFERENCES `dusun` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bidang_penggunaan` FOREIGN KEY (`jenis_penggunaan_id`)
    REFERENCES `jenis_penggunaan` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. BUKU C DESA
-- ----------------------------------------------------------------------------
CREATE TABLE `buku_c` (
  `id`            int(11) NOT NULL AUTO_INCREMENT,
  `nomor`         int(11) NOT NULL COMMENT 'Nomor C-Desa, sama dengan nama berkas PDF-nya',
  `jenis`         enum('C-1','C-2','C-3') NOT NULL DEFAULT 'C-1',
  `bidang_id`     int(11) DEFAULT NULL,
  `dusun_id`      smallint(6) DEFAULT NULL,
  `pemegang_id`   int(11) DEFAULT NULL,
  `nama_pemegang` varchar(150) NOT NULL DEFAULT '',
  `nomor_persil`  varchar(40) DEFAULT NULL,
  `luas_m2`       decimal(12,2) DEFAULT NULL,
  `tahun_daftar`  smallint(6) DEFAULT NULL,
  `berkas_pdf`    varchar(191) DEFAULT NULL,
  `catatan`       text DEFAULT NULL,
  `dibuat_pada`   timestamp NOT NULL DEFAULT current_timestamp(),
  `diubah_pada`   timestamp NOT NULL DEFAULT current_timestamp()
                               ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_buku_c_nomor` (`nomor`),
  KEY `idx_buku_c_bidang` (`bidang_id`),
  KEY `idx_buku_c_pemegang` (`pemegang_id`),
  KEY `idx_buku_c_nama` (`nama_pemegang`),
  CONSTRAINT `fk_buku_c_bidang` FOREIGN KEY (`bidang_id`)
    REFERENCES `bidang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_buku_c_dusun` FOREIGN KEY (`dusun_id`)
    REFERENCES `dusun` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_buku_c_pemegang` FOREIGN KEY (`pemegang_id`)
    REFERENCES `pemegang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. HAK ATAS TANAH (satu hak aktif per bidang)
-- ----------------------------------------------------------------------------
CREATE TABLE `hak` (
  `id`             int(11) NOT NULL AUTO_INCREMENT,
  `bidang_id`      int(11) NOT NULL,
  `jenis_hak_id`   smallint(6) NOT NULL,
  `no_sertifikat`  varchar(60) DEFAULT NULL,
  `no_hak_pakai`   varchar(100) DEFAULT NULL,
  `buku_c_id`      int(11) DEFAULT NULL,
  `pemegang_id`    int(11) DEFAULT NULL,
  `pemegang_nama`  varchar(150) DEFAULT NULL,
  `catatan`        text DEFAULT NULL,
  `diperoleh_pada` date DEFAULT NULL,
  `dibuat_oleh`    int(11) DEFAULT NULL,
  `diubah_oleh`    int(11) DEFAULT NULL,
  `dibuat_pada`    timestamp NOT NULL DEFAULT current_timestamp(),
  `diubah_pada`    timestamp NOT NULL DEFAULT current_timestamp()
                               ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hak_bidang` (`bidang_id`),
  KEY `idx_hak_jenis` (`jenis_hak_id`),
  KEY `idx_hak_pemegang` (`pemegang_id`),
  KEY `idx_hak_buku_c` (`buku_c_id`),
  CONSTRAINT `fk_hak_bidang` FOREIGN KEY (`bidang_id`)
    REFERENCES `bidang` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hak_jenis` FOREIGN KEY (`jenis_hak_id`)
    REFERENCES `jenis_hak` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_hak_buku_c` FOREIGN KEY (`buku_c_id`)
    REFERENCES `buku_c` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_hak_pemegang` FOREIGN KEY (`pemegang_id`)
    REFERENCES `pemegang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_hak_dibuat_oleh` FOREIGN KEY (`dibuat_oleh`)
    REFERENCES `pengguna` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_hak_diubah_oleh` FOREIGN KEY (`diubah_oleh`)
    REFERENCES `pengguna` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9. OBJEK PAJAK & SPPT PBB
--    Satu NOP bisa punya banyak tahun SPPT, jadi identitas wajib pajak
--    dipisah dari ketetapan tahunan.
--    `blok_id` dihitung otomatis dari digit 12-14 NOP dan boleh ditulis ulang
--    kapan pun; `bidang_id` hanya diisi lewat tautan tangan karena data desa
--    tidak menyimpan kunci per bidang yang sama di kedua sisi.
-- ----------------------------------------------------------------------------
CREATE TABLE `objek_pajak` (
  `nop`          char(18) NOT NULL,
  `bidang_id`    int(11) DEFAULT NULL,
  `blok_id`      int(11) DEFAULT NULL,
  `dusun_id`     smallint(6) DEFAULT NULL,
  `nama_wp`      varchar(150) NOT NULL DEFAULT '',
  `alamat_wp`    varchar(200) DEFAULT NULL,
  `alamat_op`    varchar(200) DEFAULT NULL,
  `luas_bumi_m2` int(11) DEFAULT NULL,
  `luas_bng_m2`  int(11) NOT NULL DEFAULT 0,
  `catatan`      text DEFAULT NULL,
  `dibuat_pada`  timestamp NOT NULL DEFAULT current_timestamp(),
  `diubah_pada`  timestamp NOT NULL DEFAULT current_timestamp()
                             ON UPDATE current_timestamp(),
  PRIMARY KEY (`nop`),
  KEY `idx_objek_bidang` (`bidang_id`),
  KEY `idx_objek_blok` (`blok_id`),
  KEY `idx_objek_dusun` (`dusun_id`),
  KEY `idx_objek_nama` (`nama_wp`),
  CONSTRAINT `fk_objek_bidang` FOREIGN KEY (`bidang_id`)
    REFERENCES `bidang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_objek_blok` FOREIGN KEY (`blok_id`)
    REFERENCES `blok` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_objek_dusun` FOREIGN KEY (`dusun_id`)
    REFERENCES `dusun` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sppt` (
  `id`               int(11) NOT NULL AUTO_INCREMENT,
  `nop`              char(18) NOT NULL,
  `tahun_pajak`      smallint(6) NOT NULL,
  `njop_pbb`         decimal(16,2) NOT NULL DEFAULT 0.00,
  `pajak_ditetapkan` decimal(14,2) NOT NULL DEFAULT 0.00,
  `terbayar`         decimal(14,2) NOT NULL DEFAULT 0.00,
  `sisa`             decimal(14,2) GENERATED ALWAYS AS (`pajak_ditetapkan` - `terbayar`) STORED,
  `status_bayar`     enum('Lunas','Belum Bayar','Sebagian') NOT NULL DEFAULT 'Belum Bayar',
  `tanggal_bayar`    date DEFAULT NULL,
  `pemungut`         varchar(60) DEFAULT NULL,
  `sumber`           varchar(30) NOT NULL DEFAULT 'import',
  `dibuat_pada`      timestamp NOT NULL DEFAULT current_timestamp(),
  `diubah_pada`      timestamp NOT NULL DEFAULT current_timestamp()
                                 ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sppt_nop_tahun` (`nop`,`tahun_pajak`),
  KEY `idx_sppt_tahun_status` (`tahun_pajak`,`status_bayar`),
  CONSTRAINT `fk_sppt_objek` FOREIGN KEY (`nop`)
    REFERENCES `objek_pajak` (`nop`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pembayaran` (
  `id`             int(11) NOT NULL AUTO_INCREMENT,
  `sppt_id`        int(11) NOT NULL,
  `tanggal_bayar`  date NOT NULL,
  `jumlah`         decimal(14,2) NOT NULL,
  `metode`         enum('Tunai','Transfer','Non-tunai','Lainnya') NOT NULL DEFAULT 'Tunai',
  `referensi`      varchar(80) DEFAULT NULL,
  `pemungut`       varchar(60) DEFAULT NULL,
  `dicatat_oleh`   int(11) DEFAULT NULL,
  `keterangan`     varchar(255) DEFAULT NULL,
  `dibuat_pada`    timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pembayaran_sppt` (`sppt_id`),
  KEY `idx_pembayaran_tanggal` (`tanggal_bayar`),
  CONSTRAINT `fk_pembayaran_sppt` FOREIGN KEY (`sppt_id`)
    REFERENCES `sppt` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pembayaran_pengguna` FOREIGN KEY (`dicatat_oleh`)
    REFERENCES `pengguna` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `ck_pembayaran_jumlah` CHECK (`jumlah` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. PERALIHAN & MUTASI HAK
-- ----------------------------------------------------------------------------
CREATE TABLE `peralihan` (
  `id`                   int(11) NOT NULL AUTO_INCREMENT,
  `kode`                 varchar(20) NOT NULL,
  `bidang_id`            int(11) DEFAULT NULL,
  `nop`                  char(18) DEFAULT NULL,
  `buku_c_id`            int(11) DEFAULT NULL,
  `jenis_peralihan_id`   smallint(6) NOT NULL,
  `tanggal`              date NOT NULL,
  `no_akta`              varchar(80) DEFAULT NULL,
  `pejabat`              varchar(120) DEFAULT NULL COMMENT 'PPAT/Notaris/Camat yang menerbitkan',
  `pihak_pertama`        varchar(150) NOT NULL DEFAULT '',
  `pihak_kedua`          varchar(150) NOT NULL DEFAULT '',
  `luas_m2`              decimal(12,2) DEFAULT NULL,
  `nilai_rp`             decimal(16,2) NOT NULL DEFAULT 0.00,
  `dusun_id`             smallint(6) DEFAULT NULL,
  `status`               enum('Diajukan','Verifikasi','Selesai','Ditolak','Dicabut') NOT NULL DEFAULT 'Diajukan',
  `catatan`              text DEFAULT NULL,
  `dicatat_oleh`         int(11) DEFAULT NULL,
  `dibuat_pada`          timestamp NOT NULL DEFAULT current_timestamp(),
  `diubah_pada`          timestamp NOT NULL DEFAULT current_timestamp()
                                     ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_peralihan_kode` (`kode`),
  KEY `idx_peralihan_bidang` (`bidang_id`),
  KEY `idx_peralihan_tanggal` (`tanggal`),
  KEY `idx_peralihan_status` (`status`),
  KEY `idx_peralihan_nop` (`nop`),
  CONSTRAINT `fk_peralihan_bidang` FOREIGN KEY (`bidang_id`)
    REFERENCES `bidang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_peralihan_buku_c` FOREIGN KEY (`buku_c_id`)
    REFERENCES `buku_c` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_peralihan_jenis` FOREIGN KEY (`jenis_peralihan_id`)
    REFERENCES `jenis_peralihan` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_peralihan_dusun` FOREIGN KEY (`dusun_id`)
    REFERENCES `dusun` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_peralihan_pengguna` FOREIGN KEY (`dicatat_oleh`)
    REFERENCES `pengguna` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_peralihan_objek` FOREIGN KEY (`nop`)
    REFERENCES `objek_pajak` (`nop`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11. LAPORAN / SENGKETA
-- ----------------------------------------------------------------------------
CREATE TABLE `laporan` (
  `id`                  int(11) NOT NULL AUTO_INCREMENT,
  `nomor`               varchar(20) NOT NULL,
  `kategori`            enum('Sengketa','Tumpang tindih','Data salah','Peralihan belum tercatat','Lainnya') NOT NULL DEFAULT 'Sengketa',
  `bidang_id`           int(11) DEFAULT NULL,
  `nop`                 char(18) DEFAULT NULL,
  `nama_pelapor`        varchar(100) NOT NULL,
  `telepon`             varchar(20) DEFAULT NULL,
  `keterangan`          text NOT NULL,
  `geom`                point NOT NULL,
  `status`              enum('Menunggu','Diproses','Selesai','Ditolak') NOT NULL DEFAULT 'Menunggu',
  `tanggal_lapor`       timestamp NOT NULL DEFAULT current_timestamp(),
  `tanggal_selesai`     datetime DEFAULT NULL,
  `ditangani_oleh`      int(11) DEFAULT NULL,
  `catatan_penanganan`  text DEFAULT NULL,
  `ip_lapor`            varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_laporan_nomor` (`nomor`),
  KEY `idx_laporan_status` (`status`,`tanggal_lapor`),
  KEY `idx_laporan_bidang` (`bidang_id`),
  SPATIAL KEY `spn_laporan_geom` (`geom`),
  CONSTRAINT `fk_laporan_bidang` FOREIGN KEY (`bidang_id`)
    REFERENCES `bidang` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_laporan_objek` FOREIGN KEY (`nop`)
    REFERENCES `objek_pajak` (`nop`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_laporan_pengguna` FOREIGN KEY (`ditangani_oleh`)
    REFERENCES `pengguna` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SEED MASTER DATA
-- ============================================================================

INSERT INTO `dusun` (`nama`,`kode`,`urutan`) VALUES
  ('DAKAH','DAK',1), ('ROWOGANDU','RWG',2), ('KALIPURU','KLP',3),
  ('GEDONG','GDG',4), ('SABRANG','SBR',5), ('KANDANG','KND',6),
  ('TEMPURAN','TMP',7), ('KREYO','KRY',8), ('WATES','WTS',9),
  ('NGRANDU','NGR',10);

INSERT INTO `jenis_hak` (`kode`,`nama`,`urutan`) VALUES
  ('HM','Hak Milik',1),
  ('HP','Hak Pakai',2),
  ('HGB','Hak Guna Bangunan',3),
  ('HGU','Hak Guna Usaha',4),
  ('BCD','Tercatat dalam Buku C Desa',5),
  ('KDH','Milik Pemerintah Desa / Kas Desa',6),
  ('BLM','Jenis hak tidak tercatat',7),
  ('BLH','Belum difinalisasi',8);

INSERT INTO `jenis_penggunaan` (`nama`) VALUES
  ('Pertanian (Sawah)'), ('Pertanian (Kebun / Tegal)'), ('Pemukiman'),
  ('Perdagangan / Jasa'), ('Perkantoran'), ('Industrial / Pabrik'),
  ('Fasilitas Umum'), ('Sarana Olahraga'), ('Pekarangan'), ('Belum diketahui');

INSERT INTO `jenis_peralihan` (`nama`) VALUES
  ('Jual Beli'), ('Waris'), ('Hibah'), ('Hibah Wasiat'), ('Tukar Menukar'),
  ('Peralihan Nama'), ('Apresiasi / Lainnya');

INSERT INTO `setelan` (`kunci`,`nilai`,`keterangan`) VALUES
  ('skema_versi','1','Versi skema basis data'),
  ('nama_sistem','SIPETADES','Nama sistem di antarmuka'),
  ('nama_desa','Gedong','Nama desa'),
  ('nama_kecamatan','Patean','Kecamatan'),
  ('nama_kabupaten','Kendal','Kabupaten'),
  ('nama_provinsi','Jawa Tengah','Provinsi'),
  ('kode_wilayah_nop','3324040008','10 digit awal NOP yang valid untuk Desa Gedong'),
  ('peta_lat_pusat','-7.0872','Koordinat pusat awal peta'),
  ('peta_lon_pusat','110.0693','Koordinat pusat awal peta'),
  ('peta_zoom_awal','15','Zoom awal peta'),
  ('tahun_pbb_aktif','2026','Tahun pajak yang ditampilkan pertama kali'),
  ('rapat_konfirmasi','1','1 = bidang tanpa jenis hak butuh konfirmasi lapangan'),
  ('batas_unggah_pdf_MB','25','Batas ukuran berkas pendukung laporan');

COMMIT;
SET autocommit = 1;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- VIEW AGREGASI
-- ============================================================================

DROP VIEW IF EXISTS `v_statistik_pbb`;
CREATE VIEW `v_statistik_pbb` AS
SELECT
  s.tahun_pajak                                            AS tahun,
  COUNT(*)                                                 AS total_objek,
  SUM(s.pajak_ditetapkan)                                  AS total_ketetapan,
  SUM(LEAST(s.terbayar, s.pajak_ditetapkan))               AS total_terbayar,
  SUM(GREATEST(s.sisa, 0))                                 AS total_piutang,
  SUM(s.status_bayar = 'Lunas')                            AS jml_lunas,
  SUM(s.status_bayar <> 'Lunas')                           AS jml_belum,
  ROUND(100 * SUM(LEAST(s.terbayar, s.pajak_ditetapkan))
        / NULLIF(SUM(s.pajak_ditetapkan), 0), 2)           AS persen_realisasi
FROM `sppt` s
GROUP BY s.tahun_pajak;

DROP VIEW IF EXISTS `v_rekap_dusun`;
CREATE VIEW `v_rekap_dusun` AS
SELECT
  s.tahun_pajak                                            AS tahun,
  o.dusun_id,
  d.nama                                                   AS dusun,
  COUNT(*)                                                 AS total_objek,
  SUM(s.pajak_ditetapkan)                                  AS ketetapan,
  SUM(LEAST(s.terbayar, s.pajak_ditetapkan))               AS terbayar,
  SUM(GREATEST(s.sisa, 0))                                 AS piutang,
  SUM(s.status_bayar = 'Lunas')                            AS jml_lunas,
  ROUND(100 * SUM(LEAST(s.terbayar, s.pajak_ditetapkan))
        / NULLIF(SUM(s.pajak_ditetapkan), 0), 2)           AS persen_realisasi
FROM `sppt` s
JOIN `objek_pajak` o ON o.nop = s.nop
LEFT JOIN `dusun` d ON d.id = o.dusun_id
GROUP BY s.tahun_pajak, o.dusun_id, d.nama;

-- Catatan: satu bidang bisa memiliki lebih dari satu NOP (satu lahan dipecah
-- ke beberapa wajib pajak), jadi baris v_bidang dapat mengganda bila dipakai
-- untuk daftar. Ambil nop/nama_wp lewat api/bidang.php per bidang bila perlu
-- daftar yang satu baris per bidang.
DROP VIEW IF EXISTS `v_bidang`;
CREATE VIEW `v_bidang` AS
SELECT
  b.id,
  b.kode,
  b.tipe,
  b.nib,
  b.luas_m2,
  b.status,
  b.pusat_lon,
  b.pusat_lat,
  bl.kode      AS blok,
  bl.nomor     AS nomor_blok,
  ds.nama      AS dusun,
  jp.nama      AS jenis_hak,
  COALESCE(pm.nama, h.pemegang_nama) AS pemegang_nama,
  bc.nomor     AS buku_c_nomor,
  h.no_sertifikat,
  h.no_hak_pakai,
  h.diperoleh_pada,
  h.catatan    AS catatan_hak,
  op.nop,
  op.nama_wp,
  di.nama      AS jenis_penggunaan,
  b.diubah_pada
FROM `bidang` b
LEFT JOIN `blok` bl        ON bl.id = b.blok_id
LEFT JOIN `dusun` ds       ON ds.id = b.dusun_id
LEFT JOIN `jenis_penggunaan` di ON di.id = b.jenis_penggunaan_id
LEFT JOIN `hak` h          ON h.bidang_id = b.id
LEFT JOIN `jenis_hak` jp   ON jp.id = h.jenis_hak_id
LEFT JOIN `pemegang` pm    ON pm.id = h.pemegang_id
LEFT JOIN `buku_c` bc      ON bc.id = h.buku_c_id
LEFT JOIN `objek_pajak` op ON op.bidang_id = b.id;

DROP VIEW IF EXISTS `v_ringkasan_sistem`;
CREATE VIEW `v_ringkasan_sistem` AS
SELECT
  (SELECT COUNT(*) FROM `bidang`)                        AS jumlah_bidang,
  (SELECT COUNT(*) FROM `bidang` WHERE tipe = 'tanah')   AS jumlah_bidang_tanah,
  (SELECT COUNT(*) FROM `blok`)                          AS jumlah_blok,
  (SELECT COUNT(*) FROM `objek_pajak`)                   AS jumlah_objek_pajak,
  (SELECT COUNT(*) FROM `buku_c`)                        AS jumlah_buku_c,
  (SELECT COUNT(*) FROM `hak`)                           AS jumlah_hak_tercatat,
  (SELECT COUNT(*) FROM `peralihan`)                     AS jumlah_peralihan,
  (SELECT COUNT(*) FROM `laporan` WHERE status IN ('Menunggu','Diproses')) AS laporan_aktif,
  (SELECT COUNT(*) FROM `pengguna` WHERE is_active = 1)  AS pengguna_aktif;
