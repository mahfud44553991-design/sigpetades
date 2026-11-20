<?php
declare(strict_types=1);

/**
 * Lapis Sumbu jalan yang rapi: garis kembar dibuang, sisanya dijahit menjadi
 * polilin utuh. Dipakai api/peta-lama.php?lapisan=garis-jalan-sumbu.
 *
 * Bahan: garis-jalan-sumbu.geojson (turunan tools/garis-jalan-label.php) dan
 * garis-sumbu-manual.geojson (alat "Garis manual"), koordinat meter DXF lokal.
 *
 * Dua tahap, tanpa menggeser koordinat dan tanpa mengarang garis baru:
 *
 *  1. KEMBAR. Tiap garis disampel tiap 1 m. Garis manual didahulukan, lalu
 *     garis terpanjang. Garis dibuang bila >= 90% sampelnya berada <= 0,6 m
 *     dari garis yang sudah dipakai.
 *  2. JAHIT, aturannya sama dengan tools/jahit-sumbu-blok.php: verteks yang
 *     berjarak <= 0,35 m satu simpul; di simpul derajat 2 dua ruas selalu
 *     disambung; di simpul bercabang pasangan paling lurus (<= 40 derajat)
 *     didahulukan dan tiap ruas hanya dipakai sekali per simpul; ruas kembar
 *     (simpul sama, titik tengah <= 0,5 m) disimpan sekali; cincin tertutup
 *     dipertahankan.
 *  3. CELAH. Ujung polilin derajat 1 yang berhadapan sampai 8 m disambung
 *     bila arah sambungannya menerus di kedua ujung (<= 40 derajat). Ujung
 *     yang berimpit (jarak <= 1 m, arah keluar kedua ujung berlawanan
 *     >= 150 derajat) juga disambung: garis yang digambar melewati titik
 *     pertemuan sedikit, jadi sambungannya berupa tumpang tindih <= 1 m,
 *     bukan celah. Jembatan memakai dua ujung itu sebagai titik, jadi tidak
 *     ada koordinat baru.
 *
 * Hasil dicache di storage/cache/sumbu-rapi.json dengan kunci md5 kedua berkas
 * bahan, jadi dihitung ulang hanya saat berkas berubah.
 */

require_once __DIR__ . '/peta-rapi.php';

const SUMBU_RAPI_VERSI = 'v3';
const SUMBU_RAPI_SIMPUL_M = 0.35;
const SUMBU_RAPI_BELOK_MAKS = 40.0;
const SUMBU_RAPI_CELAH_M = 8.0;
const SUMBU_RAPI_BERIMPIT_M = 1.0;
const SUMBU_RAPI_BERIMPIT_BELOK = 150.0;
const SUMBU_RAPI_KEMBAR_JARAK = 0.5;
const SUMBU_RAPI_SEL_SIMPUL = 1.0;
const SUMBU_RAPI_SAMPEL_M = 1.0;
const SUMBU_RAPI_TUTUP_M = 0.6;
const SUMBU_RAPI_CAKUPAN = 0.9;
const SUMBU_RAPI_SEL_UJI = 20.0;

function sumbu_rapi_jarak(float $ax, float $ay, float $bx, float $by): float
{
    return hypot($bx - $ax, $by - $ay);
}

/** @return array{0:float,1:float} jarak titik ke ruas dan posisi 0..1 di ruas */
function sumbu_rapi_proyeksi(float $px, float $py, float $ax, float $ay, float $bx, float $by): array
{
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $p2 = $dx * $dx + $dy * $dy;
    if ($p2 <= 0.0) {
        return [sumbu_rapi_jarak($px, $py, $ax, $ay), 0.0];
    }
    $t = max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $p2));

    return [hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy)), $t];
}

/** @return array{0:float,1:float} */
function sumbu_rapi_arah(array $dari, array $ke): array
{
    $dx = $ke[0] - $dari[0];
    $dy = $ke[1] - $dari[1];
    $panjang = hypot($dx, $dy);

    return $panjang <= 0.0 ? [0.0, 0.0] : [$dx / $panjang, $dy / $panjang];
}

