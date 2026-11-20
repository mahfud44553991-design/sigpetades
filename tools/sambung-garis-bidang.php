<?php
/**
 * Sambung ujung bebas garis perairan mengikuti tepi bidang kadastrum.
 *
 * Tiap ujung bebas ditarik garis pendek (<= 3 m) ke tepi bidang terdekat yang
 * arahnya sejajar (<= 40 derajat), lalu ditelusuri mengikuti verteks tepi bidang
 * (belok <= 45 derajat) sampai mendekati ujung bebas lain (<= 3 m) yang arahnya
 * saling membelakangi. Titik baru hanya verteks tepi bidang dan titik proyeksi
 * tegak lurus pada tepi bidang; tidak ada titik karangan.
 *
 * pemakaian: sambung-garis-bidang.php [--attach=3.0] [--stop=3.0] [--terapkan]
 */
require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const BERKAS = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-perairan.geojson';
const META = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/metadata.json';
const JUDUL = 'GARIS BATAS PERAIRAN 020100';
const SIMPUL_TOL = 0.35;
const ATTACH_SUDUT = 40.0;
const JALAN_SUDUT = 45.0;
const STOP_ANTI = 0.3;
const MAKS_PANJANG = 400.0;
const MAKS_LANGKAH = 250;

$terapkan = in_array('--terapkan', $argv, true);
$attachMaks = 3.0;
$stopJarak = 3.0;
foreach ($argv as $a) {
    if (str_starts_with($a, '--attach=')) $attachMaks = (float) substr($a, 9);
    if (str_starts_with($a, '--stop=')) $stopJarak = (float) substr($a, 7);
}

function arah(array $a, array $b): array {
    $dx = $b[0] - $a[0]; $dy = $b[1] - $a[1]; $l = hypot($dx, $dy);
    return $l > 0 ? [$dx / $l, $dy / $l] : [0.0, 0.0];
}
function proyeksi(array $p, float $ax, float $ay, float $bx, float $by): array {
    $dx = $bx - $ax; $dy = $by - $ay; $l2 = $dx * $dx + $dy * $dy;
    if ($l2 < 1e-12) return [INF, 0.0, 0.0, 0.0];
    $u = (($p[0] - $ax) * $dx + ($p[1] - $ay) * $dy) / $l2;
    $u = max(0.0, min(1.0, $u));
    $qx = $ax + $u * $dx; $qy = $ay + $u * $dy;
    return [hypot($p[0] - $qx, $p[1] - $qy), $qx, $qy, $u];
}

// ---------------------------------------------------------------- perairan
$j = json_decode((string) file_get_contents(BERKAS), true);
$F = $j['features'];
$nAwal = count($F);
$wNode = []; $wKisi = [];
$ambilW = function (array $p) use (&$wNode, &$wKisi): int {
    $x = (int) floor($p[0]); $y = (int) floor($p[1]);
    for ($gx = $x - 1; $gx <= $x + 1; $gx++) for ($gy = $y - 1; $gy <= $y + 1; $gy++) {
        foreach ($wKisi["$gx:$gy"] ?? [] as $id) {
            if (hypot($wNode[$id][0] - $p[0], $wNode[$id][1] - $p[1]) <= SIMPUL_TOL) return $id;
        }
    }
    $id = count($wNode); $wNode[$id] = $p; $wKisi["$x:$y"][] = $id; return $id;
};
$derajat = []; $ujung = [];
foreach ($F as $i => $f) {
    if (($f['geometry']['type'] ?? '') !== 'LineString') continue;
    $t = $f['geometry']['coordinates'];
    if (count($t) < 2) continue;
    $a = $ambilW($t[0]); $b = $ambilW($t[count($t) - 1]);
    if ($a !== $b) {
        $derajat[$a] = ($derajat[$a] ?? 0) + 1;
        $derajat[$b] = ($derajat[$b] ?? 0) + 1;
    }
    if ($a === $b) continue; // cincin tertutup bukan ujung bebas
    $ujung[] = ['idx' => $i, 'ujung' => 'a', 'node' => $a, 'titik' => $t[0],
        'arah' => arah($t[1], $t[0]), 'kode' => (string) ($f['properties']['kode'] ?? ''),
        'label' => (string) ($f['properties']['label'] ?? '')];
    $ujung[] = ['idx' => $i, 'ujung' => 'b', 'node' => $b, 'titik' => $t[count($t) - 1],
        'arah' => arah($t[count($t) - 2], $t[count($t) - 1]), 'kode' => (string) ($f['properties']['kode'] ?? ''),
        'label' => (string) ($f['properties']['label'] ?? '')];
}
$bebas = [];
foreach ($ujung as $u) {
    if (($derajat[$u['node']] ?? 0) === 1) { $u['kunci'] = $u['idx'] . ':' . $u['ujung']; $bebas[] = $u; }
}
printf("perairan: %d baris, %d ujung bebas\n", $nAwal, count($bebas));

