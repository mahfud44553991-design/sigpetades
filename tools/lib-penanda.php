<?php
declare(strict_types=1);

/**
 * Menurunkan poligon bidang dari satu titik penanda pada layer peta induk.
 *
 * Rantai penurunan ini satu-satunya tempat rumus penanda ditulis. Halaman
 * Peta Induk memanggilnya lewat api/penanda-bidang.php, baik untuk satu
 * penanda ("pratinjau", "garis-baru") maupun untuk banyak penanda sekaligus
 * ("batch-pratinjau", "batch-simpan"), sehingga titik yang sama selalu
 * sampai pada geometri dan kode yang sama:
 *
 *   1. poligon tertutup 020100 yang memuat titik   -> jenis "tertutup"
 *   2. garis bidang tertutup (ring)                -> jenis "garis-tertutup"
 *   3. rangkaian seluruh garis 020100              -> jenis "garis"
 *   4. muka terkecil hasil potongan garis di
 *      sekitar titik, untuk kantong terbuka        -> jenis "garis-baru"
 *
 * Semua koordinat di file ini masih meter proyeksi DXF lokal; konversi ke
 * WGS84 dilakukan pemanggil lewat dxfKeWgs84()/siapkanGeometri().
 */

require_once __DIR__ . '/lib-impor.php';

