<?php
/**
 * Tarik tiap ujung bebas garis perairan lurus ke titik terdekat pada garis
 * perairan komponen lain (pertemuan T).
 *
 * Dipakai karena gambar sumber 020100 memang terpotong: tiap ujung bebas
 * berimpit persis (0,000 m) dengan ujung polyline sumber, jadi tidak ada
 * lanjutan asli yang bisa dipulihkan. Tiap jembatan hanya 2 titik: ujung
 * bebas yang sudah ada dan proyeksi tegak lurus pada garis target.
 *
 * Sambungan yang memotong garis perairan mana pun (termasuk garis asalnya
 * sendiri dan jembatan yang sudah dipilih) ditolak; dicoba kandidat berikutnya
 * yang lebih jauh. Bila semua kandidat memotong, yang terdekat tetap dipakai.
 *
 * pemakaian: tarik-sungai-terdekat.php [--D=250] [--terapkan]
 */
require_once __DIR__ . '/lib-impor.php';
date_default_timezone_set('Asia/Jakarta');

const BERKAS = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-perairan.geojson';
const META = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers/metadata.json';
const JUDUL = 'GARIS BATAS PERAIRAN 020100';
const SIMPUL_TOL = 0.35;

$terapkan = in_array('--terapkan', $argv, true);
$D = 250.0;
foreach ($argv as $a) if (str_starts_with($a, '--D=')) $D = (float) substr($a, 4);

function proyeksi(array $p, float $ax, float $ay, float $bx, float $by): array {
    $dx = $bx - $ax; $dy = $by - $ay; $l2 = $dx * $dx + $dy * $dy;
    if ($l2 < 1e-12) return [INF, 0.0, 0.0];
    $u = (($p[0] - $ax) * $dx + ($p[1] - $ay) * $dy) / $l2;
    $u = max(0.0, min(1.0, $u));
    $qx = $ax + $u * $dx; $qy = $ay + $u * $dy;
    return [hypot($p[0] - $qx, $p[1] - $qy), $qx, $qy];
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
$j = json_decode((string) file_get_contents(BERKAS), true);
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
    if ($a === $b) continue; // cincin tertutup bukan ujung bebas
    $derajat[$a] = ($derajat[$a] ?? 0) + 1;
    $derajat[$b] = ($derajat[$b] ?? 0) + 1;
    $ujung[] = ['idx' => $i, 'node' => $a, 'titik' => $t[0], 'ujung' => 'a',
        'kode' => (string) ($f['properties']['kode'] ?? ''), 'label' => (string) ($f['properties']['label'] ?? '')];
    $ujung[] = ['idx' => $i, 'node' => $b, 'titik' => $t[count($t) - 1], 'ujung' => 'b',
        'kode' => (string) ($f['properties']['kode'] ?? ''), 'label' => (string) ($f['properties']['label'] ?? '')];
}
$bebas = [];
foreach ($ujung as $u) if (($derajat[$u['node']] ?? 0) === 1) $bebas[] = $u;

// ------------------------------------------------------------- komponen
$induk = [];
$cari = function ($x) use (&$induk) { while ($induk[$x] !== $x) { $induk[$x] = $induk[$induk[$x]]; $x = $induk[$x]; } return $x; };
for ($i = 0; $i < count($wNode); $i++) $induk[$i] = $i;
foreach ($nodeFitur as $s) { $ra = $cari($s[0]); $rb = $cari($s[1]); if ($ra !== $rb) $induk[$ra] = $rb; }
$komp = [];
foreach ($nodeFitur as $i => $s) $komp[$i] = $cari($s[0]);
$k0 = count(array_unique($komp));

// ------------------------------------------------------------- segmen
$seg = []; $kisi = [];
foreach ($F as $i => $f) {
    if (($f['geometry']['type'] ?? '') !== 'LineString') continue;
    $t = $f['geometry']['coordinates'];
    for ($q = 1; $q < count($t); $q++) {
        $si = count($seg);
        $seg[$si] = [$i, $t[$q - 1][0], $t[$q - 1][1], $t[$q][0], $t[$q][1]];
        $x0 = (int) floor(min($seg[$si][1], $seg[$si][3]) / 10); $x1 = (int) floor(max($seg[$si][1], $seg[$si][3]) / 10);
        $y0 = (int) floor(min($seg[$si][2], $seg[$si][4]) / 10); $y1 = (int) floor(max($seg[$si][2], $seg[$si][4]) / 10);
        for ($x = $x0; $x <= $x1; $x++) for ($y = $y0; $y <= $y1; $y++) $kisi["$x:$y"][] = $si;
    }
}
printf("perairan: %d baris, %d segmen, %d ujung bebas, %d komponen\n", count($F), count($seg), count($bebas), $k0);

