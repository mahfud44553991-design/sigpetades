<?php
/**
 * Susulkan tiap ujung bebas garis perairan MENYUSURI tepi bidang: ujung ditarik
 * ke tepi bidang terdekat (<= 3 m), ditelusuri lewat graf tepi bidang sampai
 * mendekati garis perairan lain (<= 3 m), lalu dilabuhkan tegak lurus.
 *
 * Jalur tepi dipakai hanya bila panjangnya <= 3 kali jarak lurus ke target;
 * selebihnya ujung tetap ditarik lurus (jembatan pendek) supaya tidak memutar
 * jauh mengelilingi blok. Jembatan lurus menolak kandidat yang memotong garis
 * perairan lain atau jembatan lain yang sudah dipilih.
 *
 * pemakaian: sambung-sungai-tepi.php [--attach=3] [--stop=3] [--rasio=3]
 *            [--maks=400] [--D=250] [--berkas=...] [--terapkan]
 */
require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const BERKAS = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-perairan.geojson';
const META = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/metadata.json';
const JUDUL = 'GARIS BATAS PERAIRAN 020100';
const SIMPUL_TOL = 0.35;

$terapkan = in_array('--terapkan', $argv, true);
$attachMaks = 3.0; $stopJarak = 3.0; $rasioMaks = 3.0; $maks = 400.0; $D = 250.0;
$berkas = BERKAS; $keluar = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--attach=')) $attachMaks = (float) substr($a, 9);
    if (str_starts_with($a, '--stop=')) $stopJarak = (float) substr($a, 7);
    if (str_starts_with($a, '--rasio=')) $rasioMaks = (float) substr($a, 8);
    if (str_starts_with($a, '--maks=')) $maks = (float) substr($a, 7);
    if (str_starts_with($a, '--D=')) $D = (float) substr($a, 4);
    if (str_starts_with($a, '--berkas=')) $berkas = substr($a, 9);
    if (str_starts_with($a, '--keluar=')) $keluar = substr($a, 9);
}

function proyeksi(array $p, float $ax, float $ay, float $bx, float $by): array {
    $dx = $bx - $ax; $dy = $by - $ay; $l2 = $dx * $dx + $dy * $dy;
    if ($l2 < 1e-12) return [INF, 0.0, 0.0, 0.0];
    $u = (($p[0] - $ax) * $dx + ($p[1] - $ay) * $dy) / $l2;
    $u = max(0.0, min(1.0, $u));
    $qx = $ax + $u * $dx; $qy = $ay + $u * $dy;
    return [hypot($p[0] - $qx, $p[1] - $qy), $qx, $qy, $u];
}

function dekatRuas(array $p1, array $p2, array $q1, array $q2): array {
    $d1x = $p2[0] - $p1[0]; $d1y = $p2[1] - $p1[1];
    $d2x = $q2[0] - $q1[0]; $d2y = $q2[1] - $q1[1];
    $rx = $p1[0] - $q1[0]; $ry = $p1[1] - $q1[1];
    $a = $d1x * $d1x + $d1y * $d1y;
    $e = $d2x * $d2x + $d2y * $d2y;
    $f = $d2x * $rx + $d2y * $ry;
    $c = $d1x * $rx + $d1y * $ry;
    $b = $d1x * $d2x + $d1y * $d2y;
    $den = $a * $e - $b * $b;
    $t = $den > 1e-12 ? max(0.0, min(1.0, ($b * $f - $c * $e) / $den)) : 0.0;
    $s = $e > 1e-12 ? ($b * $t + $f) / $e : 0.0;
    $s = max(0.0, min(1.0, $s));
    $t = $a > 1e-12 ? max(0.0, min(1.0, ($b * $s - $c) / $a)) : 0.0;
    $qx = $p1[0] + $t * $d1x; $qy = $p1[1] + $t * $d1y;
    $ux = $q1[0] + $s * $d2x; $uy = $q1[1] + $s * $d2y;
    return [$t, $s, hypot($qx - $ux, $qy - $uy), $qx, $qy, $ux, $uy];
}

