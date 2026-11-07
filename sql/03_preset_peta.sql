-- ============================================================================
-- SIPETADES — tabel preset tampilan peta
-- Lanjutan sql/01_skema.sql. Aman dijalankan berulang (IF NOT EXISTS).
--
--   D:\xampp\mysql\bin\mysql.exe -u root sipetades_gedong < sql\03_preset_peta.sql
--
-- Dibutuhkan api/preset.php: satu baris = satu preset tampilan peta (pusat,
-- zoom, saringan, lapisan) yang disimpan api/preset.php dan dimuat kembali
-- lewat daftar "Preset tampilan" di peta.php.
-- ============================================================================

SET NAMES utf8mb4;
USE `sipetades_gedong`;

CREATE TABLE IF NOT EXISTS `preset_peta` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `nama`        varchar(120) NOT NULL,
  `data_json`   json NOT NULL,
  `dibuat_oleh` int(11) DEFAULT NULL,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  `diubah_pada` timestamp NOT NULL DEFAULT current_timestamp()
                          ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_preset_nama` (`nama`),
  KEY `idx_preset_pembuat` (`dibuat_oleh`),
  CONSTRAINT `fk_preset_pengguna` FOREIGN KEY (`dibuat_oleh`)
    REFERENCES `pengguna` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;