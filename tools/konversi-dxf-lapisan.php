<?php
declare(strict_types=1);

/**
 * Konversi layer DXF arsip lama menjadi GeoJSON untuk penampil peta-lama.php.
 *
 * Jalankan dari CLI, bukan lewat browser:
 *   php -d memory_limit=1024M tools/konversi-dxf-lapisan.php
 *
 * Sumber default: files/master peta/MASTER-dari-dwg.dxf
 * Keluaran: files/sig-pertanahan-gedong-lokal/data/dxf-layers/<kunci>.geojson
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

const SUMBER = __DIR__ . '/../files/master peta/MASTER-dari-dwg.dxf';
const TUJUAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';

/** Layer DXF => nama berkas GeoJSON. */
const LAPISAN = [
    'BIDANG HM' => 'bidang-hm',
    'HM' => 'hm',
    'OPTIMALISASI HM' => 'optimalisasi-hm',
    'lb_lai' => 'lb-lai',
    '010500' => '010500',
    '020400' => '020400',
    'NIB HM' => 'nib-hm',
    'BATAS DESA' => 'batas-desa',
    'BLOK' => 'blok',
    'BATAS JALAN' => 'batas-jalan',
];

$galat = fn (string $pesan): never => exit('GAGAL: ' . $pesan . PHP_EOL);

if (!is_file(SUMBER)) {
    $galat('berkas sumber tidak ditemukan: ' . SUMBER);
}

$fh = fopen(SUMBER, 'rb');
if ($fh === false) {
    $galat('berkas sumber tidak dapat dibuka.');
}

$fitur = [];
$jumlah = [];
$dilewati = [];
foreach (LAPISAN as $lapisan => $kunci) {
    $fitur[$lapisan] = [];
    $jumlah[$lapisan] = ['polygon' => 0, 'line' => 0, 'text' => 0, 'point' => 0];
    $dilewati[$lapisan] = 0;
}

/** @return array{0:string,1:string}|null */
function pasangan($fh): ?array
{
    $barisKode = fgets($fh);
    if ($barisKode === false) {
        return null;
    }
    $barisNilai = fgets($fh);
    if ($barisNilai === false) {
        return null;
    }
    return [trim($barisKode), trim($barisNilai)];
}

function rapikan_teks(string $teks): string
{
    $teks = str_replace(
        ['%%d', '%%D', '%%p', '%%P', '%%c', '%%C', '\\P'],
        ['°', '°', '±', '±', 'Ø', 'Ø', ' '],
        $teks
    );
    $teks = preg_replace('/\\\\[A-Za-z][^;]*;/', '', $teks) ?? $teks;
    $teks = str_replace(['{', '}'], '', $teks);
    return trim(preg_replace('/\s+/u', ' ', $teks) ?? $teks);
}

function luas_ring(array $titik): float
{
    $n = count($titik);
    if ($n < 3) {
        return 0.0;
    }
    $jumlah = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $a = $titik[$i];
        $b = $titik[($i + 1) % $n];
        $jumlah += $a[0] * $b[1] - $b[0] * $a[1];
    }
    return abs($jumlah) / 2.0;
}

function titik_di_ring(float $x, float $y, array $ring): bool
{
    $n = count($ring);
    $dalam = false;
    for ($i = 0, $k = $n - 1; $i < $n; $k = $i++) {
        $xi = $ring[$i][0];
        $yi = $ring[$i][1];
        $xk = $ring[$k][0];
        $yk = $ring[$k][1];
        if ((($yi > $y) !== ($yk > $y)) && ($x < ($xk - $xi) * ($y - $yi) / ($yk - $yi) + $xi)) {
            $dalam = !$dalam;
        }
    }
    return $dalam;
}

/** Bagian luas ring yang jatuh di dalam gabungan poligon lain, diukur dengan contoh titik kisi. */
function cakupan_ring(array $ring, array $bb, float $luas, array $poligon): float
{
    $langkah = max(0.5, min(2.0, sqrt(max($luas, 1.0) / 300.0)));
    $kandidat = [];
    foreach ($poligon as $i => $p) {
        if ($p['bb'][2] < $bb[0] || $p['bb'][0] > $bb[2] || $p['bb'][3] < $bb[1] || $p['bb'][1] > $bb[3]) {
            continue;
        }
        $kandidat[] = $i;
    }
    if ($kandidat === []) {
        return 0.0;
    }
    $dalam = 0;
    $kena = 0;
    for ($x = $bb[0] + $langkah / 2; $x < $bb[2]; $x += $langkah) {
        for ($y = $bb[1] + $langkah / 2; $y < $bb[3]; $y += $langkah) {
            if (!titik_di_ring($x, $y, $ring)) {
                continue;
            }
            $dalam++;
            foreach ($kandidat as $i) {
                if (titik_di_ring($x, $y, $poligon[$i]['r'])) {
                    $kena++;
                    break;
                }
            }
        }
    }
    return $dalam > 0 ? $kena / $dalam : 0.0;
}

