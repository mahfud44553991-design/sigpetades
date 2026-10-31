<?php
declare(strict_types=1);

/**
 * Kumpulan fungsi impor data desa. Dipakai skrip CLI di tools/ dan halaman
 * pasang.php, jadi logikanya cukup ditulis sekali.
 *
 * Setiap fungsi mengembalikan array ringkasan dan tidak memanggil exit.
 */

require_once __DIR__ . '/../app/bootstrap.php';

// ---------------------------------------------------------------------------
// Pembantu GeoJSON
// ---------------------------------------------------------------------------

/** @return array<int, array> daftar feature dari satu FeatureCollection */
function geojsonBaca(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Berkas tidak ditemukan: ' . $path);
    }
    $isi = file_get_contents($path);
    if ($isi === false) {
        throw new RuntimeException('Berkas gagal dibaca: ' . $path);
    }
    $data = json_decode($isi, true, 512, JSON_THROW_ON_ERROR);
    if (($data['type'] ?? '') !== 'FeatureCollection' || !isset($data['features'])) {
        throw new RuntimeException('Bukan FeatureCollection yang sah: ' . $path);
    }
    return $data['features'];
}

/**
 * Ubah geometri GeoJSON menjadi WKT. Koordinat GeoJSON [bujur, lintang]
 * sudah satu urutan dengan yang dibutuhkan WKT.
 */
function geojsonKeWkt(array $geometry): ?string
{
    $jenis = $geometry['type'] ?? '';
    $koordinat = $geometry['coordinates'] ?? null;
    if (!is_array($koordinat)) {
        return null;
    }

    if ($jenis === 'Polygon') {
        $isi = wktPoligon($koordinat);
        return $isi === null ? null : 'POLYGON(' . $isi . ')';
    }
    if ($jenis === 'MultiPolygon') {
        $bagian = [];
        foreach ($koordinat as $satuPoligon) {
            $isi = is_array($satuPoligon) ? wktPoligon($satuPoligon) : null;
            if ($isi !== null) {
                $bagian[] = '(' . $isi . ')';
            }
        }
        return $bagian === [] ? null : 'MULTIPOLYGON(' . implode(',', $bagian) . ')';
    }
    if ($jenis === 'Point' && count($koordinat) >= 2) {
        return 'POINT(' . wktPasangan($koordinat[0], $koordinat[1]) . ')';
    }
    return null;
}

/**
 * Cincin poligon GeoJSON (daftar titik luar, lalu lubang) menjadi
 * "(cincin),(lubang)" tanpa pembungkus, sehingga dipakai bersama oleh
 * POLYGON dan tiap bagian MULTIPOLYGON.
 */
function wktPoligon(array $cincin): ?string
{
    $bagian = [];
    foreach ($cincin as $satuCincin) {
        if (!is_array($satuCincin)) {
            continue;
        }
        $teks = wktRing($satuCincin);
        if ($teks !== null) {
            $bagian[] = '(' . $teks . ')';
        }
    }
    return $bagian === [] ? null : implode(',', $bagian);
}

/** Satu cincin menjadi daftar koordinat tertutup; null jika terlalu tipis. */
function wktRing(array $ring): ?string
{
    $titik = [];
    foreach ($ring as $pasangan) {
        if (!is_array($pasangan) || count($pasangan) < 2) {
            continue;
        }
        $teks = wktPasangan($pasangan[0], $pasangan[1]);
        // Titik berulang berurutan membuat MariaDB menolak cincin.
        if ($titik !== [] && end($titik) === $teks) {
            continue;
        }
        $titik[] = $teks;
    }

    if (count($titik) < 3) {
        return null;
    }
    if ($titik[0] !== end($titik)) {
        $titik[] = $titik[0];
    }
    return count($titik) < 4 ? null : implode(',', $titik);
}

function wktPasangan(mixed $bujur, mixed $lintang): string
{
    if (!is_numeric($bujur) || !is_numeric($lintang)) {
        throw new RuntimeException('Koordinat GeoJSON bukan angka.');
    }
    return sprintf('%.9f %.9f', (float) $bujur, (float) $lintang);
}

/** Ambil titik berat (centroid) poligon dari MariaDB, sebagai [lon, lat]. */
function wktPusat(PDO $pdo, string $wkt): array
{
    static $statement = null;
    if ($statement === null) {
        $statement = $pdo->prepare('SELECT ST_AsText(ST_Centroid(ST_GeomFromText(?, 4326)))');
    }
    $statement->execute([$wkt]);
    $teks = (string) $statement->fetchColumn();

    if (preg_match('/POINT\s*\(\s*(-?[0-9.]+)\s+(-?[0-9.]+)/i', $teks, $cocok)) {
        return ['lon' => (float) $cocok[1], 'lat' => (float) $cocok[2]];
    }
    return ['lon' => null, 'lat' => null];
}

/** Poligon tunggal diubah ke MultiPolygon supaya kolom geometri cocok. */
function keMultipolygon(array $geometry): array
{
    if (($geometry['type'] ?? '') === 'Polygon') {
        return ['type' => 'MultiPolygon', 'coordinates' => [$geometry['coordinates']]];
    }
    return $geometry;
}

/** Angka dari teks yang mungkin memakai pemisah ribuan gaya Indonesia. */
function angkaTeks(string $teks, float $fallback = 0.0): float
{
    $bersih = preg_replace('/[^\d.\-]/', '', str_replace(',', '.', $teks)) ?? '';
    return $bersih === '' || $bersih === '-' ? $fallback : (float) $bersih;
}

// ---------------------------------------------------------------------------
// 1. Blok peta
// ---------------------------------------------------------------------------

function imporBlok(string $path, bool $reset = true): array
{
    $pdo = db();
    $features = geojsonBaca($path);

    if ($reset) {
        $pdo->exec('DELETE FROM blok');
    }

    $sudah = [];
    foreach ($pdo->query('SELECT kode FROM blok')->fetchAll(PDO::FETCH_COLUMN) as $kode) {
        $sudah[(string) $kode] = true;
    }

    $sisip = $pdo->prepare(
        'INSERT INTO blok (kode, nomor, nama, geom, batas_tersedia, batas_perkiraan,
                           sumber_perkiraan, jumlah_bidang_estimasi, maks_jarak_estimasi_m)
         VALUES (?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, ?, ?, ?)'
    );

    $dibuat = 0; $dilewati = 0; $galat = [];

    foreach ($features as $index => $feature) {
        $props = $feature['properties'] ?? [];
        $nama = trim((string) ($props['blok'] ?? ''));
        if ($nama === '' || isset($sudah[$nama])) {
            $dilewati++;
            continue;
        }

        $wkt = geojsonKeWkt(keMultipolygon($feature['geometry'] ?? []));
        if ($wkt === null) {
            $galat[] = 'Blok "' . $nama . '" (feature #' . $index . '): geometri tidak dikenal';
            continue;
        }

        try {
            $sisip->execute([
                $nama,
                isset($props['nomor_blok']) ? (int) $props['nomor_blok'] : null,
                $nama,
                $wkt,
                !empty($props['boundary_available']) ? 1 : 0,
                !empty($props['estimated_boundary']) ? 1 : 0,
                $props['estimated_source'] ?? null,
                isset($props['estimated_parcel_count']) ? (int) $props['estimated_parcel_count'] : null,
                isset($props['estimated_max_distance_meters']) ? (int) $props['estimated_max_distance_meters'] : null,
            ]);
            $sudah[$nama] = true;
            $dibuat++;
        } catch (PDOException $error) {
            $galat[] = 'Blok "' . $nama . '": ' . $error->getMessage();
        }
    }

    return ['jenis' => 'blok', 'dibuat' => $dibuat, 'dilewati' => $dilewati, 'galat' => $galat];
}

// ---------------------------------------------------------------------------
// 2. Bidang tanah dan bidang lain
// ---------------------------------------------------------------------------

/** Jenis hak awal untuk bidang yang belum dikonfirmasi lapangan. */
const JENIS_HAK_DEFAULT = 'BLM';

function imporBidang(string $path, string $tipe, bool $reset = true, int $batasLapor = 20): array
{
    $pdo = db();
    $features = geojsonBaca($path);

    if ($reset) {
        // hak ikut terhapus lewat ON DELETE CASCADE pada fk_hak_bidang.
        $pdo->exec('DELETE FROM bidang');
    }

    $idBlok = [];
    foreach ($pdo->query('SELECT kode, id FROM blok')->fetchAll() as $baris) {
        $idBlok[(string) $baris['kode']] = (int) $baris['id'];
    }

    $sudah = [];
    foreach ($pdo->query('SELECT kode FROM bidang')->fetchAll(PDO::FETCH_COLUMN) as $kode) {
        $sudah[(string) $kode] = true;
    }

    $idJenisHak = [];
    foreach ($pdo->query('SELECT kode, id FROM jenis_hak')->fetchAll() as $baris) {
        $idJenisHak[(string) $baris['kode']] = (int) $baris['id'];
    }
    $idTidakTercatat = $idJenisHak[JENIS_HAK_DEFAULT] ?? null;
    if ($idTidakTercatat === null) {
        throw new RuntimeException('Master jenis_hak kode "' . JENIS_HAK_DEFAULT . '" tidak ada. Jalankan sql/01_skema.sql.');
    }

    // Status_hak pada GeoJSON memakai nama lengkap, bukan kode.
    $kodeDariNama = [];
    foreach (db_all('SELECT kode, nama FROM jenis_hak') as $baris) {
        $kodeDariNama[trim((string) $baris['nama'])] = (string) $baris['kode'];
    }

    $sisip = $pdo->prepare(
        'INSERT INTO bidang (kode, tipe, nib, blok_id, luas_m2, status, sumber, catatan, geom, pusat_lon, pusat_lat)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?)'
    );
    $sisipHak = $pdo->prepare(
        'INSERT INTO hak (bidang_id, jenis_hak_id, pemegang_nama, catatan) VALUES (?, ?, ?, ?)'
    );

    $dibuat = 0; $dilewati = 0; $perluCek = 0; $galat = [];

    foreach ($features as $feature) {
        $props = $feature['properties'] ?? [];
        $kode = trim((string) ($props['id_bidang'] ?? ''));
        if ($kode === '' || isset($sudah[$kode])) {
            $dilewati++;
            continue;
        }

        $wkt = geojsonKeWkt($feature['geometry'] ?? []);
        if ($wkt === null) {
            $galat[] = 'Bidang ' . $kode . ': geometri tidak dikenal';
            continue;
        }

        $namaBlok = trim((string) ($props['blok'] ?? ''));
        $blokId = $idBlok[$namaBlok] ?? null;

        $nib = trim((string) ($props['label_nib'] ?? ''));
        $luas = isset($props['luas_m2']) && is_numeric($props['luas_m2']) ? (float) $props['luas_m2'] : null;
        $sumber = mb_substr(trim((string) ($props['sumber_layer'] ?? '')) ?: 'master', 0, 40);
        $statusAwal = trim((string) ($props['status_hak'] ?? ''));
        $perkiraan = !empty($props['blok_perkiraan']);

        $idHak = $idTidakTercatat;
        $perluVerifikasi = true;
        if ($statusAwal !== '' && isset($kodeDariNama[$statusAwal], $idJenisHak[$kodeDariNama[$statusAwal]])) {
            $idHak = $idJenisHak[$kodeDariNama[$statusAwal]];
            $perluVerifikasi = $statusAwal === 'Jenis hak tidak tercatat'
                || $statusAwal === 'Belum difinalisasi'
                || $perkiraan;
        }

        $pusat = wktPusat($pdo, $wkt);
        if ($perluVerifikasi) {
            $perluCek++;
        }

        $pemegang = trim((string) ($props['pemegang_hak'] ?? '')) ?: null;

        try {
            db_transaksi(static function (PDO $connect) use (
                $sisip, $sisipHak, $kode, $tipe, $nib, $blokId, $luas, $sumber,
                $perluVerifikasi, $wkt, $pusat, $idHak, $statusAwal, $perkiraan, $pemegang
            ): void {
                $sisip->execute([
                    $kode,
                    $tipe,
                    $nib === '' ? null : $nib,
                    $blokId,
                    $luas,
                    $perluVerifikasi ? 'perlu_verifikasi' : 'aktif',
                    $sumber,
                    $perkiraan ? 'Blok diperkirakan otomatis, belum dicek lapangan.' : null,
                    $wkt,
                    $pusat['lon'],
                    $pusat['lat'],
                ]);
                $sisipHak->execute([
                    (int) $connect->lastInsertId(),
                    $idHak,
                    $pemegang,
                    $statusAwal === '' ? null : 'Dibawa dari hasil digitasi: ' . $statusAwal,
                ]);
            });
            $sudah[$kode] = true;
            $dibuat++;
        } catch (Throwable $error) {
            if (count($galat) < $batasLapor) {
                $galat[] = 'Bidang ' . $kode . ': ' . $error->getMessage();
            }
        }
    }

    return [
        'jenis' => 'bidang-' . $tipe,
        'dibuat' => $dibuat,
        'dilewati' => $dilewati,
        'perlu_verifikasi' => $perluCek,
        'galat' => $galat,
    ];
}

// ---------------------------------------------------------------------------
// 3. SPPT / PBB dari CSV
// ---------------------------------------------------------------------------

/**
 * Kolom yang dikenali: nop, nama_wp, alamat_wp, alamat_op, dusun, luas_bumi,
 * luas_bng, njop, pajak_ditetapkan, terbayar, status_bayar, tanggal_bayar,
 * tahun_pajak, pemungut. Varian nama pada ALIAS_KOLOM_SPPT juga diterima.
 */
/** Buang seluruh data pajak agar impor ulang mulai dari nol. */
function spptKosongkan(): void
{
    db_exec('DELETE FROM pembayaran');
    db_exec('DELETE FROM sppt');
    db_exec('DELETE FROM objek_pajak');
}

/**
 * Penampung bersama untuk importer SPPT: satu closure pemroses baris dan satu
 * closure ringkasan, supaya CSV maupun dump memakai pencatatan hasil yang sama.
 *
 * @return array{proses:callable(array<string,string>):void, ringkasan:callable(string):array}
 */
function spptPemroses(?int $tahun): array
{
    $tahunDefault = $tahun ?? (int) setelan('tahun_pbb_aktif', '2026');
    $kodeWilayah = (string) setelan('kode_wilayah_nop', '3324040008');

    $petaDusun = [];
    foreach (daftar_dusun() as $baris) {
        $petaDusun[strtoupper(trim((string) $baris['nama']))] = (int) $baris['id'];
    }

    $pencacah = ['ok' => 0, 'lewati' => 0, 'galat' => []];

    $proses = static function (array $data) use (&$pencacah, $tahunDefault, $kodeWilayah, $petaDusun): void {
        $nop = normalkan_nop((string) ($data['nop'] ?? ''));
        try {
            if (spptSimpanBaris($data, $tahunDefault, $kodeWilayah, $petaDusun) === 'ok') {
                $pencacah['ok']++;
            } else {
                $pencacah['lewati']++;
                if (count($pencacah['galat']) < 20 && $nop !== '') {
                    $pencacah['galat'][] = 'NOP di luar kode wilayah ' . $kodeWilayah . ': ' . $nop;
                }
            }
        } catch (Throwable $error) {
            if (count($pencacah['galat']) < 20) {
                $pencacah['galat'][] = 'NOP ' . ($nop ?: '?') . ': ' . $error->getMessage();
            }
        }
    };

    $ringkasan = static function (string $jenis) use (&$pencacah): array {
        return [
            'jenis' => $jenis,
            'baris' => $pencacah['ok'],
            'dilewati' => $pencacah['lewati'],
            'galat' => $pencacah['galat'],
        ];
    };

    return ['proses' => $proses, 'ringkasan' => $ringkasan];
}

/**
 * Pecah isi satu tuple SQL, contoh: ("a", 'b, c', NULL), menjadi daftar string.
 * Petik tunggal, escape backslash, dan petik ganda dihormati; NULL jadi kosong.
 *
 * @return array<int,string>
 */
function sqlPecahTuple(string $teks): array
{
    $nilai = [];
    $kini = '';
    $dalamKutip = false;
    $panjang = strlen($teks);

    for ($i = 0; $i < $panjang; $i++) {
        $huruf = $teks[$i];

        if ($dalamKutip) {
            if ($huruf === '\\' && $i + 1 < $panjang) {
                $berikut = $teks[$i + 1];
                $kini .= match ($berikut) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => '', '0' => '',
                    default => $berikut,
                };
                $i++;
                continue;
            }
            if ($huruf === "'") {
                if ($i + 1 < $panjang && $teks[$i + 1] === "'") {
                    $kini .= "'";
                    $i++;
                    continue;
                }
                $dalamKutip = false;
                continue;
            }
            $kini .= $huruf;
            continue;
        }

        if ($huruf === "'") {
            $dalamKutip = true;
            continue;
        }
        if ($huruf === ',') {
            $bersih = trim($kini);
            $nilai[] = strcasecmp($bersih, 'NULL') === 0 ? '' : $bersih;
            $kini = '';
            continue;
        }
        $kini .= $huruf;
    }

    $bersih = trim($kini);
    $nilai[] = strcasecmp($bersih, 'NULL') === 0 ? '' : $bersih;

    return $nilai;
}
/**
 * Peta kolom CSV (nama => posisi) menjadi asosiasi nama => nilai.
 * @param array<string,int> $indeks
 */
function csvKeAsosiasi(array $indeks, array $baris): array
{
    $data = [];
    foreach ($indeks as $nama => $posisi) {
        $data[$nama] = (string) ($baris[$posisi] ?? '');
    }
    return $data;
}

/**
 * Simpan satu baris data SPPT ke objek_pajak lalu sppt. Dipakai oleh importer
 * CSV dan importer dump lama, jadi aturan NOP, dusun, dan status cukup sekali.
 *
 * @param array<string,string> $data      kolom => nilai mentah
 * @param array<string,int>    $petaDusun NAMA DUSUN => id
 * @return string 'ok' | 'lewati'
 */
function spptSimpanBaris(array $data, int $tahunDefault, string $kodeWilayah, array $petaDusun): string
{
    $ambil = static fn(string $kolom): string => trim((string) ($data[$kolom] ?? ''));

    $nop = normalkan_nop($ambil('nop'));
    if (strlen($nop) !== 18 || ($kodeWilayah !== '' && !str_starts_with($nop, $kodeWilayah))) {
        return 'lewati';
    }

    $alamatOp = $ambil('alamat_op');
    $teksDusun = $ambil('dusun');
    $dusunId = $teksDusun !== ''
        ? ($petaDusun[strtoupper($teksDusun)] ?? null)
        : dusun_dari_teks($alamatOp);

    $tahunPajak = (int) ($ambil('tahun_pajak') ?: $tahunDefault);
    $ketetapan = angkaTeks($ambil('pajak_ditetapkan'));
    $terbayar = min(angkaTeks($ambil('terbayar')), $ketetapan);
    $status = ($ambil('status_bayar') === 'Lunas' || ($ketetapan > 0 && $terbayar >= $ketetapan))
        ? 'Lunas'
        : 'Belum Bayar';
    $tanggal = $ambil('tanggal_bayar');
    $tanggal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ? $tanggal : ($status === 'Lunas' ? date('Y-m-d') : null);

    $luasBumi = $ambil('luas_bumi');
    $luasBng = $ambil('luas_bng');
    $blokId = blok_dari_nop($nop);

    db_transaksi(static function () use (
        $nop, $dusunId, $blokId, $ambil, $alamatOp, $tahunPajak,
        $ketetapan, $terbayar, $status, $tanggal, $luasBumi, $luasBng
    ): void {
        db_exec(
            'INSERT INTO objek_pajak
                (nop, dusun_id, blok_id, nama_wp, alamat_wp, alamat_op, luas_bumi_m2, luas_bng_m2)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                dusun_id = COALESCE(VALUES(dusun_id), dusun_id),
                blok_id = COALESCE(VALUES(blok_id), blok_id),
                nama_wp = VALUES(nama_wp),
                alamat_wp = VALUES(alamat_wp),
                alamat_op = VALUES(alamat_op),
                luas_bumi_m2 = VALUES(luas_bumi_m2),
                luas_bng_m2 = VALUES(luas_bng_m2)',
            [
                $nop, $dusunId, $blokId,
                mb_substr($ambil('nama_wp'), 0, 150) ?: '—',
                mb_substr($ambil('alamat_wp'), 0, 200) ?: null,
                mb_substr($alamatOp, 0, 200) ?: null,
                $luasBumi === '' ? null : (int) angkaTeks($luasBumi),
                $luasBng === '' ? 0 : (int) angkaTeks($luasBng),
            ]
        );

        db_exec(
            'INSERT INTO sppt
                (nop, tahun_pajak, njop_pbb, pajak_ditetapkan, terbayar, status_bayar, tanggal_bayar, pemungut, sumber)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                njop_pbb = VALUES(njop_pbb),
                pajak_ditetapkan = VALUES(pajak_ditetapkan),
                terbayar = VALUES(terbayar),
                status_bayar = VALUES(status_bayar),
                tanggal_bayar = VALUES(tanggal_bayar)',
            [
                $nop, $tahunPajak,
                angkaTeks($ambil('njop')),
                $ketetapan, $terbayar, $status, $tanggal,
                mb_substr($ambil('pemungut'), 0, 60) ?: null,
                'import',
            ]
        );
    });

    return 'ok';
}

/** Nama kolom lain yang biasa muncul di lembar Excel desa. */
const ALIAS_KOLOM_SPPT = [
    'nop_pbb'      => 'nop',
    'nama_pemilik' => 'nama_wp',
    'pbb_terutang' => 'pajak_ditetapkan',
    'tagihan_pbb'  => 'pajak_ditetapkan',
    'njop_pbb'     => 'njop',
    'tgl_bayar'    => 'tanggal_bayar',
];

function imporSpptCsv(string $path, ?int $tahun = null, bool $reset = false): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Berkas CSV tidak ditemukan: ' . $path);
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Berkas CSV gagal dibuka: ' . $path);
    }

    $kepala = fgetcsv($handle);
    if (!is_array($kepala)) {
        fclose($handle);
        throw new RuntimeException('Baris pertama CSV harus berisi nama kolom.');
    }

    $indeks = [];
    foreach ($kepala as $posisi => $nama) {
        $namaBersih = strtolower(trim((string) preg_replace('/[^a-z0-9_]/i', '_', (string) $nama)));
        if ($namaBersih !== '') {
            $indeks[$namaBersih] = $posisi;
        }
    }
    if (!isset($indeks['nop'])) {
        fclose($handle);
        throw new RuntimeException('Kolom "nop" wajib ada pada CSV.');
    }
    foreach (ALIAS_KOLOM_SPPT as $alias => $kanonik) {
        if (isset($indeks[$alias]) && !isset($indeks[$kanonik])) {
            $indeks[$kanonik] = $indeks[$alias];
        }
    }

    if ($reset) {
        spptKosongkan();
    }

    $pemroses = spptPemroses($tahun);
    while (($baris = fgetcsv($handle)) !== false) {
        if ($baris === [null] || $baris === [false] || $baris === ['']) {
            continue;
        }
        $pemroses['proses'](csvKeAsosiasi($indeks, $baris));
    }
    fclose($handle);

    return $pemroses['ringkasan']('sppt-csv');
}

/**
 * Impor data PBB dari dump lama (mysqldump) tanpa harus menjalankan dump itu.
 * Hanya tabel sppt_gedong yang dibaca; geometrinya diabaikan karena bidang
 * sudah dimuat dari GeoJSON.
 */
function imporSpptDump(string $path, ?int $tahun = null, bool $reset = false): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Berkas dump tidak ditemukan: ' . $path);
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Berkas dump gagal dibuka: ' . $path);
    }

    if ($reset) {
        spptKosongkan();
    }

    $pemroses = spptPemroses($tahun);
    $kolom = null;

    while (($teks = fgets($handle)) !== false) {
        $teks = rtrim($teks, "\r\n");
        if ($teks === '') {
            continue;
        }

        if (preg_match('/^INSERT INTO `sppt_gedong` \((.+)\) VALUES/i', $teks, $cocok)) {
            $kolom = array_map(
                static fn(string $s): string => strtolower(trim($s, '` ')),
                explode(',', $cocok[1])
            );
            continue;
        }
        if ($kolom === null) {
            continue;
        }

        // Baris nilai diawali "(", diakhiri ")," atau ");".
        if ($teks[0] !== '(') {
            $kolom = null;
            continue;
        }
        $potong = strrpos($teks, ')');
        if ($potong === false) {
            continue;
        }
        $nilai = sqlPecahTuple(substr($teks, 1, $potong - 1));
        if (count($nilai) >= count($kolom)) {
            $data = [];
            foreach ($kolom as $posisi => $nama) {
                $data[$nama] = $nilai[$posisi];
            }
            $pemroses['proses']($data);
        }
    }
    fclose($handle);

    return $pemroses['ringkasan']('sppt-dump');
}
// ---------------------------------------------------------------------------
// 4. Indeks arsip PDF Buku C
// ---------------------------------------------------------------------------

/**
 * Daftarkan hasil pindai di folder arsip ke tabel buku_c. Baris yang sudah ada
 * tidak disentuh; nama pemilik diisi manual lewat halaman Buku C.
 */
function indeksBukuC(?string $folder = null): array
{
    $folder ??= (string) sip_config('path.arsip_buku_c');
    if (!is_dir($folder)) {
        throw new RuntimeException('Folder arsip tidak ada: ' . $folder);
    }

    $sudah = [];
    foreach (db_all('SELECT nomor FROM buku_c') as $baris) {
        $sudah[(int) $baris['nomor']] = true;
    }

    $dibuat = 0; $dilewati = 0;
    foreach ((array) scandir($folder) as $namaBerkas) {
        if (!preg_match('/^(\d+)\.pdf$/i', (string) $namaBerkas, $cocok)) {
            continue;
        }
        $nomor = (int) $cocok[1];
        if (isset($sudah[$nomor])) {
            $dilewati++;
            continue;
        }
        db_exec('INSERT INTO buku_c (nomor, berkas_pdf) VALUES (?, ?)',
            [$nomor, str_replace('\\', '/', $namaBerkas)]);
        $sudah[$nomor] = true;
        $dibuat++;
    }

    return ['jenis' => 'buku_c', 'dibuat' => $dibuat, 'dilewati' => $dilewati, 'folder' => $folder];
}

// ---------------------------------------------------------------------------
// 5. PETA GANG (label GG pada MASTER.dxf)
// ---------------------------------------------------------------------------

/**
 * MASTER.dxf memakai koordinat meter proyeksi lokal, bukan lintang/bujur.
 * Koefisien di bawah dikocokkan dengan mencocokkan label nomor bidang pada
 * layer "NIB HM" terhadap label_coordinates yang sudah dibetulkan di
 * bidang_tanah.geojson (1.435 pasangan, sisa kuadrat terkecil derajat satu:
 * RMS 1,2 m). Derajat dua tidak memperbaiki, jadi transformasinya affine.
 * Ulangi pengocokan dengan `php tools/fit-transform-dxf.php` bila MASTER.dxf
 * diganti.
 */
const DXF_KE_WGS84 = [
    'lon' => [107.6978701759, 9.052689479256e-6, -1.174350305552e-8],
    'lat' => [-13.56764666871, 1.058144066869e-8, 9.042751981065e-6],
];

/** Meter proyeksi DXF -> [bujur, lintang] derajat. */
function dxfKeWgs84(float $bujurMeter, float $lintangMeter): array
{
    $lon = DXF_KE_WGS84['lon'];
    $lat = DXF_KE_WGS84['lat'];

    return [
        $lon[0] + $lon[1] * $bujurMeter + $lon[2] * $lintangMeter,
        $lat[0] + $lat[1] * $bujurMeter + $lat[2] * $lintangMeter,
    ];
}

/**
 * Berkas peta master yang dipakai perintah berbasis DXF.
 *
 * Peta lapangan diserahkan sebagai DWG yang tidak bisa dibaca PHP, jadi berkas
 * .dwg dikonversi lebih dulu dengan LibreDWG portabel di tools/vendor/libredwg
 * dan hasilnya dipakai ulang selama sumbernya tidak berubah. Hasil konversi
 * memakai nama <nama>-dari-dwg.dxf sehingga MASTER.dxf yang sudah ada tidak
 * pernah tertimpa.
 */
function masterDxf(?string $berkas = null): string
{
    $path = $berkas ?? SIP_ROOT . '/files/master peta/MASTER.dxf';
    if (!is_file($path)) {
        throw new RuntimeException('Berkas peta master tidak ditemukan: ' . $path);
    }
    $path = (string) realpath($path);
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'dwg') {
        return $path;
    }

    $dxf = preg_replace('/\.dwg$/i', '', $path) . '-dari-dwg.dxf';
    if (is_file($dxf) && filemtime($dxf) >= filemtime($path)) {
        return $dxf;
    }

    $exe = SIP_ROOT . '/tools/vendor/libredwg/dwg2dxf.exe';
    if (!is_file($exe)) {
        throw new RuntimeException(
            'Membaca DWG perlu LibreDWG: unduh libredwg-*-win64.zip dari '
            . 'https://github.com/LibreDWG/libredwg/releases, isi tools/vendor/libredwg/.'
        );
    }
    // dwg2dxf mencetak peringatan untuk objek yang tidak dikenali; itu bukan
    // kegagalan, jadi yang diperiksa hanya berkas hasilnya.
    exec(
        escapeshellarg($exe) . ' -y -o ' . escapeshellarg($dxf) . ' ' . escapeshellarg($path)
        . ' > ' . escapeshellarg($dxf . '.log') . ' 2>&1',
        $keluar,
        $kode
    );
    if (!is_file($dxf) || filesize($dxf) === 0) {
        throw new RuntimeException('Konversi ' . $path . ' gagal (kode ' . $kode . ').');
    }

    return $dxf;
}

/**
 * Ambil semua entity TEXT dari sebuah DXF: layer, isi teks, titik sisip meter.
 * Baca baris demi baris karena MASTER.dxf 18 MB.
 *
 * @return array<int, array{layer:string, teks:string, x:float, y:float}>
 */
function dxfTeks(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Berkas DXF tidak ditemukan: ' . $path);
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Berkas DXF gagal dibuka: ' . $path);
    }

    $out = [];
    $entity = null; $layer = null; $teks = null; $x = null; $y = null;
    $simpan = static function () use (&$entity, &$layer, &$teks, &$x, &$y, &$out): void {
        if ($entity === 'TEXT' && $layer !== null && $teks !== null && $x !== null && $y !== null) {
            $isi = trim($teks);
            if ($isi !== '') {
                $out[] = ['layer' => $layer, 'teks' => $isi, 'x' => $x, 'y' => $y];
            }
        }
        $entity = null; $layer = null; $teks = null; $x = null; $y = null;
    };

    $kode = null;
    while (($baris = fgets($handle)) !== false) {
        $potong = rtrim($baris);
        if ($potong === '') {
            continue;
        }
        if ($kode === null) {
            if (preg_match('/^\s*\d+$/', $potong)) {
                $kode = (int) $potong;
            }
            continue;
        }
        $nilai = trim($potong);
        switch ($kode) {
            case 0: $simpan(); $entity = $nilai; break;
            case 8: $layer = $nilai; break;
            case 1: $teks = $nilai; break;
            case 10: if ($x === null) { $x = (float) $nilai; } break;
            case 20: if ($y === null) { $y = (float) $nilai; } break;
        }
        $kode = null;
    }
    $simpan();
    fclose($handle);

    return $out;
}

/**
 * Baca seluruh garis poligon (LWPOLYLINE dan POLYLINE ber-VERTEX) dari berkas
 * DXF. Titiknya masih dalam satuan meter proyek berkas, jadi teruskan ke
 * dxfKeWgs84() sebelum dipakai. Baris dibaca sekali-satu, jadi berkas besar
 * tidak memenuhi memori.
 *
 * @return array<int, array{layer:string, titik:array<int, array{0:float,1:float}>, tertutup:bool}>
 */
function dxfPoligon(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Berkas DXF tidak ditemukan: ' . $path);
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Berkas DXF gagal dibuka: ' . $path);
    }

    $out = [];
    $entity = null; $layer = null; $bendera = 0; $titik = []; $x = null;
    $simpan = static function () use (&$entity, &$layer, &$bendera, &$titik, &$out, &$x): void {
        if (($entity === 'LWPOLYLINE' || $entity === 'POLYLINE') && count($titik) >= 3) {
            $out[] = ['layer' => (string) $layer, 'titik' => $titik, 'tertutup' => ($bendera & 1) === 1];
        }
        // VERTEX dan SEQEND melanjutkan POLYLINE sebelumnya, jadi jangan dihapus.
        if ($entity !== 'VERTEX' && $entity !== 'SEQEND') {
            $layer = null; $bendera = 0; $titik = [];
        }
        $x = null;
    };

    $kode = null;
    while (($baris = fgets($handle)) !== false) {
        $potong = rtrim($baris);
        if ($potong === '') {
            continue;
        }
        if ($kode === null) {
            if (preg_match('/^\s*\d+$/', $potong)) {
                $kode = (int) $potong;
            }
            continue;
        }
        $nilai = trim($potong);
        switch ($kode) {
            case 0: $simpan(); $entity = $nilai; break;
            case 8: $layer = $nilai; break;
            case 70: $bendera = (int) $nilai; break;
            case 10: if ($x === null) { $x = (float) $nilai; } break;
            case 20: if ($x !== null) { $titik[] = [$x, (float) $nilai]; $x = null; } break;
        }
        $kode = null;
    }
    $simpan();
    fclose($handle);

    return $out;
}

/**
 * Daftar tanah gang (label "GG" dan "TANAH GG") dalam MASTER.dxf,
 * sudah dipindahkan ke WGS84. Label ini adalah titik penanda, bukan poligon:
 * MASTER.dxf tidak memuat bentuk gang, jadi geometrinya masih harus digitasi.
 *
 * @return array<int, array{kode:string, teks:string, sumber_layer:string, lon:float, lat:float}>
 */
function petaGgLabel(?string $path = null): array
{
    $path = masterDxf($path);
    $temuan = [];
    foreach (dxfTeks($path) as $t) {
        $isi = strtoupper(preg_replace('/\s+/', ' ', $t['teks']) ?? '');
        if ($isi !== 'GG' && $isi !== 'TANAH GG') {
            continue;
        }
        [$lon, $lat] = dxfKeWgs84($t['x'], $t['y']);
        $temuan[] = [
            'kode' => null,
            'teks' => $isi,
            'sumber_layer' => $t['layer'],
            'lon' => round($lon, 7),
            'lat' => round($lat, 7),
        ];
    }
    foreach ($temuan as $i => &$gg) {
        $gg['kode'] = 'GG-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
    }
    unset($gg);

    return $temuan;
}

/**
 * Tentukan blok tempat tiap penanda gang jatuh. Kalau tidak ada blok yang
 * memuat, blok terdekat beserta jarak perkiraannya yang dipakai, supaya
 * petugas tahu harus mengecek yang mana.
 *
 * Jarak dihitung dalam derajat lalu dikali 111.320 m; cukup untuk perkiraan.
 *
 * @param array<int, array> $label hasil petaGgLabel()
 * @return array<int, array> label yang sudah dilengkapi blok
 */
function petaGgTandaiBlok(array $label): array
{
    $blok = db_all('SELECT id, kode, nomor FROM blok ORDER BY kode');
    if (!$blok) {
        throw new RuntimeException('Tabel blok masih kosong. Jalankan impor blok lebih dulu.');
    }

    $cari = db()->prepare(
        'SELECT id FROM blok
          WHERE ST_Contains(geom, ST_GeomFromText(CONCAT("POINT(", ?, " ", ?, ")"), 4326))
          LIMIT 1'
    );
    $jarak = db()->prepare(
        'SELECT kode, nomor,
                ROUND(MIN(ST_Distance(geom, ST_GeomFromText(CONCAT("POINT(", ?, " ", ?, ")"), 4326))) * 111320, 1) meter
           FROM blok'
    );

    $peta = [];
    foreach ($blok as $b) {
        $peta[(int) $b['id']] = ['kode' => (string) $b['kode'], 'nomor' => $b['nomor']];
    }

    foreach ($label as &$gg) {
        $cari->execute([$gg['lon'], $gg['lat']]);
        $id = $cari->fetchColumn();
        if ($id !== false && $id !== null) {
            $gg['blok'] = $peta[(int) $id]['kode'];
            $gg['nomor_blok'] = $peta[(int) $id]['nomor'] === null ? null : (int) $peta[(int) $id]['nomor'];
            $gg['jarak_blok_m'] = 0.0;
            $gg['perlu_dicek'] = 0;
            continue;
        }
        $jarak->execute([$gg['lon'], $gg['lat']]);
        $terdekat = $jarak->fetch(PDO::FETCH_ASSOC);
        $gg['blok'] = $terdekat['kode'] ?? null;
        $gg['nomor_blok'] = isset($terdekat['nomor']) && $terdekat['nomor'] !== null
            ? (int) $terdekat['nomor']
            : null;
        $gg['jarak_blok_m'] = isset($terdekat['meter']) ? (float) $terdekat['meter'] : null;
        $gg['perlu_dicek'] = 1;
    }
    unset($gg);

    return $label;
}

/**
 * Tulis daftar penanda gang menjadi GeoJSON titik + CSV di folder yang sama.
 * Berkas ini dipakai untuk mendigitasi bentuk gang; setelah jadi poligon,
 * unggah lewat pasang.php atau `php tools/impor.php gang <berkas.geojson> --terapkan`.
 *
 * @return array{geojson:string, csv:string, jumlah:int}
 */
function petaGgTulis(array $label, ?string $folder = null): array
{
    $folder ??= SIP_ROOT . '/files/peta gang';
    if (!is_dir($folder) && !mkdir($folder, 0775, true)) {
        throw new RuntimeException('Folder tidak bisa dibuat: ' . $folder);
    }

    $fitur = [];
    foreach ($label as $gg) {
        $fitur[] = [
            'type' => 'Feature',
            'properties' => [
                'id_gg' => $gg['kode'],
                'label' => $gg['teks'],
                'sumber_layer' => $gg['sumber_layer'],
                'blok' => $gg['blok'],
                'nomor_blok' => $gg['nomor_blok'],
                'jarak_blok_m' => $gg['jarak_blok_m'],
                'status_hak' => 'Hak Pakai',
                'perlu_dicek' => (bool) $gg['perlu_dicek'],
            ],
            'geometry' => ['type' => 'Point', 'coordinates' => [$gg['lon'], $gg['lat']]],
        ];
    }

    $geojson = $folder . '/peta_gg.geojson';
    $ditulis = json_encode([
        'type' => 'FeatureCollection',
        'name' => 'Penanda tanah gang (GG) Desa Gedong dari MASTER.dxf',
        'catatan' => 'Titik penanda, bukan poligon. Digitasi bentuk gang lalu impor sebagai Hak Pakai.',
        'features' => $fitur,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($geojson, $ditulis . "\n");

    $csv = $folder . '/peta_gg.csv';
    $penhandle = fopen($csv, 'w');
    if ($penhandle === false) {
        throw new RuntimeException('CSV gagal ditulis: ' . $csv);
    }
    fputcsv($penhandle, ['kode_gg', 'label', 'layer_asal', 'bujur', 'lintang', 'blok', 'nomor_blok', 'jarak_blok_m', 'perlu_dicek']);
    foreach ($label as $gg) {
        fputcsv($penhandle, [
            $gg['kode'], $gg['teks'], $gg['sumber_layer'], $gg['lon'], $gg['lat'],
            $gg['blok'], $gg['nomor_blok'], $gg['jarak_blok_m'], $gg['perlu_dicek'] ? 'ya' : 'tidak',
        ]);
    }
    fclose($penhandle);

    return ['geojson' => $geojson, 'csv' => $csv, 'jumlah' => count($label)];
}

/**
 * Rangkaian lengkap: baca MASTER.dxf, tentukan nomor blok tiap penanda, tulis
 * GeoJSON + CSV. Dipakai perintah `impor.php gg` dan halaman pasang.php.
 *
 * @return array<string, mixed> ringkasan, plus daftar titik pada kunci 'label'
 */
function petaGgEkspor(?string $dxf = null, ?string $folder = null): array
{
    $label = petaGgTandaiBlok(petaGgLabel($dxf));
    $tulis = petaGgTulis($label, $folder);

    return [
        'jenis' => 'gg',
        'dibaca' => $tulis['jumlah'],
        'perlu_dicek' => count(array_filter($label, static fn(array $g): bool => (bool) $g['perlu_dicek'])),
        'geojson' => $tulis['geojson'],
        'csv' => $tulis['csv'],
        'label' => $label,
    ];
}

// ---------------------------------------------------------------------------
// 6. Impor poligon gang ke Hak Pakai
// ---------------------------------------------------------------------------

/** Pemegang hak untuk tanah gang: aset Pemerintah Desa. */
const GG_PEMEGANG = 'Pemerintah Desa Gedong';

/** Nilai kolom bidang.sumber untuk tanah gang. */
const GG_SUMBER = 'GG';

/**
 * Impor poligon gang menjadi bidang ber-Hak Pakai, dikelompokkan per nomor
 * blok. Setiap feature wajib punya `blok` (kode blok, mis. "BLOK 4") dan
 * `id_bidang`; nilainya yang menentukan blok_id, jadi nomor blok ikut terisi.
 *
 * Tanpa $terapkan fungsi ini hanya memeriksa berkas dan melaporkan isinya.
 *
 * @return array<string, mixed>
 */
function imporGangBerkas(string $path, bool $terapkan = false): array
{
    if (!is_file($path)) {
        throw new RuntimeException('GeoJSON gang tidak ditemukan: ' . $path);
    }
    $fitur = geojsonBaca($path);
    if (!$fitur) {
        throw new RuntimeException('GeoJSON gang tidak berisi feature: ' . $path);
    }

    $kodeBlok = [];
    foreach (db_all('SELECT kode FROM blok') as $baris) {
        $kodeBlok[(string) $baris['kode']] = true;
    }

    $kurang = [];
    foreach ($fitur as $nomor => $f) {
        $props = $f['properties'] ?? [];
        $kode = trim((string) ($props['id_bidang'] ?? ''));
        $namaBlok = trim((string) ($props['blok'] ?? ''));
        if ($kode === '') {
            $kurang[] = 'feature #' . ($nomor + 1) . ' tanpa id_bidang';
        } elseif ($namaBlok === '') {
            $kurang[] = 'feature ' . $kode . ' tanpa blok';
        } elseif (!isset($kodeBlok[$namaBlok])) {
            $kurang[] = 'feature ' . $kode . ' memakai blok "' . $namaBlok . '" yang tidak ada di tabel blok';
        } elseif (geojsonKeWkt($f['geometry'] ?? []) === null) {
            $kurang[] = 'feature ' . $kode . ' geometri poligonnya tidak sah';
        }
    }
    if ($kurang) {
        throw new RuntimeException(
            'Berkas belum siap impor: ' . implode('; ', array_slice($kurang, 0, 8))
            . (count($kurang) > 8 ? ' (' . count($kurang) . ' secara total).' : '.')
        );
    }

    $namaHak = (string) (db_value("SELECT nama FROM jenis_hak WHERE kode = 'HP'") ?: 'Hak Pakai');
    foreach ($fitur as &$f) {
        $f['properties']['status_hak'] = $namaHak;
        $f['properties']['sumber_layer'] = GG_SUMBER;
        $f['properties']['pemegang_hak'] = GG_PEMEGANG;
    }
    unset($f);

    if (!$terapkan) {
        return ['jenis' => 'gang', 'dibaca' => count($fitur), 'dibuat' => 0];
    }

    $tmp = (string) tempnam(SIP_ROOT . '/storage', 'gang');
    file_put_contents($tmp, json_encode(['type' => 'FeatureCollection', 'features' => $fitur]));
    try {
        $hasil = imporBidang($tmp, 'lain', false);
    } finally {
        @unlink($tmp);
    }
    $hasil['jenis'] = 'gang';
    $hasil['pemegang'] = GG_PEMEGANG;

    return $hasil;
}

// ---------------------------------------------------------------------------
// 7. Menggabungkan dua blok peta
// ---------------------------------------------------------------------------

/** Ambil satu baris blok dari kode, atau nomor blok saja ("11" = "BLOK 11"). */
function blokCari(string $kode): array
{
    $baku = strtoupper(trim($kode));
    foreach (array_unique([$kode, $baku, 'BLOK ' . $baku]) as $uji) {
        $baris = db_one(
            'SELECT id, kode, nomor, nama, batas_tersedia, batas_perkiraan, sumber_perkiraan,
                    jumlah_bidang_estimasi, ST_AsText(geom) AS wkt
             FROM blok WHERE kode = ? LIMIT 1',
            [$uji]
        );
        if (is_array($baris)) {
            return $baris;
        }
    }
    throw new RuntimeException('Blok "' . $kode . '" tidak ada di tabel blok.');
}

/**
 * Pindahkan seluruh bidang milik satu blok ke blok lain, lebur batas blok
 * sumber ke dalam blok tujuan, lalu buang baris sumber. Batas, daftar bidang
 * dan identitas kedua blok dicadangkan ke berkas JSON di storage/ sebelum ada
 * perubahan, supaya penggabungan bisa dibalik.
 *
 * Tanpa `$terapkan` fungsi ini hanya melaporkan apa yang akan berubah.
 *
 * @return array<string, mixed>
 */
function gabungBlok(string $kodeAsal, string $kodeTujuan, bool $terapkan = false): array
{
    $asal = blokCari($kodeAsal);
    $tujuan = blokCari($kodeTujuan);
    if ((int) $asal['id'] === (int) $tujuan['id']) {
        throw new RuntimeException('Blok sumber dan blok tujuan harus berbeda.');
    }

    $bidang = db_all(
        'SELECT kode, nib, luas_m2 FROM bidang WHERE blok_id = ? ORDER BY kode',
        [(int) $asal['id']]
    );

    $hasil = [
        'jenis' => 'gabung',
        'sumber' => $asal['kode'],
        'tujuan' => $tujuan['kode'],
        'bidang_pindah' => count($bidang),
        'sumber_perkiraan' => $asal['sumber_perkiraan'],
    ];

    if (!$terapkan) {
        $hasil['dipindahkan'] = 0;
        $hasil['bidang'] = array_map(
            static fn(array $b): string => (string) $b['kode'] . ' (' . (int) $b['luas_m2'] . ' m2)',
            $bidang
        );
        return $hasil;
    }

    $cadangan = SIP_ROOT . '/storage/gabung-blok-' . date('Ymd-His') . '.json';
    $ditulis = file_put_contents(
        $cadangan,
        json_encode(
            ['waktu' => date('c'), 'blok_sumber' => $asal, 'blok_tujuan' => $tujuan, 'bidang' => $bidang],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )
    );
    if ($ditulis === false) {
        throw new RuntimeException('Cadangan gagal ditulis ke ' . $cadangan . '. Penggabungan dibatalkan.');
    }

    $dipindahkan = db_transaksi(static function (PDO $connect) use ($asal, $tujuan): int {
        $pindah = $connect->prepare('UPDATE bidang SET blok_id = ? WHERE blok_id = ?');
        $pindah->execute([(int) $tujuan['id'], (int) $asal['id']]);

        // ST_Union mengembalikan geometri tanpa SRID dan MariaDB 10.4 tidak punya
        // ST_SetSRID, jadi hasil gabungan dibaca sebagai WKT lalu dipasang kembali.
        $gabung = $connect->prepare(
            'SELECT ST_AsText(ST_Union(geom, ST_GeomFromText(?, 4326))) FROM blok WHERE id = ?'
        );
        $gabung->execute([(string) $asal['wkt'], (int) $tujuan['id']]);
        $wktBaru = wktKeMultipolygon((string) $gabung->fetchColumn());

        $pasang = $connect->prepare('UPDATE blok SET geom = ST_GeomFromText(?, 4326) WHERE id = ?');
        $pasang->execute([$wktBaru, (int) $tujuan['id']]);

        $buang = $connect->prepare('DELETE FROM blok WHERE id = ?');
        $buang->execute([(int) $asal['id']]);

        return $pindah->rowCount();
    });

    audit(
        'ubah',
        'blok',
        (int) $tujuan['id'],
        'Blok ' . $asal['kode'] . ' digabungkan ke ' . $tujuan['kode']
            . '; ' . $dipindahkan . ' bidang dipindahkan, batas ' . $asal['kode'] . ' dilebur dan barisnya dihapus. '
            . 'Cadangan: ' . $cadangan,
        ['blok' => $asal['kode'], 'nomor' => $asal['nomor'], 'jumlah_bidang' => count($bidang)],
        ['blok' => $tujuan['kode'], 'nomor' => $tujuan['nomor'], 'bidang_bertambah' => $dipindahkan]
    );

    $luasSesudah = db_value(
        'SELECT ROUND(ST_Area(geom) * 1.23e10, 0) FROM blok WHERE id = ?',
        [(int) $tujuan['id']]
    );

    return $hasil + ['dipindahkan' => $dipindahkan, 'luas_tujuan_m2' => (int) $luasSesudah, 'cadangan' => $cadangan];
}

// ---------------------------------------------------------------------------
// 8. Batas blok dari peta dasar
// ---------------------------------------------------------------------------

const BATAS_BLOK_LAPISAN = 'BATAS BLOK';
const BATAS_BLOK_MIN_M2 = 20000.0;
const BATAS_PENYANGGA_M = 15.0;

/** Luas poligon dari daftar titik dalam meter proyek (rumus talibusur). */
function poligonLuas(array $titik): float
{
    $total = 0.0;
    $n = count($titik);
    for ($i = 0; $i < $n; $i++) {
        [$ax, $ay] = $titik[$i];
        [$bx, $by] = $titik[($i + 1) % $n];
        $total += $ax * $by - $bx * $ay;
    }

    return abs($total) / 2.0;
}

/** Titik meter proyek menjadi WKT poligon WGS84; cincin ditutup bila belum tertutup. */
function poligonKeWkt(array $titik): string
{
    $sudut = array_map(
        static function (array $t): string {
            [$lon, $lat] = dxfKeWgs84($t[0], $t[1]);
            return number_format($lon, 8, '.', '') . ' ' . number_format($lat, 8, '.', '');
        },
        $titik
    );
    if (reset($sudut) !== end($sudut)) {
        $sudut[] = reset($sudut);
    }

    return 'POLYGON((' . implode(',', $sudut) . '))';
}

/**
 * Garis luar tiap blok pada lapisan BATAS BLOK di MASTER.dxf. Sebuah garis luar
 * dianggap milik blok yang paling banyak menampung bidang, jadi blok yang
 * garisnya tidak sejalan dengan sebaran bidangnya tidak ikut terpakai.
 *
 * @return array<string, array{wkt:string, luas_m2:float, bidang:int}>
 */
function garisLuarBlok(?string $dxf = null): array
{
    $dxf = masterDxf($dxf);
    $temuan = [];

    foreach (dxfPoligon($dxf) as $p) {
        $luas = poligonLuas($p['titik']);
        if ($p['layer'] !== BATAS_BLOK_LAPISAN || $luas < BATAS_BLOK_MIN_M2) {
            continue;
        }
        $wkt = poligonKeWkt($p['titik']);
        $punya = db_one(
            'SELECT b.kode AS kode, COUNT(*) AS bidang
             FROM bidang d JOIN blok b ON b.id = d.blok_id
             WHERE ST_Contains(ST_GeomFromText(?, 4326), ST_Centroid(d.geom))
             GROUP BY b.kode ORDER BY COUNT(*) DESC LIMIT 1',
            [$wkt]
        );
        if ($punya === null) {
            continue;
        }
        $kode = (string) $punya['kode'];
        if (!isset($temuan[$kode]) || $luas > $temuan[$kode]['luas_m2']) {
            $temuan[$kode] = ['wkt' => $wkt, 'luas_m2' => $luas, 'bidang' => (int) $punya['bidang']];
        }
    }

    return $temuan;
}

/**
 * Gabungkan daftar WKT jadi satu geometri. MariaDB 10.4 tidak punya ST_Union
 * bentuk agregat, jadi gabungan dikerjakan berpasangan dari tepi.
 * Hasilnya tanpa SRID; bungkus dengan ST_GeomFromText(?, 4326) saat disimpan.
 */
function wktGabung(array $daftarWkt): string
{
    $daftar = array_values(array_filter(array_map('strval', $daftarWkt), static fn(string $w): bool => $w !== ''));
    if ($daftar === []) {
        throw new RuntimeException('Tidak ada geometri yang bisa digabungkan.');
    }

    while (count($daftar) > 1) {
        $berikutnya = [];
        for ($i = 0, $n = count($daftar); $i < $n; $i += 2) {
            if ($i + 1 >= $n) {
                $berikutnya[] = $daftar[$i];
                continue;
            }
            $hasil = db_value(
                'SELECT ST_AsText(ST_Union(ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326)))',
                [$daftar[$i], $daftar[$i + 1]]
            );
            if (!is_string($hasil) || $hasil === '') {
                throw new RuntimeException('Penggabungan geometri blok gagal.');
            }
            $berikutnya[] = $hasil;
        }
        $daftar = $berikutnya;
    }

    return $daftar[0];
}

/** Luas WKT dalam meter persegi; derajat persegi dikonversi dengan pendekatan datar. */
function luasWkt(string $wkt): float
{
    $faktor = (111320.0 ** 2) * cos(deg2rad(7.08));

    return (float) db_value('SELECT ST_Area(ST_GeomFromText(?, 4326)) * ?', [$wkt, $faktor]);
}

/**
 * Kolom geometri blok bertipe MULTIPOLYGON, sedangkan ST_Buffer dan ST_Union
 * bisa mengembalikan POLYGON biasa. Rapihkan bentuknya sebelum disimpan.
 */
function wktKeMultipolygon(string $wkt): string
{
    $bersih = trim($wkt);
    if (preg_match('/^MULTIPOLYGON\s*\(/i', $bersih) === 1) {
        return $bersih;
    }
    if (preg_match('/^POLYGON\s*\(/i', $bersih) === 1) {
        return 'MULTIPOLYGON(' . substr($bersih, (int) strpos($bersih, '(')) . ')';
    }

    throw new RuntimeException('Batas blok harus poligon; yang diterima: ' . strtoupper(substr($bersih, 0, 32)));
}

/** Selubung luar pada jarak $meter; jarak tanah dikonversi ke derajat. */
function wktSelubung(string $wkt, float $meter): string
{
    $hasil = db_value(
        'SELECT ST_AsText(ST_Buffer(ST_GeomFromText(?, 4326), ?))',
        [$wkt, $meter / 111320.0]
    );
    if (!is_string($hasil) || $hasil === '') {
        throw new RuntimeException('Penyangga geometri blok gagal dihitung.');
    }

    return $hasil;
}

/** Simpan kondisi blok sebelum diubah ke berkas JSON di storage/. */
function cadanganBlok(string $nama, array $isi): string
{
    $path = SIP_ROOT . '/storage/' . $nama . '-' . date('Ymd-His') . '.json';
    // dua cadangan dalam detik yang sama tidak boleh saling menimpa
    for ($nomor = 2; is_file($path); $nomor++) {
        $path = SIP_ROOT . '/storage/' . $nama . '-' . date('Ymd-His') . '-' . $nomor . '.json';
    }
    if (file_put_contents($path, json_encode($isi, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        throw new RuntimeException('Cadangan gagal ditulis ke ' . $path . '.');
    }

    return $path;
}

/**
 * Pasang garis luar MASTER.dxf sebagai batas blok.
 *
 * Dengan `$sisip`, bidang milik blok yang jatuh di luar garis luar ikut
 * digabungkan: tidak ada bidang kehilangan blok, tetapi batasnya bisa terdiri
 * atas beberapa bagian. Tanpa `$sisip`, batasnya persis satu garis luar — bentuk
 * yang enak dilihat di peta — dan bidang blok itu yang berada di luarnya ditandai
 * perlu_verifikasi. Bidang milik blok lain yang jatuh di dalam garis luar tidak
 * pernah dipindahkan; statusnya saja yang ditandai.
 *
 * @param array<int, string> $daftarKode  kode atau nomor blok
 * @return array<string, mixed>
 */
function pasangGarisLuarBlok(array $daftarKode, bool $terapkan = false, bool $sisip = true, ?string $master = null): array
{
    $berkasMaster = masterDxf($master);
    // nama yang dicatat di sumber_perkiraan: berkas yang diberikan, bukan
    // hasil konversi DWG-nya
    $namaMaster = basename($master ?? SIP_ROOT . '/files/master peta/MASTER.dxf');
    $luar = garisLuarBlok($berkasMaster);
    $dipasang = 0;
    $ditandai = 0;
    $kurang = [];

    foreach ($daftarKode as $kodeMinta) {
        $blok = blokCari((string) $kodeMinta);
        $kode = (string) $blok['kode'];
        if (!isset($luar[$kode])) {
            $kurang[] = $kode . ': tidak ada garis luar di lapisan ' . BATAS_BLOK_LAPISAN;
            continue;
        }

        $wktLuar = $luar[$kode]['wkt'];
        $luarBidang = db_all(
            'SELECT d.kode, d.status, ST_AsText(d.geom) AS wkt FROM bidang d
             WHERE d.blok_id = ?
               AND NOT ST_Contains(ST_GeomFromText(?, 4326), ST_Centroid(d.geom))
             ORDER BY d.kode',
            [(int) $blok['id'], $wktLuar]
        );
        $asing = db_all(
            'SELECT d.kode, b.kode AS blok_asal FROM bidang d JOIN blok b ON b.id = d.blok_id
             WHERE d.blok_id <> ? AND d.status = "aktif"
               AND ST_Contains(ST_GeomFromText(?, 4326), ST_Centroid(d.geom))
             ORDER BY d.kode',
            [(int) $blok['id'], $wktLuar]
        );

        $perluDicek = $asing;
        if (!$sisip) {
            foreach ($luarBidang as $b) {
                if ($b['status'] === 'aktif') {
                    $perluDicek[] = ['kode' => $b['kode'], 'blok_asal' => $kode];
                }
            }
        }

        if (!$terapkan) {
            printf(
                "%s: garis luar %s m2, %d bidang %s, %d bidang perlu_verifikasi\n",
                $kode,
                number_format($luar[$kode]['luas_m2'], 0, ',', '.'),
                count($luarBidang),
                $sisip ? 'disisipkan' : 'di luar garis',
                count($perluDicek)
            );
            continue;
        }

        $baru = $sisip
            ? wktKeMultipolygon(wktGabung(array_merge([$wktLuar], array_column($luarBidang, 'wkt'))))
            : wktKeMultipolygon($wktLuar);
        $sumber = $sisip
            ? 'garis luar ' . $namaMaster . ' + ' . count($luarBidang) . ' bidang sisipan'
            : 'garis luar ' . $namaMaster;
        $cadangan = cadanganBlok('garis-luar-' . str_replace(' ', '-', $kode), [
            'waktu' => date('c'),
            'blok' => $blok,
            'garis_luar' => $wktLuar,
            'bidang_sisip' => array_column($luarBidang, 'kode'),
            'bidang_perlu_dicek' => $perluDicek,
        ]);

        db_transaksi(static function () use ($blok, $baru, $sumber, $perluDicek): void {
            db_exec(
                'UPDATE blok
                    SET geom = ST_GeomFromText(?, 4326), batas_tersedia = 1, batas_perkiraan = 0,
                        sumber_perkiraan = ?, jumlah_bidang_estimasi = NULL, maks_jarak_estimasi_m = NULL
                  WHERE id = ?',
                [$baru, $sumber, (int) $blok['id']]
            );
            foreach ($perluDicek as $b) {
                db_exec(
                    'UPDATE bidang SET status = "perlu_verifikasi" WHERE kode = ? AND status = "aktif"',
                    [(string) $b['kode']]
                );
            }
        });

        audit(
            'ubah',
            'blok',
            (int) $blok['id'],
            'Batas ' . $kode . ' dipasang dari garis luar ' . BATAS_BLOK_LAPISAN . ' ' . $namaMaster . ' ('
                . ($sisip ? count($luarBidang) . ' bidang sisipan' : 'satu garis tanpa sisipan') . '); '
                . count($perluDicek) . ' bidang ditandai perlu_verifikasi. Cadangan: ' . $cadangan,
            ['batas_perkiraan' => (int) $blok['batas_perkiraan'], 'wkt' => $blok['wkt']],
            ['batas_tersedia' => 1, 'garis_luar' => $wktLuar]
        );

        $dipasang++;
        $ditandai += count($perluDicek);
        printf(
            "%s: batas dipasang (%s m2, %d bagian), %d bidang ditandai perlu_verifikasi\n",
            $kode,
            number_format(luasWkt($baru), 0, ',', '.'),
            (int) db_value('SELECT ST_NumGeometries(geom) FROM blok WHERE id = ?', [(int) $blok['id']]),
            count($perluDicek)
        );
    }

    return [
        'jenis' => 'garis-luar',
        'dipasang' => $dipasang,
        'perlu_dicek' => $ditandai,
        'kurang' => $kurang,
    ];
}

/**
 * Buat batas perkiraan baru untuk blok yang tidak punya garis luar: gabungan
 * Buat batas perkiraan baru untuk blok: gabungan seluruh bidang anggotanya,
 * lalu diselubungi sejauh $penyanggaM. Dengan $penyanggaM nol (mode --tepat)
 * batasnya persis mengikuti bentuk bidang anggotanya tanpa selubung.
 *
 * @param array<int, string> $daftarKode
 * @return array<string, mixed>
 */
function perkirakanBatasBlok(array $daftarKode, bool $terapkan = false, float $penyanggaM = BATAS_PENYANGGA_M): array
{
    $dipasang = 0;
    $kurang = [];

    foreach ($daftarKode as $kodeMinta) {
        $blok = blokCari((string) $kodeMinta);
        $kode = (string) $blok['kode'];
        $bidang = db_all(
            'SELECT d.kode, ST_AsText(d.geom) AS wkt FROM bidang d WHERE d.blok_id = ? ORDER BY d.kode',
            [(int) $blok['id']]
        );
        if (count($bidang) < 3) {
            $kurang[] = $kode . ': bidang anggota kurang untuk dibuat batas (' . count($bidang) . ')';
            continue;
        }

        $gabung = wktGabung(array_column($bidang, 'wkt'));
        $baru = $penyanggaM > 0
            ? wktKeMultipolygon(wktSelubung($gabung, $penyanggaM))
            : wktKeMultipolygon($gabung);
        $sumber = $penyanggaM > 0
            ? 'gabungan bidang anggota + penyangga ' . $penyanggaM . ' m'
            : 'gabungan bidang anggota';
        $bagian = (int) db_value('SELECT ST_NumGeometries(ST_GeomFromText(?, 4326))', [$baru]);
        $luar = db_all(
            'SELECT d.kode FROM bidang d WHERE d.blok_id = ?
             AND NOT ST_Contains(ST_GeomFromText(?, 4326), ST_Centroid(d.geom))',
            [(int) $blok['id'], $baru]
        );

        if (!$terapkan) {
            printf(
                "%s: %d bidang -> %s m2 dalam %d bagian, %d bidang tetap di luar\n",
                $kode,
                count($bidang),
                number_format(luasWkt($baru), 0, ',', '.'),
                $bagian,
                count($luar)
            );
            continue;
        }

        $cadangan = cadanganBlok('batas-kira-' . str_replace(' ', '-', $kode), [
            'waktu' => date('c'),
            'blok' => $blok,
            'penyangga_m' => $penyanggaM,
            'bidang' => array_column($bidang, 'kode'),
            'bidang_tetap_di_luar' => array_column($luar, 'kode'),
        ]);

        db_exec(
            'UPDATE blok
                SET geom = ST_GeomFromText(?, 4326), batas_tersedia = 0, batas_perkiraan = 1,
                    sumber_perkiraan = ?, jumlah_bidang_estimasi = ?, maks_jarak_estimasi_m = ?
              WHERE id = ?',
            [
                $baru,
                $sumber,
                count($bidang),
                (int) $penyanggaM,
                (int) $blok['id'],
            ]
        );

        audit(
            'ubah',
            'blok',
            (int) $blok['id'],
            'Batas ' . $kode . ' dibuat dari gabungan ' . count($bidang) . ' bidang anggota (' . $sumber . '), '
                . $bagian . ' bagian; ' . count($luar) . ' bidang tetap di luar. Cadangan: ' . $cadangan,
            ['wkt' => $blok['wkt']],
            ['wkt' => $baru]
        );

        $dipasang++;
        printf("%s: batas dipasang (%d bagian), %d bidang tetap di luar\n", $kode, $bagian, count($luar));
    }

    return ['jenis' => 'batas-kira', 'dipasang' => $dipasang, 'kurang' => $kurang];
}

/**
 * Pindahkan bidang milik satu blok yang pusatnya berada di luar batas bloknya
 * sendiri ke blok lain. Dipakai sesudah sebuah blok dipasang garis luar
 * tunggalnya: kelompok bidang yang ternyata milik blok tetangga ikut dipindah
 * supaya tidak menggantung di luar batas lamanya.
 *
 * Blok asal, blok tujuan dan daftar bidang (termasuk statusnya) dicadangkan ke
 * storage/ sebelum ada perubahan supaya pemindahan bisa dibalik satu per satu.
 */
function pindahkanBidangLuarBatas(string $kodeAsal, string $kodeTujuan, bool $terapkan = false): array
{
    $asal = blokCari($kodeAsal);
    $tujuan = blokCari($kodeTujuan);
    if ((int) $asal['id'] === (int) $tujuan['id']) {
        throw new RuntimeException('Blok asal dan blok tujuan tidak boleh sama.');
    }

    $luar = db_all(
        'SELECT id, kode, status FROM bidang
          WHERE blok_id = ?
            AND NOT ST_Contains(ST_GeomFromText(?, 4326), ST_Centroid(geom))
       ORDER BY kode',
        [(int) $asal['id'], (string) $asal['wkt']]
    );

    if ($luar === []) {
        printf("%s: tidak ada bidang di luar batasnya, %s tidak berubah\n", $asal['kode'], $tujuan['kode']);

        return ['jenis' => 'pindah-bidang', 'dipindah' => 0, 'tujuan' => $tujuan['kode']];
    }

    if (!$terapkan) {
        printf("%s: %d bidang di luar batasnya akan dipindah ke %s\n", $asal['kode'], count($luar), $tujuan['kode']);

        return [
            'jenis' => 'pindah-bidang',
            'dipindah' => 0,
            'tujuan' => $tujuan['kode'],
            'bidang' => array_column($luar, 'kode'),
        ];
    }

    $cadangan = cadanganBlok(
        'pindah-' . str_replace(' ', '-', $asal['kode']) . '-ke-' . str_replace(' ', '-', $tujuan['kode']),
        ['waktu' => date('c'), 'blok_asal' => $asal, 'blok_tujuan' => $tujuan, 'bidang' => $luar]
    );

    db_transaksi(static function () use ($luar, $tujuan): void {
        foreach ($luar as $b) {
            db_exec('UPDATE bidang SET blok_id = ? WHERE id = ?', [(int) $tujuan['id'], (int) $b['id']]);
        }
    });

    audit(
        'ubah',
        'blok',
        (int) $asal['id'],
        count($luar) . ' bidang ' . $asal['kode'] . ' yang berada di luar batasnya dipindahkan ke '
            . $tujuan['kode'] . '. Cadangan: ' . $cadangan,
        ['blok' => $asal['kode'], 'bidang' => array_column($luar, 'kode')],
        ['blok' => $tujuan['kode']]
    );

    printf("%s: %d bidang dipindahkan ke %s\n", $asal['kode'], count($luar), $tujuan['kode']);

    return ['jenis' => 'pindah-bidang', 'dipindah' => count($luar), 'tujuan' => $tujuan['kode']];
}

/**
 * Pindahkan daftar bidang tertentu ke sebuah blok, di luar posisi geometrisnya.
 * Dipakai ketika kepemilikan blok sudah diputuskan petugas lapangan: kode yang
 * tidak ditemukan atau sudah berada di blok tujuan dilaporkan, tidak dipaksa.
 *
 * Blok asal setiap bidang dicadangkan ke storage/ sebelum perubahan supaya
 * pemindahan bisa dibalik per bidang.
 */
function ubahBlokBidang(array $daftarKode, string $kodeBlok, bool $terapkan = false): array
{
    $tujuan = blokCari($kodeBlok);
    $kode = array_values(array_unique(array_map(static fn(string $k): string => strtoupper(trim($k)), $daftarKode)));
    if ($kode === []) {
        throw new RuntimeException('Sebutkan minimal satu kode bidang.');
    }

    $tanya = implode(',', array_fill(0, count($kode), '?'));
    $temu = db_all(
        'SELECT d.id, d.kode, d.blok_id, d.status, b.kode AS blok_lama
           FROM bidang d LEFT JOIN blok b ON b.id = d.blok_id
          WHERE d.kode IN (' . $tanya . ')
       ORDER BY d.kode',
        $kode
    );

    $tidak = array_values(array_diff($kode, array_column($temu, 'kode')));
    foreach ($tidak as $k) {
        $mirip = db_all("SELECT kode FROM bidang WHERE REPLACE(kode, '-', '') LIKE ? ORDER BY kode LIMIT 3", ['%' . preg_replace('/\D/', '', $k)]);
        echo '   ! ' . $k . ' tidak ada' . ($mirip === [] ? '' : ', mungkin maksudnya: ' . implode(', ', array_column($mirip, 'kode'))) . PHP_EOL;
    }

    $pindah = array_values(array_filter($temu, static fn(array $b): bool => (int) $b['blok_id'] !== (int) $tujuan['id']));
    if ($pindah === []) {
        printf("%d bidang yang disebutkan sudah milik %s\n", count($temu), $tujuan['kode']);

        return ['jenis' => 'ubah-blok', 'dipindah' => 0, 'tujuan' => $tujuan['kode'], 'tidak_dikenal' => $tidak];
    }

    if (!$terapkan) {
        printf("%d bidang akan dipindah ke %s\n", count($pindah), $tujuan['kode']);
        foreach ($pindah as $b) {
            printf("   - %-18s dari %s\n", $b['kode'], $b['blok_lama'] ?? 'tanpa blok');
        }

        return ['jenis' => 'ubah-blok', 'dipindah' => 0, 'tujuan' => $tujuan['kode'], 'tidak_dikenal' => $tidak];
    }

    $cadangan = cadanganBlok('ubah-blok-ke-' . str_replace(' ', '-', $tujuan['kode']), [
        'waktu' => date('c'),
        'blok_tujuan' => $tujuan,
        'bidang' => $pindah,
    ]);

    db_transaksi(static function () use ($pindah, $tujuan): void {
        foreach ($pindah as $b) {
            db_exec('UPDATE bidang SET blok_id = ? WHERE id = ?', [(int) $tujuan['id'], (int) $b['id']]);
        }
    });

    $perBlok = [];
    foreach ($pindah as $b) {
        $perBlok[$b['blok_lama'] ?? 'tanpa blok'][] = $b['kode'];
    }
    foreach ($perBlok as $asal => $daftar) {
        audit(
            'ubah',
            'bidang',
            implode(',', $daftar),
            count($daftar) . ' bidang dari ' . $asal . ' dipindahkan ke ' . $tujuan['kode'] . ' atas keputusan petugas. Cadangan: ' . $cadangan,
            ['blok' => $asal],
            ['blok' => $tujuan['kode']]
        );
    }

    printf("%d bidang dipindahkan ke %s\n", count($pindah), $tujuan['kode']);

    return [
        'jenis' => 'ubah-blok',
        'dipindah' => count($pindah),
        'tujuan' => $tujuan['kode'],
        'tidak_dikenal' => $tidak,
    ];
}

// ---------------------------------------------------------------------------
// 8b. Koreksi data yang terbukti menyimpang dari geometri
// ---------------------------------------------------------------------------

/** Bagian luas yang boleh menyimpang antara `luas_m2` dan luas gambar cincinnya. */
const LUAS_TOLERANSI = 0.05;

/**
 * Hitung ulang `bidang.luas_m2` dari cincin yang tersimpan di geom.
 *
 * Angka kolom ini datang dari luas gambar digitasi GeoJSON, bukan dari register
 * tanah — terbukti dari audit 20 Okt 2026: dari 4.045 bidang hidup hanya dua yang
 * menyimpang, dan keduanya simpangan kecil (0,02 m2 dan 0,27 m2). Jadi kolom yang
 * nol atau melenceng lebih dari $toleransi memang cacat digitasi, bukan data
 * pendaftaran yang harus dihormati.
 *
 * Hanya membaca geometri; yang ditulis hanya `luas_m2`.
 */
function hitungUlangLuasBidang(bool $terapkan = false, float $toleransi = LUAS_TOLERANSI): array
{
    $calon = [];
    foreach (db_all(
        "SELECT id, kode, status, luas_m2, ST_AsText(geom) AS wkt
           FROM bidang
          WHERE status NOT IN ('dihapus', 'duplikat') AND geom IS NOT NULL
       ORDER BY kode"
    ) as $b) {
        $gambar = round(luasWkt((string) $b['wkt']), 2);
        $lama = (float) $b['luas_m2'];
        // `luas_m2 = 0` selalu cacat: sebidang tanah yang tercatat punya cincin
        // pastilah luas gambar cincinnya bukan nol.
        if ($lama > 0 && abs($gambar - $lama) <= $toleransi * max($gambar, 1.0)) {
            continue;
        }
        $calon[] = ['id' => (int) $b['id'], 'kode' => (string) $b['kode'],
            'status' => (string) $b['status'], 'lama' => $lama, 'baru' => $gambar];
    }

    $hasil = ['jenis' => 'luas', 'terapkan' => $terapkan, 'toleransi' => $toleransi,
        'diperiksa' => (int) db_value("SELECT COUNT(*) FROM bidang
                                        WHERE status NOT IN ('dihapus', 'duplikat') AND geom IS NOT NULL"),
        'berbeda' => count($calon), 'diperbaiki' => 0];
    if (!$terapkan) {
        $hasil['contoh'] = array_map(static fn(array $c): string => sprintf(
            '%s: %s m2 -> %s m2 (%s)', $c['kode'], number_format($c['lama'], 2, ',', '.'),
            number_format($c['baru'], 2, ',', '.'), $c['status']
        ), $calon);

        return $hasil;
    }
    if ($calon === []) {
        return $hasil;
    }

    $cadangan = cadanganBlok('luas-dari-geometri', [
        'waktu' => date('c'), 'toleransi' => $toleransi, 'bidang' => $calon,
    ]);
    db_transaksi(static function () use ($calon): void {
        foreach ($calon as $c) {
            db_exec('UPDATE bidang SET luas_m2 = ? WHERE id = ?', [$c['baru'], $c['id']]);
        }
    });
    audit(
        'ubah',
        'bidang',
        'luas-dari-geometri',
        count($calon) . ' bidang disesuaikan luas_m2-nya dengan luas cincin di geom '
            . '(toleransi ' . $toleransi . ' bagian); luas_m2 impor GeoJSON bukan angka pendaftaran. '
            . 'Cadangan: ' . $cadangan,
        array_map(static fn(array $c): array => ['kode' => $c['kode'], 'luas_m2' => $c['lama']], $calon),
        array_map(static fn(array $c): array => ['kode' => $c['kode'], 'luas_m2' => $c['baru']], $calon)
    );

    return $hasil + ['diperbaiki' => count($calon), 'cadangan' => $cadangan];
}

/** Garis blok yang boleh dipakai mengoreksi perkiraan: garis ukur, bukan turunan anggota. */
const BLOK_KOREKSI_SUMBER = 'garis luar MASTER.dxf';
/** Anggota yang titik tengahnya jatuh di dalam garisnya sendiri, minimum, supaya garis itu dipercaya. */
const BLOK_KEPERCAYAAN_MIN = 0.9;

/**
 * Catatan yang menandai bidang yang bloknya dipasang MESIN — satu-satunya yang boleh
 * dipindah otomatis. Dulu hanya "Blok diperkirakan otomatis" saja; dua penanda lain
 * ditambahkan 20 Okt 2026 sesudah terukur bahwa `koreksi-blok` melaporkan
 * `akan_dipindah = 0` sementara 66 bidang punya garis ukur yang benar-benar menaungi
 * titik tengahnya: 37 di antaranya dipasang `dxf-persil` (catatan "Cincin MASTER.dxf
 * …") dan 5 oleh `kuning`. Sasaran yang lama masih tertahan sampai sekarang hanya
 * karena kalimat WHERE-nya tidak pernah menyebut catatan mereka.
 *
 * Bidang ber-catatan kosong sengaja TIDAK masuk: tanpa penanda, tidak bisa dibedakan
 * antara penugasan otomatis dan keputusan petugas yang tidak menulis catatan
 * (`pindah` dan `ubah-blok` hanya mengubah `blok_id`), dan keputusan petugas tidak
 * pernah ditimpa oleh alat otomatis.
 *
 * @return array<string, string> pola LIKE => keterangan untuk laporan
 */
function catatanBlokMesin(): array
{
    return [
        'Blok diperkirakan otomatis%' => 'blok ditaksir `kira`',
        'Cincin MASTER.dxf lapis %' => 'cincin dipasang `dxf-persil`',
        'Garis kuning (ACI 2)%' => 'bidang dipasang `kuning`',
    ];
}

/**
 * Satu klausa `catatan LIKE ... ` untuk semua penanda mesin, ditambah penolakan terhadap
 * baris yang sudah dikoreksi perintah ini — kalau tidak, ronde kedua akan memindah bidang
 * yang sama ke blok lain lagi.
 */
function sqlCatatanBlokMesin(string $kolom = 'catatan'): string
{
    $pola = [];
    foreach (array_keys(catatanBlokMesin()) as $p) {
        $pola[] = $kolom . " LIKE '" . str_replace("'", "''", $p) . "'";
    }

    return '(' . implode(' OR ', $pola) . ") AND " . $kolom . " NOT LIKE '%Blok dikoreksi%'";
}

/**
 * Pindahkan bidang yang bloknya masih perkiraan otomatis ke blok yang garis ukurnya
 * benar-benar menaungi titik tengah bidang itu.
 *
 * Syaratnya sengaja sempit (20 Okt 2026):
 *   - status masih `perlu_verifikasi` dan catatannya salah satu penanda mesin dari
 *     `catatanBlokMesin()` — perkiraan `kira`, cincin `dxf-persil`, atau bidang `kuning`.
 *     Bidang yang bloknya hasil keputusan petugas (`pindah`, `ubah-blok`), turunan digitasi
 *     sumber, atau yang sudah pernah dikoreksi perintah ini tidak disentuh, karena
 *     pemindahan otomatis akan membatalkan bukti yang lebih kuat;
 *   - titik tengahnya TIDAK jatuh di dalam garis blok yang tercatat sekarang;
 *   - titik tengahnya jatuh di dalam TEPAT SATU garis blok, dan garis itu bergambar
 *     ukur MASTER.dxf (bukan `gabungan bidang anggota`, yang bentuknya justru turunan
 *     anggotanya sehingga memindahkan bidang ke dalamnya memindahkan kesalahan);
 *   - blok tujuan itu sendiri dipercaya: >= BLOK_KEPERCAYAAN_MIN anggotanya yang hidup
 *     punya titik tengah di dalam garisnya sendiri. BLOK 23 (79%) dan BLOK 9 (79%)
 *     gagal syarat ini, jadi tidak ada bidang yang dipindahkan ke keduanya.
 *
 * Bidang yang titik tengahnya tidak jatuh di dalam garis mana pun, atau jatuh di dua
 * garis sekaligus, tetap menjadi daftar kerja petugas — tidak ditebak.
 */
function koreksiBlokPerkiraan(bool $terapkan = false, float $minimumKepercayaan = BLOK_KEPERCAYAAN_MIN): array
{
    $blok = [];
    foreach (db_all('SELECT id, kode, sumber_perkiraan, ST_AsText(geom) AS wkt FROM blok WHERE geom IS NOT NULL') as $bl) {
        $bagian = wktBagian((string) $bl['wkt']);
        if ($bagian === []) {
            continue;
        }
        $blok[(int) $bl['id']] = ['kode' => (string) $bl['kode'],
            'sumber' => (string) ($bl['sumber_perkiraan'] ?? ''), 'bagian' => $bagian]
            + wktWilayah((string) $bl['wkt']);
    }

    // kepercayaan tiap garis: berapa persen anggotanya sendiri yang benar-benar dinaungi
    $perBlok = [];
    foreach ($blok as $id => $bl) {
        $anggota = 0;
        $menaungi = 0;
        foreach (db_all('SELECT id, kode, status, luas_m2, ST_X(ST_Centroid(geom)) AS lon, ST_Y(ST_Centroid(geom)) AS lat
                          FROM bidang WHERE blok_id = ? AND status NOT IN (\'dihapus\', \'duplikat\')', [$id]) as $b) {
            $anggota++;
            if (titikDiDalamBagian([(float) $b['lon'], (float) $b['lat']], $bl['bagian'])) {
                $menaungi++;
            }
        }
        $perBlok[$id] = ['anggota' => $anggota, 'menaungi' => $menaungi,
            'kepercayaan' => $anggota === 0 ? 0.0 : $menaungi / $anggota];
    }
    $terpilih = static fn(int $id): bool => $blok[$id]['sumber'] === BLOK_KOREKSI_SUMBER
        && $perBlok[$id]['kepercayaan'] >= $minimumKepercayaan;

    $pindah = [];
    $ditahan = [];
    $sasaran = sqlCatatanBlokMesin('b.catatan');
    foreach (db_all(
        "SELECT b.id, b.kode, b.blok_id, bl.kode AS blok_asal,
                ST_X(ST_Centroid(b.geom)) AS lon, ST_Y(ST_Centroid(b.geom)) AS lat
           FROM bidang b LEFT JOIN blok bl ON bl.id = b.blok_id
          WHERE b.status = 'perlu_verifikasi' AND b.geom IS NOT NULL
            AND $sasaran
       ORDER BY b.kode"
    ) as $b) {
        $pusat = [(float) $b['lon'], (float) $b['lat']];
        $menaungi = [];
        foreach ($blok as $id => $bl) {
            if ($pusat[0] < $bl['w'] || $pusat[0] > $bl['e'] || $pusat[1] < $bl['s'] || $pusat[1] > $bl['n']) {
                continue;
            }
            if (titikDiDalamBagian($pusat, $bl['bagian'])) {
                $menaungi[] = $id;
            }
        }
        $punya = in_array((int) $b['blok_id'], $menaungi, true);
        if ($punya || $menaungi === []) {
            continue;                       // garis bloknya sendiri masih menaungi, atau tidak ada sama sekali
        }
        if (count($menaungi) > 1) {
            $ditahan[] = ['kode' => (string) $b['kode'], 'alasan' => 'titik tengahnya di dalam '
                . count($menaungi) . ' garis blok sekaligus: '
                . implode(' + ', array_map(static fn(int $i): string => $blok[$i]['kode'], $menaungi))];
            continue;
        }
        $tujuan = $menaungi[0];
        if (!$terpilih($tujuan)) {
            $ditahan[] = ['kode' => (string) $b['kode'], 'alasan' => 'garis ' . $blok[$tujuan]['kode']
                . ' tidak bisa dipakai (' . $blok[$tujuan]['sumber'] . ', menaungi '
                . round(100 * $perBlok[$tujuan]['kepercayaan']) . '% anggotanya sendiri)'];
            continue;
        }
        $pindah[] = ['id' => (int) $b['id'], 'kode' => (string) $b['kode'],
            'dari' => (string) $b['blok_asal'], 'ke' => (string) $blok[$tujuan]['kode'],
            'lon' => (float) $b['lon'], 'lat' => (float) $b['lat'],
            'kepercayaan' => round(100 * $perBlok[$tujuan]['kepercayaan'])];
    }

    // Gerbang kedua, dan yang satu ini milik basis data sendiri: `titikDiDalamBagian()` membaca
    // tiap cincin sebagai bagian padat sehingga lebih murah hati daripada `ST_Contains`. Ronde
    // 20 Okt 10:27 memindah `HM-199D K3` ke BLOK 26 yang menurut MariaDB berjarak 482 m dari
    // titik tengahnya — tanpa gerbang ini kesalahan yang sama bisa ditulis ulang.
    $teruji = [];
    foreach ($pindah as $p) {
        $titik = "'" . sprintf('POINT(%.10F %.10F)', $p['lon'], $p['lat']) . "'";
        $teruji[(string) $p['kode']] = array_map(
            static fn(array $r): string => (string) $r['kode'],
            db_all('SELECT kode FROM blok WHERE geom IS NOT NULL
                      AND ST_Contains(geom, ST_PointFromText(' . $titik . ', 4326))')
        );
    }
    $pindah = array_values(array_filter($pindah, static function (array $p) use ($teruji, &$ditahan): bool {
        if (in_array($p['ke'], $teruji[(string) $p['kode']], true)) {
            return true;
        }
        $ditahan[] = ['kode' => (string) $p['kode'], 'alasan' => 'garis ' . $p['ke']
            . ' menaungi menurut wktBagian() tapi tidak menurut ST_Contains MariaDB'
            . ($teruji[(string) $p['kode']] === [] ? ', dan tidak ada garis lain yang menaunginya'
                : '; yang menaungi: ' . implode(' + ', $teruji[(string) $p['kode']]))];

        return false;
    }));

    $hasil = ['jenis' => 'koreksi-blok', 'terapkan' => $terapkan, 'bidang_diperiksa' =>
        (int) db_value('SELECT COUNT(*) FROM bidang WHERE status = \'perlu_verifikasi\'
                          AND geom IS NOT NULL AND ' . sqlCatatanBlokMesin()),
        'akan_dipindah' => count($pindah), 'ditahan' => count($ditahan), 'dipindah' => 0];
    if (!$terapkan) {
        $hasil['contoh'] = array_map(static fn(array $p): string => $p['kode'] . ': '
            . $p['dari'] . ' -> ' . $p['ke'], $pindah);
        $hasil['galat'] = array_map(static fn(array $d): string => $d['kode'] . ' — ' . $d['alasan'],
            array_slice($ditahan, 0, 20));

        return $hasil;
    }
    if ($pindah === []) {
        return $hasil;
    }

    $cadangan = cadanganBlok('koreksi-blok-perkiraan', [
        'waktu' => date('c'),
        'aturan' => 'perlu_verifikasi + catatan penanda mesin (' . implode(', ', array_keys(catatanBlokMesin()))
            . '), titik tengah di luar garis bloknya sendiri dan di dalam tepat satu garis ukur MASTER.dxf'
            . ' yang menaungi >= ' . round(100 * $minimumKepercayaan) . '% anggotanya sendiri',
        'bidang' => $pindah,
    ]);
    $hari = date('Y-m-d');
    $ubah = db()->prepare(
        'UPDATE bidang
           SET blok_id = (SELECT id FROM blok WHERE kode = ?),
               catatan = CONCAT(catatan, " Blok dikoreksi ", ?,
                                ": titik tengahnya jatuh di dalam garis ukur ", ?,
                                " yang menaungi ", ?, "% anggotanya sendiri, sedangkan garis ", ?,
                                " tidak menaunginya lagi.")
         WHERE id = ?'
    );
    db_transaksi(static function () use ($ubah, $pindah, $hari): void {
        foreach ($pindah as $p) {
            $ubah->execute([(string) $p['ke'], $hari, (string) $p['ke'], (int) $p['kepercayaan'],
                (string) $p['dari'], (int) $p['id']]);
        }
    });
    audit(
        'ubah',
        'bidang',
        'koreksi-blok-perkiraan',
        count($pindah) . ' bidang berpindah blok: perkiraan otomatis diganti blok yang garis ukurnya '
            . 'menaungi titik tengahnya (' . implode(', ', array_map(static fn(array $p): string =>
                $p['dari'] . ' -> ' . $p['ke'], array_slice($pindah, 0, 6))) . ' dan '
            . count($pindah) . ' bidang lainnya). ' . count($ditahan) . ' bidang ditahan. Cadangan: ' . $cadangan,
        array_map(static fn(array $p): array => ['kode' => $p['kode'], 'blok' => $p['dari']], $pindah),
        array_map(static fn(array $p): array => ['kode' => $p['kode'], 'blok' => $p['ke']], $pindah)
    );

    // `array_merge`, bukan `+`: $hasil sudah membawa 'dipindah' => 0 dari ringkasan di atas,
    // dan operator `+` tidak menimpa kunci yang sudah ada — terukur 20 Okt 2026 perintah ini
    // memindah 57 bidang lalu melaporkan `dipindah=0`.
    return array_merge($hasil, ['dipindah' => count($pindah), 'cadangan' => $cadangan]);
}

// ---------------------------------------------------------------------------
// 9. Batas blok berhenti di tepi jalan
// ---------------------------------------------------------------------------

/**
 * Lebar setengah jalan per kelas `highway` OpenStreetMap, dalam meter. OSM
 * hanya menyimpan sumbu jalan, jadi angka inilah yang menentukan seberapa jauh
 * tepi jalan berada dari garis yang dipakai memotong batas blok.
 */
const JALAN_SETENGAH_M = [
    'primary' => 5.5,
    'secondary' => 5.0,
    'tertiary' => 4.0,
    'unclassified' => 3.0,
    'residential' => 3.0,
    'living_street' => 2.75,
    'service' => 2.5,
    'track' => 1.5,
    'pedestrian' => 1.5,
    'footway' => 0.8,
    'path' => 0.8,
    'cycleway' => 0.8,
];

/** Serpihan hasil potongan di bawah luas ini dibuang; terlalu kecil untuk jadi bagian blok. */
const JALAN_SERPIHAN_M2 = 500.0;

/** Batas bujur sangkar sebuah WKT dalam derajat: s, n, w, e. */
function wktWilayah(string $wkt): array
{
    preg_match_all('/(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)/', $wkt, $m);
    $lon = array_map('floatval', $m[1]);
    $lat = array_map('floatval', $m[2]);
    if ($lat === []) {
        throw new RuntimeException('WKT tidak punya koordinat untuk dicari wilayahnya.');
    }

    return ['s' => min($lat), 'n' => max($lat), 'w' => min($lon), 'e' => max($lon)];
}

/**
 * Normal hasil operasi spatial supaya bisa disimpan ke kolom MULTIPOLYGON.
 * MariaDB 10.4 sering mengembalikan GEOMETRYCOLLECTION berisi campuran poligon
 * dan garis, sedangkan ST_CollectionExtract tidak ada di versi ini; bagian yang
 * bukan poligon dipisahkan satu per satu lewat ST_GeometryN.
 */
function wktAmbilPoligon(string $wkt): string
{
    $jenis = (string) db_value('SELECT GeometryType(ST_GeomFromText(?, 4326))', [$wkt]);
    if ($jenis === 'POLYGON' || $jenis === 'MULTIPOLYGON') {
        return wktKeMultipolygon($wkt);
    }

    $jumlah = (int) db_value('SELECT ST_NumGeometries(ST_GeomFromText(?, 4326))', [$wkt]);
    $kumpul = [];
    for ($i = 1; $i <= $jumlah; $i++) {
        $tanya = 'ST_GeometryN(ST_GeomFromText(?, 4326), ?)';
        $sub = (string) db_value('SELECT GeometryType(' . $tanya . ')', [$wkt, $i]);
        if ($sub === 'POLYGON' || $sub === 'MULTIPOLYGON') {
            $kumpul[] = (string) db_value('SELECT ST_AsText(' . $tanya . ')', [$wkt, $i]);
        } elseif (str_starts_with($sub, 'GEOMETRYCOLLECTION')) {
            // koleksi bersarang: gali dulu supaya isinya tidak hilang
            $kumpul[] = wktAmbilPoligon((string) db_value('SELECT ST_AsText(' . $tanya . ')', [$wkt, $i]));
        }
    }
    if ($kumpul === []) {
        throw new RuntimeException('Hasil operasi geometri tidak berisi poligon (' . $jenis . ').');
    }

    // penggabungannya sendiri bisa menghasilkan koleksi baru: normal lagi
    return wktAmbilPoligon(wktGabung($kumpul));
}

/** Kurangi poligon $a dengan $b; hasil dinormalisasi ke MULTIPOLYGON. */
function wktKurangi(string $a, string $b): string
{
    $hasil = db_value(
        'SELECT ST_AsText(ST_Difference(ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326)))',
        [$a, $b]
    );
    if (!is_string($hasil) || $hasil === '') {
        throw new RuntimeException('Pengurangan geometri gagal dihitung.');
    }

    return wktAmbilPoligon($hasil);
}

/**
 * Ruas jalan OpenStreetMap yang melintas di sekitar sebuah WKT blok.
 *
 * @return array<int, array{kelas: string, wkt: string, setengah_m: float}>
 */
function jalanDekat(string $berkas, string $wktBlok, float $jelajahM = 60.0): array
{
    $w = wktWilayah($wktBlok);
    $derajat = $jelajahM / 111320.0;
    $data = json_decode((string) file_get_contents($berkas), true);
    if (!is_array($data)) {
        throw new RuntimeException('Berkas jalan OSM tidak terbaca: ' . $berkas);
    }

    $garis = [];
    foreach ((array) ($data['elements'] ?? []) as $e) {
        if (($e['type'] ?? '') !== 'way') {
            continue;
        }
        $kelas = (string) ($e['tags']['highway'] ?? '');
        if (!isset(JALAN_SETENGAH_M[$kelas])) {
            continue;
        }
        $titik = (array) ($e['geometry'] ?? []);
        if (count($titik) < 2) {
            continue;
        }

        $dekat = false;
        foreach ($titik as $p) {
            if ($p['lat'] >= $w['s'] - $derajat && $p['lat'] <= $w['n'] + $derajat
                && $p['lon'] >= $w['w'] - $derajat && $p['lon'] <= $w['e'] + $derajat) {
                $dekat = true;
                break;
            }
        }
        if (!$dekat) {
            continue;
        }

        $poin = [];
        foreach ($titik as $p) {
            $poin[] = sprintf('%.7f %.7f', (float) $p['lon'], (float) $p['lat']);
        }
        $garis[] = [
            'kelas' => $kelas,
            'wkt' => 'LINESTRING(' . implode(', ', $poin) . ')',
            'setengah_m' => (float) JALAN_SETENGAH_M[$kelas],
        ];
    }

    return $garis;
}

/** Koridor jalan penuh (tepi ke tepi) di sekitar sebuah blok. */
function koridorJalan(string $berkas, string $wktBlok): array
{
    $garis = jalanDekat($berkas, $wktBlok);
    if ($garis === []) {
        return ['wkt' => '', 'ruas' => 0, 'kelas' => []];
    }

    $pita = array_map(
        static fn(array $g): string => (string) db_value(
            'SELECT ST_AsText(ST_Buffer(ST_GeomFromText(?, 4326), ?))',
            [$g['wkt'], $g['setengah_m'] / 111320.0]
        ),
        $garis
    );

    $kelas = [];
    foreach ($garis as $g) {
        $kelas[$g['kelas']] = ($kelas[$g['kelas']] ?? 0) + 1;
    }

    return ['wkt' => wktAmbilPoligon(wktGabung($pita)), 'ruas' => count($garis), 'kelas' => $kelas];
}

/** Buang bagian yang lebih kecil dari $minimumM2; sisanya digabung kembali. */
function buangSerpihan(string $wkt, float $minimumM2): array
{
    $jumlah = (int) db_value('SELECT ST_NumGeometries(ST_GeomFromText(?, 4326))', [$wkt]);
    $tinggal = [];
    $dibuang = 0;
    for ($i = 1; $i <= $jumlah; $i++) {
        $s = (string) db_value('SELECT ST_AsText(ST_GeometryN(ST_GeomFromText(?, 4326), ?))', [$wkt, $i]);
        if (luasWkt($s) >= $minimumM2) {
            $tinggal[] = $s;
        } else {
            $dibuang++;
        }
    }
    if ($tinggal === []) {
        throw new RuntimeException('Semua bagian blok lebih kecil dari ' . $minimumM2 . ' m2; batas tidak diubah.');
    }

    return ['wkt' => wktKeMultipolygon(wktGabung($tinggal)), 'dibuang' => $dibuang];
}

/**
 * Rapikan batas blok agar berhenti di tepi jalan.
 *
 * Koridor jalan OSM dikurangkan dari batas blok sehingga permukaan jalan tidak
 * lagi dihitung sebagai milik blok. Hanya koridor yang benar-benar masuk ke
 * dalam blok yang memotong; jalan di luar blok tidak mengubah apa pun.
 * Serpihan hasil potongan di bawah JALAN_SERPIHAN_M2 dibuang.
 *
 * @param array<int, string> $daftarKode
 * @return array<string, mixed>
 */
function rapikanBatasJalan(array $daftarKode, bool $terapkan = false, float $serpihanM2 = JALAN_SERPIHAN_M2): array
{
    $berkas = SIP_ROOT . '/files/master peta/jalan_osm.json';
    if (!is_file($berkas)) {
        throw new RuntimeException(
            'Jalan OpenStreetMap belum ada di files/master peta/jalan_osm.json. Unduh dulu dari Overpass.'
        );
    }

    $dipasang = 0;
    $kurang = [];

    foreach ($daftarKode as $kodeMinta) {
        $blok = blokCari((string) $kodeMinta);
        $kode = (string) $blok['kode'];
        $lama = (string) $blok['wkt'];

        $koridor = koridorJalan($berkas, $lama);
        if ($koridor['ruas'] === 0) {
            $kurang[] = $kode . ': tidak ada ruas jalan OSM di sekitarnya';
            continue;
        }

        $sebelum = (int) db_value('SELECT ST_NumGeometries(ST_GeomFromText(?, 4326))', [$lama]);
        $diLuarLama = (int) db_value(
            'SELECT COUNT(*) FROM bidang WHERE blok_id = ?
              AND NOT ST_Contains(ST_GeomFromText(?, 4326), ST_Centroid(geom))',
            [(int) $blok['id'], $lama]
        );

        $baru = wktKurangi($lama, $koridor['wkt']);
        $rapi = buangSerpihan($baru, $serpihanM2);
        $baru = $rapi['wkt'];

        $sesudah = (int) db_value('SELECT ST_NumGeometries(ST_GeomFromText(?, 4326))', [$baru]);
        $terpotong = luasWkt($lama) - luasWkt($baru);
        $luar = db_all(
            'SELECT d.kode FROM bidang d WHERE d.blok_id = ?
              AND NOT ST_Contains(ST_GeomFromText(?, 4326), ST_Centroid(d.geom))
             ORDER BY d.kode',
            [(int) $blok['id'], $baru]
        );

        printf(
            "%s: %d ruas jalan, %s m2 terpotong, bagian %d -> %d (serpihan dibuang %d), %d bidang di luar batas (sebelumnya %d)\n",
            $kode,
            $koridor['ruas'],
            number_format($terpotong, 0, ',', '.'),
            $sebelum,
            $sesudah,
            $rapi['dibuang'],
            count($luar),
            $diLuarLama
        );

        if (!$terapkan) {
            continue;
        }

        $sumber = trim((string) ($blok['sumber_perkiraan'] ?? ''));
        $sumber = ($sumber === '' ? 'batas blok' : $sumber) . ' + tepi jalan OSM';

        $cadangan = cadanganBlok('rapikan-jalan-' . str_replace(' ', '-', $kode), [
            'waktu' => date('c'),
            'blok' => $blok,
            'ruas_jalan' => $koridor['ruas'],
            'kelas_jalan' => $koridor['kelas'],
            'terpotong_m2' => round($terpotong, 1),
            'bidang_di_luar' => array_column($luar, 'kode'),
        ]);

        db_exec(
            'UPDATE blok
                SET geom = ST_GeomFromText(?, 4326), sumber_perkiraan = ?
              WHERE id = ?',
            [$baru, substr($sumber, 0, 60), (int) $blok['id']]
        );

        audit(
            'ubah',
            'blok',
            (int) $blok['id'],
            'Batas ' . $kode . ' dirapikan ke tepi jalan: ' . $koridor['ruas'] . ' ruas OSM dikurangkan, '
                . number_format($terpotong, 0, ',', '.') . ' m2 terpotong, '
                . count($luar) . ' bidang berada di luar batas baru. Cadangan: ' . $cadangan,
            ['wkt' => $lama, 'bagian' => $sebelum],
            ['bagian' => $sesudah, 'sumber' => $sumber]
        );

        $dipasang++;
    }

    return [
        'jenis' => 'rapikan-jalan',
        'dipasang' => $dipasang,
        'kurang' => $kurang,
        'terapkan' => $terapkan,
    ];
}

// ---------------------------------------------------------------------------
// 10. Bidang kembar
// ---------------------------------------------------------------------------

/**
 * Pasangan bidang hidup yang poligonnya identik secara topologi.
 *
 * Pembandingnya ST_Equals, bukan sidik teks WKT. Kembaran di berkas GeoJSON sumber
 * ada yang menyimpan cincin yang sama dengan titik awal atau arah putaran berbeda;
 * teksnya beda tapi bentuknya satu bidang. Diukur 19 Okt 2026 atas 3.700 bidang
 * hidup: 43 grup kembar, 40 di antaranya lolos dari MD5(ST_AsText(geom))
 * (_lama/scratch/kembaran-stequals.php).
 *
 * MBRIntersects dipakai sebagai prasyarat murah sebelum ST_Equals. Indeks
 * spasial `b` sengaja diabaikan: MariaDB 10.4 melempar ER_READ_ONLY_TRANSACTION
 * (1207) begitu indeks itu dipakai di join self (MDEV-26123). Tanpa indeks
 * pemeriksaan atas 4.397 bidang hidup selesai ±10,8 detik (dengan indeks
 * ±10,0 detik), dan hasilnya sama.
 *
 * `$hanyaHidup` membatasi pemeriksaan pada bidang yang belum berstatus duplikat;
 * matikan untuk menghitung seluruh kelompok kembar termasuk yang sudah ditandai.
 *
 * @return list<array{ai:int, bi:int}>
 */
function pasanganKembarGeometri(bool $hanyaHidup = true): array
{
    $status = $hanyaHidup
        ? 'a.status NOT IN ("dihapus", "duplikat") AND b.status NOT IN ("dihapus", "duplikat")'
        : 'a.status <> "dihapus" AND b.status <> "dihapus"';

    return array_map(
        static fn(array $p): array => ['ai' => (int) $p['ai'], 'bi' => (int) $p['bi']],
        db_all(
            "SELECT a.id AS ai, b.id AS bi
               FROM bidang a
               JOIN bidang b IGNORE INDEX (spn_bidang_geom)
                 ON b.id > a.id AND MBRIntersects(a.geom, b.geom)
              WHERE $status
                AND ST_Equals(a.geom, b.geom)
              ORDER BY a.id, b.id"
        )
    );
}

/**
 * Rangkaian pasangan menjadi grup: A kembar dengan B, B kembar dengan C = satu
 * grup. `$bidang` peta `id => baris`; yang dikembalikan daftar grup berisi baris,
 * hanya grup beranggota lebih dari satu.
 *
 * @param list<array{ai:int, bi:int}> $pasangan
 * @param array<int, array<string, mixed>> $bidang
 * @return list<list<array<string, mixed>>>
 */
function grupkanPasangan(array $pasangan, array $bidang): array
{
    $induk = [];
    foreach (array_keys($bidang) as $id) {
        $induk[(int) $id] = (int) $id;
    }
    $akar = static function (int $x) use (&$akar, &$induk): int {
        while ($induk[$x] !== $x) {
            $x = $induk[$x] = $induk[$induk[$x]];
        }
        return $x;
    };
    foreach ($pasangan as $p) {
        $a = $akar((int) $p['ai']);
        $b = $akar((int) $p['bi']);
        if ($a !== $b) {
            $induk[$b] = $a;
        }
    }

    $grup = [];
    foreach ($bidang as $id => $baris) {
        $grup[$akar((int) $id)][] = $baris;
    }
    $grup = array_values(array_filter($grup, static fn(array $g): bool => count($g) > 1));
    foreach ($grup as &$g) {
        usort($g, static fn(array $a, array $x): int => strcmp((string) $a['kode'], (string) $x['kode']));
    }
    return $grup;
}

/**
 * Grupkan bidang yang poligonnya persis sama, sisakan satu, tandai sisanya
 * sebagai duplikat.
 *
 * Kembar ini sudah ada di berkas GeoJSON sumber, bukan hasil impor yang berjalan
 * dua kali (dibuktikan _lama/scratch/sidik-kembar.php). GROUP BY atas teks WKT
 * tidak dipakai karena dipotong ke max_sort_length 1024 bait, dan sidik teks juga
 * tidak: lihat pasanganKembarGeometri().
 *
 * Yang disisakan per grup: yang berstatus aktif, lalu yang datanya paling
 * lengkap, lalu id terkecil. Blok, nib dan luas pasangan yang ditandai tidak
 * disentuh — hanya status dan catatan — supaya keputusan ini bisa dibalik dari
 * berkas cadangan.
 *
 * Grup yang anggotanya tercatat di blok berlainan dilewati oleh aturan otomatis:
 * menyisakan satu anggotanya berarti memindahkan bidang itu ke blok pasangannya,
 * dan itu keputusan petugas. Petugas boleh memutuskannya tanpa memindahkan apa pun
 * dengan menyebut kode salinan yang dibuang di `$buang` — grup itu baru terbuka kalau
 * persis satu anggota yang tersisa tetap hidup.
 *
 * @param list<string> $buang kode bidang yang dinyatakan petugas sebagai salinan ganda
 * @return array<string, mixed>
 */
function tandaiBidangDuplikat(bool $terapkan = false, array $buang = []): array
{
    $baris = db_all(
        'SELECT b.id, b.kode, b.tipe, b.status, b.nib, b.luas_m2, b.catatan, b.blok_id,
                b.dusun_id, b.jenis_penggunaan_id, bl.kode AS blok
           FROM bidang b LEFT JOIN blok bl ON bl.id = b.blok_id
          WHERE b.status NOT IN ("dihapus", "duplikat")
          ORDER BY b.kode'
    );

    $menurutId = [];
    foreach ($baris as $b) {
        $menurutId[(int) $b['id']] = $b;
    }
    $pasangan = pasanganKembarGeometri();
    $grup = grupkanPasangan($pasangan, $menurutId);

    $lengkap = static fn(array $b): int => ($b['nib'] !== null && $b['nib'] !== '' ? 8 : 0)
        + ($b['luas_m2'] !== null ? 4 : 0)
        + ($b['blok_id'] !== null ? 2 : 0)
        + ($b['dusun_id'] !== null && $b['jenis_penggunaan_id'] !== null ? 1 : 0);

    $korban = [];
    $silang = [];
    $dipakai = [];
    $tandai = static function (array $b, array $hidup, int $sebanyak, bool $dipilihPetugas) use (&$korban): void {
        $korban[] = [
            'id' => (int) $b['id'],
            'kode' => (string) $b['kode'],
            'tipe' => (string) $b['tipe'],
            'status' => (string) $b['status'],
            'catatan' => (string) ($b['catatan'] ?? ''),
            'blok' => (string) ($b['blok'] ?? 'tanpa blok'),
            'luas' => $b['luas_m2'] === null ? null : (float) $b['luas_m2'],
            'hidup' => (string) $hidup['kode'],
            'hidup_id' => (int) $hidup['id'],
            'sebanyak' => $sebanyak,
            'dipilih' => $dipilihPetugas,
        ];
    };

    foreach ($grup as $anggota) {
        $kodenya = array_map(static fn(array $b): string => strtoupper((string) $b['kode']), $anggota);
        $dinamai = array_values(array_intersect($kodenya, array_map('strtoupper', $buang)));
        $dipakai = array_merge($dipakai, $dinamai);
        $bedaBlok = count(array_unique(array_column($anggota, 'blok_id'))) > 1;

        if ($bedaBlok) {
            // hanya terbuka kalau petugas menyebut salinan yang dibuang dan persis satu
            // anggota tetap hidup; selain itu tetap dilaporkan sebagai DILEWATI
            if ($dinamai === [] || count($anggota) - count($dinamai) !== 1) {
                $silang[] = $anggota;
                continue;
            }
            $hidup = null;
            $dibuang = [];
            foreach ($anggota as $b) {
                if (in_array(strtoupper((string) $b['kode']), $dinamai, true)) {
                    $dibuang[] = $b;
                } else {
                    $hidup = $b;
                }
            }
            foreach ($dibuang as $b) {
                $tandai($b, (array) $hidup, count($anggota), true);
            }
            continue;
        }

        usort($anggota, static fn(array $a, array $x): int =>
            [$a['status'] !== 'aktif', -$lengkap($a), (int) $a['id']]
                <=> [$x['status'] !== 'aktif', -$lengkap($x), (int) $x['id']]);
        $hidup = $anggota[0];
        foreach (array_slice($anggota, 1) as $b) {
            $tandai($b, $hidup, count($anggota), false);
        }
    }

    $takDikenal = array_values(array_diff(array_map('strtoupper', $buang), $dipakai));

    $luasGanda = 0.0;
    foreach ($korban as $b) {
        $luasGanda += (float) $b['luas'];
    }
    $perBlok = [];
    foreach ($korban as $b) {
        $perBlok[$b['blok']][] = $b['kode'];
    }
    ksort($perBlok, SORT_NATURAL);

    $olehPetugas = array_values(array_filter($korban, static fn(array $b): bool => $b['dipilih']));

    printf("%d bidang hidup diperiksa, %d pasangan geometri identik\n",
        count($menurutId), count($pasangan));
    printf("%d grup geometri kembar, %d bidang akan ditandai duplikat\n",
        count($grup) - count($silang), count($korban));
    if ($olehPetugas !== []) {
        printf("   %d di antaranya grup beda blok yang diputuskan petugas: %s\n",
            count($olehPetugas), implode(', ', array_column($olehPetugas, 'kode')));
    }
    if ($takDikenal !== []) {
        printf("   PERINGATAN: kode ini disebut tapi tidak ada di grup kembar mana pun: %s\n",
            implode(', ', $takDikenal));
    }
    printf("luas yang tercatat ganda: %s m2\n", number_format($luasGanda, 0, ',', '.'));
    foreach ($perBlok as $blok => $daftar) {
        printf("   %-12s %d\n", $blok, count($daftar));
    }
    echo "\n" . str_pad('KODE', 20) . str_pad('STATUS KINI', 20) . str_pad('DIBIARKAN HIDUP', 20) . "BLOK\n";
    foreach (array_slice($korban, 0, 15) as $b) {
        echo '   ' . str_pad($b['kode'], 20) . str_pad($b['status'], 20)
            . str_pad($b['hidup'], 20) . $b['blok']
            . ($b['dipilih'] ? '  (dibuang atas pilihan petugas)' : '') . "\n";
    }
    if (count($korban) > 15) {
        echo '   ... dan ' . (count($korban) - 15) . " bidang lainnya\n";
    }

    if ($silang !== []) {
        echo "\nDILEWATI, anggotanya tercatat di blok berlainan (" . count($silang) . " grup):\n";
        foreach ($silang as $g) {
            $tulis = [];
            foreach ($g as $b) {
                $tulis[] = $b['kode'] . ' [' . ($b['blok'] ?? 'tanpa blok') . ', ' . $b['status'] . ']';
            }
            echo '   ' . implode(' kembar dengan ', $tulis) . "\n";
        }
        echo "   Pilih dulu bidang mana yang benar-benar ada; menandai salah satu dari grup ini\n"
           . "   memindahkan bidang itu ke blok pasangannya. Kalau salinan yang mana sudah\n"
           . "   dipastikan, sebut kodenya supaya ikut ditandai tanpa memindah apa pun:\n"
           . "     php tools/impor.php duplikat <KODE-SALINAN-YANG-DIBUANG> ... [--terapkan]\n";
    }

    if (!$terapkan) {
        return [
            'jenis' => 'duplikat',
            'dipiksa' => count($menurutId),
            'pasangan' => count($pasangan),
            'grup' => count($grup) - count($silang),
            'ditandai' => 0,
            'dipilih' => count($olehPetugas),
            'dilewati' => count($silang),
            'tak_dikenal' => count($takDikenal),
            'terapkan' => false,
        ];
    }

    $cadangan = cadanganBlok('duplikat-bidang', [
        'waktu' => date('c'),
        'perintah' => 'php tools/impor.php duplikat' . ($buang === [] ? '' : ' ' . implode(' ', $buang))
            . ' --terapkan',
        'pembanding' => 'ST_Equals atas seluruh bidang yang belum berstatus duplikat',
        'grup' => count($grup) - count($silang),
        'dilewati' => array_map(static fn(array $g): array => array_map(
            static fn(array $b): array => ['kode' => $b['kode'], 'blok' => $b['blok'] ?? null],
            $g
        ), $silang),
        'bidang' => $korban,
    ]);

    $hari = date('Y-m-d');
    db_transaksi(static function () use ($korban, $hari): void {
        foreach ($korban as $b) {
            $sebab = 'Poligon persis sama dengan ' . $b['hidup'] . ' (id ' . $b['hidup_id'] . '); ditandai '
                . $hari . ' oleh perintah impor.php duplikat. Kembar ini sudah ada di berkas GeoJSON sumber.';
            if ($b['dipilih']) {
                $sebab .= ' Grup ini tercatat di dua blok berbeda dan salinan mana yang dibuang '
                    . 'dipilih petugas; geometri tidak dipindah.';
            }
            db_exec(
                "UPDATE bidang SET status = 'duplikat', catatan = ? WHERE id = ?",
                [$b['catatan'] === '' ? $sebab : $b['catatan'] . ' | ' . $sebab, $b['id']]
            );
        }
    });

    foreach ($perBlok as $blok => $daftar) {
        audit(
            'ubah',
            'bidang',
            $blok,
            count($daftar) . ' bidang di ' . $blok . ' ditandai duplikat karena geometrinya persis sama '
                . 'dengan bidang yang dibiarkan hidup. Cadangan: ' . $cadangan,
            ['status' => 'aktif atau perlu_verifikasi'],
            ['status' => 'duplikat', 'kode' => $daftar]
        );
    }
    if ($olehPetugas !== []) {
        $jejak = [];
        foreach ($olehPetugas as $b) {
            $jejak[] = $b['kode'] . ' (' . $b['blok'] . ') dibuang, ' . $b['hidup'] . ' tetap hidup';
        }
        audit(
            'ubah',
            'bidang',
            'duplikat-beda-blok',
            count($olehPetugas) . ' grup kembar beda blok diputuskan petugas: ' . implode('; ', $jejak)
                . '. Geometri tidak dipindah. Cadangan: ' . $cadangan,
            ['status' => 'perlu_verifikasi'],
            ['status' => 'duplikat', 'kode' => array_column($olehPetugas, 'kode')]
        );
    }

    printf("\n%d bidang ditandai duplikat. Cadangan: %s\n", count($korban), $cadangan);

    return [
        'jenis' => 'duplikat',
        'dipiksa' => count($menurutId),
        'pasangan' => count($pasangan),
        'grup' => count($grup) - count($silang),
        'ditandai' => count($korban),
        'dipilih' => count($olehPetugas),
        'dilewati' => count($silang),
        'tak_dikenal' => count($takDikenal),
        'luas_ganda' => round($luasGanda),
        'cadangan' => $cadangan,
        'terapkan' => true,
    ];
}

// ---------------------------------------------------------------------------
// 11. Tautan NOP PBB ke peta
// ---------------------------------------------------------------------------

/** Kolom blok_id sudah terpasang di tabel objek_pajak? */
function kolomBlokPajakAda(): bool
{
    return (int) db_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'objek_pajak'
            AND COLUMN_NAME = 'blok_id'"
    ) > 0;
}

/**
 * Isi objek_pajak.blok_id dari kode blok di dalam NOP.
 *
 * Ini tautan tingkat blok, bukan tingkat bidang: digit 12-14 NOP menyebut nomor
 * blok peta (020 -> BLOK 2), dan setiap blok yang dipakai PBB sudah ada di peta.
 * Tautan ini dapat dihitung ulang kapan pun, jadi tidak perlu cadangan untuk
 * membaliknya selama blok tidak berganti nomor.
 *
 * @return array<string, mixed>
 */
function tautanNopKeBlok(bool $terapkan = false): array
{
    $kolom = kolomBlokPajakAda();
    if (!$kolom && !$terapkan) {
        return ['jenis' => 'taut-blok', 'kolom' => 'belum ada', 'terapkan' => false];
    }
    if (!$kolom) {
        db_exec(
            'ALTER TABLE objek_pajak
                ADD COLUMN blok_id int(11) DEFAULT NULL AFTER bidang_id,
                ADD KEY idx_objek_blok (blok_id),
                ADD CONSTRAINT fk_objek_blok FOREIGN KEY (blok_id)
                    REFERENCES blok (id) ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    $nomorKeId = [];
    foreach (db_all('SELECT id, kode, nomor FROM blok WHERE nomor IS NOT NULL') as $b) {
        $nomorKeId[(int) $b['nomor']] = ['id' => (int) $b['id'], 'kode' => (string) $b['kode']];
    }

    $berubah = [];
    $takTernomer = [];
    $sudah = 0;
    foreach (db_all('SELECT nop, bidang_id, blok_id FROM objek_pajak ORDER BY nop') as $o) {
        $nomor = nomor_blok_dari_nop($o['nop']);
        if ($nomor === null || !isset($nomorKeId[$nomor])) {
            $takTernomer[] = $o['nop'];
            continue;
        }
        $target = $nomorKeId[$nomor]['id'];
        if ((int) ($o['blok_id'] ?? -1) === $target) {
            $sudah++;
            continue;
        }
        $berubah[$target][] = $o['nop'];
    }

    $hasil = [
        'jenis' => 'taut-blok',
        'kolom' => $kolom ? 'ada' : 'baru dibuat',
        'total' => (int) db_value('SELECT COUNT(*) FROM objek_pajak'),
        'sudah_benar' => $sudah,
        'diisi' => array_sum(array_map('count', $berubah)),
        'tak_ternomor' => count($takTernomer),
        'terapkan' => $terapkan,
    ];
    if ($takTernomer !== []) {
        $hasil['contoh_tak_ternomor'] = array_slice($takTernomer, 0, 10);
    }
    if (!$terapkan || $berubah === []) {
        return $hasil;
    }

    $namaPerId = array_column($nomorKeId, 'kode', 'id');
    $isi = [];
    foreach ($berubah as $id => $daftarNop) {
        $isi[] = ['blok' => $namaPerId[$id] ?? (string) $id, 'jumlah' => count($daftarNop)];
    }
    $cadangan = cadanganBlok('taut-blok-pajak', ['perubahan' => $isi, 'nop' => $berubah]);

    foreach ($berubah as $id => $daftarNop) {
        $tanda = implode(',', array_fill(0, count($daftarNop), '?'));
        db_exec("UPDATE objek_pajak SET blok_id = ? WHERE nop IN ($tanda)",
            array_merge([$id], $daftarNop));
        audit(
            'ubah', 'objek_pajak', $namaPerId[$id] ?? (string) $id,
            count($daftarNop) . ' objek pajak ditautkan ke ' . ($namaPerId[$id] ?? $id)
                . ' berdasar kode blok pada NOP. Cadangan: ' . $cadangan,
            ['blok_id' => null], ['blok_id' => $id, 'nop' => $daftarNop]
        );
    }

    printf("\n%d objek pajak tertaut ke blok peta. Cadangan: %s\n", $hasil['diisi'], $cadangan);
    return $hasil;
}

/**
 * Calon pasangan NOP dan bidang di satu blok, untuk dicocokkan petugas.
 *
 * Tidak ada kunci bersama per bidang antara data PBB dan hasil digitasi: nib
 * peta, nomor persil Buku C, luas bumi SPPT dan nama pemegang hak kosong atau
 * memakai nomor yang berbeda, jadi pencocokan otomatis per bidang tidak pernah
 * sah. Yang bisa disajikan hanyalah daftar dua sisi yang belum tertaut dalam
 * blok yang sama.
 *
 * @return array<string, mixed>
 */
function calonTautanBlok(string $kodeBlok): array
{
    $blok = blokCari($kodeBlok);

    $bidang = db_all(
        "SELECT b.id, b.kode, b.nib, b.luas_m2, b.status, h.pemegang_nama
           FROM bidang b
           LEFT JOIN hak h ON h.bidang_id = b.id
          WHERE b.blok_id = ? AND b.tipe = 'tanah'
            AND b.status NOT IN ('dihapus', 'duplikat')
            AND NOT EXISTS (SELECT 1 FROM objek_pajak o WHERE o.bidang_id = b.id)
          ORDER BY b.kode",
        [(int) $blok['id']]
    );
    $nop = db_all(
        'SELECT o.nop, o.nama_wp, o.alamat_op, o.luas_bumi_m2,
                SUBSTRING(o.nop,15,2) AS persil, SUBSTRING(o.nop,17,2) AS urut
           FROM objek_pajak o
          WHERE o.blok_id = ? AND o.bidang_id IS NULL
          ORDER BY o.nop',
        [(int) $blok['id']]
    );

    // Urutan calon hanya saran: nama pemegang bidang (dari label peta) dilawankan
    // dengan nama wajib pajak seblok. Yang menautkan tetap petugas.
    $pasangan = [];
    foreach ($bidang as $b) {
        $nilai = [];
        foreach ($nop as $o) {
            $skor = skor_nama($b['pemegang_nama'], $o['nama_wp']);
            if ($skor > 0) {
                $nilai[] = ['nop' => $o['nop'], 'nama_wp' => $o['nama_wp'], 'skor' => $skor];
            }
        }
        usort($nilai, static fn(array $x, array $y): int => [$y['skor'], $x['nop']] <=> [$x['skor'], $y['nop']]);
        $pasangan[] = ['bidang' => $b, 'calon' => array_slice($nilai, 0, 3), 'jumlah' => count($nilai)];
    }

    return [
        'blok' => $blok,
        'jumlah_bidang' => count($bidang),
        'jumlah_nop' => count($nop),
        'jumlah_tertaut' => (int) db_value(
            'SELECT COUNT(*) FROM objek_pajak WHERE blok_id = ? AND bidang_id IS NOT NULL',
            [(int) $blok['id']]
        ),
        'tanpa_nama' => count(array_filter($bidang, static fn(array $b): bool => (string) ($b['pemegang_nama'] ?? '') === '')),
        'pasti' => count(array_filter($pasangan, static fn(array $p): bool => $p['jumlah'] === 1 && $p['calon'][0]['skor'] >= 2)),
        'pasangan' => $pasangan,
        'bidang' => $bidang,
        'nop' => $nop,
    ];
}

/**
 * Tautkan satu NOP ke satu bidang — versi baris perintah dari aksi
 * "pasang-nop" di halaman bidang.
 *
 * @return array<string, mixed>
 */
function tautkanNopKeBidang(string $kodeBidang, string $nopMentah, bool $terapkan = false): array
{
    $nop = normalkan_nop($nopMentah);
    if (!nop_valid($nop)) {
        throw new RuntimeException('NOP harus 18 digit dan diawali kode wilayah desa '
            . setelan('kode_wilayah_nop', '3324040008') . '.');
    }
    $bidang = db_one(
        'SELECT b.id, b.kode, b.status, b.blok_id, bl.kode AS blok, bl.nomor AS nomor_blok
           FROM bidang b LEFT JOIN blok bl ON bl.id = b.blok_id
          WHERE b.kode = ? LIMIT 1',
        [strtoupper(trim($kodeBidang))]
    );
    if ($bidang === null) {
        throw new RuntimeException('Bidang ' . trim($kodeBidang) . ' tidak ditemukan.');
    }
    if ($bidang['status'] === 'dihapus') {
        throw new RuntimeException('Bidang ' . $bidang['kode'] . ' berstatus dihapus.');
    }
    $objek = db_one('SELECT nop, bidang_id, blok_id, nama_wp FROM objek_pajak WHERE nop = ?', [$nop]);
    if ($objek === null) {
        throw new RuntimeException('NOP ' . $nop . ' belum ada di daftar objek pajak.');
    }
    if ($objek['bidang_id'] !== null && (int) $objek['bidang_id'] !== (int) $bidang['id']) {
        throw new RuntimeException('NOP ini sudah tertaut ke bidang '
            . (string) db_value('SELECT kode FROM bidang WHERE id = ?', [(int) $objek['bidang_id']]) . '.');
    }

    $peringatan = [];
    $nomor = nomor_blok_dari_nop($nop);
    if ($nomor !== null && $bidang['nomor_blok'] !== null && $nomor !== (int) $bidang['nomor_blok']) {
        $peringatan[] = 'NOP ini menunjuk BLOK ' . $nomor . ', bidang ada di ' . $bidang['blok'] . '.';
    }
    if ($bidang['status'] === 'duplikat') {
        $peringatan[] = 'Bidang ini ditandai duplikat; pertimbangkan menautkan ke geometri yang hidup.';
    }

    $hasil = [
        'jenis' => 'taut-nop',
        'terapkan' => $terapkan,
        'nop' => $nop,
        'nama_wp' => $objek['nama_wp'],
        'bidang' => $bidang['kode'],
        'blok' => $bidang['blok'],
        'sudah' => $objek['bidang_id'] !== null,
        'peringatan' => $peringatan,
    ];
    if (!$terapkan || $objek['bidang_id'] !== null) {
        return $hasil;
    }

    $cadangan = cadanganBlok('taut-nop', [[
        'nop' => $nop, 'bidang_id' => $objek['bidang_id'], 'sebelum' => $objek,
    ]]);
    db_exec('UPDATE objek_pajak SET bidang_id = ? WHERE nop = ?', [(int) $bidang['id'], $nop]);
    audit(
        'ubah', 'objek_pajak', $nop,
        'NOP ' . $nop . ' (' . $objek['nama_wp'] . ') ditautkan ke bidang ' . $bidang['kode']
            . '. Cadangan: ' . $cadangan,
        $objek, ['nop' => $nop, 'bidang_id' => (int) $bidang['id']]
    );
    $hasil['cadangan'] = $cadangan;
    return $hasil;
}

// ---------------------------------------------------------------------------
// 12. Nama pemegang dari lapisan tn_nama pada peta master
// ---------------------------------------------------------------------------

/** Nama pemegang di MASTER.dxf disimpan sebagai TEXT pada lapisan ini. */
const NAMA_LAPISAN = 'tn_nama';

/** Konversi DXF ke WGS84 punya RMS 1,2 m, jadi label yang jatuh tak lebih dari
 *  2 m dari sebuah bidang masih masuk akal sebagai namanya bidang itu. */
const NAMA_TOLERANSI_M = 2.0;

/** Jangkauan aturan keempat: label yang tidak jatuh di bidang mana pun boleh
 *  dipinjam oleh bidang terdekatnya sejauh tidak ada bidang lain yang sama dekat.
 *  Diukur pada 47 bidang tanpa nama: 9 label tak diperebutkan dalam 20 m, 6 di
 *  antaranya dalam 10 m. Yang lebih jauh dari ini biasanya label milik bidang
 *  tetangga yang bergeser, bukan nama bidang kosong ini. */
const NAMA_JANGKAU_M = 10.0;

/** Bidang yang kebagian lebih dari sekian label tidak lagi bisa dibaca sebagai
 *  satu pemegang: ukurannya tanda bangunan umum (kantor desa, balai) yang
 *  dilingkungi bidang milik banyak orang. Nama begitu jangan ditulis. */
const NAMA_MAKS_BAGIAN = 4;

const NAMA_ASAL = [
    'label' => 'Nama diambil dari label lapisan ' . NAMA_LAPISAN . ' pada peta master',
    'tepi' => 'Nama label ' . NAMA_LAPISAN . ' yang jatuh kurang dari ' . NAMA_TOLERANSI_M . ' m dari tepi bidang',
    'terdekat' => 'Nama label ' . NAMA_LAPISAN . ' terdekat yang tidak diperebutkan bidang hidup lain (maks ' . NAMA_JANGKAU_M . ' m)',
    'kembaran' => 'Nama disalin dari bidang kembar yang poligonnya identik',
];

/**
 * Ambil label nama dari lapisan tn_nama beserta bidang tempat label itu jatuh.
 * Label yang tidak jatuh di dalam bidang hidup tetap dikembalikan dengan
 * bidang_id = null supaya bisa dipakai aturan tepi.
 *
 * @return array<int, array{bidang_id:?int, nama:string, lon:float, lat:float}>
 */
function labelNamaMaster(?string $berkas = null): array
{
    $siap = db()->prepare(
        "SELECT id FROM bidang WHERE tipe = 'tanah' AND status NOT IN ('dihapus', 'duplikat')
           AND ST_Contains(geom, POINT(?, ?)) ORDER BY luas_m2 ASC LIMIT 1"
    );
    $out = [];
    foreach (dxfTeks(masterDxf($berkas)) as $t) {
        if ($t['layer'] !== NAMA_LAPISAN) {
            continue;
        }
        if (!namaLayak($t['teks'])) {
            continue;
        }
        [$lon, $lat] = dxfKeWgs84($t['x'], $t['y']);
        $siap->execute([$lon, $lat]);
        $id = $siap->fetchColumn();
        $out[] = [
            'bidang_id' => ($id === false || $id === null) ? null : (int) $id,
            'nama' => $t['teks'],
            'lon' => (float) $lon,
            'lat' => (float) $lat,
        ];
    }
    return $out;
}

/**
 * Bidang hidup yang tidak menumpang label apa-apa tapi hanya berjarak beberapa
 * meter dari satu label: nama itu dipinjam kalau hanya ada satu bidang calon.
 * Jaraknya diuji dua kali — MBRIntersects menyaring cepat tapi hanya melihat kotak
 * pembatas poligon, ST_Distance yang memastikan tepinya benar-benar sedekat itu.
 *
 * @param array<int, array{bidang_id:?int, nama:string, lon:float, lat:float}> $label
 * @param array<int, array<string, string>> $sudah bidang yang sudah dapat label
 * @return array<int, array<string, string>>
 */
function namaDariTepi(array $label, array $sudah): array
{
    $derajat = NAMA_TOLERANSI_M / 111320.0;
    $tetangga = db()->prepare(
        "SELECT id FROM bidang WHERE tipe = 'tanah' AND status NOT IN ('dihapus', 'duplikat')
           AND MBRIntersects(geom, ST_Buffer(POINT(?, ?), ?))
           AND ST_Distance(geom, POINT(?, ?)) <= ?"
    );
    $out = [];
    foreach ($label as $l) {
        if ($l['bidang_id'] !== null) {
            continue;
        }
        $tetangga->execute([$l['lon'], $l['lat'], $derajat, $l['lon'], $l['lat'], $derajat]);
        $kandidat = $tetangga->fetchAll();
        if (count($kandidat) !== 1) {
            continue;                       // diperebutkan atau tidak ada sama sekali
        }
        $id = (int) $kandidat[0]['id'];
        if (isset($sudah[$id])) {
            continue;                       // bidangnya sudah punya nama sendiri
        }
        $nama = strtoupper(preg_replace('/\s+/', ' ', trim($l['nama'])) ?? '');
        if ($nama !== '') {
            $out[$id][$nama] = $nama;
        }
    }
    return $out;
}

/**
 * Label yang tidak jatuh di bidang mana pun dan tidak terpakai aturan tepi:
 * pinjamkan ke bidang hidup tanpa nama terdekat, tapi hanya kalau tidak ada
 * bidang hidup lain yang sama dekat atau lebih dekat ke label itu, dan hanya
 * kalau bidang itu cuma punya satu nama semacam ini. Dua nama yang sama-sama
 * tak berpemenang pada satu bidang (terukur satu kasus: HM-0666) dibiarkan —
 * itu pertanyaan lapangan, bukan undian.
 *
 * @param array<int, array{bidang_id:?int, nama:string, lon:float, lat:float}> $label
 * @param array<int, array<string, string>> $sudah bidang yang sudah dapat nama
 * @return array<int, array<string, string>>
 */
function namaTerdekat(array $label, array $sudah): array
{
    $derajat = NAMA_JANGKAU_M / 111320.0;
    $kosong = db_all(
        "SELECT b.id, b.pusat_lon AS lon, b.pusat_lat AS lat
           FROM bidang b LEFT JOIN hak h ON h.bidang_id = b.id
          WHERE b.tipe = 'tanah' AND b.status NOT IN ('dihapus', 'duplikat')
            AND (h.pemegang_nama IS NULL OR h.pemegang_nama = '')"
    );
    $jarak = db()->prepare(
        'SELECT ROUND(ST_Distance(ST_GeomFromText(ST_AsText(geom), 0), POINT(?, ?)) * 111320.0, 2)
           FROM bidang WHERE id = ?'
    );
    // bidang hidup lain yang sama dekatnya atau lebih dekat ke label ini
    $pesaing = db()->prepare(
        "SELECT COUNT(*) FROM bidang x
          WHERE x.tipe = 'tanah' AND x.status NOT IN ('dihapus', 'duplikat') AND x.id <> ?
            AND ST_Distance(ST_GeomFromText(ST_AsText(x.geom), 0), POINT(?, ?)) * 111320.0 <= ?"
    );

    $out = [];
    foreach ($kosong as $b) {
        $id = (int) $b['id'];
        if (isset($sudah[$id])) {
            continue;
        }
        $nama = [];
        foreach ($label as $l) {
            if ($l['bidang_id'] !== null) {
                continue;                   // label ini sudah jadi nama bidangnya sendiri
            }
            if (abs($l['lon'] - (float) $b['lon']) > $derajat
                || abs($l['lat'] - (float) $b['lat']) > $derajat) {
                continue;
            }
            $jarak->execute([(float) $l['lon'], (float) $l['lat'], $id]);
            $meter = $jarak->fetchColumn();
            if ($meter === false || (float) $meter > NAMA_JANGKAU_M) {
                continue;
            }
            $pesaing->execute([$id, (float) $l['lon'], (float) $l['lat'], (float) $meter]);
            if ((int) $pesaing->fetchColumn() > 0) {
                continue;                   // ada bidang lain yang lebih berhak atas label ini
            }
            $bersih = strtoupper(preg_replace('/\s+/', ' ', trim($l['nama'])) ?? '');
            if ($bersih !== '') {
                $nama[$bersih] = $bersih;
            }
        }
        if (count($nama) === 1) {
            $kunci = (string) array_key_first($nama);
            $out[$id][$kunci] = $kunci;
        }
    }
    return $out;
}

/**
 * Bidang yang poligonnya identik dengan bidang lain yang sudah bernama.
 * Kembaran begitu bukan bidang baru: namanya sama dengan jaminan, dan bagian
 * dari pasangan ini lolos penandaan `duplikat` karena aturan itu membandingkan
 * teks WKT, bukan bentuk.
 *
 * @return array<int, array{nama:string, dari:string}>
 */
function namaDariKembaran(): array
{
    $out = [];
    foreach (db_all(
        "SELECT a.id AS kosong, b.kode AS dari, hb.pemegang_nama AS nama
           FROM bidang a
           JOIN bidang b ON b.luas_m2 = a.luas_m2 AND b.id <> a.id
           JOIN hak hb ON hb.bidang_id = b.id AND hb.pemegang_nama IS NOT NULL AND hb.pemegang_nama <> ''
           LEFT JOIN hak ha ON ha.bidang_id = a.id
          WHERE a.tipe = 'tanah' AND b.tipe = 'tanah'
            AND a.status NOT IN ('dihapus', 'duplikat')
            AND b.status NOT IN ('dihapus', 'duplikat')
            AND (ha.pemegang_nama IS NULL OR ha.pemegang_nama = '')
            AND ST_Equals(a.geom, b.geom)
          ORDER BY a.id, b.id"
    ) as $p) {
        $id = (int) $p['kosong'];
        $nama = strtoupper(preg_replace('/\s+/', ' ', trim((string) $p['nama'])) ?? '');
        if ($nama === '') {
            continue;
        }
        $out[$id][$nama] = (string) $p['dari'];
    }
    return $out;
}

/**
 * Pecah nilai nama menjadi bagian-bagiannya, dirapikan dan disortir, dengan dan
 * tanpa penyaringan anotasi peta (lihat namaLayak()). Urutan bagian bukan pembeda:
 * 'A / B' sama dengan 'B / A'.
 *
 * @return array{bagian:array<int,string>, utuh:string, bersih:string}
 */
function rapikanNama(string $lama): array
{
    $bagian = array_values(array_filter(
        array_map(static fn(string $s): string => trim(preg_replace('/\s+/', ' ', $s) ?? ''),
            explode('/', $lama)),
        static fn(string $s): bool => $s !== ''
    ));
    sort($bagian, SORT_STRING);
    $unik = array_values(array_unique($bagian, SORT_REGULAR));
    $bersih = array_values(array_filter($unik, static fn(string $s): bool => namaLayak($s)));
    return ['bagian' => $unik, 'utuh' => implode(' / ', $unik), 'bersih' => implode(' / ', $bersih)];
}

/**
 * Isi hak.pemegang_nama dari label nama peta master.
 *
 * MASTER.dxf memuat ± 4.400 label TEXT di lapisan tn_nama. Label diberi ke bidang
 * lewat tiga aturan, dari yang paling pasti:
 *   1. label  — titik sisip label jatuh di dalam poligon bidang hidup;
 *   2. tepi   — label tidak jatuh di mana pun tapi kurang dari NAMA_TOLERANSI_M
 *               dari HANYA satu bidang hidup (konversi DXF ke WGS84 punya RMS 1,2 m,
 *               jadi label sering duduk tepat di atas garis tepi);
 *   3. terdekat — label tidak jatuh di mana pun dan tidak lolos aturan tepi, tapi
 *               kurang dari NAMA_JANGKAU_M dari satu bidang tanpa nama yang tidak
 *               bersaing dengan bidang hidup mana pun atas label itu;
 *   4. kembaran — bidang tanpa label yang poligonnya identik dengan bidang hidup lain
 *               yang sudah bernama; bagiannya lolos penandaan `duplikat` karena
 *               aturan itu membandingkan teks WKT, bukan bentuk.
 * Nama yang tersimpan tapi ternyata anotasi peta ("MUSHOLA", "AA") dibersihkan.
 * Nama inilah yang membuat calon tautan NOP bisa diurutkan, jadi impor ini
 * prasyarat perintah `calon`.
 *
 * @return array<string, mixed>
 */
function namaPemegangDariMaster(bool $terapkan = false, ?string $berkas = null): array
{
    $label = labelNamaMaster($berkas);
    $asal = [];
    foreach ($label as $l) {
        if ($l['bidang_id'] === null) {
            continue;
        }
        $nama = strtoupper(preg_replace('/\s+/', ' ', trim($l['nama'])) ?? '');
        if ($nama === '') {
            continue;
        }
        $asal[$l['bidang_id']][$nama] = 'label';
    }
    foreach (namaDariTepi($label, $asal) as $id => $daftar) {
        foreach ($daftar as $nama) {
            $asal[$id][$nama] = 'tepi';
        }
    }
    foreach (namaTerdekat($label, $asal) as $id => $daftar) {
        foreach ($daftar as $nama) {
            $asal[$id][$nama] = 'terdekat';
        }
    }
    foreach (namaDariKembaran() as $id => $isi) {
        if (isset($asal[$id])) {
            continue;                       // bidang ini sudah dapat labelnya sendiri
        }
        $nama = array_key_first($isi);
        $asal[$id][$nama] = 'kembaran:' . $isi[$nama];
    }
    $perBidang = [];
    foreach ($asal as $id => $isi) {
        $perBidang[$id] = array_keys($isi);
    }

    $hidup = (int) db_value("SELECT COUNT(*) FROM bidang WHERE tipe='tanah'
                               AND status NOT IN ('dihapus', 'duplikat')");
    $sebelum = db_all(
        "SELECT b.id, b.kode, h.pemegang_nama FROM bidang b
           LEFT JOIN hak h ON h.bidang_id = b.id
          WHERE b.tipe = 'tanah' AND b.status NOT IN ('dihapus', 'duplikat')"
    );
    $punyaNama = 0;
    $kodeDari = [];
    foreach ($sebelum as $b) {
        if ((string) ($b['pemegang_nama'] ?? '') !== '') {
            $punyaNama++;
        }
        $kodeDari[(int) $b['id']] = (string) $b['kode'];
    }

    $akanDiisi = [];
    $kewalahan = [];
    foreach ($perBidang as $id => $isi) {
        $rapi = rapikanNama(implode(' / ', array_values($isi)));
        $teks = $rapi['bersih'];
        if (count($rapi['bagian']) > NAMA_MAKS_BAGIAN) {
            $kewalahan[$id] = ['jumlah' => count($rapi['bagian']), 'nama' => $teks, 'asal' => $asal[$id]];
            continue;                       // bukan satu pemegang, ini bangunan umum
        }
        $lama = rapikanNama(trim((string) (db_value('SELECT pemegang_nama FROM hak WHERE bidang_id = ?', [$id]) ?? '')))['bersih'];
        if ($lama !== '' && strtoupper($lama) !== strtoupper($teks)) {
            $akanDiisi[$id] = ['baru' => $teks, 'berebut' => true, 'asal' => $asal[$id]];
            continue;
        }
        if ($lama === '') {
            $akanDiisi[$id] = ['baru' => $teks, 'berebut' => false, 'asal' => $asal[$id]];
        }
    }

    // Bidang yang nama lamanya memuat anotasi peta dan tidak kebagian nama baru.
    $dibersihkan = [];
    foreach ($sebelum as $b) {
        $lama = trim((string) ($b['pemegang_nama'] ?? ''));
        if ($lama === '' || isset($akanDiisi[(int) $b['id']])) {
            continue;
        }
        $rapi = rapikanNama($lama);
        if ($rapi['bersih'] === '' || $rapi['utuh'] !== $rapi['bersih']) {
            $dibersihkan[(int) $b['id']] = ['kode' => (string) $b['kode'], 'lama' => $lama, 'bersih' => $rapi['bersih']];
        }
    }

    /** Satu penanda asal untuk tiap bidang: kembaran > tepi > terdekat > label. */
    $penanda = static function (array $isi): string {
        foreach ($isi as $a) {
            if (str_starts_with($a, 'kembaran:')) {
                return $a;
            }
        }
        foreach (['tepi', 'terdekat'] as $k) {
            if (in_array($k, $isi, true)) {
                return $k;
            }
        }
        return 'label';
    };
    $asalBaru = static function (array $isi) use ($penanda): string {
        $penanda = $penanda($isi);
        if (str_starts_with($penanda, 'kembaran:')) {
            return NAMA_ASAL['kembaran'] . ' ' . substr($penanda, 9);
        }
        return NAMA_ASAL[$penanda];
    };

    $hasil = [
        'jenis' => 'nama-pemegang',
        'terapkan' => $terapkan,
        'label_dibaca' => count($label),
        'bidang_bernama' => count($perBidang),
        'bidang_hidup' => $hidup,
        'sudah_punya_nama' => $punyaNama,
        'akan_diisi' => count($akanDiisi),
        'dari_tepi' => count(array_filter($akanDiisi,
            static fn(array $x): bool => in_array('tepi', $x['asal'], true))),
        'dari_terdekat' => count(array_filter($akanDiisi,
            static fn(array $x): bool => in_array('terdekat', $x['asal'], true))),
        'dari_kembaran' => count(array_filter($akanDiisi,
            static fn(array $x): bool => (bool) array_filter($x['asal'],
                static fn(string $a): bool => str_starts_with($a, 'kembaran:')))),
        'berebut' => count(array_filter($akanDiisi, static fn(array $x): bool => $x['berebut'])),
        'kewalahan' => count($kewalahan),
        'dibersihkan' => count($dibersihkan),
        'contoh' => array_slice(array_map(
            static fn(int $id): string => $kodeDari[$id] . ' = ' . $akanDiisi[$id]['baru']
                . ' [' . $penanda($akanDiisi[$id]['asal']) . ']',
            array_slice(array_keys($akanDiisi), 0, 8)
        ), 0),
        'contoh_bersih' => array_slice(array_map(
            static fn(array $x): string => $x['kode'] . ': ' . $x['lama'] . ' -> '
                . ($x['bersih'] === '' ? '(kosong)' : $x['bersih']),
            array_values($dibersihkan)
        ), 0, 12),
    ];
    $galat = [];
    foreach ($kewalahan as $id => $x) {
        $galat[] = $kodeDari[$id] . ': kebagian ' . $x['jumlah'] . ' label (maks ' . NAMA_MAKS_BAGIAN
            . ') — nama tidak ditulis, periksa apakah bidang ini bangunan umum';
    }
    $hasil['galat'] = $galat;
    if (!$terapkan || ($akanDiisi === [] && $dibersihkan === [])) {
        return $hasil;
    }

    $cadangan = cadanganBlok('nama-pemegang', array_map(
        static fn(array $b): array => [
            'bidang_id' => (int) $b['id'], 'kode' => $b['kode'], 'sebelum' => $b['pemegang_nama'],
        ],
        array_values(array_filter($sebelum, static fn(array $b): bool => isset($akanDiisi[(int) $b['id']])
            || isset($dibersihkan[(int) $b['id']])))
    ));
    foreach ($akanDiisi as $id => $isi) {
        if ($isi['berebut']) {
            continue;                       // namanya berbeda: tidak ditimpa diam-diam
        }
        // hak.bidang_id unik, jadi satu perintah cukup untuk bidang yang sudah
        // punya baris hak maupun yang belum. Catatan tidak disentuh: kolom itu
        // milik asal hak bidang, provenansi nama cukup tercatat di audit_log.
        db_exec(
            'INSERT INTO hak (bidang_id, jenis_hak_id, pemegang_nama, catatan)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE pemegang_nama = VALUES(pemegang_nama)',
            [
                (int) $id,
                (int) (db_value('SELECT jenis_hak_id FROM hak WHERE bidang_id = ?', [$id])
                    ?? db_value("SELECT id FROM jenis_hak WHERE kode = 'HM'") ?? 1),
                $isi['baru'],
                $asalBaru($isi['asal']),
            ]
        );
    }
    foreach ($dibersihkan as $id => $rapi) {
        db_exec('UPDATE hak SET pemegang_nama = ? WHERE bidang_id = ?', [
            $rapi['bersih'] === '' ? null : $rapi['bersih'],
            (int) $id,
        ]);
    }
    audit(
        'ubah', 'bidang', 'nama-pemegang',
        sprintf('%d bidang diisi nama pemegangnya dari lapisan %s MASTER.dxf (%d lewat tepi, %d lewat terdekat, %d lewat kembaran), %d nama dibersihkan dari anotasi peta. Cadangan: %s',
            count($akanDiisi) - count(array_filter($akanDiisi, static fn(array $x): bool => $x['berebut'])),
            NAMA_LAPISAN, $hasil['dari_tepi'], $hasil['dari_terdekat'], $hasil['dari_kembaran'],
            count($dibersihkan), $cadangan),
        null, ['bidang' => count($akanDiisi)]
    );
    $hasil['cadangan'] = $cadangan;
    return $hasil;
}

// ---------------------------------------------------------------------------
// 13. NIS dari lapisan tn_alashak pada peta master
// ---------------------------------------------------------------------------

/** Nomor alas hak di MASTER.dxf ditulis sebagai TEXT `NIS : ...` pada lapisan ini. */
const NIS_LAPISAN = 'tn_alashak';

/** Bidang sepanjang 120 karakter muat empat NIS sekaligus; yang lebih panjang
 *  dari itu berarti labelnya menumpuk di satu poligon dan tidak bisa dipakai
 *  tanpa keputusan petugas. */
const NIS_PANJANG_MAKS = 120;

function kolomNisAda(): bool
{
    return (int) db_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bidang'
            AND COLUMN_NAME = 'nis'"
    ) > 0;
}

/**
 * Tuang label `NIS : ...` pada lapisan alas hak ke bidang hidup tempat ia jatuh.
 * Bidang terkecil yang menampung titik label dipakai, sama seperti aturan label
 * pada impor nama.
 *
 * Berbeda dengan lapisan tn_nama, di sini bidang tipe `lain` (lapisan `020100`)
 * ikut dilibatkan: kedua lapisan nyaris tidak tumpang tindih — dari 2.143 label
 * yang kebagian poligon, nol yang jatuh di kedua lapisan sekaligus, sisanya 1.995
 * duduk di dalam poligon `020100`. Membatasi aturan ini ke tipe tanah akan
 * membuang hampir semua nomor, bukan menjaga kebenarannya.
 *
 * @return array{bidang: array<int, array<string, string>>, di_luar: int, dibaca: int}
 */
function labelNisMaster(?string $berkas = null): array
{
    $siap = db()->prepare(
        "SELECT id FROM bidang WHERE status NOT IN ('dihapus', 'duplikat')
           AND ST_Contains(geom, POINT(?, ?)) ORDER BY luas_m2 ASC LIMIT 1"
    );
    $bidang = [];
    $luar = [];
    $dibaca = 0;
    $diLuar = 0;
    foreach (dxfTeks(masterDxf($berkas)) as $t) {
        if ($t['layer'] !== NIS_LAPISAN) {
            continue;
        }
        $nilai = trim(preg_replace('/\s+/', ' ', $t['teks']) ?? '');
        if (!preg_match('/^NIS\s*:\s*(.+)$/iu', $nilai, $coklat)
            || trim($coklat[1]) === '0') {
            continue;                       // anotasi daftar, bukan nomor
        }
        $nis = strtoupper(trim($coklat[1]));
        $dibaca++;
        [$lon, $lat] = dxfKeWgs84($t['x'], $t['y']);
        $siap->execute([(float) $lon, (float) $lat]);
        $id = $siap->fetchColumn();
        if ($id === false || $id === null) {
            $diLuar++;
            $luar[] = ['nilai' => $nis, 'lon' => (float) $lon, 'lat' => (float) $lat];
            continue;
        }
        $bidang[(int) $id][$nis] = $nis;
    }
    return ['bidang' => $bidang, 'di_luar' => $diLuar, 'dibaca' => $dibaca, 'luar' => $luar];
}

/**
 * Aturan tepi untuk label nomor — saudara kembar `namaDariTepi` pada impor nama.
 *
 * Sebagian label peta ditulis dengan ujung pena di luar persilnya sendiri: titiknya
 * tidak jatuh di poligon mana pun, padahal bidangnya cuma bersentuhan beberapa
 * puluh sentimeter. Jarak itu nyata, cuma jarang yang berhak dipinjam. Diukur
 * 20 Okt 2026 atas label-label menganggur itu: dari 769 `080201` hanya tiga nomor
 * yang lolos (01800 ke HM-1096 pada 1 m, 01816 ke HM-0108 pada 0,26 m, 03814 ke
 * DXF-020100-2258 pada 1,36 m), dan dari 359 `NIB HM` tidak ada satu pun. Yang
 * ditolak bukan karena jauh: 03215 berdiri 0,07 m dari DXF-020100-0850 tapi 0,93 m
 * juga dari DXF-020100-0074 yang nomornya sudah terpasang, jadi penyangganya
 * menyentuh dua bidang dan pilihannya dibiarkan ke petugas.
 *
 * Sebuah label menganggur hanya dipakai kalau SEMUA syarat ini terpenuhi:
 *   - penyangga NOMOR_TOLERANSI_M menyentuh tepat satu bidang hidup. Uji kotak
 *     pembatas saja tidak cukup — MBRIntersects meloloskan poligon yang tepinya
 *     20 m jauhnya (terukur pada label `080201` 01731 dan HM-1224), jadi jarak
 *     sebenarnya ikut diuji dengan ST_Distance;
 *   - bidang itu sasaran (status dan kolomnya masih kosong) dan tidak sudah punya
 *     label yang jatuh di dalamnya sendiri;
 *   - nomor itu belum terpasang di bidang hidup lain dan tidak sedang dipinjam oleh
 *     bidang lain; dua label menganggur yang menunjuk satu bidang dibatalkan.
 *
 * @param list<array{nilai:string, lon:float, lat:float}> $luar
 * @param list<int> $target
 * @param array<int, mixed> $sudahPunyaLabel
 * @param array<int, mixed> $sudahTerisi
 * @param array<int, mixed> $sudahDipilih
 * @param array<string, int> $terpasang nomor => id bidang hidup yang memakainya
 * @return array{tepi: array<int, string>, ditolak: int, diuji: int}
 */
function labelTepiNomor(array $luar, array $target, array $sudahPunyaLabel, array $sudahTerisi,
                        array $sudahDipilih, array $terpasang): array
{
    $derajat = NOMOR_TOLERANSI_M / 111320.0;
    $tetangga = db()->prepare(
        "SELECT id FROM bidang WHERE status NOT IN ('dihapus', 'duplikat')
           AND MBRIntersects(geom, ST_Buffer(POINT(?, ?), ?))
           AND ST_Distance(geom, POINT(?, ?)) <= ?"
    );
    $tepi = [];
    $ditolak = 0;
    foreach ($luar as $l) {
        $tetangga->execute([$l['lon'], $l['lat'], $derajat, $l['lon'], $l['lat'], $derajat]);
        $kandidat = $tetangga->fetchAll(PDO::FETCH_COLUMN);
        if (count($kandidat) !== 1) {
            $ditolak++;                     // diperebutkan beberapa bidang, atau tidak ada sama sekali
            continue;
        }
        $id = (int) $kandidat[0];
        if (!in_array($id, $target, true) || isset($sudahPunyaLabel[$id])
            || isset($sudahTerisi[$id]) || isset($sudahDipilih[$id])) {
            $ditolak++;                     // bidang ini sudah punya nomor sendiri, atau bukan sasaran
            continue;
        }
        if (isset($terpasang[$l['nilai']])) {
            $ditolak++;                     // nomor itu sudah terpasang di bidang lain
            continue;
        }
        if (isset($tepi[$id]) && $tepi[$id] !== $l['nilai']) {
            unset($tepi[$id]);              // dua label menganggur menunjuk satu bidang: peta tidak bisa memilih
            $ditolak++;
            continue;
        }
        $tepi[$id] = $l['nilai'];
    }
    return ['tepi' => $tepi, 'ditolak' => $ditolak, 'diuji' => count($luar)];
}

/**
 * Isi bidang.nis dari label lapisan tn_alashak pada peta master.
 *
 * NIS bukan nib: nilai keduanya tidak bersinggungan sama sekali (1.266 nib unik
 * di basis data, irisannya nol terhadap 2.696 NIS label), jadi nomor ini disimpan
 * pada kolomnya sendiri dan tidak menimpa identitas yang sudah ada.
 *
 * Bidang dengan dua NIS atau lebih dibiarkan apa adanya di laporan — labelnya
 * memang duduk di dalam poligon yang sama, tapi persil mana yang memegang nomor
 * mana tidak bisa dipastikan dari peta. Dua NIS yang tertulis `A / B` dianggap
 * satu transkripsi dan boleh disimpan; yang menentukan tetap petugas.
 *
 * Bawaan perintah ini hanya menyentuh bidang bertstatus perlu_verifikasi, yaitu
 * yang ditanyakan petugas. Tambah --semua untuk mengisi semua bidang tanah hidup.
 *
 * @return array<string, mixed>
 */
function nisDariMaster(bool $terapkan = false, bool $semua = false, ?string $berkas = null): array
{
    $kolom = kolomNisAda();
    if (!$kolom && !$terapkan) {
        return [
            'jenis' => 'nis-bidang', 'kolom' => 'belum ada', 'terapkan' => false,
            'catatan' => 'bidang.nis baru dibuat saat perintah ini dijalankan dengan --terapkan',
        ];
    }
    if (!$kolom) {
        db_exec('ALTER TABLE bidang
                    ADD COLUMN nis varchar(120) DEFAULT NULL COMMENT \'Angka pada label `NIS : ...` lapisan tn_alashak peta master, beberapa dipisah " / "\' AFTER nib');
    }

    $label = labelNisMaster($berkas);
    $hidup = db_all(
        "SELECT id, kode, nib, nis, tipe, status FROM bidang
          WHERE status NOT IN ('dihapus', 'duplikat')"
    );
    $menurutId = [];
    foreach ($hidup as $b) {
        $menurutId[(int) $b['id']] = $b;
    }
    $target = $semua ? array_keys($menurutId) : array_keys(array_filter(
        $menurutId, static fn(array $b): bool => $b['status'] === 'perlu_verifikasi'
    ));

    $akanDiisi = [];
    $cara = [];
    $berebut = [];
    $terlaluPanjang = [];
    $galat = [];
    foreach ($target as $id) {
        $bidang = $menurutId[$id];
        $daftar = $label['bidang'][$id] ?? [];
        if ($daftar === []) {
            continue;
        }
        $teks = implode(' / ', $daftar);
        if (strlen($teks) > NIS_PANJANG_MAKS) {
            $terlaluPanjang[$id] = $teks;
            continue;
        }
        $lama = trim((string) ($bidang['nis'] ?? ''));
        if ($lama !== '' && strtoupper($lama) !== strtoupper($teks)) {
            $berebut[$id] = ['baru' => $teks, 'lama' => $lama];
            continue;
        }
        if ($lama === '') {
            $akanDiisi[$id] = $teks;
            $cara[$id] = 'tepat di dalam';
        }
    }

    // Label NIS yang titiknya tidak jatuh di poligon mana pun masih boleh dipakai
    // kalau penyangga 2 m menyentuh tepat satu bidang sasaran dan nomornya belum
    // terpasang di bidang hidup lain. Lihat `labelTepiNomor`.
    $terpasang = [];
    $terisi = [];
    foreach ($hidup as $b) {
        $lama = trim((string) ($b['nis'] ?? ''));
        if ($lama === '') {
            continue;
        }
        $terisi[(int) $b['id']] = true;
        foreach (preg_split('/ \/ /', $lama) as $angka) {
            $terpasang[strtoupper(trim($angka))] = (int) $b['id'];
        }
    }
    $tepi = labelTepiNomor($label['luar'], $target, $label['bidang'], $terisi, $akanDiisi, $terpasang);
    // Dua label menganggur yang tertulis sama jangan sampai meminjamkan nomor yang
    // sama ke dua bidang: keduanya dibatalkan.
    $pemegang = [];
    foreach ($tepi['tepi'] as $id => $nis) {
        $pemegang[$nis][] = $id;
    }
    $sengketa = [];
    foreach ($pemegang as $nis => $ids) {
        if (count($ids) < 2) {
            continue;
        }
        foreach ($ids as $id) {
            unset($tepi['tepi'][$id]);
            $lain = array_diff($ids, [$id]);
            $sengketa[$id] = 'nis ' . $nis . ' juga dipinjam '
                . implode(', ', array_map(static fn(int $x): string => (string) $menurutId[$x]['kode'], $lain));
        }
    }
    foreach ($tepi['tepi'] as $id => $nis) {
        if (strlen($nis) > NIS_PANJANG_MAKS) {
            $terlaluPanjang[$id] = $nis;
            continue;
        }
        $akanDiisi[$id] = $nis;
        $cara[$id] = 'label tepi ' . NOMOR_TOLERANSI_M . ' m';
    }

    $tanpaLabel = array_values(array_filter(
        $target, static fn(int $id): bool => !isset($label['bidang'][$id])
    ));
    $hasil = [
        'jenis' => 'nis-bidang',
        'terapkan' => $terapkan,
        'sasaran' => $semua ? 'semua bidang hidup' : 'bidang perlu_verifikasi',
        'label_dibaca' => $label['dibaca'],
        'label_tanpa_bidang' => $label['di_luar'],
        'label_tepi_diuji' => $tepi['diuji'],
        'label_tepi_ditolak' => $tepi['ditolak'],
        'sasaran_diperiksa' => count($target),
        'sasaran_tanah' => count(array_filter($target,
            static fn(int $id): bool => $menurutId[$id]['tipe'] === 'tanah')),
        'sasaran_kebagian' => count(array_intersect($target, array_keys($label['bidang']))),
        'sasaran_tanpa_label' => count($tanpaLabel),
        'nis_ganda' => count(array_filter(
            array_intersect_key($label['bidang'], array_flip($target)),
            static fn(array $d): bool => count($d) > 1
        )),
        'akan_diisi' => count($akanDiisi),
        'berebut' => count($berebut),
        'sengketa_tepi' => count($sengketa),
        'terlalu_panjang' => count($terlaluPanjang),
        'sudah_punya_nis' => count(array_filter(
            $target, static fn(int $id): bool => trim((string) ($menurutId[$id]['nis'] ?? '')) !== ''
        )),
        'akan_diisi_tepi' => count(array_filter($cara,
            static fn(string $c): bool => $c !== 'tepat di dalam')),
        'contoh' => array_slice(array_map(
            static fn(int $id): string => $menurutId[$id]['kode'] . ' = ' . $akanDiisi[$id]
                . ' (' . ($cara[$id] ?? 'tepat di dalam') . ')',
            array_slice(array_keys($akanDiisi), 0, 8)
        ), 0),
    ];
    foreach ($berebut as $id => $isi) {
        $galat[] = $menurutId[$id]['kode'] . ': nis lama "' . $isi['lama'] . '" berbeda dari label "'
            . $isi['baru'] . '" — tidak ditimpa, perbaiki lewat halaman bidang';
    }
    foreach ($terlaluPanjang as $id => $teks) {
        $galat[] = $menurutId[$id]['kode'] . ': ' . count(preg_split('/ \/ /', $teks)) . ' NIS menumpuk ('
            . $teks . '), melebihi ' . NIS_PANJANG_MAKS . ' karakter — tidak diisi';
    }
    foreach ($sengketa as $id => $sebab) {
        $galat[] = $menurutId[$id]['kode'] . ': ' . $sebab . ' — tidak diisi, labelnya tertulis dua kali';
    }
    $hasil['galat'] = $galat;
    if (!$terapkan || $akanDiisi === []) {
        return $hasil;
    }

    $cadangan = cadanganBlok('nis-bidang', array_map(
        static fn(int $id): array => [
            'bidang_id' => $id,
            'kode' => $menurutId[$id]['kode'],
            'sebelum' => $menurutId[$id]['nis'],
            'sesudah' => $akanDiisi[$id],
            'cara' => $cara[$id] ?? 'tepat di dalam',
        ],
        array_keys($akanDiisi)
    ));
    foreach ($akanDiisi as $id => $teks) {
        db_exec('UPDATE bidang SET nis = ? WHERE id = ?', [$teks, (int) $id]);
    }
    audit(
        'ubah', 'bidang', 'nis-bidang',
        sprintf('%d bidang diisi nis-nya dari label lapisan %s MASTER.dxf (%d di antaranya lebih dari satu nomor, '
            . '%d dipinjam dari label tepi %s m yang titiknya tidak jatuh di poligon mana pun), sasaran: %s. Cadangan: %s',
            count($akanDiisi), NIS_LAPISAN, $hasil['nis_ganda'], $hasil['akan_diisi_tepi'],
            NOMOR_TOLERANSI_M, $hasil['sasaran'], $cadangan),
        null, ['bidang' => count($akanDiisi)]
    );
    $hasil['cadangan'] = $cadangan;
    return $hasil;
}

// ---------------------------------------------------------------------------
// 14. Kode bidang diganti dari DXF-<lapisan>-<nomor> menjadi HM-<NIS>
// ---------------------------------------------------------------------------

/** Bidang hasil digitasi lapisan DXF masuk dengan kode sementara semacam ini. */
const KODE_SEMENTARA = 'DXF-%';

/** Lebar kolom bidang.kode; kode usang lebih panjang dari ini tidak dilirik. */
const KODE_PANJANG_MAKS = 32;

/** Prefix kode baru — petugas yang meminta pola ini yang memilihnya. */
const KODE_BARU_PREFIX = 'HM-';

/**
 * Ganti kode sementara bidang `020100` menjadi HM-<NIS>.
 *
 * Hanya bidang yang punya SATU NIS dan nomornya tidak dipakai bidang lain yang
 * diganti. Yang tetap berkode lama: bidang tanpa NIS, bidang dengan dua NIS
 * (`A219 / 1071D` — tidak bisa dipilih satu), dan pasangan yang dua-duanya
 * mencatat nomor sama (mis. 1071D pada DXF-020100-2132 dan -2133). Kolom kode
 * punya UNIQUE, jadi menebak berarti menabrak constraint atau menggabungkan dua
 * bidang yang berbeda.
 *
 * `kode` tidak dirujuk foreign key mana pun (semua tabel menunjuk `bidang.id`),
 * jadi menggantinya aman secara relasi. Yang perlu diingat: `impor.php bidang`
 * membaca ulang GeoJSON dan memberi kode DXF-nya kembali, jadi Rename ini tidak
 * bertahan di atas impor ulang — ia perbaikan tampilan, bukan sumber kebenaran.
 * Kolom `sumber` (020100) dan `nis` tetap seperti semula.
 *
 * @return array<string, mixed>
 */
function kodeDariNis(bool $terapkan = false): array
{
    if (!kolomNisAda()) {
        return [
            'jenis' => 'kode-nis', 'kolom' => 'belum ada', 'terapkan' => $terapkan,
            'catatan' => 'jalankan "impor.php nis --terapkan" lebih dulu',
        ];
    }

    $sementara = db_all(
        "SELECT id, kode, nis FROM bidang WHERE kode LIKE '" . KODE_SEMENTARA . "'"
    );
    $dipakai = [];
    foreach (db_all('SELECT kode FROM bidang') as $k) {
        $dipakai[strtoupper(trim((string) $k['kode']))] = true;
    }

    $perBaru = [];
    $tanpaNis = 0;
    $nisGanda = 0;
    $terlaluPanjang = [];
    foreach ($sementara as $b) {
        $nis = trim((string) ($b['nis'] ?? ''));
        if ($nis === '') {
            $tanpaNis++;
            continue;
        }
        if (str_contains($nis, '/')) {
            $nisGanda++;
            continue;
        }
        $nis = strtoupper(preg_replace('/\s+/', ' ', $nis) ?? '');
        $baru = KODE_BARU_PREFIX . $nis;
        if (strlen($baru) > KODE_PANJANG_MAKS) {
            $terlaluPanjang[(int) $b['id']] = $baru;
            continue;
        }
        $perBaru[$baru][] = [(int) $b['id'], $b['kode']];
    }

    $akanGanti = [];
    $sudahAda = [];
    $kembar = [];
    foreach ($perBaru as $baru => $isi) {
        if (count($isi) > 1) {
            $kembar[$baru] = $isi;
            continue;                       // dua bidang mencatat nomor yang sama
        }
        if (isset($dipakai[$baru])) {
            $sudahAda[$baru] = $isi[0];
            continue;                       // nomornya sudah jadi kode bidang lain
        }
        $akanGanti[$isi[0][0]] = ['baru' => $baru, 'lama' => $isi[0][1]];
    }

    $hasil = [
        'jenis' => 'kode-nis',
        'terapkan' => $terapkan,
        'berkode_sementara' => count($sementara),
        'akan_ganti' => count($akanGanti),
        'tetap_tanpa_nis' => $tanpaNis,
        'tetap_nis_ganda' => $nisGanda,
        'dilewati_kembar' => array_sum(array_map(
            static fn(array $i): int => count($i), $kembar
        )),
        'dilewati_sudah_ada' => count($sudahAda),
        'dilewati_terlalu_panjang' => count($terlaluPanjang),
        'contoh' => array_slice(array_map(
            static fn(int $id): string => $akanGanti[$id]['lama'] . ' -> ' . $akanGanti[$id]['baru'],
            array_slice(array_keys($akanGanti), 0, 8)
        ), 0),
    ];
    $galat = [];
    foreach ($kembar as $baru => $isi) {
        $galat[] = $baru . ' tercatat di ' . count($isi) . ' bidang ('
            . implode(', ', array_map(static fn(array $x): string => $x[1], $isi))
            . ') — tidak diganti, nomor mana milik bidang mana itu pertanyaan lapangan';
    }
    foreach ($sudahAda as $baru => $isi) {
        $galat[] = $baru . ' sudah dipakai bidang lain — ' . $isi[1] . ' tetap berkode lama';
    }
    foreach ($terlaluPanjang as $id => $baru) {
        $galat[] = (string) db_value('SELECT kode FROM bidang WHERE id = ?', [$id])
            . ': "' . $baru . '" melebihi ' . KODE_PANJANG_MAKS . ' karakter — tidak diganti';
    }
    $hasil['galat'] = $galat;
    if (!$terapkan || $akanGanti === []) {
        return $hasil;
    }

    $barisCadangan = [];
    foreach ($akanGanti as $id => $isi) {
        $barisCadangan[] = ['bidang_id' => $id, 'lama' => $isi['lama'], 'baru' => $isi['baru']];
    }
    $cadangan = cadanganBlok('kode-nis', $barisCadangan);

    db_transaksi(static function (PDO $connect) use ($akanGanti): void {
        $siap = $connect->prepare('UPDATE bidang SET kode = ? WHERE id = ?');
        foreach ($akanGanti as $id => $isi) {
            $siap->execute([$isi['baru'], $id]);
        }
    });
    audit(
        'ubah', 'bidang', 'kode-nis',
        sprintf('%d bidang berkode %s diganti menjadi %s<nis>-nya (nis lapisan %s); %d tetap karena tanpa nis, '
            . '%d karena nis-nya ganda, %d karena nomornya dipakai bidang lain. Cadangan: %s',
            count($akanGanti), KODE_SEMENTARA, KODE_BARU_PREFIX, NIS_LAPISAN,
            $tanpaNis, $nisGanda, count($sudahAda) + count($kembar) + count($terlaluPanjang), $cadangan),
        null, ['bidang' => count($akanGanti)]
    );
    $hasil['cadangan'] = $cadangan;
    return $hasil;
}

// ---------------------------------------------------------------------------
// 15. Nama pemegang untuk bidang perlu_verifikasi lintas lapisan
// ---------------------------------------------------------------------------

/**
 * Isi hak.pemegang_nama pada bidang berstatus perlu_verifikasi dari label
 * tn_nama MASTER.dxf — termasuk bidang lapisan `020100` (tipe `lain`).
 *
 * Perintah `nama` (§12) membatasi dirinya ke tipe tanah, dan hampir semua bidang
 * perlu_verifikasi justru duduk di lapisan lain: terukur 19 Okt 2026, 2.237 dari
 * 2.355 bidang `lain` perlu_verifikasi menampung satu label nama di dalam
 * poligonnya dan semuanya masih tanpa nama. Aturan yang dipakai di sini hanya
 * aturan paling pasti dari §12 — titik sisip label jatuh di dalam poligon
 * (`ST_Contains`, poligon terkecil kalau bertumpuk). Aturan tepi dan terdekat
 * tidak dipakai: keduanya ditakar untuk bidang tanah dan belum pernah diukur
 * untuk lapisan ini, jadi memangunya sekarang sama dengan mengarang.
 *
 * Dua label dalam satu bidang ditulis dipisah ` / ` seperti pada §12 (suami-istri
 * memang tercatat begitu); nama yang sudah ada dan berbeda tidak pernah ditimpa.
 * Baris `hak` untuk semua bidang ini sudah ada (jenis `BLM` dari digitasi), jadi
 * perintah ini hanya menyentuh `pemegang_nama` — `jenis_hak_id` dan `catatan`
 * dibiarkan; provenansinya masuk ke audit_log.
 *
 * @return array<string, mixed>
 */
function namaVerifikasiDariMaster(bool $terapkan = false, ?string $berkas = null): array
{
    $sasaran = db_all(
        "SELECT b.id, b.kode, b.tipe, h.pemegang_nama, h.id AS hak_id
           FROM bidang b LEFT JOIN hak h ON h.bidang_id = b.id
          WHERE b.status = 'perlu_verifikasi'"
    );
    $target = [];
    foreach ($sasaran as $b) {
        $target[(int) $b['id']] = $b;
    }

    $cek = db()->prepare(
        "SELECT id FROM bidang WHERE status NOT IN ('dihapus', 'duplikat')
           AND ST_Contains(geom, POINT(?, ?)) ORDER BY luas_m2 ASC LIMIT 1"
    );
    $labelLayak = 0;
    $labelDiSasaran = 0;
    $perBidang = [];
    foreach (dxfTeks(masterDxf($berkas)) as $t) {
        if ($t['layer'] !== NAMA_LAPISAN || !namaLayak($t['teks'])) {
            continue;
        }
        $nama = strtoupper(preg_replace('/\s+/', ' ', trim($t['teks'])) ?? '');
        if ($nama === '') {
            continue;
        }
        $labelLayak++;
        [$lon, $lat] = dxfKeWgs84($t['x'], $t['y']);
        $cek->execute([(float) $lon, (float) $lat]);
        $id = $cek->fetchColumn();
        if ($id === false || $id === null || !isset($target[(int) $id])) {
            continue;
        }
        $labelDiSasaran++;
        $perBidang[(int) $id][$nama] = $nama;
    }

    $akanDiisi = [];
    $berebut = [];
    $kewalahan = [];
    foreach ($perBidang as $id => $isi) {
        $rapi = rapikanNama(implode(' / ', $isi));
        $teks = $rapi['bersih'];
        if (count($rapi['bagian']) > NAMA_MAKS_BAGIAN) {
            $kewalahan[$id] = ['jumlah' => count($rapi['bagian']), 'lama' => $rapi['utuh']];
            continue;                       // bukan satu pemegang, ini bangunan umum
        }
        // Nama lama dibandingkan setelah disaring, jadi anotasi peta yang tersimpan
        // di kolom itu tidak dihitung sebagai nama orang: bidang yang lamanya cuma
        // "JALAN" boleh diisi label "SISWADI", bidang yang lamanya "SISWADI" tidak.
        $lama = rapikanNama(trim((string) ($target[$id]['pemegang_nama'] ?? '')));
        if ($lama['bersih'] !== '' && strtoupper($lama['bersih']) !== strtoupper($teks)) {
            $berebut[$id] = ['baru' => $teks, 'lama' => $lama['utuh']];
            continue;
        }
        if ($lama['bersih'] === '') {
            $akanDiisi[$id] = $teks;
        }
    }

    // Nama lama pada bidang sasaran yang masih memuat anotasi peta: bagian yang
    // bukan orang dibuang, yang seluruhnya anotasi dikosongkan.
    $dibersihkan = [];
    foreach ($target as $id => $b) {
        $lama = trim((string) ($b['pemegang_nama'] ?? ''));
        if ($lama === '' || isset($akanDiisi[$id])) {
            continue;
        }
        $rapi = rapikanNama($lama);
        if ($rapi['utuh'] !== $rapi['bersih']) {
            $dibersihkan[$id] = $rapi['bersih'];
        }
    }

    $tanpaLabel = count(array_diff(array_keys($target), array_keys($perBidang)));
    $hasil = [
        'jenis' => 'nama-verifikasi',
        'terapkan' => $terapkan,
        'sasaran' => count($target),
        'label_dibaca' => $labelLayak,
        'label_di_bidang_sasaran' => $labelDiSasaran,
        'sasaran_kebagian_label' => count($perBidang),
        'sasaran_tanpa_label' => $tanpaLabel,
        'akan_diisi' => count($akanDiisi),
        'dari_tanah' => count(array_filter($akanDiisi,
            static fn(string $teks, int $id): bool => $target[$id]['tipe'] === 'tanah',
            ARRAY_FILTER_USE_BOTH)),
        'dari_lain' => count(array_filter($akanDiisi,
            static fn(string $teks, int $id): bool => $target[$id]['tipe'] === 'lain',
            ARRAY_FILTER_USE_BOTH)),
        'nama_ganda' => count(array_filter($akanDiisi, static fn(string $teks): bool => str_contains($teks, ' / '))),
        'berebut' => count($berebut),
        'kewalahan' => count($kewalahan),
        'dibersihkan' => count($dibersihkan),
        'tanpa_baris_hak' => count(array_filter($akanDiisi,
            static fn(int $id): bool => $target[$id]['hak_id'] === null, ARRAY_FILTER_USE_KEY)),
        'contoh' => array_slice(array_map(
            static fn(int $id): string => $target[$id]['kode'] . ' = ' . $akanDiisi[$id],
            array_slice(array_keys($akanDiisi), 0, 8)
        ), 0),
        'contoh_bersih' => array_slice(array_map(
            static fn(int $id, string $baru): string => $target[$id]['kode'] . ': '
                . (string) $target[$id]['pemegang_nama'] . ' -> ' . ($baru === '' ? '(kosong)' : $baru),
            array_keys($dibersihkan), $dibersihkan
        ), 0, 12),
    ];
    $galat = [];
    foreach ($berebut as $id => $isi) {
        $galat[] = $target[$id]['kode'] . ': nama lama "' . $isi['lama'] . '" berbeda dari label "'
            . $isi['baru'] . '" — tidak ditimpa';
    }
    foreach ($kewalahan as $id => $x) {
        $galat[] = $target[$id]['kode'] . ': kebagian ' . $x['jumlah'] . ' label (maks ' . NAMA_MAKS_BAGIAN
            . ') — nama tidak ditulis, periksa apakah bidang ini bangunan umum';
    }
    $hasil['galat'] = $galat;
    if (!$terapkan || ($akanDiisi === [] && $dibersihkan === [])) {
        return $hasil;
    }

    $cadangan = cadanganBlok('nama-verifikasi', array_map(
        static fn(int $id): array => [
            'bidang_id' => $id,
            'kode' => $target[$id]['kode'],
            'sebelum' => $target[$id]['pemegang_nama'],
        ],
        array_merge(array_keys($akanDiisi), array_keys($dibersihkan))
    ));
    foreach ($akanDiisi as $id => $teks) {
        if ($target[$id]['hak_id'] === null) {
            db_exec(
                'INSERT INTO hak (bidang_id, pemegang_nama, catatan) VALUES (?, ?, ?)',
                [
                    $id, $teks,
                    'Nama diambil dari label lapisan ' . NAMA_LAPISAN . ' pada peta master;'
                        . ' jenis hak belum tercatat',
                ]
            );
            continue;
        }
        db_exec('UPDATE hak SET pemegang_nama = ? WHERE id = ?', [$teks, (int) $target[$id]['hak_id']]);
    }
    foreach ($dibersihkan as $id => $baru) {
        db_exec('UPDATE hak SET pemegang_nama = ? WHERE id = ?', [
            $baru === '' ? null : $baru,
            (int) $target[$id]['hak_id'],
        ]);
    }
    audit(
        'ubah', 'bidang', 'nama-verifikasi',
        sprintf('%d bidang perlu_verifikasi diisi nama pemegangnya dari label lapisan %s MASTER.dxf '
            . '(%d bidang tipe lain dari lapisan 020100, %d bidang tanah; %d menampung lebih dari satu label). '
            . '%d bidang sasaran tidak punya label sama sekali. %d nama lama pada bidang sasaran dibersihkan '
            . 'dari anotasi peta. Cadangan: %s',
            count($akanDiisi), NAMA_LAPISAN, $hasil['dari_lain'], $hasil['dari_tanah'],
            $hasil['nama_ganda'], $tanpaLabel, count($dibersihkan), $cadangan),
        null, ['bidang' => count($akanDiisi) + count($dibersihkan)]
    );
    $hasil['cadangan'] = $cadangan;
    return $hasil;
}

/**
 * Pekerjaan bersama perintah `nib` dan `daftar`: tuang label nomor satu lapisan
 * peta master ke satu kolom bidang, hanya untuk bidang yang kolomnya masih kosong.
 *
 * Aturan jatuhnya label sama dengan impor nama dan NIS: titik sisip label dicari
 * poligon hidup terkecil yang menampungnya. Tiga hal dijaga:
 *   - bidang yang kebagian dua nomor berbeda tidak diisi, hanya dilaporkan;
 *   - nomor yang diklaim lebih dari satu bidang juga tidak diisi — satu nomor
 *     daftar harus menunjuk satu bidang, dan peta tidak bisa memilih;
 *   - nilai yang sudah ada di kolom itu tidak pernah ditimpa.
 *
 * Label yang titiknya tidak jatuh di poligon mana pun masih boleh dipakai lewat
 * aturan tepi yang sama dengan `namaDariTepi` pada impor nama: penyangga
 * `NOMOR_TOLERANSI_M` harus menyentuh tepat satu bidang sasaran, nomor itu tidak
 * boleh sudah dipakai bidang hidup lain, dan bidangnya tidak boleh sudah punya
 * label sendiri. Terukur 19-20 Okt 2026: tiga nomor daftar dan dua NIS berdiri
 * 0,1-0,4 m di luar poligon yang sebenarnya mereka namai — kartunya menulis label
 * dengan ujung pena, bukan titik tengah. Bidang yang menang lewat aturan tepi
 * dilaporkan terpisah supaya bisa dibaca balik.
 *
 * Sekaligus menjadi uji silang: pada bidang yang kolomnya sudah terisi, berapa
 * label yang menghasilkan nomor sama dan berapa yang berbeda. Angka itu yang
 * membuktikan lapisan mana yang memang sumber kolom tersebut (lihat README 3.14).
 *
 * @param array{kolom: string, lapisan: string, jenis: string, buat: ?string} $konfig
 * @return array<string, mixed>
 */
function isiNomorDariLapisan(array $konfig, bool $terapkan = false, bool $semua = false,
                             ?string $berkas = null): array
{
    $kolom = $konfig['kolom'];
    $jenis = $konfig['jenis'];
    if ($konfig['buat'] !== null && (int) db_value(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bidang' AND COLUMN_NAME = ?", [$kolom]
    ) === 0) {
        if (!$terapkan) {
            return [
                'jenis' => $jenis, 'kolom' => 'belum ada', 'terapkan' => false,
                'catatan' => 'bidang.' . $kolom . ' baru dibuat saat perintah ini dijalankan dengan --terapkan',
            ];
        }
        db_exec($konfig['buat']);
    }

    $siap = db()->prepare(
        "SELECT id FROM bidang WHERE status NOT IN ('dihapus', 'duplikat')
           AND ST_Contains(geom, POINT(?, ?)) ORDER BY luas_m2 ASC LIMIT 1"
    );
    $label = [];
    $luar = [];
    $dibaca = 0;
    $diLuar = 0;
    $ditolak = 0;
    foreach (dxfTeks(masterDxf($berkas)) as $t) {
        if ($t['layer'] !== $konfig['lapisan']) {
            continue;
        }
        $nilai = strtoupper(trim(preg_replace('/\s+/', ' ', $t['teks']) ?? ''));
        if (preg_match('/^(M\.)?0*\d{2,}$/i', $nilai) !== 1) {
            $ditolak++;
            continue;                       // anotasi, bukan nomor bidang
        }
        $dibaca++;
        [$lon, $lat] = dxfKeWgs84($t['x'], $t['y']);
        $siap->execute([(float) $lon, (float) $lat]);
        $id = $siap->fetchColumn();
        if ($id === false || $id === null) {
            $diLuar++;
            $luar[] = ['nilai' => $nilai, 'lon' => (float) $lon, 'lat' => (float) $lat];
            continue;
        }
        $label[(int) $id][$nilai] = $nilai;
    }

    $hidup = db_all(sprintf(
        "SELECT id, kode, tipe, status, %s AS nilai FROM bidang
          WHERE status NOT IN ('dihapus', 'duplikat')", $kolom
    ));
    $menurutId = [];
    foreach ($hidup as $b) {
        $menurutId[(int) $b['id']] = $b;
    }
    $target = $semua ? array_keys($menurutId) : array_keys(array_filter(
        $menurutId, static fn(array $b): bool => $b['status'] === 'perlu_verifikasi'
    ));

    $cocok = 0;
    $berbeda = 0;
    $akanDiisi = [];
    $cara = [];
    $ganda = [];
    foreach ($target as $id) {
        if (trim((string) ($menurutId[$id]['nilai'] ?? '')) !== '') {
            continue;                       // kolom sudah terisi: bukan sasaran lagi
        }
        $daftar = $label[$id] ?? [];
        if ($daftar === []) {
            continue;
        }
        if (count($daftar) > 1) {
            $ganda[$id] = implode(' / ', $daftar);
            continue;
        }
        if (strlen((string) reset($daftar)) > NOMOR_PANJANG_MAKS) {
            $ganda[$id] = 'terlalu panjang: ' . reset($daftar);
            continue;
        }
        $akanDiisi[$id] = (string) reset($daftar);
        $cara[$id] = 'tepat di dalam';
    }

    // Label yang titiknya tidak jatuh di poligon mana pun: boleh dipinjam kalau
    // penyangga NOMOR_TOLERANSI_M menyentuh tepat satu bidang sasaran, nomornya
    // belum terpasang di bidang hidup lain, dan bidang itu tidak sudah punya label
    // sendiri. Sama persis dengan namaDariTepi pada perintah `nama`.
    $terpasang = [];
    $terisi = [];
    foreach ($hidup as $b) {
        $lama = trim((string) ($b['nilai'] ?? ''));
        if ($lama !== '') {
            $terpasang[$lama] = (int) $b['id'];
            $terisi[(int) $b['id']] = true;
        }
    }
    $tepi = labelTepiNomor($luar, $target, $label, $terisi, $akanDiisi, $terpasang);
    $tepiDitolak = $tepi['ditolak'];
    foreach ($tepi['tepi'] as $id => $nomor) {
        $akanDiisi[$id] = $nomor;
        $cara[$id] = 'label tepi ' . NOMOR_TOLERANSI_M . ' m';
    }

    // Nomor yang diklaim lebih dari satu bidang: tidak ada yang boleh memakainya.
    $pemegang = [];
    foreach ($akanDiisi as $id => $nomor) {
        $pemegang[$nomor][] = $id;
    }
    foreach ($pemegang as $nomor => $ids) {
        if (count($ids) < 2) {
            continue;
        }
        foreach ($ids as $id) {
            unset($akanDiisi[$id], $cara[$id]);
            $ganda[$id] = 'nomor ' . $nomor . ' juga jatuh di bidang '
                . implode(', ', array_map(static fn(int $x): string => (string) $menurutId[$x]['kode'],
                    array_diff($ids, [$id])));
        }
    }

    // Uji silang pada bidang yang kolomnya sudah terisi.
    foreach ($menurutId as $id => $b) {
        $lama = trim((string) ($b['nilai'] ?? ''));
        if ($lama === '' || !isset($label[$id])) {
            continue;
        }
        $angka = static fn(string $s): string => (string) (int) ltrim(strtoupper($s), 'M.');
        $ada = array_map($angka, array_keys($label[$id]));
        if (in_array($angka($lama), $ada, true)) {
            $cocok++;
        } else {
            $berbeda++;
        }
    }

    $tanpaLabel = array_values(array_filter(
        $target, static fn(int $id): bool => !isset($label[$id])
    ));
    $dariTepi = static fn(array $idSet): int => count(array_filter(
        $idSet, static fn(int $id): bool => ($cara[$id] ?? '') !== 'tepat di dalam'
    ));
    $hasil = [
        'jenis' => $jenis,
        'terapkan' => $terapkan,
        'lapisan' => $konfig['lapisan'],
        'kolom' => $kolom,
        'sasaran' => $semua ? 'semua bidang hidup' : 'bidang perlu_verifikasi',
        'label_dibaca' => $dibaca,
        'label_bukan_nomor' => $ditolak,
        'label_tanpa_bidang' => $diLuar,
        'label_tepi_diuji' => count($luar),
        'label_tepi_ditolak' => $tepiDitolak,
        'sasaran_diperiksa' => count($target),
        'sasaran_kebagian' => count(array_intersect($target, array_keys($label))),
        'sasaran_tanpa_label' => count($tanpaLabel),
        'akan_diisi' => count($akanDiisi),
        'akan_diisi_tepi' => $dariTepi(array_keys($akanDiisi)),
        'uji_silang_cocok' => $cocok,
        'uji_silang_berbeda' => $berbeda,
        'tidak_diisi' => count($ganda),
        'sudah_terisi' => count(array_filter(
            $target, static fn(int $id): bool => trim((string) ($menurutId[$id]['nilai'] ?? '')) !== ''
        )),
        'contoh' => array_slice(array_map(
            static fn(int $id): string => $menurutId[$id]['kode'] . ' = ' . $akanDiisi[$id]
                . ' (' . ($cara[$id] ?? 'tepat di dalam') . ')',
            array_slice(array_keys($akanDiisi), 0, 8)
        ), 0),
    ];
    $galat = [];
    foreach ($ganda as $id => $sebab) {
        $galat[] = $menurutId[$id]['kode'] . ': ' . $sebab . ' — tidak diisi, periksa letak labelnya';
    }
    $hasil['galat'] = $galat;
    if (!$terapkan || $akanDiisi === []) {
        return $hasil;
    }

    $cadangan = cadanganBlok($jenis, array_map(
        static fn(int $id): array => [
            'bidang_id' => $id,
            'kode' => $menurutId[$id]['kode'],
            'sebelum' => $menurutId[$id]['nilai'],
            'sesudah' => $akanDiisi[$id],
            'cara' => $cara[$id] ?? 'tepat di dalam',
        ],
        array_keys($akanDiisi)
    ));
    db_transaksi(static function (PDO $connect) use ($akanDiisi, $kolom): void {
        $siap = $connect->prepare('UPDATE bidang SET ' . $kolom . ' = ? WHERE id = ?');
        foreach ($akanDiisi as $id => $nomor) {
            $siap->execute([$nomor, (int) $id]);
        }
    });
    audit(
        'ubah', 'bidang', $jenis,
        sprintf('%d bidang diisi %s-nya dari label lapisan `%s` MASTER.dxf (%d titiknya tepat di dalam poligon, '
            . '%d dipinjam dari label tepi %s m yang tidak jatuh di bidang mana pun); uji silang ke bidang yang sudah '
            . 'terisi: %d cocok, %d berbeda; %d bidang tidak diisi karena nomornya ganda. Sasaran: %s. Cadangan: %s',
            count($akanDiisi), $kolom, $konfig['lapisan'],
            count($akanDiisi) - $dariTepi(array_keys($akanDiisi)), $dariTepi(array_keys($akanDiisi)),
            NOMOR_TOLERANSI_M, $cocok, $berbeda, count($ganda),
            $hasil['sasaran'], $cadangan),
        null, ['bidang' => count($akanDiisi)]
    );
    $hasil['cadangan'] = $cadangan;
    return $hasil;
}

/** Nomor persil HM ditulis sebagai TEXT pada lapisan ini. */
const NIB_LAPISAN = 'NIB HM';

/** Bagian `080201` pada peta master: register nomor lain, bukan nib. */
const DAFTAR_LAPISAN = '080201';

/** `bidang.nib` dan `bidang.nomor_daftar` varchar(20). */
const NOMOR_PANJANG_MAKS = 20;

/**
 * Jarak maksimum label nomor yang titiknya jatuh di luar poligon (meter). Sama
 * dengan NAMA_TOLERANSI_M pada impor nama: kartu menggambar label dengan ujung
 * pena, jadi titiknya bisa beberapa puluh sentimeter di luar batas persilnya.
 */
const NOMOR_TOLERANSI_M = 2.0;

/**
 * Isi bidang.nib dari lapisan `NIB HM`.
 *
 * Lapisan ini terbukti menjadi sumber kolomnya: diukur 19 Okt 2026, 1.269 bidang
 * yang nib-nya sudah terisi menghasilkan nomor yang sama persis dari label lapisan
 * ini dan NOL yang berbeda. Cakupannya kecil — peta hanya menulis nomor persil HM,
 * jadi sisanya 2.359 bidang `perlu_verifikasi` memang tidak punya NIB di peta mana
 * pun. Nomor bagian `020100` ada di lapisan lain; lihat README 3.14.
 *
 * @return array<string, mixed>
 */
function nibDariMaster(bool $terapkan = false, bool $semua = false, ?string $berkas = null): array
{
    return isiNomorDariLapisan(
        ['kolom' => 'nib', 'lapisan' => NIB_LAPISAN, 'jenis' => 'nib-bidang', 'buat' => null],
        $terapkan, $semua, $berkas
    );
}

/**
 * Isi bidang.nomor_daftar dari label lapisan `080201`.
 *
 * Register ini dipakai pada bagian desa hasil digitasi `020100` dan tidak sama
 * dengan nib: dari 2.354 nomornya, nol yang menabrak nib yang sudah ada, tapi pada
 * delapan bidang yang punya keduanya ia berbeda dari nib 7 kali dari 8. Karena itu
 * nomornya disimpan pada kolom sendiri, bukan ditukarkan ke nib (README 3.14).
 *
 * @return array<string, mixed>
 */
function nomorDaftarDariMaster(bool $terapkan = false, bool $semua = false, ?string $berkas = null): array
{
    return isiNomorDariLapisan(
        [
            'kolom' => 'nomor_daftar',
            'lapisan' => DAFTAR_LAPISAN,
            'jenis' => 'nomor-daftar',
            'buat' => "ALTER TABLE bidang ADD COLUMN nomor_daftar varchar(20) DEFAULT NULL
                         COMMENT 'Nomor pada label lapisan `080201` peta master — register lain, bukan nib'
                         AFTER nis",
        ],
        $terapkan, $semua, $berkas
    );
}

/** Jenis hak yang hanya menandai "belum ada datanya", bukan hak sesungguhnya. */
const JENIS_HAK_SEMENTARA = ['BLM', 'BLH'];

/**
 * Tetapkan jenis Hak Milik pada bidang yang jenis haknya masih sementara.
 *
 * Bawaan hanya menyentuh bidang `perlu_verifikasi`; `--semua` melebarkan ke semua
 * bidang hidup. Baris `hak` yang sudah mencatat jenis sungguhan (HM, HP, HGB, ...)
 * tidak pernah diganti, dan nomor sertifikat, nomor hak pakai serta tautan Buku C
 * pada baris yang disentuh ikut dibiarkan — perintah ini hanya memindahkan jenisnya.
 *
 * Catatan baris `hak` ditulis ulang karena isinya menyebut jenis yang lama
 * ("Dibawa dari hasil digitasi: Jenis hak tidak tercatat") dan akan bertentangan
 * dengan lencana Hak Milik di layar. Nilai lama ada di cadangan JSON dan di
 * `audit_log`, jadi perubahan bisa dibaca balik.
 *
 * Bidang yang di atas poligonya tertulis anotasi petugas — `GG`, `TANAH DESA`,
 * `BENGKOK`, masjid, jalan — selalu dilewati selama tidak ada nama orang yang
 * bersaing. Aturan ini dulu tidak membacanya, jadi 35 bidang tanah desa, bengkok
 * dan label gang ikut berganti Hak Milik pada 20 Okt 2026; lihat `hakDariAnotasi()`
 * untuk membetulkannya.
 *
 * @return array<string, mixed>
 */
function hakMilikVerifikasi(bool $terapkan = false, bool $semua = false): array
{
    $hm = db_one("SELECT id, nama FROM jenis_hak WHERE kode = 'HM' LIMIT 1");
    if ($hm === null) {
        throw new RuntimeException('jenis_hak kode HM tidak ada — jalankan sql/01_skema.sql lebih dulu.');
    }
    $sementara = "'" . implode("','", JENIS_HAK_SEMENTARA) . "'";

    $baris = db_all(sprintf(
        "SELECT b.id AS bidang_id, b.kode, b.tipe, b.status, h.id AS hak_id,
                jh.kode AS jenis_lama, h.catatan, h.no_sertifikat, h.buku_c_id
           FROM bidang b
           LEFT JOIN hak h        ON h.bidang_id = b.id
           LEFT JOIN jenis_hak jh ON jh.id = h.jenis_hak_id
          WHERE (h.id IS NULL OR jh.kode IN (%s))
            %s
          ORDER BY b.kode",
        $sementara,
        $semua ? "AND b.status <> 'dihapus'" : "AND b.status = 'perlu_verifikasi'"
    ));

    // Bidang yang poligonya ditumpungi anotasi petugas (GG, TANAH DESA, BENGKOK,
    // masjid, ...) tidak boleh ikut dijadikan Hak Milik: peta menyatakan tanahnya
    // bukan milik orang. Yang anotasi dan namanya masih bentrok dibiarkan lewat —
    // nama orang tetap bukti yang lebih kuat daripada label yang bergeser. Label GG
    // pada persil yang punya nomornya sendiri juga dibiarkan lewat: yang bergeser
    // labelnya, bukan status tanahnya (lihat `jenisHakDariAnotasi()`).
    $dikecualikan = [];
    foreach (anotasiPetugas() as $id => $b) {
        if ($b['nama'] !== []) {
            continue;
        }
        if (jenisHakDariAnotasi($b) !== null) {
            $dikecualikan[$id] = true;
            continue;
        }
        foreach (array_keys($b['anotasi']) as $k) {
            if (in_array($k, ANOTASI_FASILITAS, true)) {
                $dikecualikan[$id] = true;
                break;
            }
        }
    }
    $disebabkanAnotasi = 0;
    $baris = array_values(array_filter($baris, static function (array $b) use ($dikecualikan, &$disebabkanAnotasi): bool {
        if (isset($dikecualikan[(int) $b['bidang_id']])) {
            $disebabkanAnotasi++;
            return false;
        }
        return true;
    }));

    $akanDiubah = [];
    $akanDisisip = [];
    $berbukti = 0;
    foreach ($baris as $b) {
        if ($b['no_sertifikat'] !== null && $b['no_sertifikat'] !== '' || $b['buku_c_id'] !== null) {
            $berbukti++;                     // jenisnya pindah, bukti tertulisnya tidak disentuh
        }
        if ($b['hak_id'] === null) {
            $akanDisisip[(int) $b['bidang_id']] = $b;
        } else {
            $akanDiubah[(int) $b['hak_id']] = $b;
        }
    }

    $keterangan = 'Hak Milik ditetapkan oleh perintah "impor.php hak-milik" pada '
        . date('Y-m-d') . '; jenis sebelumnya {lama} dan status bidang {status}.';

    $hasil = [
        'jenis' => 'hak-milik',
        'terapkan' => $terapkan,
        'sasaran' => $semua ? 'semua bidang hidup' : 'bidang perlu_verifikasi',
        'dikecualikan_beranotasi' => $disebabkanAnotasi,
        'bidang_diperiksa' => (int) db_value(sprintf(
            "SELECT COUNT(*) FROM bidang WHERE %s",
            $semua ? "status <> 'dihapus'" : "status = 'perlu_verifikasi'"
        )),
        'akan_diubah' => count($akanDiubah),
        'baris_hak_dibuat' => count($akanDisisip),
        'menyertai_bukti' => $berbukti,
        'sudah_jenis_sungguhan' => (int) db_value(
            "SELECT COUNT(*) FROM bidang b JOIN hak h ON h.bidang_id = b.id
               JOIN jenis_hak jh ON jh.id = h.jenis_hak_id
              WHERE jh.kode NOT IN ($sementara)
                AND " . ($semua ? "b.status <> 'dihapus'" : "b.status = 'perlu_verifikasi'")
        ),
        'menurut_status_tipe' => array_map(
            static fn(array $r): string => $r['kode'] . ' x' . $r['n'],
            db_all("SELECT b.status, b.tipe, IFNULL(jh.kode, 'tanpa baris') AS kode, COUNT(*) n
                      FROM bidang b LEFT JOIN hak h ON h.bidang_id = b.id
                      LEFT JOIN jenis_hak jh ON jh.id = h.jenis_hak_id
                     GROUP BY b.status, b.tipe, jh.kode ORDER BY b.status, b.tipe, n DESC")
        ),
        'contoh' => array_slice(array_map(
            static fn(array $b): string => $b['kode'] . ' (' . $b['tipe'] . '): '
                . ($b['jenis_lama'] ?? 'tanpa baris hak') . ' -> HM',
            array_slice($baris, 0, 8)
        ), 0),
    ];
    if (!$terapkan || $baris === []) {
        return $hasil;
    }

    $cadangan = cadanganBlok('hak-milik', array_map(
        static fn(array $b): array => [
            'bidang_id' => (int) $b['bidang_id'],
            'kode' => $b['kode'],
            'hak_id' => $b['hak_id'] === null ? null : (int) $b['hak_id'],
            'jenis_lama' => $b['jenis_lama'],
            'catatan_lama' => $b['catatan'],
        ],
        $baris
    ));

    $namaJenis = [];
    foreach (db_all('SELECT kode, nama FROM jenis_hak') as $j) {
        $namaJenis[(string) $j['kode']] = (string) $j['nama'];
    }
    $terang = static function (array $b) use ($keterangan, $namaJenis): string {
        return str_replace(
            ['{lama}', '{status}'],
            [$b['jenis_lama'] === null ? 'tidak ada baris hak' : ($namaJenis[$b['jenis_lama']] ?? $b['jenis_lama']),
                (string) $b['status']],
            $keterangan
        );
    };

    db_transaksi(static function (PDO $connect) use ($akanDiubah, $akanDisisip, $hm, $terang): void {
        $ubah = $connect->prepare('UPDATE hak SET jenis_hak_id = ?, catatan = ? WHERE id = ?');
        foreach ($akanDiubah as $hakId => $b) {
            $ubah->execute([(int) $hm['id'], $terang($b), (int) $hakId]);
        }
        $sisip = $connect->prepare(
            'INSERT INTO hak (bidang_id, jenis_hak_id, catatan) VALUES (?, ?, ?)'
        );
        foreach ($akanDisisip as $bidangId => $b) {
            $sisip->execute([(int) $bidangId, (int) $hm['id'], $terang($b)]);
        }
    });

    audit(
        'ubah', 'bidang', 'hak-milik',
        sprintf('%d bidang diisi jenis haknya dengan Hak Milik; sasaran: %s. %d di antaranya masih menyimpan '
            . 'bukti tertulis (nomor sertifikat atau tautan Buku C) yang tidak disentuh, dan %d baris hak baru '
            . 'dibuat untuk bidang yang belum punya. %d bidang beranotasi petugas (gang, tanah desa, bengkok, '
            . 'masjid) dikecualikan. Jenis dan catatan lama tersimpan di %s.',
            count($akanDiubah) + count($akanDisisip), $hasil['sasaran'], $berbukti,
            count($akanDisisip), $disebabkanAnotasi, $cadangan),
        null, ['hak' => count($akanDiubah) + count($akanDisisip)]
    );
    $hasil['cadangan'] = $cadangan;
    return $hasil;
}

/**
 * Anotasi petugas pada lapisan `tn_nama` yang isinya bukan nama orang, dan jenis
 * hak yang disatakannya. `TANAH DESA`, `BENGKOK` (termasuk bengkok KADUS / KAUR /
 * BEKEL / LURAH) dan `BALAI` adalah tanah kas desa — miliknya pemerintah desa.
 * `GG` gang juga pengunya Hak Pakai, tapi hanya pada bidang yang tidak punya
 * nomornya sendiri: peta master menggambar gang sebagai titik, jadi labelnya bisa
 * jatuh di persil tetangga (lihat `jenisHakDariAnotasi()`).
 */
const ANOTASI_HAK = [
    'GG' => 'HP',
    'TANAH DESA' => 'KDH',
    'BENGKOK' => 'KDH',
    'BALAI' => 'KDH',
];

/**
 * Fasilitas umum yang jenis haknya jangan ditetapkan alat. Masjid dan mushola di
 * desa kebanyakan tanah wakaf, jalan dan saluran punya ceritanya sendiri. Bidang
 * begini tetap tidak boleh dijadikan Hak Milik oleh `hak-milik`, tapi di sini
 * hanya dilaporkan supaya petugas yang menentukan jenisnya.
 */
const ANOTASI_FASILITAS = [
    'MASJID', 'MUSHOLA', 'MUSHALLA', 'MUSALA', 'LANGGAR', 'MCK', 'GORO', 'SALURAN',
    'KUBUR', 'RTH', 'PARKIR', 'TELAGA', 'IRIGASI', 'JALAN',
];

/**
 * Cocokkan sebuah label dengan daftar kata anotasi; kata harus berdiri sendiri
 * supaya `GG` tidak menyambar nama yang kebetulan memuat gg. Kata panjang dicoba
 * lebih dulu: `BENGKOK KAUR KEUANGAN` anotasi bengkok, bukan coretan KAUR saja.
 *
 * @param list<string> $kata
 */
function kataAnotasi(string $teks, array $kata): ?string
{
    $isi = strtoupper(preg_replace('/\s+/', ' ', trim($teks)) ?? '');
    usort($kata, static fn(string $a, string $b): int => strcmp($b, $a));
    foreach ($kata as $k) {
        if (preg_match('/(?:^|[^A-Z])' . preg_quote($k, '/') . '(?:[^A-Z]|$)/', $isi) === 1) {
            return $k;
        }
    }
    return null;
}

/**
 * Bidang hidup yang poligonya ditumpungi anotasi petugas, beserta label nama
 * orang yang ikut jatuh di atasnya. Penempatan label sama seperti aturan nama:
 * yang mengenai beberapa bidang dihitung untuk yang paling kecil.
 *
 * Diukur 20 Okt 2026 atas 3.654 bidang hidup: 52 poligon beranotasi, 5 di
 * antaranya juga kebagian label nama orang — anotasi semacam itu bergeser dari
 * bidang tetangga dan tidak boleh membatalkan haknya.
 *
 * @return array<int, array{kode:string, blok:?string, tipe:string, status:string,
 *     luas_m2:?string, nib:?string, nis:?string, nomor_daftar:?string,
 *     bernomor:bool, anotasi:array<string, string>, nama:list<string>}>
 */
function anotasiPetugas(?string $berkas = null): array
{
    $kata = array_merge(array_keys(ANOTASI_HAK), ANOTASI_FASILITAS);
    $hidup = [];
    foreach (db_all(
        "SELECT b.id, b.kode, b.tipe, b.status, b.luas_m2, b.nib, b.nis, b.nomor_daftar,
                bl.kode AS blok
           FROM bidang b LEFT JOIN blok bl ON bl.id = b.blok_id
          WHERE b.status NOT IN ('dihapus', 'duplikat')"
    ) as $b) {
        $hidup[(int) $b['id']] = $b + [
            'anotasi' => [],
            'nama' => [],
            'bernomor' => ($b['nib'] ?? '') !== '' || ($b['nis'] ?? '') !== ''
                || ($b['nomor_daftar'] ?? '') !== '',
        ];
    }

    $penanda = db()->prepare(
        "SELECT id FROM bidang WHERE status NOT IN ('dihapus', 'duplikat')
           AND ST_Contains(geom, POINT(?, ?)) ORDER BY luas_m2 ASC LIMIT 1"
    );
    foreach (dxfTeks(masterDxf($berkas)) as $t) {
        if ($t['layer'] !== NAMA_LAPISAN) {
            continue;
        }
        $teks = strtoupper(preg_replace('/\s+/', ' ', trim((string) $t['teks'])) ?? '');
        if ($teks === '') {
            continue;
        }
        [$lon, $lat] = dxfKeWgs84((float) $t['x'], (float) $t['y']);
        $penanda->execute([(float) $lon, (float) $lat]);
        $id = $penanda->fetchColumn();
        if ($id === false) {
            continue;
        }
        $id = (int) $id;
        $k = kataAnotasi($teks, $kata);
        if ($k !== null) {
            $hidup[$id]['anotasi'][$k] = $teks;
        } elseif (namaLayak($teks)) {
            $hidup[$id]['nama'][] = $teks;
        }
    }
    return array_filter($hidup, static fn(array $b): bool => $b['anotasi'] !== []);
}

/**
 * Jenis hak yang dinyatakan anotasi pada sebuah bidang, atau null kalau anotasi
 * itu tidak cukup untuk memutuskan.
 *
 * `GG` diperlakukan berbeda dari bengkok. Peta master tidak pernah menggambar
 * gang sebagai poligon: dari 15 label `GG`/`TANAH GG` jatuh 13 di ruang terbuka
 * di antara persil dan hanya 2 yang mendarat di dalam poligon — `DXF-020100-2572`
 * (12.259 m2, tanpa satu nomor pun) dan `HM-128C` (2.048 m2, sudah tercatat
 * `nis 128C` + nomor daftar `02710`, bentuknya seluas persil biasa). Yang kedua
 * jelas bukan gang: persilnya bernomor, label gangsnya yang bergeser. Karena itu
 * `GG` hanya memutuskan bila bidang tidak punya nib, nis, atau nomor daftar dan
 * tidak kebagian label nama orang.
 *
 * @param array{anotasi:array<string, string>, nama:list<string>, bernomor:bool} $b
 */
function jenisHakDariAnotasi(array $b): ?string
{
    foreach (array_keys($b['anotasi']) as $k) {
        if (!isset(ANOTASI_HAK[$k])) {
            continue;
        }
        if ($k === 'GG' && ($b['bernomor'] || $b['nama'] !== [])) {
            continue;
        }
        return ANOTASI_HAK[$k];
    }
    return null;
}

/**
 * Betulkan jenis hak bidang yang poligonya ternyata beranotasi petugas: gang
 * menjadi Hak Pakai, tanah desa dan bengkok menjadi milik pemerintah desa, dan
 * penggunaan tanahnya diisi Fasilitas Umum. Lapisan anotasi ini tidak pernah
 * dibaca `hak-milik`, jadi seluruhnya sempat pindah ke Hak Milik.
 *
 * Bidang yang anotasi dan nama orang jatuh di poligon yang sama ditahan, begitu
 * pula yang sudah menyimpan bukti tertulis (nomor sertifikat, nomor hak pakai,
 * tautan Buku C). Baris `hak` yang jenisnya bukan Hak Milik atau jenis sementara
 * tidak disentuh — keputusan orang jangan ditimpa alat. Label `GG` di atas persil
 * yang punya nomor sendiri juga ditahan (`ditahan_gg_bergeser`).
 *
 * Perubahan yang dulu dibuat aturan ini tapi sekarang tidak lagi diputuskan anotasi
 * dipulihkan balik ke Hak Milik (lihat `jenisHakDariAnotasi()`), karena catatan baris
 * `hak`-nya masih menyebut perintah ini. Bidang yang jenis dan penggunaannya sudah
 * sesuai anotasi hanya dilaporkan sebagai `sudah_sesuai`, catatannya tidak ditulis
 * ulang, supaya menjalankan ulang tidak tampak seperti keputusan baru.
 *
 * @return array<string, mixed>
 */
function hakDariAnotasi(bool $terapkan = false, bool $semua = false, ?string $berkas = null): array
{
    $jenises = [];
    foreach (db_all('SELECT id, kode, nama FROM jenis_hak') as $j) {
        $jenises[(string) $j['kode']] = $j;
    }
    foreach (['HM', 'KDH', 'HP'] as $perlu) {
        if (!isset($jenises[$perlu])) {
            throw new RuntimeException("jenis_hak kode $perlu tidak ada — jalankan sql/01_skema.sql lebih dulu.");
        }
    }
    $fasilitas = db_one("SELECT id FROM jenis_penggunaan WHERE nama = 'Fasilitas Umum' LIMIT 1");

    $beranotasi = anotasiPetugas($berkas);
    $tulis = static fn(array $b, string $sebab): string => sprintf(
        '%-18s %-10s %-17s %9s m2  %-30s %s',
        $b['kode'], $b['blok'] ?? '-', $b['status'],
        number_format((float) $b['luas_m2'], 0, ',', '.'),
        implode(' / ', array_values($b['anotasi'])), $sebab
    );
    $sasaran = [];
    $rincian = [];
    $ditahan = ['nama' => [], 'bukti' => [], 'jenis_sudah_sungguhan' => [], 'fasilitas' => [],
        'status' => [], 'gg_bergeser' => []];
    foreach ($beranotasi as $id => $b) {
        $kode = jenisHakDariAnotasi($b);
        if ($kode === null) {
            if (isset($b['anotasi']['GG'])) {
                $ditahan['gg_bergeser'][] = $b['kode'];
                $rincian['gg_bergeser'][] = $tulis($b, $b['nama'] === []
                    ? 'punya nomor persil sendiri, label GG-nya yang bergeser'
                    : 'bersaing dengan nama ' . implode(', ', $b['nama']));
            } else {
                $ditahan['fasilitas'][] = $b['kode'];   // masjid/mushola/jalan: petugas yang menetapkan
                $rincian['fasilitas'][] = $tulis($b, 'jenisnya keputusan petugas');
            }
            continue;
        }
        if (!$semua && $b['status'] !== 'perlu_verifikasi') {
            $ditahan['status'][] = $b['kode'];
            $rincian['di_luar_sasaran'][] = $tulis($b, 'bawaan hanya perlu_verifikasi, pakai --semua');
            continue;
        }
        if ($b['nama'] !== []) {
            $ditahan['nama'][] = $b['kode'];
            $rincian['nama'][] = $tulis($b, 'juga kebagian nama ' . implode(', ', $b['nama']));
            continue;
        }
        $sasaran[(int) $id] = $b + ['kode_hak' => $kode];
    }

    // Baris yang dulu dituliskan perintah ini dan hari ini tidak lagi diputuskan
    // anotasi dikembalikan ke Hak Milik. Aturan `GG` diperketat 20 Okt 2026 sesudah
    // diaudit: label gang yang mendarat di persil bernomor bukan bukti bahwa tanahnya
    // gang, melainkan bukti bahwa labelnya bergeser — `HM-128C` (nis 128C, daftar
    // 02710) sempat dijadikan Hak Pakai karenanya. Jenis yang sudah digantikan
    // keputusan orang, dan yang masih menyimpan bukti tertulis, tidak disentuh.
    $dipulihkan = [];
    foreach (db_all(
        "SELECT b.id, b.kode, b.status, b.luas_m2, bl.kode AS blok,
                h.id AS hak_id, h.no_sertifikat, h.no_hak_pakai, h.buku_c_id,
                jh.kode AS jenis_kode
           FROM bidang b
           JOIN hak h         ON h.bidang_id = b.id
           JOIN jenis_hak jh  ON jh.id = h.jenis_hak_id
           LEFT JOIN blok bl  ON bl.id = b.blok_id
          WHERE h.catatan LIKE '%impor.php hak-anotasi%'
            AND b.status NOT IN ('dihapus', 'duplikat')
          ORDER BY b.kode"
    ) as $h) {
        $id = (int) $h['id'];
        $b = $beranotasi[$id] ?? null;
        if ($b !== null && jenisHakDariAnotasi($b) !== null) {
            continue;                                  // anotasi masih memutuskan
        }
        if ((string) $h['jenis_kode'] !== 'HP' && (string) $h['jenis_kode'] !== 'KDH') {
            continue;                                  // sudah diganti keputusan lain
        }
        if (($h['no_sertifikat'] ?? '') !== '' || ($h['no_hak_pakai'] ?? '') !== ''
            || $h['buku_c_id'] !== null) {
            continue;                                  // bukti tertulis jangan ditimpa
        }
        $dipulihkan[$id] = [
            'hak_id' => (int) $h['hak_id'], 'jenis_lama' => (string) $h['jenis_kode'],
        ] + ($b ?? [
            'kode' => $h['kode'], 'blok' => $h['blok'], 'status' => $h['status'],
            'luas_m2' => $h['luas_m2'], 'anotasi' => [],
        ]);
        $rincian['pulihkan'][] = $tulis($dipulihkan[$id], sprintf(
            '%s -> %s, penggunaan Fasilitas Umum dihapus',
            $dipulihkan[$id]['jenis_lama'], $jenises['HM']['nama']
        ));
    }

    $hakMenurutBidang = [];
    if ($sasaran !== []) {
        foreach (db_all(sprintf(
            "SELECT h.bidang_id, h.id, h.jenis_hak_id, h.catatan, h.no_sertifikat, h.no_hak_pakai, h.buku_c_id,
                    jh.kode AS jenis_kode
               FROM hak h LEFT JOIN jenis_hak jh ON jh.id = h.jenis_hak_id
              WHERE h.bidang_id IN (%s)",
            implode(',', array_map('intval', array_keys($sasaran)))
        )) as $h) {
            $hakMenurutBidang[(int) $h['bidang_id']] = $h;
        }
    }

    $akan = [];
    $sudahSesuai = [];
    foreach ($sasaran as $id => $b) {
        $h = $hakMenurutBidang[$id] ?? null;
        $lama = $h === null ? '' : (string) ($h['jenis_kode'] ?? '');
        if ($lama === $b['kode_hak'] && $fasilitas !== null
            && (int) db_value("SELECT COALESCE(jenis_penggunaan_id, 0) FROM bidang WHERE id = $id") === (int) $fasilitas['id']) {
            $sudahSesuai[] = $b['kode'];
            $rincian['sudah_sesuai'][] = $tulis($b, "sudah $lama, tidak diubah");
            continue;
        }
        if ($h !== null && $lama !== '' && $lama !== 'HM' && !in_array($lama, JENIS_HAK_SEMENTARA, true)) {
            $ditahan['jenis_sudah_sungguhan'][] = $b['kode'] . ' (' . $lama . ')';
            $rincian['jenis_sudah_sungguhan'][] = $tulis($b, "jenis $lama sudah keputusan orang, tidak ditimpa");
            continue;
        }
        if ($h !== null && (($h['no_sertifikat'] ?? '') !== '' || ($h['no_hak_pakai'] ?? '') !== ''
            || $h['buku_c_id'] !== null)) {
            $ditahan['bukti'][] = $b['kode'];
            $rincian['bukti'][] = $tulis($b, 'masih menyimpan nomor sertifikat / hak pakai / Buku C');
            continue;
        }
        $akan[$id] = $b + ['hak_id' => $h === null ? null : (int) $h['id'], 'jenis_lama' => $lama];
        $rincian['ubah'][] = $tulis($b, sprintf(
            '%s -> %s',
            $lama === '' ? 'tanpa baris hak' : $lama,
            $jenises[$b['kode_hak']]['nama']
        ));
    }

    $menurutJenis = [];
    $luas = [];
    foreach ($akan as $b) {
        $menurutJenis[$b['kode_hak']][] = $b['kode'];
        $luas[$b['kode_hak']] = ($luas[$b['kode_hak']] ?? 0.0) + (float) $b['luas_m2'];
    }

    $hasil = [
        'jenis' => 'hak-anotasi',
        'terapkan' => $terapkan,
        'sasaran' => $semua ? 'semua bidang hidup' : 'bidang perlu_verifikasi',
        'bidang_beranotasi' => count($beranotasi),
        'akan_diubah' => count($akan),
        'sudah_sesuai' => $sudahSesuai,
        'akan_dipulihkan' => count($dipulihkan),
        'dipulihkan' => array_map(
            static fn(array $b): string => $b['kode'] . ' [' . $b['jenis_lama'] . ']',
            array_values($dipulihkan)
        ),
        'menurut_jenis' => array_map(
            static fn(array $k): string => implode(', ', $k),
            $menurutJenis
        ),
        'luas_m2' => array_map(static fn(float $v): string => number_format($v, 0, ',', '.'), $luas),
        'ditahan_nama' => $ditahan['nama'],
        'ditahan_bukti' => $ditahan['bukti'],
        'jenis_sudah_sungguhan' => $ditahan['jenis_sudah_sungguhan'],
        'di_luar_sasaran' => $ditahan['status'],
        'fasilitas_dilaporkan' => $ditahan['fasilitas'],
        'ditahan_gg_bergeser' => $ditahan['gg_bergeser'],
        'contoh' => array_slice(array_map(
            static fn(array $b): string => $b['kode'] . ' [' . implode('+', array_keys($b['anotasi'])) . ']: '
                . ($b['jenis_lama'] === '' ? 'tanpa baris hak' : $b['jenis_lama']) . ' -> ' . $b['kode_hak'],
            array_slice(array_values($akan), 0, 10)
        ), 0),
        'rincian' => $rincian,
        'galat' => $fasilitas === null ? ['penggunaan Fasilitas Umum tidak ada di jenis_penggunaan'] : [],
    ];
    if (!$terapkan || ($akan === [] && $dipulihkan === []) || $fasilitas === null) {
        return $hasil;
    }

    $cadangan = cadanganBlok('hak-anotasi', array_merge(
        array_map(
            static fn(int $id, array $b): array => [
                'bidang_id' => $id,
                'kode' => $b['kode'],
                'hak_id' => $b['hak_id'],
                'jenis_lama' => $b['jenis_lama'],
                'catatan_lama' => $hakMenurutBidang[$id]['catatan'] ?? null,
                'penggunaan_lama' => db_value("SELECT jenis_penggunaan_id FROM bidang WHERE id = $id"),
                'anotasi' => array_values($b['anotasi']),
            ],
            array_keys($akan),
            $akan
        ),
        array_map(
            static fn(int $id, array $b): array => [
                'pulihkan' => true,
                'bidang_id' => $id,
                'kode' => $b['kode'],
                'hak_id' => $b['hak_id'],
                'jenis_lama' => $b['jenis_lama'],
                'penggunaan_lama' => db_value("SELECT jenis_penggunaan_id FROM bidang WHERE id = $id"),
                'anotasi' => array_values($b['anotasi']),
            ],
            array_keys($dipulihkan),
            $dipulihkan
        )
    ));

    $hari = date('Y-m-d');
    db_transaksi(static function (PDO $connect) use ($akan, $dipulihkan, $jenises, $fasilitas, $hari): void {
        $ubah = $connect->prepare('UPDATE hak SET jenis_hak_id = ?, catatan = ? WHERE id = ?');
        $sisip = $connect->prepare('INSERT INTO hak (bidang_id, jenis_hak_id, catatan) VALUES (?, ?, ?)');
        $pakai = $connect->prepare('UPDATE bidang SET jenis_penggunaan_id = ? WHERE id = ?');
        $lepas = $connect->prepare('UPDATE bidang SET jenis_penggunaan_id = NULL WHERE id = ? AND jenis_penggunaan_id = ?');
        foreach ($akan as $id => $b) {
            $catatan = sprintf(
                'Jenis %s ditetapkan dari anotasi "%s" pada lapisan tn_nama peta master oleh perintah '
                . 'impor.php hak-anotasi pada %s; sebelumnya %s. Bidang ini tidak dibaca sebagai tanah milik orang.',
                $jenises[$b['kode_hak']]['nama'],
                implode(' / ', array_values($b['anotasi'])),
                $hari,
                $b['jenis_lama'] === '' ? 'tidak ada baris hak' : $b['jenis_lama']
            );
            if ($b['hak_id'] === null) {
                $sisip->execute([$id, (int) $jenises[$b['kode_hak']]['id'], $catatan]);
            } else {
                $ubah->execute([(int) $jenises[$b['kode_hak']]['id'], $catatan, (int) $b['hak_id']]);
            }
            $pakai->execute([(int) $fasilitas['id'], $id]);
        }
        foreach ($dipulihkan as $id => $b) {
            $catatan = sprintf(
                'Jenis Hak Milik dipulihkan oleh perintah impor.php hak-anotasi pada %s: anotasi "%s" di atas '
                . 'poligon ini tidak lagi cukup untuk menetapkan jenis lain, %s yang ditulisnya sebelumnya '
                . 'dibatalkan. Persil ini tetap dibaca tanah milik orang.',
                $hari,
                $b['anotasi'] === [] ? 'tidak ada' : implode(' / ', array_values($b['anotasi'])),
                $b['jenis_lama']
            );
            $ubah->execute([(int) $jenises['HM']['id'], $catatan, $b['hak_id']]);
            $lepas->execute([$id, (int) $fasilitas['id']]);
        }
    });

    audit(
        'ubah', 'bidang', 'hak-anotasi',
        sprintf('%d bidang jenis haknya dibetulkan mengikuti anotasi petugas pada peta master (%s) dan %d yang dulu '
            . 'pindah karenanya dikembalikan ke Hak Milik; %d gang menjadi Hak Pakai, sisanya tanah desa/bengkok '
            . 'menjadi milik pemerintah desa, dan penggunaan tanahnya diisi Fasilitas Umum. %d bidang ditahan karena '
            . 'anotasi dan nama orang jatuh di poligon yang sama, %d karena masih menyimpan bukti tertulis, %d karena '
            . 'label GG-nya ternyata mendarat di persil bernomor sendiri. Jenis lama tersimpan di %s.',
            count($akan), $hasil['sasaran'], count($dipulihkan),
            count($menurutJenis['HP'] ?? []), count($ditahan['nama']), count($ditahan['bukti']),
            count($ditahan['gg_bergeser']), $cadangan),
        null, ['hak' => count($akan) + count($dipulihkan)]
    );
    $hasil['cadangan'] = $cadangan;
    return $hasil;
}

/**
 * Pasangan bidang yang menempati tempat yang sama persis, diukur dari irisan
 * wilayahnya bukan dari ST_Equals.
 *
 * `tandaiBidangDuplikat` membandingkan dengan ST_Equals dan aturan itu menolak
 * cincin yang titiknya sama tapi dimulai dari titik lain atau berputar arah
 * berbeda — persis bentuk kembaran warisan GeoJSON sumber. Penggantinya di sini:
 * irisan dua poligon harus >= 99% dari luas masing-masing dan selisih luas
 * tercatatnya <= 1 m2. Luas wilayah dihitung dengan ST_Area(geom) di kedua sisi
 * karena geometri disimpan tanpa SRID sehingga satuannya derajat persegi;
 * membandingkannya dengan kolom luas_m2 (meter persegi) membuat rasio ini selalu 0.
 *
 * Diukur 20 Okt 2026 atas 4.043 bidang: 403 pasangan lolos dan hanya 4 yang kedua
 * duanya masih hidup — sisanya sudah lebih dulu ditandai duplikat. Batas 99% itulah
 * yang menahan kulit penutup, poligon besar menjepit beberapa persil anak
 * (DXF-020100-0057 seluas 31.991 m2 menelan HM-114C + HM-164A + HM-1559D); irisannya
 * terhadap tiap anak hanya sebagian kecil luas anak itu, jadi ia bukan kembar dan
 * memang tidak boleh ditandai. Bidang tanpa luas tercatat tidak ikut terpiksa —
 * selisih luas atas NULL tidak pernah bernilai benar. Indeks spasial diabaikan
 * agar plan tidak pernah menyentuh jalur yang melempar 1207 (MDEV-26123).
 *
 * @return list<array<string, mixed>> satu baris per pasangan, kolom berafkhir _a/_b
 */
function pasanganKembarTepat(): array
{
    return db_all(
        "SELECT a.id AS id_a, a.kode AS kode_a, a.status AS st_a, a.luas_m2 AS luas_a,
                b.id AS id_b, b.kode AS kode_b, b.status AS st_b, b.luas_m2 AS luas_b,
                ROUND(ST_Area(ST_Intersection(a.geom, b.geom)) / NULLIF(ST_Area(a.geom), 0), 4) AS irisan_a,
                ROUND(ST_Area(ST_Intersection(a.geom, b.geom)) / NULLIF(ST_Area(b.geom), 0), 4) AS irisan_b
           FROM bidang a
           JOIN bidang b IGNORE INDEX (spn_bidang_geom)
             ON b.blok_id = a.blok_id AND b.id > a.id AND b.status <> 'dihapus'
          WHERE a.status <> 'dihapus'
            AND MBRIntersects(a.geom, b.geom)
            AND ST_Area(a.geom) > 0 AND ST_Area(b.geom) > 0
            AND ST_Area(ST_Intersection(a.geom, b.geom)) / NULLIF(ST_Area(a.geom), 0) >= 0.99
            AND ST_Area(ST_Intersection(a.geom, b.geom)) / NULLIF(ST_Area(b.geom), 0) >= 0.99
            AND ABS(a.luas_m2 - b.luas_m2) <= 1
          ORDER BY a.kode, b.kode"
    );
}

/**
 * Tandai kembaran geometri yang lolos dari ST_Equals sebagai duplikat.
 *
 * Satu grup menyisakan satu bidang, sisanya ditandai — sama seperti
 * `impor.php duplikat`, dan dengan jalur tulisan yang sama pula: status plus
 * catatan, geometri tidak disentuh, seluruhnya tersimpan di berkas cadangan lebih
 * dulu. Yang disisakan: yang berstatus aktif, lalu yang datanya paling lengkap
 * (nib, nis, nomor daftar, nama pemegang), lalu kode HM- di depan, lalu id
 * terkecil. Dengan aturan itu ketiga pasangan perlu_verifikasi memilih HM-134C,
 * HM-1830D dan DXF-020100-0197, yaitu yang sudah bersandar di NIS atau nomor
 * daftar sementara kembarannya masih kosong melompong.
 *
 * Grup yang dua-duanya berstatus aktif ditahan dan hanya dilaporkan. `aktif`
 * artinya bidang itu sudah dibaca orang, bukan hasil coran peta, dan memilih
 * salah satunya tanpa melihat buku adalah keputusan petugas — apalagi kalau
 * keduanya tercatat dengan nib yang sama.
 *
 * @return array<string, mixed>
 */
function kembarTepatDuplikat(bool $terapkan = false): array
{
    $semua = pasanganKembarTepat();

    $pasangan = [];
    $sudahTandai = 0;
    foreach ($semua as $p) {
        if ($p['st_a'] === 'duplikat' || $p['st_b'] === 'duplikat') {
            $sudahTandai++;
            continue;
        }
        $pasangan[] = ['ai' => (int) $p['id_a'], 'bi' => (int) $p['id_b']];
    }

    $baris = db_all(
        'SELECT b.id, b.kode, b.tipe, b.status, b.nib, b.nis, b.nomor_daftar, b.luas_m2,
                b.catatan, b.sumber, bl.kode AS blok, h.pemegang_nama
           FROM bidang b LEFT JOIN blok bl ON bl.id = b.blok_id
           LEFT JOIN hak h ON h.bidang_id = b.id
          WHERE b.status NOT IN ("dihapus", "duplikat")
          ORDER BY b.kode'
    );
    $menurutId = [];
    foreach ($baris as $b) {
        $menurutId[(int) $b['id']] = $b;
    }
    $grup = grupkanPasangan($pasangan, $menurutId);

    $isi = static fn(?string $nilai): int => ($nilai === null || $nilai === '') ? 0 : 1;
    $lengkap = static fn(array $b): int => 8 * $isi($b['nib']) + 4 * $isi($b['nis'])
        + 2 * $isi($b['nomor_daftar']) + 1 * $isi($b['pemegang_nama']);

    $korban = [];
    $ditahan = [];
    foreach ($grup as $anggota) {
        $sudahAktif = array_values(array_filter(
            $anggota, static fn(array $b): bool => $b['status'] === 'aktif'
        ));
        if (count($sudahAktif) > 1) {
            $ditahan[] = $anggota;
            continue;
        }
        usort($anggota, static fn(array $a, array $x): int =>
            [$a['status'] !== 'aktif', -$lengkap($a), strpos((string) $a['kode'], 'HM-') === 0 ? 0 : 1, (int) $a['id']]
                <=> [$x['status'] !== 'aktif', -$lengkap($x), strpos((string) $x['kode'], 'HM-') === 0 ? 0 : 1, (int) $x['id']]);
        $hidup = $anggota[0];
        foreach (array_slice($anggota, 1) as $b) {
            $korban[] = [
                'id' => (int) $b['id'],
                'kode' => (string) $b['kode'],
                'status' => (string) $b['status'],
                'catatan' => (string) ($b['catatan'] ?? ''),
                'blok' => (string) ($b['blok'] ?? 'tanpa blok'),
                'luas' => $b['luas_m2'] === null ? null : (float) $b['luas_m2'],
                'identitas' => trim(($b['nib'] ? 'nib ' . $b['nib'] . ' ' : '')
                    . ($b['nis'] ? 'nis ' . $b['nis'] . ' ' : '')
                    . ($b['nomor_daftar'] ? 'daftar ' . $b['nomor_daftar'] . ' ' : '')
                    . ((string) ($b['pemegang_nama'] ?? '') !== '' ? 'atas nama ' . $b['pemegang_nama'] : '')),
                'hidup' => (string) $hidup['kode'],
                'hidup_id' => (int) $hidup['id'],
                'hidup_identitas' => trim(($hidup['nib'] ? 'nib ' . $hidup['nib'] . ' ' : '')
                    . ($hidup['nis'] ? 'nis ' . $hidup['nis'] . ' ' : '')
                    . ($hidup['nomor_daftar'] ? 'daftar ' . $hidup['nomor_daftar'] . ' ' : '')
                    . ((string) ($hidup['pemegang_nama'] ?? '') !== '' ? 'atas nama ' . $hidup['pemegang_nama'] : '')),
                'sebanyak' => count($anggota),
            ];
        }
    }

    $luasGanda = 0.0;
    foreach ($korban as $b) {
        $luasGanda += (float) $b['luas'];
    }

    printf("%d pasangan saling menelan penuh dengan luas sama, %d di antaranya dua-duanya masih hidup\n",
        count($semua), count($pasangan));
    printf("%d grup kembar tepat, %d bidang akan ditandai duplikat (luas ganda %s m2)\n",
        count($grup) - count($ditahan), count($korban), number_format($luasGanda, 0, ',', '.'));
    echo "\n" . str_pad('DITANDAI', 20) . str_pad('DIBIARKAN HIDUP', 20) . str_pad('BLOK', 12) . "IDENTITAS YANG TINGGAL\n";
    foreach ($korban as $b) {
        echo '   ' . str_pad($b['kode'], 20) . str_pad($b['hidup'], 20) . str_pad($b['blok'], 12)
            . ($b['hidup_identitas'] === '' ? '(tidak ada)' : $b['hidup_identitas']) . "\n";
        echo '     yang ditinggalkan di ' . $b['kode'] . ': '
            . ($b['identitas'] === '' ? 'bidang ini tidak punya identitas apa pun' : $b['identitas']) . "\n";
    }

    $galat = [];
    if ($ditahan !== []) {
        echo "\nDITAHAN, kedua kembarannya sama-sama berstatus aktif (" . count($ditahan) . " grup):\n";
        foreach ($ditahan as $g) {
            $tulis = [];
            foreach ($g as $b) {
                $tulis[] = $b['kode'] . ' [nib ' . ($b['nib'] ?: '-') . ', ' . ($b['pemegang_nama'] ?: 'tanpa nama')
                    . ', ' . number_format((float) $b['luas_m2'], 0, ',', '.') . ' m2]';
            }
            $barisDitahan = implode(' kembar dengan ', $tulis);
            echo '   ' . $barisDitahan . "\n";
            $galat[] = $barisDitahan . ' — dua-duanya aktif, pilih sendiri yang mana lalu '
                . 'jalankan impor.php duplikat atau ubah statusnya manual';
        }
    }

    $contoh = [];
    foreach ($korban as $b) {
        $contoh[] = $b['kode'] . ' -> duplikat, ' . $b['hidup'] . ' tetap hidup';
    }

    if (!$terapkan) {
        return [
            'jenis' => 'kembar-tepat',
            'terapkan' => false,
            'pasangan' => count($semua),
            'pasangan_hidup' => count($pasangan),
            'sudah_duplikat' => $sudahTandai,
            'grup' => count($grup) - count($ditahan),
            'akan_tandai' => count($korban),
            'ditandai' => 0,
            'ditahan' => count($ditahan),
            'luas_ganda_m2' => round($luasGanda, 2),
            'contoh' => $contoh,
            'galat' => $galat,
        ];
    }

    $cadangan = cadanganBlok('kembar-tepat-bidang', [
        'waktu' => date('c'),
        'perintah' => 'php tools/impor.php kembar-tepat --terapkan',
        'pembanding' => 'irisan >= 99% luas masing-masing dan selisih luas <= 1 m2, '
            . 'atas bidang yang belum berstatus duplikat',
        'pasangan' => count($semua),
        'pasangan_hidup' => count($pasangan),
        'ditahan' => array_map(static fn(array $g): array => array_map(
            static fn(array $b): array => ['kode' => $b['kode'], 'nib' => $b['nib'], 'status' => $b['status']],
            $g
        ), $ditahan),
        'bidang' => $korban,
    ]);

    $hari = date('Y-m-d');
    db_transaksi(static function () use ($korban, $hari): void {
        foreach ($korban as $b) {
            $sebab = 'Poligon menempati tempat yang sama persis dengan ' . $b['hidup']
                . ' (id ' . $b['hidup_id'] . ') walau urutan titiknya berbeda; ditandai ' . $hari
                . ' oleh perintah impor.php kembar-tepat. Bidang yang hidup menyimpan datanya: '
                . ($b['hidup_identitas'] === '' ? 'belum ada' : $b['hidup_identitas']) . '.';
            db_exec(
                'UPDATE bidang SET status = ?, catatan = ? WHERE id = ?',
                ['duplikat', $b['catatan'] === '' ? $sebab : $b['catatan'] . ' | ' . $sebab, $b['id']]
            );
        }
    });

    $perBlok = [];
    foreach ($korban as $b) {
        $perBlok[$b['blok']][] = $b['kode'];
    }
    ksort($perBlok, SORT_NATURAL);
    foreach ($perBlok as $blok => $daftar) {
        audit(
            'ubah',
            'bidang',
            $blok,
            count($daftar) . ' bidang di ' . $blok . ' ditandai duplikat karena poligonnya menempati tempat '
                . 'yang sama persis dengan bidang yang dibiarkan hidup, walau ST_Equals tidak menangkapnya. '
                . 'Cadangan: ' . $cadangan,
            ['status' => 'aktif atau perlu_verifikasi'],
            ['status' => 'duplikat', 'kode' => $daftar]
        );
    }

    printf("\n%d bidang ditandai duplikat. Cadangan: %s\n", count($korban), $cadangan);

    return [
        'jenis' => 'kembar-tepat',
        'terapkan' => true,
        'pasangan' => count($semua),
        'pasangan_hidup' => count($pasangan),
        'sudah_duplikat' => $sudahTandai,
        'grup' => count($grup) - count($ditahan),
        'ditandai' => count($korban),
        'ditahan' => count($ditahan),
        'luas_ganda_m2' => round($luasGanda, 2),
        'cadangan' => $cadangan,
        'galat' => $galat,
    ];
}

// ---------------------------------------------------------------------------
// 18. Persil yang tertinggal di peta master
// ---------------------------------------------------------------------------

/** Lapisan persil MASTER.dxf dan prefiks kode untuk bidang baru dari peta itu.
 *  `HM` ditambahkan 20 Okt 2026 sesudah diukur: dari 77 ringnya 74 sudah punya bidang,
 *  jadi hanya 3 persil yang benar-benar tertinggal — dan tidak ada satu pun yang bisa
 *  masuk tanpa melonggarkan aturan celah maupun tumpang tindih. */
const DXF_PERSIL_LAPISAN = [
    'BIDANG HM' => ['tipe' => 'tanah', 'kode' => 'DXF-BIDANGHM'],
    '020100' => ['tipe' => 'lain', 'kode' => 'DXF-020100'],
    'HM' => ['tipe' => 'tanah', 'kode' => 'DXF-HM'],
];
/** Celah ujung maksimum yang masih dianggap cincin tertutup. */
const DXF_PERSIL_CELAH_M = 0.01;
/** Di bawah ini bukan sebidang tanah lagi. */
const DXF_PERSIL_MIN_M2 = 20.0;
/** Titik DXF yang lebih dekat ke titik nol affine daripada ini = coretan, bukan tanah. */
const DXF_PERSIL_ASAL_M = 1000.0;
/** Bagian dari luas calon yang boleh menumpang bidang lama. */
const DXF_PERSIL_TUMPANG = 0.02;
/** Di bawah ini dua poligon dianggap berbagi tepi, bukan sekadar berdekatan. */
const DXF_PERSIL_TETANGGA_M = 0.5;
/** Sumber garis blok yang boleh tumbuh menelan anggota baru lewat tetangga tepi. */
const BLOK_GABUNGAN_ANGGOTA = 'gabungan bidang anggota';
/** Lebar sel kisi uji titik-dalam-poligon, derajat (~55 m). */
const KISI_SEL = 0.0005;

/**
 * Persil di MASTER.dxf yang belum masuk basis data.
 *
 * `dxfPoligon()` hanya menganggap sebuah garis poligon sebagai poligon kalau
 * bendera "closed" di berkasnya diset. Di lapisan `020100` Digitator menggambar
 * cincinnya lengkap tapi benderanya tidak diset: 5.305 garis terbuka, dan 1.892
 * di antaranya ujungnya bertemu dalam <= 1 cm (jarak antara = nol koma nol).
 * Cincin semacam itu secara geometri sudah tertutup, hanya tidak ditandai, jadi
 * digitasi GeoJSON tidak pernah mengambilnya dan basis data kehilangan 435 persil
 * (31,1 ha, semuanya membawa label nama/NIS di dalamnya).
 *
 * Aturan penerimaan dibuat sesempit mungkin supaya tidak ada geometri karangan:
 *   - hanya lapisan persil `BIDANG HM` dan `020100`;
 *   - cincin tertutup bendera ATAU garis terbuka yang ujungnya berjarak <= $celahM;
 *   - dibuang kalau titik pertamanya di dekat titik nol affine (coretan, bukan tanah);
 *   - dibuang kalau titik tengahnya atau salah satu sudutnya jatuh di dalam bidang
 *     yang sudah ada (itu garis ganda di atas persil yang sama, bukan persil baru);
 *   - dibuang kalau irisan sebenarnya dengan bidang mana pun >= $tumpang bagian dari
 *     luasnya (ST_Intersection, bukan kotak pembatas);
 *   - calon yang saling menumpang dikurangi, yang disimpan yang paling luas.
 *
 * Seleksi tidak pernah menebak nama, nomor, atau jenis hak: bidang baru masuk
 * sebagai `perlu_verifikasi` dengan penanda `BLM`, lalu `impor.php nama`, `nib`,
 * `nomor-daftar` dan `hak-milik` yang mengisinya seperti biasa.
 *
 * Blok dicari dari titik tengah yang jatuh di dalam garis luar blok, dan kalau itu gagal
 * dari tetangga tepi pada blok yang garisnya memang gabungan anggotanya. Kalau dua-duanya
 * gagal, calon ditahan — kecuali `$pasangTanpaBlok`: poligonya tetap dipasang
 * dengan `blok_id` NULL dan garis blok terdekat ditulis di catatannya sebagai petunjuk.
 * Blok tidak pernah dikarang dari kedekatan.
 *
 * `$longgar` melewati uji "ada sudut jatuh di dalam bidang lama". Uji itu murah tetapi
 * terlalu keras untuk cincin yang baru ditutup dari celah sampai 1 m: ujungnya sering
 * menyentuh tepi/sudut tetangga tanpa irisan luas sama sekali (terukur 31 Okt 2026:
 * 137 dari 170 calon beririsan 0% tetapi tertolak olehnya). Titik tengah dan irisan
 * sebenarnya tetap wajib lolos, jadi duplikat tetap tersaring.
 *
 * @return array<string, mixed>
 */
function persilDariDxf(bool $terapkan = false, float $celahM = DXF_PERSIL_CELAH_M,
    float $minimumM2 = DXF_PERSIL_MIN_M2, ?string $berkas = null, bool $pasangTanpaBlok = false,
    bool $longgar = false): array
{
    $dxf = masterDxf($berkas);

    // ---- kumpulkan cincin ----------------------------------------------------
    $cincin = [];
    foreach (dxfPoligon($dxf) as $p) {
        $layer = (string) $p['layer'];
        if (!isset(DXF_PERSIL_LAPISAN[$layer]) || count($p['titik']) < 3) {
            continue;
        }
        $awal = $p['titik'][0];
        $akhir = $p['titik'][count($p['titik']) - 1];
        $celah = hypot($awal[0] - $akhir[0], $awal[1] - $akhir[1]);
        if (!$p['tertutup'] && $celah > $celahM) {
            continue;
        }
        if (max(array_map('abs', $awal)) < DXF_PERSIL_ASAL_M) {
            continue;
        }
        $luas = abs(poligonLuas($p['titik']));
        if ($luas < $minimumM2) {
            continue;
        }
        $cincin[] = ['layer' => $layer, 'titik' => $p['titik'], 'luas' => $luas,
            'celah' => $p['tertutup'] ? 0.0 : $celah];
    }

    // ---- geometri yang sudah ada di basis data -------------------------------
    $sudah = [];
    foreach (db_all("SELECT id, kode, ST_AsText(geom) AS wkt FROM bidang WHERE status <> 'dihapus' AND geom IS NOT NULL") as $b) {
        $ring = wktCincin((string) $b['wkt']);
        if (count($ring) < 3) {
            continue;
        }
        $sudah[(int) $b['id']] = ['id' => (int) $b['id'], 'kode' => $b['kode'], 'ring' => $ring]
            + wktWilayah((string) $b['wkt']);
    }
    $kisi = kisiBangun($sudah);

    $calon = [];
    $tercakup = 0;
    foreach ($cincin as $c) {
        $wkt = wktRing(array_map(static fn(array $t): array => dxfKeWgs84($t[0], $t[1]), $c['titik']));
        if ($wkt === null) {
            continue;   // cincinnya terlalu tipis: titik berulang atau kurang dari tiga sudut
        }
        $wgs = 'POLYGON((' . $wkt . '))';
        $pusat = cincinPusatWgs($c['titik']);
        // Uji murah lebih dulu: titik tengah atau sudut yang jatuh di dalam bidang
        // lama berarti cincin ini cuma garis ganda. Dengan $longgar hanya titik tengah
        // yang diperiksa; irisan luas sebenarnya di bawah tetap menyaring duplikat.
        $menumpang = tumpukKisi($pusat, $sudah, $kisi) !== [];
        if (!$longgar && !$menumpang) {
            foreach ($c['titik'] as $u) {
                if (tumpukKisi(dxfKeWgs84($u[0], $u[1]), $sudah, $kisi) !== []) {
                    $menumpang = true;
                    break;
                }
            }
        }
        if ($menumpang) {
            $tercakup++;
            continue;
        }
        $calon[] = $c + ['wkt' => $wgs, 'pusat' => $pusat] + wktWilayah($wgs);
    }

    // ---- uji irisan sebenarnya, lalu buang calon yang saling menumpuk --------
    $susun = [];
    $dibuangTumpangan = 0;
    foreach ($calon as $k => $c) {
        $tumpang = db_all(
            'SELECT b.kode FROM bidang b
              WHERE b.status <> \'dihapus\' AND b.geom IS NOT NULL
                AND MBRIntersects(b.geom, ST_GeomFromText(?, 4326))
                AND ST_Area(ST_Intersection(b.geom, ST_GeomFromText(?, 4326)))
                    >= ? * ST_Area(ST_GeomFromText(?, 4326))
              LIMIT 1',
            [$c['wkt'], $c['wkt'], DXF_PERSIL_TUMPANG, $c['wkt']]
        );
        if ($tumpang !== []) {
            $dibuangTumpangan++;
            continue;
        }
        $susun[] = $c + ['nomor' => $k];
    }
    usort($susun, static fn(array $a, array $b): int => $b['luas'] <=> $a['luas']);

    $dipilih = [];
    $kembar = 0;
    foreach ($susun as $c) {
        foreach ($dipilih as $d) {
            if (cincinSalingTumpuk($c, $d)) {
                $kembar++;
                continue 2;
            }
        }
        $dipilih[] = $c;
    }

    // ---- blok tujuan ---------------------------------------------------------
    $blok = [];
    foreach (db_all('SELECT id, kode, sumber_perkiraan, ST_AsText(geom) AS wkt FROM blok WHERE geom IS NOT NULL') as $bl) {
        // Semua bagiannya, bukan hanya yang terbesar: blok yang garis luarnya gabungan
        // bidang anggotanya terpecah menjadi ratusan kepul, dan titik tengah calon bisa
        // jatuh di kepul mana pun.
        $bagian = wktBagian((string) $bl['wkt']);
        if ($bagian !== []) {
            $blok[] = ['id' => (int) $bl['id'], 'kode' => (string) $bl['kode'],
                'sumber' => (string) ($bl['sumber_perkiraan'] ?? ''), 'wkt' => (string) $bl['wkt'],
                'bagian' => $bagian]
                + wktWilayah((string) $bl['wkt']);
        }
    }
    $perCalon = [];
    $tanpaBlok = [];
    foreach ($dipilih as $c) {
        $kodeBlok = null;
        $idBlok = null;
        foreach ($blok as $bl) {
            if (titikDiDalamBagian($c['pusat'], $bl['bagian'])) {
                $kodeBlok = $bl['kode'];
                $idBlok = $bl['id'];
                break;
            }
        }
        if ($idBlok !== null) {
            $perCalon[] = $c + ['blok_id' => $idBlok, 'blok' => $kodeBlok,
                'blok_alasan' => 'titik tengahnya di dalam garis luar ' . $kodeBlok];
            continue;
        }
        $c = calonDariTetanggaTepi($c, $blok);
        if (isset($c['blok_id'])) {
            $perCalon[] = $c;
        } else {
            $tanpaBlok[] = $c;
        }
    }

    // `--tanpa-blok`: geometri yang sah tetap dipasang walaupun bloknya tidak bisa
    // ditentukan. Blok TIDAK pernah dikarang: barisnya masuk dengan blok_id NULL dan
    // catatan yang menyebut garis blok terdekat, supaya petugas yang menetapkannya.
    $dipasangTanpaBlok = 0;
    if ($pasangTanpaBlok && $tanpaBlok !== []) {
        foreach ($tanpaBlok as $c) {
            $dekat = garisBlokTerdekat($c['pusat'], $blok);
            $perCalon[] = $c + ['blok_id' => null, 'blok' => null,
                'blok_alasan' => 'blok belum ditentukan: ' . (string) ($c['alasan'] ?? 'di luar semua garis blok')
                    . ($dekat['kode'] === null ? '' : '; garis blok terdekat ' . $dekat['kode']
                        . ' pada ' . number_format($dekat['jarak_m'], 1, ',', '.') . ' m')];
        }
        $dipasangTanpaBlok = count($tanpaBlok);
        $tanpaBlok = [];
    }

    // Calon yang ditahan dilaporkan penuh — termasuk setelah --terapkan — karena
    // daftar inilah yang menunggu garis bloknya diperlebar.
    $laporTanpaBlok = static fn(): array => array_map(static fn(array $c): array => [
        'lapisan' => $c['layer'], 'luas_m2' => round($c['luas'], 1), 'pusat' => $c['pusat'],
        'wkt' => $c['wkt'], 'alasan' => (string) ($c['alasan'] ?? 'titik tengahnya di luar semua garis blok'),
    ], $tanpaBlok);

    // nomor kode melanjutkan daftar tertinggi per lapisan
    $nomor = [];
    foreach (DXF_PERSIL_LAPISAN as $layer => $konfig) {
        $tertinggi = 0;
        foreach (db_all('SELECT kode FROM bidang WHERE kode LIKE ?', [$konfig['kode'] . '-%']) as $b) {
            if (preg_match('/-(\d+)$/', (string) $b['kode'], $m) === 1) {
                $tertinggi = max($tertinggi, (int) $m[1]);
            }
        }
        $nomor[$layer] = $tertinggi;
    }
    foreach ($perCalon as &$c) {
        $nomor[$c['layer']]++;
        $c['kode_baru'] = DXF_PERSIL_LAPISAN[$c['layer']]['kode'] . '-' . sprintf('%04d', $nomor[$c['layer']]);
    }
    unset($c);

    $luasTotal = array_sum(array_column($perCalon, 'luas'));
    $hasil = [
        'jenis' => 'dxf-persil',
        'cincin_dibaca' => count($cincin),
        'sudah_tercakup' => $tercakup,
        'dibuang_bertumpuk' => $dibuangTumpangan,
        'kembar_diantara_calon' => $kembar,
        'calon' => count($perCalon),
        'calon_tanpa_blok' => count($tanpaBlok),
        'dipasang_tanpa_blok' => $dipasangTanpaBlok,
        'luas_m2' => round($luasTotal, 1),
        'celah_m' => $celahM,
        'minimum_m2' => $minimumM2,
        'terapkan' => $terapkan,
    ];
    if (!$terapkan) {
        $hasil['contoh'] = array_slice(array_map(static fn(array $c): array => [
            'kode' => $c['kode_baru'], 'lapisan' => $c['layer'], 'blok' => $c['blok'],
            'blok_alasan' => (string) $c['blok_alasan'],
            'luas_m2' => round($c['luas'], 1), 'pusat' => array_map(
                static fn(float $v): float => round($v, 6), $c['pusat']),
        ], $perCalon), 0, 25);
        $hasil['tanpa_blok'] = $laporTanpaBlok();

        return $hasil;
    }

    $cadangan = cadanganBlok('dxf-persil', [
        'waktu' => date('c'),
        'perintah' => 'php tools/impor.php dxf-persil' . ($pasangTanpaBlok ? ' --tanpa-blok' : '')
            . ($longgar ? ' --longgar' : '') . ' --terapkan',
        'berkas' => $dxf,
        'aturan' => 'celah ujung <= ' . $celahM . ' m, luas >= ' . $minimumM2 . ' m2, irisan >= '
            . DXF_PERSIL_TUMPANG . ' bagian dibuang; '
            . ($longgar
                ? 'uji sudut dilewati (--longgar), titik tengah dan irisan tetap wajib lolos; '
                : 'titik tengah atau sudut di dalam bidang lama dibuang; ')
            . 'blok dari titik tengah dalam garis luar, '
            . 'kalau gagal baru dari tetangga tepi <= ' . DXF_PERSIL_TETANGGA_M . ' m pada blok '
            . 'bergabungan anggota; ' . ($pasangTanpaBlok
                ? 'yang masih gagal dipasang dengan blok_id NULL (--tanpa-blok)'
                : 'yang masih gagal ditahan'),
        'dijumlah' => ['cincin' => count($cincin), 'tercakup' => $tercakup,
            'bertumpuk' => $dibuangTumpangan, 'kembar' => $kembar, 'diisi' => count($perCalon)],
        'bidang' => array_map(static fn(array $c): array => [
            'kode' => $c['kode_baru'], 'lapisan' => $c['layer'], 'blok' => $c['blok'],
            'blok_alasan' => (string) $c['blok_alasan'],
            'luas_m2' => round($c['luas'], 2), 'pusat' => $c['pusat'],
        ], $perCalon),
    ]);

    // Catatan yang menjelaskan dari mana bloknya datang — atau justru mengapa tidak.
    $catatanBlok = static function (array $c): string {
        if ($c['blok'] === null) {
            return ' Bloknya sengaja dibiarkan kosong: ' . $c['blok_alasan']
                . '. Tetapkan oleh petugas dengan `impor.php ubah-blok <nomor blok> '
                . $c['kode_baru'] . ' --terapkan`.';
        }
        if (str_starts_with((string) $c['blok_alasan'], 'titik tengah')) {
            return '';
        }

        return ' Bloknya bukan dari garis luar: ' . $c['blok_alasan']
            . ', sehingga garis ' . $c['blok'] . ' perlu dibangun ulang dengan'
            . ' `impor.php kira ' . preg_replace('/\D/', '', (string) $c['blok']) . ' --tepat --terapkan`.';
    };

    $pdo = db();
    $sisip = $pdo->prepare(
        'INSERT INTO bidang (kode, tipe, blok_id, luas_m2, status, sumber, catatan, geom, pusat_lon, pusat_lat)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?)'
    );
    $sisipHak = $pdo->prepare('INSERT INTO hak (bidang_id, jenis_hak_id, catatan) VALUES (?, ?, ?)');
    $idJenisHak = [];
    foreach (db_all('SELECT kode, id FROM jenis_hak') as $baris) {
        $idJenisHak[(string) $baris['kode']] = (int) $baris['id'];
    }
    $idBlm = $idJenisHak[JENIS_HAK_DEFAULT] ?? null;
    if ($idBlm === null) {
        throw new RuntimeException('Master jenis_hak kode "' . JENIS_HAK_DEFAULT . '" tidak ada. Jalankan sql/01_skema.sql.');
    }
    $hari = date('Y-m-d');

    db_transaksi(static function (PDO $connect) use ($sisip, $sisipHak, $perCalon, $idBlm, $hari, $catatanBlok): void {
        foreach ($perCalon as $c) {
            $sisip->execute([
                $c['kode_baru'],
                DXF_PERSIL_LAPISAN[$c['layer']]['tipe'],
                $c['blok_id'],
                round($c['luas'], 2),
                'perlu_verifikasi',
                $c['layer'],
                'Cincin MASTER.dxf lapis ' . $c['layer'] . ' yang bendera tertutupnya tidak diset '
                    . '(celah ujung ' . number_format($c['celah'], 3, ',', '.') . ' m); dipasang ' . $hari
                    . ' oleh perintah impor.php dxf-persil.' . $catatanBlok($c),
                $c['wkt'],
                $c['pusat'][0],
                $c['pusat'][1],
            ]);
            $sisipHak->execute([
                (int) $connect->lastInsertId(),
                $idBlm,
                'Bidang hasil penutupan celah ujung peta master; jenis hak belum tercatat.',
            ]);
        }
    });

    $perBlok = [];
    foreach ($perCalon as $c) {
        $perBlok[$c['blok'] ?? 'BLOK belum ditentukan'][] = $c['kode_baru'];
    }
    ksort($perBlok, SORT_NATURAL);
    $kunciBlok = static fn(array $c): string => (string) ($c['blok'] ?? 'BLOK belum ditentukan');
    foreach ($perBlok as $kodeBlok => $daftar) {
        $luas = array_sum(array_column(array_filter($perCalon,
            static fn(array $c): bool => $kunciBlok($c) === $kodeBlok), 'luas'));
        audit(
            'impor',
            'bidang',
            $kodeBlok,
            count($daftar) . ' bidang ' . ($kodeBlok === 'BLOK belum ditentukan'
                ? 'yang bloknya tidak bisa ditentukan' : 'di ' . $kodeBlok)
                . ' dipasang dari cincin MASTER.dxf yang bendera '
                . 'tertutupnya tidak diset sehingga tidak ikut didigitasi. Luas '
                . number_format($luas, 0, ',', '.')
                . ' m2. Cadangan: ' . $cadangan,
            null,
            ['status' => 'perlu_verifikasi', 'jenis_hak' => JENIS_HAK_DEFAULT,
                'blok_id' => $kodeBlok === 'BLOK belum ditentukan' ? null : $kodeBlok, 'kode' => $daftar]
        );
    }

    $hasil['cadangan'] = $cadangan;
    $hasil['tanpa_blok'] = $laporTanpaBlok();

    return $hasil;
}

/**
 * Beri blok kepada calon yang titik tengahnya tidak jatuh di dalam garis blok mana pun,
 * dari **tetangga tepinya**: bidang hidup yang berbagi tepi (jarak poligon
 * <= DXF_PERSIL_TETANGGA_M) dengan calon itu.
 *
 * Aturan ini hanya sah untuk blok yang garis luarnya memang didefinisikan sebagai
 * gabungan bidang anggotanya (BLOK 2, 8, 19, 21, 22 — `sumber_perkiraan =
 * 'gabungan bidang anggota'`). Garis semacam itu berhenti persis di tepi bidang yang ikut
 * didigitasi, jadi bidang yang tidak ikut didigitasi sudah pasti jatuh di luarnya, dan
 * `impor.php kira <blok> --tepat` akan merentangkannya lagi sampai ikut menaungi. Blok
 * bergaris luar ukur MASTER.dxf **tidak** boleh ditambah dari sini: garisnya bukti
 * lapangan yang berdiri sendiri, bukan turunan anggotanya, sehingga bidang yang tidak
 * masuk ke dalamnya memang di luar blok itu menurut peta.
 *
 * Jarak ke garis blok sengaja tidak dipakai sebagai penentu: garis dua blok bertetangga
 * juga saling menempel, jadi calon yang berbagi tepi dengan anggota BLOK 9 berjarak
 * 1,1 m dari garis BLOK 8 — yang mana pun "terdekat" bisa salah. Yang dipakai hanya
 * kesepakatan antar blok tetangga tepi; kalau terbagi, calon tetap ditahan.
 *
 * @param array<string, mixed>                 $c    calon dengan `wkt` dan `pusat`
 * @param array<int, array<string, mixed>>     $blok daftar blok beserta `sumber`
 * @return array<string, mixed> calon yang ditambahi `blok_id` + `blok_alasan`, atau `alasan` penolakan
 */
function calonDariTetanggaTepi(array $c, array $blok): array
{
    // Kotak pembatas diperlebar lebih dulu sebagai penyaring murah; jarak sebenarnya
    // baru disaring di HAVING karena MBRIntersects bukan uji kedekatan.
    $tetangga = db_all(
        'SELECT bl.kode AS blok, b.kode AS bidang,
                ST_Distance(b.geom, ST_GeomFromText(?, 4326)) * 111320.0 AS jarak
           FROM bidang b LEFT JOIN blok bl ON bl.id = b.blok_id
          WHERE b.status NOT IN (\'dihapus\', \'duplikat\')
            AND MBRIntersects(b.geom, ST_Buffer(ST_GeomFromText(?, 4326), ?))
          HAVING jarak <= ?
          ORDER BY jarak ASC
          LIMIT 12',
        [(string) $c['wkt'], (string) $c['wkt'], DXF_PERSIL_TETANGGA_M / 111320.0, DXF_PERSIL_TETANGGA_M]
    );
    if ($tetangga === []) {
        return $c + ['alasan' => 'tidak berbagi tepi dengan bidang hidup mana pun'];
    }
    $kodeBlok = array_values(array_unique(array_map(
        static fn(array $t): string => (string) ($t['blok'] ?? ''), $tetangga)));
    if (count($kodeBlok) > 1) {
        return $c + ['alasan' => 'berbagi tepi dengan dua blok sekaligus: ' . implode(' + ', $kodeBlok)];
    }
    $kode = $kodeBlok[0];
    $satu = null;
    foreach ($blok as $bl) {
        if ((string) $bl['kode'] === $kode) {
            $satu = $bl;
            break;
        }
    }
    if ($satu === null) {
        return $c + ['alasan' => 'blok tetangga ' . $kode . ' tidak punya geometri'];
    }
    if ((string) $satu['sumber'] !== BLOK_GABUNGAN_ANGGOTA) {
        return $c + ['alasan' => 'tetangga tepinya anggota ' . $kode
            . ', tapi blok itu bergaris luar ukur sehingga tidak bisa ditambah dari sini'];
    }
    $nama = array_slice(array_column($tetangga, 'bidang'), 0, 3);
    return $c + [
        'blok_id' => (int) $satu['id'],
        'blok' => $kode,
        'blok_alasan' => 'berbagi tepi dengan ' . implode(', ', $nama) . ' (anggota ' . $kode . ')',
        'tetangga' => array_column($tetangga, 'bidang'),
    ];
}

/**
 * Garis blok terdekat dari satu titik WGS84: `[kode, jarak meter]`.
 *
 * Angkanya HANYA dipakai sebagai petunjuk di catatan bidang baru — jarak ke garis tidak
 * pernah memberi blok, karena garis dua blok yang bertetangga juga saling menempel dan
 * sebagian bidang lama tercatat di blok yang garis luarnya sudah tidak menaunginya.
 *
 * @param array{0: float, 1: float}          $pusat
 * @param array<int, array<string, mixed>>   $blok  blok beserta `wkt` hasil ST_AsText(geom)
 * @return array{kode: string|null, jarak_m: float|null}
 */
function garisBlokTerdekat(array $pusat, array $blok): array
{
    $titik = sprintf('POINT(%F %F)', (float) $pusat[0], (float) $pusat[1]);
    $terdekat = ['kode' => null, 'jarak_m' => null];
    foreach ($blok as $bl) {
        $jarak = db_value(
            'SELECT ST_Distance(ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326)) * 111320.0',
            [$titik, (string) $bl['wkt']]
        );
        if ($jarak === null) {
            continue;
        }
        if ($terdekat['jarak_m'] === null || (float) $jarak < (float) $terdekat['jarak_m']) {
            $terdekat = ['kode' => (string) $bl['kode'], 'jarak_m' => round((float) $jarak, 1)];
        }
    }

    return $terdekat;
}

/**
 * Nomor peta dinormalkan supaya bisa dibandingkan: huruf besar, tanpa spasi, tanpa
 * awalan `NIS :`, `NIB :` dan sejenisnya yang ditulis petugas di label.
 */
function nomorPetaPekat(string $teks): string
{
    $s = (string) (strtoupper(preg_replace('/\s+/', '', $teks) ?? '') ?? '');
    foreach (['NIS:', 'NIB:', 'NIBHM:', 'DAFTAR:', 'NO:'] as $awal) {
        if (str_starts_with($s, $awal)) { $s = substr($s, strlen($awal)); }
    }

    return $s;
}

/**
 * Kunci banding kedua: angka tanpa nol di depan. Register `080201` dan `NIB HM` menulis
 * nomornya dengan nol pengisi, sedangkan SPPT sering tanpa — `01170` dan `1170` adalah
 * nomor yang sama, dan membandingkan teks mentah akan menyebutnya berbeda.
 */
function nomorPetaKunci(string $teks): string
{
    $p = nomorPetaPekat($teks);

    return $p === '' ? '' : (ltrim($p, '0') ?: '0');
}

/**
 * Daftar kerja persil yang belum terpetakan, diukur dari seluruh lapisan MASTER.dxf
 * lawan baris bidang hidup. Dua golongannya adalah dua pekerjaan yang berbeda:
 *
 *   `A` — cincin tertutup yang tidak punya bidang sama sekali: geometrinya ada, datanya
 *         belum. Sebagian besar sengaja ditahan `persilDariDxf()` karena titik tengahnya
 *         tidak jatuh di dalam garis blok mana pun.
 *   `B` — nomor register peta yang labelnya berdiri di dalam garis blok tapi di luar
 *         poligon mana pun DAN nomornya tidak tercatat di baris hidup: datanya ada,
 *         geometrinya belum. Tidak ada alat yang bisa memulihkan ini — hanya digitasi.
 *
 * Hanya membaca. Dua label nomor sejauh `$radiusM` satu persil (satu poligon biasanya
 * menampung nomor daftar + NIS + nama sekaligus). `nomor` ikut dikembalikan supaya
 * sensitivitas radius bisa diukur ulang tanpa menjalankan semua ini lagi.
 *
 * @return array{baris: array<int, array<string, string>>, cincin: int, loket: int,
 *               label: int, radius: float, nomor: array<int, array{x:float,y:float,teks:string}>}
 */
function daftarBelumTerpetakan(float $radiusM = 4.0, ?string $berkas = null): array
{
    $berkas = masterDxf($berkas);

    $bidangHidup = [];
    foreach (db_all('SELECT id, kode, ST_AsText(geom) AS wkt FROM bidang
                      WHERE status NOT IN (\'dihapus\', \'duplikat\') AND geom IS NOT NULL') as $b) {
        $ring = wktCincin((string) $b['wkt']);
        if (count($ring) < 3) { continue; }
        $bidangHidup[(int) $b['id']] = ['id' => (int) $b['id'], 'kode' => (string) $b['kode'],
            'ring' => $ring] + wktWilayah((string) $b['wkt']);
    }
    $kisi = kisiBangun($bidangHidup);

    $blok = [];
    foreach (db_all('SELECT kode, sumber_perkiraan, ST_AsText(geom) AS wkt FROM blok
                      WHERE geom IS NOT NULL') as $bl) {
        $bagian = wktBagian((string) $bl['wkt']);
        if ($bagian === []) { continue; }
        $blok[] = ['kode' => (string) $bl['kode'], 'sumber' => (string) ($bl['sumber_perkiraan'] ?? ''),
            'bagian' => $bagian] + wktWilayah((string) $bl['wkt']);
    }
    $blokDari = static function (array $pusat) use ($blok): array {
        foreach ($blok as $bl) {
            if (titikDiDalamBagian($pusat, $bl['bagian'])) { return [$bl['kode'], $bl['sumber']]; }
        }

        return ['', 'di luar blok mana pun'];
    };

    $tercatat = [];
    foreach (db_all('SELECT nomor_daftar, nib, nis FROM bidang
                      WHERE status NOT IN (\'dihapus\', \'duplikat\')') as $r) {
        foreach (['nomor_daftar', 'nib', 'nis'] as $k) {
            $v = (string) ($r[$k] ?? '');
            if (nomorPetaPekat($v) === '') { continue; }
            $tercatat[nomorPetaPekat($v)] = true;
            $tercatat[nomorPetaKunci($v)] = true;
        }
    }

    $baris = [];
    $nomorYatim = [];

    // ---- A. cincin tertutup tanpa bidang -----------------------------------------
    $terlihat = [];
    foreach (dxfPoligon($berkas) as $p) {
        $t = $p['titik'];
        if (count($t) < 3) { continue; }
        $celah = $p['tertutup'] ? 0.0
            : hypot($t[0][0] - $t[count($t) - 1][0], $t[0][1] - $t[count($t) - 1][1]);
        if ($celah > DXF_PERSIL_CELAH_M || max(array_map('abs', $t[0])) < DXF_PERSIL_ASAL_M) { continue; }
        if ($celah > 0) { $t[] = $t[0]; }
        $luas = abs(poligonLuas($t));
        if ($luas < DXF_PERSIL_MIN_M2) { continue; }
        $sentuh = false;
        foreach ($t as $u) {
            if (tumpukKisi(dxfKeWgs84((float) $u[0], (float) $u[1]), $bidangHidup, $kisi) !== []) {
                $sentuh = true;
                break;
            }
        }
        $pusat = cincinPusatWgs($t);
        if (!$sentuh && tumpukKisi($pusat, $bidangHidup, $kisi) !== []) { $sentuh = true; }
        if ($sentuh) { continue; }
        $sidik = md5(sprintf('%.5f|%.5f|%.1f', $pusat[0], $pusat[1], $luas));
        if (isset($terlihat[$sidik])) { $terlihat[$sidik]['kembar']++; continue; }
        $terlihat[$sidik] = ['lapisan' => (string) $p['layer'], 'titik' => $t, 'celah' => $celah,
            'luas' => $luas, 'pusat' => $pusat, 'kembar' => 1];
    }

    $label = array_values(array_filter(
        dxfTeks($berkas),
        static fn(array $x): bool => $x['layer'] !== 'MAP_NAME'
    ));
    foreach ($terlihat as $c) {
        $isi = [];
        foreach ($label as $L) {
            if (in_array($L['layer'], ['tn_nama', '080201', 'NIB HM', 'tn_alashak', '080203'], true)
                && titikDiDalamCincin([(float) $L['x'], (float) $L['y']], $c['titik'])) {
                $isi[] = $L['layer'] . '=' . $L['teks'];
            }
        }
        [$kodeBlok, $sumber] = $blokDari($c['pusat']);
        $baris[] = [
            'golongan' => 'A cincin tanpa bidang',
            'lapisan' => $c['lapisan'],
            'luas_m2' => number_format($c['luas'], 1, '.', ''),
            'blok' => $kodeBlok,
            'sumber_garis_blok' => $sumber,
            'bujur' => number_format($c['pusat'][0], 7, '.', ''),
            'lintang' => number_format($c['pusat'][1], 7, '.', ''),
            'cacah' => $c['kembar'] . ' ring identik',
            'catatan_geometri' => $c['celah'] > 0
                ? 'celah ujung ' . number_format($c['celah'], 3, '.', '') . ' m'
                : 'flag tertutup',
            'label_di_dalamnya' => implode('; ', $isi) ?: 'TANPA LABEL',
        ];
    }
    $cincin = count($baris);

    // ---- B. nomor register yang tidak berdiri di atas bidang ---------------------
    // Jarak dihitung di ruang meter DXF, bukan derajat, supaya pembulatan bujur tidak
    // mengubah kelompoknya.
    foreach ($label as $L) {
        if (!in_array($L['layer'], ['080201', 'NIB HM', 'tn_alashak'], true)) { continue; }
        $kunci = nomorPetaPekat((string) $L['teks']);
        if ($kunci === '' || $kunci === '0') { continue; }          // "NIS : 0" itu kosong
        $pusat = dxfKeWgs84((float) $L['x'], (float) $L['y']);
        if (tumpukKisi($pusat, $bidangHidup, $kisi) !== []) { continue; }
        if (($tercatat[$kunci] ?? false)
            || ($tercatat[nomorPetaKunci((string) $L['teks'])] ?? false)) { continue; }
        $nomorYatim[] = ['x' => (float) $L['x'], 'y' => (float) $L['y'], 'teks' => (string) $L['teks'],
            'lapisan' => (string) $L['layer'], 'pusat' => $pusat];
    }

    $titik = [];
    foreach ($nomorYatim as $L) {
        $g = null;
        foreach ($titik as $i => $g0) {
            if (hypot($L['x'] - $g0['x'], $L['y'] - $g0['y']) <= $radiusM) { $g = $i; break; }
        }
        if ($g === null) {
            $titik[] = ['x' => $L['x'], 'y' => $L['y'], 'pusat' => $L['pusat'],
                'nomor' => [$L['lapisan'] . '=' . $L['teks']], 'lapisan' => [$L['lapisan'] => 1]];
            continue;
        }
        $titik[$g]['x'] = ($titik[$g]['x'] + $L['x']) / 2.0;
        $titik[$g]['y'] = ($titik[$g]['y'] + $L['y']) / 2.0;
        $titik[$g]['nomor'][] = $L['lapisan'] . '=' . $L['teks'];
        $titik[$g]['lapisan'][$L['lapisan']] = ($titik[$g]['lapisan'][$L['lapisan']] ?? 0) + 1;
    }
    usort($titik, static fn(array $a, array $b): int
        => count($b['nomor']) <=> count($a['nomor']) ?: strcmp($a['nomor'][0], $b['nomor'][0]));
    foreach ($titik as $c) {
        [$kodeBlok, $sumber] = $blokDari($c['pusat']);
        if ($kodeBlok === '') { continue; }        // di luar blok: bukan tanah yang bisa didigitasi
        $baris[] = [
            'golongan' => 'B nomor tanpa bidang',
            'lapisan' => implode('+', array_keys($c['lapisan'])),
            'luas_m2' => '',
            'blok' => $kodeBlok,
            'sumber_garis_blok' => $sumber,
            'bujur' => number_format($c['pusat'][0], 7, '.', ''),
            'lintang' => number_format($c['pusat'][1], 7, '.', ''),
            'cacah' => count($c['nomor']) . ' label',
            'catatan_geometri' => '',
            'label_di_dalamnya' => implode('; ', $c['nomor']),
        ];
    }

    usort($baris, static fn(array $a, array $b): int
        => strcmp($a['golongan'], $b['golongan']) ?: strcmp($a['blok'], $b['blok'])
           ?: ((float) $b['luas_m2'] <=> (float) $a['luas_m2']));

    return [
        'baris' => $baris,
        'cincin' => $cincin,
        'loket' => count($baris) - $cincin,
        'label' => count($nomorYatim),
        'radius' => $radiusM,
        'nomor' => $nomorYatim,
    ];
}

/**
 * Cincin terluas dari sebuah WKT POLYGON/MULTIPOLYGON, sebagai daftar [lon, lat].
 * Bagian yang lebih kecil (lubang, atau kepul MULTIPOLYGON lain) diabaikan: uji
 * titik-dalam yang memakai fungsi ini hanya perlu cincin yang benar-benar menaungi
 * lahannya. Untuk geometri yang terpecah-pecah — blok yang garis luarnya gabungan
 * bidang anggotanya — pakai wktBagian(), karena titik yang dicari bisa jatuh di
 * kepul mana pun, bukan hanya yang terbesar.
 *
 * @return array<int, array{0:float,1:float}>
 */
function wktCincin(string $wkt): array
{
    $terbaik = [];
    foreach (wktBagian($wkt) as $ring) {
        if (poligonLuas($ring) > poligonLuas($terbaik)) {
            $terbaik = $ring;
        }
    }

    return $terbaik;
}

/**
 * Semua cincin dari sebuah WKT POLYGON atau MULTIPOLYGON, tiap bagian sebagai
 * daftar [lon, lat]. Yang diambil adalah kurung terdalam, jadi MULTIPOLYGON dengan
 * beberapa kepul — dan POLYGON berlubang, yang lubangnya ikut sebagai cincin
 * tersendiri — terurai benar. Pola `(( ... ))` yang lama menelan kurung buka
 * pertama ke dalam koordinat, sehingga bujur sudut pertama terbaca nol.
 *
 * @return array<int, array<int, array{0:float,1:float}>>
 */
function wktBagian(string $wkt): array
{
    $bagian = [];
    if (preg_match_all('/\(([^()]*)\)/s', $wkt, $cocok) < 1) {
        return $bagian;
    }
    foreach ($cocok[1] as $isi) {
        $ring = [];
        foreach (preg_split('/,\s*/', trim($isi)) as $pasangan) {
            $pecah = preg_split('/\s+/', trim($pasangan)) ?: [];
            if (count($pecah) >= 2) {
                $ring[] = [(float) $pecah[0], (float) $pecah[1]];
            }
        }
        if (count($ring) >= 3) {
            $bagian[] = $ring;
        }
    }

    return $bagian;
}

/**
 * Uji titik-dalam untuk geometri yang terpecah: benar bila salah satu bagiannya
 * menampung titik itu.
 *
 * @param array<int, array<int, array{0:float,1:float}>> $bagian
 */
function titikDiDalamBagian(array $titik, array $bagian): bool
{
    foreach ($bagian as $ring) {
        if (titikDiDalamCincin($titik, $ring)) {
            return true;
        }
    }

    return false;
}

/**
 * Kisi sel ~55 m: kunci sel -> daftar kunci array geometri yang kotaknya menumpang
 * sel itu. Tanpa kisi, tiap uji titik harus mencoba seluruh bidang dan perintah ini
 * berjalan bermenit-menit; kotak pembatas hanya dipakai untuk menyaring, keputusan
 * tetap diambil dari uji cincin sebenarnya.
 *
 * @param array<array-key, array{s:float,n:float,w:float,e:float}> $geometri
 * @return array<string, array<int, array-key>>
 */
function kisiBangun(array $geometri): array
{
    $kisi = [];
    foreach ($geometri as $kunci => $g) {
        for ($x = (int) floor($g['w'] / KISI_SEL); $x <= (int) floor($g['e'] / KISI_SEL); $x++) {
            for ($y = (int) floor($g['s'] / KISI_SEL); $y <= (int) floor($g['n'] / KISI_SEL); $y++) {
                $kisi[$x . ':' . $y][] = $kunci;
            }
        }
    }

    return $kisi;
}

/** Daftar kunci geometri yang benar-benar memuat titik, bukan hanya kotaknya. */
function tumpukKisi(array $titik, array $geometri, array $kisi): array
{
    $sel = $kisi[((int) floor($titik[0] / KISI_SEL)) . ':' . ((int) floor($titik[1] / KISI_SEL))] ?? [];
    $temu = [];
    foreach ($sel as $kunci) {
        if (titikDiDalamCincin($titik, $geometri[$kunci]['ring'])) {
            $temu[] = $kunci;
        }
    }

    return $temu;
}

/** Titik tengah cincin meter proyek (cincin ditutup lebih dulu), lalu ke WGS84. */
function cincinPusatWgs(array $meter): array
{
    $c = $meter;
    if ($c[0] !== $c[count($c) - 1]) {
        $c[] = $c[0];
    }
    $six = 0.0; $siy = 0.0; $sa = 0.0;
    for ($i = 0, $n = count($c) - 1; $i < $n; $i++) {
        $p = $c[$i][0] * $c[$i + 1][1] - $c[$i + 1][0] * $c[$i][1];
        $six += ($c[$i][0] + $c[$i + 1][0]) * $p;
        $siy += ($c[$i][1] + $c[$i + 1][1]) * $p;
        $sa += $p;
    }
    if (abs($sa) < 1e-9) {
        return dxfKeWgs84(
            array_sum(array_column($c, 0)) / count($c),
            array_sum(array_column($c, 1)) / count($c)
        );
    }

    // Talas-tengah dihitung di ruang meter: dxfKeWgs84() affine, jadi titik
    // tengahnya ikut pindah dengan tepat. Di ruang derajat cincin DXF yang tidak
    // menutup diri membuat penjumlahannya kehilangan seluruh nilainya.
    return dxfKeWgs84($six / (3 * $sa), $siy / (3 * $sa));
}

/** Ray casting; koordinat titik dan cincin harus satu ruang (derajat atau meter). */
function titikDiDalamCincin(array $titik, array $ring): bool
{
    $luar = false;
    for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
        $a = $ring[$i]; $b = $ring[$j];
        if (($a[1] > $titik[1]) !== ($b[1] > $titik[1])
            && $titik[0] < ($b[0] - $a[0]) * ($titik[1] - $a[1]) / ($b[1] - $a[1]) + $a[0]) {
            $luar = !$luar;
        }
    }

    return $luar;
}

/** Dua cincin dianggap kembar kalau kotaknya bersentuhan dan salah satu pusatnya ada di dalam cincin lain. */
function cincinSalingTumpuk(array $a, array $b): bool
{
    if ($a['pusat'][0] < $b['w'] || $a['pusat'][0] > $b['e'] || $a['pusat'][1] < $b['s'] || $a['pusat'][1] > $b['n']
        || $b['pusat'][0] < $a['w'] || $b['pusat'][0] > $a['e'] || $b['pusat'][1] < $a['s'] || $b['pusat'][1] > $a['n']) {
        return false;
    }

    return wktTitikDiDalam($a['pusat'], $b['wkt']) || wktTitikDiDalam($b['pusat'], $a['wkt']);
}

/** Uji titik terhadap cincin terluas sebuah WKT. */
function wktTitikDiDalam(array $titik, string $wkt): bool
{
    $ring = wktCincin($wkt);

    return $ring !== [] && titikDiDalamCincin($titik, $ring);
}

/** Hitungan isi basis data untuk ringkasan sesudah impor. */
function hitunganTabel(): array{
    $tabel = ['blok', 'bidang', 'hak', 'pemegang', 'buku_c', 'objek_pajak', 'sppt', 'pembayaran', 'peralihan', 'laporan', 'pengguna'];
    $hasil = [];
    foreach ($tabel as $nama) {
        $hasil[$nama] = (int) db_value('SELECT COUNT(*) FROM `' . $nama . '`');
    }
    return $hasil;
}

// ---------------------------------------------------------------------------
// 19. Garis kuning (ACI 2) pada peta master
// ---------------------------------------------------------------------------

/** ACI 2 = kuning. Digitator menandai persil yang ditinjau lapangan dengan warna ini. */
const DXF_WARNA_KUNING = 2;
/** Jauh maksimum sebuah sudut boleh ditarik ke garis tetangga. */
const KUNING_TARIK_M = 0.15;
/** Di atas jarak ini dua bidang tidak lagi berbagi tepi. */
const KUNING_TETANGGA_M = 0.5;
/** Sebuah bidang disebut padanan garis kuning bila menjelaskan bagian ini dari garis itu. */
const KUNING_JELAS = 0.5;
/** Lebar sel indeks ruang (meter) tempat mencari tetangga. */
const KUNING_PETAK_M = 25.0;
/** Di bawah jarak ini sebuah sudut tidak menambah bentuk apa pun dan dibuang dari cincin. */
const KUNING_SUDUT_M = 0.15;
/** Meter per derajat lintang; bujur dikalikan cos lintang desa. */
const METER_PER_DERAJAT = 111320.0;
const LINTANG_DESA = 7.08;

/**
 * Poligon bewarna ACI tertentu pada seksi ENTITIES sebuah berkas DXF.
 *
 * Seksi BLOCKS diabaikan: isinya definisi blok berkoordinat lokal sehingga titiknya
 * tidak jatuh di mana pun di lapangan. `dxfPoligon()` tidak membaca grup 62 sama
 * sekali, jadi warna tidak pernah sampai ke basis data dan garis kuning hanya bisa
 * ditemukan dengan pembacaan khusus ini.
 *
 * @return list<array{layer:string, titik:list<array{0:float,1:float}>, tertutup:bool}>
 */
function dxfPoligonBewarna(string $path, int $warnaCari): array
{
    if (!is_file($path)) {
        throw new RuntimeException('Berkas DXF tidak ditemukan: ' . $path);
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('Berkas DXF gagal dibuka: ' . $path);
    }

    $out = [];
    $seksi = '?'; $entity = null; $layer = null; $warna = null; $bendera = 0; $titik = []; $x = null;
    $simpan = static function () use (&$entity, &$layer, &$warna, &$bendera, &$titik, &$seksi, &$out, &$x, $warnaCari): void {
        if (($entity === 'LWPOLYLINE' || $entity === 'POLYLINE') && $warna === $warnaCari
            && $seksi === 'ENTITIES' && count($titik) >= 3) {
            $out[] = ['layer' => (string) $layer, 'titik' => $titik, 'tertutup' => ($bendera & 1) === 1];
        }
        // VERTEX dan SEQEND melanjutkan POLYLINE sebelumnya, jadi jangan dihapus.
        if ($entity !== 'VERTEX' && $entity !== 'SEQEND') {
            $layer = null; $warna = null; $bendera = 0; $titik = [];
        }
        $x = null;
    };

    $kode = null;
    while (($baris = fgets($handle)) !== false) {
        $potong = rtrim($baris);
        if ($potong === '') {
            continue;
        }
        if ($kode === null) {
            if (preg_match('/^\s*-?\d+$/', $potong) === 1) {
                $kode = (int) $potong;
            }
            continue;
        }
        $nilai = trim($potong);
        switch ($kode) {
            case 0: $simpan(); $entity = $nilai; break;
            case 2: if ($entity === 'SECTION') { $seksi = $nilai; } break;
            case 8: $layer = $nilai; break;
            case 62: $warna = (int) $nilai; break;
            case 70: $bendera = (int) $nilai; break;
            case 10: if ($x === null) { $x = (float) $nilai; } break;
            case 20: if ($x !== null) { $titik[] = [$x, (float) $nilai]; $x = null; } break;
        }
        $kode = null;
    }
    $simpan();
    fclose($handle);

    return $out;
}

/** Cincin ditutup bila belum: titik pertama diulang di akhirnya. */
function cincinTutup(array $ring): array
{
    if ($ring === []) {
        return $ring;
    }
    $a = $ring[0];
    $b = $ring[count($ring) - 1];
    if (abs((float) $a[0] - (float) $b[0]) > 1e-12 || abs((float) $a[1] - (float) $b[1]) > 1e-12) {
        $ring[] = $a;
    }

    return $ring;
}

/**
 * Buang sudut yang menempel sudut sebelumnya (<= `$minM` meter) dari sebuah cincin derajat.
 *
 * Sisa potongan `ST_Difference` sering meninggalkan duri seukuran sentimeter. Duri itu tidak
 * menambah bentuk, tapi membuat MariaDB menyerah: `ST_PointOnSurface()` atas poligon berdurasi
 * 0,06 m mengembalikan NULL sehingga `pusat_lon`/`pusat_lat` tidak bisa diisi (bidang
 * `DXF-HM-0007` sempat tercatat berpusat di 0,0).
 */
function cincinRapi(array $ring, float $minM = KUNING_SUDUT_M): array
{
    $lebar = METER_PER_DERAJAT * cos(deg2rad(LINTANG_DESA));
    $jauh = static function (array $a, array $b) use ($lebar): float {
        return hypot(((float) $a[0] - (float) $b[0]) * $lebar, ((float) $a[1] - (float) $b[1]) * METER_PER_DERAJAT);
    };

    $bersih = [];
    foreach ($ring as $p) {
        if ($bersih !== [] && $jauh($p, $bersih[count($bersih) - 1]) <= $minM) {
            continue;
        }
        $bersih[] = $p;
    }
    while (count($bersih) > 3 && $jauh($bersih[0], $bersih[count($bersih) - 1]) <= $minM) {
        array_pop($bersih);
    }

    return cincinTutup($bersih);
}

/**
 * WKT poligon sampai delapan desimal, kosong kalau cincinnya tidak cukup.
 *
 * `wktPoligon()` membulatkan ke enam desimal, yaitu 8–11 cm pada bujur desa ini.
 * Penjajaran kuning hanya memindah sudut sampai 15 cm, jadi pembulatan itu menghapus
 * tarikan yang baru saja dihitung: di uji, lima dari tujuh belas tarikan hilang sebelum
 * sempat menulis. Delapan desimal = ± 1 mm, cukup untuk menyebut tepi dua bidang sama.
 */
function wktPoligonTepat(array $ring): string
{
    $r = cincinTutup($ring);
    if (count($r) < 4) {
        return '';
    }

    return 'POLYGON((' . implode(',', array_map(
        static fn(array $p): string => sprintf('%.8F %.8F', (float) $p[0], (float) $p[1]), $r)) . '))';
}

/**
 * Jarak terpendek sebuah titik ke sebuah cincin, berikut kaki proyeksinya.
 *
 * @return array{0:float, 1:array{0:float,1:float}}
 */
function titikKeCincin(array $titik, array $cincin): array
{
    $j = INF;
    $kaki = $titik;
    for ($i = 0, $n = count($cincin) - 1; $i < $n; $i++) {
        $a = $cincin[$i];
        $b = $cincin[$i + 1];
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $pp = $dx * $dx + $dy * $dy;
        $u = $pp > 0.0
            ? max(0.0, min(1.0, (($titik[0] - $a[0]) * $dx + ($titik[1] - $a[1]) * $dy) / $pp))
            : 0.0;
        $q = [$a[0] + $u * $dx, $a[1] + $u * $dy];
        $d = hypot($titik[0] - $q[0], $titik[1] - $q[1]);
        if ($d < $j) {
            $j = $d;
            $kaki = $q;
        }
    }

    return [$j, $kaki];
}

/**
 * Titik yang dijamin jatuh di dalam sebuah cincin derajat, sebagai [bujur, lintang].
 *
 * `cincinPusatWgs()` memakai talas-tengah luas (centroid) sebagai pusat bidang, dan itu
 * dipertahankan di sini supaya penugasan blok berjalan dengan aturan yang sama. Bedanya,
 * hasilnya diuji dulu: talas-tengah sebuah poligon cekung bisa jatuh di luar bidang itu
 * sendiri. Sengaja tidak memakai `ST_PointOnSurface()` — pada 33 bidang basis data ini,
 * termasuk garis kuning #48 yang sederhana dan tidak menembus dirinya, fungsi itu
 * mengembalikan NULL dan bidang `DXF-HM-0007` sempat tertulis berpusat di 0,0.
 */
function pusatCincin(array $ring): ?array
{
    $r = cincinTutup($ring);
    if (count($r) < 4) {
        return null;
    }
    $enam = 0.0; $siX = 0.0; $siY = 0.0;
    for ($i = 0, $n = count($r) - 1; $i < $n; $i++) {
        $p = (float) $r[$i][0] * (float) $r[$i + 1][1] - (float) $r[$i + 1][0] * (float) $r[$i][1];
        $enam += $p;
        $siX += ((float) $r[$i][0] + (float) $r[$i + 1][0]) * $p;
        $siY += ((float) $r[$i][1] + (float) $r[$i + 1][1]) * $p;
    }
    $tengah = static fn(array $t): array => [
        array_sum(array_column($t, 0)) / count($t), array_sum(array_column($t, 1)) / count($t)];
    $kandidat = [];
    if (abs($enam) > 1e-15) {
        $kandidat[] = [$siX / (3 * $enam), $siY / (3 * $enam)];
    }
    $kandidat[] = $tengah(array_slice($r, 0, -1));
    for ($i = 1, $n = count($r) - 1; $i < $n - 1; $i++) {
        $kandidat[] = $tengah([$r[0], $r[$i], $r[$i + 1]]);
    }
    foreach ($kandidat as $p) {
        if (titikDiDalamCincin($p, $r)) {
            return [(float) $p[0], (float) $p[1]];
        }
    }

    return null;
}

/** Titik yang dijamin jatuh di atas sebuah geometri, sebagai [bujur, lintang]; null kalau tidak ada. */
function pusatWkt(string $wkt): ?array
{
    return pusatCincin(wktCincin($wkt));
}

/**
 * Pasangkan tiap garis kuning MASTER.dxf dengan bidangnya, sejajarkan tepi bidang itu
 * ke garis tetangga, dan siapkan garis kuning yang tidak punya bidang sebagai bidang baru.
 *
 * Padanan dipilih dari berapa bagian GARIS KUNING yang terjelaskan oleh sebuah bidang,
 * bukan berapa bagian bidang yang tertutup garis: garis yang meleset bisa tetap menutup
 * 99% bidang sempit sambil cuma menjelaskan 1% dirinya sendiri.
 *
 * Penjajaran hanya memindahkan sudut yang sudah jatuh <= `$tarikM` dari garis tetangga
 * ke garis itu — ia menutup celah digitasi, tidak mengarang bentuk baru. Luas_m2 yang
 * tercatat di basis data tidak disentuh: itu angka pendaftaran, bukan hasil gambar.
 *
 * Garis kuning yatim dipotong sampai pas ke bidang yang sudah ada lebih dulu, jadi
 * bidang baru tidak menimpa tanah orang; kalau hasil potongnya terpecah, hanya kepul
 * terbesar yang dipasang dan sisanya dilaporkan.
 *
 * Perintah ini **satu ronde**. Dua bidang yang berdempetan sama-sama menarik sudut shared
 * ke garis yang tersimpan di tetangga, dan garis itu sendiri sudah digeser pada ronde yang
 * sama, jadi ronde kedua menghasilkan tarikan balik: terukur 20 Okt 2026 ronde 2 memindah
 * 8 bidang (2,98 m2) dan ronde 3 membatalkan 5 di antaranya (HM-0127 572,37 -> 572,18 lalu
 * kembali 572,37). Celah <= 0,063 m pada tepi yang berdua karenanya dibiarkan, bukan
 * dikejar. Yang tetap dianggap cacat hanya jeda yang benar-benar besar; ambang lapor
 * `tolis` ada di `_lama/scratch/kuning-sesuaikan.php`.
 */
function kuningMasterSelaraskan(bool $terapkan = false, float $tarikM = KUNING_TARIK_M, ?string $berkas = null): array
{
    $dxf = masterDxf($berkas);
    $kuning = array_values(array_filter(
        dxfPoligonBewarna($dxf, DXF_WARNA_KUNING),
        static fn(array $p): bool => $p['tertutup'] && abs(poligonLuas($p['titik'])) >= DXF_PERSIL_MIN_M2
    ));
    if ($kuning === []) {
        throw new RuntimeException('Tidak ada poligon kuning tertutup di ' . $dxf . '.');
    }

    $keDerajatTitik = static fn(array $titik): array => array_map(
        static fn(array $p): array => dxfKeWgs84((float) $p[0], (float) $p[1]), $titik);

    // Bingkai meter lokal: jarak dan luas dibandingkan langsung, bukan lewat derajat.
    $acuan = [];
    foreach ($kuning as $k) {
        $acuan = array_merge($acuan, $keDerajatTitik($k['titik']));
    }
    $t0 = array_sum(array_column($acuan, 0)) / count($acuan);
    $t1 = array_sum(array_column($acuan, 1)) / count($acuan);
    $lebarDerajat = METER_PER_DERAJAT * cos(deg2rad(LINTANG_DESA));
    $keMeter = static fn(array $p): array => [
        ($p[0] - $t0) * $lebarDerajat, ($p[1] - $t1) * METER_PER_DERAJAT];
    $keDerajat = static fn(array $p): array => [
        $t0 + $p[0] / $lebarDerajat, $t1 + $p[1] / METER_PER_DERAJAT];

    // ---- bidang hidup + indeks ruang ----
    $hidup = [];
    foreach (db_all("SELECT id, kode, sumber, status, luas_m2, pusat_lon, pusat_lat, ST_AsText(geom) AS wkt
                      FROM bidang
                     WHERE status NOT IN ('dihapus', 'duplikat') AND geom IS NOT NULL") as $b) {
        $r = cincinTutup(wktCincin((string) $b['wkt']));
        if (count($r) < 4) {
            continue;
        }
        $meter = array_map($keMeter, $r);
        $hidup[(int) $b['id']] = [
            'id' => (int) $b['id'], 'kode' => (string) $b['kode'], 'sumber' => (string) $b['sumber'],
            'status' => (string) $b['status'], 'luas' => (float) $b['luas_m2'],
            'derajat' => $r, 'meter' => $meter, 'wkt' => (string) $b['wkt'],
            'pusat' => [(float) $b['pusat_lon'], (float) $b['pusat_lat']],
            'kotak' => [min(array_column($meter, 0)), min(array_column($meter, 1)),
                max(array_column($meter, 0)), max(array_column($meter, 1))],
        ];
    }

    $petak = [];
    foreach ($hidup as $j => $b) {
        for ($x = (int) floor($b['kotak'][0] / KUNING_PETAK_M); $x <= (int) floor($b['kotak'][2] / KUNING_PETAK_M); $x++) {
            for ($y = (int) floor($b['kotak'][1] / KUNING_PETAK_M); $y <= (int) floor($b['kotak'][3] / KUNING_PETAK_M); $y++) {
                $petak[$x . ':' . $y][] = $j;
            }
        }
    }
    $kandidat = static function (array $k) use ($petak): array {
        $temu = [];
        for ($x = (int) floor(($k[0] - 1.0) / KUNING_PETAK_M); $x <= (int) floor(($k[2] + 1.0) / KUNING_PETAK_M); $x++) {
            for ($y = (int) floor(($k[1] - 1.0) / KUNING_PETAK_M); $y <= (int) floor(($k[3] + 1.0) / KUNING_PETAK_M); $y++) {
                foreach ($petak[$x . ':' . $y] ?? [] as $j) {
                    $temu[$j] = true;
                }
            }
        }

        return array_keys($temu);
    };

    // ---- label peta master, untuk mengidentifikasi garis yang tidak punya bidang ----
    $label = [];
    foreach (dxfTeks($dxf) as $t) {
        if (in_array((string) $t['layer'], [NAMA_LAPISAN, NIB_LAPISAN, DAFTAR_LAPISAN, NIS_LAPISAN], true)) {
            $label[] = ['lapisan' => (string) $t['layer'], 'teks' => (string) $t['teks'],
                'w' => dxfKeWgs84((float) $t['x'], (float) $t['y'])];
        }
    }
    $isiLabel = static function (array $ringDerajat) use ($label): array {
        $temu = [];
        foreach ($label as $l) {
            if (titikDiDalamCincin($l['w'], $ringDerajat)) {
                $temu[] = ['lapisan' => $l['lapisan'], 'teks' => $l['teks']];
            }
        }

        return $temu;
    };

    $faktor = METER_PER_DERAJAT * METER_PER_DERAJAT * cos(deg2rad(LINTANG_DESA));
    // Faktor luas ditulis sebagai angka, bukan parameter: MariaDB menolak `geom * ?`.
    $faktorSql = sprintf('%.10F', $faktor);
    $ubah = [];
    $yatim = [];
    $berpadan = 0;
    foreach ($kuning as $i => $k) {
        $nomor = $i + 1;
        $luasKuning = abs(poligonLuas($k['titik']));
        $derajat = cincinTutup($keDerajatTitik($k['titik']));
        $wktKuning = wktPoligonTepat($derajat);

        $calon = db_all(
            'SELECT id, ST_Area(ST_Intersection(geom, ST_GeomFromText(?, 4326))) * ' . $faktorSql . ' AS isi_m2,
                    ST_Area(geom) * ' . $faktorSql . ' AS diri_m2
               FROM bidang
              WHERE status NOT IN (\'dihapus\', \'duplikat\')
                AND MBRIntersects(geom, ST_GeomFromText(?, 4326))
                AND ST_Intersects(geom, ST_GeomFromText(?, 4326))',
            [$wktKuning, $wktKuning, $wktKuning]
        );
        $terbaik = null;
        foreach ($calon as $c) {
            $jelas = (float) $c['isi_m2'] / max($luasKuning, 0.001);
            if ($terbaik === null || $jelas > $terbaik['jelas']) {
                $terbaik = ['id' => (int) $c['id'], 'jelas' => $jelas,
                    'tutup' => (float) $c['isi_m2'] / max((float) $c['diri_m2'], 1e-12)];
            }
        }

        if ($terbaik === null || $terbaik['jelas'] < KUNING_JELAS) {
            $yatim[] = ['nomor' => $nomor, 'lapisan' => $k['layer'], 'luas_gambar' => round($luasKuning, 1),
                'derajat' => $derajat, 'label' => $isiLabel($derajat),
                'tolak' => $terbaik === null ? null
                    : ['kode' => $hidup[$terbaik['id']]['kode'], 'jelas' => round($terbaik['jelas'], 3)]];
            continue;
        }

        $berpadan++;
        $id = $terbaik['id'];
        $sendiri = $hidup[$id];

        // Tetangga: bidang hidup yang berbagi tepi.
        $tetangga = [];
        foreach ($kandidat($sendiri['kotak']) as $j) {
            if ($j === $id) {
                continue;
            }
            $l = $hidup[$j];
            if ($l['kotak'][0] > $sendiri['kotak'][2] + 1 || $l['kotak'][2] < $sendiri['kotak'][0] - 1
                || $l['kotak'][1] > $sendiri['kotak'][3] + 1 || $l['kotak'][3] < $sendiri['kotak'][1] - 1) {
                continue;
            }
            $jeda = INF;
            foreach ($sendiri['meter'] as $p) {
                $jeda = min($jeda, titikKeCincin($p, $l['meter'])[0]);
            }
            if ($jeda <= KUNING_TETANGGA_M) {
                $tetangga[] = ['id' => $j, 'kode' => $l['kode'], 'jeda' => round($jeda, 3)];
            }
        }
        usort($tetangga, static fn(array $a, array $b): int => $a['jeda'] <=> $b['jeda']);

        // Tarik sudut yang sudah dekat ke garis tetangga yang paling dekat.
        $sesudah = [];
        $ditarik = 0;
        $pindah = [];
        foreach ($sendiri['meter'] as $p) {
            $milik = null;
            $jauh = INF;
            $kaki = $p;
            foreach ($tetangga as $t) {
                [$d, $q] = titikKeCincin($p, $hidup[$t['id']]['meter']);
                if ($d < $jauh) {
                    $jauh = $d;
                    $kaki = $q;
                    $milik = $t['kode'];
                }
            }
            if ($milik !== null && $jauh <= $tarikM && $jauh > 1e-6) {
                $sesudah[] = $kaki;
                $pindah[] = $jauh;
                $ditarik++;
            } else {
                $sesudah[] = $p;
            }
        }
        // Cincin yang ditulis selalu dirapikan: kalau tidak ada sudut yang ditarik tapi cincin
        // itu berdurul (sudut menempel <= KUNING_SUDUT_M), durulnya yang dibuang.
        $ringSesudah = cincinRapi($ditarik > 0 ? array_map($keDerajat, $sesudah) : $sendiri['derajat']);
        if ($ditarik === 0 && count($ringSesudah) === count(cincinTutup($sendiri['derajat']))) {
            continue;
        }
        $wktSesudah = wktPoligonTepat($ringSesudah);
        if ($wktSesudah === '') {
            continue;
        }
        // Sudut yang sudah duduk di garis tetangga tetap dihitung "ditarik" oleh `jeda <= --tarik`,
        // jadi tanpa penyaringan ini ronde kedua melaporkan 29 bidang "berubah" padahal tidak ada
        // yang bergeser. Yang dibandingkan adalah **teks hasil formatter yang sama**, bukan angka
        // mentah: titik yang diproyeksikan ulang menghasilkan debu float ~1e-9 derajat yang hilang
        // di bawah presisi simpan (%.8F), dan `ST_AsText()` sendiri menulis 110.07181610 sebagai
        // 110.0718161 sehingga perbandingan dengan kolom `geom` selalu kalah.
        if ($wktSesudah === wktPoligonTepat(cincinTutup($sendiri['derajat']))) {
            continue;
        }
        // Yang dibandingkan adalah geometri tersimpan bidang itu sendiri, bukan garis kuningnya:
        // angka inilah yang benar-benar berubah di basis data.
        $luasLama = luasWkt($sendiri['wkt']);
        $luasBaru = luasWkt($wktSesudah);
        $pusatBaru = pusatCincin($ringSesudah);
        if ($pusatBaru === null) {
            continue;
        }
        $ubah[] = ['id' => $id, 'kode' => $sendiri['kode'], 'nomor' => $nomor,
            'lapisan' => $k['layer'], 'wkt_lama' => $sendiri['wkt'], 'wkt_baru' => $wktSesudah,
            'pusat' => $pusatBaru,
            'luas_lama' => round($luasLama, 2), 'luas_baru' => round($luasBaru, 2),
            'delta' => round($luasBaru - $luasLama, 2),
            'sudut' => $ditarik, 'jeda_maks' => $pindah === [] ? 0.0 : round(max($pindah), 3),
            'sudut_dibuang' => count(cincinTutup($sendiri['derajat'])) - count($ringSesudah),
            'pusat_lama' => [(float) $sendiri['pusat'][0], (float) $sendiri['pusat'][1]],
            'tetangga' => count($tetangga), 'cakup' => round(100 * $terbaik['jelas'], 1)];
    }

    // ---- garis kuning yatim: potong sampai pas ke bidang yang sudah ada ----
    $blok = [];
    foreach (db_all('SELECT id, kode, ST_AsText(geom) AS w FROM blok') as $bl) {
        $blok[(int) $bl['id']] = ['kode' => (string) $bl['kode'], 'bagian' => wktBagian((string) $bl['w']),
            'wkt' => (string) $bl['w']];
    }
    foreach ($yatim as $n => $y) {
        $wktGaris = wktPoligonTepat($y['derajat']);
        $penumpang = db_all(
            'SELECT id, kode, status, ST_Area(ST_Intersection(geom, ST_GeomFromText(?, 4326))) * ' . $faktorSql . ' AS isi_m2
               FROM bidang
              WHERE status NOT IN (\'dihapus\', \'duplikat\')
                AND MBRIntersects(geom, ST_GeomFromText(?, 4326))
                AND ST_Intersects(geom, ST_GeomFromText(?, 4326))
              ORDER BY isi_m2 DESC',
            [$wktGaris, $wktGaris, $wktGaris]
        );
        $sisa = $wktGaris;
        foreach ($penumpang as $p) {
            $sisa = (string) db_value(
                'SELECT ST_AsText(ST_Difference(ST_GeomFromText(?, 4326), ST_GeomFromText(?, 4326)))',
                [$sisa, (string) db_value('SELECT ST_AsText(geom) FROM bidang WHERE id = ?', [(int) $p['id']])]
            );
        }
        $bagian = wktBagian($sisa);
        $terbesar = cincinRapi(wktCincin($sisa));
        if (count($terbesar) < 4) {
            $yatim[$n]['ditolak'] = 'sisa hasil potongan terlalu tipis untuk dipasang';
            continue;
        }
        $wktBaru = wktPoligonTepat($terbesar);
        $luas = luasWkt($wktBaru);
        if ($luas < DXF_PERSIL_MIN_M2) {
            $yatim[$n]['ditolak'] = 'sisa hasil potongan hanya ' . round($luas, 1) . ' m2';
            continue;
        }
        $pusat = pusatWkt($wktBaru);
        if ($pusat === null) {
            $yatim[$n]['ditolak'] = 'tidak ada titik yang jatuh di dalam cincin hasilnya; '
                . 'poligonya pasti menembus dirinya sendiri';
            continue;
        }
        $kodBlok = null;
        $terdekat = null;
        foreach ($blok as $b) {
            if (titikDiDalamBagian($pusat, $b['bagian'])) {
                $kodBlok = $b['kode'];
                break;
            }
        }
        if ($kodBlok === null) {
            // Konvensi §3.21: blok tidak ditebak, tapi garis terdekat plus jaraknya ditulis
            // ke catatan supaya petugas punya alamat untuk mencarinya.
            $terdekat = garisBlokTerdekat($pusat, $blok);
        }
        $nama = '';
        $nib = '';
        foreach ($y['label'] as $l) {
            if ($l['lapisan'] === NAMA_LAPISAN && $nama === '') {
                $nama = $l['teks'];
            }
            if ($l['lapisan'] === NIB_LAPISAN && $nib === '') {
                $nib = $l['teks'];
            }
        }
        $yatim[$n] += ['wkt' => $wktBaru, 'luas_pasang' => round($luas, 1), 'pusat' => $pusat,
            'blok' => $kodBlok, 'nama' => $nama, 'nib' => $nib, 'bagian' => count($bagian),
            'garis_terdekat' => $terdekat,
            'penumpang' => array_map(static fn(array $p): array => ['kode' => $p['kode'],
                'isi_m2' => round((float) $p['isi_m2'], 1)], $penumpang)];
    }
    $pasang = array_values(array_filter($yatim, static fn(array $y): bool => isset($y['wkt'])));

    // ---- laporan ----
    printf("garis kuning tertutup terpasang=%d  berpadan=%d  yatim=%d\n",
        count($kuning), $berpadan, count($yatim));
    foreach ($yatim as $y) {
        printf("  #%d %-9s %9s m2  %s%s\n", $y['nomor'], $y['lapisan'],
            number_format($y['luas_gambar'], 1, ',', '.'),
            isset($y['wkt'])
                ? 'dipasang jadi ' . number_format($y['luas_pasang'], 1, ',', '.') . ' m2 setelah dipotong'
                    . ' (' . $y['bagian'] . ' kepul, terbesar dipakai), blok ' . ($y['blok'] ?? 'tidak ada')
                    . ', label: ' . (implode(' ', array_column($y['label'], 'teks')) ?: 'tanpa label')
                : 'ditahan: ' . (string) ($y['ditolak'] ?? 'tidak ada bidang padan'),
            $y['tolak'] === null ? '' : ' — padan terdekat ' . $y['tolak']['kode']
                . ' hanya menjelaskan ' . number_format(100 * $y['tolak']['jelas'], 1, ',', '.') . '% garisnya');
    }
    printf("\nbidang yang dijajarkan: %d, total delta luas %s m2\n", count($ubah),
        number_format(array_sum(array_column($ubah, 'delta')), 2, ',', '.'));
    foreach ($ubah as $u) {
        printf("  %-20s kuning #%d  sudut ditarik=%-3d jeda maks=%6s m  luas %9s -> %9s m2  tetangga=%d%s\n",
            $u['kode'], $u['nomor'], $u['sudut'], number_format($u['jeda_maks'], 3, ',', '.'),
            number_format($u['luas_lama'], 2, ',', '.'), number_format($u['luas_baru'], 2, ',', '.'),
            $u['tetangga'],
            $u['sudut_dibuang'] > 0 ? '  durul dibuang=' . $u['sudut_dibuang'] : '');
    }

    $hasil = [
        'jenis' => 'kuning-master', 'berkas' => $dxf, 'tarik_m' => $tarikM,
        'kuning' => count($kuning), 'berpadan' => $berpadan, 'yatim' => count($yatim),
        'dijajarkan' => count($ubah), 'delta_m2' => round(array_sum(array_column($ubah, 'delta')), 2),
        'bidang_baru' => count($pasang), 'terapkan' => $terapkan,
    ];
    if (!$terapkan) {
        $hasil['contoh_yatim'] = array_map(static fn(array $y): array => [
            'nomor' => $y['nomor'], 'lapisan' => $y['lapisan'], 'luas_m2' => $y['luas_gambar'],
            'luas_pasang_m2' => $y['luas_pasang'], 'blok' => $y['blok'], 'nama' => $y['nama'],
            'nib' => $y['nib'], 'garis_blok_terdekat' => $y['garis_terdekat'],
            'label' => array_column($y['label'], 'teks'),
        ], $pasang);
        $hasil['yatim_ditolak'] = array_map(static fn(array $y): array => [
            'nomor' => $y['nomor'], 'lapisan' => $y['lapisan'], 'alasan' => (string) ($y['ditolak'] ?? ''),
        ], array_values(array_filter($yatim, static fn(array $y): bool => !isset($y['wkt']))));

        return $hasil;
    }

    $cadangan = cadanganBlok('kuning-master', [
        'waktu' => date('c'),
        'perintah' => 'php tools/impor.php kuning --terapkan',
        'berkas' => $dxf,
        'aturan' => 'tarik sudut <= ' . $tarikM . ' m ke garis tetangga; tetangga berbagi tepi <= '
            . KUNING_TETANGGA_M . ' m; padanan harus menjelaskan >= ' . (100 * KUNING_JELAS) . '% garis kuning; '
            . 'garis yatim dipotong sampai pas ke bidang hidup sebelum dipasang',
        'dijumlah' => ['kuning' => count($kuning), 'berpadan' => $berpadan, 'yatim' => count($yatim),
            'dijajarkan' => count($ubah), 'bidang_baru' => count($pasang)],
        'jajaran' => array_map(static fn(array $u): array => [
            'id' => $u['id'], 'kode' => $u['kode'], 'sudut' => $u['sudut'],
            'luas_lama' => $u['luas_lama'], 'luas_baru' => $u['luas_baru'], 'wkt_lama' => $u['wkt_lama'],
            'pusat_lama' => $u['pusat_lama'], 'pusat_baru' => $u['pusat'],
        ], $ubah),
        'yatim' => array_map(static fn(array $y): array => [
            'nomor' => $y['nomor'], 'lapisan' => $y['lapisan'], 'luas_gambar' => $y['luas_gambar'],
            'wkt' => $y['wkt'], 'blok' => $y['blok'], 'label' => array_column($y['label'], 'teks'),
            'penumpang' => $y['penumpang'],
        ], $yatim),
    ]);

    $nomor = [];
    foreach (DXF_PERSIL_LAPISAN as $layer => $konfig) {
        $tertinggi = 0;
        foreach (db_all('SELECT kode FROM bidang WHERE kode LIKE ?', [$konfig['kode'] . '-%']) as $b) {
            if (preg_match('/-(\d+)$/', (string) $b['kode'], $m) === 1) {
                $tertinggi = max($tertinggi, (int) $m[1]);
            }
        }
        $nomor[$layer] = $tertinggi;
    }
    $idBlok = array_column(db_all('SELECT id, kode FROM blok'), 'id', 'kode');
    $idJenisHak = [];
    foreach (db_all('SELECT kode, id FROM jenis_hak') as $baris) {
        $idJenisHak[(string) $baris['kode']] = (int) $baris['id'];
    }
    $idBlm = $idJenisHak[JENIS_HAK_DEFAULT] ?? null;
    if ($idBlm === null) {
        throw new RuntimeException('Master jenis_hak kode "' . JENIS_HAK_DEFAULT . '" tidak ada. Jalankan sql/01_skema.sql.');
    }
    $hari = date('Y-m-d');

    $pdo = db();
    $isiBaru = $pdo->prepare(
        'INSERT INTO bidang (kode, tipe, nib, blok_id, luas_m2, status, sumber, catatan, geom, pusat_lon, pusat_lat)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?)'
    );
    $isiHak = $pdo->prepare('INSERT INTO hak (bidang_id, jenis_hak_id, pemegang_nama, catatan) VALUES (?, ?, ?, ?)');
    $pasangGeom = $pdo->prepare('UPDATE bidang SET geom = ST_GeomFromText(?, 4326), pusat_lon = ?, pusat_lat = ? WHERE id = ?');

    db_transaksi(static function (PDO $connect) use ($ubah, $pasangGeom): void {
        foreach ($ubah as $u) {
            $pasangGeom->execute([$u['wkt_baru'], $u['pusat'][0], $u['pusat'][1], $u['id']]);
        }
    });

    foreach ($pasang as $y) {
        $nomor[$y['lapisan']] = ($nomor[$y['lapisan']] ?? 0) + 1;
        $kodeBaru = (DXF_PERSIL_LAPISAN[$y['lapisan']]['kode'] ?? 'DXF-KUNING') . '-' . sprintf('%04d', $nomor[$y['lapisan']]);
        $konfig = DXF_PERSIL_LAPISAN[$y['lapisan']] ?? ['tipe' => 'tanah'];
        $catatan = 'Garis kuning (ACI 2) MASTER.dxf yang tidak punya bidang padan; dipasang ' . $hari
            . ' oleh perintah impor.php kuning sesudah dipotong sampai pas ke bidang tetangga'
            . ' (' . number_format($y['luas_gambar'], 1, ',', '.') . ' m2 utuh menjadi '
            . number_format($y['luas_pasang'], 1, ',', '.') . ' m2).'
            . ($y['blok'] === null
                ? ' Bloknya dibiarkan kosong: titik tengahnya di luar semua garis blok; garis blok '
                    . 'terdekat ' . (string) $y['garis_terdekat']['kode'] . ' berjarak '
                    . number_format((float) $y['garis_terdekat']['jarak_m'], 1, ',', '.')
                    . ' m — tentukan dengan `impor.php ubah-blok <kode blok> ' . $kodeBaru . ' --terapkan`.'
                : '');

        db_transaksi(static function (PDO $connect) use ($isiBaru, $isiHak, $y, $kodeBaru, $konfig, $idBlok, $idBlm, $catatan): void {
            $isiBaru->execute([
                $kodeBaru, $konfig['tipe'], $y['nib'] === '' ? null : $y['nib'],
                $y['blok'] === null ? null : (int) $idBlok[$y['blok']],
                $y['luas_pasang'], 'perlu_verifikasi', $y['lapisan'], $catatan,
                $y['wkt'], $y['pusat'][0], $y['pusat'][1],
            ]);
            $isiHak->execute([
                (int) $connect->lastInsertId(), $idBlm,
                $y['nama'] === '' ? null : $y['nama'],
                'Bidang dari garis kuning MASTER.dxf; nama dan nomor diambil dari label peta, belum dikonfirmasi lapangan.',
            ]);
        });

        audit('impor', 'bidang', $kodeBaru,
            'Garis kuning #' . $y['nomor'] . ' (lapisan ' . $y['lapisan'] . ') dipasang menjadi ' . $kodeBaru
            . ': ' . number_format($y['luas_pasang'], 1, ',', '.') . ' m2, ' . ($y['blok'] ?? 'blok kosong')
            . ', nama label "' . $y['nama'] . '", NIB label "' . $y['nib'] . '". Cadangan: ' . $cadangan,
            ['wkt_garis_utuh' => wktPoligonTepat($y['derajat']), 'luas_m2' => $y['luas_gambar']],
            ['kode' => $kodeBaru, 'status' => 'perlu_verifikasi', 'blok' => $y['blok'], 'luas_m2' => $y['luas_pasang']]);
    }

    foreach ($ubah as $u) {
        audit('ubah', 'bidang', $u['id'],
            'Tepi ' . $u['kode'] . ' dijajarkan ke garis tetangga mengikuti polyline kuning #' . $u['nomor']
            . ': ' . $u['sudut'] . ' sudut dipindah'
            . ($u['sudut_dibuang'] > 0 ? ', ' . $u['sudut_dibuang'] . ' sudut berdurul dibuang' : '')
            . ', luas gambar '
            . number_format($u['luas_lama'], 2, ',', '.') . ' -> ' . number_format($u['luas_baru'], 2, ',', '.')
            . ' m2. Luas tercatat tidak diubah. Cadangan: ' . $cadangan,
            ['wkt' => $u['wkt_lama'], 'luas_gambar_m2' => $u['luas_lama'], 'pusat' => $u['pusat_lama']],
            ['luas_gambar_m2' => $u['luas_baru'], 'pusat' => $u['pusat'],
                'sudut_dipindah' => $u['sudut'], 'sudut_dibuang' => $u['sudut_dibuang']]);
    }

    $hasil['cadangan'] = $cadangan;

    return $hasil;
}
