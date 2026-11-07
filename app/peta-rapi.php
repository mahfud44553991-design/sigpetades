<?php
declare(strict_types=1);

/**
 * Turunan geometri peta: garis jalan OpenStreetMap dipotong di tepi bidang,
 * label perairan DXF disambung menjadi garis. Tidak menulis ke tabel apa pun;
 * hasil jalan disimpan di storage/cache dan dibuang saat geometri bidang berubah.
 */

const PETA_RAPI_LANGKAH_M = 0.75;
const PETA_RAPI_SEDERHANA_M = 1.2;
const PETA_RAPI_SUNGAI_MAKS_M = 140.0;
const PETA_RAPI_SUNGAI_TETANGGA = 2;
const PETA_RAPI_SEL_DERAJAT = 0.002;
const PETA_RAPI_LABEL_UMUM = ['SUNGAI', 'SALURAN', 'SLURAN', 'IRIGASI', 'SALURAN IRIGASI', 'KALI', 'PERAIRAN'];
const PETA_RAPI_VERSI = 'v3';
const PETA_RAPI_JALAN_MIN_M = 6.0;
const PETA_RAPI_JALUR_SEL_M = 2.0;
const PETA_RAPI_JALUR_MARGIN_M = 80.0;

function peta_rapi_jarak_m(float $ax, float $ay, float $bx, float $by): float
{
    return hypot(($bx - $ax) * 110570.0, ($by - $ay) * 111320.0);
}

/** @return array{0:float,1:float,2:float,3:float} */
function peta_rapi_bbox(array $ring): array
{
    $minX = $minY = INF;
    $maxX = $maxY = -INF;
    foreach ($ring as $titik) {
        $x = (float) $titik[0];
        $y = (float) $titik[1];
        if ($x < $minX) { $minX = $x; }
        if ($x > $maxX) { $maxX = $x; }
        if ($y < $minY) { $minY = $y; }
        if ($y > $maxY) { $maxY = $y; }
    }

    return [$minX, $minY, $maxX, $maxY];
}

function peta_rapi_dalam_ring(float $x, float $y, array $ring): bool
{
    $dalam = false;
    $jumlah = count($ring);
    for ($i = 0, $j = $jumlah - 1; $i < $jumlah; $j = $i++) {
        $xi = (float) $ring[$i][0]; $yi = (float) $ring[$i][1];
        $xj = (float) $ring[$j][0]; $yj = (float) $ring[$j][1];
        if ((($yi > $y) !== ($yj > $y))
            && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
            $dalam = !$dalam;
        }
    }

    return $dalam;
}

/**
 * Poligon bidang (cincin luar + lubang) beserta kisi 0,002° supaya uji titik
 * tidak menyapu 8.000 bidang setiap kali.
 *
 * @return array{poligon:array<int,array{luar:array,lubang:array,bb:array}>,sel:float,petak:array<string,array<int,int>>}
 */
function peta_rapi_indeks_bidang(): array
{
    $baris = db_all(
        "SELECT ST_AsGeoJSON(geom) AS geojson FROM bidang
         WHERE geom IS NOT NULL AND status <> 'dihapus'"
    );

    $poligon = [];
    foreach ($baris as $satu) {
        $geometry = json_decode((string) $satu['geojson'], true);
        if (!is_array($geometry)) { continue; }
        $daftar = match ($geometry['type'] ?? '') {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => [],
        };
        foreach ($daftar as $cincin) {
            if (!isset($cincin[0]) || count($cincin[0]) < 4) { continue; }
            $poligon[] = [
                'luar' => $cincin[0],
                'lubang' => array_slice($cincin, 1),
                'bb' => peta_rapi_bbox($cincin[0]),
            ];
        }
    }

    $sel = PETA_RAPI_SEL_DERAJAT;
    $petak = [];
    foreach ($poligon as $nomor => $p) {
        for ($gx = (int) floor($p['bb'][0] / $sel); $gx <= (int) floor($p['bb'][2] / $sel); $gx++) {
            for ($gy = (int) floor($p['bb'][1] / $sel); $gy <= (int) floor($p['bb'][3] / $sel); $gy++) {
                $petak[$gx . ':' . $gy][] = $nomor;
            }
        }
    }

    return ['poligon' => $poligon, 'sel' => $sel, 'petak' => $petak];
}

