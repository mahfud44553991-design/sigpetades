<?php
declare(strict_types=1);

/**
 * Rapikan lapis Jalan hasil penurunan 020100 (garis-jalan.geojson).
 *
 *   php -d memory_limit=1024M tools/rapikan-garis-jalan.php            # uji kering
 *   php -d memory_limit=1024M tools/rapikan-garis-jalan.php --terapkan
 *
 * Latar. `tools/garis-batas-020100.php` mengklaim SATU entitas 020100 per baris,
 * jadi lapis Jalan berisi 3.144 potong yang sebenarnya sudah saling bersentuhan:
 * 1.532 simpul tepat dipakai dua baris (ujung satu = pangkal berikutnya), 928
 * simpul hanya memegang satu ujung, dan jaringannya terpecah menjadi 540
 * komponen. Akibatnya satu sisi jalan muncul sebagai lima sampai delapan baris
 * pendek, `kode` mengikuti urutan serpihan berkas sumber, dan tidak ada garis
 * yang bisa dibaca sebagai satu koridor utuh.
 *
 * Yang alat ini kerjakan — semuanya topologi, tidak ada koordinat baru:
 *   1. ujung yang berjarak <= --toleransi (bawaan 0,35 m, sama dengan
 *      TOLERANSI_TEPI si pembangkit) dianggap satu simpul;
 *   2. pasangan baris yang berbagi KEDUA simpul dan rata-rata selisih bentuknya
 *      <= --duplikat-maks (bawaan 0,5 m) adalah tepi yang sama, digambar dua
 *      kali: yang lebih sedikit titik / lebih pendek dibuang. Sepasang tepi
 *      sejajar sebuah jalan (1–15 m) tidak ikut terbuang karena selisih
 *      bentuknya jauh di atas ambang itu;
 *   3. simpul berderajat 2 menyambung dua baris menjadi satu garis selama
 *      belokan di simpul itu <= --belok derajat (bawaan 40), jadi sudut
 *      persimpangan tetap terputus dan dua jalan yang bertemu di sebuah tikungan
 *      tidak disatukan. Cincin (pangkal = ujung) tidak disambung;
 *   4. verifikasi: setiap baris asal harus cocok titik-demi-titik dengan entitas
 *      020100-nya (`indeks`, 16.322 bidang & garis), setiap titik hasil rapikan
 *      harus pernah menjadi titik salah satu baris asal, dan panjang total hanya
 *      boleh berkurang sebesar duplikat yang dibuang.
 *
 * Sisa pekerjaan dilaporkan, tidak dipaksakan: celah antar potongan TIDAK
 * dijembatani — dua ujung yang tidak berbagi simpul tetap dua garis, dan
 * jumlahnya diukur per pita jarak terhadap ujung terdekat (≤2 m, 2–10 m, 10–50 m,
 * >50 m) supaya sisanya terlihat tanpa diubah diam-diam. `garis-selokan`,
 * `garis-perairan`, dan `garis-lain` tidak disentuh, jadi kelas tetap eksklusif
 * seperti hasil penurunan aslinya.
 *
 * Hasil: `kode` dinomori ulang menurut `indeks` sumber terkecil, `jumlah_bagian`
 * dan `indeks_all` menyimpan asal-usul sambungan, `rasio_tepi_bidang` dan
 * `jarak_label_m` dirata-reratakan tertimbang panjang (perhatian: `jarak_label_m`
 * punya dua makna menurut `cara` — jarak label untuk 'benih', lebar koridor untuk
 * 'pita1'/'pita2' — dan rata-ratanya mencampur keduanya), sedangkan `cara` dan
 * `label` diwarisi bagian terpanjang; `asal` jadi 'campur' bila bagiannya
 * berbeda asal. `metadata.json` ikut diperbarui supaya angka pada panel Peta
 * Induk cocok dengan isinya; blok `dirapikan`-nya akumulatif — jumlah kembar,
 * sambungan, panjang kembar dan jembatan ditambah ke nilai lama dan `sebelum_*`
 * tetap menggambarkan bahan baku, selama ambangnya sama. Jalankan sampai uji
 * kering melapor 0 kembar dan 0 sambungan untuk memastikan sudah titik tetap.
 *
 * Uji kering adalah bawaan; tanpa --terapkan tidak ada berkas yang ditulis.
 *
 *   --rinci            tampilkan tiap sambungan dan tiap duplikat yang dibuang
 *   --keluaran=BERKAS  tulis calon hasil ke berkas ini (relatif root) tanpa
 *                      menyentuh lapis asli
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan dari CLI.');
}

require_once __DIR__ . '/lib-impor.php';

const DIR_LAPISAN = __DIR__ . '/../files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const BERKAS_LAPIS = 'garis-jalan';
const JUDUL_META = 'GARIS BATAS JALAN 020100';

function galat(string $pesan): never
{
    fwrite(STDERR, 'GAGAL: ' . $pesan . PHP_EOL);
    exit(1);
}

function angkaNum(float $n, int $desimal = 0): string
{
    return number_format($n, $desimal, ',', '.');
}

function panjang(array $t): float
{
    $jml = 0.0;
    for ($i = 1; $i < count($t); $i++) {
        $jml += hypot($t[$i][0] - $t[$i - 1][0], $t[$i][1] - $t[$i - 1][1]);
    }
    return $jml;
}

/** Kunci persis sebuah titik; sama seperti sumber, jadi pencocokan tanpa pembulatan longgar. */
function kunciTitik(array $p): string
{
    return sprintf('%.6f:%.6f', (float) $p[0], (float) $p[1]);
}

