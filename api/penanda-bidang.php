<?php
declare(strict_types=1);

/**
 * Bidang baru dari Penanda titik di peta induk 020100.
 *
 *   POST {aksi: "pratinjau", x: <easting DXF>, y: <northing DXF>}
 *   POST {aksi: "garis-baru", x: <easting DXF>, y: <northing DXF>}
 *   POST {aksi: "bersihkan"}
 *   POST {aksi: "simpan", token: "<token pratinjau>"}
 *
 * Pratinjau memilih bidang terkecil yang memuat titik: poligon tertutup
 * 020100, lalu garis bidang tertutup (LineString yang ujungnya bertemu),
 * lalu rangkaian seluruh garis 020100 yang membentuk cincin. Untuk titik di
 * kantong terbuka, "garis-baru" memotong garis pada titik potong lalu
 * menelusuri muka terkecil yang memuat titik, sehingga batasnya mengikuti
 * garis sebelahnya. Geometri selalu dibaca ulang dari berkas layer di server,
 * tidak pernah dari klien.
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

/** Sambung celah ujung garis 020100 sampai 1 satuan lokal DXF (≈ 1 m). */
const TOLERANSI_SAMBUNG_GARIS_DXF = 1.0;

/**
 * Rapatkan celah kecil pada rangkaian garis sebelum muka ditelusuri.
 *
 * Ujung garis yang berjarak <= toleransi dijadikan satu simpul, dan ujung
 * yang nyaris menempel ke badan segmen lain diproyeksikan ke segmen itu.
 * Garis DXF sering berhenti sepersekian unit sebelum bertemu, sehingga tanpa
 * langkah ini muka tidak pernah tertutup walau celahnya kecil.
 *
 * @param array<int, array{0:float,1:float,2:float,3:float,4:string}> $segmen
 * @return array<int, array{0:float,1:float,2:float,3:float,4:string}>
 */
