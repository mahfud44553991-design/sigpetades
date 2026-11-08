<?php
declare(strict_types=1);

/**
 * Mesin halaman Pengaturan: cadangan basis data, ekspor/impor berkas JSON, dan
 * pengosongan data berkelompok.
 *
 * Dua aturan yang berlaku di seluruh berkas ini:
 *   1. Setiap tindakan merusak punya dua langkah — uji-kering lebih dulu, baru
 *      `terapkan`. Tidak ada tombol yang langsung menulis.
 *   2. Tidak ada satu pun jalur tulis yang jalan tanpa cadangan .sql berhasil
 *      ditulis lebih dulu. Kalau cadangan gagal, tindakannya dibatalkan.
 *
 * Nama tabel dan kolom tidak bisa lewat placeholder, jadi semuanya dibaca dari
 * information_schema dan divalidasi ulang sebelum disisipkan ke SQL.
 */

/** Kelompok yang boleh dikosongkan. Master data dan akun sengaja tidak ada di sini. */
const KELOMPOK_KOSONG = [
    'pbb' => [
        'nama' => 'PBB: objek pajak, SPPT, pembayaran',
        'tabel' => ['pembayaran', 'sppt', 'objek_pajak'],
    ],
    'pertanahan' => [
        'nama' => 'Bidang tanah dan hak atas tanah',
        'tabel' => ['hak', 'pemegang', 'bidang'],
        'peringatan' => 'Seluruh hasil penjajaran garis, penautan NOP, dan nama pemegang ikut hilang.',
    ],
    'buku' => [
        'nama' => 'Buku C dan riwayat peralihan',
        'tabel' => ['buku_c', 'peralihan'],
    ],
    'laporan' => [
        'nama' => 'Laporan warga',
        'tabel' => ['laporan'],
    ],
    'blok' => [
        'nama' => 'Blok peta (garis luar dan anggotanya)',
        'tabel' => ['blok'],
        'peringatan' => 'Batas 19 blok hasil MASTER.dxf ikut terhapus. Mengosongkan blok tanpa mengosongkan bidang membuat semua bidang kehilangan bloknya (NULL).',
    ],
    'audit' => [
        'nama' => 'Jejak audit',
        'tabel' => ['audit_log'],
        'peringatan' => 'Bukti perubahan yang sudah tercatat hilang. Jangan dikosongkan kecuali diminta.',
    ],
];

/** Tabel yang di luar jangkauan halaman ini: master data dan akun. */
const TABEL_LUAR_JANGKAUAN = ['dusun', 'jenis_hak', 'jenis_penggunaan', 'jenis_peralihan', 'setelan', 'pengguna'];

// ---------------------------------------------------------------------------
// Sarana
// ---------------------------------------------------------------------------

/** Folder kerja di bawah storage/, dibuat bila belum ada. Namanya dari daftar tetap. */
function pengaturan_folder(string $bagian): string
{
    if (!in_array($bagian, ['cadangan', 'ekspor', 'impor'], true)) {
        throw new RuntimeException('Bagian penyimpanan tidak dikenal.');
    }
    $path = SIP_ROOT . '/storage/' . $bagian;
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Folder tidak bisa dibuat: ' . $path);
    }
    if (!is_writable($path)) {
        throw new RuntimeException('Folder tidak bisa ditulis: ' . $path);
    }
    return $path;
}

/**
 * Kunci sebuah nama berkas yang datang dari pengguna ke dalam satu folder.
 * Tanpa ini, "?unduh=../../app/config.php" akan mengeluarkan isi konfigurasi.
 */
function pengaturan_aman(string $folder, string $nama, string $akhiran): string
{
    $bersih = basename(str_replace('\\', '/', trim($nama)));
    if ($bersih === '' || !preg_match('/^[A-Za-z0-9._-]+\.' . preg_quote($akhiran, '/') . '$/', $bersih)) {
        throw new RuntimeException('Nama berkas tidak sah.');
    }
    $path = realpath($folder . '/' . $bersih);
    $akar = realpath($folder);
    if ($path === false || $akar === false || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $akar) . '/')) {
        throw new RuntimeException('Berkas tidak ditemukan di dalam folder cadangan.');
    }
    return $path;
}

