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
 *   POST {aksi: "batch-gabung", token: "<token pratinjau massal>", pilih: [i, ...]}
 *   POST {aksi: "batch-lepas-gabung", token: "<token pratinjau massal>", pilih: [i, ...]}
 *   POST {aksi: "batch-simpan", token: "<token pratinjau massal>"}
 *   POST {aksi: "batch-bersihkan"}
 *
 * Pratinjau memilih bidang terkecil yang memuat titik: poligon tertutup
 * 020100, lalu garis bidang tertutup (LineString yang ujungnya bertemu),
 * lalu rangkaian seluruh garis 020100 yang membentuk cincin. Titik yang kalah
 * pada ketiganya dicocokkan ke Bidang kadastrum: persil tabel bidang yang
 * memuat titik, lalu muka yang dibatasi garis dan tepi cincin Polygon 020100.
 * Untuk titik di kantong terbuka, "garis-baru" memotong garis pada titik potong
 * lalu menelusuri muka terkecil yang memuat titik, sehingga batasnya mengikuti
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
 *
 * "batch-gabung" menyimpan kelompok nomor penanda di sesi; geometri union
 * dihitung ulang di server dari layer (tools/lib-penanda.php gabungPoligonDxf)
 * saat pratinjau dijawab dan saat menyimpan. Klien hanya mengirim nomor baris.
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
        'kadastrum' => 'Bidang kadastrum ' . ($handle !== '' ? $handle : 'tanpa handle'),
        'garis-tertutup' => 'garis bidang tertutup ' . ($handle !== '' ? $handle : 'tanpa handle'),
        default => 'poligon tertutup ' . ($handle !== '' ? $handle : 'tanpa handle'),
    };
    return sprintf(
        'Dibuat dari penanda X %.2f Y %.2f pada layer peta induk 020100 (%s); perlu verifikasi%s.',
        $x,
        $y,
        $asal,
        $blok !== null ? '; blok ' . blok_tiga_digit($blok['kode']) . ' dari titik pusat bidang' : ' dan penetapan blok'
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

        $ditemukan = bidangDiTitik($konteks, $x, $y, true, true);
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

/**
 * Ambil sesi pratinjau massal yang cocok dengan token, atau hentikan permintaan.
 *
 * @return array<string, mixed>
 */
function sesiBatch(string $token): array
{
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
    return $pratinjau;
}

/** Catatan bidang hasil gabungan beberapa penanda massal. */
function catatanGabung(array $anggota, array $titik, ?array $blok): string
{
    $daftar = [];
    foreach ($anggota as $i) {
        $daftar[] = sprintf('X %.2f Y %.2f', (float) $titik[$i][0], (float) $titik[$i][1]);
    }
    return sprintf(
        'Dibuat dari %d penanda massal yang digabung pada layer peta induk 020100 (%s); perlu verifikasi%s.',
        count($anggota),
        implode(', ', $daftar),
        $blok !== null ? '; blok ' . blok_tiga_digit($blok['kode']) . ' dari titik pusat bidang' : ' dan penetapan blok'
    );
}

/**
 * Terapkan kelompok gabungan pada hasil susunBatch.
 *
 * Setiap kelompok berisi nomor penanda (i) yang barisnya berstatus "baru";
 * barisnya diganti satu baris union, anggota lain dibuang. Kelompok yang
 * anggotanya tidak lagi baru atau yang unionnya tidak menyatu dilewati,
 * sehingga pratinjau tidak pernah menampilkan gabungan yang tidak sah.
 *
 * @return array{hasil:array<int,array>,bahan:array<int,array>,kelompok:array<int,array<int,int>>}
 */
function terapkanGabungBatch(array $susun, array $kelompok, array $titik): array
{
    $posHasil = [];
    foreach ($susun['hasil'] as $pos => $baris) {
        $posHasil[(int) $baris['i']] = $pos;
    }
    $posBahan = [];
    foreach ($susun['bahan'] as $pos => $bahan) {
        $posBahan[(int) $bahan['i']] = $pos;
    }

    $terpakai = [];
    $ganti = [];
    $bahanBaru = [];
    $kelompokSah = [];

    foreach ($kelompok as $mentah) {
        if (!is_array($mentah)) {
            continue;
        }
        $anggota = array_values(array_unique(array_map('intval', $mentah)));
        sort($anggota);
        if (count($anggota) < 2 || !isset($posHasil[$anggota[0]])) {
            continue;
        }

        $geometri = [];
        $sah = true;
        foreach ($anggota as $i) {
            $pos = $posHasil[$i] ?? null;
            if ($pos === null || !isset($posBahan[$i])
                || ($susun['hasil'][$pos]['status'] ?? '') !== 'baru') {
                $sah = false;
                break;
            }
            $geometri[] = $susun['hasil'][$pos]['geometri'];
        }
        if (!$sah) {
            continue;
        }

        try {
            $union = gabungPoligonDxf($geometri);
            if ($union === null) {
                continue;
            }
            $siap = siapkanGeometri($union);
        } catch (Throwable $error) {
            continue;
        }

        $kode = kodeBidangGarisBaru($union);
        $rep = $anggota[0];
        $batas = [INF, INF, -INF, -INF];
        foreach ($union['coordinates'][0] as $pojok) {
            $batas[0] = min($batas[0], (float) $pojok[0]);
            $batas[1] = min($batas[1], (float) $pojok[1]);
            $batas[2] = max($batas[2], (float) $pojok[0]);
            $batas[3] = max($batas[3], (float) $pojok[1]);
        }

        $baris = [
            'i' => $rep,
            'x' => (float) $susun['hasil'][$posHasil[$rep]]['x'],
            'y' => (float) $susun['hasil'][$posHasil[$rep]]['y'],
            'kode' => $kode,
            'jenis' => 'gabungan',
            'handle' => '',
            'luas_m2' => round((float) $siap['luas'], 2),
            'batas' => $batas,
            'gabung' => $anggota,
        ];

        $duplikat = duplikatBidang($kode, $siap['wkt']);
        if ($duplikat !== null) {
            $baris['status'] = 'kembar';
            $baris['duplikat'] = $duplikat;
            $ganti[$posHasil[$rep]] = $baris;
        } else {
            $baris['status'] = 'baru';
            $baris['geometri'] = $union;
            $ganti[$posHasil[$rep]] = $baris;
            $blok = blokPusatBidang($siap['pusat']);
            $bahanBaru[$posHasil[$rep]] = [
                'i' => $rep,
                'x' => $baris['x'],
                'y' => $baris['y'],
                'kode' => $kode,
                'jenis' => 'gabungan',
                'handle' => '',
                'luas_m2' => $baris['luas_m2'],
                'siap' => $siap,
                'blok' => $blok,
                'catatan' => catatanGabung($anggota, $titik, $blok),
                'gabung' => $anggota,
            ];
        }

        foreach ($anggota as $i) {
            $terpakai[$i] = true;
        }
        $kelompokSah[] = $anggota;
    }

    $hasil = [];
    foreach ($susun['hasil'] as $pos => $baris) {
        if (isset($ganti[$pos])) {
            $hasil[] = $ganti[$pos];
        } elseif (($baris['status'] ?? '') === 'baru' && isset($terpakai[(int) $baris['i']])) {
            continue;
        } else {
            $hasil[] = $baris;
        }
    }
    $bahan = [];
    foreach ($susun['bahan'] as $pos => $asal) {
        if (isset($bahanBaru[$pos])) {
            $bahan[] = $bahanBaru[$pos];
        } elseif (isset($terpakai[(int) $asal['i']])) {
            continue;
        } else {
            $bahan[] = $asal;
        }
    }

    return ['hasil' => $hasil, 'bahan' => $bahan, 'kelompok' => $kelompokSah];
}

/** Jawab pratinjau massal dari sesi yang sudah tersimpan. */
function jawabBatch(array $susun, array $titik, array $kelompok, array $pengguna, int $terpangkas, string $awalan = ''): void
{
    $rapi = terapkanGabungBatch($susun, $kelompok, $titik);
    $_SESSION['penanda_batch']['gabung'] = $rapi['kelompok'];
    $_SESSION['penanda_batch']['terpangkas'] = $terpangkas;

    $hitung = ['baru' => 0, 'kembar' => 0, 'gagal' => 0];
    foreach ($rapi['hasil'] as $baris) {
        $hitung[$baris['status']]++;
    }

    $pesan = sprintf(
        '%sPratinjau %d penanda: %d bidang baru, %d kembar, %d gagal.',
        $awalan,
        count($rapi['hasil']),
        $hitung['baru'],
        $hitung['kembar'],
        $hitung['gagal']
    );
    if ($terpangkas > 0) {
        $pesan .= ' ' . $terpangkas . ' penanda ganda diabaikan.';
    }

    json_ok([
        'token' => (string) $_SESSION['penanda_batch']['token'],
        'jumlah' => count($rapi['hasil']),
        'terpangkas' => $terpangkas,
        'baru' => $hitung['baru'],
        'kembar' => $hitung['kembar'],
        'gagal' => $hitung['gagal'],
        'batas' => PENANDA_BATCH_BATAS,
        'boleh_simpan' => $pengguna['peran'] === PERAN_SUPER && $hitung['baru'] > 0,
        'hasil' => $rapi['hasil'],
    ], $pesan);
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

    $ditemukan = bidangDariPenanda(fiturLapisanPetaInduk('020100'), $x, $y, null, true);
    if ($ditemukan === null) {
        json_error('Penanda tidak berada di dalam poligon tertutup, garis bidang tertutup, rangkaian garis 020100 yang menutup, maupun muka tertutup dari garis sekitarnya. Titik ini belum punya batas tertutup; digitasi batas bidangnya lebih dulu, atau geser penanda ke celah di antara bidang kadastrum.', 422);
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
        'blok' => $blok === null ? null : blok_tiga_digit($blok['kode']),
    ]);

    json_ok(
        [
            'id' => $id,
            'kode' => $pratinjau['kode'],
            'blok' => $blok === null ? null : blok_tiga_digit($blok['kode'])
        ],
        'Bidang baru disimpan sebagai perlu verifikasi'
            . ($blok !== null ? ' di ' . blok_tiga_digit($blok['kode']) : '') . '.'
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

    $_SESSION['penanda_batch'] = [
        'token' => bin2hex(random_bytes(16)),
        'pengguna_id' => auth_user_id(),
        'waktu' => time(),
        'titik' => $titik,
        'gabung' => [],
        'terpangkas' => $jumlahMasuk - count($titik),
    ];

    jawabBatch($susun, $titik, [], $pengguna, $jumlahMasuk - count($titik));
}

/**
 * Nomor baris yang dikirim klien, dipangkas ke rentang titik sesi.
 *
 * @return array<int,int>
 */
function pilihBatch(array $masukan, int $jumlahTitik): array
{
    $pilih = is_array($masukan['pilih'] ?? null) ? $masukan['pilih'] : [];
    $pilih = array_map('intval', $pilih);
    $pilih = array_unique(array_filter($pilih, static fn (int $i): bool => $i >= 0 && $i < $jumlahTitik));
    $pilih = array_values($pilih);
    sort($pilih);
    return $pilih;
}

/** Titik sesi batch yang sah, atau hentikan permintaan. */
function titikSesiBatch(array $pratinjau): array
{
    $titik = is_array($pratinjau['titik'] ?? null) ? $pratinjau['titik'] : [];
    if ($titik === [] || count($titik) > PENANDA_BATCH_BATAS) {
        unset($_SESSION['penanda_batch']);
        json_error('Daftar titik pratinjau massal tidak sah. Ulangi pratinjau bidang.', 410);
    }
    return $titik;
}

if ($aksi === 'batch-gabung') {
    set_time_limit(300);
    $pratinjau = sesiBatch((string) ($masukan['token'] ?? ''));
    $titik = titikSesiBatch($pratinjau);
    $pilih = pilihBatch($masukan, count($titik));
    if ($pilih === []) {
        json_error('Centang minimal dua baris bidang baru untuk digabung.', 422);
    }

    $susun = susunBatch($titik);
    $status = [];
    $geometri = [];
    foreach ($susun['hasil'] as $baris) {
        $status[(int) $baris['i']] = (string) $baris['status'];
        if ($baris['status'] === 'baru') {
            $geometri[(int) $baris['i']] = $baris['geometri'];
        }
    }

    // Pilihan ikut menyeret seluruh anggota kelompok yang sudah ada, supaya
    // gabungan bisa ditambah lagi tanpa kehilangan anggota lama.
    $kelompokLama = is_array($pratinjau['gabung'] ?? null) ? $pratinjau['gabung'] : [];
    $terpilih = array_fill_keys($pilih, true);
    foreach ($kelompokLama as $g) {
        foreach ($g as $i) {
            if (isset($terpilih[(int) $i])) {
                foreach ($g as $j) {
                    $terpilih[(int) $j] = true;
                }
                break;
            }
        }
    }
    $anggota = array_keys($terpilih);
    sort($anggota);
    if (count($anggota) < 2) {
        json_error('Pilih minimal dua bidang baru; baris yang sudah digabung dihitung satu.', 422);
    }
    foreach ($anggota as $i) {
        if (($status[$i] ?? '') !== 'baru') {
            json_error('Baris penanda ke-' . ($i + 1) . ' bukan bidang baru, jadi tidak bisa digabung.', 422);
        }
    }

    $union = gabungPoligonDxf(array_map(static fn (int $i): array => $geometri[$i], $anggota));
    if ($union === null) {
        json_error('Bidang terpilih tidak bersambung; gabung hanya untuk muka yang berbagi batas.', 422);
    }
    try {
        $luasGabung = (float) siapkanGeometri($union)['luas'];
    } catch (Throwable $error) {
        json_error('Geometri gabungan tidak dapat dipakai: ' . $error->getMessage(), 422);
    }

    $sisa = [];
    foreach ($kelompokLama as $g) {
        $kena = false;
        foreach ($g as $i) {
            if (isset($terpilih[(int) $i])) {
                $kena = true;
                break;
            }
        }
        if (!$kena) {
            $sisa[] = $g;
        }
    }
    $kelompok = array_merge($sisa, [$anggota]);
    $_SESSION['penanda_batch']['gabung'] = $kelompok;

    jawabBatch(
        $susun,
        $titik,
        $kelompok,
        $pengguna,
        (int) ($pratinjau['terpangkas'] ?? 0),
        count($anggota) . ' muka digabung (' . angka($luasGabung, 2) . ' m²). '
    );
}

if ($aksi === 'batch-lepas-gabung') {
    $pratinjau = sesiBatch((string) ($masukan['token'] ?? ''));
    $titik = titikSesiBatch($pratinjau);
    $pilih = pilihBatch($masukan, count($titik));
    if ($pilih === []) {
        json_error('Centang baris gabungan yang mau dilepas.', 422);
    }

    $kelompokLama = is_array($pratinjau['gabung'] ?? null) ? $pratinjau['gabung'] : [];
    $sisa = [];
    foreach ($kelompokLama as $g) {
        $kena = false;
        foreach ($g as $i) {
            if (in_array((int) $i, $pilih, true)) {
                $kena = true;
                break;
            }
        }
        if (!$kena) {
            $sisa[] = $g;
        }
    }
    if (count($sisa) === count($kelompokLama)) {
        json_error('Baris terpilih tidak sedang digabung.', 422);
    }

    jawabBatch(
        susunBatch($titik),
        $titik,
        $sisa,
        $pengguna,
        (int) ($pratinjau['terpangkas'] ?? 0),
        'Gabungan dilepas. '
    );
}

if ($aksi === 'batch-simpan') {
    if ($pengguna['peran'] !== PERAN_SUPER) {
        json_error('Hanya Super Admin yang dapat menyimpan bidang baru.', 403);
    }
    set_time_limit(600);

    $pratinjau = sesiBatch((string) ($masukan['token'] ?? ''));
    $titik = titikSesiBatch($pratinjau);

    $idJenisHak = db_value('SELECT id FROM jenis_hak WHERE kode = ?', [JENIS_HAK_DEFAULT]);
    if ($idJenisHak === null) {
        json_error('Jenis hak BLM tidak tersedia. Jalankan sql/01_skema.sql.', 500);
    }

    // Geometri dihitung ulang dari layer, bukan dari pratinjau klien, lalu
    // kembar diperiksa lagi di dalam transaksi: bidang yang baru saja
    // disisipkan pada batch yang sama ikut terlihat oleh koneksi yang sama.
    // Kelompok gabungan ikut dihitung ulang di sini, jadi union yang masuk
    // basis data selalu berasal dari server.
    $susun = susunBatch($titik);
    $rapi = terapkanGabungBatch(
        $susun,
        is_array($pratinjau['gabung'] ?? null) ? $pratinjau['gabung'] : [],
        $titik
    );
    $jalan = db_transaksi(static function (PDO $pdo) use ($rapi, $idJenisHak): array {
        $dibuat = [];
        $dilewati = [];
        foreach ($rapi['bahan'] as $bahan) {
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
                'gabung' => $bahan['gabung'] ?? null,
            ];
        }
        return ['dibuat' => $dibuat, 'dilewati' => $dilewati];
    });

    $gagal = 0;
    foreach ($rapi['hasil'] as $baris) {
        if ($baris['status'] === 'gagal') {
            $gagal++;
        }
    }

    unset($_SESSION['penanda_batch']);
    foreach ($jalan['dibuat'] as $bidang) {
        $data = [
            'kode' => $bidang['kode'],
            'lapisan' => '020100',
            'jenis' => $bidang['jenis'],
            'handle' => $bidang['handle'],
            'penanda' => ['x' => $titik[$bidang['i']][0], 'y' => $titik[$bidang['i']][1]],
            'luas_m2' => $bidang['luas_m2'],
            'blok' => $bidang['blok'],
            'massal' => true,
        ];
        if (!empty($bidang['gabung'])) {
            $data['gabung'] = $bidang['gabung'];
            foreach ($bidang['gabung'] as $i) {
                $data['penanda_gabung'][] = ['x' => $titik[$i][0], 'y' => $titik[$i][1]];
            }
        }
        audit('buat', 'bidang', (string) $bidang['id'],
            'Buat bidang dari penanda massal peta induk 020100: ' . $bidang['kode'], null, $data);
    }

    $digabung = 0;
    foreach ($jalan['dibuat'] as $bidang) {
        if (!empty($bidang['gabung'])) {
            $digabung++;
        }
    }

    json_ok([
        'dibuat' => $jalan['dibuat'],
        'dilewati' => $jalan['dilewati'],
        'gagal' => $gagal,
        'digabung' => $digabung,
    ], sprintf(
        '%d bidang baru disimpan sebagai perlu verifikasi%s, %d dilewati.',
        count($jalan['dibuat']),
        $digabung > 0 ? ' (' . $digabung . ' dari gabungan penanda)' : '',
        count($jalan['dilewati'])
    ));
}

json_error('Aksi tidak dikenal. Gunakan "pratinjau", "garis-baru", "bersihkan", "simpan", '
    . '"batch-pratinjau", "batch-gabung", "batch-lepas-gabung", "batch-simpan", '
    . 'atau "batch-bersihkan".', 400);