function sambungCelahSegmen(array $segmen, float $toleransi): array
{
    // Sel kisi minimal 0,5 satuan agar penelusuran garis tetap terbatas.
    $sel = max($toleransi, 0.5);
    $simpul = [];
    $kisiSimpul = [];
    $idSimpul = static function (float $x, float $y) use (&$simpul, &$kisiSimpul, $toleransi, $sel): int {
        $cx = (int) floor($x / $sel);
        $cy = (int) floor($y / $sel);
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                foreach ($kisiSimpul[($cx + $dx) . ':' . ($cy + $dy)] ?? [] as $id) {
                    if (hypot($simpul[$id][0] - $x, $simpul[$id][1] - $y) <= $toleransi) {
                        return $id;
                    }
                }
            }
        }
        $id = count($simpul);
        $simpul[$id] = [$x, $y];
        $kisiSimpul[$cx . ':' . $cy][] = $id;
        return $id;
    };

    $rapat = [];
    foreach ($segmen as $s) {
        $u = $idSimpul((float) $s[0], (float) $s[1]);
        $v = $idSimpul((float) $s[2], (float) $s[3]);
        if ($u === $v) {
            continue;
        }
        $rapat[] = [$simpul[$u][0], $simpul[$u][1], $simpul[$v][0], $simpul[$v][1], (string) $s[4]];
    }

    $kisiSegmen = [];
    foreach ($rapat as $id => $s) {
        $cx = (int) floor($s[0] / $sel);
        $cy = (int) floor($s[1] / $sel);
        $cxAkhir = (int) floor($s[2] / $sel);
        $cyAkhir = (int) floor($s[3] / $sel);
        $kisiSegmen[$cx . ':' . $cy][] = $id;
        $dx = $s[2] - $s[0];
        $dy = $s[3] - $s[1];
        $langkahX = $dx > 0 ? 1 : ($dx < 0 ? -1 : 0);
        $langkahY = $dy > 0 ? 1 : ($dy < 0 ? -1 : 0);
        $tMaxX = $langkahX === 0 ? INF : ((($cx + ($langkahX > 0 ? 1 : 0)) * $sel) - $s[0]) / $dx;
        $tMaxY = $langkahY === 0 ? INF : ((($cy + ($langkahY > 0 ? 1 : 0)) * $sel) - $s[1]) / $dy;
        $tDeltaX = $langkahX === 0 ? INF : $sel / abs($dx);
        $tDeltaY = $langkahY === 0 ? INF : $sel / abs($dy);
        // Telusuri sel yang benar-benar dilewati garis, bukan seluruh bbox-nya.
        for ($jaga = 0; $jaga < 100000 && ($cx !== $cxAkhir || $cy !== $cyAkhir); $jaga++) {
            if ($tMaxX < $tMaxY) {
                $cx += $langkahX;
                $tMaxX += $tDeltaX;
            } else {
                $cy += $langkahY;
                $tMaxY += $tDeltaY;
            }
            $kisiSegmen[$cx . ':' . $cy][] = $id;
        }
    }

    $potong = [];
    foreach ($simpul as $idSimpulKini => [$x, $y]) {
        $cx = (int) floor($x / $sel);
        $cy = (int) floor($y / $sel);
        $calon = [];
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                foreach ($kisiSegmen[($cx + $dx) . ':' . ($cy + $dy)] ?? [] as $idGaris) {
                    $calon[$idGaris] = true;
                }
            }
        }
        foreach (array_keys($calon) as $idGaris) {
            $s = $rapat[$idGaris];
            if (($s[0] === $x && $s[1] === $y) || ($s[2] === $x && $s[3] === $y)) {
                continue;
            }
            $dx = $s[2] - $s[0];
            $dy = $s[3] - $s[1];
            $panjang2 = $dx * $dx + $dy * $dy;
            if ($panjang2 <= 1e-12) {
                continue;
            }
            $t = (($x - $s[0]) * $dx + ($y - $s[1]) * $dy) / $panjang2;
            if ($t <= 1e-6 || $t >= 1.0 - 1e-6) {
                continue;
            }
            if (hypot($s[0] + $t * $dx - $x, $s[1] + $t * $dy - $y) > $toleransi) {
                continue;
            }
            $potong[$idGaris][] = [$t, $idSimpulKini];
        }
    }

    if ($potong === []) {
        return $rapat;
    }

    $hasil = [];
    foreach ($rapat as $idGaris => $s) {
        if (!isset($potong[$idGaris])) {
            $hasil[] = $s;
            continue;
        }
        $daftar = $potong[$idGaris];
        usort($daftar, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $kini = [$s[0], $s[1]];
        foreach ($daftar as [, $idTitik]) {
            $titik = $simpul[$idTitik];
            if (hypot($titik[0] - $kini[0], $titik[1] - $kini[1]) < 1e-9) {
                continue;
            }
            $hasil[] = [$kini[0], $kini[1], $titik[0], $titik[1], $s[4]];
            $kini = $titik;
        }
        if (hypot($s[2] - $kini[0], $s[3] - $kini[1]) >= 1e-9) {
            $hasil[] = [$kini[0], $kini[1], $s[2], $s[3], $s[4]];
        }
    }
    return $hasil;
}

/**
 * Muka bidang terkecil dari rangkaian garis 020100 di sekitar titik.
 *
 * Celah ujung garis sampai 1 satuan disambung lebih dulu, lalu garis dipotong
 * pada titik potongnya dan setengah-sisi ditelusuri untuk mendapat muka yang
 * tertutup. Dipakai untuk titik di kantong terbuka: batas bidannya mengikuti
 * garis sebelahnya. Jari-jari diulang bila muka belum tertutup pada jendela
 * pertama.
 *
 * @return array{geometry:array,luas:float,keliling:float,handle:string}|null
 */