/** Lokasi mysqldump.exe / mysql.exe XAMPP, atau null bila tidak ketemu. */
function pengaturan_binari(string $nama): ?string
{
    if (!preg_match('/^(mysqldump|mysql)$/', $nama)) {
        throw new RuntimeException('Perintah MySQL tidak dikenal.');
    }
    $kandidat = [];
    if (($bin = (string) sip_config('db.bin', '')) !== '') {
        $kandidat[] = rtrim($bin, '/\\') . '/' . $nama . '.exe';
    }
    foreach (['D:/xampp', 'C:/xampp', 'D:/laragon/bin/mysql', 'C:/laragon/bin/mysql'] as $akar) {
        $kandidat[] = $akar . '/bin/' . $nama . '.exe';
        $kandidat[] = $akar . '/mysql/bin/' . $nama . '.exe';
    }
    foreach ($kandidat as $kandidatPath) {
        if (is_file($kandidatPath)) {
            return str_replace('/', '\\', $kandidatPath);
        }
    }
    return null;
}

/**
 * Berkas kredensial sementara. Sandi tidak pernah ditulis ke baris perintah,
 * karena di Windows proses yang sedang berjalan bisa dibaca siapa pun.
 * @return array{0: string, 1: callable} path dan penutupnya
 */
function pengaturan_kredensial(): array
{
    $cfg = sip_config('db');
    $path = tempnam(sys_get_temp_dir(), 'sip-cnf-');
    if ($path === false) {
        throw new RuntimeException('Berkas kredensial sementara tidak bisa dibuat.');
    }
    $isi = "[client]\n"
        . 'host=' . str_replace("\n", '', (string) $cfg['host']) . "\n"
        . 'port=' . (int) $cfg['port'] . "\n"
        . 'user=' . str_replace("\n", '', (string) $cfg['user']) . "\n"
        . 'password="' . str_replace(["\n", '"'], '', (string) ($cfg['pass'] ?? '')) . "\"\n";
    file_put_contents($path, $isi);
    @chmod($path, 0600);

    return [$path, static function () use ($path): void {
        if (is_file($path)) {
            @unlink($path);
        }
    }];
}

/** Daftar tabel basis (tanpa view), urut nama. View tidak ikut dicadangkan lewat JSON. */
function pengaturan_tabel_basis(): array
{
    $nama = array_map(
        static fn(array $b): string => (string) $b['TABLE_NAME'],
        db_all(
            "SELECT TABLE_NAME FROM information_schema.tables
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
           ORDER BY TABLE_NAME"
        )
    );
    foreach ($nama as $tabel) {
        if (!preg_match('/^[a-z0-9_]+$/i', $tabel)) {
            throw new RuntimeException('Nama tabel tidak bisa dipakai: ' . $tabel);
        }
    }
    return $nama;
}

/**
 * Kolom satu tabel, dipisah menjadi kolom biasa dan kolom ruang (nama => tipe),
 * karena yang kedua tidak bisa diperlakukan sebagai teks.
 * @return array{0: string[], 1: array<string,string>}
 */