function memotongRuas(array $p, array $q, array $s): bool {
    [$x1, $y1] = $p; [$x2, $y2] = $q;
    $x3 = $s[1]; $y3 = $s[2]; $x4 = $s[3]; $y4 = $s[4];
    $d1 = ($x2 - $x1) * ($y3 - $y1) - ($y2 - $y1) * ($x3 - $x1);
    $d2 = ($x2 - $x1) * ($y4 - $y1) - ($y2 - $y1) * ($x4 - $x1);
    $d3 = ($x4 - $x3) * ($y1 - $y3) - ($y4 - $y3) * ($x1 - $x3);
    $d4 = ($x4 - $x3) * ($y2 - $y3) - ($y4 - $y3) * ($x2 - $x3);
    return (($d1 > 1e-9 && $d2 < -1e-9) || ($d1 < -1e-9 && $d2 > 1e-9))
        && (($d3 > 1e-9 && $d4 < -1e-9) || ($d3 < -1e-9 && $d4 > 1e-9));
}

// ------------------------------------------------------------- perairan
$j = json_decode((string) file_get_contents($berkas), true);
$F = $j['features'];
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
$derajat = []; $ujung = []; $nodeFitur = [];
foreach ($F as $i => $f) {
    if (($f['geometry']['type'] ?? '') !== 'LineString') continue;
    $t = $f['geometry']['coordinates'];
    if (count($t) < 2) continue;
    $a = $ambilW($t[0]); $b = $ambilW($t[count($t) - 1]);
    $nodeFitur[$i] = [$a, $b];
    if ($a === $b) continue;
    $derajat[$a] = ($derajat[$a] ?? 0) + 1;
    $derajat[$b] = ($derajat[$b] ?? 0) + 1;
    $ujung[] = ['idx' => $i, 'node' => $a, 'titik' => $t[0], 'ujung' => 'a',
        'kode' => (string) ($f['properties']['kode'] ?? ''), 'label' => (string) ($f['properties']['label'] ?? '')];
    $ujung[] = ['idx' => $i, 'node' => $b, 'titik' => $t[count($t) - 1], 'ujung' => 'b',
        'kode' => (string) ($f['properties']['kode'] ?? ''), 'label' => (string) ($f['properties']['label'] ?? '')];
}
$bebas = [];
foreach ($ujung as $u) if (($derajat[$u['node']] ?? 0) === 1) { $u['kunci'] = $u['idx'] . ':' . $u['ujung']; $bebas[] = $u; }

$induk = [];
$cari = function ($x) use (&$induk) { while ($induk[$x] !== $x) { $induk[$x] = $induk[$induk[$x]]; $x = $induk[$x]; } return $x; };
for ($i = 0; $i < count($wNode); $i++) $induk[$i] = $i;
foreach ($nodeFitur as $s) { $ra = $cari($s[0]); $rb = $cari($s[1]); if ($ra !== $rb) $induk[$ra] = $rb; }
$komp = [];
foreach ($nodeFitur as $i => $s) $komp[$i] = $cari($s[0]);
$k0 = count(array_unique($komp));