function mukaGarisDiSekitar(array $features, float $px, float $py): ?array
{
    $batasLuas = 200000.0;
    foreach ([250.0, 400.0, 600.0] as $radius) {
        $segmen = [];
        foreach ($features as $fitur) {
            $geometri = $fitur['geometry'] ?? [];
            if (($geometri['type'] ?? '') !== 'LineString') {
                continue;
            }
            $koordinat = $geometri['coordinates'] ?? [];
            $handle = (string) ($fitur['properties']['dxf_handle'] ?? '');
            for ($i = 1, $n = count($koordinat); $i < $n; $i++) {
                $ax = (float) $koordinat[$i - 1][0];
                $ay = (float) $koordinat[$i - 1][1];
                $bx = (float) $koordinat[$i][0];
                $by = (float) $koordinat[$i][1];
                if (($ax === $bx && $ay === $by)
                    || max($ax, $bx) < $px - $radius || min($ax, $bx) > $px + $radius
                    || max($ay, $by) < $py - $radius || min($ay, $by) > $py + $radius) {
                    continue;
                }
                $segmen[] = [$ax, $ay, $bx, $by, $handle];
            }
        }
        if (count($segmen) < 3) {
            continue;
        }
        $segmen = sambungCelahSegmen($segmen, TOLERANSI_SAMBUNG_GARIS_DXF);
        if (count($segmen) < 3) {
            continue;
        }

        $pecah = [];
        foreach ($segmen as $indeks => $_) {
            $pecah[$indeks] = [0.0, 1.0];
        }
        $jumlah = count($segmen);
        for ($i = 0; $i < $jumlah; $i++) {
            [$ax, $ay, $bx, $by] = $segmen[$i];
            $d1x = $bx - $ax;
            $d1y = $by - $ay;
            for ($j = $i + 1; $j < $jumlah; $j++) {
                [$cx, $cy, $dx, $dy] = $segmen[$j];
                $d2x = $dx - $cx;
                $d2y = $dy - $cy;
                $penyebut = $d1x * $d2y - $d1y * $d2x;
                if (abs($penyebut) < 1e-12) {
                    continue;
                }
                $qx = $cx - $ax;
                $qy = $cy - $ay;
                $t = ($qx * $d2y - $qy * $d2x) / $penyebut;
                $u = ($qx * $d1y - $qy * $d1x) / $penyebut;
                if ($t < -1e-9 || $t > 1 + 1e-9 || $u < -1e-9 || $u > 1 + 1e-9) {
                    continue;
                }
                $pecah[$i][] = max(0.0, min(1.0, $t));
                $pecah[$j][] = max(0.0, min(1.0, $u));
            }
        }

        $kunci = [];
        $koordinat = [];
        $node = static function (float $x, float $y) use (&$kunci, &$koordinat): int {
            $k = sprintf('%.3F,%.3F', $x, $y);
            if (!isset($kunci[$k])) {
                $kunci[$k] = count($koordinat);
                $koordinat[] = [$x, $y];
            }
            return $kunci[$k];
        };

        $tepi = [];
        $dipakai = [];
        foreach ($segmen as $indeks => [$ax, $ay, $bx, $by, $handle]) {
            $daftar = $pecah[$indeks];
            sort($daftar);
            $unik = [];
            foreach ($daftar as $t) {
                if ($unik === [] || abs($t - end($unik)) > 1e-7) {
                    $unik[] = $t;
                }
            }
            for ($k = 1, $m = count($unik); $k < $m; $k++) {
                if ($unik[$k] - $unik[$k - 1] < 1e-9) {
                    continue;
                }
                $u = $node($ax + ($bx - $ax) * $unik[$k - 1], $ay + ($by - $ay) * $unik[$k - 1]);
                $v = $node($ax + ($bx - $ax) * $unik[$k], $ay + ($by - $ay) * $unik[$k]);
                if ($u === $v) {
                    continue;
                }
                $pasangan = min($u, $v) . ':' . max($u, $v);
                if (isset($dipakai[$pasangan])) {
                    continue;
                }
                $dipakai[$pasangan] = true;
                $tepi[] = [$u, $v, $handle];
            }
        }

        $setengah = [];
        foreach ($tepi as $id => [$u, $v]) {
            $setengah[] = [
                'edge' => $id, 'from' => $u, 'to' => $v, 'twin' => $id * 2 + 1,
                'angle' => atan2($koordinat[$v][1] - $koordinat[$u][1], $koordinat[$v][0] - $koordinat[$u][0]),
            ];
            $setengah[] = [
                'edge' => $id, 'from' => $v, 'to' => $u, 'twin' => $id * 2,
                'angle' => atan2($koordinat[$u][1] - $koordinat[$v][1], $koordinat[$u][0] - $koordinat[$v][0]),
            ];
        }
        $keluar = [];
        foreach ($setengah as $id => $h) {
            $keluar[$h['from']][] = $id;
        }
        foreach ($keluar as &$daftar) {
            usort($daftar, static fn($a, $b) => $setengah[$a]['angle'] <=> $setengah[$b]['angle']);
        }
        unset($daftar);

        $terbaik = null;
        $luasTerbaik = INF;
        $dikunjungi = [];
        foreach ($setengah as $awal => $_) {
            if (isset($dikunjungi[$awal])) {
                continue;
            }
            $kini = $awal;
            $cincin = [];
            $handle = [];
            $tertutup = false;
            for ($langkah = 0, $batas = count($setengah) + 1; $langkah < $batas; $langkah++) {
                if (isset($dikunjungi[$kini])) {
                    $tertutup = $kini === $awal;
                    break;
                }
                $dikunjungi[$kini] = true;
                $h = $setengah[$kini];
                $cincin[] = $koordinat[$h['from']];
                if ($tepi[$h['edge']][2] !== '') {
                    $handle[] = $tepi[$h['edge']][2];
                }
                $keluarDari = $keluar[$h['to']] ?? [];
                $posisi = array_search($h['twin'], $keluarDari, true);
                if ($posisi === false) {
                    break;
                }
                $kini = $keluarDari[($posisi - 1 + count($keluarDari)) % count($keluarDari)];
            }
            if (!$tertutup || count($cincin) < 3) {
                continue;
            }
            $cincin[] = $cincin[0];
            $dua = 0.0;
            $keliling = 0.0;
            for ($i = 0, $akhir = count($cincin) - 1; $i < $akhir; $i++) {
                $dua += $cincin[$i][0] * $cincin[$i + 1][1] - $cincin[$i + 1][0] * $cincin[$i][1];
                $keliling += hypot($cincin[$i + 1][0] - $cincin[$i][0], $cincin[$i + 1][1] - $cincin[$i][1]);
            }
            $luas = $dua / 2;
            if ($luas <= 1.0 || $luas > $batasLuas || $luas >= $luasTerbaik) {
                continue;
            }
            if (!titikDalamCincin($cincin, $px, $py)) {
                continue;
            }
            $terbaik = [
                'geometry' => ['type' => 'Polygon', 'coordinates' => [$cincin]],
                'luas' => $luas,
                'keliling' => $keliling,
                'handle' => implode(', ', array_values(array_unique($handle))),
            ];
            $luasTerbaik = $luas;
        }

        if ($terbaik !== null) {
            return $terbaik;
        }
    }
    return null;
}

