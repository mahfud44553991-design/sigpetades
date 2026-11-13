<?php
declare(strict_types=1);

/**
 * Bidang baru dari Penanda titik di peta induk 020100.
 *
 *   POST {aksi: "pratinjau", x: <easting DXF>, y: <northing DXF>}
 *   POST {aksi: "garis-baru", x: <easting DXF>, y: <northing DXF>}
 *   POST {aksi: "bersihkan"}
 *   POST {aksi: "simpan", token: "<token pratinjau>"}
 *   POST {aksi: "batch-pratinjau", titik: [[x, y], ...]}
 *   POST {aksi: "batch-simpan", token: "<token pratinjau massal>"}
 *   POST {aksi: "batch-bersihkan"}
 *
 * Pratinjau memilih bidang terkecil yang memuat titik: poligon tertutup
 * 020100, lalu garis bidang tertutup (LineString yang ujungnya bertemu),
 * lalu rangkaian seluruh garis 020100 yang membentuk cincin. Untuk titik di
 * kantong terbuka, "garis-baru" memotong garis pada titik potong lalu
 * menelusuri muka terkecil yang memuat titik, sehingga batasnya mengikuti
 * garis sebelahnya. Geometri selalu dibaca ulang dari berkas layer di server,
 * tidak pernah dari klien.
 *
 * Penurunan geometrinya tinggal di tools/lib-penanda.php supaya alat CLI
 * pembentuk poligon gang dan proses massal memakai rumus yang sama.
 *
 * Proses massal ("batch-*") menurunkan seluruh titik dalam satu permintaan.
 * Sesi hanya menyimpan daftar titiknya, jadi penyimpanan mengulang penurunan
 * yang sama dan geometri yang masuk ke basis data tidak pernah datang dari
 * klien. Satu batch ditulis dalam satu transaksi: satu baris gagal, semua
 * batal.
 */

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../tools/lib-impor.php';
require_once __DIR__ . '/../tools/lib-penanda.php';

set_time_limit(60);

/** Batas titik untuk satu kali pratinjau massal. */
const PENANDA_BATCH_BATAS = 150;

$pengguna = wajib_masuk();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Metode tidak diizinkan.', 405);
}
wajib_csrf();

$masukan = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($masukan)) {
    $masukan = $_POST;
}
$aksi = (string) ($masukan['aksi'] ?? '');

/** Asal geometri, dipakai di catatan bidang dan jejak audit. */
function catatanPenanda(string $jenis, string $handle, float $x, float $y, ?array $blok = null): string
{
    $asal = match ($jenis) {
        'garis' => 'rangkaian garis',
        'garis-baru' => 'garis bidang baru dari garis sekitar ' . ($handle !== '' ? $handle : 'tanpa handle'),
        'garis-tertutup' => 'garis bidang tertutup ' . ($handle !== '' ? $handle : 'tanpa handle'),
        default => 'poligon tertutup ' . ($handle !== '' ? $handle : 'tanpa handle'),
    };
    return sprintf(
        'Dibuat dari penanda X %.2f Y %.2f pada layer peta induk 020100 (%s); perlu verifikasi%s.',
        $x,
        $y,
        $asal,
        $blok !== null ? '; blok ' . $blok['kode'] . ' dari titik pusat bidang' : ' dan penetapan blok'
    );
}

/**
 * Blok yang menaungi titik pusat bidang.
 *
 * Pusat yang dipakai adalah titik yang sama dengan yang disimpan pada baris
 * ini (`pusat_lon`/`pusat_lat`), jadi blok yang tercatat memang blok tempat
 * bidang itu berada menurut basis data. Poligon blok terkecil menang kalau
 * ada yang bertumpuk, dan blok berbatas perkiraan diabaikan supaya bidang
 * baru tidak otomatis masuk ke blok yang batasnya masih dugaan.
 *
 * @param array{lon:?float,lat:?float} $pusat
 * @return array{id:int,kode:string}|null
 */