function sumbu_rapi_belokan(array $masuk, array $keluar): float
{
    $dot = max(-1.0, min(1.0, $masuk[0] * $keluar[0] + $masuk[1] * $keluar[1]));

    return rad2deg(acos($dot));
}

function sumbu_rapi_panjang(array $titik): float
{
    $panjang = 0.0;
    for ($i = 1, $n = count($titik); $i < $n; $i++) {
        $panjang += sumbu_rapi_jarak($titik[$i - 1][0], $titik[$i - 1][1],
            $titik[$i][0], $titik[$i][1]);
    }

    return $panjang;
}

/** Sampel tiap 1 m sepanjang garis, termasuk kedua ujung. */
function sumbu_rapi_sampel(array $titik): array
{
    $keluar = [$titik[0]];
    for ($i = 1, $n = count($titik); $i < $n; $i++) {
        [$ax, $ay] = $titik[$i - 1];
        [$bx, $by] = $titik[$i];
        $d = sumbu_rapi_jarak($ax, $ay, $bx, $by);
        $langkah = max(1, (int) ceil($d / SUMBU_RAPI_SAMPEL_M));
        for ($k = 1; $k <= $langkah; $k++) {
            $t = $k / $langkah;
            $keluar[] = [$ax + ($bx - $ax) * $t, $ay + ($by - $ay) * $t];
        }
    }

    return $keluar;
}

/**
 * Susun garis bahan: manual didahulukan, lalu turunan; masing-masing terpanjang
 * dulu supaya garis pendek yang menumpang garis panjang ikut terbuang.
 *
 * @return array<int,array{titik:array,sumber:string,kode:string,label:string,panjang:float}>
 */
function sumbu_rapi_bahan(array $fitur, string $sumber): array
{
    $keluar = [];
    foreach ($fitur as $f) {
        if (!is_array($f)) {
            continue;
        }
        $g = $f['geometry'] ?? [];
        if (($g['type'] ?? '') !== 'LineString' || !is_array($g['coordinates'] ?? null)) {
            continue;
        }
        $titik = [];
        foreach ($g['coordinates'] as $p) {
            if (!is_array($p) || !isset($p[0], $p[1]) || !is_numeric($p[0]) || !is_numeric($p[1])) {
                continue 2;
            }
            $titik[] = [(float) $p[0], (float) $p[1]];
        }
        if (count($titik) < 2) {
            continue;
        }
        $p = is_array($f['properties'] ?? null) ? $f['properties'] : [];
        $keluar[] = [
            'titik' => $titik,
            'sumber' => $sumber,
            'kode' => (string) ($p['kode'] ?? ''),
            'label' => (string) ($p['label'] ?? ''),
            'panjang' => sumbu_rapi_panjang($titik),
        ];
    }
    usort($keluar, static fn(array $a, array $b): int => $b['panjang'] <=> $a['panjang']);

    return $keluar;
}

/**
 * Kembalikan ['features' => ..., 'ringkasan' => ...]. Fitur keluaran berkode
 * JAHIT-#### dengan properti sumber 'jahit', daftar kode sumbu bahan, label,
 * jumlah ruas, panjang_m, dan tertutup. Urut dari polilin terpanjang.
 */
