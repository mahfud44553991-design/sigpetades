<?php
declare(strict_types=1);

/**
 * Sumbu jalan manual dari alat "Sumbu jalan" di Peta Induk.
 *
 *   POST {aksi: "simpan", titik: [[x, y], ...], label: "..."}
 *   POST {aksi: "potong", kode: "SUMBU-M0002", titik: [x, y]}
 *   POST {aksi: "hapus", kode: "SUMBU-M0002"}
 *   POST {aksi: "hapus-terakhir"}
 *
 * Titik datang sebagai meter DXF lokal (X = easting, Y = northing) persis
 * seperti koordinat yang ditampilkan peta. Baris disimpan di berkas terpisah
 * garis-sumbu-manual.geojson, bukan di garis-jalan-sumbu.geojson, supaya
 * menjalankan ulang tools/garis-jalan-label.php tidak menghapus sumbu yang
 * digambar tangan. Berkas itu disajikan lewat api/peta-lama.php dengan nama
 * lapisan garis-jalan-sumbu, digabung dengan sumbu hasil turunan.
 */

require __DIR__ . '/../app/bootstrap.php';

set_time_limit(30);

const BERKAS_SUMBU_MANUAL = SIP_ROOT . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/garis-sumbu-manual.geojson';
const SUMBU_MANUAL_TITIK_MAKS = 2000;
const SUMBU_MANUAL_LABEL_MAKS = 60;

$pengguna = wajib_masuk(PERAN_PENGELOLA);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Metode tidak diizinkan.', 405);
}
wajib_csrf();

$masukan = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($masukan)) {
    $masukan = $_POST;
}
$aksi = (string) ($masukan['aksi'] ?? '');

/**
 * Baca seluruh fitur dari berkas sumbu manual.
 * Berkas yang belum ada dianggap koleksi kosong; berkas rusak ditolak supaya
 * baris lama tidak tertimpa diam-diam.
 * @return array<int, array<string, mixed>>
 */
function bacaSumbuManual(): array
{
    if (!is_file(BERKAS_SUMBU_MANUAL)) {
        return [];
    }
    $isi = json_decode((string) file_get_contents(BERKAS_SUMBU_MANUAL), true);
    if (!is_array($isi) || ($isi['type'] ?? '') !== 'FeatureCollection' || !is_array($isi['features'] ?? null)) {
        throw new RuntimeException('Berkas sumbu manual rusak; perbaiki atau pindahkan dulu berkasnya.');
    }
    return array_values(array_filter($isi['features'], 'is_array'));
}