/** Rata-rata jarak titik tengah segmen $a ke garis patah $b (deviasi bentuk satu arah). */
function deviasi(array $a, array $b): float
{
    if (count($a) < 2 || count($b) < 2) {
        return 9e9;
    }
    $tot = 0.0;
    $n = 0;
    for ($i = 0; $i + 1 < count($a); $i++) {
        $x = ($a[$i][0] + $a[$i + 1][0]) / 2;
        $y = ($a[$i][1] + $a[$i + 1][1]) / 2;
        $min = 9e9;
        for ($k = 0; $k + 1 < count($b); $k++) {
            $dx = $b[$k + 1][0] - $b[$k][0];
            $dy = $b[$k + 1][1] - $b[$k][1];
            $pq = $dx * $dx + $dy * $dy;
            $t = $pq > 0 ? max(0.0, min(1.0, (($x - $b[$k][0]) * $dx + ($y - $b[$k][1]) * $dy) / $pq)) : 0.0;
            $d = hypot($x - ($b[$k][0] + $t * $dx), $y - ($b[$k][1] + $t * $dy));
            if ($d < $min) {
                $min = $d;
            }
        }
        $tot += $min;
        $n++;
    }
    return $n > 0 ? $tot / $n : 9e9;
}

/** Banyaknya komponen terhubung pada graf baris-yang-berbagi-simpul. */
function komponen(array $bag, array $deret): int
{
    $adj = [];
    foreach ($bag as $id => $isi) {
        foreach (array_unique([$isi['a'], $isi['b']]) as $nd) {
            foreach (array_keys($deret[$nd] ?? []) as $o2) {
                if ($o2 != $id) {
                    $adj[$id][$o2] = true;
                }
            }
        }
    }
    $seen = [];
    $n = 0;
    foreach (array_keys($bag) as $id) {
        if (isset($seen[$id])) {
            continue;
        }
        $n++;
        $tump = [$id];
        $seen[$id] = true;
        while ($tump) {
            foreach (array_keys($adj[array_pop($tump)] ?? []) as $y) {
                if (!isset($seen[$y])) {
                    $seen[$y] = true;
                    $tump[] = $y;
                }
            }
        }
    }
    return $n;
}

$argv = $_SERVER['argv'] ?? [];
$terapkan = in_array('--terapkan', $argv, true);
$rinci = in_array('--rinci', $argv, true);
$ambil = static function (string $nama, float $bawaan) use ($argv): float {
    foreach ($argv as $a) {
        if (str_starts_with($a, '--' . $nama . '=')) {
            return (float) substr($a, strlen($nama) + 3);
        }
    }
    return $bawaan;
};
$toleransi = $ambil('toleransi', 0.35);
$belok = $ambil('belok', 40.0);
$duplikatMaks = $ambil('duplikat-maks', 0.5);
$keluaran = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--keluaran=')) {
        $keluaran = (string) substr($a, 11);
    }
}
if ($toleransi <= 0 || $belok <= 0 || $belok > 180 || $duplikatMaks <= 0) {
    galat('ambang --toleransi, --duplikat-maks harus positif dan --belok antara 1..180.');
}

$fitur = geojsonBaca(DIR_LAPISAN . '/' . BERKAS_LAPIS . '.geojson');
$sumber = geojsonBaca(DIR_LAPISAN . '/020100.geojson');
printf("sumber 020100 (bidang & garis): %s entitas · lapis Jalan: %s baris\n",
    angkaNum((float) count($sumber)), angkaNum((float) count($fitur)));

