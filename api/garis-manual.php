<?php
declare(strict_types=1);

/**
 * Garis manual dari alat "Garis manual" di Peta Induk.
 *
 *   POST {lapis: "garis-jalan-sumbu", aksi: "simpan", titik: [[x, y], ...], label: "..."}
 *   POST {lapis: "garis-jalan-sumbu", aksi: "potong", kode: "SUMBU-M0002", titik: [x, y]}
 *   POST {lapis: "garis-jalan-sumbu", aksi: "hapus", kode: "SUMBU-M0002"}
 *   POST {lapis: "garis-jalan-sumbu", aksi: "hapus-terakhir"}
 *
 * lapis juga boleh "010500" (Garis luar desa). Titik datang sebagai meter DXF
 * lokal (X = easting, Y = northing) persis seperti koordinat yang ditampilkan
 * peta. Tiap lapis punya berkas manual sendiri:
 *
 *   garis-jalan-sumbu -> garis-sumbu-manual.geojson
 *   010500            -> garis-luar-desa-manual.geojson
 *
 * Berkas manual terpisah dari berkas turunan supaya menjalankan ulang
 * tools/garis-jalan-label.php tidak menghapus garis yang digambar tangan.
 * Keduanya disajikan lewat api/peta-lama.php dengan nama lapisannya masing-masing.
 */

require __DIR__ . '/../app/bootstrap.php';

set_time_limit(30);

const FOLDER_LAPISAN_DXF = SIP_ROOT . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers';
const GARIS_MANUAL_TITIK_MAKS = 2000;
const GARIS_MANUAL_LABEL_MAKS = 60;

const LAPIS_GARIS_MANUAL = [
    'garis-jalan-sumbu' => [
        'berkas' => 'garis-sumbu-manual.geojson',
        'awalan' => 'SUMBU-M',
        'label' => 'JALAN',
        'nama' => 'Sumbu jalan',
    ],
    '010500' => [
        'berkas' => 'garis-luar-desa-manual.geojson',
        'awalan' => 'BATAS-M',
        'label' => 'BATAS DESA',
        'nama' => 'Garis luar desa',
    ],
];

$pengguna = wajib_masuk(PERAN_PENGELOLA);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Metode tidak diizinkan.', 405);
}
wajib_csrf();

$masukan = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($masukan)) {
    $masukan = $_POST;
}
$lapis = trim((string) ($masukan['lapis'] ?? ''));
if (!isset(LAPIS_GARIS_MANUAL[$lapis])) {
    json_error('Lapis garis manual tidak dikenal.', 422);
}
$konfigurasi = LAPIS_GARIS_MANUAL[$lapis];
$namaLapis = $konfigurasi['nama'];
$berkasManual = FOLDER_LAPISAN_DXF . '/' . $konfigurasi['berkas'];
$aksi = (string) ($masukan['aksi'] ?? '');

/**
 * Baca seluruh fitur dari berkas manual satu lapis.
 * Berkas yang belum ada dianggap koleksi kosong; berkas rusak ditolak supaya
 * baris lama tidak tertimpa diam-diam.
 * @return array<int, array<string, mixed>>
 */
function bacaGarisManual(string $berkas): array
{
    if (!is_file($berkas)) {
        return [];
    }
    $isi = json_decode((string) file_get_contents($berkas), true);
    if (!is_array($isi) || ($isi['type'] ?? '') !== 'FeatureCollection' || !is_array($isi['features'] ?? null)) {
        throw new RuntimeException('Berkas garis manual rusak; perbaiki atau pindahkan dulu berkasnya.');
    }
    return array_values(array_filter($isi['features'], 'is_array'));
}