// ------------------------------------------------------- graf tepi bidang
$bNode = []; $bKisi = [];
$ambilB = function (array $p) use (&$bNode, &$bKisi): int {
    $x = (int) floor($p[0]); $y = (int) floor($p[1]);
    for ($gx = $x - 1; $gx <= $x + 1; $gx++) for ($gy = $y - 1; $gy <= $y + 1; $gy++) {
        foreach ($bKisi["$gx:$gy"] ?? [] as $id) {
            if (hypot($bNode[$id][0] - $p[0], $bNode[$id][1] - $p[1]) <= SIMPUL_TOL) return $id;
        }
    }
    $id = count($bNode); $bNode[$id] = $p; $bKisi["$x:$y"][] = $id; return $id;
};
$bEdges = []; $bAdj = []; $bKunci = []; $bSel = []; $kisiTepi = [];
$t0 = microtime(true); $pol = 0;
foreach ((array) db_all("SELECT ST_AsGeoJSON(geom) AS geojson FROM bidang WHERE status <> 'dihapus' AND geom IS NOT NULL") as $baris) {
    $g = json_decode((string) $baris['geojson'], true);
    if (!is_array($g) || !isset($g['type'])) continue;
    $bagian = $g['type'] === 'MultiPolygon' ? (array) $g['coordinates'] : ([$g['type'] === 'Polygon' ? $g['coordinates'] : []]);
    foreach ($bagian as $p) {
        $ring = array_map(fn(array $q) => wgs84KeDxf((float) $q[0], (float) $q[1]), (array) ($p[0] ?? []));
        $n = count($ring); if ($n < 4) continue; $pol++;
        for ($i = 1; $i < $n; $i++) {
            $a = $ring[$i - 1]; $b = $ring[$i];
            $na = $ambilB($a); $nb = $ambilB($b);
            if ($na === $nb) continue;
            $key = min($na, $nb) . ':' . max($na, $nb);
            if (isset($bKunci[$key])) continue;
            $bKunci[$key] = true;
            $e = count($bEdges);
            $bEdges[$e] = [$a[0], $a[1], $b[0], $b[1], $na, $nb];
            $bAdj[$na][$nb] = $e;
            $bAdj[$nb][$na] = $e;
            $x0 = (int) floor(min($a[0], $b[0]) / 10); $x1 = (int) floor(max($a[0], $b[0]) / 10);
            $y0 = (int) floor(min($a[1], $b[1]) / 10); $y1 = (int) floor(max($a[1], $b[1]) / 10);
            for ($x = $x0; $x <= $x1; $x++) for ($y = $y0; $y <= $y1; $y++) $kisiTepi["$x:$y"][] = $e;
        }
    }
}
printf("bidang: %d poligon, %d tepi unik, %d simpul (%.1f dtk)\n",
    $pol, count($bEdges), count($bNode), microtime(true) - $t0);

// --------------------------------------------------- pencarian sambungan
$cosAttach = cos(deg2rad(ATTACH_SUDUT));
$cosJalan = cos(deg2rad(JALAN_SUDUT));