// ── 1. baca baris + simpul, dan verifikasi tiap baris ke entitas sumbernya ───
$sel = max(0.05, $toleransi);
$node = [];
$kisiN = [];
$simpul = static function (float $x, float $y) use (&$node, &$kisiN, $sel): int {
    $gx = (int) floor($x / $sel);
    $gy = (int) floor($y / $sel);
    for ($i = $gx - 1; $i <= $gx + 1; $i++) {
        for ($k = $gy - 1; $k <= $gy + 1; $k++) {
            foreach (array_keys($kisiN[$i . ':' . $k] ?? []) as $id) {
                if (hypot($node[$id][0] - $x, $node[$id][1] - $y) <= $sel) {
                    return $id;
                }
            }
        }
    }
    $id = count($node);
    $node[$id] = [$x, $y];
    $kisiN[$gx . ':' . $gy][$id] = true;
    return $id;
};

/** Titik milik satu entitas sumber, diindeks per `indeks` baris. */
$petaSumber = [];
foreach ($sumber as $idx => $f) {
    $c = $f['geometry']['coordinates'] ?? null;
    if (!is_array($c)) {
        continue;
    }
    if (($f['geometry']['type'] ?? '') === 'Polygon') {
        $c = $c[0] ?? [];
    }
    $set = [];
    foreach ($c as $p) {
        if (is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)) {
            $set[kunciTitik($p)] = true;
        }
    }
    $petaSumber[(int) $idx] = $set;
}

$bag = [];
$barisTerverifikasi = 0;
$barisTakTerverifikasi = 0;
$barisTanpaSumber = 0;
$sebelumPanjang = 0.0;
$titikSebelum = 0;
foreach ($fitur as $i => $f) {
    $c = $f['geometry']['coordinates'] ?? [];
    if (!is_array($c) || count($c) < 2) {
        $barisTakTerverifikasi++;
        continue;
    }
    $titik = array_map(static fn(array $p): array => [(float) $p[0], (float) $p[1]], $c);
    $p = $f['properties'] ?? [];
    $idx = (int) ($p['indeks'] ?? -1);
    // Baris hasil rapikan sebelumnya mewakili BEBERAPA entitas sumber; uji
    // keanggotaan titik dipakai terhadap gabungan indeks_all, bukan indeks saja.
    $daftar = array_map('intval', (array) ($p['indeks_all'] ?? [$idx]));
    if ($daftar === []) {
        $daftar = [$idx];
    }
    $set = [];
    foreach ($daftar as $d) {
        foreach (array_keys($petaSumber[$d] ?? []) as $k) {
            $set[$k] = true;
        }
    }
    if ($set === []) {
        $barisTanpaSumber++;
    } else {
        $cocok = 0;
        foreach ($titik as $p0) {
            if (isset($set[kunciTitik($p0)])) {
                $cocok++;
            }
        }
        if ($cocok === count($titik)) {
            $barisTerverifikasi++;
        } else {
            $barisTakTerverifikasi++;
        }
    }
    $tertutup = hypot($titik[0][0] - $titik[count($titik) - 1][0],
        $titik[0][1] - $titik[count($titik) - 1][1]) <= 0.02;
    $a = $simpul($titik[0][0], $titik[0][1]);
    $b = $tertutup ? $a : $simpul($titik[count($titik) - 1][0], $titik[count($titik) - 1][1]);
    $jarak = $p['jarak_label_m'] ?? null;
    $bag[$i] = [
        'ordinal' => $i + 1,
        'kode' => (string) ($p['kode'] ?? ''),
        'indeks' => $idx,
        'titik' => $titik,
        'panjang' => panjang($titik),
        'a' => $a,
        'b' => $b,
        'tertutup' => $tertutup,
        'cara' => (string) ($p['cara'] ?? ''),
        'asal' => (string) ($p['asal'] ?? 'garis'),
        'rasioTepi' => (float) ($p['rasio_tepi_bidang'] ?? 0),
        'jarak' => $jarak === null ? null : (float) $jarak,
        'label' => (string) ($p['label'] ?? 'JALAN'),
        'jumlah' => max(1, (int) ($p['jumlah_bagian'] ?? 1)),
        'indeksAll' => array_values(array_unique(array_map('intval',
            (array) ($p['indeks_all'] ?? [$idx])))),
        'bagian' => [$i + 1],
    ];
    $sebelumPanjang += $bag[$i]['panjang'];
    $titikSebelum += count($titik);
}
printf("simpul (toleransi %.2f m): %s · %s baris · %s titik · panjang %s m\n",
    $sel, angkaNum((float) count($node)), angkaNum((float) count($bag)),
    angkaNum((float) $titikSebelum), angkaNum($sebelumPanjang));
