<?php
declare(strict_types=1);

/** Escape untuk HTML. Pakai di setiap output dinamis. */
function e(mixed $nilai): string
{
    return htmlspecialchars((string) $nilai, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape untuk atribut href/src yang berisi path lokal. */
function e_url(string $path): string
{
    return e(str_replace(' ', '%20', $path));
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $pesan, int $status = 400, array $tambahan = []): never
{
    json_response(['status' => 'gagal', 'error' => $pesan] + $tambahan, $status);
}

function json_ok(array $data = [], string $pesan = 'OK'): never
{
    json_response(['status' => 'berhasil', 'pesan' => $pesan] + $data);
}

function redirect(string $halaman): never
{
    header('Location: ' . (str_starts_with($halaman, 'http') ? $halaman : sip_url($halaman)));
    exit;
}

function flash_set(string $kunci, string $pesan): void
{
    $_SESSION['flash'][$kunci] = $pesan;
}

function flash_get(string $kunci): string
{
    $pesan = (string) ($_SESSION['flash'][$kunci] ?? '');
    unset($_SESSION['flash'][$kunci]);
    return $pesan;
}

// ---------------------------------------------------------------------------
// Baca input
// ---------------------------------------------------------------------------

function post_str(string $kunci, string $fallback = ''): string
{
    $nilai = $_POST[$kunci] ?? $fallback;
    return is_scalar($nilai) ? trim((string) $nilai) : $fallback;
}

function post_int(string $kunci, ?int $fallback = null): ?int
{
    $nilai = filter_var($_POST[$kunci] ?? null, FILTER_VALIDATE_INT);
    return $nilai === false ? $fallback : $nilai;
}

function post_float(string $kunci, ?float $fallback = null): ?float
{
    $nilai = filter_var($_POST[$kunci] ?? null, FILTER_VALIDATE_FLOAT);
    return $nilai === false ? $fallback : (float) $nilai;
}

function get_str(string $kunci, string $fallback = ''): string
{
    $nilai = $_GET[$kunci] ?? $fallback;
    return is_scalar($nilai) ? trim((string) $nilai) : $fallback;
}

function get_int(string $kunci, ?int $fallback = null): ?int
{
    $nilai = filter_var($_GET[$kunci] ?? null, FILTER_VALIDATE_INT);
    return $nilai === false ? $fallback : $nilai;
}

/** Panjang teks dalam karakter, bukan byte. Penting untuk nama berdiakritik. */
function len_utf8(?string $teks): int
{
    return mb_strlen((string) $teks, 'UTF-8');
}

function perpanjang(string $teks, int $maks): string
{
    return mb_strimwidth($teks, 0, $maks, '…', 'UTF-8');
}

// ---------------------------------------------------------------------------
// Format tampilan
// ---------------------------------------------------------------------------

function angka(float|int|null $nilai, int $desimal = 0): string
{
    return number_format((float) $nilai, $desimal, ',', '.');
}

function rupiah(float|int|null $nilai): string
{
    return 'Rp ' . number_format((float) $nilai, 0, ',', '.');
}

function tanggal(?string $iso, bool $denganJam = false): string
{
    if ($iso === null || $iso === '' || str_starts_with($iso, '0000')) {
        return '—';
    }
    $waktu = strtotime($iso);
    if ($waktu === false) {
        return '—';
    }
    $bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
              'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    return sprintf('%s %s %s%s',
        date('j', $waktu),
        $bulan[(int) date('n', $waktu)],
        date('Y', $waktu),
        $denganJam ? ' · ' . date('H:i', $waktu) : ''
    );
}

// ---------------------------------------------------------------------------
// Master data & setelan
// ---------------------------------------------------------------------------

function setelan(string $kunci, string $fallback = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT kunci, nilai FROM setelan') as $baris) {
            $cache[$baris['kunci']] = (string) $baris['nilai'];
        }
    }
    return $cache[$kunci] ?? $fallback;
}

function daftar_dusun(): array
{
    static $dusun = null;
    return $dusun ??= db_all('SELECT id, nama FROM dusun ORDER BY urutan, nama');
}

function daftar_jenis_hak(): array
{
    static $jenis = null;
    return $jenis ??= db_all('SELECT id, kode, nama FROM jenis_hak WHERE aktif = 1 ORDER BY urutan');
}