/** Rata-rata jarak tiap titik ring A ke titik terdekat pada ring B. */
function jarak_rata_ring(array $a, array $b): float
{
    if ($a === [] || $b === []) {
        return INF;
    }
    $jumlah = 0.0;
    foreach ($a as $pa) {
        $dekat = INF;
        foreach ($b as $pb) {
            $d = hypot($pa[0] - $pb[0], $pa[1] - $pb[1]);
            if ($d < $dekat) {
                $dekat = $d;
            }
        }
        $jumlah += $dekat;
    }
    return $jumlah / count($a);
}

/**
 * Titik-titik pada busur antara dua titik LWPOLYLINE dengan faktor bulge.
 *
 * @return list<array{0:float,1:float}> titik antara (tanpa titik awal)
 */
function busur(float $x1, float $y1, float $x2, float $y2, float $bulge): array
{
    if (abs($bulge) < 1e-12) {
        return [];
    }
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $tali = hypot($dx, $dy);
    if ($tali < 1e-12) {
        return [];
    }
    $sudut = 4.0 * atan($bulge);
    $jari = $tali / (2.0 * sin(abs($sudut) / 2.0));
    $setengah = $tali / 2.0;
    $geser = sqrt(max(0.0, $jari * $jari - $setengah * $setengah));
    $mx = ($x1 + $x2) / 2.0;
    $my = ($y1 + $y2) / 2.0;
    $nx = -$dy / $tali;
    $ny = $dx / $tali;
    $arah = $bulge > 0 ? 1.0 : -1.0;
    $cx = $mx + $nx * $geser * $arah;
    $cy = $my + $ny * $geser * $arah;

    $a1 = atan2($y1 - $cy, $x1 - $cx);
    $a2 = atan2($y2 - $cy, $x2 - $cx);
    $delta = $a2 - $a1;
    if ($bulge > 0) {
        while ($delta <= 0) {
            $delta += 2 * M_PI;
        }
    } else {
        while ($delta >= 0) {
            $delta -= 2 * M_PI;
        }
    }
    $langkah = max(2, (int) ceil(abs($delta) / (M_PI / 18)));
    $titik = [];
    for ($i = 1; $i < $langkah; $i++) {
        $a = $a1 + $delta * $i / $langkah;
        $titik[] = [$cx + $jari * cos($a), $cy + $jari * sin($a)];
    }
    return $titik;
}

function simpan(string $kunci, string $jenis, array $geometri, array $sifat): void
{
    global $fitur, $jumlah;
    $fitur[$kunci][] = [
        'type' => 'Feature',
        'properties' => $sifat,
        'geometry' => $geometri,
    ];
    $jumlah[$kunci][$jenis]++;
}

$bagian = '';
$menungguBagian = false;
$entitas = null;

while (($p = pasangan($fh)) !== null) {
    [$kode, $nilai] = $p;

    if ($kode === '0') {
        if ($entitas !== null) {
            proses_entitas($entitas);
            $entitas = null;
        }
        if ($nilai === 'SECTION') {
            $bagian = '';
            $menungguBagian = true;
            continue;
        }
        if ($nilai === 'ENDSEC') {
            $bagian = '';
            $menungguBagian = false;
            continue;
        }
        if ($bagian === 'ENTITIES') {
            $entitas = ['jenis' => $nilai, 'data' => []];
        }
        continue;
    }

    if ($menungguBagian && $kode === '2') {
        $bagian = $nilai;
        $menungguBagian = false;
        continue;
    }

    if ($entitas !== null) {
        $entitas['data'][] = [$kode, $nilai];
    }
}