printf("verifikasi baris ke entitas sumbernya: %s cocok penuh · %s sebagian · %s tanpa indeks sumber\n",
    angkaNum((float) $barisTerverifikasi), angkaNum((float) $barisTakTerverifikasi),
    angkaNum((float) $barisTanpaSumber));

$deret = [];
foreach ($bag as $id => $isi) {
    foreach (array_unique([$isi['a'], $isi['b']]) as $nd) {
        $deret[$nd][$id] = true;
    }
}
$derajat = [];
foreach ($deret as $ds) {
    $derajat[count($ds)] = ($derajat[count($ds)] ?? 0) + 1;
}
ksort($derajat);
$deretText = [];
foreach ($derajat as $d => $n) {
    $deretText[] = $d . '=>' . $n;
}
$kompSebelum = komponen($bag, $deret);
echo "derajat simpul sebelum: " . implode('  ', $deretText)
    . "  ·  komponen sebelum: " . angkaNum((float) $kompSebelum) . "\n";

// ── 2. buang duplikat bentuk ────────────────────────────────────────────────
$kunci = [];
foreach ($bag as $id => $isi) {
    if ($isi['tertutup']) {
        continue;
    }
    $kunci[min($isi['a'], $isi['b']) . ':' . max($isi['a'], $isi['b'])][] = $id;
}
$daftarBuang = [];
foreach ($kunci as $lohi => $ids) {
    if (count($ids) < 2) {
        continue;
    }
    for ($i = 0; $i < count($ids); $i++) {
        for ($k = $i + 1; $k < count($ids); $k++) {
            $p = $ids[$i];
            $q = $ids[$k];
            if (!isset($bag[$p], $bag[$q])) {
                continue;
            }
            $dv = min(deviasi($bag[$p]['titik'], $bag[$q]['titik']),
                deviasi($bag[$q]['titik'], $bag[$p]['titik']));
            if ($dv > $duplikatMaks) {
                continue;
            }
            $banding = (count($bag[$p]['titik']) <=> count($bag[$q]['titik'])) * 1000
                + ($bag[$p]['panjang'] <=> $bag[$q]['panjang']);
            $korban = $banding < 0 ? $p : $q;
            $sisa = $korban === $p ? $q : $p;
            foreach (array_unique([$bag[$korban]['a'], $bag[$korban]['b']]) as $nd) {
                unset($deret[$nd][$korban]);
            }
            $daftarBuang[] = ['pertahankan' => $bag[$sisa]['ordinal'],
                'buang' => $bag[$korban]['ordinal'], 'kode' => $bag[$korban]['kode'],
                'deviasi_m' => round($dv, 3), 'panjang_m' => round($bag[$korban]['panjang'], 2)];
            unset($bag[$korban]);
        }
    }
}
$panjangBuang = 0.0;
foreach ($daftarBuang as $d) {
    $panjangBuang += $d['panjang_m'];
}
printf("duplikat bentuk <= %.2f m dibuang: %d baris · %s m\n",
    $duplikatMaks, count($daftarBuang), angkaNum($panjangBuang));
if ($rinci) {
    foreach ($daftarBuang as $d) {
        printf("  %s (#%d) dibuang, kembar dengan #%d (deviasi %.2f m)\n",
            $d['kode'], $d['buang'], $d['pertahankan'], $d['deviasi_m']);
    }
}