function blokPusatBidang(?array $pusat): ?array
{
    $lon = $pusat['lon'] ?? null;
    $lat = $pusat['lat'] ?? null;
    if ($lon === null || $lat === null) {
        return null;
    }
    $blok = db_one(
        'SELECT id, kode
           FROM blok
          WHERE geom IS NOT NULL
            AND batas_perkiraan = 0
            AND ST_Contains(geom, POINT(?, ?))
          ORDER BY ST_Area(geom) ASC
          LIMIT 1',
        [(float) $lon, (float) $lat]
    );
    if ($blok === null) {
        return null;
    }
    return ['id' => (int) $blok['id'], 'kode' => (string) $blok['kode']];
}

/** Sisipkan satu bidang hasil penanda beserta hak BLM-nya; mengembalikan id baru. */
function sisipBidangPenanda(PDO $pdo, string $kode, array $siap, string $catatan, int $idJenisHak, ?int $blokId = null): int
{
    $pdo->prepare(
        'INSERT INTO bidang
            (kode, tipe, luas_m2, blok_id, status, sumber, catatan, geom, pusat_lon, pusat_lat)
         VALUES (?, \'tanah\', ?, ?, \'perlu_verifikasi\', \'peta-020100\', ?, ST_GeomFromText(?, 4326), ?, ?)'
    )->execute([
        $kode,
        round((float) $siap['luas'], 2),
        $blokId,
        $catatan,
        $siap['wkt'],
        $siap['pusat']['lon'],
        $siap['pusat']['lat'],
    ]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO hak (bidang_id, jenis_hak_id, catatan) VALUES (?, ?, ?)')
        ->execute([$id, $idJenisHak, 'Jenis hak belum dikonfirmasi; dibuat dari penanda peta induk 020100.']);
    return $id;
}

/**
 * Ambil daftar titik penanda dari masukan: pasangan [x, y] meter DXF.
 * Titik yang jatuh pada tempat sama (pembulatan sentimeter) dibuang supaya
 * satu muka tidak dihitung dua kali.
 *
 * @return array<int, array{0:float,1:float}>
 */
function titikBatch(array $masukan): array
{
    $mentah = $masukan['titik'] ?? null;
    if (!is_array($mentah) || $mentah === []) {
        json_error('Daftar titik penanda kosong.', 422);
    }
    if (count($mentah) > PENANDA_BATCH_BATAS) {
        json_error('Terlalu banyak penanda: maksimal ' . PENANDA_BATCH_BATAS . ' titik untuk satu kali pratinjau.', 422);
    }

    $titik = [];
    $kunci = [];
    foreach ($mentah as $nomor => $pasangan) {
        $x = is_array($pasangan) && isset($pasangan[0]) && is_numeric($pasangan[0]) ? (float) $pasangan[0] : NAN;
        $y = is_array($pasangan) && isset($pasangan[1]) && is_numeric($pasangan[1]) ? (float) $pasangan[1] : NAN;
        if (!is_finite($x) || !is_finite($y)) {
            json_error('Titik ke-' . ((int) $nomor + 1) . ' bukan pasangan koordinat angka.', 422);
        }
        $k = sprintf('%.2F,%.2F', $x, $y);
        if (isset($kunci[$k])) {
            continue;
        }
        $kunci[$k] = true;
        $titik[] = [$x, $y];
    }

    if ($titik === []) {
        json_error('Semua titik penanda duplikat, tidak ada yang perlu diperiksa.', 422);
    }

    return $titik;
}

/**
 * Turunkan geometri untuk tiap titik penanda sekaligus menandai yang kembar.
 *
 * Rangkaian garis 020100 disusun satu kali untuk seluruh daftar; tanpa itu
 * setiap titik membaca ulang dan menyusun ulang seluruh layer.
 *
 * @param array<int, array{0:float,1:float}> $titik
 * @return array{hasil:array<int,array>, bahan:array<int,array>}
 */
function susunBatch(array $titik): array
{
    $konteks = konteksPenandaPetaInduk('020100');

    $hasil = [];
    $bahan = [];
    $lihatKode = [];

    foreach ($titik as $nomor => [$x, $y]) {
        $baris = ['i' => $nomor, 'x' => $x, 'y' => $y];

        $ditemukan = bidangDiTitik($konteks, $x, $y);
        if ($ditemukan === null) {
            $hasil[] = $baris + [
                'status' => 'gagal',
                'pesan' => 'Tidak ada poligon tertutup dan garis di sekitar titik belum membentuk muka bidang.',
            ];
            continue;
        }

        $kode = (string) $ditemukan['kode'];
        $jenis = (string) $ditemukan['jenis'];
        $handle = (string) ($ditemukan['calon']['handle'] ?? '');
        $baris += ['kode' => $kode, 'jenis' => $jenis, 'handle' => $handle];

        if (isset($lihatKode[$kode])) {
            $hasil[] = $baris + [
                'status' => 'kembar',
                'duplikat' => ['kode' => $kode, 'id' => 0, 'alasan' => 'batch'],
                'sumber_i' => $lihatKode[$kode],
            ];
            continue;
        }
        $lihatKode[$kode] = $nomor;

        try {
            $siap = siapkanGeometri($ditemukan['calon']['geometry']);
        } catch (Throwable $error) {
            $hasil[] = $baris + ['status' => 'gagal', 'pesan' => $error->getMessage()];
            continue;
        }

        $baris['luas_m2'] = round((float) $siap['luas'], 2);

        $batas = [INF, INF, -INF, -INF];
        foreach ($ditemukan['calon']['geometry']['coordinates'][0] ?? [] as $pojok) {
            $batas[0] = min($batas[0], (float) $pojok[0]);
            $batas[1] = min($batas[1], (float) $pojok[1]);
            $batas[2] = max($batas[2], (float) $pojok[0]);
            $batas[3] = max($batas[3], (float) $pojok[1]);
        }
        $baris['batas'] = is_finite($batas[0]) ? $batas : [$x, $y, $x, $y];

        $duplikat = duplikatBidang($kode, $siap['wkt']);
        if ($duplikat !== null) {
            $hasil[] = $baris + ['status' => 'kembar', 'duplikat' => $duplikat];
            continue;
        }

        $hasil[] = $baris + [
            'status' => 'baru',
            'geometri' => $ditemukan['calon']['geometry'],
        ];
        $blok = blokPusatBidang($siap['pusat']);
        $bahan[] = $baris + [
            'siap' => $siap,
            'blok' => $blok,
            'catatan' => catatanPenanda($jenis, $handle, $x, $y, $blok),
        ];
    }

    return ['hasil' => $hasil, 'bahan' => $bahan];
}

/** Simpan pratinjau ke sesi lalu kirim jawaban JSON. */
function kirimPratinjau(array $calon, string $jenis, string $kode, float $x, float $y, array $pengguna): void
{
    try {
        $siap = siapkanGeometri($calon['geometry']);
    } catch (Throwable $error) {
        json_error('Geometri calon bidang tidak dapat dipakai: ' . $error->getMessage(), 422);
    }

    $duplikat = duplikatBidang($kode, $siap['wkt']);
    $token = bin2hex(random_bytes(16));
    $_SESSION['penanda_bidang'] = [
        'token' => $token,
        'pengguna_id' => auth_user_id(),
        'waktu' => time(),
        'kode' => $kode,
        'wkt' => $siap['wkt'],
        'luas' => $siap['luas'],
        'pusat' => $siap['pusat'],
        'x' => $x,
        'y' => $y,
        'jenis' => $jenis,
        'handle' => (string) $calon['handle'],
    ];

    json_ok([
        'token' => $token,
        'kode' => $kode,
        'jenis' => $jenis,
        'handle' => (string) $calon['handle'],
        'luas_m2' => round($siap['luas'], 2),
        'duplikat' => $duplikat,
        'boleh_simpan' => $pengguna['peran'] === PERAN_SUPER && $duplikat === null,
        'geometri' => ['type' => 'Polygon', 'coordinates' => $calon['geometry']['coordinates']],
    ], 'Pratinjau bidang siap.');
}

if ($aksi === 'bersihkan') {
    unset($_SESSION['penanda_bidang']);
    json_ok([], 'Pratinjau dibersihkan.');
}

if ($aksi === 'pratinjau') {
    $x = isset($masukan['x']) && is_numeric($masukan['x']) ? (float) $masukan['x'] : NAN;
    $y = isset($masukan['y']) && is_numeric($masukan['y']) ? (float) $masukan['y'] : NAN;
    if (!is_finite($x) || !is_finite($y)) {
        json_error('Koordinat penanda tidak sah.', 422);
    }

    $ditemukan = bidangDariPenanda(fiturLapisanPetaInduk('020100'), $x, $y);
    if ($ditemukan === null) {
        json_error('Penanda tidak berada di dalam poligon tertutup, garis bidang tertutup, atau rangkaian garis 020100 yang menutup. Pakai “Bidang dari garis sekitar” untuk membentuk bidang baru.', 422);
    }

    kirimPratinjau($ditemukan['calon'], $ditemukan['jenis'], $ditemukan['kode'], $x, $y, $pengguna);
}

if ($aksi === 'garis-baru') {
    $x = isset($masukan['x']) && is_numeric($masukan['x']) ? (float) $masukan['x'] : NAN;
    $y = isset($masukan['y']) && is_numeric($masukan['y']) ? (float) $masukan['y'] : NAN;
    if (!is_finite($x) || !is_finite($y)) {
        json_error('Koordinat penanda tidak sah.', 422);
    }

    $calon = mukaGarisDiSekitar(fiturLapisanPetaInduk('020100'), $x, $y);
    if ($calon === null) {
        json_error('Garis 020100 di sekitar penanda belum membentuk muka bidang tertutup. Geser penanda mendekati pertemuan garis.', 422);
    }

    kirimPratinjau($calon, 'garis-baru', kodeBidangGarisBaru($calon['geometry']), $x, $y, $pengguna);
}

if ($aksi === 'simpan') {
    if ($pengguna['peran'] !== PERAN_SUPER) {
        json_error('Hanya Super Admin yang dapat menyimpan bidang baru.', 403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Metode tidak diizinkan.', 405);
    }

    $token = (string) ($masukan['token'] ?? '');
    $pratinjau = $_SESSION['penanda_bidang'] ?? null;
    if (!is_array($pratinjau) || !isset($pratinjau['token']) || $token === ''
        || !hash_equals((string) $pratinjau['token'], $token)) {
        json_error('Pratinjau tidak ditemukan. Ulangi pratinjau bidang.', 410);
    }
    if ((int) $pratinjau['pengguna_id'] !== (int) auth_user_id()) {
        json_error('Pratinjau ini milik pengguna lain. Ulangi pratinjau bidang.', 403);
    }
    if (time() - (int) $pratinjau['waktu'] > 1800) {
        unset($_SESSION['penanda_bidang']);
        json_error('Pratinjau kedaluwarsa. Ulangi pratinjau bidang.', 410);
    }

    $duplikat = duplikatBidang((string) $pratinjau['kode'], (string) $pratinjau['wkt']);
    if ($duplikat !== null) {
        unset($_SESSION['penanda_bidang']);
        json_error('Bidang sudah ada: ' . $duplikat['kode'] . '. Penyimpanan dibatalkan.', 409);
    }

    $idJenisHak = db_value('SELECT id FROM jenis_hak WHERE kode = ?', [JENIS_HAK_DEFAULT]);
    if ($idJenisHak === null) {
        json_error('Jenis hak BLM tidak tersedia. Jalankan sql/01_skema.sql.', 500);
    }

    $blok = blokPusatBidang($pratinjau['pusat'] ?? null);
    $catatan = catatanPenanda(
        (string) $pratinjau['jenis'],
        (string) $pratinjau['handle'],
        (float) $pratinjau['x'],
        (float) $pratinjau['y'],
        $blok
    );

    $id = db_transaksi(static function (PDO $pdo) use ($pratinjau, $idJenisHak, $catatan, $blok): int {
        return sisipBidangPenanda($pdo, (string) $pratinjau['kode'], [
            'wkt' => (string) $pratinjau['wkt'],
            'luas' => (float) $pratinjau['luas'],
            'pusat' => $pratinjau['pusat'],
        ], $catatan, (int) $idJenisHak, $blok['id'] ?? null);
    });

    unset($_SESSION['penanda_bidang']);
    audit('buat', 'bidang', (string) $id, 'Buat bidang dari penanda peta induk 020100: ' . $pratinjau['kode'], null, [
        'kode' => $pratinjau['kode'],
        'lapisan' => '020100',
        'jenis' => $pratinjau['jenis'],
        'handle' => $pratinjau['handle'],
        'penanda' => ['x' => $pratinjau['x'], 'y' => $pratinjau['y']],
        'luas_m2' => round((float) $pratinjau['luas'], 2),
        'blok' => $blok['kode'] ?? null,
    ]);

    json_ok(
        ['id' => $id, 'kode' => $pratinjau['kode'], 'blok' => $blok['kode'] ?? null],
        'Bidang baru disimpan sebagai perlu verifikasi'
            . ($blok !== null ? ' di ' . $blok['kode'] : '') . '.'
    );
}

if ($aksi === 'batch-bersihkan') {
    unset($_SESSION['penanda_batch']);
    json_ok([], 'Pratinjau massal dibersihkan.');
}

if ($aksi === 'batch-pratinjau') {
    set_time_limit(300);
    $jumlahMasuk = is_array($masukan['titik'] ?? null) ? count($masukan['titik']) : 0;
    $titik = titikBatch($masukan);
    $susun = susunBatch($titik);

    $hitung = ['baru' => 0, 'kembar' => 0, 'gagal' => 0];
    foreach ($susun['hasil'] as $baris) {
        $hitung[$baris['status']]++;
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION['penanda_batch'] = [
        'token' => $token,
        'pengguna_id' => auth_user_id(),
        'waktu' => time(),
        'titik' => $titik,
    ];

    json_ok([
        'token' => $token,
        'jumlah' => count($susun['hasil']),
        'terpangkas' => $jumlahMasuk - count($titik),
        'baru' => $hitung['baru'],
        'kembar' => $hitung['kembar'],
        'gagal' => $hitung['gagal'],
        'batas' => PENANDA_BATCH_BATAS,
        'boleh_simpan' => $pengguna['peran'] === PERAN_SUPER && $hitung['baru'] > 0,
        'hasil' => $susun['hasil'],
    ], sprintf(
        'Pratinjau %d penanda: %d bidang baru, %d kembar, %d gagal.',
        count($susun['hasil']),
        $hitung['baru'],
        $hitung['kembar'],
        $hitung['gagal']
    ));
}

if ($aksi === 'batch-simpan') {
    if ($pengguna['peran'] !== PERAN_SUPER) {
        json_error('Hanya Super Admin yang dapat menyimpan bidang baru.', 403);
    }
    set_time_limit(600);

    $token = (string) ($masukan['token'] ?? '');
    $pratinjau = $_SESSION['penanda_batch'] ?? null;
    if (!is_array($pratinjau) || !isset($pratinjau['token']) || $token === ''
        || !hash_equals((string) $pratinjau['token'], $token)) {
        json_error('Pratinjau massal tidak ditemukan. Ulangi pratinjau bidang.', 410);
    }
    if ((int) $pratinjau['pengguna_id'] !== (int) auth_user_id()) {
        json_error('Pratinjau massal ini milik pengguna lain.', 403);
    }
    if (time() - (int) $pratinjau['waktu'] > 1800) {
        unset($_SESSION['penanda_batch']);
        json_error('Pratinjau massal kedaluwarsa. Ulangi pratinjau bidang.', 410);
    }
    $titik = is_array($pratinjau['titik'] ?? null) ? $pratinjau['titik'] : [];
    if ($titik === [] || count($titik) > PENANDA_BATCH_BATAS) {
        unset($_SESSION['penanda_batch']);
        json_error('Daftar titik pratinjau massal tidak sah. Ulangi pratinjau bidang.', 410);
    }

    $idJenisHak = db_value('SELECT id FROM jenis_hak WHERE kode = ?', [JENIS_HAK_DEFAULT]);
    if ($idJenisHak === null) {
        json_error('Jenis hak BLM tidak tersedia. Jalankan sql/01_skema.sql.', 500);
    }

    // Geometri dihitung ulang dari layer, bukan dari pratinjau klien, lalu
    // kembar diperiksa lagi di dalam transaksi: bidang yang baru saja
    // disisipkan pada batch yang sama ikut terlihat oleh koneksi yang sama.
    $susun = susunBatch($titik);
    $jalan = db_transaksi(static function (PDO $pdo) use ($susun, $idJenisHak): array {
        $dibuat = [];
        $dilewati = [];
        foreach ($susun['bahan'] as $bahan) {
            $duplikat = duplikatBidang($bahan['kode'], $bahan['siap']['wkt']);
            if ($duplikat !== null) {
                $dilewati[] = ['i' => $bahan['i'], 'kode' => $bahan['kode'], 'duplikat' => $duplikat];
                continue;
            }
            $id = sisipBidangPenanda(
                $pdo,
                $bahan['kode'],
                $bahan['siap'],
                $bahan['catatan'],
                (int) $idJenisHak,
                $bahan['blok']['id'] ?? null
            );
            $dibuat[] = [
                'id' => $id,
                'i' => $bahan['i'],
                'kode' => $bahan['kode'],
                'jenis' => $bahan['jenis'],
                'handle' => $bahan['handle'],
                'luas_m2' => $bahan['luas_m2'],
                'blok' => $bahan['blok']['kode'] ?? null,
            ];
        }
        return ['dibuat' => $dibuat, 'dilewati' => $dilewati];
    });

    $gagal = 0;
    foreach ($susun['hasil'] as $baris) {
        if ($baris['status'] === 'gagal') {
            $gagal++;
        }
    }

    unset($_SESSION['penanda_batch']);
    foreach ($jalan['dibuat'] as $bidang) {
        audit('buat', 'bidang', (string) $bidang['id'],
            'Buat bidang dari penanda massal peta induk 020100: ' . $bidang['kode'], null, [
                'kode' => $bidang['kode'],
                'lapisan' => '020100',
                'jenis' => $bidang['jenis'],
                'handle' => $bidang['handle'],
                'penanda' => ['x' => $titik[$bidang['i']][0], 'y' => $titik[$bidang['i']][1]],
                'luas_m2' => $bidang['luas_m2'],
                'blok' => $bidang['blok'],
                'massal' => true,
            ]);
    }

    json_ok([
        'dibuat' => $jalan['dibuat'],
        'dilewati' => $jalan['dilewati'],
        'gagal' => $gagal,
    ], sprintf(
        '%d bidang baru disimpan sebagai perlu verifikasi, %d dilewati.',
        count($jalan['dibuat']),
        count($jalan['dilewati'])
    ));
}

json_error('Aksi tidak dikenal. Gunakan "pratinjau", "garis-baru", "bersihkan", "simpan", '
    . '"batch-pratinjau", "batch-simpan", atau "batch-bersihkan".', 400);