/** Tulis ulang berkas lewat berkas sementara lalu rename, supaya tidak pernah setengah jadi. */
function tulisGarisManual(string $berkas, array $fitur): void
{
    $naskah = json_encode(
        ['type' => 'FeatureCollection', 'features' => array_values($fitur)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($naskah === false) {
        throw new RuntimeException('Garis manual gagal disusun sebagai JSON.');
    }
    $folder = dirname($berkas);
    $sementara = tempnam($folder, 'garis-manual-');
    if ($sementara === false || file_put_contents($sementara, $naskah) !== strlen($naskah)) {
        if ($sementara !== false && is_file($sementara)) {
            unlink($sementara);
        }
        throw new RuntimeException('Berkas garis manual tidak dapat ditulis. Periksa izin folder data.');
    }
    if (!rename($sementara, $berkas)) {
        unlink($sementara);
        throw new RuntimeException('Berkas garis manual tidak dapat diganti.');
    }
}

/** Kode berikutnya dari pola SUMBU-M0001, BATAS-M0001, ... */
function kodeGarisManualBaru(array $fitur, string $awalan): string
{
    $tertinggi = 0;
    foreach ($fitur as $f) {
        $kode = (string) ($f['properties']['kode'] ?? '');
        if (preg_match('/^' . preg_quote($awalan, '/') . '(\d+)$/', $kode, $cocok)) {
            $tertinggi = max($tertinggi, (int) $cocok[1]);
        }
    }
    return $awalan . str_pad((string) ($tertinggi + 1), 4, '0', STR_PAD_LEFT);
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

function indeksGarisManual(array $fitur, string $kode): int
{
    foreach ($fitur as $i => $f) {
        if ((string) ($f['properties']['kode'] ?? '') === $kode) {
            return (int) $i;
        }
    }
    return -1;
}

try {
    $kunci = fopen($berkasManual . '.lock', 'c');
    if ($kunci === false || !flock($kunci, LOCK_EX)) {
        json_error('Berkas garis manual sedang dipakai proses lain. Coba lagi sebentar.', 503);
    }

    try {
        $fitur = bacaGarisManual($berkasManual);

        if ($aksi === 'simpan') {
            $titikMasuk = $masukan['titik'] ?? null;
            if (!is_array($titikMasuk) || count($titikMasuk) < 2) {
                json_error($namaLapis . ' butuh minimal dua titik.', 422);
            }
            if (count($titikMasuk) > GARIS_MANUAL_TITIK_MAKS) {
                json_error('Terlalu banyak titik: maksimal ' . GARIS_MANUAL_TITIK_MAKS . ' untuk satu garis.', 422);
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

            $panjang = panjangGarisManual($titik);
            if ($panjang < 1.0) {
                json_error('Garis terlalu pendek (kurang dari 1 m).', 422);
            }

            $label = trim((string) ($masukan['label'] ?? ''));
            $label = $label === '' ? $konfigurasi['label'] : mb_substr($label, 0, GARIS_MANUAL_LABEL_MAKS);

            $kode = kodeGarisManualBaru($fitur, $konfigurasi['awalan']);
            $fitur[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => $titik],
                'properties' => [
                    'kode' => $kode,
                    'sumber' => 'manual',
                    'cara' => 'manual',
                    'lapis' => $lapis,
                    'label' => $label,
                    'panjang_m' => round($panjang, 2),
                    'jumlah_titik' => count($titik),
                    'dibuat_oleh' => (string) $pengguna['username'],
                    'dibuat_pada' => date('Y-m-d H:i:s'),
                ],
            ];
            tulisGarisManual($berkasManual, $fitur);

            audit('buat', 'garis-manual', $kode,
                $namaLapis . ' manual ' . $kode . ' disimpan: ' . angka(count($titik)) . ' titik, '
                    . angka($panjang, 2) . ' m, label ' . $label);

            json_ok([
                'kode' => $kode,
                'lapis' => $lapis,
                'panjang_m' => round($panjang, 2),
                'jumlah_titik' => count($titik),
                'jumlah' => count($fitur),
            ], $namaLapis . ' ' . $kode . ' tersimpan (' . angka($panjang, 2) . ' m).');
        }

        if ($aksi === 'potong') {
            $kode = trim((string) ($masukan['kode'] ?? ''));
            $pasangan = $masukan['titik'] ?? null;
            if ($kode === '' || !is_array($pasangan)
                || !array_key_exists(0, $pasangan) || !array_key_exists(1, $pasangan)
                || !is_numeric($pasangan[0]) || !is_numeric($pasangan[1])) {
                json_error('Potong butuh kode garis dan titik potong X/Y.', 422);
            }
            $px = (float) $pasangan[0];
            $py = (float) $pasangan[1];
            if (!is_finite($px) || !is_finite($py) || abs($px) > 1e7 || abs($py) > 1e7) {
                json_error('Titik potong di luar rentang koordinat DXF.', 422);
            }

            $indeks = indeksGarisManual($fitur, $kode);
            if ($indeks < 0) {
                json_error($namaLapis . ' ' . $kode . ' tidak ditemukan.', 404);
            }
            $geometri = $fitur[$indeks]['geometry'] ?? null;
            if (!is_array($geometri) || ($geometri['type'] ?? '') !== 'LineString'
                || !is_array($geometri['coordinates'] ?? null)
                || count($geometri['coordinates']) < 2) {
                json_error($namaLapis . ' ' . $kode . ' bukan garis yang bisa dipotong.', 422);
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
            $label = $label === '' ? $konfigurasi['label'] : $label;
            $buatBagian = static function (string $kodeBagian, array $titikBagian, float $panjangBagian)
                    use ($pengguna, $label, $kode, $lapis): array {
                return [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'LineString', 'coordinates' => $titikBagian],
                    'properties' => [
                        'kode' => $kodeBagian,
                        'sumber' => 'manual',
                        'cara' => 'manual',
                        'lapis' => $lapis,
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
            $kodeA = kodeGarisManualBaru($fitur, $konfigurasi['awalan']);
            $fitur[] = $buatBagian($kodeA, $bagianA, $panjangA);
            $kodeB = kodeGarisManualBaru($fitur, $konfigurasi['awalan']);
            $fitur[] = $buatBagian($kodeB, $bagianB, $panjangB);
            tulisGarisManual($berkasManual, $fitur);

            audit('ubah', 'garis-manual', $kode,
                $namaLapis . ' manual ' . $kode . ' dipotong menjadi ' . $kodeA . ' ('
                    . angka($panjangA, 2) . ' m) dan ' . $kodeB . ' (' . angka($panjangB, 2) . ' m)');

            json_ok([
                'kode' => [$kodeA, $kodeB],
                'lapis' => $lapis,
                'panjang_m' => [round($panjangA, 2), round($panjangB, 2)],
                'potong' => $potong,
                'jumlah' => count($fitur),
            ], $namaLapis . ' ' . $kode . ' dipotong menjadi ' . $kodeA . ' dan ' . $kodeB . '.');
        }

        if ($aksi === 'hapus') {
            $kode = trim((string) ($masukan['kode'] ?? ''));
            if ($kode === '') {
                json_error('Hapus butuh kode garis.', 422);
            }
            $indeks = indeksGarisManual($fitur, $kode);
            if ($indeks < 0) {
                json_error($namaLapis . ' ' . $kode . ' tidak ditemukan.', 404);
            }
            array_splice($fitur, $indeks, 1);
            tulisGarisManual($berkasManual, $fitur);
            audit('hapus', 'garis-manual', $kode, $namaLapis . ' manual ' . $kode . ' dihapus');

            json_ok([
                'kode' => $kode,
                'lapis' => $lapis,
                'jumlah' => count($fitur),
            ], $namaLapis . ' ' . $kode . ' dihapus.');
        }

        if ($aksi === 'hapus-terakhir') {
            $terakhir = array_pop($fitur);
            if ($terakhir === null) {
                json_error('Belum ada garis manual yang tersimpan pada lapis ini.', 404);
            }
            $kode = (string) ($terakhir['properties']['kode'] ?? '');
            tulisGarisManual($berkasManual, $fitur);
            audit('hapus', 'garis-manual', $kode,
                $namaLapis . ' manual ' . ($kode === '' ? '(tanpa kode)' : $kode) . ' dihapus dari berkas manual');

            json_ok([
                'kode' => $kode,
                'lapis' => $lapis,
                'jumlah' => count($fitur),
            ], $namaLapis . ' ' . ($kode === '' ? 'terakhir' : $kode) . ' dihapus.');
        }

        json_error('Aksi tidak dikenal.', 400);
    } finally {
        flock($kunci, LOCK_UN);
        fclose($kunci);
    }
} catch (RuntimeException $error) {
    json_error($error->getMessage(), 500);
}