// ── 3. sambung rantai pada simpul berderajat 2 ──────────────────────────────
$daftarSambung = [];
$tolak = [];
$sudahNilai = [];
$belokanCatat = [];
$jembatan = 0.0;
$putaran = 0;
while ($putaran < 60) {
    $putaran++;
    $baru = 0;
    foreach ($deret as $nd => $ang) {
        $ids = array_keys($ang);
        if (count($ids) !== 2) {
            continue;
        }
        [$p, $q] = $ids;
        if (!isset($bag[$p], $bag[$q]) || $bag[$p]['tertutup'] || $bag[$q]['tertutup']) {
            continue;
        }
        $tp = $bag[$p]['titik'];
        $tq = $bag[$q]['titik'];
        if ($bag[$p]['b'] !== $nd) {
            $tp = array_reverse($tp);
        }
        if ($bag[$q]['a'] !== $nd) {
            $tq = array_reverse($tq);
        }
        $u = count($tp) - 2;
        $arahP = rad2deg(atan2($tp[$u + 1][1] - $tp[$u][1], $tp[$u + 1][0] - $tp[$u][0]));
        $arahQ = rad2deg(atan2($tq[1][1] - $tq[0][1], $tq[1][0] - $tq[0][0]));
        $belokan = abs(fmod($arahP - $arahQ, 360.0));
        if ($belokan > 180) {
            $belokan = 360 - $belokan;
        }
        $bin = (int) (floor($belokan / 10) * 10);
        if (!isset($sudahNilai[$nd])) {
            $sudahNilai[$nd] = true;
            $belokanCatat[$bin] = ($belokanCatat[$bin] ?? 0) + 1;
        }
        if ($belokan > $belok) {
            $tolak[$nd] = true;
            continue;
        }
        $titik = $tp;
        $patah = 0;
        for ($i = 0; $i < count($tq); $i++) {
            $l = count($titik) - 1;
            $susul = hypot($tq[$i][0] - $titik[$l][0], $tq[$i][1] - $titik[$l][1]);
            if ($i === 0 && $susul <= 0.005) {
                $patah++;
                continue;
            }
            if ($i === 0) {
                $jembatan += $susul;
            }
            $titik[] = $tq[$i];
        }
        $ua = $bag[$p]['a'] === $nd ? $bag[$p]['b'] : $bag[$p]['a'];
        $ub = $bag[$q]['b'] === $nd ? $bag[$q]['a'] : $bag[$q]['b'];
        $tertutup = $ua === $ub || hypot($titik[0][0] - $titik[count($titik) - 1][0],
                $titik[0][1] - $titik[count($titik) - 1][1]) <= 0.02;
        if ($tertutup) {
            $ub = $ua;
        }
        $bobot = max(0.01, $bag[$p]['panjang'] + $bag[$q]['panjang']);
        $besar = $bag[$p]['panjang'] >= $bag[$q]['panjang'] ? $bag[$p] : $bag[$q];
        $jarakB = $bag[$p]['jarak'];
        $jarakC = $bag[$q]['jarak'];
        $isi = [
            'ordinal' => min($bag[$p]['ordinal'], $bag[$q]['ordinal']),
            'kode' => $besar['kode'],
            'indeks' => min($bag[$p]['indeks'], $bag[$q]['indeks']),
            'titik' => $titik,
            'panjang' => panjang($titik),
            'a' => $ua,
            'b' => $ub,
            'tertutup' => $tertutup,
            'cara' => $besar['cara'],
            'asal' => $bag[$p]['asal'] === $bag[$q]['asal'] ? $bag[$p]['asal'] : 'campur',
            'rasioTepi' => ($bag[$p]['rasioTepi'] * $bag[$p]['panjang']
                + $bag[$q]['rasioTepi'] * $bag[$q]['panjang']) / $bobot,
            'jarak' => $jarakB === null ? $jarakC : ($jarakC === null ? $jarakB
                : round(($jarakB * $bag[$p]['panjang'] + $jarakC * $bag[$q]['panjang']) / $bobot, 3)),
            'label' => $besar['label'],
            'jumlah' => $bag[$p]['jumlah'] + $bag[$q]['jumlah'],
            'indeksAll' => array_merge($bag[$p]['indeksAll'], $bag[$q]['indeksAll']),
            'bagian' => array_merge($bag[$p]['bagian'], $bag[$q]['bagian']),
        ];
        foreach (array_unique([$bag[$p]['a'], $bag[$p]['b'], $bag[$q]['a'], $bag[$q]['b']]) as $nd2) {
            unset($deret[$nd2][$p], $deret[$nd2][$q]);
        }
        $daftarSambung[] = ['jadi' => $isi['ordinal'], 'bagian' => $isi['bagian'],
            'belok_derajat' => round($belokan, 1), 'titik_dibuang' => $patah];
        $bag[$q] = $isi;
        unset($bag[$p]);
        $deret[$ua][$q] = true;
        $deret[$ub][$q] = true;
        $baru++;
    }
    if ($baru === 0) {
        break;
    }
}
foreach ($deret as $nd => $ds) {
    if ($ds === []) {
        unset($deret[$nd]);
    }
}
ksort($belokanCatat);
$sudut = [];
foreach ($belokanCatat as $b => $n) {
    $sudut[] = $b . 'deg=>' . $n;
}
printf("penyambungan: %d putaran · %d sambungan dibuat · %d simpul derajat 2 dibiarkan "
    . "karena belok > %.0f° · jembatan simpul %.2f m\n",
    $putaran, count($daftarSambung), count($tolak), $belok, $jembatan);