function peta_rapi_dalam_bidang(float $x, float $y, array $indeks): bool
{
    $sel = $indeks['sel'];
    $kunci = ((int) floor($x / $sel)) . ':' . ((int) floor($y / $sel));
    if (!isset($indeks['petak'][$kunci])) {
        return false;
    }
    foreach ($indeks['petak'][$kunci] as $nomor) {
        $p = $indeks['poligon'][$nomor];
        if ($x < $p['bb'][0] || $x > $p['bb'][2] || $y < $p['bb'][1] || $y > $p['bb'][3]) {
            continue;
        }
        if (!peta_rapi_dalam_ring($x, $y, $p['luar'])) {
            continue;
        }
        foreach ($p['lubang'] as $lubang) {
            if (peta_rapi_dalam_ring($x, $y, $lubang)) {
                continue 2;
            }
        }
        return true;
    }

    return false;
}

/** Douglas-Peucker dengan toleransi meter. */
function peta_rapi_sederhanakan(array $titik, float $toleransi): array
{
    $jumlah = count($titik);
    if ($jumlah < 3) {
        return $titik;
    }

    $ax = (float) $titik[0][0]; $ay = (float) $titik[0][1];
    $bx = (float) $titik[$jumlah - 1][0]; $by = (float) $titik[$jumlah - 1][1];
    $dx = ($bx - $ax) * 110570.0; $dy = ($by - $ay) * 111320.0;
    $panjang2 = $dx * $dx + $dy * $dy;

    $maks = -1.0;
    $potong = -1;
    for ($i = 1; $i < $jumlah - 1; $i++) {
        $px = ((float) $titik[$i][0] - $ax) * 110570.0;
        $py = ((float) $titik[$i][1] - $ay) * 111320.0;
        $t = $panjang2 > 0 ? ($px * $dx + $py * $dy) / $panjang2 : 0.0;
        $t = max(0.0, min(1.0, $t));
        $jarak = hypot($px - $t * $dx, $py - $t * $dy);
        if ($jarak > $maks) { $maks = $jarak; $potong = $i; }
    }
    if ($maks <= $toleransi) {
        return [$titik[0], $titik[$jumlah - 1]];
    }

    $kiri = peta_rapi_sederhanakan(array_slice($titik, 0, $potong + 1), $toleransi);
    $kanan = peta_rapi_sederhanakan(array_slice($titik, $potong), $toleransi);

    return array_merge(array_slice($kiri, 0, -1), $kanan);
}

/**
 * Potong satu garis di bagian yang masuk ke dalam bidang.
 *
 * @param array<int,array{0:float,1:float}> $titik
 * @return array<int,array<int,array{0:float,1:float}>>
 */
function peta_rapi_potong_garis(array $titik, array $indeks): array
{
    $hasil = [];
    $aktif = null;
    $jumlah = count($titik);

    for ($i = 1; $i < $jumlah; $i++) {
        $ax = (float) $titik[$i - 1][0]; $ay = (float) $titik[$i - 1][1];
        $bx = (float) $titik[$i][0];     $by = (float) $titik[$i][1];
        $panjang = peta_rapi_jarak_m($ax, $ay, $bx, $by);
        if ($panjang < 0.01) {
            continue;
        }
        $langkah = max(1, (int) ceil($panjang / PETA_RAPI_LANGKAH_M));

        for ($k = 0; $k < $langkah; $k++) {
            $tm = ($k + 0.5) / $langkah;
            $luar = !peta_rapi_dalam_bidang(
                $ax + ($bx - $ax) * $tm,
                $ay + ($by - $ay) * $tm,
                $indeks
            );

            if ($luar) {
                if ($aktif === null) {
                    $t = $k / $langkah;
                    $aktif = [[$ax + ($bx - $ax) * $t, $ay + ($by - $ay) * $t]];
                }
                $t = ($k + 1) / $langkah;
                $px = $ax + ($bx - $ax) * $t;
                $py = $ay + ($by - $ay) * $t;
                $akhir = $aktif[count($aktif) - 1];
                if ($akhir[0] !== $px || $akhir[1] !== $py) {
                    $aktif[] = [$px, $py];
                }
            } elseif ($aktif !== null) {
                $t = $k / $langkah;
                $aktif[] = [$ax + ($bx - $ax) * $t, $ay + ($by - $ay) * $t];
                if (count($aktif) >= 2) {
                    $hasil[] = $aktif;
                }
                $aktif = null;
            }
        }
    }

    if ($aktif !== null && count($aktif) >= 2) {
        $hasil[] = $aktif;
    }

    $rapi = [];
    foreach ($hasil as $bagian) {
        $bagian = array_map(
            static fn(array $p): array => [round($p[0], 6), round($p[1], 6)],
            peta_rapi_sederhanakan($bagian, PETA_RAPI_SEDERHANA_M)
        );
        $sama = true;
        $awal = $bagian[0];
        foreach ($bagian as $p) {
            if ($p !== $awal) { $sama = false; break; }
        }
        if (!$sama && count($bagian) >= 2) {
            $rapi[] = $bagian;
        }
    }

    return $rapi;
}