function daftar_jenis_peralihan(): array
{
    static $jenis = null;
    return $jenis ??= db_all('SELECT id, nama FROM jenis_peralihan WHERE aktif = 1 ORDER BY id');
}

function daftar_jenis_penggunaan(): array
{
    static $jenis = null;
    return $jenis ??= db_all('SELECT id, nama FROM jenis_penggunaan WHERE aktif = 1 ORDER BY nama');
}

/**
 * NOP Desa Gedong = 18 digit dan diawali kode wilayah dari setelan.
 * Titik dan spasi dibuang lebih dulu karena sering dipakai orang menyalin
 * dari SPPT cetak (33.24.040.008.002-0001.0).
 */
function normalkan_nop(?string $nop): string
{
    $angka = preg_replace('/\D+/', '', (string) $nop) ?? '';
    return $angka === '' ? '' : $angka;
}

function nop_valid(?string $nop): bool
{
    $angka = normalkan_nop($nop);
    $kode = setelan('kode_wilayah_nop', '3324040008');
    return strlen($angka) === 18 && str_starts_with($angka, $kode);
}

/** Nomor blok peta dari NOP. Digit 12-14 NOP memuat kode blok kelipatan
 * sepuluh: 020 berarti BLOK 2, 260 berarti BLOK 26.
 */
function nomor_blok_dari_nop(?string $nop): ?int
{
    $angka = normalkan_nop($nop);
    if (strlen($angka) !== 18) {
        return null;
    }
    $kode = (int) substr($angka, 11, 3);
    if ($kode <= 0 || $kode % 10 !== 0) {
        return null;
    }
    return intdiv($kode, 10);
}

/** id baris `blok` tempat sebuah NOP berada, atau null bila kodenya kosong. */
function blok_dari_nop(?string $nop): ?int
{
    static $peta = null;
    if ($peta === null) {
        $peta = [];
        foreach (db_all('SELECT id, nomor FROM blok WHERE nomor IS NOT NULL') as $b) {
            $peta[(int) $b['nomor']] = (int) $b['id'];
        }
    }
    $nomor = nomor_blok_dari_nop($nop);
    return $nomor === null ? null : ($peta[$nomor] ?? null);
}

/** Cocokkan teks dusun dari alamat ke tabel dusun. Mengembalikan id atau null. */
function dusun_dari_teks(?string $teks): ?int
{
    static $peta = null;
    if ($peta === null) {
        $peta = [];
        foreach (daftar_dusun() as $d) {
            $peta[strtoupper($d['nama'])] = (int) $d['id'];
        }
    }
    $upper = strtoupper(preg_replace('/\s+/', ' ', (string) $teks) ?? '');
    foreach ($peta as $nama => $id) {
        if (str_contains($upper, $nama)) {
            return $id;
        }
    }
    return null;
}

/** Unsur nama yang tidak dipakai sebagai pembanding: gelar dan singkatan. */
const NAMA_UNSUR_LECIH = [
    'B', 'BIN', 'BINTI', 'BT', 'D', 'DE', 'H', 'HJ', 'SAG', 'S', 'R', 'W', 'AL', 'DARI',
    'S.PD', 'SPD', 'S.H', 'S.E', 'S.SOS', 'M.PDI', 'Drs', 'Hj',
];

/** Kunci pembanding nama orang: huruf besar, tanda baca dibuang, gelar dibuang. */
function kunci_nama(?string $nama): string
{
    static $lecih = null;
    if ($lecih === null) {
        $lecih = array_map(static fn(string $x): string => strtoupper($x), NAMA_UNSUR_LECIH);
    }
    $bersih = strtoupper(preg_replace('/[^A-Z ]/i', ' ', (string) $nama) ?? '');
    $kata = array_values(array_filter(
        preg_split('/\s+/', trim($bersih)) ?: [],
        static fn(string $k): bool => $k !== '' && !in_array($k, $lecih, true)
    ));
    return implode(' ', $kata);
}

/**
 * Skor kecocokan nama pemegang bidang dengan nama wajib pajak, 0-3.
 *
 * Bukan keputusan: angkanya hanya urutan untuk menampilkan calon paling mungkin
 * di paling atas. Petugas tetap yang menautkan. Nama pemegang boleh berisi
 * beberapa orang dipisah garis miring (suami-istri di satu bidang).
 */