echo 'belokan di tiap simpul derajat 2 yang sempat dinilai: ' . implode('  ', $sudut) . "\n";
if ($rinci) {
    foreach (array_slice($daftarSambung, 0, 40) as $s) {
        printf("  #%d = %d bagian [%s]\n", $s['jadi'], count($s['bagian']),
            implode(', ', $s['bagian']));
    }
}

// ── 4. statistik + verifikasi akhir ─────────────────────────────────────────
$derajat2 = [];
$dangling = 0;
foreach ($deret as $ds) {
    $n = count($ds);
    $derajat2[$n] = ($derajat2[$n] ?? 0) + 1;
    if ($n === 1) {
        $dangling++;
    }
}
ksort($derajat2);
$t2 = [];
foreach ($derajat2 as $d => $n) {
    $t2[] = $d . '=>' . $n;
}
echo "derajat simpul sesudah: " . implode('  ', $t2) . "\n";
$kompSesudah = komponen($bag, $deret);
printf("komponen terhubung: %s sebelum → %s sesudah\n",
    angkaNum((float) $kompSebelum), angkaNum((float) $kompSesudah));

$setelahJumlah = count($bag);
$setelahPanjang = 0.0;
$setelahTitik = 0;
$cincin = 0;
$titikAsal = [];
foreach ($fitur as $f) {
    foreach ($f['geometry']['coordinates'] ?? [] as $p0) {
        $titikAsal[kunciTitik($p0)] = true;
    }
}
$titikBaru = 0;
$panjangSalin = [];
foreach ($bag as $id => $isi) {
    $setelahPanjang += $isi['panjang'];
    $setelahTitik += count($isi['titik']);
    if ($isi['tertutup']) {
        $cincin++;
    }
    foreach ($isi['titik'] as $p0) {
        if (!isset($titikAsal[kunciTitik($p0)])) {
            $titikBaru++;
        }
    }
}
printf("sesudah: %s baris (%s cincin) · %s titik · %s m · rata-rata %s m/baris · %s sambungan per baris\n",
    angkaNum((float) $setelahJumlah), angkaNum((float) $cincin), angkaNum((float) $setelahTitik),
    angkaNum($setelahPanjang), angkaNum($setelahPanjang / max(1, $setelahJumlah), 1),
    angkaNum((float) array_sum(array_column($bag, 'jumlah')) / max(1, $setelahJumlah), 2));
printf("kekalan panjang: %s m (sebelum) - %s m (duplikat) = %s m · hasil %s m · selisih %.3f m "
    . "(jembatan simpul %.3f m)\n",
    angkaNum($sebelumPanjang, 2), angkaNum($panjangBuang, 2),
    angkaNum($sebelumPanjang - $panjangBuang, 2), angkaNum($setelahPanjang, 2),
    $setelahPanjang - ($sebelumPanjang - $panjangBuang), $jembatan);
printf("titik yang tidak ada di baris asal (harus 0): %d\n", $titikBaru);

// ujung bergantung → pita jarak ke ujung bergantung terdekat
$kisiG = [];
$gantung = [];
$selG = 2.5;
foreach ($deret as $nd => $ds) {
    if (count($ds) !== 1) {
        continue;
    }
    $x = $node[$nd][0];
    $y = $node[$nd][1];
    $gi = count($gantung);
    $gantung[$gi] = [$x, $y];
    $kisiG[(int) floor($x / $selG) . ':' . (int) floor($y / $selG)][$gi] = true;
}
$pita = ['<=2 m' => 0, '2-10 m' => 0, '10-50 m' => 0, '>50 m' => 0];
$radius = (int) ceil(50.0 / $selG);
foreach ($gantung as $gi => $pt) {
    $gx = (int) floor($pt[0] / $selG);
    $gy = (int) floor($pt[1] / $selG);
    $min = 9e9;
    for ($i = $gx - $radius; $i <= $gx + $radius; $i++) {
        for ($k = $gy - $radius; $k <= $gy + $radius; $k++) {
            foreach (array_keys($kisiG[$i . ':' . $k] ?? []) as $o) {
                if ($o === $gi) {
                    continue;
                }
                $d = hypot($gantung[$o][0] - $pt[0], $gantung[$o][1] - $pt[1]);
                if ($d < $min) {
                    $min = $d;
                }
            }
        }
    }
    if ($min > 50) {
        $pita['>50 m']++;
    } elseif ($min <= 2) {
        $pita['<=2 m']++;
    } elseif ($min <= 10) {
        $pita['2-10 m']++;
    } else {
        $pita['10-50 m']++;
    }
}
echo 'ujung bergantung ' . count($gantung) . ' · jarak ke ujung terdekat: '
    . implode(' · ', array_map(static fn(string $k, int $v): string => $k . ' ' . $v,
        array_keys($pita), array_values($pita))) . "\n";