/** Panjang polyline dalam meter. */
function peta_rapi_panjang(array $titik): float
{
    $panjang = 0.0;
    for ($i = 1; $i < count($titik); $i++) {
        $panjang += peta_rapi_jarak_m(
            (float) $titik[$i - 1][0], (float) $titik[$i - 1][1],
            (float) $titik[$i][0], (float) $titik[$i][1]
        );
    }

    return $panjang;
}

/** Segmen lurus tidak menyentuh satu bidang pun. */
function peta_rapi_garis_bebas(array $a, array $b, array $indeks): bool
{
    $panjang = peta_rapi_jarak_m($a[0], $a[1], $b[0], $b[1]);
    $langkah = max(1, (int) ceil($panjang / 3.0));
    for ($s = 0; $s <= $langkah; $s++) {
        $t = $s / $langkah;
        if (peta_rapi_dalam_bidang($a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $indeks)) {
            return false;
        }
    }

    return true;
}

/**
 * Jalur terpendek dari $a ke $b melalui kisi 2 m yang tidak menembus bidang.
 * Dipakai saat garis lurus antara dua label perairan memotong persil.
 *
 * @return array<int,array{0:float,1:float}>|null
 */
function peta_rapi_jalur_bebas(array $a, array $b, array $indeks): ?array
{
    $sel = PETA_RAPI_JALUR_SEL_M;
    $margin = PETA_RAPI_JALUR_MARGIN_M;
    $minLon = min($a[0], $b[0]) - $margin / 110570.0;
    $maxLon = max($a[0], $b[0]) + $margin / 110570.0;
    $minLat = min($a[1], $b[1]) - $margin / 111320.0;
    $maxLat = max($a[1], $b[1]) + $margin / 111320.0;
    $selLon = $sel / 110570.0;
    $selLat = $sel / 111320.0;
    $kol = (int) ceil(($maxLon - $minLon) / $selLon) + 1;
    $bar = (int) ceil(($maxLat - $minLat) / $selLat) + 1;
    if ($kol * $bar > 200000) {
        return null;
    }

    $blokir = [];
    $tutup = static function (int $i, int $j) use (&$blokir, $kol, $bar, $minLon, $minLat, $selLon, $selLat, $indeks): bool {
        if ($i < 0 || $j < 0 || $i >= $kol || $j >= $bar) {
            return true;
        }
        $kunci = $j * $kol + $i;
        if (!isset($blokir[$kunci])) {
            $blokir[$kunci] = peta_rapi_dalam_bidang(
                $minLon + ($i + 0.5) * $selLon,
                $minLat + ($j + 0.5) * $selLat,
                $indeks
            );
        }

        return $blokir[$kunci];
    };
    $selBebas = static function (float $lon, float $lat) use ($tutup, $minLon, $minLat, $selLon, $selLat): ?array {
        $i0 = (int) round(($lon - $minLon) / $selLon - 0.5);
        $j0 = (int) round(($lat - $minLat) / $selLat - 0.5);
        for ($r = 0; $r <= 8; $r++) {
            for ($di = -$r; $di <= $r; $di++) {
                for ($dj = -$r; $dj <= $r; $dj++) {
                    if (max(abs($di), abs($dj)) !== $r) { continue; }
                    if (!$tutup($i0 + $di, $j0 + $dj)) { return [$i0 + $di, $j0 + $dj]; }
                }
            }
        }

        return null;
    };

    $awal = $selBebas($a[0], $a[1]);
    $tujuan = $selBebas($b[0], $b[1]);
    if ($awal === null || $tujuan === null) {
        return null;
    }
    $gi = $tujuan[0];
    $gj = $tujuan[1];
    $heuri = static fn(int $i, int $j): float => hypot(($i - $gi) * $sel, ($j - $gj) * $sel);

    $k0 = $awal[1] * $kol + $awal[0];
    $g = [$k0 => 0.0];
    $dari = [];
    $selesai = [];
    $buka = new SplPriorityQueue();
    $buka->insert($k0, -$heuri($awal[0], $awal[1]));
    $arah = [[1, 0, 1.0], [-1, 0, 1.0], [0, 1, 1.0], [0, -1, 1.0],
        [1, 1, M_SQRT2], [1, -1, M_SQRT2], [-1, 1, M_SQRT2], [-1, -1, M_SQRT2]];

    $ketemu = false;
    $kunjungan = 0;
    while (!$buka->isEmpty()) {
        $k = $buka->extract();
        if (isset($selesai[$k])) { continue; }
        $selesai[$k] = true;
        $i = $k % $kol;
        $j = intdiv($k, $kol);
        if ($i === $gi && $j === $gj) { $ketemu = true; break; }
        if (++$kunjungan > 120000) { return null; }

        foreach ($arah as [$di, $dj, $biaya]) {
            $ni = $i + $di;
            $nj = $j + $dj;
            if ($tutup($ni, $nj)) { continue; }
            if ($di !== 0 && $dj !== 0 && ($tutup($i + $di, $j) || $tutup($i, $j + $dj))) {
                continue; // jangan memotong sudut bidang
            }
            $nk = $nj * $kol + $ni;
            $baru = $g[$k] + $biaya * $sel;
            if (!isset($g[$nk]) || $baru < $g[$nk]) {
                $g[$nk] = $baru;
                $dari[$nk] = $k;
                $buka->insert($nk, -($baru + $heuri($ni, $nj)));
            }
        }
    }
    if (!$ketemu) {
        return null;
    }

    $jalur = [];
    $k = $tujuan[1] * $kol + $tujuan[0];
    while (true) {
        $jalur[] = [
            $minLon + (($k % $kol) + 0.5) * $selLon,
            $minLat + (intdiv($k, $kol) + 0.5) * $selLat,
        ];
        if ($k === $k0) { break; }
        if (!isset($dari[$k])) { return null; }
        $k = $dari[$k];
    }
    $jalur = array_reverse($jalur);
    $jalur[0] = [(float) $a[0], (float) $a[1]];
    $jalur[count($jalur) - 1] = [(float) $b[0], (float) $b[1]];

    $lurus = peta_rapi_jarak_m($a[0], $a[1], $b[0], $b[1]);
    if (peta_rapi_panjang($jalur) > $lurus * 3 + 40) {
        return null; // memutar terlalu jauh, lebih baik garis lurus
    }

    return peta_rapi_sederhanakan($jalur, 1.5);
}