$jembatan = [];
$memotong = function (array $p, array $q, int $fiturTarget) use ($kisi, $seg, &$jembatan): bool {
    $x0 = (int) floor(min($p[0], $q[0]) / 10); $x1 = (int) floor(max($p[0], $q[0]) / 10);
    $y0 = (int) floor(min($p[1], $q[1]) / 10); $y1 = (int) floor(max($p[1], $q[1]) / 10);
    for ($x = $x0; $x <= $x1; $x++) for ($y = $y0; $y <= $y1; $y++) {
        foreach ($kisi["$x:$y"] ?? [] as $si) {
            if ($seg[$si][0] === $fiturTarget) continue;
            if (memotongRuas($p, $q, $seg[$si])) return true;
        }
    }
    foreach ($jembatan as $jp) {
        if (memotongRuas($p, $q, $jp)) return true;
    }
    return false;
};

// ------------------------------------------------ kandidat tiap ujung
$pilih = []; $gagal = 0; $ditolakPotong = 0; $paksaPotong = 0;
foreach ($bebas as $u) {
    $kx = (int) floor($u['titik'][0] / 10); $ky = (int) floor($u['titik'][1] / 10);
    $kandidat = []; $terpilih = null;
    for ($r = 1; $r <= (int) ceil($D / 10) + 1; $r++) {
        for ($gx = $kx - $r; $gx <= $kx + $r; $gx++) for ($gy = $ky - $r; $gy <= $ky + $r; $gy++) {
            if (max(abs($gx - $kx), abs($gy - $ky)) !== $r) continue;
            foreach ($kisi["$gx:$gy"] ?? [] as $si) {
                $s = $seg[$si];
                if ($s[0] === $u['idx']) continue;
                if (($komp[$s[0]] ?? -1) === $komp[$u['idx']]) continue;
                [$d, $qx, $qy] = proyeksi($u['titik'], $s[1], $s[2], $s[3], $s[4]);
                if ($d > $D || $d < 1e-6) continue;
                $key = $s[0] . ':' . round($qx, 3) . ':' . round($qy, 3);
                $kandidat[$key] = ['d' => $d, 'q' => [$qx, $qy], 'si' => $si, 'fitur' => $s[0]];
            }
        }
        if ($kandidat !== []) {
            uasort($kandidat, fn($a, $b) => $a['d'] <=> $b['d']);
            foreach ($kandidat as $c) {
                if ($c['d'] > $D) break;
                if ($memotong($u['titik'], $c['q'], $c['fitur'])) { $ditolakPotong++; continue; }
                $terpilih = $c; break;
            }
            if ($terpilih !== null && $terpilih['d'] < ($r - 1) * 10) break;
        }
    }
    if ($terpilih === null && $kandidat !== []) {
        uasort($kandidat, fn($a, $b) => $a['d'] <=> $b['d']);
        $terpilih = reset($kandidat);
        $paksaPotong++;
    }
    if ($terpilih === null) { $gagal++; continue; }
    $pilih[] = ['u' => $u, 'd' => $terpilih['d'], 'q' => $terpilih['q'], 'fitur' => $terpilih['fitur']];
    $jembatan[] = [-1, $u['titik'][0], $u['titik'][1], $terpilih['q'][0], $terpilih['q'][1]];
}

$panjang = array_sum(array_column($pilih, 'd'));
printf("sambungan: %d · %.1f m · rata-rata %.1f m · ujung tanpa target: %d · kandidat ditolak karena memotong: %d · terpaksa memotong: %d\n",
    count($pilih), $panjang, count($pilih) ? $panjang / count($pilih) : 0, $gagal, $ditolakPotong, $paksaPotong);
$pita = ['<=3' => 0, '3-10' => 0, '10-30' => 0, '30-60' => 0, '60-100' => 0, '>100' => 0];
foreach ($pilih as $c) {
    $k = $c['d'] <= 3 ? '<=3' : ($c['d'] <= 10 ? '3-10' : ($c['d'] <= 30 ? '10-30' : ($c['d'] <= 60 ? '30-60' : ($c['d'] <= 100 ? '60-100' : '>100'))));
    $pita[$k]++;
}
printf("pita jarak: %s\n", json_encode($pita));
$maks = 0.0;
foreach ($pilih as $c) $maks = max($maks, $c['d']);
printf("jarak terpanjang: %.2f m\n", $maks);