// ── 5. susun GeoJSON hasil ──────────────────────────────────────────────────
$urut = $bag;
uasort($urut, static fn(array $a, array $b): int => ($a['indeks'] <=> $b['indeks'])
    ?: ($a['ordinal'] <=> $b['ordinal']));
$nomor = 0;
$keluar = [];
$caraCount = [];
$asalCount = [];
foreach ($urut as $isi) {
    $nomor++;
    $kode = sprintf('JALAN-020100-%04d', $nomor);
    $caraCount[$isi['cara']] = ($caraCount[$isi['cara']] ?? 0) + 1;
    $asalCount[$isi['asal']] = ($asalCount[$isi['asal']] ?? 0) + 1;
    $indeksAll = $isi['indeksAll'];
    sort($indeksAll);
    $keluar[] = [
        'type' => 'Feature',
        'properties' => [
            'kode' => $kode,
            'sumber' => '020100',
            'indeks' => $isi['indeks'],
            'indeks_all' => array_values(array_unique($indeksAll)),
            'jumlah_bagian' => $isi['jumlah'],
            'panjang_m' => round($isi['panjang'], 2),
            'rasio_tepi_bidang' => round($isi['rasioTepi'], 3),
            'asal' => $isi['asal'],
            'cara' => $isi['cara'],
            'jarak_label_m' => $isi['jarak'] === null ? null : round($isi['jarak'], 2),
            'label' => $isi['label'],
            'dirapikan' => $isi['jumlah'] > 1 ? 1 : 0,
        ],
        'geometry' => ['type' => 'LineString', 'coordinates' => $isi['titik']],
    ];
}

// ── 6. tulis ────────────────────────────────────────────────────────────────
$naskah = json_encode(['type' => 'FeatureCollection', 'features' => $keluar],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($naskah === false) {
    galat('gagal menyusun JSON hasil rapikan.');
}
$jejak = [
    'waktu' => date('c'),
    'berkas' => BERKAS_LAPIS . '.geojson',
    'ambang' => ['toleransi_m' => $toleransi, 'belok_derajat' => $belok,
        'duplikat_maks_m' => $duplikatMaks],
    'sebelum' => ['baris' => count($fitur), 'panjang_m' => round($sebelumPanjang, 2),
        'titik' => $titikSebelum, 'simpul' => count($node), 'derajat' => $derajat,
        'komponen' => $kompSebelum],
    'sesudah' => ['baris' => $setelahJumlah, 'cincin' => $cincin,
        'panjang_m' => round($setelahPanjang, 2), 'titik' => $setelahTitik,
        'derajat' => $derajat2, 'ujung_bergantun' => $dangling, 'pita_jarak' => $pita,
        'komponen' => $kompSesudah, 'sambungan' => count($daftarSambung),
        'simpul_ditolak_belok' => count($tolak), 'belokan_dinilai' => $belokanCatat,
        'jembatan_m' => round($jembatan, 3)],
    'verifikasi' => ['baris_cocok_sumber' => $barisTerverifikasi,
        'baris_sebagian_sumber' => $barisTakTerverifikasi,
        'baris_tanpa_indeks_sumber' => $barisTanpaSumber,
        'titik_tidak_asli' => $titikBaru,
        'selisih_panjang_m' => round($setelahPanjang - ($sebelumPanjang - $panjangBuang), 3)],
    'duplikat_dibuang' => $daftarBuang,
    'sambungan_jumlah' => count($daftarSambung),
    'sambungan' => $daftarSambung,
];

if ($keluaran !== null) {
    $path = $keluaran;
    if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:/', $path)) {
        $path = SIP_ROOT . '/' . $path;
    }
    if (file_put_contents($path, $naskah) === false) {
        galat('gagal menulis calon hasil ke ' . $path);
    }
    echo 'calon hasil ditulis ke ' . $path . "\n";
}

if (!$terapkan) {
    echo "MODE UJI KERING: tidak ada lapis yang diubah. Tambah --terapkan untuk menjalankan,\n"
        . "atau --keluaran=nama.geojson untuk menulis calon hasil tanpa menyentuh lapis.\n";
    exit(0);
}