/** Kode bidang garis baru: tetap untuk cincin yang sama, awalan B. */
function kodeBidangGarisBaru(array $geometri): string
{
    $kunci = '';
    foreach ($geometri['coordinates'][0] ?? [] as $pasangan) {
        $kunci .= sprintf('%.3F,%.3F;', (float) $pasangan[0], (float) $pasangan[1]);
    }
    return 'DXF-020100-B' . str_pad((string) (crc32($kunci) % 100000), 5, '0', STR_PAD_LEFT);
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

/** Blok resmi terkecil yang memuat titik pusat bidang, atau null. */
function blokPusatBidang(string $wkt): ?array
{
    $blok = db_one(
        'SELECT id, kode, nomor, nama
           FROM blok
          WHERE geom IS NOT NULL
            AND batas_perkiraan = 0
            AND ST_Contains(geom, ST_Centroid(ST_GeomFromText(?, 4326)))
          ORDER BY ST_Area(geom) ASC
          LIMIT 1',
        [$wkt]
    );
    if ($blok === null) {
        return null;
    }
    return [
        'id' => (int) $blok['id'],
        'kode' => (string) $blok['kode'],
        'nomor' => $blok['nomor'] === null ? null : (int) $blok['nomor'],
        'nama' => (string) $blok['nama'],
    ];
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
    $blok = blokPusatBidang($siap['wkt']);
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
        'blok' => $blok,
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
        json_error('Penanda tidak berada di dalam poligon tertutup, garis bidang tertutup, atau rangkaian garis 020100 yang menutup. Pakai “Bidang dari garis sekitar” untuk membentuk bidang baru.', 422);
    }

    kirimPratinjau($calon, $jenis, kodeBidangPetaInduk((int) $calon['indeks'], $jenis), $x, $y, $pengguna);
}