function sumbu_rapi_rapikan(array $turunan, array $manual): array
{
    $bahanManual = sumbu_rapi_bahan($manual, 'manual');
    $bahanTurunan = sumbu_rapi_bahan($turunan, 'label');
    $bahan = array_merge($bahanManual, $bahanTurunan);

    // ---------------------------------------------------------------- kembar
    $kisiSegmen = [];
    $segmen = [];
    $cakupan = static function (array $sampel) use (&$kisiSegmen, &$segmen): float {
        $dekat = 0;
        foreach ($sampel as [$x, $y]) {
            $gx = (int) floor($x / SUMBU_RAPI_SEL_UJI);
            $gy = (int) floor($y / SUMBU_RAPI_SEL_UJI);
            $ketemu = false;
            for ($cx = $gx - 1; $cx <= $gx + 1 && !$ketemu; $cx++) {
                for ($cy = $gy - 1; $cy <= $gy + 1 && !$ketemu; $cy++) {
                    foreach ($kisiSegmen[$cx . ':' . $cy] ?? [] as $id) {
                        $s = $segmen[$id];
                        [$d] = sumbu_rapi_proyeksi($x, $y, $s[0], $s[1], $s[2], $s[3]);
                        if ($d <= SUMBU_RAPI_TUTUP_M) {
                            $ketemu = true;
                            break;
                        }
                    }
                }
            }
            if ($ketemu) {
                $dekat++;
            }
        }

        return $sampel === [] ? 1.0 : $dekat / count($sampel);
    };
    $daftarkan = static function (array $titik) use (&$kisiSegmen, &$segmen): void {
        for ($i = 1, $n = count($titik); $i < $n; $i++) {
            [$ax, $ay] = $titik[$i - 1];
            [$bx, $by] = $titik[$i];
            if (sumbu_rapi_jarak($ax, $ay, $bx, $by) <= 0.0) {
                continue;
            }
            $id = count($segmen);
            $segmen[$id] = [$ax, $ay, $bx, $by];
            $gx0 = (int) floor(min($ax, $bx) / SUMBU_RAPI_SEL_UJI);
            $gx1 = (int) floor(max($ax, $bx) / SUMBU_RAPI_SEL_UJI);
            $gy0 = (int) floor(min($ay, $by) / SUMBU_RAPI_SEL_UJI);
            $gy1 = (int) floor(max($ay, $by) / SUMBU_RAPI_SEL_UJI);
            for ($gx = $gx0; $gx <= $gx1; $gx++) {
                for ($gy = $gy0; $gy <= $gy1; $gy++) {
                    $kisiSegmen[$gx . ':' . $gy][] = $id;
                }
            }
        }
    };

    $dipakai = [];
    $buang = ['manual' => 0, 'label' => 0];
    $panjangBuang = 0.0;
    foreach ($bahan as $garis) {
        $sampel = sumbu_rapi_sampel($garis['titik']);
        if ($cakupan($sampel) >= SUMBU_RAPI_CAKUPAN) {
            $buang[$garis['sumber']]++;
            $panjangBuang += $garis['panjang'];

            continue;
        }
        $daftarkan($garis['titik']);
        $dipakai[] = $garis;
    }

    // ---------------------------------------------------------------- jahit
    $simpulTitik = [];
    $simpulKisi = [];
    $simpul = static function (float $x, float $y) use (&$simpulTitik, &$simpulKisi): int {
        $gx = (int) floor($x / SUMBU_RAPI_SEL_SIMPUL);
        $gy = (int) floor($y / SUMBU_RAPI_SEL_SIMPUL);
        for ($cx = $gx - 1; $cx <= $gx + 1; $cx++) {
            for ($cy = $gy - 1; $cy <= $gy + 1; $cy++) {
                foreach ($simpulKisi[$cx . ':' . $cy] ?? [] as $id) {
                    [$sx, $sy] = $simpulTitik[$id];
                    if (sumbu_rapi_jarak($x, $y, $sx, $sy) <= SUMBU_RAPI_SIMPUL_M) {
                        return $id;
                    }
                }
            }
        }
        $id = count($simpulTitik);
        $simpulTitik[$id] = [$x, $y];
        $simpulKisi[$gx . ':' . $gy][] = $id;

        return $id;
    };

    $ruas = [];
    foreach ($dipakai as $garis) {
        $titik = $garis['titik'];
        for ($i = 1, $n = count($titik); $i < $n; $i++) {
            [$ax, $ay] = $titik[$i - 1];
            [$bx, $by] = $titik[$i];
            if (sumbu_rapi_jarak($ax, $ay, $bx, $by) <= 0.0) {
                continue;
            }
            $ia = $simpul($ax, $ay);
            $ib = $simpul($bx, $by);
            if ($ia === $ib) {
                continue;
            }
            $ruas[] = ['a' => $ia, 'b' => $ib, 'pa' => [$ax, $ay], 'pb' => [$bx, $by],
                'kode' => $garis['kode'], 'label' => $garis['label']];
        }
    }

    $perPasangan = [];
    $ruasBersih = [];
    $buangKembar = 0;
    foreach ($ruas as $r) {
        $kunci = min($r['a'], $r['b']) . ':' . max($r['a'], $r['b']);
        if (isset($perPasangan[$kunci])) {
            $lain = $ruasBersih[$perPasangan[$kunci]];
            $mx1 = ($r['pa'][0] + $r['pb'][0]) / 2;
            $my1 = ($r['pa'][1] + $r['pb'][1]) / 2;
            $mx2 = ($lain['pa'][0] + $lain['pb'][0]) / 2;
            $my2 = ($lain['pa'][1] + $lain['pb'][1]) / 2;
            if (sumbu_rapi_jarak($mx1, $my1, $mx2, $my2) <= SUMBU_RAPI_KEMBAR_JARAK) {
                $buangKembar++;

                continue;
            }
        }
        $perPasangan[$kunci] = count($ruasBersih);
        $ruasBersih[] = $r;
    }
    $ruas = $ruasBersih;
    unset($perPasangan);
    $panjangRuas = 0.0;
    foreach ($ruas as $r) {
        $panjangRuas += sumbu_rapi_jarak($r['pa'][0], $r['pa'][1], $r['pb'][0], $r['pb'][1]);
    }

    $keluar = [];
    foreach ($ruas as $id => $r) {
        $keluar[$r['a']][$id] = sumbu_rapi_arah($r['pa'], $r['pb']);
        $keluar[$r['b']][$id] = sumbu_rapi_arah($r['pb'], $r['pa']);
    }

    $pasangan = [];
    $jumlahPasangan = 0;
    $simpulBercabang = 0;
    foreach ($keluar as $simpulId => $daftar) {
        if (count($daftar) < 2) {
            continue;
        }
        if (count($daftar) > 2) {
            $simpulBercabang++;
        }
        $ids = array_keys($daftar);
        $kandidat = [];
        $simpulDua = count($daftar) === 2;
        for ($i = 0, $m = count($ids); $i < $m; $i++) {
            for ($j = $i + 1; $j < $m; $j++) {
                $v1 = $daftar[$ids[$i]];
                $v2 = $daftar[$ids[$j]];
                $belok = sumbu_rapi_belokan([-$v1[0], -$v1[1]], $v2);
                if (!$simpulDua && $belok > SUMBU_RAPI_BELOK_MAKS) {
                    continue;
                }
                $kandidat[] = [$belok, $ids[$i], $ids[$j]];
            }
        }
        if ($kandidat === []) {
            continue;
        }
        usort($kandidat, static fn(array $x, array $y): int => $x[0] <=> $y[0]);
        foreach ($kandidat as [, $a, $b]) {
            if (isset($pasangan[$simpulId][$a]) || isset($pasangan[$simpulId][$b])) {
                continue;
            }
            $pasangan[$simpulId][$a] = $b;
            $pasangan[$simpulId][$b] = $a;
            $jumlahPasangan++;
        }
    }

    // ---------------------------------------------------------------- celah
    $celahJembatan = 0;
    $celahPanjang = 0.0;
    if (SUMBU_RAPI_CELAH_M > 0.0) {
        $bebas = [];
        foreach ($ruas as $id => $r) {
            foreach ([[$r['a'], $r['pa'], $r['pb']], [$r['b'], $r['pb'], $r['pa']]] as [$s, $p, $lain]) {
                if (isset($pasangan[$s][$id])) {
                    continue;
                }
                $bebas[] = ['simpul' => $s, 'ruas' => $id, 'p' => $p,
                    'keluar' => sumbu_rapi_arah($p, $lain)];
            }
        }
        $kandidatCelah = [];
        for ($i = 0, $m = count($bebas); $i < $m; $i++) {
            for ($j = $i + 1; $j < $m; $j++) {
                if ($bebas[$i]['ruas'] === $bebas[$j]['ruas']
                    || $bebas[$i]['simpul'] === $bebas[$j]['simpul']) {
                    continue;
                }
                $d = sumbu_rapi_jarak($bebas[$i]['p'][0], $bebas[$i]['p'][1],
                    $bebas[$j]['p'][0], $bebas[$j]['p'][1]);
                if ($d <= SUMBU_RAPI_SIMPUL_M || $d > SUMBU_RAPI_CELAH_M) {
                    continue;
                }
                $u = sumbu_rapi_arah($bebas[$i]['p'], $bebas[$j]['p']);
                $masuk = [-$bebas[$i]['keluar'][0], -$bebas[$i]['keluar'][1]];
                $lurus = sumbu_rapi_belokan($masuk, $u) <= SUMBU_RAPI_BELOK_MAKS
                    && sumbu_rapi_belokan($u, $bebas[$j]['keluar']) <= SUMBU_RAPI_BELOK_MAKS;
                $berimpit = $d <= SUMBU_RAPI_BERIMPIT_M
                    && sumbu_rapi_belokan($bebas[$i]['keluar'], $bebas[$j]['keluar'])
                        >= SUMBU_RAPI_BERIMPIT_BELOK;
                if (!$lurus && !$berimpit) {
                    continue;
                }
                $kandidatCelah[] = [$d, $i, $j];
            }
        }
        usort($kandidatCelah, static fn(array $x, array $y): int => $x[0] <=> $y[0]);
        $dipakaiUjung = [];
        foreach ($kandidatCelah as [$d, $i, $j]) {
            if (isset($dipakaiUjung[$i]) || isset($dipakaiUjung[$j])) {
                continue;
            }
            $dipakaiUjung[$i] = true;
            $dipakaiUjung[$j] = true;
            $idJembatan = count($ruas);
            $ruas[] = ['a' => $bebas[$i]['simpul'], 'b' => $bebas[$j]['simpul'],
                'pa' => $bebas[$i]['p'], 'pb' => $bebas[$j]['p'], 'kode' => 'CELAH', 'label' => ''];
            $pasangan[$bebas[$i]['simpul']][$bebas[$i]['ruas']] = $idJembatan;
            $pasangan[$bebas[$i]['simpul']][$idJembatan] = $bebas[$i]['ruas'];
            $pasangan[$bebas[$j]['simpul']][$bebas[$j]['ruas']] = $idJembatan;
            $pasangan[$bebas[$j]['simpul']][$idJembatan] = $bebas[$j]['ruas'];
            $celahJembatan++;
            $celahPanjang += $d;
            $panjangRuas += $d;
        }
    }

    $terpakai = [];
    $polilin = [];
    foreach ($ruas as $idAwal => $rAwal) {
        if (isset($terpakai[$idAwal])) {
            continue;
        }
        $terpakai[$idAwal] = true;
        $titik = [$rAwal['pa'], $rAwal['pb']];
        $kodeSumbu = [$rAwal['kode'] => true];
        $label = [$rAwal['label'] => true];
        $jumlahRuas = 1;
        $simpulAwal = $rAwal['a'];
        $simpulAkhir = $rAwal['b'];

        foreach ([true, false] as $maju) {
            $simpulKini = $maju ? $simpulAkhir : $simpulAwal;
            $idKini = $idAwal;
            while (($idLanjut = $pasangan[$simpulKini][$idKini] ?? null) !== null) {
                if (isset($terpakai[$idLanjut])) {
                    break;
                }
                $lanjut = $ruas[$idLanjut];
                $dariSimpul = $lanjut['a'] === $simpulKini;
                if (!$dariSimpul && $lanjut['b'] !== $simpulKini) {
                    break;
                }
                $simpulBaru = $dariSimpul ? $lanjut['b'] : $lanjut['a'];
                $ujungBaru = $dariSimpul ? $lanjut['pb'] : $lanjut['pa'];
                $terpakai[$idLanjut] = true;
                $jumlahRuas++;
                $kodeSumbu[$lanjut['kode']] = true;
                $label[$lanjut['label']] = true;
                if ($maju) {
                    $titik[] = $ujungBaru;
                    $simpulAkhir = $simpulBaru;
                } else {
                    array_unshift($titik, $ujungBaru);
                    $simpulAwal = $simpulBaru;
                }
                $simpulKini = $simpulBaru;
                $idKini = $idLanjut;
            }
        }

        $tertutup = $simpulAwal === $simpulAkhir && count($titik) > 2;
        if ($tertutup) {
            $titik[count($titik) - 1] = $titik[0];
        }
        $polilin[] = [
            'titik' => $titik,
            'sumbu' => array_values(array_filter(array_keys($kodeSumbu),
                static fn(string $s): bool => $s !== '' && $s !== 'CELAH')),
            'label' => array_values(array_filter(array_keys($label),
                static fn(string $s): bool => $s !== '')),
            'ruas' => $jumlahRuas,
            'panjang' => sumbu_rapi_panjang($titik),
            'tertutup' => $tertutup,
        ];
    }

    usort($polilin, static fn(array $a, array $b): int => $b['panjang'] <=> $a['panjang']);

    $fitur = [];
    foreach ($polilin as $i => $p) {
        $fitur[] = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => array_map(
                    static fn(array $t): array => [round($t[0], 3), round($t[1], 3)],
                    $p['titik']
                ),
            ],
            'properties' => [
                'kode' => sprintf('JAHIT-%04d', $i + 1),
                'sumber' => 'jahit',
                'sumbu' => $p['sumbu'],
                'label' => $p['label'],
                'jumlah_ruas' => $p['ruas'],
                'jumlah_titik' => count($p['titik']),
                'tertutup' => $p['tertutup'],
                'panjang_m' => round($p['panjang'], 2),
            ],
        ];
    }

    return [
        'features' => $fitur,
        'ringkasan' => [
            'bahan' => count($bahan),
            'manual' => count($bahanManual),
            'turunan' => count($bahanTurunan),
            'dipakai' => count($dipakai),
            'kembar_dibuang' => $buang['manual'] + $buang['label'],
            'kembar_manual' => $buang['manual'],
            'kembar_turunan' => $buang['label'],
            'panjang_kembar_m' => round($panjangBuang, 1),
            'ruas' => count($ruas),
            'ruas_kembar_dibuang' => $buangKembar,
            'panjang_ruas_m' => round($panjangRuas, 1),
            'pasangan' => $jumlahPasangan,
            'simpul_bercabang' => $simpulBercabang,
            'celah_dijembatani' => $celahJembatan,
            'celah_panjang_m' => round($celahPanjang, 1),
            'polilin' => count($fitur),
            'cincin' => count(array_filter($polilin, static fn(array $p): bool => $p['tertutup'])),
            'panjang_m' => round(array_sum(array_column($polilin, 'panjang')), 1),
        ],
    ];
}