$cariAttach = function (array $p, array $T) use ($kisiTepi, $bEdges, $cosAttach, $attachMaks): ?array {
    $x = (int) floor($p[0] / 10); $y = (int) floor($p[1] / 10);
    $best = null;
    for ($gx = $x - 1; $gx <= $x + 1; $gx++) for ($gy = $y - 1; $gy <= $y + 1; $gy++) {
        foreach ($kisiTepi["$gx:$gy"] ?? [] as $ei) {
            [$ax, $ay, $bx, $by] = $bEdges[$ei];
            [$d, $qx, $qy, $u] = proyeksi($p, $ax, $ay, $bx, $by);
            if ($d > $attachMaks) continue;
            $ux = $bx - $ax; $uy = $by - $ay; $l = hypot($ux, $uy);
            if ($l < 1e-9) continue;
            $ux /= $l; $uy /= $l;
            $dot = $ux * $T[0] + $uy * $T[1];
            if ($dot >= $cosAttach) { $dir = [$ux, $uy]; $dd = $dot; }
            elseif (-$dot >= $cosAttach) { $dir = [-$ux, -$uy]; $dd = -$dot; }
            else continue;
            if ($best === null || $d < $best['d'] - 1e-9
                || (abs($d - $best['d']) <= 1e-9 && $dd > $best['dd'])) {
                $best = ['d' => $d, 'q' => [$qx, $qy], 'dir' => $dir, 'ei' => $ei, 'dd' => $dd];
            }
        }
    }
    return $best;
};

$kartuBebas = [];
foreach ($bebas as $k => $u) {
    $kx = (int) floor($u['titik'][0] / 10); $ky = (int) floor($u['titik'][1] / 10);
    for ($gx = $kx - 1; $gx <= $kx + 1; $gx++) for ($gy = $ky - 1; $gy <= $ky + 1; $gy++) $kartuBebas["$gx:$gy"][] = $k;
}

$cariBerhenti = function (array $p0, array $p1, array $arah, string $kunciAwal, int $idxAwal) use ($bebas, $kartuBebas, $stopJarak): ?array {
    $dx = $p1[0] - $p0[0]; $dy = $p1[1] - $p0[1]; $l2 = $dx * $dx + $dy * $dy;
    if ($l2 < 1e-12) return null;
    $best = null;
    // sel mencakup kotak pembatas ruas, bukan hanya titik tengahnya
    $x0 = (int) floor((min($p0[0], $p1[0]) - $stopJarak) / 10);
    $x1 = (int) floor((max($p0[0], $p1[0]) + $stopJarak) / 10);
    $y0 = (int) floor((min($p0[1], $p1[1]) - $stopJarak) / 10);
    $y1 = (int) floor((max($p0[1], $p1[1]) + $stopJarak) / 10);
    for ($gx = $x0; $gx <= $x1; $gx++) for ($gy = $y0; $gy <= $y1; $gy++) {
        foreach ($kartuBebas["$gx:$gy"] ?? [] as $k) {
            $u = $bebas[$k];
            if ($u['kunci'] === $kunciAwal || $u['idx'] === $idxAwal) continue;
            $t = (($u['titik'][0] - $p0[0]) * $dx + ($u['titik'][1] - $p0[1]) * $dy) / $l2;
            $t = max(0.0, min(1.0, $t));
            $qx = $p0[0] + $t * $dx; $qy = $p0[1] + $t * $dy;
            $d = hypot($u['titik'][0] - $qx, $u['titik'][1] - $qy);
            if ($d > $stopJarak) continue;
            if ($u['arah'][0] * $arah[0] + $u['arah'][1] * $arah[1] > -STOP_ANTI) continue;
            if (($qx - $p0[0]) * $arah[0] + ($qy - $p0[1]) * $arah[1] < -0.05) continue;
            if ($best === null || $t < $best['t'] - 1e-9
                || (abs($t - $best['t']) <= 1e-9 && $d < $best['d'])) {
                $best = ['t' => $t, 'd' => $d, 'q' => [$qx, $qy], 'k' => $k];
            }
        }
    }
    return $best;
};