function proses_entitas(array $entitas): void
{
    global $dilewati;

    $lapisan = null;
    $nilai = [];
    foreach ($entitas['data'] as [$kode, $v]) {
        if ($kode === '8' && $lapisan === null) {
            $lapisan = $v;
            continue;
        }
        $nilai[$kode][] = $v;
    }
    if ($lapisan === null || !isset(LAPISAN[$lapisan])) {
        return;
    }
    $kunci = $lapisan;

    $angka = static fn (string $kode, int $i = 0, float $bawaan = 0.0): float
        => isset($nilai[$kode][$i]) ? (float) str_replace(',', '.', $nilai[$kode][$i]) : $bawaan;

    switch ($entitas['jenis']) {
        case 'LWPOLYLINE':
            $tertutup = ((int) $angka('70') & 1) === 1;
            $x = $nilai['10'] ?? [];
            $y = $nilai['20'] ?? [];
            $bulge = $nilai['42'] ?? [];
            $n = min(count($x), count($y));
            if ($n < 2) {
                $dilewati[$kunci]++;
                return;
            }
            $titik = [];
            for ($i = 0; $i < $n; $i++) {
                $x1 = (float) $x[$i];
                $y1 = (float) $y[$i];
                $titik[] = [$x1, $y1];
                $b = isset($bulge[$i]) ? (float) $bulge[$i] : 0.0;
                if (abs($b) > 1e-12 && $i + 1 < $n) {
                    foreach (busur($x1, $y1, (float) $x[$i + 1], (float) $y[$i + 1], $b) as $t) {
                        $titik[] = $t;
                    }
                }
            }
            if ($tertutup && abs((float) ($bulge[$n - 1] ?? 0)) > 1e-12) {
                $akhir = $titik[0];
                foreach (busur((float) $x[$n - 1], (float) $y[$n - 1], (float) $x[0], (float) $y[0], (float) $bulge[$n - 1]) as $t) {
                    $titik[] = $t;
                }
                $titik[] = $akhir;
            }
            if (!$tertutup) {
                $awal = $titik[0];
                $akhir = $titik[count($titik) - 1];
                $celah = hypot($akhir[0] - $awal[0], $akhir[1] - $awal[1]);
                $ring = $titik;
                if ($celah > 1e-9) {
                    $ring[] = $awal;
                }
                if ($celah <= 1.0 && luas_ring($ring) >= 20.0) {
                    simpan($kunci, 'polygon', ['type' => 'Polygon', 'coordinates' => [$ring]], [
                        'layer' => $lapisan,
                        'closed' => true,
                        'auto_closed' => true,
                        'gap' => round($celah, 3),
                    ]);
                    return;
                }
                simpan($kunci, 'line', ['type' => 'LineString', 'coordinates' => $titik], [
                    'layer' => $lapisan,
                    'closed' => false,
                ]);
                return;
            }
            if (count($titik) < 4 || $titik[0] !== $titik[count($titik) - 1]) {
                $titik[] = $titik[0];
            }
            if (count($titik) < 4) {
                $dilewati[$kunci]++;
                return;
            }
            simpan($kunci, 'polygon', ['type' => 'Polygon', 'coordinates' => [$titik]], [
                'layer' => $lapisan,
                'closed' => true,
            ]);
            return;

        case 'LINE':
            $x1 = $angka('10');
            $y1 = $angka('20');
            $x2 = $angka('11');
            $y2 = $angka('21');
            if (hypot($x2 - $x1, $y2 - $y1) < 1e-12) {
                $dilewati[$kunci]++;
                return;
            }
            simpan($kunci, 'line', [
                'type' => 'LineString',
                'coordinates' => [[$x1, $y1], [$x2, $y2]],
            ], ['layer' => $lapisan, 'closed' => false]);
            return;

        case 'TEXT':
            $teks = rapikan_teks($nilai['1'][0] ?? '');
            if ($teks === '') {
                return;
            }
            $px = $nilai['10'][0] ?? null;
            $py = $nilai['20'][0] ?? null;
            if (((int) $angka('72') !== 0 || (int) $angka('73') !== 0)
                && isset($nilai['11'][0], $nilai['21'][0])) {
                $px = $nilai['11'][0];
                $py = $nilai['21'][0];
            }
            if ($px === null || $py === null) {
                $dilewati[$kunci]++;
                return;
            }
            simpan($kunci, 'text', [
                'type' => 'Point',
                'coordinates' => [(float) $px, (float) $py],
            ], [
                'layer' => $lapisan,
                'label' => $teks,
                'height' => $angka('40', 0, 0.0),
                'rotasi' => $angka('50', 0, 0.0),
            ]);
            return;

        case 'MTEXT':
            $teks = rapikan_teks(implode('', $nilai['3'] ?? []) . ($nilai['1'][0] ?? ''));
            if ($teks === '') {
                return;
            }
            if (!isset($nilai['10'][0], $nilai['20'][0])) {
                $dilewati[$kunci]++;
                return;
            }
            simpan($kunci, 'text', [
                'type' => 'Point',
                'coordinates' => [(float) $nilai['10'][0], (float) $nilai['20'][0]],
            ], [
                'layer' => $lapisan,
                'label' => $teks,
                'height' => $angka('40', 0, 0.0),
                'rotasi' => $angka('50', 0, 0.0),
            ]);
            return;

        case 'INSERT':
            if (!isset($nilai['10'][0], $nilai['20'][0])) {
                return;
            }
            simpan($kunci, 'point', [
                'type' => 'Point',
                'coordinates' => [(float) $nilai['10'][0], (float) $nilai['20'][0]],
            ], [
                'layer' => $lapisan,
                'blok' => $nilai['2'][0] ?? '',
            ]);
            return;

        case 'POINT':
        case 'CIRCLE':
            if (!isset($nilai['10'][0], $nilai['20'][0])) {
                return;
            }
            simpan($kunci, 'point', [
                'type' => 'Point',
                'coordinates' => [(float) $nilai['10'][0], (float) $nilai['20'][0]],
            ], ['layer' => $lapisan]);
            return;

        default:
            $dilewati[$kunci]++;
            return;
    }
}

