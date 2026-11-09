<?php
declare(strict_types=1);

/**
 * Bidang baru dari Penanda titik di peta induk 020100.
 *
 *   POST {aksi: "pratinjau", x: <easting DXF>, y: <northing DXF>}
 *   POST {aksi: "bersihkan"}
 *   POST {aksi: "simpan", token: "<token pratinjau>"}
 *
 * Pratinjau memilih bidang terkecil yang memuat titik: poligon tertutup
 * 020100, lalu garis bidang tertutup (LineString yang ujungnya bertemu),
 * lalu rangkaian seluruh garis 020100 yang membentuk cincin. Geometri selalu
 * dibaca ulang dari berkas layer di server, tidak pernah dari klien.
 */

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../tools/lib-impor.php';

set_time_limit(60);

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

function fiturPetaInduk020100(): array
{
    $sumber = LAPISAN_POLIGON_PETA_INDUK['020100'];
    return geojsonBaca(SIP_ROOT . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . $sumber['berkas']);
}

function titikDalamCincin(array $cincin, float $x, float $y): bool
{
    $dalam = false;
    for ($i = 0, $j = count($cincin) - 1; $i < count($cincin); $j = $i++) {
        $xi = (float) $cincin[$i][0];
        $yi = (float) $cincin[$i][1];
        $xj = (float) $cincin[$j][0];
        $yj = (float) $cincin[$j][1];
        if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
            $dalam = !$dalam;
        }
    }
    return $dalam;
}

function titikDalamPoligon(array $poligon, float $x, float $y): bool
{
    $dalam = false;
    foreach ($poligon as $cincin) {
        if (is_array($cincin) && titikDalamCincin($cincin, $x, $y)) {
            $dalam = !$dalam;
        }
    }
    return $dalam;
}

function luasPoligonDxf(array $poligon): float
{
    $luas = 0.0;
    foreach ($poligon as $nomor => $cincin) {
        $titik = [];
        foreach ($cincin as $pasangan) {
            $titik[] = [(float) $pasangan[0], (float) $pasangan[1]];
        }
        if (count($titik) < 3) {
            continue;
        }
        $luas += $nomor === 0 ? poligonLuas($titik) : -poligonLuas($titik);
    }
    return $luas;
}

/** Poligon tertutup terkecil yang memuat titik, atau null. */
function poligonTertutupDiTitik(array $features, float $x, float $y): ?array
{
    $terbaik = null;
    $luasTerbaik = INF;
    foreach ($features as $indeks => $fitur) {
        $geometri = $fitur['geometry'] ?? [];
        if (($geometri['type'] ?? '') !== 'Polygon') {
            continue;
        }
        $poligon = $geometri['coordinates'] ?? null;
        if (!is_array($poligon) || $poligon === [] || !titikDalamPoligon($poligon, $x, $y)) {
            continue;
        }
        $luas = luasPoligonDxf($poligon);
        if ($luas <= 0 || $luas >= $luasTerbaik) {
            continue;
        }
        $terbaik = [
            'indeks' => (int) $indeks,
            'geometry' => $geometri,
            'handle' => (string) ($fitur['properties']['dxf_handle'] ?? ''),
        ];
        $luasTerbaik = $luas;
    }
    return $terbaik;
}

/** Garis bidang tertutup (LineString yang ujungnya bertemu) terkecil yang memuat titik, atau null. */
function poligonGarisTertutupDiTitik(array $features, float $x, float $y): ?array
{
    $terbaik = null;
    $luasTerbaik = INF;
    foreach ($features as $indeks => $fitur) {
        $geometri = $fitur['geometry'] ?? [];
        if (($geometri['type'] ?? '') !== 'LineString') {
            continue;
        }
        $cincin = $geometri['coordinates'] ?? null;
        if (!is_array($cincin) || count($cincin) < 4 || $cincin[0] !== $cincin[count($cincin) - 1]) {
            continue;
        }
        if (!titikDalamCincin($cincin, $x, $y)) {
            continue;
        }
        $luas = luasPoligonDxf([$cincin]);
        if ($luas <= 0 || $luas >= $luasTerbaik) {
            continue;
        }
        $terbaik = [
            'indeks' => (int) $indeks,
            'geometry' => ['type' => 'Polygon', 'coordinates' => [$cincin]],
            'handle' => (string) ($fitur['properties']['dxf_handle'] ?? ''),
        ];
        $luasTerbaik = $luas;
    }
    return $terbaik;
}