$calon = [];
$gagalAttach = 0; $gagalJalan = 0; $gagalPanjang = 0;
foreach ($bebas as $k => $be) {
    $att = $cariAttach($be['titik'], $be['arah']);
    if ($att === null) { $gagalAttach++; continue; }
    $e = $bEdges[$att['ei']];
    [$px, $py] = $att['q'];
    $keB = $att['dir'][0] * ($e[2] - $px) + $att['dir'][1] * ($e[3] - $py) >= 0;
    $jauh = $keB ? [$e[2], $e[3], $e[5]] : [$e[0], $e[1], $e[4]];
    $nodeProj = $keB ? $e[4] : $e[5];
    $path = [$be['titik'], [$px, $py]];
    $panjang = hypot($px - $be['titik'][0], $py - $be['titik'][1]);
    $arahJalan = $att['dir'];
    // cek berhenti di ruas penghubung pertama
    $stop = $cariBerhenti([$px, $py], [$jauh[0], $jauh[1]], $arahJalan, $be['kunci'], $be['idx']);
    if ($stop !== null) {
        $path[] = $stop['q']; $path[] = $bebas[$stop['k']]['titik'];
        $panjang += hypot($stop['q'][0] - $px, $stop['q'][1] - $py)
            + hypot($bebas[$stop['k']]['titik'][0] - $stop['q'][0], $bebas[$stop['k']]['titik'][1] - $stop['q'][1]);
        $calon[] = ['a' => $be['kunci'], 'b' => $bebas[$stop['k']]['kunci'], 'path' => $path,
            'panjang' => $panjang, 'simpul' => 1, 'kodeA' => $be['kode'], 'kodeB' => $bebas[$stop['k']]['kode'],
            'labelA' => $be['label'], 'labelB' => $bebas[$stop['k']]['label']];
        continue;
    }
    $cur = [$jauh[0], $jauh[1]];
    $arahJalan = arah([$px, $py], $cur);
    $panjang += hypot($cur[0] - $px, $cur[1] - $py);
    $path[] = $cur;
    $node = $jauh[2]; $dari = $nodeProj;
    $dilihat = [$nodeProj => true, $node => true];
    $simpul = 1; $sukses = null; $alasanGagal = 'buntu';
    for ($langkah = 0; $langkah < MAKS_LANGKAH; $langkah++) {
        $pilih = null;
        foreach ($bAdj[$node] ?? [] as $v => $ei) {
            if ($v === $dari || isset($dilihat[$v])) continue;
            $d = arah($bNode[$node], $bNode[$v]);
            $dot = $d[0] * $arahJalan[0] + $d[1] * $arahJalan[1];
            if ($dot < $cosJalan) continue;
            if ($pilih === null || $dot > $pilih['dot']) $pilih = ['v' => $v, 'ei' => $ei, 'dot' => $dot];
        }
        if ($pilih === null) break;
        $e = $bEdges[$pilih['ei']];
        $jauh = $e[4] === $node ? [$e[2], $e[3], $e[5]] : [$e[0], $e[1], $e[4]];
        $stop = $cariBerhenti($cur, [$jauh[0], $jauh[1]], $arahJalan, $be['kunci'], $be['idx']);
        if ($stop !== null) {
            $sukses = ['stop' => $stop, 'jauh' => $jauh];
            break;
        }
        $potong = hypot($jauh[0] - $cur[0], $jauh[1] - $cur[1]);
        if ($panjang + $potong > MAKS_PANJANG) { $alasanGagal = 'panjang'; break; }
        $panjang += $potong;
        $arahJalan = arah($cur, [$jauh[0], $jauh[1]]);
        $cur = [$jauh[0], $jauh[1]];
        $path[] = $cur;
        $dilihat[$pilih['v']] = true;
        $dari = $node; $node = $pilih['v']; $simpul++;
    }
    if ($sukses === null) { if ($alasanGagal === 'panjang') $gagalPanjang++; else $gagalJalan++; continue; }
    $stop = $sukses['stop'];
    $jauh = $sukses['jauh'];
    $panjang += hypot($stop['q'][0] - $cur[0], $stop['q'][1] - $cur[1]);
    $path[] = $stop['q'];
    $panjang += hypot($bebas[$stop['k']]['titik'][0] - $stop['q'][0], $bebas[$stop['k']]['titik'][1] - $stop['q'][1]);
    $path[] = $bebas[$stop['k']]['titik'];
    $calon[] = ['a' => $be['kunci'], 'b' => $bebas[$stop['k']]['kunci'], 'path' => $path,
        'panjang' => $panjang, 'simpul' => $simpul, 'kodeA' => $be['kode'], 'kodeB' => $bebas[$stop['k']]['kode'],
        'labelA' => $be['label'], 'labelB' => $bebas[$stop['k']]['label']];
}
printf("ujung tanpa tepi bidang dalam %.1f m/%.0f deg: %d\n", $attachMaks, ATTACH_SUDUT, $gagalAttach);
printf("penelusuran buntu: %d · melewati batas panjang: %d\n", $gagalJalan, $gagalPanjang);
printf("calon sambungan: %d\n", count($calon));

