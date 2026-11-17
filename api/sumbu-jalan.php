<?php
declare(strict_types=1);

/**
 * Sumbu jalan manual dari alat "Sumbu jalan" di Peta Induk.
 *
 *   POST {aksi: "simpan", titik: [[x, y], ...], label: "..."}
 *   POST {aksi: "hapus-terakhir"}
 *
 * Titik datang sebagai meter DXF lokal (X = easting, Y = northing) persis
 * seperti koordinat yang ditampilkan peta. Baris disimpan di berkas terpisah
 * garis-sumbu-manual.geojson, bukan di garis-jalan-sumbu.geojson, supaya
 * menjalankan ulang tools/garis-jalan-label.php tidak menghapus sumbu yang
 * digambar tangan. Berkas itu disajikan lewat api/peta-lama.php dengan nama
 * lapisan garis-jalan-sumbu-manual.
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