/** Tulis ulang berkas lewat berkas sementara lalu rename, supaya tidak pernah setengah jadi. */
function tulisSumbuManual(array $fitur): void
{
    $naskah = json_encode(
        ['type' => 'FeatureCollection', 'features' => array_values($fitur)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($naskah === false) {
        throw new RuntimeException('Sumbu manual gagal disusun sebagai JSON.');
    }
    $folder = dirname(BERKAS_SUMBU_MANUAL);
    $sementara = tempnam($folder, 'sumbu-manual-');
    if ($sementara === false || file_put_contents($sementara, $naskah) !== strlen($naskah)) {
        if ($sementara !== false && is_file($sementara)) {
            unlink($sementara);
        }
        throw new RuntimeException('Berkas sumbu manual tidak dapat ditulis. Periksa izin folder data.');
    }
    if (!rename($sementara, BERKAS_SUMBU_MANUAL)) {
        unlink($sementara);
        throw new RuntimeException('Berkas sumbu manual tidak dapat diganti.');
    }
}

/** Kode berikutnya dari pola SUMBU-M0001, SUMBU-M0002, ... */
function kodeSumbuManualBaru(array $fitur): string
{
    $tertinggi = 0;
    foreach ($fitur as $f) {
        $kode = (string) ($f['properties']['kode'] ?? '');
        if (preg_match('/^SUMBU-M(\d+)$/', $kode, $cocok)) {
            $tertinggi = max($tertinggi, (int) $cocok[1]);
        }
    }
    return 'SUMBU-M' . str_pad((string) ($tertinggi + 1), 4, '0', STR_PAD_LEFT);
}

/** @param array<int, array{0: float, 1: float}> $titik */
function panjangGarisManual(array $titik): float
{
    $panjang = 0.0;
    for ($i = 1; $i < count($titik); $i++) {
        $panjang += hypot($titik[$i][0] - $titik[$i - 1][0], $titik[$i][1] - $titik[$i - 1][1]);
    }
    return $panjang;
}

function indeksSumbuManual(array $fitur, string $kode): int
{
    foreach ($fitur as $i => $f) {
        if ((string) ($f['properties']['kode'] ?? '') === $kode) {
            return (int) $i;
        }
    }
    return -1;
}

try {
    $kunci = fopen(BERKAS_SUMBU_MANUAL . '.lock', 'c');
    if ($kunci === false || !flock($kunci, LOCK_EX)) {
        json_error('Berkas sumbu manual sedang dipakai proses lain. Coba lagi sebentar.', 503);
    }

    try {
        $fitur = bacaSumbuManual();

        if ($aksi === 'simpan') {
            $titikMasuk = $masukan['titik'] ?? null;
            if (!is_array($titikMasuk) || count($titikMasuk) < 2) {
                json_error('Sumbu jalan butuh minimal dua titik.', 422);
            }
            if (count($titikMasuk) > SUMBU_MANUAL_TITIK_MAKS) {
                json_error('Terlalu banyak titik: maksimal ' . SUMBU_MANUAL_TITIK_MAKS . ' untuk satu sumbu.', 422);
            }

            $titik = [];
            foreach ($titikMasuk as $i => $pasangan) {
                if (!is_array($pasangan) || count($pasangan) < 2
                    || !is_numeric($pasangan[0]) || !is_numeric($pasangan[1])) {
                    json_error('Titik ke-' . ($i + 1) . ' tidak berisi pasangan angka X dan Y.', 422);
                }
                $x = (float) $pasangan[0];
                $y = (float) $pasangan[1];
                if (!is_finite($x) || !is_finite($y) || abs($x) > 1e7 || abs($y) > 1e7) {
                    json_error('Titik ke-' . ($i + 1) . ' di luar rentang koordinat DXF.', 422);
                }
                $titik[] = [round($x, 3), round($y, 3)];
            }

            $panjang = 0.0;
            for ($i = 1; $i < count($titik); $i++) {
                $panjang += hypot($titik[$i][0] - $titik[$i - 1][0], $titik[$i][1] - $titik[$i - 1][1]);
            }
            if ($panjang < 1.0) {
                json_error('Sumbu terlalu pendek (kurang dari 1 m).', 422);
            }

            $label = trim((string) ($masukan['label'] ?? ''));
            $label = $label === '' ? 'JALAN' : mb_substr($label, 0, SUMBU_MANUAL_LABEL_MAKS);

            $kode = kodeSumbuManualBaru($fitur);
            $fitur[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => $titik],
                'properties' => [
                    'kode' => $kode,
                    'sumber' => 'manual',
                    'cara' => 'manual',
                    'label' => $label,
                    'panjang_m' => round($panjang, 2),
                    'jumlah_titik' => count($titik),
                    'dibuat_oleh' => (string) $pengguna['username'],
                    'dibuat_pada' => date('Y-m-d H:i:s'),
                ],
            ];
            tulisSumbuManual($fitur);

            audit('buat', 'sumbu-jalan', $kode,
                'Sumbu jalan manual ' . $kode . ' disimpan: ' . angka(count($titik)) . ' titik, '
                    . angka($panjang, 2) . ' m, label ' . $label);

            json_ok([
                'kode' => $kode,
                'panjang_m' => round($panjang, 2),
                'jumlah_titik' => count($titik),
                'jumlah' => count($fitur),
            ], 'Sumbu ' . $kode . ' tersimpan (' . angka($panjang, 2) . ' m).');
        }

        if ($aksi === 'potong') {
            $kode = trim((string) ($masukan['kode'] ?? ''));
            $pasangan = $masukan['titik'] ?? null;
            if ($kode === '' || !is_array($pasangan)
                || !array_key_exists(0, $pasangan) || !array_key_exists(1, $pasangan)
                || !is_numeric($pasangan[0]) || !is_numeric($pasangan[1])) {
                json_error('Potong butuh kode sumbu dan titik potong X/Y.', 422);
            }
            $px = (float) $pasangan[0];
            $py = (float) $pasangan[1];
            if (!is_finite($px) || !is_finite($py) || abs($px) > 1e7 || abs($py) > 1e7) {
                json_error('Titik potong di luar rentang koordinat DXF.', 422);
            }

            $indeks = indeksSumbuManual($fitur, $kode);
            if ($indeks < 0) {
                json_error('Sumbu ' . $kode . ' tidak ditemukan.', 404);
            }
            $geometri = $fitur[$indeks]['geometry'] ?? null;
            if (!is_array($geometri) || ($geometri['type'] ?? '') !== 'LineString'
                || !is_array($geometri['coordinates'] ?? null)
                || count($geometri['coordinates']) < 2) {
                json_error('Sumbu ' . $kode . ' bukan garis yang bisa dipotong.', 422);
            }
            $titikAsal = [];
            foreach ($geometri['coordinates'] as $p) {
                if (!is_array($p) || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null)) {
                    json_error('Geometri ' . $kode . ' berisi titik yang tidak sah.', 500);
                }
                $titikAsal[] = [(float) $p[0], (float) $p[1]];
            }

            // Titik potong = proyeksi klik ke segmen garis terdekat.
            $terbaik = null;
            for ($i = 0; $i + 1 < count($titikAsal); $i++) {
                $ax = $titikAsal[$i][0];
                $ay = $titikAsal[$i][1];
                $dx = $titikAsal[$i + 1][0] - $ax;
                $dy = $titikAsal[$i + 1][1] - $ay;
                $kuadrat = $dx * $dx + $dy * $dy;
                if ($kuadrat <= 0.0) {
                    continue;
                }
                $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $kuadrat;
                $t = max(0.0, min(1.0, $t));
                $qx = $ax + $t * $dx;
                $qy = $ay + $t * $dy;
                $jarak = hypot($px - $qx, $py - $qy);
                if ($terbaik === null || $jarak < $terbaik['jarak']) {
                    $terbaik = ['i' => $i, 't' => $t, 'x' => $qx, 'y' => $qy, 'jarak' => $jarak];
                }
            }
            if ($terbaik === null) {
                json_error('Garis ' . $kode . ' tidak punya segmen yang bisa dipotong.', 422);
            }
            $segmenTerakhir = count($titikAsal) - 2;
            if (($terbaik['i'] === 0 && $terbaik['t'] <= 1e-6)
                || ($terbaik['i'] === $segmenTerakhir && $terbaik['t'] >= 1.0 - 1e-6)) {
                json_error('Titik potong jatuh di ujung garis; klik di tengah garis.', 422);
            }

            $i = $terbaik['i'];
            if ($terbaik['t'] <= 1e-6) {
                $potong = $titikAsal[$i];
                $bagianA = array_slice($titikAsal, 0, $i + 1);
                $bagianB = array_slice($titikAsal, $i);
            } elseif ($terbaik['t'] >= 1.0 - 1e-6) {
                $potong = $titikAsal[$i + 1];
                $bagianA = array_slice($titikAsal, 0, $i + 2);
                $bagianB = array_slice($titikAsal, $i + 1);
            } else {
                $potong = [round($terbaik['x'], 3), round($terbaik['y'], 3)];
                $bagianA = array_slice($titikAsal, 0, $i + 1);
                $bagianA[] = $potong;
                $bagianB = [$potong];
                foreach (array_slice($titikAsal, $i + 1) as $p) {
                    $bagianB[] = $p;
                }
            }
            $panjangA = panjangGarisManual($bagianA);
            $panjangB = panjangGarisManual($bagianB);
            if ($panjangA < 1.0 || $panjangB < 1.0) {
                json_error('Potongan terlalu pendek: satu bagian kurang dari 1 m.', 422);
            }

            $properti = is_array($fitur[$indeks]['properties'] ?? null) ? $fitur[$indeks]['properties'] : [];
            $label = trim((string) ($properti['label'] ?? ''));
            $label = $label === '' ? 'JALAN' : $label;
            $buatBagian = static function (string $kodeBagian, array $titikBagian, float $panjangBagian)
                    use ($pengguna, $label, $kode): array {
                return [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'LineString', 'coordinates' => $titikBagian],
                    'properties' => [
                        'kode' => $kodeBagian,
                        'sumber' => 'manual',
                        'cara' => 'manual',
                        'label' => $label,
                        'panjang_m' => round($panjangBagian, 2),
                        'jumlah_titik' => count($titikBagian),
                        'dibuat_oleh' => (string) $pengguna['username'],
                        'dibuat_pada' => date('Y-m-d H:i:s'),
                        'dipotong_dari' => $kode,
                    ],
                ];
            };

            array_splice($fitur, $indeks, 1);
            $kodeA = kodeSumbuManualBaru($fitur);
            $fitur[] = $buatBagian($kodeA, $bagianA, $panjangA);
            $kodeB = kodeSumbuManualBaru($fitur);
            $fitur[] = $buatBagian($kodeB, $bagianB, $panjangB);
            tulisSumbuManual($fitur);

            audit('ubah', 'sumbu-jalan', $kode,
                'Sumbu jalan manual ' . $kode . ' dipotong menjadi ' . $kodeA . ' ('
                    . angka($panjangA, 2) . ' m) dan ' . $kodeB . ' (' . angka($panjangB, 2) . ' m)');

            json_ok([
                'kode' => [$kodeA, $kodeB],
                'panjang_m' => [round($panjangA, 2), round($panjangB, 2)],
                'potong' => $potong,
                'jumlah' => count($fitur),
            ], 'Sumbu ' . $kode . ' dipotong menjadi ' . $kodeA . ' dan ' . $kodeB . '.');
        }

        if ($aksi === 'hapus') {
            $kode = trim((string) ($masukan['kode'] ?? ''));
            if ($kode === '') {
                json_error('Hapus butuh kode sumbu.', 422);
            }
            $indeks = indeksSumbuManual($fitur, $kode);
            if ($indeks < 0) {
                json_error('Sumbu ' . $kode . ' tidak ditemukan.', 404);
            }
            array_splice($fitur, $indeks, 1);
            tulisSumbuManual($fitur);
            audit('hapus', 'sumbu-jalan', $kode, 'Sumbu jalan manual ' . $kode . ' dihapus');

            json_ok([
                'kode' => $kode,
                'jumlah' => count($fitur),
            ], 'Sumbu ' . $kode . ' dihapus.');
        }

        if ($aksi === 'hapus-terakhir') {
            $terakhir = array_pop($fitur);
            if ($terakhir === null) {
                json_error('Belum ada sumbu manual yang tersimpan.', 404);
            }
            $kode = (string) ($terakhir['properties']['kode'] ?? '');
            tulisSumbuManual($fitur);
            audit('hapus', 'sumbu-jalan', $kode,
                'Sumbu jalan manual ' . ($kode === '' ? '(tanpa kode)' : $kode) . ' dihapus dari berkas manual');

            json_ok([
                'kode' => $kode,
                'jumlah' => count($fitur),
            ], 'Sumbu ' . ($kode === '' ? 'terakhir' : $kode) . ' dihapus.');
        }

        json_error('Aksi tidak dikenal.', 400);
    } finally {
        flock($kunci, LOCK_UN);
        fclose($kunci);
    }
} catch (RuntimeException $error) {
    json_error($error->getMessage(), 500);
}