/** Haluskan sudut polyline tanpa menggeser titik ke dalam bidang. */
function peta_rapi_haluskan(array $titik, array $indeks, int $putaran = 2): array
{
    $jumlah = count($titik);
    for ($p = 0; $p < $putaran; $p++) {
        for ($i = 1; $i < $jumlah - 1; $i++) {
            $x = ($titik[$i - 1][0] + 2 * $titik[$i][0] + $titik[$i + 1][0]) / 4;
            $y = ($titik[$i - 1][1] + 2 * $titik[$i][1] + $titik[$i + 1][1]) / 4;
            if (!peta_rapi_dalam_bidang($x, $y, $indeks)) {
                $titik[$i] = [$x, $y];
            }
        }
    }

    return peta_rapi_sederhanakan($titik, 1.0);
}

/** Ganti segmen hasil penghalusan/sederhana yang kembali menembus bidang. */
function peta_rapi_perbaiki(array $titik, array $indeks): array
{
    if (count($titik) < 2) {
        return $titik;
    }
    $keluar = [$titik[0]];
    for ($i = 1; $i < count($titik); $i++) {
        $a = $keluar[count($keluar) - 1];
        $b = $titik[$i];
        if (peta_rapi_garis_bebas($a, $b, $indeks)) {
            $keluar[] = $b;
            continue;
        }
        $lewat = peta_rapi_jalur_bebas($a, $b, $indeks);
        if ($lewat === null) {
            $keluar[] = $b;
            continue;
        }
        foreach (array_slice($lewat, 1) as $p) {
            $keluar[] = $p;
        }
    }

    return peta_rapi_sederhanakan($keluar, 1.0);
}