// pasangan: jalur terpendek dulu, tiap ujung dipakai sekali
usort($calon, fn($a, $b) => $a['panjang'] <=> $b['panjang']);
$dipakaiUjung = [];
$terpilih = [];
foreach ($calon as $c) {
    if (isset($dipakaiUjung[$c['a']]) || isset($dipakaiUjung[$c['b']])) continue;
    $dipakaiUjung[$c['a']] = true; $dipakaiUjung[$c['b']] = true;
    $terpilih[] = $c;
}
$panjangBaru = array_sum(array_column($terpilih, 'panjang'));
$titikBaru = array_sum(array_column($terpilih, 'simpul'));
printf("dipakai: %d sambungan, %d ujung, %.2f m, %d simpul bidang (+2 proyeksi tiap jalur)\n",
    count($terpilih), 2 * count($terpilih), $panjangBaru, $titikBaru);
$pita = ['<=10 m' => 0, '10-30 m' => 0, '30-60 m' => 0, '60-120 m' => 0, '>120 m' => 0];
foreach ($terpilih as $c) {
    $k = $c['panjang'] <= 10 ? '<=10 m' : ($c['panjang'] <= 30 ? '10-30 m' : ($c['panjang'] <= 60 ? '30-60 m' : ($c['panjang'] <= 120 ? '60-120 m' : '>120 m')));
    $pita[$k]++;
}
foreach ($pita as $k => $v) printf("  panjang %s: %d\n", $k, $v);
printf("%-22s %-22s %8s %7s %6s %6s %s\n", 'dari', 'ke', 'panjang', 'lurus', 'rasio', 'simpul', 'komponen');
$k0 = komponen($F);
$kompSekarang = $k0;
printf("komponen awal: %d\n", $k0);
foreach ($terpilih as $i => $c) {
    $p = $c['path'];
    $lurus = hypot($p[count($p) - 1][0] - $p[0][0], $p[count($p) - 1][1] - $p[0][1]);
    $ujian = $F;
    for ($q = 0; $q <= $i; $q++) {
        $ujian[] = ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => $terpilih[$q]['path']],
            'properties' => []];
    }
    $k = komponen($ujian);
    $turun = $kompSekarang - $k;
    printf("%-22s %-22s %8.2f %7.2f %6.2f %6d %d\n", $c['kodeA'], $c['kodeB'], $c['panjang'], $lurus,
        $lurus > 0 ? $c['panjang'] / $lurus : 0.0, $c['simpul'], $turun);
    if ($turun === 0 || $c['panjang'] > 100) {
        printf("    jalur: %s\n", json_encode($c['path'], JSON_UNESCAPED_SLASHES));
    }
    $kompSekarang = $k;
}