/** Poligon hasil rangkaian garis 020100 terkecil yang memuat titik, atau null. */
function poligonGarisDiTitik(array $features, float $x, float $y): ?array
{
    $hasil = poligonDariGarisPetaInduk($features);
    $terbaik = null;
    $luasTerbaik = INF;
    foreach ($hasil['poligon'] as $indeks => $poligon) {
        $geometri = $poligon['geometry'] ?? [];
        $koordinat = $geometri['coordinates'] ?? null;
        if (!is_array($koordinat) || $koordinat === [] || !titikDalamPoligon($koordinat, $x, $y)) {
            continue;
        }
        $luas = luasPoligonDxf($koordinat);
        if ($luas <= 0 || $luas >= $luasTerbaik) {
            continue;
        }
        $terbaik = [
            'indeks' => (int) $indeks,
            'geometry' => $geometri,
            'handle' => '',
        ];
        $luasTerbaik = $luas;
    }
    return $terbaik;
}

/** Kode poligon/rangkaian mengikuti imporPoligonPetaInduk; garis tertutup memakai awalan T. */
function kodeBidangPetaInduk(int $indeks, string $jenis): string
{
    $awalan = $jenis === 'garis' ? 'G' : ($jenis === 'garis-tertutup' ? 'T' : '');
    return 'DXF-020100-' . $awalan . str_pad((string) ($indeks + 1), 5, '0', STR_PAD_LEFT);
}

/** @return array{kode:string,id:int,alasan:string}|null */
function duplikatBidang(string $kode, string $wkt): ?array
{
    $sama = db_one('SELECT id FROM bidang WHERE kode = ? LIMIT 1', [$kode]);
    if ($sama !== null) {
        return ['kode' => $kode, 'id' => (int) $sama['id'], 'alasan' => 'kode'];
    }
    $mirip = db_one(
        'SELECT id, kode FROM bidang
          WHERE status <> \'dihapus\'
            AND MBRIntersects(geom, ST_GeomFromText(?, 4326))
            AND ST_Area(ST_Intersection(geom, ST_GeomFromText(?, 4326)))
                >= 0.95 * ST_Area(ST_GeomFromText(?, 4326))
            AND ST_Area(ST_Intersection(geom, ST_GeomFromText(?, 4326)))
                >= 0.95 * ST_Area(geom)
          LIMIT 1',
        [$wkt, $wkt, $wkt, $wkt]
    );
    if ($mirip !== null) {
        return ['kode' => (string) $mirip['kode'], 'id' => (int) $mirip['id'], 'alasan' => 'geometri'];
    }
    return null;
}