function pengaturan_kolom(string $tabel): array
{
    if (!preg_match('/^[a-z0-9_]+$/i', $tabel)) {
        throw new RuntimeException('Nama tabel tidak bisa dipakai: ' . $tabel);
    }
    $baris = db_all(
        'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        [$tabel]
    );
    if ($baris === []) {
        throw new RuntimeException('Tabel tidak ditemukan: ' . $tabel);
    }

    $biasa = [];
    $ruang = [];
    foreach ($baris as $b) {
        $nama = (string) $b['COLUMN_NAME'];
        $tipe = strtolower((string) $b['DATA_TYPE']);
        if (!preg_match('/^[a-z0-9_]+$/i', $nama)) {
            throw new RuntimeException('Nama kolom tidak bisa dipakai: ' . $nama);
        }
        if (in_array($tipe, ['geometry', 'point', 'linestring', 'polygon', 'multipoint',
                             'multilinestring', 'multipolygon', 'geometrycollection'], true)) {
            $ruang[$nama] = $tipe;
            continue;
        }
        if (in_array($tipe, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'], true)) {
            throw new RuntimeException("Tabel {$tabel} punya kolom biner non-ruang ({$nama}); ekspor JSON belum mendukungnya.");
        }
        $biasa[] = $nama;
    }

    return [$biasa, $ruang];
}

function pengaturan_jumlah(string $tabel): int
{
    return (int) db_value("SELECT COUNT(*) FROM `{$tabel}`");
}

// ---------------------------------------------------------------------------
// Cadangan
// ---------------------------------------------------------------------------

/** Nama berkas cadangan selalu dibuat di sini, tidak pernah dari input pengguna. */
function cadangan_nama(string $merek): string
{
    return $merek . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sql';
}

/**
 * mysqldump ke storage/cadangan/. Mengembalikan nama, ukuran, dan jumlah tabel.
 */
function cadangan_buat(string $merek = 'cadangan', ?array $tabel = null): array
{
    $bin = pengaturan_binari('mysqldump');
    if ($bin === null) {
        throw new RuntimeException('mysqldump.exe tidak ditemukan. Isi "bin" pada bagian db di app/config.php.');
    }

    [$cnf, $bersihkan] = pengaturan_kredensial();
    try {
        $nama = cadangan_nama($merek);
        $path = pengaturan_folder('cadangan') . '/' . $nama;
        $argumen = [
            '--defaults-extra-file=' . $cnf,
            '--no-tablespaces',
            '--single-transaction',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
        ];
        // --defaults-extra-file harus argumen pertama, jadi nama file ditaruh setelahnya.
        $perintah = escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $argumen))
            . ' ' . escapeshellarg((string) sip_config('db.name'))
            . ($tabel === null ? '' : ' ' . implode(' ', array_map(
                static function (string $t): string {
                    if (!preg_match('/^[a-z0-9_]+$/i', $t)) {
                        throw new RuntimeException('Nama tabel tidak bisa dipakai: ' . $t);
                    }
                    return escapeshellarg($t);
                },
                $tabel
            )))
            . ' > ' . escapeshellarg($path);

        $keluar = [];
        $kode = 0;
        exec($perintah . ' 2>&1', $keluar, $kode);
        if ($kode !== 0 || !is_file($path) || filesize($path) < 200) {
            if (is_file($path)) {
                @unlink($path);
            }
            throw new RuntimeException('Cadangan gagal dibuat (kode ' . $kode . '): '
                . mb_substr(implode(' ', $keluar), 0, 300));
        }

        return ['nama' => $nama, 'path' => $path, 'byte' => (int) filesize($path),
                'tabel' => $tabel === null ? count(pengaturan_tabel_basis()) : count($tabel)];
    } finally {
        $bersihkan();
    }
}

/** Isi storage/cadangan/, terbaru lebih dulu. */
function cadangan_daftar(): array
{
    $folder = pengaturan_folder('cadangan');
    $hasil = [];
    foreach ((array) glob($folder . '/*.sql') as $path) {
        $hasil[] = [
            'nama' => basename($path),
            'byte' => (int) filesize($path),
            'waktu' => (int) filemtime($path),
        ];
    }
    usort($hasil, static fn(array $a, array $b): int => $b['waktu'] <=> $a['waktu']);
    return $hasil;
}