/** Isi layer peta induk sebagai daftar feature GeoJSON. */
function fiturLapisanPetaInduk(string $lapisan = '020100'): array
{
    if (!isset(LAPISAN_POLIGON_PETA_INDUK[$lapisan])) {
        throw new RuntimeException('Layer peta induk tidak dikenal: ' . $lapisan);
    }
    $sumber = LAPISAN_POLIGON_PETA_INDUK[$lapisan];
    return geojsonBaca(SIP_ROOT . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/' . $sumber['berkas']);
}

/**
 * Batas luar tiap fitur layer: [xmin, ymin, xmax, ymax] meter DXF.
 *
 * Uji titik-dalam atas seluruh layer mahal (puluhan ribu fitur), sedangkan
 * membandingkan empat angka murah. Indeks ini dipakai untuk membuang fitur
 * yang jelas tidak mungkin memuat penanda sebelum cincinnya diperiksa.
 *
 * @return array<int, array{0:float,1:float,2:float,3:float}>
 */
function batasFiturLapisan(array $features): array
{
    $batas = [];
    foreach ($features as $indeks => $fitur) {
        $geometri = $fitur['geometry'] ?? [];
        $tipe = (string) ($geometri['type'] ?? '');
        if ($tipe === 'Polygon') {
            $cincin = $geometri['coordinates'][0] ?? [];
        } elseif ($tipe === 'LineString') {
            $cincin = $geometri['coordinates'] ?? [];
        } else {
            continue;
        }
        $xmin = INF;
        $ymin = INF;
        $xmax = -INF;
        $ymax = -INF;
        foreach ($cincin as $titik) {
            $x = (float) $titik[0];
            $y = (float) $titik[1];
            $xmin = min($xmin, $x);
            $xmax = max($xmax, $x);
            $ymin = min($ymin, $y);
            $ymax = max($ymax, $y);
        }
        if (!is_finite($xmin) || $xmax < $xmin || $ymax < $ymin) {
            continue;
        }
        $batas[$indeks] = [$xmin, $ymin, $xmax, $ymax];
    }
    return $batas;
}

/** Titik di dalam batas luar? */
function batasMemuat(array $batas, float $x, float $y): bool
{
    return $x >= $batas[0] && $x <= $batas[2] && $y >= $batas[1] && $y <= $batas[3];
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

/**
 * Kembaran berstatus 'duplikat' dikecualikan seperti pada bidang_filter():
 * induk aslinya tetap terdeteksi, jadi baris kembaran tidak memblokir bidang baru.
 *
 * Uji tumpang tindih dua arah (>= 95% kedua sisi): bidang lama yang menelan
 * calon ini utuh juga kembar, walau luasnya jauh lebih besar.
 *
 * @return array{kode:string,id:int,alasan:string}|null
 */
function duplikatBidang(string $kode, string $wkt): ?array
{
    $sama = db_one('SELECT id FROM bidang WHERE kode = ? LIMIT 1', [$kode]);
    if ($sama !== null) {
        return ['kode' => $kode, 'id' => (int) $sama['id'], 'alasan' => 'kode'];
    }
    $mirip = db_one(
        'SELECT id, kode FROM bidang
          WHERE status NOT IN (\'dihapus\', \'duplikat\')
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
function poligonTertutupDiTitik(array $features, float $x, float $y, ?array $batas = null): ?array
{
    $terbaik = null;
    $luasTerbaik = INF;
    foreach ($features as $indeks => $fitur) {
        if ($batas !== null) {
            $b = $batas[$indeks] ?? null;
            if ($b === null || !batasMemuat($b, $x, $y)) {
                continue;
            }
        }
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
function poligonGarisTertutupDiTitik(array $features, float $x, float $y, ?array $batas = null): ?array
{
    $terbaik = null;
    $luasTerbaik = INF;
    foreach ($features as $indeks => $fitur) {
        if ($batas !== null) {
            $b = $batas[$indeks] ?? null;
            if ($b === null || !batasMemuat($b, $x, $y)) {
                continue;
            }
        }
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

/**
 * Poligon hasil rangkaian garis 020100 terkecil yang memuat titik, atau null.
 *
 * Penyusun rangkaian garis mahal (seluruh layer dibaca ulang), jadi pemanggil
 * yang menguji banyak titik boleh menyerahkan hasil poligonDariGarisPetaInduk()
 * sekali lewat $bangunan.
 */
function poligonGarisDiTitik(array $features, float $x, float $y, ?array $bangunan = null, ?array $batas = null): ?array
{
    $hasil = $bangunan ?? poligonDariGarisPetaInduk($features);
    $terbaik = null;
    $luasTerbaik = INF;
    foreach ($hasil['poligon'] as $indeks => $poligon) {
        if ($batas !== null) {
            $b = $batas[$indeks] ?? null;
            if ($b === null || !batasMemuat($b, $x, $y)) {
                continue;
            }
        }
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

/**
 * Muka bidang terkecil dari rangkaian garis 020100 di sekitar titik.
 *
 * Garis dipotong pada titik potongnya, lalu setengah-sisi ditelusuri untuk
 * mendapat muka yang tertutup. Dipakai untuk titik di kantong terbuka: batas
 * bidannya mengikuti garis sebelahnya. Jari-jari diulang bila muka belum
 * tertutup pada jendela pertama.
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

        $pecah = [];
        foreach ($segmen as $indeks => $_) {
            $pecah[$indeks] = [0.0, 1.0];
        }

        // Hanya segmen yang berbagi petak kisi diuji saling memotong. Tanpa
        // kisi, satu jendela di daerah padat menguji jutaan pasangan dan satu
        // penanda butuh beberapa detik.
        $sisih = max(1.0, $radius / 3.0);
        $petak = [];
        foreach ($segmen as $indeks => [$ax, $ay, $bx, $by]) {
            $gxAwal = (int) floor(min($ax, $bx) / $sisih);
            $gxAkhir = (int) floor(max($ax, $bx) / $sisih);
            $gyAwal = (int) floor(min($ay, $by) / $sisih);
            $gyAkhir = (int) floor(max($ay, $by) / $sisih);
            for ($gx = $gxAwal; $gx <= $gxAkhir; $gx++) {
                for ($gy = $gyAwal; $gy <= $gyAkhir; $gy++) {
                    $petak[$gx . ':' . $gy][$indeks] = true;
                }
            }
        }

        $sudah = [];
        foreach ($petak as $daftar) {
            if (count($daftar) < 2) {
                continue;
            }
            $urut = array_keys($daftar);
            $jumlah = count($urut);
            for ($a = 0; $a < $jumlah; $a++) {
                $i = (int) $urut[$a];
                for ($b = $a + 1; $b < $jumlah; $b++) {
                    $j = (int) $urut[$b];
                    $kunci = $i . ':' . $j;
                    if (isset($sudah[$kunci])) {
                        continue;
                    }
                    $sudah[$kunci] = true;
                    [$ax, $ay, $bx, $by] = $segmen[$i];
                    [$cx, $cy, $dx, $dy] = $segmen[$j];
                    $d1x = $bx - $ax;
                    $d1y = $by - $ay;
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
                'radius' => $radius,
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

/**
 * Bahan penurunan yang bisa dipakai berulang kali.
 *
 * Batas luar tiap fitur selalu disusun karena murah dibandingkan uji
 * titik-dalam; susunan rangkaian garis hanya disusun bila pemanggil belum
 * punya dan benar-benar butuh langkah tiga.
 *
 * @param array<int, array>|null $bangunan hasil poligonDariGarisPetaInduk()
 */
function konteksPenanda(array $fitur, ?array $bangunan = null): array
{
    return [
        'fitur' => $fitur,
        'batas' => batasFiturLapisan($fitur),
        'bangunan' => $bangunan,
        'batasGaris' => null,
    ];
}

/** Layer peta induk siap pakai untuk banyak penanda sekaligus. */
function konteksPenandaPetaInduk(string $lapisan = '020100'): array
{
    $konteks = konteksPenanda(fiturLapisanPetaInduk($lapisan));
    $konteks['bangunan'] = poligonDariGarisPetaInduk($konteks['fitur']);
    $konteks['batasGaris'] = batasFiturLapisan($konteks['bangunan']['poligon']);
    return $konteks;
}

/**
 * Cari bidang yang memuat penanda pada bahan yang sudah disusun.
 *
 * Urutan langkah: poligon tertutup, garis bidang tertutup, rangkaian garis.
 * Bila ketiganya kalah dan $garisBaru true, titik masih dicoba pada muka
 * terkecil hasil potongan garis di sekitarnya, sehingga kantong terbuka seperti
 * gang ikut terbentuk.
 *
 * @return array{calon:array,jenis:string,kode:string}|null
 */
function bidangDiTitik(array $konteks, float $x, float $y, bool $garisBaru = true): ?array
{
    $fitur = $konteks['fitur'];
    $batas = $konteks['batas'];

    $calon = poligonTertutupDiTitik($fitur, $x, $y, $batas);
    $jenis = 'tertutup';
    if ($calon === null) {
        $calon = poligonGarisTertutupDiTitik($fitur, $x, $y, $batas);
        $jenis = 'garis-tertutup';
    }
    if ($calon === null) {
        $bangunan = $konteks['bangunan'];
        $batasGaris = $konteks['batasGaris'];
        if ($bangunan === null) {
            $bangunan = poligonDariGarisPetaInduk($fitur);
            $batasGaris = batasFiturLapisan($bangunan['poligon']);
        }
        $calon = poligonGarisDiTitik($fitur, $x, $y, $bangunan, $batasGaris);
        $jenis = 'garis';
    }
    if ($calon !== null) {
        return [
            'calon' => $calon,
            'jenis' => $jenis,
            'kode' => kodeBidangPetaInduk((int) $calon['indeks'], $jenis),
        ];
    }
    if (!$garisBaru) {
        return null;
    }

    $calon = mukaGarisDiSekitar($fitur, $x, $y);
    if ($calon === null) {
        return null;
    }

    return [
        'calon' => $calon,
        'jenis' => 'garis-baru',
        'kode' => kodeBidangGarisBaru($calon['geometry']),
    ];
}

/**
 * Cari bidang yang memuat penanda: poligon tertutup, lalu garis bidang tertutup,
 * lalu rangkaian garis 020100. Kantong terbuka TIDAK dicoba; itu tugas tombol
 * "Bidang dari garis sekitar".
 *
 * @param array<int, array>|null $bangunan hasil poligonDariGarisPetaInduk() bila sudah disusun
 * @return array{calon:array,jenis:string,kode:string}|null
 */
function bidangDariPenanda(array $features, float $x, float $y, ?array $bangunan = null): ?array
{
    return bidangDiTitik(konteksPenanda($features, $bangunan), $x, $y, false);
}