$wSeg = []; $kisiAir = [];
foreach ($F as $i => $f) {
    $t = $f['geometry']['coordinates'];
    for ($q = 1; $q < count($t); $q++) {
        $si = count($wSeg);
        $wSeg[$si] = [$i, $t[$q - 1][0], $t[$q - 1][1], $t[$q][0], $t[$q][1]];
        $x0 = (int) floor(min($wSeg[$si][1], $wSeg[$si][3]) / 10); $x1 = (int) floor(max($wSeg[$si][1], $wSeg[$si][3]) / 10);
        $y0 = (int) floor(min($wSeg[$si][2], $wSeg[$si][4]) / 10); $y1 = (int) floor(max($wSeg[$si][2], $wSeg[$si][4]) / 10);
        for ($x = $x0; $x <= $x1; $x++) for ($y = $y0; $y <= $y1; $y++) $kisiAir["$x:$y"][] = $si;
    }
}
printf("perairan: %d baris, %d segmen, %d ujung bebas, %d komponen\n",
    count($F), count($wSeg), count($bebas), $k0);

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
$bEdges = []; $bAdj = []; $bKunci = []; $kisiTepi = [];
$t0 = microtime(true);
foreach ((array) db_all("SELECT ST_AsGeoJSON(geom) AS geojson FROM bidang WHERE status <> 'dihapus' AND geom IS NOT NULL") as $baris) {
    $g = json_decode((string) $baris['geojson'], true);
    if (!is_array($g) || !isset($g['type'])) continue;
    $bagian = $g['type'] === 'MultiPolygon' ? (array) $g['coordinates'] : ([$g['type'] === 'Polygon' ? $g['coordinates'] : []]);
    foreach ($bagian as $p) {
        $ring = array_map(fn(array $q) => wgs84KeDxf((float) $q[0], (float) $q[1]), (array) ($p[0] ?? []));
        $n = count($ring); if ($n < 4) continue;
        for ($i = 1; $i < $n; $i++) {
            $a = $ring[$i - 1]; $b = $ring[$i];
            $na = $ambilB($a); $nb = $ambilB($b);
            if ($na === $nb) continue;
            $key = min($na, $nb) . ':' . max($na, $nb);
            if (isset($bKunci[$key])) continue;
            $bKunci[$key] = true;
            $e = count($bEdges);
            $bEdges[$e] = [$a[0], $a[1], $b[0], $b[1], $na, $nb, hypot($b[0] - $a[0], $b[1] - $a[1])];
            $bAdj[$na][$nb] = $e;
            $bAdj[$nb][$na] = $e;
            $x0 = (int) floor(min($a[0], $b[0]) / 10); $x1 = (int) floor(max($a[0], $b[0]) / 10);
            $y0 = (int) floor(min($a[1], $b[1]) / 10); $y1 = (int) floor(max($a[1], $b[1]) / 10);
            for ($x = $x0; $x <= $x1; $x++) for ($y = $y0; $y <= $y1; $y++) $kisiTepi["$x:$y"][] = $e;
        }
    }
}
printf("tepi bidang: %d, simpul %d (%.1f dtk)\n", count($bEdges), count($bNode), microtime(true) - $t0);

// tiap tepi bidang menyimpan garis perairan terdekat (<= stop)
$tepiDekat = [];
foreach ($wSeg as $si => $s) {
    $x0 = (int) floor((min($s[1], $s[3]) - $stopJarak) / 10);
    $x1 = (int) floor((max($s[1], $s[3]) + $stopJarak) / 10);
    $y0 = (int) floor((min($s[2], $s[4]) - $stopJarak) / 10);
    $y1 = (int) floor((max($s[2], $s[4]) + $stopJarak) / 10);
    $dilihat = [];
    for ($x = $x0; $x <= $x1; $x++) for ($y = $y0; $y <= $y1; $y++) {
        foreach ($kisiTepi["$x:$y"] ?? [] as $ei) {
            if (isset($dilihat[$ei . ':' . $s[0]])) continue;
            $dilihat[$ei . ':' . $s[0]] = true;
            $e = $bEdges[$ei];
            [$t, $sb, $d, $qx, $qy, $rx, $ry] = dekatRuas([$e[0], $e[1]], [$e[2], $e[3]], [$s[1], $s[2]], [$s[3], $s[4]]);
            if ($d > $stopJarak) continue;
            $lama = $tepiDekat[$ei][$s[0]] ?? null;
            if ($lama === null || $d < $lama[1]) $tepiDekat[$ei][$s[0]] = [$s[0], $d, $qx, $qy, $rx, $ry, $t];
        }
    }
}

