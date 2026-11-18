<?php
declare(strict_types=1);

/**
 * Titik obyek manual dari kartu "Penanda titik obyek" di Peta Induk.
 *
 *   POST {aksi: "simpan", titik: [x, y], nama: "Balai desa", jenis: "Bangunan"}
 *   POST {aksi: "hapus", kode: "OBYEK-M0002"}
 *   POST {aksi: "hapus-terakhir"}
 *
 * Titik dikirim sebagai meter DXF lokal (X = easting, Y = northing) persis
 * seperti koordinat yang ditampilkan peta, lalu disimpan sebagai Point di
 * titik-obyek-manual.geojson. Berkas ini bukan tabel basis data: titik obyek
 * hanya penanda kerja di peta, bukan bidang tanah. Berkasnya disajikan lewat
 * api/peta-lama.php?lapisan=titik-obyek supaya ikut tampil sebagai satu lapis.
 */

require __DIR__ . '/../app/bootstrap.php';

set_time_limit(30);

const BERKAS_TITIK_OBYEK = SIP_ROOT . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/titik-obyek-manual.geojson';
const TITIK_OBYEK_NAMA_MAKS = 80;
const TITIK_OBYEK_JENIS = ['Bangunan', 'Pohon', 'Sumur', 'Patok', 'Saluran', 'Lainnya'];

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
 * Baca seluruh titik dari berkas. Berkas yang belum ada dianggap kosong;
 * berkas rusak ditolak supaya titik lama tidak tertimpa diam-diam.
 * @return array<int, array<string, mixed>>
 */
function bacaTitikObyek(string $berkas): array
{
    if (!is_file($berkas)) {
        return [];
    }
    $isi = json_decode((string) file_get_contents($berkas), true);
    if (!is_array($isi) || ($isi['type'] ?? '') !== 'FeatureCollection' || !is_array($isi['features'] ?? null)) {
        throw new RuntimeException('Berkas titik obyek rusak; perbaiki atau pindahkan dulu berkasnya.');
    }
    return array_values(array_filter($isi['features'], 'is_array'));
}