/**
 * Baca kedua berkas bahan, kembalikan hasil rapi (dicache). Null bila berkas
 * turunan tidak ada atau tidak dapat dibaca.
 */
function sumbu_rapi_lapis(string $berkasTurunan, string $berkasManual): ?array
{
    if (!is_file($berkasTurunan)) {
        return null;
    }
    $tanda = SUMBU_RAPI_VERSI . '|' . md5_file($berkasTurunan);
    if (is_file($berkasManual)) {
        $tanda .= '|' . md5_file($berkasManual);
    }
    $kunci = md5($tanda);
    $cache = peta_rapi_cache_baca('sumbu-rapi.json', $kunci);
    if ($cache !== null) {
        return $cache;
    }

    $turunan = json_decode((string) file_get_contents($berkasTurunan), true);
    if (!is_array($turunan) || ($turunan['type'] ?? '') !== 'FeatureCollection'
        || !is_array($turunan['features'] ?? null)) {
        return null;
    }
    $manual = [];
    if (is_file($berkasManual)) {
        $baca = json_decode((string) file_get_contents($berkasManual), true);
        if (is_array($baca) && ($baca['type'] ?? '') === 'FeatureCollection'
            && is_array($baca['features'] ?? null)) {
            $manual = $baca['features'];
        }
    }

    $hasil = sumbu_rapi_rapikan($turunan['features'], $manual);
    peta_rapi_cache_tulis('sumbu-rapi.json', $kunci, $hasil);

    return $hasil;
}
