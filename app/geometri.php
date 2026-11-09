<?php
declare(strict_types=1);

/**
 * Geometri persil untuk tools digitasi (buat, pecah, gabung).
 *
 * Luas dan pusat dihitung pada proyeksi ekuirektangular lokal, bukan derajat²
 * dikali konstanta ekuator. Cara lama kelebihan hitung ±1,5 % di lintisan
 * Desa Gedong, dan rata-rata titik sudut menempatkan pusat di luar bidang
 * berbentuk L sehingga pencarian bidang terdekat ikut meleset.
 */

const GEOMETRI_RADIUS_BUMI = 6371000.0;

/**
 * Cincin terluar sebuah geometri GeoJSON, sebagai list koordinat [lon, lat].
 * Polygon dan MultiPolygon sama-sama didukung.
 * @return array<int, array<int, array{0: float, 1: float}>>
 */
function geometri_ke_ring(array $geometry): array
{
    $tipe = $geometry['type'] ?? '';
    $ring = [];

    if ($tipe === 'Polygon') {
        foreach ((array) ($geometry['coordinates'] ?? []) as $r) {
            $ring[] = $r;
        }
    } elseif ($tipe === 'MultiPolygon') {
        foreach ((array) ($geometry['coordinates'] ?? []) as $poligon) {
            $pertama = $poligon[0] ?? null;
            if (is_array($pertama)) {
                $ring[] = $pertama;
            }
        }
    }

    return array_values(array_filter($ring, static fn($r): bool => is_array($r) && count($r) >= 3));
}

/**
 * Proyeksikan satu cincin ke meter. Titik acuan = rata-rata koordinat, sehingga
 * galat proyeksi diabaikan pada ukuran persil.
 * @return array{xy: array<int, array{0: float, 1: float}>, lon0: float, cos: float}
 */
function proyeksi_ring(array $coordinates): array
{
    $titik = [];
    foreach ($coordinates as $c) {
        if (is_array($c) && count($c) >= 2) {
            $titik[] = [(float) $c[0], (float) $c[1]];
        }
    }

    $n = count($titik);
    if ($n > 1 && $titik[0] === $titik[$n - 1]) {
        array_pop($titik);
        $n--;
    }
    if ($n === 0) {
        return ['xy' => [], 'lon0' => 0.0, 'cos' => 1.0];
    }

    $lon0 = array_sum(array_column($titik, 0)) / $n;
    $lintisan_rata = array_sum(array_column($titik, 1)) / $n;
    $cos = max(0.01, cos(deg2rad($lintisan_rata)));

    $xy = [];
    foreach ($titik as [$lon, $lat]) {
        $xy[] = [
            GEOMETRI_RADIUS_BUMI * deg2rad($lon - $lon0) * $cos,
            GEOMETRI_RADIUS_BUMI * deg2rad($lat),
        ];
    }

    return ['xy' => $xy, 'lon0' => $lon0, 'cos' => $cos];
}

/** Luas satu cincin (m²) dengan rumus tali sepatu pada bidang proyeksi. */
function luas_ring_m2(array $coordinates): float
{
    $xy = proyeksi_ring($coordinates)['xy'];
    $n = count($xy);
    if ($n < 3) {
        return 0.0;
    }

    $jumlah = 0.0;
    for ($i = 0; $i < $n; $i++) {
        [$x1, $y1] = $xy[$i];
        [$x2, $y2] = $xy[($i + 1) % $n];
        $jumlah += $x1 * $y2 - $x2 * $y1;
    }

    return abs($jumlah) / 2.0;
}