// ------------------------------------------------------- tarik lurus
$jembatan = [];
$memotong = function (array $p, array $q, int $fiturTarget) use ($kisiAir, $wSeg, &$jembatan): bool {
    $x0 = (int) floor(min($p[0], $q[0]) / 10); $x1 = (int) floor(max($p[0], $q[0]) / 10);
    $y0 = (int) floor(min($p[1], $q[1]) / 10); $y1 = (int) floor(max($p[1], $q[1]) / 10);
    for ($x = $x0; $x <= $x1; $x++) for ($y = $y0; $y <= $y1; $y++) {
        foreach ($kisiAir["$x:$y"] ?? [] as $si) {
            if ($wSeg[$si][0] === $fiturTarget) continue;
            if (memotongRuas($p, $q, $wSeg[$si])) return true;
        }
    }
    foreach ($jembatan as $jp) if (memotongRuas($p, $q, $jp)) return true;
    return false;
};
$cariLurus = function (array $u, bool $saring) use ($kisiAir, $wSeg, $komp, $D, $memotong): ?array {
    $kx = (int) floor($u['titik'][0] / 10); $ky = (int) floor($u['titik'][1] / 10);
    $kandidat = [];
    for ($r = 1; $r <= (int) ceil($D / 10) + 1; $r++) {
        for ($gx = $kx - $r; $gx <= $kx + $r; $gx++) for ($gy = $ky - $r; $gy <= $ky + $r; $gy++) {
            if (max(abs($gx - $kx), abs($gy - $ky)) !== $r) continue;
            foreach ($kisiAir["$gx:$gy"] ?? [] as $si) {
                $s = $wSeg[$si];
                if ($s[0] === $u['idx']) continue;
                if (($komp[$s[0]] ?? -1) === $komp[$u['idx']]) continue;
                [$d, $qx, $qy] = proyeksi($u['titik'], $s[1], $s[2], $s[3], $s[4]);
                if ($d > $D || $d < 1e-6) continue;
                $kunci = $s[0] . ':' . round($qx, 3) . ':' . round($qy, 3);
                $kandidat[$kunci] = ['d' => $d, 'q' => [$qx, $qy], 'fi' => $s[0]];
            }
        }
        if ($kandidat !== [] && $r > 1 && min(array_column($kandidat, 'd')) < ($r - 1) * 10) break;
    }
    if ($kandidat === []) return null;
    uasort($kandidat, fn($a, $b) => $a['d'] <=> $b['d']);
    if ($saring) foreach ($kandidat as $c) if (!$memotong($u['titik'], $c['q'], $c['fi'])) return $c;
    return reset($kandidat);
};