if ($aksi === 'garis-baru') {
    $x = isset($masukan['x']) && is_numeric($masukan['x']) ? (float) $masukan['x'] : NAN;
    $y = isset($masukan['y']) && is_numeric($masukan['y']) ? (float) $masukan['y'] : NAN;
    if (!is_finite($x) || !is_finite($y)) {
        json_error('Koordinat penanda tidak sah.', 422);
    }

    $calon = mukaGarisDiSekitar(fiturPetaInduk020100(), $x, $y);
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

    $asal = match ($pratinjau['jenis']) {
        'garis' => 'rangkaian garis',
        'garis-baru' => 'garis bidang baru dari garis sekitar ' . ($pratinjau['handle'] !== '' ? $pratinjau['handle'] : 'tanpa handle'),
        'garis-tertutup' => 'garis bidang tertutup ' . ($pratinjau['handle'] !== '' ? $pratinjau['handle'] : 'tanpa handle'),
        default => 'poligon tertutup ' . ($pratinjau['handle'] !== '' ? $pratinjau['handle'] : 'tanpa handle'),
    };
    $blok = blokPusatBidang((string) $pratinjau['wkt']);
    $catatan = sprintf(
        'Dibuat dari penanda X %.2f Y %.2f pada layer peta induk 020100 (%s); perlu verifikasi%s.',
        (float) $pratinjau['x'],
        (float) $pratinjau['y'],
        $asal,
        $blok !== null ? '; blok ' . $blok['kode'] . ' dari titik pusat bidang' : ' dan penetapan blok'
    );

    $id = db_transaksi(static function (PDO $pdo) use ($pratinjau, $idJenisHak, $catatan, $blok): int {
        $pdo->prepare(
            'INSERT INTO bidang
                (kode, tipe, luas_m2, blok_id, status, sumber, catatan, geom, pusat_lon, pusat_lat)
             VALUES (?, \'tanah\', ?, ?, \'perlu_verifikasi\', \'peta-020100\', ?, ST_GeomFromText(?, 4326), ?, ?)'
        )->execute([
            $pratinjau['kode'],
            round((float) $pratinjau['luas'], 2),
            $blok['id'] ?? null,
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
        'blok' => $blok['kode'] ?? null,
    ]);

    json_ok([
        'id' => $id,
        'kode' => $pratinjau['kode'],
        'blok' => $blok,
    ], 'Bidang baru disimpan sebagai perlu verifikasi'
        . ($blok !== null ? ' di ' . $blok['kode'] : '') . '.');
}

json_error('Aksi tidak dikenal. Gunakan "pratinjau", "garis-baru", "bersihkan", atau "simpan".', 400);