/** Titik berat satu cincin sebagai ['lon' => ..., 'lat' => ...]. */
function pusat_ring(array $coordinates): array
{
    ['xy' => $xy, 'lon0' => $lon0, 'cos' => $cos] = proyeksi_ring($coordinates);
    $n = count($xy);
    if ($n === 0) {
        return ['lon' => 0.0, 'lat' => 0.0];
    }

    $luas = 0.0;
    $px = 0.0;
    $py = 0.0;
    for ($i = 0; $i < $n; $i++) {
        [$x1, $y1] = $xy[$i];
        [$x2, $y2] = $xy[($i + 1) % $n];
        $silang = $x1 * $y2 - $x2 * $y1;
        $luas += $silang;
        $px += ($x1 + $x2) * $silang;
        $py += ($y1 + $y2) * $silang;
    }
    $luas /= 2.0;

    if (abs($luas) < 0.0001) {
        // Cincin praktis garis lurus: tidak ada titik berat, pakai rata-rata sudut.
        $px = array_sum(array_column($xy, 0)) / $n;
        $py = array_sum(array_column($xy, 1)) / $n;
    } else {
        $px /= (6.0 * $luas);
        $py /= (6.0 * $luas);
    }

    return [
        'lon' => round($lon0 + rad2deg($px / (GEOMETRI_RADIUS_BUMI * $cos)), 7),
        'lat' => round(rad2deg($py / GEOMETRI_RADIUS_BUMI), 7),
    ];
}

/**
 * Luas total geometri (m²). Pada MultiPolygon, lubang (cincin dalam) dikurangi.
 */
function luas_geometri_m2(array $geometry): float
{
    if (($geometry['type'] ?? '') === 'MultiPolygon') {
        $total = 0.0;
        foreach ((array) ($geometry['coordinates'] ?? []) as $poligon) {
            $total += luas_polygon_dengan_lubang($poligon);
        }
        return round($total, 2);
    }

    return round(luas_polygon_dengan_lubang((array) ($geometry['coordinates'] ?? [])), 2);
}

function luas_polygon_dengan_lubang(array $rings): float
{
    $luas = 0.0;
    foreach ($rings as $i => $ring) {
        if (!is_array($ring) || count($ring) < 3) {
            continue;
        }
        $s = luas_ring_m2($ring);
        $luas += ($i === 0 ? $s : -$s);
    }

    return max(0.0, $luas);
}

/** Pusat geometri; pada MultiPolygon memakai rata-rata berbobot luas. */
function pusat_geometri(array $geometry): array
{
    $ring = geometri_ke_ring($geometry);
    if ($ring === []) {
        return ['lon' => 0.0, 'lat' => 0.0];
    }

    if (count($ring) === 1) {
        return pusat_ring($ring[0]);
    }

    $bobotLuas = 0.0;
    $bujur = 0.0;
    $lintis = 0.0;
    foreach ($ring as $r) {
        $s = luas_ring_m2($r);
        $p = pusat_ring($r);
        $bobotLuas += $s;
        $bujur += $p['lon'] * $s;
        $lintis += $p['lat'] * $s;
    }

    if ($bobotLuas <= 0.0) {
        return pusat_ring($ring[0]);
    }

    return ['lon' => round($bujur / $bobotLuas, 7), 'lat' => round($lintis / $bobotLuas, 7)];
}

/**
 * WKT Polygon/MultiPolygon dari geometri GeoJSON; cincin yang belum tertutup
 * ditutup ulang agar ST_GeomFromText tidak menolak input.
 */
function geometri_ke_wkt(array $geometry): string
{
    $tipe = $geometry['type'] ?? '';

    if ($tipe === 'Polygon') {
        $rings = ring_wkt((array) ($geometry['coordinates'] ?? []));
        return $rings === [] ? '' : 'POLYGON(' . implode(',', $rings) . ')';
    }

    if ($tipe === 'MultiPolygon') {
        $poligon = [];
        foreach ((array) ($geometry['coordinates'] ?? []) as $rings) {
            $r = ring_wkt((array) $rings);
            if ($r !== []) {
                $poligon[] = '(' . implode(',', $r) . ')';
            }
        }
        return $poligon === [] ? '' : 'MULTIPOLYGON(' . implode(',', $poligon) . ')';
    }

    return '';
}

/** @param array<int, array> $rings @return array<int, string> */
function ring_wkt(array $rings): array
{
    $hasil = [];
    foreach ($rings as $ring) {
        $points = [];
        foreach ((array) $ring as $coord) {
            if (!is_array($coord) || count($coord) < 2) {
                continue;
            }
            $points[] = round((float) $coord[0], 7) . ' ' . round((float) $coord[1], 7);
        }
        if (count($points) < 3) {
            continue;
        }
        if ($points[0] !== $points[count($points) - 1]) {
            $points[] = $points[0];
        }
        $hasil[] = '(' . implode(',', $points) . ')';
    }

    return $hasil;
}