// komponen sesudah
$induk2 = $induk;
$cari2 = function ($x) use (&$induk2) { while ($induk2[$x] !== $x) { $induk2[$x] = $induk2[$induk2[$x]]; $x = $induk2[$x]; } return $x; };
foreach ($pilih as $c) {
    $ra = $cari2($c['u']['node']); $rb = $cari2($nodeFitur[$c['fitur']][0]);
    if ($ra !== $rb) $induk2[$ra] = $rb;
}
$k1 = 0; $akar = [];
foreach ($nodeFitur as $i => $s) $akar[$cari2($s[0])] = 1;
$k1 = count($akar);
printf("komponen %d -> %d\n", $k0, $k1);

// verifikasi: ujung yang belum menyentuh garis lain sesudah jembatan dipasang
$uji = $F;
foreach ($pilih as $c) {
    $uji[] = ['type' => 'Feature', 'properties' => [],
        'geometry' => ['type' => 'LineString', 'coordinates' => [$c['u']['titik'], $c['q']]]];
}
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
$sisa = []; $sisaUjung = 0;
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

if ($terapkan) {
    if ($pilih === []) { echo "TIDAK ADA yang diterapkan\n"; exit(0); }
    $folder = __DIR__ . '/../_lama/cadangan-tarik-sungai-' . date('Ymd');
    if (!is_dir($folder)) mkdir($folder, 0777, true);
    $st = date('Ymd-His');
    copy(BERKAS, "$folder/garis-perairan-sebelum-{$st}.geojson");
    copy(BERKAS, __DIR__ . "/../storage/cadangan/garis-perairan-sebelum-tarik-{$st}.geojson");

    $nomor = 0;
    foreach ($F as $f) {
        if (preg_match('/^PERAIRAN-SAMBUNG-(\d+)$/', (string) ($f['properties']['kode'] ?? ''), $m)) $nomor = max($nomor, (int) $m[1]);
    }
    $Fbaru = $F;
    $catatan = [];
    foreach ($pilih as $c) {
        $nomor++;
        $kode = sprintf('PERAIRAN-SAMBUNG-%04d', $nomor);
        $label = $c['u']['label'] !== '' ? $c['u']['label'] : (string) ($F[$c['fitur']]['properties']['label'] ?? '');
        $Fbaru[] = [
            'type' => 'Feature',
            'geometry' => ['type' => 'LineString', 'coordinates' => [$c['u']['titik'], $c['q']]],
            'properties' => [
                'kode' => $kode,
                'sumber' => '020100',
                'indeks' => -1,
                'indeks_all' => [],
                'jumlah_bagian' => 1,
                'panjang_m' => round($c['d'], 2),
                'rasio_tepi_bidang' => null,
                'asal' => 'tarik',
                'cara' => 'tarik-terdekat',
                'jarak_label_m' => null,
                'label' => $label,
                'dirapikan' => 0,
                'jembatan_celah' => 0,
                'dari' => [$c['u']['kode'], (string) ($F[$c['fitur']]['properties']['kode'] ?? '')],
                'simpul_bidang' => 0,
                'titik_tidak_asli' => 1,
            ],
        ];
        $catatan[] = [
            'kode' => $kode,
            'dari' => [$c['u']['kode'], (string) ($F[$c['fitur']]['properties']['kode'] ?? '')],
            'ujung' => $c['u']['ujung'],
            'panjang_m' => round($c['d'], 2),
        ];
    }
    file_put_contents("$folder/tarik-{$st}.json", json_encode($catatan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $j['features'] = $Fbaru;
    $naskah = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = tempnam(dirname(BERKAS), 'perairan-');
    file_put_contents($tmp, $naskah);
    rename($tmp, BERKAS);

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
    $l['tarik_terdekat'] = [
        'alat' => 'tools/tarik-sungai-terdekat.php',
        'pada' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('c'),
        'aturan' => 'tiap ujung bebas ditarik lurus ke titik terdekat pada garis perairan komponen lain '
            . '(jarak maks ' . $D . ' m); sambungan yang memotong garis perairan mana pun selain garis targetnya '
            . 'ditolak, dan jembatan yang sudah dipilih tidak boleh saling memotong',
        'terpaksa_memotong' => $paksaPotong,
        'ujung_bebas_sebelum' => count($bebas),
        'ujung_bebas_sesudah' => $gagal,
        'sambungan' => count($pilih),
        'panjang_m' => round($panjang, 2),
        'jarak_maks_m' => round($maks, 2),
        'titik_baru_per_sambungan' => 1,
        'komponen' => ['sebelum' => $k0, 'sesudah' => $k1],
        'pasangan' => $catatan,
    ];
    $meta['layers'][JUDUL] = $l;
    $naskahMeta = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $tmpMeta = tempnam(dirname(META), 'meta-');
    file_put_contents($tmpMeta, $naskahMeta);
    rename($tmpMeta, META);
    printf("DITERAPKAN: %d baris %.2f m · metadata %d baris\n", count($Fbaru), $total, $l['features']);
}