/** Tulis ulang berkas lewat berkas sementara lalu rename, supaya tidak pernah setengah jadi. */
function tulisTitikObyek(string $berkas, array $fitur): void
{
    $naskah = json_encode(
        ['type' => 'FeatureCollection', 'features' => array_values($fitur)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($naskah === false) {
        throw new RuntimeException('Titik obyek gagal disusun sebagai JSON.');
    }
    $folder = dirname($berkas);
    $sementara = tempnam($folder, 'titik-obyek-');
    if ($sementara === false || file_put_contents($sementara, $naskah) !== strlen($naskah)) {
        if ($sementara !== false && is_file($sementara)) {
            unlink($sementara);
        }
        throw new RuntimeException('Berkas titik obyek tidak dapat ditulis. Periksa izin folder data.');
    }
    if (!rename($sementara, $berkas)) {
        unlink($sementara);
        throw new RuntimeException('Berkas titik obyek tidak dapat diganti.');
    }
}

/** Kode berikutnya dari pola OBYEK-M0001, OBYEK-M0002, ... */
function kodeTitikObyekBaru(array $fitur): string
{
    $tertinggi = 0;
    foreach ($fitur as $f) {
        $kode = (string) ($f['properties']['kode'] ?? '');
        if (preg_match('/^OBYEK-M(\d+)$/', $kode, $cocok)) {
            $tertinggi = max($tertinggi, (int) $cocok[1]);
        }
    }
    return 'OBYEK-M' . str_pad((string) ($tertinggi + 1), 4, '0', STR_PAD_LEFT);
}

function indeksTitikObyek(array $fitur, string $kode): int
{
    foreach ($fitur as $i => $f) {
        if ((string) ($f['properties']['kode'] ?? '') === $kode) {
            return (int) $i;
        }
    }
    return -1;
}

try {
    $kunci = fopen(BERKAS_TITIK_OBYEK . '.lock', 'c');
    if ($kunci === false || !flock($kunci, LOCK_EX)) {
        json_error('Berkas titik obyek sedang dipakai proses lain. Coba lagi sebentar.', 503);
    }

    try {
        $fitur = bacaTitikObyek(BERKAS_TITIK_OBYEK);

        if ($aksi === 'simpan') {
            $pasangan = $masukan['titik'] ?? null;
            if (!is_array($pasangan) || !array_key_exists(0, $pasangan) || !array_key_exists(1, $pasangan)
                || !is_numeric($pasangan[0]) || !is_numeric($pasangan[1])) {
                json_error('Simpan titik butuh koordinat X dan Y.', 422);
            }
            $x = (float) $pasangan[0];
            $y = (float) $pasangan[1];
            if (!is_finite($x) || !is_finite($y) || abs($x) > 1e7 || abs($y) > 1e7) {
                json_error('Titik di luar rentang koordinat DXF.', 422);
            }

            $nama = trim((string) ($masukan['nama'] ?? ''));
            if ($nama === '') {
                json_error('Nama obyek wajib diisi.', 422);
            }
            if (mb_strlen($nama) > TITIK_OBYEK_NAMA_MAKS) {
                json_error('Nama obyek terlalu panjang: maksimal ' . TITIK_OBYEK_NAMA_MAKS . ' karakter.', 422);
            }

            $jenis = trim((string) ($masukan['jenis'] ?? ''));
            if ($jenis === '') {
                $jenis = 'Lainnya';
            }
            if (!in_array($jenis, TITIK_OBYEK_JENIS, true)) {
                json_error('Jenis obyek tidak dikenal. Pilih salah satu: ' . implode(', ', TITIK_OBYEK_JENIS) . '.', 422);
            }

            $kode = kodeTitikObyekBaru($fitur);
            $fitur[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [round($x, 3), round($y, 3)]],
                'properties' => [
                    'kode' => $kode,
                    'sumber' => 'manual',
                    'nama' => $nama,
                    'jenis' => $jenis,
                    'dibuat_oleh' => (string) $pengguna['username'],
                    'dibuat_pada' => date('Y-m-d H:i:s'),
                ],
            ];
            tulisTitikObyek(BERKAS_TITIK_OBYEK, $fitur);

            audit('buat', 'titik-obyek', $kode,
                'Titik obyek ' . $kode . ' (' . $nama . ', ' . $jenis . ') disimpan di X '
                    . angka($x, 3) . ' Y ' . angka($y, 3));

            json_ok([
                'kode' => $kode,
                'nama' => $nama,
                'jenis' => $jenis,
                'x' => round($x, 3),
                'y' => round($y, 3),
                'jumlah' => count($fitur),
            ], 'Titik obyek ' . $kode . ' (' . $nama . ') tersimpan.');
        }

        if ($aksi === 'hapus') {
            $kode = trim((string) ($masukan['kode'] ?? ''));
            if ($kode === '') {
                json_error('Hapus butuh kode titik obyek.', 422);
            }
            $indeks = indeksTitikObyek($fitur, $kode);
            if ($indeks < 0) {
                json_error('Titik obyek ' . $kode . ' tidak ditemukan.', 404);
            }
            $nama = (string) ($fitur[$indeks]['properties']['nama'] ?? '');
            array_splice($fitur, $indeks, 1);
            tulisTitikObyek(BERKAS_TITIK_OBYEK, $fitur);
            audit('hapus', 'titik-obyek', $kode,
                'Titik obyek ' . $kode . ($nama === '' ? '' : ' (' . $nama . ')') . ' dihapus');

            json_ok([
                'kode' => $kode,
                'jumlah' => count($fitur),
            ], 'Titik obyek ' . $kode . ' dihapus.');
        }

        if ($aksi === 'hapus-terakhir') {
            $terakhir = array_pop($fitur);
            if ($terakhir === null) {
                json_error('Belum ada titik obyek yang tersimpan.', 404);
            }
            $kode = (string) ($terakhir['properties']['kode'] ?? '');
            tulisTitikObyek(BERKAS_TITIK_OBYEK, $fitur);
            audit('hapus', 'titik-obyek', $kode,
                'Titik obyek ' . ($kode === '' ? '(tanpa kode)' : $kode) . ' dihapus dari berkas');

            json_ok([
                'kode' => $kode,
                'jumlah' => count($fitur),
            ], 'Titik obyek ' . ($kode === '' ? 'terakhir' : $kode) . ' dihapus.');
        }

        json_error('Aksi tidak dikenal.', 400);
    } finally {
        flock($kunci, LOCK_UN);
        fclose($kunci);
    }
} catch (RuntimeException $error) {
    json_error($error->getMessage(), 500);
}