// -------------------------------------------------------------- rencana
$rencana = []; $nJalur = 0; $nLurus = 0; $nGagal = 0; $nTanpaTempel = 0; $nTerpaksa = 0;
$totalJalur = 0.0; $totalLurus = 0.0; $maksJalur = 0.0;
$t2 = microtime(true);
foreach ($bebas as $u) {
    $lurus = $cariLurus($u, false);
    $dLurus = $lurus['d'] ?? null;

    $attach = null;
    $x = (int) floor($u['titik'][0] / 10); $y = (int) floor($u['titik'][1] / 10);
    for ($gx = $x - 1; $gx <= $x + 1; $gx++) for ($gy = $y - 1; $gy <= $y + 1; $gy++) {
        foreach ($kisiTepi["$gx:$gy"] ?? [] as $ei) {
            $e = $bEdges[$ei];
            [$d, $qx, $qy, $t] = proyeksi($u['titik'], $e[0], $e[1], $e[2], $e[3]);
            if ($d > $attachMaks) continue;
            if ($attach === null || $d < $attach['d']) $attach = ['d' => $d, 'q' => [$qx, $qy], 'ei' => $ei, 't' => $t];
        }
    }
    $jalur = null;
    if ($attach !== null) {
        $e = $bEdges[$attach['ei']];
        $pq = new SplPriorityQueue();
        $pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $jarak = []; $dilihat = []; $datang = [];
        foreach ([[$e[4], $attach['t'] * $e[6]], [$e[5], (1 - $attach['t']) * $e[6]]] as [$n0, $d0]) {
            if ($d0 > $maks) continue;
            if (!isset($jarak[$n0]) || $d0 < $jarak[$n0]) { $jarak[$n0] = $d0; $pq->insert($n0, -$d0); }
        }
        $terbaik = null;
        while (!$pq->isEmpty()) {
            $it = $pq->extract();
            $n = $it['data']; $du = -$it['priority'];
            if (isset($dilihat[$n])) continue;
            $dilihat[$n] = true;
            if ($du > $maks) break;
            if ($terbaik !== null && $du >= $terbaik[0]) break;
            foreach ($bAdj[$n] ?? [] as $v => $ei) {
                $ee = $bEdges[$ei];
                foreach ($tepiDekat[$ei] ?? [] as $en) {
                    [$fi, $d, $qx, $qy, $rx, $ry, $tt] = $en;
                    if ($fi === $u['idx']) continue;
                    $tDariN = ($ee[4] === $n) ? $tt : (1 - $tt);
                    $total = $du + $tDariN * $ee[6] + $d;
                    if ($total > $maks) continue;
                    if ($terbaik === null || $total < $terbaik[0]) {
                        $terbaik = [$total, $fi, $d, $qx, $qy, $rx, $ry, $n, $ei];
                    }
                }
            }
            foreach ($bAdj[$n] ?? [] as $v => $ei) {
                if (isset($dilihat[$v])) continue;
                $nd = $du + $bEdges[$ei][6];
                if ($nd > $maks) continue;
                if (!isset($jarak[$v]) || $nd < $jarak[$v] - 1e-9) { $jarak[$v] = $nd; $datang[$v] = $n; $pq->insert($v, -$nd); }
            }
        }
        if ($terbaik !== null) {
            $rantai = [$terbaik[7]]; $cur = $terbaik[7];
            while (isset($datang[$cur])) { $cur = $datang[$cur]; $rantai[] = $cur; }
            $rantai = array_reverse($rantai);
            $titik = [$u['titik'], $attach['q']];
            foreach ($rantai as $nn) $titik[] = $bNode[$nn];
            $titik[] = [$terbaik[5], $terbaik[6]];
            $bersih = [];
            foreach ($titik as $pt) {
                $akh = $bersih === [] ? null : $bersih[count($bersih) - 1];
                if ($akh === null || hypot($pt[0] - $akh[0], $pt[1] - $akh[1]) > 1e-9) $bersih[] = $pt;
            }
            $jalur = ['titik' => $bersih, 'total' => $terbaik[0] + $attach['d'], 'fi' => $terbaik[1],
                'simpul' => count($rantai)];
        }
    } else $nTanpaTempel++;

    $pakaiJalur = $jalur !== null && ($dLurus === null || $jalur['total'] <= $rasioMaks * $dLurus);
    if ($pakaiJalur) {
        $rencana[] = ['u' => $u, 'jenis' => 'tepi', 'titik' => $jalur['titik'],
            'panjang' => $jalur['total'], 'fi' => $jalur['fi'], 'lurus' => $dLurus, 'simpul' => $jalur['simpul']];
        $nJalur++; $totalJalur += $jalur['total']; $maksJalur = max($maksJalur, $jalur['total']);
        continue;
    }
    $pilih = $cariLurus($u, true);
    if ($pilih === null) {
        if ($jalur !== null) {
            $rencana[] = ['u' => $u, 'jenis' => 'tepi', 'titik' => $jalur['titik'],
                'panjang' => $jalur['total'], 'fi' => $jalur['fi'], 'lurus' => $dLurus, 'simpul' => $jalur['simpul']];
            $nJalur++; $totalJalur += $jalur['total']; $maksJalur = max($maksJalur, $jalur['total']);
        } else { $nGagal++; }
        continue;
    }
    if ($dLurus !== null && $pilih['d'] > $dLurus + 1e-9) $nTerpaksa++;
    $rencana[] = ['u' => $u, 'jenis' => 'lurus', 'titik' => [$u['titik'], $pilih['q']],
        'panjang' => $pilih['d'], 'fi' => $pilih['fi'], 'lurus' => $pilih['d'], 'simpul' => 0];
    $jembatan[] = [-1, $u['titik'][0], $u['titik'][1], $pilih['q'][0], $pilih['q'][1]];
    $nLurus++; $totalLurus += $pilih['d'];
}
printf("telusur %.1f dtk\n", microtime(true) - $t2);
printf("ujung bebas %d · jalur tepi %d (%.1f m, terpanjang %.1f m) · tarik lurus %d (%.1f m) · tanpa tempel %d · gagal %d · lurus dipaksa menjauh %d\n",
    count($bebas), $nJalur, $totalJalur, $maksJalur, $nLurus, $totalLurus, $nTanpaTempel, $nGagal, $nTerpaksa);