fclose($fh);

$berkasMeta = TUJUAN . '/metadata.json';
$meta = is_file($berkasMeta)
    ? json_decode((string) file_get_contents($berkasMeta), true) ?: []
    : [];
$meta['source_file'] = $meta['source_file'] ?? 'MASTER-dari-dwg.dxf';
$meta['layers'] = $meta['layers'] ?? [];

foreach (LAPISAN as $lapisan => $kunci) {
    if ($fitur[$lapisan] === []) {
        echo "PERINGATAN: layer {$lapisan} tidak menghasilkan fitur.\n";
        continue;
    }
    $naskah = json_encode(
        ['type' => 'FeatureCollection', 'features' => $fitur[$lapisan]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($naskah === false) {
        $galat('gagal menyusun JSON layer ' . $lapisan);
    }
    file_put_contents(TUJUAN . '/' . $kunci . '.geojson', $naskah);

    $jenis = array_filter($jumlah[$lapisan], static fn (int $n): bool => $n > 0);
    $meta['layers'][$lapisan] = [
        'features' => count($fitur[$lapisan]),
        'types' => $jenis,
    ];
    if ($dilewati[$lapisan] > 0) {
        $meta['layers'][$lapisan]['invalid_skipped'] = $dilewati[$lapisan];
    }
    $meta['layers'][$lapisan]['file'] = $kunci . '.geojson';

    printf(
        "%-16s %-22s %6d fitur  %s\n",
        $lapisan,
        $kunci . '.geojson',
        count($fitur[$lapisan]),
        json_encode($jenis)
    );
}

// ---- calon bidang: garis 020100 yang hampir tertutup tetapi belum jadi poligon
$berkas020100 = TUJUAN . '/020100.geojson';
if (is_file($berkas020100)) {
    $sumber = json_decode((string) file_get_contents($berkas020100), true);
    if (!is_array($sumber)) {
        $galat('020100.geojson tidak dapat dibaca.');
    }

    $cincin = [];
    $garis = [];
    foreach ((array) ($sumber['features'] ?? []) as $f) {
        $g = $f['geometry'] ?? null;
        if (!is_array($g)) {
            continue;
        }
        if (($g['type'] ?? '') === 'Polygon') {
            $r = $g['coordinates'][0] ?? [];
            if (count($r) >= 3) {
                $xs = array_column($r, 0);
                $ys = array_column($r, 1);
                $cincin[] = [
                    'r' => $r,
                    'bb' => [min($xs), min($ys), max($xs), max($ys)],
                    'a' => luas_ring($r),
                ];
            }
        } elseif (($g['type'] ?? '') === 'LineString') {
            $garis[] = $g['coordinates'];
        }
    }

    $calon = [];
    $duplikat = 0;
    $sudahHm = 0;
    $sudah020 = 0;

    $bidangHm = [];
    $berkasHm = TUJUAN . '/bidang-hm.geojson';
    if (is_file($berkasHm)) {
        $dataHm = json_decode((string) file_get_contents($berkasHm), true);
        foreach ((array) ($dataHm['features'] ?? []) as $f) {
            $g = $f['geometry'] ?? null;
            if (!is_array($g) || ($g['type'] ?? '') !== 'Polygon') {
                continue;
            }
            $r = $g['coordinates'][0] ?? [];
            if (count($r) < 3) {
                continue;
            }
            $xs = array_column($r, 0);
            $ys = array_column($r, 1);
            $bidangHm[] = ['r' => $r, 'bb' => [min($xs), min($ys), max($xs), max($ys)]];
        }
    }

    foreach ($garis as $c) {
        $n = count($c);
        if ($n < 4) {
            continue;
        }
        $awal = $c[0];
        $akhir = $c[$n - 1];
        $celah = hypot($akhir[0] - $awal[0], $akhir[1] - $awal[1]);
        if ($celah > 1.0) {
            continue;
        }
        $ring = $c;
        if ($celah > 1e-9) {
            $ring[] = $awal;
        }
        $luas = luas_ring($ring);
        if ($luas < 20.0) {
            continue;
        }

        $px = 0.0;
        $py = 0.0;
        foreach ($ring as $p) {
            $px += $p[0];
            $py += $p[1];
        }
        $px /= count($ring);
        $py /= count($ring);

        $sama = false;
        foreach ($cincin as $p) {
            if ($px < $p['bb'][0] || $px > $p['bb'][2] || $py < $p['bb'][1] || $py > $p['bb'][3]) {
                continue;
            }
            if (!titik_di_ring($px, $py, $p['r'])) {
                continue;
            }
            $rasio = $p['a'] > 0 ? $luas / $p['a'] : 0.0;
            if ($rasio >= 0.95 && $rasio <= 1.05 && jarak_rata_ring($ring, $p['r']) <= 2.0) {
                $sama = true;
                break;
            }
        }
        if ($sama) {
            $duplikat++;
            continue;
        }

        $xs = array_column($ring, 0);
        $ys = array_column($ring, 1);
        $bbRing = [min($xs), min($ys), max($xs), max($ys)];
        $kenaHm = cakupan_ring($ring, $bbRing, $luas, $bidangHm);
        $kena020 = $kenaHm >= 0.5 ? 0.0 : cakupan_ring($ring, $bbRing, $luas, $cincin);
        if ($kenaHm >= 0.5) {
            $sudahHm++;
            continue;
        }
        if ($kena020 >= 0.5) {
            $sudah020++;
            continue;
        }

        $calon[] = [
            'type' => 'Feature',
            'properties' => [
                'layer' => '020100',
                'closed' => true,
                'auto_closed' => true,
                'gap' => round($celah, 3),
                'luas_unit2' => round($luas, 1),
            ],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]],
        ];
    }

    $naskah = json_encode(
        ['type' => 'FeatureCollection', 'features' => $calon],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($naskah === false) {
        $galat('gagal menyusun JSON calon bidang.');
    }
    file_put_contents(TUJUAN . '/calon-bidang.geojson', $naskah);
    $meta['layers']['CALON BIDANG'] = [
        'features' => count($calon),
        'types' => ['polygon' => count($calon)],
        'file' => 'calon-bidang.geojson',
        'derived_from' => 'garis terbuka 020100 dengan celah ujung <= 1 unit dan luas >= 20 unit²',
        'duplicates_skipped' => $duplikat,
        'already_mapped_skipped' => $sudahHm + $sudah020,
        'already_mapped_detail' => ['bidang_hm' => $sudahHm, 'tertutup_020100' => $sudah020],
    ];
    $catatan = [];
    foreach ((array) ($meta['notes'] ?? []) as $n) {
        if (strpos((string) $n, 'CALON BIDANG:') !== 0) {
            $catatan[] = $n;
        }
    }
    $catatan[] = sprintf(
        'CALON BIDANG: %s poligon dari garis 020100 yang ujungnya hampir bertemu tetapi belum ditutup; '
        . '%s kandidat dilewati karena bentuknya sama dengan poligon tertutup, dan %s karena sudah tertutup '
        . 'bidang HM (%s) atau poligon tertutup 020100 (%s). Hanya untuk menandai bidang yang belum '
        . 'terpetakan, bukan bidang resmi.',
        number_format(count($calon), 0, ',', '.'),
        number_format($duplikat, 0, ',', '.'),
        number_format($sudahHm + $sudah020, 0, ',', '.'),
        number_format($sudahHm, 0, ',', '.'),
        number_format($sudah020, 0, ',', '.')
    );
    $meta['notes'] = $catatan;
    printf(
        "%-16s %-22s %6d fitur  (duplikat %d, sudah terpetakan %d)\n",
        'CALON BIDANG',
        'calon-bidang.geojson',
        count($calon),
        $duplikat,
        $sudahHm + $sudah020
    );
} else {
    echo "PERINGATAN: 020100.geojson tidak ada; calon bidang dilewati.\n";
}

$meta['converted_at'] = date('c');
file_put_contents(
    $berkasMeta,
    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
);
echo "metadata.json diperbarui.\n";