function peta_rapi_cache_dir(): string
{
    $dir = SIP_ROOT . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function peta_rapi_kunci_bidang(): string
{
    $tanda = db_value(
        "SELECT CONCAT(COUNT(*), '|', COALESCE(MAX(diubah_pada), ''), '|', COALESCE(MAX(id), 0))
         FROM bidang WHERE geom IS NOT NULL AND status <> 'dihapus'"
    );

    return PETA_RAPI_VERSI . '-' . md5((string) $tanda);
}

function peta_rapi_cache_baca(string $nama, string $kunci): ?array
{
    $path = peta_rapi_cache_dir() . '/' . $nama;
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data) || ($data['kunci'] ?? null) !== $kunci || !is_array($data['isi'] ?? null)) {
        return null;
    }

    return $data['isi'];
}

function peta_rapi_cache_tulis(string $nama, string $kunci, array $isi): void
{
    $isi = json_encode(['kunci' => $kunci, 'isi' => $isi], JSON_UNESCAPED_SLASHES);
    if (is_string($isi)) {
        @file_put_contents(peta_rapi_cache_dir() . '/' . $nama, $isi);
    }
}

/**
 * @param array<int,array{id:int,kelas:string,nama:string,titik:array}> $ruas
 * @return array<int,array> fitur GeoJSON jalan yang sudah dipotong
 */
function peta_jalan_rapi(array $ruas): array
{
    $kunci = peta_rapi_kunci_bidang();
    $cache = peta_rapi_cache_baca('peta-jalan-rapi.json', $kunci);
    if ($cache !== null) {
        return $cache;
    }

    $indeks = peta_rapi_indeks_bidang();
    $fitur = [];
    foreach ($ruas as $satu) {
        $bagian = peta_rapi_potong_garis($satu['titik'], $indeks);
        $nomor = 0;
        foreach ($bagian as $titik) {
            if (peta_rapi_panjang($titik) < PETA_RAPI_JALAN_MIN_M) {
                continue; // serpihan beberapa meter di tepi bidang
            }
            $nomor++;
            $fitur[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => $titik],
                'properties' => [
                    'id' => $satu['id'],
                    'kelas' => $satu['kelas'],
                    'nama' => $satu['nama'],
                    'bagian' => $nomor,
                ],
            ];
        }
    }

    peta_rapi_cache_tulis('peta-jalan-rapi.json', $kunci, $fitur);

    return $fitur;
}

/**
 * Sambung label perairan menjadi garis: kisi tetangga terdekat -> MST ->
 * jalur terpanjang tiap komponen. Sambungan yang memotong bidang dialihkan
 * lewat celah bebas. Label yang tidak punya dua kawan terdekat dikembalikan
 * sebagai titik.
 *
 * @param array<int,array{lon:float,lat:float,nama:string,jenis:string}> $label
 * @return array{garis:array<int,array>,titik:array<int,array>}
 */