/** Jalankan satu berkas cadangan lewat mysql.exe. Menggantikan seluruh isinya. */
function cadangan_pulihkan(string $namaBerkas): array
{
    $bin = pengaturan_binari('mysql');
    if ($bin === null) {
        throw new RuntimeException('mysql.exe tidak ditemukan. Isi "bin" pada bagian db di app/config.php.');
    }
    $path = pengaturan_aman(pengaturan_folder('cadangan'), $namaBerkas, 'sql');
    if (!is_readable($path)) {
        throw new RuntimeException('Berkas cadangan tidak bisa dibaca: ' . basename($path));
    }

    [$cnf, $bersihkan] = pengaturan_kredensial();
    try {
        $perintah = [
            escapeshellarg($bin),
            '--defaults-extra-file=' . escapeshellarg($cnf),
            '--default-character-set=utf8mb4',
            '--force=0',
            escapeshellarg((string) sip_config('db.name')),
        ];
        $proses = proc_open(implode(' ', $perintah), [
            0 => ['file', $path, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipa);
        if (!is_resource($proses)) {
            throw new RuntimeException('Proses pemulihan tidak bisa dijalankan.');
        }
        $galat = (string) stream_get_contents($pipa[2]);
        $keluar = (string) stream_get_contents($pipa[1]);
        // $pipa[0] tidak ikut ditutup: stdin dibuat dari ['file', ..., 'r'],
        // sehingga proc_open tidak mengembalikannya sebagai sumber daya.
        fclose($pipa[1]); fclose($pipa[2]);
        $kode = proc_close($proses);

        if ($kode !== 0) {
            throw new RuntimeException('Pemulihan berhenti: '
                . mb_substr(trim($galat !== '' ? $galat : $keluar), 0, 400));
        }
        return ['nama' => basename($path), 'byte' => (int) filesize($path)];
    } finally {
        $bersihkan();
    }
}

/** Kirim satu berkas ke peramban sebagai unduhan. */
function pengaturan_unduh(string $path, string $mimeType = 'application/sql'): never
{
    set_time_limit(0);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

// ---------------------------------------------------------------------------
// Ekspor JSON
// ---------------------------------------------------------------------------

/**
 * Tulis seluruh basis data ke satu berkas JSON. Berkasnya ditulis dua kali:
 * sekali di memori untuk bagian "tabel" (baris per baris, supaya memorinya tidak
 * ikut membengkak bersama geometri), lalu disalin ke disk bersama kepalanya.
 */
function ekspor_buat(): array
{
    $nama = 'sipetades-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json';
    $path = pengaturan_folder('ekspor') . '/' . $nama;

    $kepala = json_encode([
        'dibuat' => date('c'), 'versi' => 1, 'perangkat' => 'SIPETADES',
        'basis' => (string) sip_config('db.name'), 'desa' => setelan('nama_desa', 'Gedong'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $kepala = substr($kepala, 0, -1) . ',"tabel":';

    $aliran = fopen('php://temp', 'w+b');
    if ($aliran === false) {
        throw new RuntimeException('Berkas sementara ekspor tidak bisa dibuat.');
    }

    $hitung = [];
    $pertama = true;
    try {
        fwrite($aliran, '{');
        foreach (pengaturan_tabel_basis() as $tabel) {
            [$biasa, $ruang] = pengaturan_kolom($tabel);
            $seleksi = array_map(static fn(string $c): string => "`{$c}`", $biasa);
            foreach (array_keys($ruang) as $kolom) {
                $seleksi[] = "HEX(ST_AsWKB(`{$kolom}`)) AS `__wkb__{$kolom}`";
                $seleksi[] = "IFNULL(ST_Srid(`{$kolom}`), 0) AS `__srid__{$kolom}`";
            }
            $statement = db_query('SELECT ' . implode(', ', $seleksi) . " FROM `{$tabel}`");

            fwrite($aliran, $pertama ? '' : ',');
            $pertama = false;
            fwrite($aliran, json_encode((string) $tabel, JSON_UNESCAPED_UNICODE) . ':{"kolom":'
                . json_encode(array_merge($biasa, array_keys($ruang))) . ',"baris":[');

            $jumlah = 0;
            $pisah = '';
            while (($baris = $statement->fetch()) !== false) {
                $objek = [];
                foreach ($biasa as $kolom) {
                    $objek[$kolom] = $baris[$kolom] === null ? null : (string) $baris[$kolom];
                }
                foreach (array_keys($ruang) as $kolom) {
                    $wkb = $baris['__wkb__' . $kolom] ?? null;
                    $objek[$kolom] = ($wkb === null || $wkb === '')
                        ? null
                        : ['wkb' => (string) $wkb, 'srid' => (int) $baris['__srid__' . $kolom]];
                }
                fwrite($aliran, $pisah . json_encode($objek, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $pisah = ',';
                $jumlah++;
            }
            fwrite($aliran, ']}');
            $hitung[$tabel] = $jumlah;
        }

        $akhir = '}}';
        fwrite($aliran, $akhir);
        rewind($aliran);

        $tuju = fopen($path, 'wb');
        if ($tuju === false) {
            throw new RuntimeException('Berkas ekspor tidak bisa dibuat: ' . $path);
        }
        fwrite($tuju, $kepala);
        stream_copy_to_stream($aliran, $tuju);
        fclose($tuju);
    } finally {
        fclose($aliran);
    }

    return ['nama' => $nama, 'path' => $path, 'byte' => (int) filesize($path), 'tabel' => $hitung];
}

function ekspor_daftar(): array
{
    $folder = pengaturan_folder('ekspor');
    $hasil = [];
    foreach ((array) glob($folder . '/*.json') as $path) {
        $hasil[] = ['nama' => basename($path), 'byte' => (int) filesize($path), 'waktu' => (int) filemtime($path)];
    }
    usort($hasil, static fn(array $a, array $b): int => $b['waktu'] <=> $a['waktu']);
    return $hasil;
}

// ---------------------------------------------------------------------------
// Impor JSON
// ---------------------------------------------------------------------------

/**
 * Baca dan nilai berkas ekspor. Berkas yang tidak dikenal ditolak di sini,
 * sebelum ada satu pun pertanyaan "yakin" ditampilkan.
 */
function impor_baca(string $path): array
{
    $mentah = file_get_contents($path);
    if ($mentah === false) {
        throw new RuntimeException('Berkas tidak bisa dibaca.');
    }
    $isi = json_decode($mentah, true);
    if (!is_array($isi) || !isset($isi['versi'], $isi['tabel']) || (int) $isi['versi'] !== 1) {
        throw new RuntimeException('Berkas ini bukan hasil ekspor SIPETADES: JSON-nya tidak terbulat '
            . 'atau kunci "versi" 1 tidak ditemukan.');
    }
    if (!is_array($isi['tabel'])) {
        throw new RuntimeException('Bagian "tabel" kosong atau bukan daftar.');
    }

    $kenalan = pengaturan_tabel_basis();
    $dibaca = [];
    foreach ($isi['tabel'] as $tabel => $isinya) {
        if (!preg_match('/^[a-z0-9_]+$/i', (string) $tabel)) {
            throw new RuntimeException('Nama tabel di berkas tidak sah: ' . $tabel);
        }
        if (!in_array($tabel, $kenalan, true)) {
            throw new RuntimeException("Berkas memuat tabel \"{$tabel}\" yang tidak ada di basis data ini.");
        }
        if (!is_array($isinya) || !isset($isinya['kolom'], $isinya['baris']) || !is_array($isinya['baris'])) {
            throw new RuntimeException("Struktur tabel \"{$tabel}\" di berkas tidak dikenali.");
        }
        [$biasa, $ruang] = pengaturan_kolom($tabel);
        $boleh = array_merge($biasa, array_keys($ruang));
        foreach ($isinya['kolom'] as $kolom) {
            if (!in_array((string) $kolom, $boleh, true)) {
                throw new RuntimeException("Kolom \"{$kolom}\" pada tabel \"{$tabel}\" tidak ada di basis data ini.");
            }
        }
        $dibaca[$tabel] = ['kolom' => array_map('strval', $isinya['kolom']),
                           'jumlah' => count($isinya['baris']), 'baris' => $isinya['baris']];
    }

    return ['versi' => (int) $isi['versi'], 'dibuat' => (string) ($isi['dibuat'] ?? ''),
            'basis' => (string) ($isi['basis'] ?? ''), 'desa' => (string) ($isi['desa'] ?? ''),
            'tabel' => $dibaca];
}

/** Perbandingan baris berkas vs basis data, untuk layar uji-kering. */
function impor_banding(array $bacaan): array
{
    $hasil = [];
    foreach ($bacaan['tabel'] as $tabel => $isinya) {
        $sekarang = pengaturan_jumlah($tabel);
        $hasil[] = [
            'tabel' => $tabel,
            'berkas' => $isinya['jumlah'],
            'sekarang' => $sekarang,
            'beda' => $isinya['jumlah'] !== $sekarang,
        ];
    }
    return $hasil;
}

/**
 * Ganti isi seluruh tabel yang ada di berkas dengan baris dari berkas.
 * Satu transaksi: kalau satu tabel gagal, tidak ada yang berubah sama sekali.
 */
function impor_terapkan(string $path): array
{
    $bacaan = impor_baca($path);
    $tertulih = [];

    db_transaksi(static function (PDO $pdo) use ($bacaan, &$tertulih): void {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($bacaan['tabel'] as $tabel => $isinya) {
                [$biasa, $ruang] = pengaturan_kolom($tabel);
                $kolomBerkas = $isinya['kolom'];
                $pdo->exec("DELETE FROM `{$tabel}`");
                if ($isinya['baris'] === []) {
                    $tertulih[$tabel] = 0;
                    continue;
                }

                $seleksi = [];
                foreach ($kolomBerkas as $kolom) {
                    $seleksi[] = in_array($kolom, array_keys($ruang), true)
                        ? "ST_GeomFromWKB(UNHEX(?), ?)"
                        : '?';
                }
                $statement = $pdo->prepare(
                    'INSERT INTO `' . $tabel . '` (`' . implode('`, `', $kolomBerkas) . '`) VALUES ('
                    . implode(', ', $seleksi) . ')'
                );

                $jumlah = 0;
                foreach ($isinya['baris'] as $baris) {
                    if (!is_array($baris)) {
                        throw new RuntimeException("Baris ke-" . ($jumlah + 1) . " pada tabel {$tabel} bukan objek.");
                    }
                    $param = [];
                    foreach ($kolomBerkas as $kolom) {
                        $nilai = $baris[$kolom] ?? null;
                        if (in_array($kolom, array_keys($ruang), true)) {
                            if ($nilai === null) {
                                $param[] = null;
                                $param[] = 0;
                                continue;
                            }
                            $wkb = is_array($nilai) ? ($nilai['wkb'] ?? null) : null;
                            $wkb = is_string($wkb) ? $wkb : '';
                            // ctype_xdigit dipakai, bukan preg_match pola berpasangan:
                            // poligon sebesar BLOK 2 membuat pola itu menembus
                            // pcre.backtrack_limit dan geometri sah terbaca rusak.
                            if ($wkb === '' || strlen($wkb) % 2 !== 0 || !ctype_xdigit($wkb)) {
                                throw new RuntimeException("Geometri pada tabel {$tabel} baris ke-"
                                    . ($jumlah + 1) . ' rusak.');
                            }
                            $param[] = $wkb;
                            $param[] = (int) (is_array($nilai) ? ($nilai['srid'] ?? 0) : 0);
                            continue;
                        }
                        $param[] = $nilai === null ? null : (string) $nilai;
                    }
                    $statement->execute($param);
                    $jumlah++;
                }
                $tertulih[$tabel] = $jumlah;
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    });

    return $tertulih;
}

/** Batas unggah yang benar-benar berlaku, untuk ditulis ke MAX_FILE_SIZE. */
function pengaturan_maks_byte(): int
{
    $unggah = (int) ((float) ini_get('upload_max_filesize') * 1048576);
    $post = (int) ((float) ini_get('post_max_size') * 1048576);
    $konfig = (int) ((float) sip_config('unggah.maks_mb', 25) * 1048576);
    $pilihan = array_filter([$unggah, $post, $konfig], static fn(int $n): bool => $n > 0);
    return $pilihan === [] ? 25 * 1048576 : min($pilihan);
}

/** Simpan berkas unggahan ke storage/impor/ dengan nama buatan server. */
function impor_simpan(string $tmp, string $namaAsli): array
{
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Berkas bukan hasil unggahan yang sah.');
    }
    if ((int) filesize($tmp) > pengaturan_maks_byte()) {
        throw new RuntimeException('Berkas lebih besar dari ' . ukuran_berkas(pengaturan_maks_byte()) . '.');
    }
    $bersih = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($namaAsli)) ?? 'berkas.json';
    if (!str_ends_with(strtolower($bersih), '.json')) {
        throw new RuntimeException('Yang diterima hanya berkas .json hasil ekspor.');
    }
    $nama = date('Ymd-His') . '-' . substr(sha1($bersih . microtime()), 0, 8) . '-' . $bersih;
    $path = pengaturan_folder('impor') . '/' . $nama;
    if (!move_uploaded_file($tmp, $path)) {
        throw new RuntimeException('Berkas gagal dipindahkan ke folder penyimpanan.');
    }
    return ['nama' => $nama, 'path' => $path, 'byte' => (int) filesize($path)];
}

// ---------------------------------------------------------------------------
// Impor SQL
// ---------------------------------------------------------------------------

/** Simpan berkas SQL unggahan ke storage/impor/. */
function sql_impor_simpan(string $tmp, string $namaAsli): array
{
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Berkas bukan hasil unggahan yang sah.');
    }
    if ((int) filesize($tmp) > pengaturan_maks_byte()) {
        throw new RuntimeException('Berkas lebih besar dari ' . ukuran_berkas(pengaturan_maks_byte()) . '.');
    }
    $bersih = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($namaAsli)) ?? 'berkas.sql';
    if (!str_ends_with(strtolower($bersih), '.sql')) {
        throw new RuntimeException('Yang diterima hanya berkas .sql.');
    }
    $nama = date('Ymd-His') . '-' . substr(sha1($bersih . microtime()), 0, 8) . '-' . $bersih;
    $path = pengaturan_folder('impor') . '/' . $nama;
    if (!move_uploaded_file($tmp, $path)) {
        throw new RuntimeException('Berkas gagal dipindahkan ke folder penyimpanan.');
    }
    return ['nama' => $nama, 'path' => $path, 'byte' => (int) filesize($path)];
}

/** Baca sekilas isi berkas SQL untuk uji-kering: jumlah statement, tabel yang disentuh. */
function sql_impor_uji(string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException('Berkas SQL tidak bisa dibaca.');
    }
    $mentah = file_get_contents($path);
    if ($mentah === false) {
        throw new RuntimeException('Berkas SQL tidak bisa dibaca.');
    }

    $ukuran = strlen($mentah);
    $baris = substr_count($mentah, "\n") + 1;

    // Ekstrak nama tabel dari statement SQL
    $tabel = [];
    if (preg_match_all('/(?:CREATE\s+TABLE|INSERT\s+INTO|UPDATE|DELETE\s+FROM|DROP\s+TABLE|ALTER\s+TABLE)\s+`?([a-z0-9_]+)`?/i', $mentah, $cocok)) {
        $tabel = array_values(array_unique($cocok[1]));
        sort($tabel);
    }

    // Hitung statement (dipisah oleh ;)
    $statement = 0;
    $bersih = preg_replace('/--[^\n]*|#[^\n]*|\/\*.*?\*\//s', '', $mentah);
    if ($bersih !== null) {
        $statement = substr_count($bersih, ';');
    }

    // Cuplikan 10 baris pertama
    $barisArray = explode("\n", $mentah);
    $cuplikan = implode("\n", array_slice($barisArray, 0, 10));

    return [
        'ukuran' => $ukuran,
        'baris' => $baris,
        'statement' => $statement,
        'tabel' => $tabel,
        'cuplikan' => $cuplikan,
    ];
}

/** Jalankan berkas SQL lewat mysql.exe. Mengembalikan output dan kode exit. */
function sql_impor_terapkan(string $path): array
{
    $bin = pengaturan_binari('mysql');
    if ($bin === null) {
        throw new RuntimeException('mysql.exe tidak ditemukan. Isi "bin" pada bagian db di app/config.php.');
    }
    if (!is_readable($path)) {
        throw new RuntimeException('Berkas SQL tidak bisa dibaca.');
    }

    [$cnf, $bersihkan] = pengaturan_kredensial();
    try {
        $perintah = [
            escapeshellarg($bin),
            '--defaults-extra-file=' . escapeshellarg($cnf),
            '--default-character-set=utf8mb4',
            '--force',
            escapeshellarg((string) sip_config('db.name')),
        ];
        $proses = proc_open(implode(' ', $perintah), [
            0 => ['file', $path, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipa);
        if (!is_resource($proses)) {
            throw new RuntimeException('Proses impor SQL tidak bisa dijalankan.');
        }
        $keluar = (string) stream_get_contents($pipa[1]);
        $galat = (string) stream_get_contents($pipa[2]);
        fclose($pipa[1]);
        fclose($pipa[2]);
        $kode = proc_close($proses);

        return [
            'kode' => $kode,
            'keluar' => trim($keluar),
            'galat' => trim($galat),
            'byte' => (int) filesize($path),
        ];
    } finally {
        $bersihkan();
    }
}

// ---------------------------------------------------------------------------
// Kosongkan data
// ---------------------------------------------------------------------------

/** Jumlah baris tiap kelompok yang akan hilang. */
function kosongkan_hitung(array $kelompok): array
{
    $hasil = [];
    foreach ($kelompok as $kunci) {
        if (!isset(KELOMPOK_KOSONG[$kunci])) {
            throw new RuntimeException('Kelompok data tidak dikenal: ' . $kunci);
        }
        foreach (KELOMPOK_KOSONG[$kunci]['tabel'] as $tabel) {
            $hasil[$tabel] = pengaturan_jumlah($tabel);
        }
    }
    return $hasil;
}

/**
 * Hapus isi kelompok terpilih. Cadangan sudah ditulis lebih dulu oleh pemanggil;
 * kalau belum, fungsi ini menolak.
 */
function kosongkan_jalankan(array $kelompok, string $buktiCadangan): array
{
    if (!is_file($buktiCadangan)) {
        throw new RuntimeException('Cadangan belum dibuat. Pengosongan dibatalkan.');
    }
    $tabel = [];
    foreach ($kelompok as $kunci) {
        if (!isset(KELOMPOK_KOSONG[$kunci])) {
            throw new RuntimeException('Kelompok data tidak dikenal: ' . $kunci);
        }
        foreach (KELOMPOK_KOSONG[$kunci]['tabel'] as $satu) {
            $tabel[$satu] = true;
        }
    }

    $terhapus = [];
    db_transaksi(static function (PDO $pdo) use ($tabel, &$terhapus): void {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (array_keys($tabel) as $nama) {
                if (!preg_match('/^[a-z0-9_]+$/i', $nama)) {
                    throw new RuntimeException('Nama tabel tidak sah: ' . $nama);
                }
                $terhapus[$nama] = $pdo->exec("DELETE FROM `{$nama}`");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    });

    return $terhapus;
}

/** Ringkasan singkat keadaan basis data untuk kepala halaman. */
function pengaturan_ringkasan(): array
{
    $tabel = pengaturan_tabel_basis();
    $byte = 0;
    foreach ($tabel as $nama) {
        $n = db_one(
            'SELECT (data_length + index_length) AS b FROM information_schema.tables
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$nama]
        );
        $byte += (int) ($n['b'] ?? 0);
    }
    return ['tabel' => count($tabel), 'byte' => $byte];
}

function ukuran_berkas(int $byte): string
{
    if ($byte >= 1048576) {
        return number_format($byte / 1048576, 1, ',', '.') . ' MB';
    }
    if ($byte >= 1024) {
        return number_format($byte / 1024, 0, ',', '.') . ' KB';
    }
    return $byte . ' B';
}