// ------------------------------------------------------------ komponen
$induk2 = $induk;
$cari2 = function ($x) use (&$induk2) { while ($induk2[$x] !== $x) { $induk2[$x] = $induk2[$induk2[$x]]; $x = $induk2[$x]; } return $x; };
foreach ($rencana as $r) {
    $ra = $cari2($r['u']['node']); $rb = $cari2($nodeFitur[$r['fi']][0]);
    if ($ra !== $rb) $induk2[$ra] = $rb;
}
$akar = [];
foreach ($nodeFitur as $i => $s) $akar[$cari2($s[0])] = 1;
$k1 = count($akar);
printf("komponen %d -> %d\n", $k0, $k1);

// verifikasi ujung sesudah sambungan
$uji = $F;
foreach ($rencana as $r) $uji[] = ['type' => 'Feature', 'properties' => [],
    'geometry' => ['type' => 'LineString', 'coordinates' => $r['titik']]];
$segUji = []; $kisiUji = [];
foreach ($uji as $i => $f) {
    $t = $f['geometry']['coordinates'];
    for ($q = 1; $q < count($t); $q++) {
        $si = count($segUji);
        $segUji[$si] = [$i, $t[$q - 1][0], $t[$q - 1][1], $t[$q][0], $t[$q][1]];
        $x0 = (int) floor(min($segUji[$si][1], $segUji[$si][3]) / 10); $x1 = (int) floor(max($segUji[$si][1], $segUji[$si][3]) / 10);
        $y0 = (int) floor(min($segUji[$si][2], $segUji[$si][4]) / 10); $y1 = (int) floor(max($segUji[$si][2], $segUji[$si][4]) / 10);
        for ($x = $x0; $x <= $x1; $x++) for ($y = $y0; $y <= $y1; $y++) $kisiUji["$x:$y"][] = $si;
    }
}
$sisaUjung = 0; $sisa = [];
foreach ($uji as $i => $f) {
    $t = $f['geometry']['coordinates'];
    if (count($t) < 2) continue;
    if (hypot($t[0][0] - $t[count($t) - 1][0], $t[0][1] - $t[count($t) - 1][1]) <= SIMPUL_TOL) continue;
    foreach ([$t[0], $t[count($t) - 1]] as $p) {
        $sisaUjung++;
        $x = (int) floor($p[0] / 10); $y = (int) floor($p[1] / 10);
        $d = INF;
        for ($gx = $x - 1; $gx <= $x + 1 && $d > SIMPUL_TOL; $gx++) for ($gy = $y - 1; $gy <= $y + 1 && $d > SIMPUL_TOL; $gy++) {
            foreach ($kisiUji["$gx:$gy"] ?? [] as $si) {
                if ($segUji[$si][0] === $i) continue;
                [$dd] = proyeksi($p, $segUji[$si][1], $segUji[$si][2], $segUji[$si][3], $segUji[$si][4]);
                if ($dd < $d) $d = $dd;
            }
        }
        if ($d > SIMPUL_TOL) $sisa[round($p[0], 2) . ':' . round($p[1], 2)] = true;
    }
}
printf("ujung diperiksa: %d · belum menyentuh garis lain (%.2f m): %d\n", $sisaUjung, SIMPUL_TOL, count($sisa));

if ($keluar !== '') {
    $fit = $F;
    foreach ($rencana as $i => $r) {
        $fit[] = ['type' => 'Feature',
            'geometry' => ['type' => 'LineString', 'coordinates' => $r['titik']],
            'properties' => ['kode' => sprintf('PREVIEW-TEPI-%04d', $i + 1), 'jenis' => $r['jenis'],
                'panjang_m' => round($r['panjang'], 2),
                'lurus_m' => $r['lurus'] === null ? null : round($r['lurus'], 2)]];
    }
    file_put_contents($keluar, json_encode(['type' => 'FeatureCollection', 'features' => $fit], JSON_UNESCAPED_SLASHES));
    printf("pratinjau: %s (%d fitur)\n", $keluar, count($fit));
}

if (!$terapkan) { echo "UJI KERING. Tambahkan --terapkan untuk menulis.\n"; exit(0); }
if ($rencana === []) { echo "TIDAK ADA yang diterapkan\n"; exit(0); }