$berkasLapis = DIR_LAPISAN . '/' . BERKAS_LAPIS . '.geojson';
$st = date('Ymd-His');
$cadangan = SIP_ROOT . '/storage/cadangan/garis-jalan-sebelum-rapikan-' . $st . '.geojson';
if (!is_dir(dirname($cadangan))) {
    galat('folder storage/cadangan tidak ada.');
}
if (!copy($berkasLapis, $cadangan)) {
    galat('gagal mencadangkan ' . $berkasLapis . ' ke ' . $cadangan);
}
if (file_put_contents($berkasLapis, $naskah) === false) {
    galat('gagal menulis ' . $berkasLapis);
}
$jejak['cadangan'] = str_replace(SIP_ROOT . '/', '', $cadangan);
$berkasJejak = SIP_ROOT . '/storage/rapikan-jalan-' . $st . '.json';
if (file_put_contents($berkasJejak, json_encode($jejak, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
    galat('gagal menulis jejak ' . $berkasJejak);
}

$berkasMeta = DIR_LAPISAN . '/metadata.json';
$meta = (array) json_decode((string) file_get_contents($berkasMeta), true);
if ($meta === []) {
    galat('metadata.json tidak dapat dibaca: ' . $berkasMeta);
}
$l = (array) ($meta['layers'][JUDUL_META] ?? []);
// Satu lapis bisa dirapikan beberapa putaran sampai tidak ada lagi yang bisa
// dikerjakan. Blok lama dipakai sebagai dasar supaya jumlah tetap menghitung
// sejak baris pertama, bukan hanya putaran terakhir.
$lama = (array) ($l['dirapikan'] ?? []);
$terus = $lama !== []
    && json_encode($lama['ambang'] ?? null) === json_encode($jejak['ambang']);
$angka = static fn (string $k): float => (float) ($lama[$k] ?? 0);
$l['features'] = $setelahJumlah;
$l['types'] = ['line' => $setelahJumlah];
$l['panjang_m'] = (int) round($setelahPanjang);
$l['asal'] = $asalCount;
$l['cara'] = $caraCount;
$l['dirapikan'] = ['alat' => 'tools/rapikan-garis-jalan.php', 'pada' => date('c'),
    'ambang' => $jejak['ambang'], 'putaran' => $terus ? (int) ($lama['putaran'] ?? 1) + 1 : 1,
    'pertama_pada' => $terus ? ($lama['pertama_pada'] ?? $lama['pada'] ?? null) : date('c'),
    'sebelum_baris' => $terus ? (int) ($lama['sebelum_baris'] ?? count($fitur)) : count($fitur),
    'sebelum_panjang_m' => $terus ? (float) ($lama['sebelum_panjang_m'] ?? round($sebelumPanjang, 2))
        : round($sebelumPanjang, 2),
    'sesudah_baris' => $setelahJumlah, 'sesudah_panjang_m' => round($setelahPanjang, 2),
    'duplikat_dibuang' => (int) ($angka('duplikat_dibuang') + count($daftarBuang)),
    'duplikat_panjang_m' => round($angka('duplikat_panjang_m') + $panjangBuang, 2),
    'sambungan' => (int) ($angka('sambungan') + count($daftarSambung)),
    'jembatan_m' => round($angka('jembatan_m') + $jembatan, 3),
    'cincin' => $cincin, 'ujung_bergantun' => $dangling, 'pita_jarak' => $pita,
    'simpul_ditolak_belok' => count($tolak), 'belokan_dinilai' => $belokanCatat,
    'titik_tidak_asli' => $titikBaru,
    'catatan' => 'duplikat_dibuang, duplikat_panjang_m, sambungan dan jembatan_m dihitung '
        . 'akumulatif sejak sebelum_baris; sebelum_* adalah keadaan lapis sebelum putaran '
        . 'pertama. simpul_ditolak_belok, belokan_dinilai, cincin, ujung_bergantun, pita_jarak '
        . 'dan titik_tidak_asli menggambarkan keadaan sesudah putaran terakhir.'];
$meta['layers'][JUDUL_META] = $l;
if (file_put_contents($berkasMeta, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) {
    galat('gagal menulis metadata.json');
}
printf("DITERAPKAN: %s · %s baris · %s m\njejak: %s\ncadangan: %s\n",
    BERKAS_LAPIS . '.geojson', angkaNum((float) $setelahJumlah), angkaNum($setelahPanjang),
    str_replace(SIP_ROOT . '/', '', $berkasJejak), $jejak['cadangan']);
