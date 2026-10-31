-- ============================================================================
-- SIPETADES — akun Super Admin pertama (opsional)
-- Lanjutan sql/01_skema.sql. Jalankan HANYA pada basis data yang baru dibuat.
--
--   D:\xampp\mysql\bin\mysql.exe -u root sipetades_gedong < sql/02_akun_awal.sql
--
-- Berkas ini TIDAK berisi kata sandi. Isi dulu @hash di bawah dengan hasil
-- password_hash() milik Anda:
--
--   D:\xampp\php\php.exe -r "echo password_hash('SandiRahasia12345', PASSWORD_DEFAULT);"
--
-- Salin keluaran perintah itu (60 karakter, diawali $2y$) ke SET @hash.
-- Selama @hash kosong, skrip ini sengaja tidak menulis satu baris pun.
--
-- Cara lain yang lebih singkat: buka http://localhost/sipetades/setup.php.
-- Halaman itu hanya aktif selama belum ada akun Super Admin.
-- ============================================================================

SET NAMES utf8mb4;
USE `sipetades_gedong`;

SET @username = 'superadmin';
SET @nama     = 'Administrator Desa';
SET @hash     = '';   -- <-- tempel hash bcrypt di antara tanda kutip

-- Berjalan dua kali tidak berbahaya: kalau sudah ada Super Admin, tidak ada
-- satu baris pun yang ditulis.
INSERT INTO `pengguna`
  (`username`, `password_hash`, `nama_lengkap`, `jabatan`, `peran`, `is_active`)
SELECT @username, @hash, @nama, 'Super Admin', 'super_admin', 1
FROM DUAL
WHERE (@hash LIKE '$2y$%' OR @hash LIKE '$2a$%' OR @hash LIKE '$2b$%')
  AND NOT EXISTS (SELECT 1 FROM `pengguna` WHERE `peran` = 'super_admin');

SELECT CASE
  WHEN EXISTS (SELECT 1 FROM `pengguna` WHERE `peran` = 'super_admin' AND `is_active` = 1)
    THEN 'OK: sudah ada Super Admin aktif. Tidak ada baris yang ditambahkan.'
  WHEN NOT (@hash LIKE '$2y$%' OR @hash LIKE '$2a$%' OR @hash LIKE '$2b$%')
    THEN 'GAGAL: @hash masih kosong atau bukan hash bcrypt. Isi dulu hasil password_hash(), atau buat akun lewat setup.php.'
  ELSE 'GAGAL: akun tidak dibuat. Periksa pesan galat di atas.'
END AS hasil;