function skor_nama(?string $pemegang, ?string $wajibPajak): int
{
    $sasaran = kunci_nama($wajibPajak);
    if ($sasaran === '' || (string) $pemegang === '') {
        return 0;
    }
    $tertinggi = 0;
    foreach (preg_split('#/#', (string) $pemegang) ?: [] as $satu) {
        $kunci = kunci_nama($satu);
        if ($kunci === '') {
            continue;
        }
        if ($kunci === $sasaran) {
            return 3;
        }
        if (str_contains($sasaran, $kunci) || str_contains($kunci, $sasaran)) {
            $tertinggi = max($tertinggi, 2);
            continue;
        }
        $kataA = array_filter(explode(' ', $kunci), static fn(string $k): bool => strlen($k) > 2);
        $kataB = array_filter(explode(' ', $sasaran), static fn(string $k): bool => strlen($k) > 2);
        if (array_intersect($kataA, $kataB) !== []) {
            $tertinggi = max($tertinggi, 1);
        }
    }
    return $tertinggi;
}

/** Kata yang di peta master menandai tempat, bukan orang. */
const NAMA_LAYAK_TOLAK = ['MASJID', 'MUSHOLA', 'MUSHALLA', 'MUSALA', 'LANGGAR', 'SEKOLAH', 'MCK',
    'GORO', 'SALURAN', 'KUBUR', 'RTH', 'PARKIR', 'SAWAH', 'KOSONG', 'BELUM', 'DAFTAR', 'UMUM',
    'FASILIT', 'TELAGA', 'IRIGASI', 'BRKI',
    // anotasi milik petugas yang ikut tertulis di lapisan tn_nama
    'JALAN', 'TANAH DESA', 'BALAI', 'PECAH', 'BENGKOK', 'KADUS', 'KAUR'];

/**
 * Apakah label peta boleh dianggap nama orang? Teks seperti "MUSHOLA" dan "AA"
 * memang tertulis di lapisan tn_nama, tapi bukan nama pemegang.
 */
function namaLayak(?string $label): bool
{
    $kunci = kunci_nama($label);
    if ($kunci === '') {
        return false;
    }
    foreach (NAMA_LAYAK_TOLAK as $kata) {
        if (str_contains($kunci, $kata)) {
            return false;
        }
    }
    // satu huruf yang diulang: AA, AAAA, X
    return !preg_match('/^([A-Z])\1*$/', str_replace(' ', '', $kunci));
}

// ---------------------------------------------------------------------------
// Audit
// ---------------------------------------------------------------------------
/**
 * Buang data pribadi dari keluaran yang boleh dibaca tanpa masuk.
 *
 * Bekerja pada dua bentuk: baris biasa (daftar) dan Feature GeoJSON
 * (`properties`). Bentuk geometri dan atribut fisik (kode, luas, hak,
 * penggunaan, blok) tetap terbuka; nilai pada `$kunci` diganti "tersimpan"
 * mengikuti perlakuan nomor telepon di daftar laporan, dan `pbb` dikosongkan.
 */
function sembunyikan_pribadi(array $baris, array $kunci): array
{
    foreach ($baris as &$satu) {
        $fitur = array_key_exists('properties', $satu);
        $isi = $fitur ? $satu['properties'] : $satu;

        foreach ($kunci as $k) {
            if (array_key_exists($k, $isi) && $isi[$k] !== null && $isi[$k] !== '') {
                $isi[$k] = 'tersimpan';
            }
        }
        // Status pembayaran menempel pada NOP, jadi ikut ditutup.
        foreach (['pbb', 'status_bayar'] as $k) {
            if (array_key_exists($k, $isi)) {
                $isi[$k] = null;
            }
        }

        if ($fitur) {
            $satu['properties'] = $isi;
        } else {
            $satu = $isi;
        }
    }
    unset($satu);

    return $baris;
}