// ------------------------------------------------------------ komponen
function komponen(array $fitur): int {
    $id = 0; $peta = []; $induk = [];
    $cari = function ($x) use (&$induk) {
        while ($induk[$x] !== $x) { $induk[$x] = $induk[$induk[$x]]; $x = $induk[$x]; }
        return $x;
    };
    $kisi = [];
    $simpulFitur = [];
    foreach ($fitur as $i => $f) {
        $t = $f['geometry']['coordinates'];
        foreach ([$t[0], $t[count($t) - 1]] as $p) {
            $kx = (int) floor($p[0]); $ky = (int) floor($p[1]);
            $terpilih = null;
            for ($gx = $kx - 1; $gx <= $kx + 1; $gx++) for ($gy = $ky - 1; $gy <= $ky + 1; $gy++) {
                foreach ($kisi["$gx:$gy"] ?? [] as $q) {
                    if (hypot($q[0] - $p[0], $q[1] - $p[1]) <= SIMPUL_TOL) { $terpilih = $q; break 2; }
                }
            }
            if ($terpilih === null) { $terpilih = $p; $kisi[$kx . ':' . $ky][] = $p; }
            $key = round($terpilih[0], 2) . ':' . round($terpilih[1], 2);
            if (!isset($peta[$key])) { $peta[$key] = $id++; $induk[$peta[$key]] = $peta[$key]; }
            $simpulFitur[$i][] = $peta[$key];
        }
    }
    $komp = $id;
    foreach ($simpulFitur as $s) {
        if (count($s) < 2) continue;
        $ra = $cari($s[0]); $rb = $cari($s[1]);
        if ($ra !== $rb) { $induk[$ra] = $rb; $komp--; }
    }
    return $komp;
}
$Fbaru = $F;
$nomor = 0;
foreach ($F as $f) { // lanjutkan penomoran baris sambung yang sudah ada
    if (preg_match('/^PERAIRAN-SAMBUNG-(\d+)$/', (string) ($f['properties']['kode'] ?? ''), $m)) {
        $nomor = max($nomor, (int) $m[1]);
    }
}
$awalNomor = $nomor + 1;
foreach ($terpilih as $c) {
    $nomor++;
    $label = $c['labelA'] !== '' ? $c['labelA'] : $c['labelB'];
    $koord = [];
    foreach ($c['path'] as $pt) {
        $akhir = $koord === [] ? null : $koord[count($koord) - 1];
        if ($akhir === null || hypot($pt[0] - $akhir[0], $pt[1] - $akhir[1]) > 1e-9) $koord[] = $pt;
    }
    $Fbaru[] = [
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => $koord],
        'properties' => [
            'kode' => sprintf('PERAIRAN-SAMBUNG-%04d', $nomor),
            'sumber' => '020100',
            'indeks' => -1,
            'indeks_all' => [],
            'jumlah_bagian' => 1,
            'panjang_m' => round($c['panjang'], 2),
            'rasio_tepi_bidang' => 1,
            'asal' => 'bidang',
            'cara' => 'jahit-bidang',
            'jarak_label_m' => null,
            'label' => $label,
            'dirapikan' => 0,
            'jembatan_celah' => 0,
            'dari' => [$c['kodeA'], $c['kodeB']],
            'simpul_bidang' => $c['simpul'],
            'titik_tidak_asli' => 0,
        ],
    ];
}
$k1 = komponen($Fbaru);
printf("komponen 0.35 m: %d -> %d\n", $k0, $k1);

// verifikasi: semua titik tengah (bukan ujung perairan) harus di tepi bidang
$cekAsli = function (array $p) use ($kisiTepi, $bEdges): float {
    $x = (int) floor($p[0] / 10); $y = (int) floor($p[1] / 10);
    $d = INF;
    foreach ($kisiTepi["$x:$y"] ?? [] as $ei) {
        [$ax, $ay, $bx, $by] = $bEdges[$ei];
        [$dd] = proyeksi($p, $ax, $ay, $bx, $by);
        if ($dd < $d) $d = $dd;
    }
    return $d;
};
$maksSimpang = 0.0; $titikDiperiksa = 0;
foreach ($terpilih as $c) {
    $t = $c['path'];
    for ($i = 1; $i < count($t) - 1; $i++) {
        $d = $cekAsli($t[$i]);
        if ($d > $maksSimpang) $maksSimpang = $d;
        $titikDiperiksa++;
    }
}
printf("verifikasi tepi bidang: %d titik, simpangan maksimum %.2e m\n", $titikDiperiksa, $maksSimpang);