/** Ubah geometri lokal menjadi WKT WGS84, sekaligus hitung luas meter persegi. */
function siapkanGeometri(array $geometri): array
{
    $koordinat = $geometri['coordinates'] ?? null;
    if (!is_array($koordinat) || $koordinat === []) {
        throw new RuntimeException('Poligon tidak memiliki cincin koordinat.');
    }
    $wgs84 = ['type' => 'Polygon', 'coordinates' => []];
    $luas = 0.0;
    foreach ($koordinat as $nomor => $cincin) {
        if (!is_array($cincin) || count($cincin) < 4) {
            throw new RuntimeException('Cincin poligon kurang dari empat titik.');
        }
        $meter = [];
        $wgs84['coordinates'][$nomor] = [];
        foreach ($cincin as $titik) {
            if (!is_array($titik) || count($titik) < 2 || !is_numeric($titik[0]) || !is_numeric($titik[1])) {
                throw new RuntimeException('Koordinat lokal poligon tidak sah.');
            }
            $x = (float) $titik[0];
            $y = (float) $titik[1];
            $meter[] = [$x, $y];
            $wgs84['coordinates'][$nomor][] = dxfKeWgs84($x, $y);
        }
        $luas += $nomor === 0 ? poligonLuas($meter) : -poligonLuas($meter);
    }
    if ($luas <= 0) {
        throw new RuntimeException('Luas poligon tidak positif.');
    }
    $wkt = geojsonKeWkt($wgs84);
    if ($wkt === null || !str_starts_with($wkt, 'POLYGON(')) {
        throw new RuntimeException('Poligon gagal dikonversi ke WGS84.');
    }
    return ['wkt' => $wkt, 'luas' => $luas, 'pusat' => wktPusat(db(), $wkt)];
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

    $features = fiturPetaInduk020100();
    $calon = poligonTertutupDiTitik($features, $x, $y);
    $jenis = 'tertutup';
    if ($calon === null) {
        $calon = poligonGarisTertutupDiTitik($features, $x, $y);
        $jenis = 'garis-tertutup';
    }
    if ($calon === null) {
        $calon = poligonGarisDiTitik($features, $x, $y);
        $jenis = 'garis';
    }
    if ($calon === null) {
        json_error('Penanda tidak berada di dalam poligon tertutup, garis bidang tertutup, atau rangkaian garis 020100 yang menutup.', 422);
    }

    $kode = kodeBidangPetaInduk((int) $calon['indeks'], $jenis);
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

    $asal = match ($pratinjau['jenis']) {
        'garis' => 'rangkaian garis',
        'garis-tertutup' => 'garis bidang tertutup ' . ($pratinjau['handle'] !== '' ? $pratinjau['handle'] : 'tanpa handle'),
        default => 'poligon tertutup ' . ($pratinjau['handle'] !== '' ? $pratinjau['handle'] : 'tanpa handle'),
    };
    $catatan = sprintf(
        'Dibuat dari penanda X %.2f Y %.2f pada layer peta induk 020100 (%s); perlu verifikasi dan penetapan blok.',
        (float) $pratinjau['x'],
        (float) $pratinjau['y'],
        $asal
    );

    $id = db_transaksi(static function (PDO $pdo) use ($pratinjau, $idJenisHak, $catatan): int {
        $pdo->prepare(
            'INSERT INTO bidang
                (kode, tipe, luas_m2, status, sumber, catatan, geom, pusat_lon, pusat_lat)
             VALUES (?, \'tanah\', ?, \'perlu_verifikasi\', \'peta-020100\', ?, ST_GeomFromText(?, 4326), ?, ?)'
        )->execute([
            $pratinjau['kode'],
            round((float) $pratinjau['luas'], 2),
            $catatan,
            $pratinjau['wkt'],
            $pratinjau['pusat']['lon'],
            $pratinjau['pusat']['lat'],
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO hak (bidang_id, jenis_hak_id, catatan) VALUES (?, ?, ?)')
            ->execute([$id, $idJenisHak, 'Jenis hak belum dikonfirmasi; dibuat dari penanda peta induk 020100.']);
        return $id;
    });

    unset($_SESSION['penanda_bidang']);
    audit('buat', 'bidang', (string) $id, 'Buat bidang dari penanda peta induk 020100: ' . $pratinjau['kode'], null, [
        'kode' => $pratinjau['kode'],
        'lapisan' => '020100',
        'jenis' => $pratinjau['jenis'],
        'handle' => $pratinjau['handle'],
        'penanda' => ['x' => $pratinjau['x'], 'y' => $pratinjau['y']],
        'luas_m2' => round((float) $pratinjau['luas'], 2),
    ]);

    json_ok(['id' => $id, 'kode' => $pratinjau['kode']], 'Bidang baru disimpan sebagai perlu verifikasi.');
}

json_error('Aksi tidak dikenal. Gunakan "pratinjau" atau "simpan".', 400);