function audit(string $aksi, string $objek, string|int $objekId, string $keterangan = '', ?array $sebelum = null, ?array $sesudah = null): void
{
    try {
        db_exec(
            'INSERT INTO audit_log
                (pengguna_id, aksi, objek, objek_id, keterangan, data_sebelum, data_sesudah, ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                auth_user_id(),
                $aksi,
                mb_substr($objek, 0, 60),
                mb_substr((string) $objekId, 0, 64),
                $keterangan === '' ? null : mb_substr($keterangan, 0, 4000),
                $sebelum === null ? null : json_encode($sebelum, JSON_UNESCAPED_UNICODE),
                $sesudah === null ? null : json_encode($sesudah, JSON_UNESCAPED_UNICODE),
                ip_terkini(),
                mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
    } catch (Throwable $error) {
        // Audit tidak boleh menggagalkan transaksi utama, tapi harus terlihat.
        error_log('SIPETADES audit gagal dicatat: ' . $error->getMessage());
    }
}

function ip_terkini(): string
{
    return mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Jalankan beberapa query dalam satu transaksi. */
function db_transaksi(callable $kerja): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $kerja($pdo);
    }
    $pdo->beginTransaction();
    try {
        $hasil = $kerja($pdo);
        $pdo->commit();
        return $hasil;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Nomor transaksi otomatis per tahun, mis. TRX-2026-014.
 * Nama tabel dan kolom tidak bisa lewat placeholder, jadi divalidasi
 * sebagai identifier dulu.
 */
function nomor_berikutnya(string $tabel, string $kolom, string $awalan): string
{
    foreach ([$tabel, $kolom] as $identifier) {
        if (!preg_match('/^[a-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Nama tabel/kolom tidak valid: ' . $identifier);
        }
    }

    $nilai = db_value(
        "SELECT MAX(CAST(SUBSTRING(`{$kolom}`, LENGTH(:awalan) + 1) AS UNSIGNED)) FROM `{$tabel}` WHERE `{$kolom}` LIKE :pola",
        ['awalan' => $awalan, 'pola' => $awalan . '%']
    );
    return $awalan . str_pad((string) (((int) $nilai) + 1), 4, '0', STR_PAD_LEFT);
}

/** Batasi nilai LIMIT supaya tidak pernah disisipkan mentah ke SQL. */
function batas_halaman(?int $halaman, ?int $perHalaman, int $maks = 200): array
{
    $perHalaman = max(1, min((int) ($perHalaman ?: 25), $maks));
    $halaman = max(1, (int) ($halaman ?: 1));
    return [$halaman, $perHalaman, ($halaman - 1) * $perHalaman];
}

/**
 * Tautan halaman untuk tabel yang dipecah. Parameternya dipertahankan agar
 * saringan aktif tidak hilang ketika berpindah halaman.
 *
 * @param array<string,scalar|null> $query kondisi saat ini tanpa 'halaman'
 */
function paginasi_html(int $halaman, int $total, int $perHalaman, array $query, string $berkas): string
{
    $jumlahHalaman = (int) ceil($total / max(1, $perHalaman));
    if ($jumlahHalaman <= 1) {
        return '<p class="paginasi"><span>' . e(angka($total)) . ' baris.</span></p>';
    }

    $nomorHalaman = static function (int $nomor) use ($query, $berkas): string {
        return sip_url($berkas, $query + ['halaman' => $nomor]);
    };

    $kiri = $halaman > 1 ? '<a class="tombol tombol-kecil" href="' . e($nomorHalaman($halaman - 1)) . '">Sebelumnya</a>' : '';
    $kanan = $halaman < $jumlahHalaman ? '<a class="tombol tombol-kecil" href="' . e($nomorHalaman($halaman + 1)) . '">Berikutnya</a>' : '';

    $awal = max(1, min($halaman - 2, $jumlahHalaman - 4));
    $akhir = min($jumlahHalaman, $awal + 4);
    $tautan = '';
    for ($i = $awal; $i <= $akhir; $i++) {
        $tautan .= $i === $halaman
            ? '<span class="lencana lencana-hijau">' . e($i) . '</span>'
            : '<a class="tombol tombol-kecil" href="' . e($nomorHalaman($i)) . '">' . e($i) . '</a>';
    }

    return '<p class="paginasi"><span>Halaman ' . e($halaman) . ' dari ' . e($jumlahHalaman)
        . ' &middot; ' . e(angka($total)) . ' baris</span>'
        . '<span class="baris-tombol">' . $kiri . $tautan . $kanan . '</span></p>';
}