function peta_sungai_rapi(array $label, string $tanda = ''): array
{
    $jumlah = count($label);
    if ($jumlah === 0) {
        return ['garis' => [], 'titik' => []];
    }

    $kunci = peta_rapi_kunci_bidang() . '|' . $tanda;
    $cache = peta_rapi_cache_baca('peta-sungai-rapi.json', $kunci);
    if ($cache !== null) {
        return $cache;
    }
    $indeks = peta_rapi_indeks_bidang();

    $dekat = [];
    for ($i = 0; $i < $jumlah; $i++) {
        $daftar = [];
        for ($j = 0; $j < $jumlah; $j++) {
            if ($i === $j) { continue; }
            $daftar[] = [
                $j,
                peta_rapi_jarak_m($label[$i]['lon'], $label[$i]['lat'], $label[$j]['lon'], $label[$j]['lat']),
            ];
        }
        usort($daftar, static fn(array $a, array $b): int => $a[1] <=> $b[1]);
        $dekat[$i] = array_slice($daftar, 0, PETA_RAPI_SUNGAI_TETANGGA);
    }

    $sisi = [];
    for ($i = 0; $i < $jumlah; $i++) {
        foreach ($dekat[$i] as [$j, $jarak]) {
            if ($jarak <= PETA_RAPI_SUNGAI_MAKS_M) {
                $sisi[] = [$i, $j, $jarak];
            }
        }
    }
    usort($sisi, static fn(array $a, array $b): int => $a[2] <=> $b[2]);

    $induk = range(0, $jumlah - 1);
    $akar = static function (int $a) use (&$induk): int {
        while ($induk[$a] !== $a) {
            $induk[$a] = $induk[$induk[$a]];
            $a = $induk[$a];
        }
        return $a;
    };
    $pohon = array_fill(0, $jumlah, []);
    foreach ($sisi as [$a, $b, $jarak]) {
        $ra = $akar($a);
        $rb = $akar($b);
        if ($ra !== $rb) {
            $induk[$ra] = $rb;
            $pohon[$a][] = [$b, $jarak];
            $pohon[$b][] = [$a, $jarak];
        }
    }

    $komponen = [];
    for ($i = 0; $i < $jumlah; $i++) {
        $komponen[$akar($i)][] = $i;
    }

    $jelajah = static function (int $awal) use ($jumlah, $pohon): array {
        $jarak = array_fill(0, $jumlah, -1.0);
        $dari = array_fill(0, $jumlah, -1);
        $jarak[$awal] = 0.0;
        $tumpuk = [$awal];
        while ($tumpuk) {
            $a = array_pop($tumpuk);
            foreach ($pohon[$a] as [$b, $w]) {
                if ($jarak[$b] < 0) {
                    $jarak[$b] = $jarak[$a] + $w;
                    $dari[$b] = $a;
                    $tumpuk[] = $b;
                }
            }
        }
        $terjauh = $awal;
        for ($i = 0; $i < $jumlah; $i++) {
            if ($jarak[$i] > $jarak[$terjauh]) { $terjauh = $i; }
        }

        return [$terjauh, $dari];
    };

    $garis = [];
    $lepas = [];
    foreach ($komponen as $anggota) {
        if (count($anggota) < 3) {
            foreach ($anggota as $i) { $lepas[] = $i; }
            continue;
        }
        $ujung = $jelajah($anggota[0])[0];
        [$terjauh, $dari] = $jelajah($ujung);

        $jalur = [];
        $i = $terjauh;
        while ($i !== -1) {
            $jalur[] = $i;
            $i = $dari[$i];
        }
        $jalur = array_reverse($jalur);

        $titik = [];
        $nama = '';
        $jenis = [];
        $sebelum = null;
        foreach ($jalur as $i) {
            $kini = [round($label[$i]['lon'], 7), round($label[$i]['lat'], 7)];
            if ($sebelum === null
                || peta_rapi_garis_bebas($sebelum, $kini, $indeks)) {
                $titik[] = $kini;
            } else {
                $lewat = peta_rapi_jalur_bebas($sebelum, $kini, $indeks);
                if ($lewat === null) {
                    $titik[] = $kini;
                } else {
                    foreach (array_slice($lewat, 1) as $p) {
                        $titik[] = [round((float) $p[0], 7), round((float) $p[1], 7)];
                    }
                }
            }
            $sebelum = $kini;
            $teks = strtoupper(trim($label[$i]['nama']));
            if ($nama === '' && !in_array($teks, PETA_RAPI_LABEL_UMUM, true)) {
                $nama = $label[$i]['nama'];
            }
            $jenis[] = $label[$i]['jenis'];
        }
        $titik = array_map(
            static fn(array $p): array => [round((float) $p[0], 7), round((float) $p[1], 7)],
            peta_rapi_perbaiki(peta_rapi_haluskan($titik, $indeks), $indeks)
        );
        $hitungJenis = array_count_values($jenis);
        arsort($hitungJenis);

        $garis[] = [
            'type' => 'Feature',
            'geometry' => ['type' => 'LineString', 'coordinates' => $titik],
            'properties' => [
                'nama' => $nama,
                'jenis' => (string) array_key_first($hitungJenis),
                'label' => count($jalur),
                'panjang_m' => (int) round(peta_rapi_panjang($titik)),
            ],
        ];
    }

    $titikLepas = [];
    foreach ($lepas as $i) {
        $titikLepas[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [round($label[$i]['lon'], 7), round($label[$i]['lat'], 7)],
            ],
            'properties' => ['nama' => $label[$i]['nama'], 'jenis' => $label[$i]['jenis']],
        ];
    }

    $hasil = ['garis' => $garis, 'titik' => $titikLepas];
    peta_rapi_cache_tulis('peta-sungai-rapi.json', $kunci, $hasil);

    return $hasil;
}