if ($terapkan) {
    if ($terpilih === []) { echo "TIDAK ADA yang diterapkan\n"; exit(0); }
    $folder = __DIR__ . '/../_lama/cadangan-sambung-bidang-' . date('Ymd');
    if (!is_dir($folder)) mkdir($folder, 0777, true);
    $st = date('Ymd-His');
    copy(BERKAS, "$folder/garis-perairan-sebelum-{$st}.geojson");
    copy(BERKAS, __DIR__ . "/../storage/cadangan/garis-perairan-sebelum-sambung-bidang-{$st}.geojson");
    $j['features'] = $Fbaru;
    $naskah = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = tempnam(dirname(BERKAS), 'perairan-');
    file_put_contents($tmp, $naskah);
    rename($tmp, BERKAS);
    $catatan = [];
    foreach ($terpilih as $i => $c) {
        $catatan[] = [
            'kode' => sprintf('PERAIRAN-SAMBUNG-%04d', $awalNomor + $i),
            'dari' => [$c['kodeA'], $c['kodeB']],
            'panjang_m' => round($c['panjang'], 2),
            'simpul_bidang' => $c['simpul'],
            'titik' => count($c['path']),
        ];
    }
    file_put_contents("$folder/sambungan-{$st}.json", json_encode($catatan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // metadata
    $meta = json_decode((string) file_get_contents(META), true);
    $l = $meta['layers'][JUDUL];
    $total = 0.0; $asal = []; $cara = [];
    foreach ($Fbaru as $f) {
        $t = $f['geometry']['coordinates'];
        for ($i = 1; $i < count($t); $i++) $total += hypot($t[$i][0] - $t[$i - 1][0], $t[$i][1] - $t[$i - 1][1]);
        $a = (string) ($f['properties']['asal'] ?? ''); $asal[$a] = ($asal[$a] ?? 0) + 1;
        $c = (string) ($f['properties']['cara'] ?? ''); $cara[$c] = ($cara[$c] ?? 0) + 1;
    }
    $l['features'] = count($Fbaru);
    $l['types'] = ['line' => count($Fbaru)];
    $l['panjang_m'] = (int) round($total);
    $l['asal'] = $asal;
    $l['cara'] = $cara;
    $l['dirapikan']['sesudah_baris'] = count($Fbaru);
    $l['dirapikan']['sesudah_panjang_m'] = round($total, 2);
    $lama = is_array($l['sambung_bidang'] ?? null) ? $l['sambung_bidang'] : [];
    $pasangan = array_merge((array) ($lama['pasangan'] ?? []), $catatan);
    $l['sambung_bidang'] = [
        'alat' => 'tools/sambung-garis-bidang.php',
        'pada' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('c'),
        'aturan' => 'ujung bebas perairan ditarik ke tepi bidang terdekat (<=3 m, sudut <=40 derajat), '
            . 'lalu ditelusuri mengikuti verteks tepi bidang (belok <=45 derajat) sampai mendekati ujung bebas '
            . 'lain (<=3 m) yang arahnya saling membelakangi (deviasi >=0,3 kosinus)',
        'ujung_bebas_sebelum' => (int) ($lama['ujung_bebas_sebelum'] ?? count($bebas)),
        'ujung_bebas_sesudah' => count($bebas) - 2 * count($terpilih),
        'sambungan' => (int) ($lama['sambungan'] ?? 0) + count($terpilih),
        'panjang_m' => round((float) ($lama['panjang_m'] ?? 0.0) + $panjangBaru, 2),
        'simpul_bidang' => (int) ($lama['simpul_bidang'] ?? 0) + $titikBaru,
        'titik_tidak_asli' => 0,
        'simpangan_maks_m' => round(max((float) ($lama['simpangan_maks_m'] ?? 0.0), $maksSimpang), 12),
        'komponen' => ['sebelum' => (int) ($lama['komponen']['sebelum'] ?? $k0), 'sesudah' => $k1],
        'pasangan' => $pasangan,
    ];
    $meta['layers'][JUDUL] = $l;
    $naskahMeta = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $tmpMeta = tempnam(dirname(META), 'meta-');
    file_put_contents($tmpMeta, $naskahMeta);
    rename($tmpMeta, META);
    printf("DITERAPKAN: %d baris %.2f m · metadata %d baris\n", count($Fbaru), $total, $l['features']);
}