$folder = __DIR__ . '/../_lama/cadangan-sambung-tepi-' . date('Ymd');
if (!is_dir($folder)) mkdir($folder, 0777, true);
$st = date('Ymd-His');
copy($berkas, "$folder/garis-perairan-sebelum-{$st}.geojson");
copy($berkas, __DIR__ . "/../storage/cadangan/garis-perairan-sebelum-tepi-{$st}.geojson");
copy(META, "$folder/metadata-sebelum-{$st}.json");

$nomor = 0;
foreach ($F as $f) {
    if (preg_match('/^PERAIRAN-SAMBUNG-(\d+)$/', (string) ($f['properties']['kode'] ?? ''), $m)) $nomor = max($nomor, (int) $m[1]);
}
$Fbaru = $F; $catatan = [];
foreach ($rencana as $r) {
    $nomor++;
    $kode = sprintf('PERAIRAN-SAMBUNG-%04d', $nomor);
    $label = $r['u']['label'] !== '' ? $r['u']['label'] : (string) ($F[$r['fi']]['properties']['label'] ?? '');
    $Fbaru[] = [
        'type' => 'Feature',
        'geometry' => ['type' => 'LineString', 'coordinates' => $r['titik']],
        'properties' => [
            'kode' => $kode,
            'sumber' => '020100',
            'indeks' => -1,
            'indeks_all' => [],
            'jumlah_bagian' => 1,
            'panjang_m' => round($r['panjang'], 2),
            'rasio_tepi_bidang' => null,
            'asal' => 'tepi',
            'cara' => $r['jenis'] === 'tepi' ? 'susur-tepi' : 'tarik-lurus',
            'jarak_label_m' => null,
            'label' => $label,
            'dirapikan' => 0,
            'jembatan_celah' => 0,
            'dari' => [$r['u']['kode'], (string) ($F[$r['fi']]['properties']['kode'] ?? '')],
            'simpul_bidang' => $r['simpul'],
            'titik_tidak_asli' => $r['jenis'] === 'tepi' ? 2 : 1,
        ],
    ];
    $catatan[] = [
        'kode' => $kode,
        'dari' => [$r['u']['kode'], (string) ($F[$r['fi']]['properties']['kode'] ?? '')],
        'ujung' => $r['u']['ujung'],
        'jenis' => $r['jenis'],
        'panjang_m' => round($r['panjang'], 2),
        'lurus_m' => $r['lurus'] === null ? null : round($r['lurus'], 2),
        'simpul_bidang' => $r['simpul'],
    ];
}
file_put_contents("$folder/sambung-tepi-{$st}.json", json_encode($catatan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$j['features'] = $Fbaru;
$tmp = tempnam(dirname($berkas), 'perairan-');
file_put_contents($tmp, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
rename($tmp, $berkas);

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
if (!isset($cara['tarik-terdekat'])) unset($l['tarik_terdekat']);
$l['sambung_tepi'] = [
    'alat' => 'tools/sambung-sungai-tepi.php',
    'pada' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('c'),
    'aturan' => 'ujung bebas ditarik ke tepi bidang terdekat (<= ' . $attachMaks . ' m), ditelusuri lewat graf tepi bidang '
        . 'sampai menyentuh garis perairan lain (<= ' . $stopJarak . ' m); jalur tepi hanya dipakai bila <= '
        . $rasioMaks . ' kali jarak lurus, selebihnya ditarik lurus ke garis perairan komponen lain',
    'rasio_maks' => $rasioMaks,
    'ujung_bebas_sebelum' => count($bebas),
    'ujung_bebas_sesudah' => $nGagal,
    'jalur_tepi' => $nJalur,
    'panjang_jalur_m' => round($totalJalur, 2),
    'jalur_terpanjang_m' => round($maksJalur, 2),
    'tarik_lurus' => $nLurus,
    'panjang_lurus_m' => round($totalLurus, 2),
    'lurus_dipaksa_menjauh' => $nTerpaksa,
    'komponen' => ['sebelum' => $k0, 'sesudah' => $k1],
    'pasangan' => $catatan,
];
$meta['layers'][JUDUL] = $l;
$tmpMeta = tempnam(dirname(META), 'meta-');
file_put_contents($tmpMeta, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
rename($tmpMeta, META);
printf("DITERAPKAN: %d baris %.2f m · metadata %d baris\n", count($Fbaru), $total, $l['features']);
