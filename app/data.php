<?php
declare(strict_types=1);

/**
 * Query pembaca yang dipakai bersama oleh dasbor dan api/statistik.php.
 * Ditaruh terpisah supaya angka di layar dan angka di JSON tidak pernah
 * bisa berbeda karena dua salinan SQL.
 */

/** Ringkasan bidang tanah (tanpa geometri). */
function statistik_bidang(): array
{
    return db_one(
        "SELECT COUNT(*) AS total,
                SUM(tipe = 'tanah') AS tanah,
                SUM(tipe = 'lain')  AS lain,
                SUM(status = 'perlu_verifikasi') AS perlu_verifikasi,
                SUM(status = 'aktif') AS aktif,
                SUM(blok_id IS NULL) AS tanpa_blok,
                SUM(pusat_lon IS NULL) AS tanpa_pusat,
                ROUND(SUM(luas_m2)) AS total_luas,
                (SELECT COUNT(*) FROM bidang WHERE status = 'duplikat') AS duplikat,
                (SELECT ROUND(SUM(luas_m2)) FROM bidang WHERE status = 'duplikat') AS luas_duplikat
         FROM bidang WHERE status NOT IN ('dihapus', 'duplikat')"
    ) ?? [];
}

/** Jumlah bidang per jenis hak, termasuk jenis yang belum terpakai. */
function komposisi_hak(): array
{
    $terpakai = [];
    foreach (db_all(
        "SELECT h.jenis_hak_id, COUNT(*) AS jumlah
         FROM hak h
         JOIN bidang b ON b.id = h.bidang_id AND b.status NOT IN ('dihapus', 'duplikat')
         GROUP BY h.jenis_hak_id"
    ) as $baris) {
        $terpakai[(int) $baris['jenis_hak_id']] = (int) $baris['jumlah'];
    }

    $hasil = [];
    foreach (daftar_jenis_hak() as $jenis) {
        $hasil[] = [
            'kode' => $jenis['kode'],
            'nama' => $jenis['nama'],
            'jumlah' => $terpakai[(int) $jenis['id']] ?? 0,
        ];
    }
    usort($hasil, static fn(array $a, array $b): int => $b['jumlah'] <=> $a['jumlah']);

    return $hasil;
}

/** Ketetapan, realisasi, dan piutang PBB satu tahun pajak. */
function statistik_pbb(int $tahun): array
{
    $baris = db_one(
        "SELECT COUNT(DISTINCT s.nop) AS objek,
                COALESCE(SUM(s.pajak_ditetapkan), 0) AS ketetapan,
                COALESCE(SUM(s.terbayar), 0) AS terbayar,
                COALESCE(SUM(s.sisa), 0) AS piutang,
                SUM(s.status_bayar = 'Lunas') AS lunas,
                SUM(s.status_bayar <> 'Lunas') AS belum
         FROM sppt s WHERE s.tahun_pajak = ?",
        [$tahun]
    ) ?? [];

    $ketetapan = (float) ($baris['ketetapan'] ?? 0);
    $terbayar = (float) ($baris['terbayar'] ?? 0);

    return [
        'tahun' => $tahun,
        'objek' => (int) ($baris['objek'] ?? 0),
        'ketetapan' => $ketetapan,
        'terbayar' => $terbayar,
        'piutang' => (float) ($baris['piutang'] ?? 0),
        'lunas' => (int) ($baris['lunas'] ?? 0),
        'belum' => (int) ($baris['belum'] ?? 0),
        'persen' => $ketetapan > 0 ? round($terbayar / $ketetapan * 100, 2) : 0.0,
    ];
}

/** Rekap PBB per dusun untuk satu tahun pajak. */
function rekap_pbb_dusun(int $tahun): array
{
    return db_all(
        'SELECT du.id, du.nama,
                COUNT(s.nop) AS objek,
                COALESCE(SUM(s.pajak_ditetapkan), 0) AS ketetapan,
                COALESCE(SUM(s.terbayar), 0) AS terbayar,
                COALESCE(SUM(s.sisa), 0) AS piutang
         FROM dusun du
         LEFT JOIN objek_pajak o ON o.dusun_id = du.id
         LEFT JOIN sppt s ON s.nop = o.nop AND s.tahun_pajak = ?
         GROUP BY du.id, du.nama
         ORDER BY piutang DESC, du.nama',
        [$tahun]
    );
}

/** Daftar tahun yang tersedia di tabel sppt, terbaru lebih dulu. */
function daftar_tahun_pbb(): array
{
    return array_map(
        static fn(array $b): int => (int) $b['tahun_pajak'],
        db_all('SELECT DISTINCT tahun_pajak FROM sppt ORDER BY tahun_pajak DESC')
    );
}

/** Peralihan terbaru, opsional per tahun. */
function peralihan_terbaru(int $batas = 6, ?int $tahun = null): array
{
    $sql = 'SELECT p.id, p.kode, p.tanggal, p.status, p.pihak_pertama, p.pihak_kedua,
                   p.luas_m2, p.nilai_rp, p.nop, j.nama AS jenis, du.nama AS dusun,
                   b.kode AS bidang_kode
            FROM peralihan p
            JOIN jenis_peralihan j ON j.id = p.jenis_peralihan_id
            LEFT JOIN dusun du ON du.id = p.dusun_id
            LEFT JOIN bidang b ON b.id = p.bidang_id';
    $param = [];

    if ($tahun !== null) {
        $sql .= ' WHERE YEAR(p.tanggal) = ?';
        $param[] = $tahun;
    }
    $sql .= ' ORDER BY p.tanggal DESC, p.id DESC LIMIT ' . max(1, min(50, $batas));

    return db_all($sql, $param);
}

/** Jumlah peralihan per jenis untuk satu tahun. */
function sebaran_peralihan(int $tahun): array
{
    return db_all(
        'SELECT j.nama AS jenis, COUNT(p.id) AS jumlah
         FROM peralihan p
         JOIN jenis_peralihan j ON j.id = p.jenis_peralihan_id
         WHERE YEAR(p.tanggal) = ?
         GROUP BY j.id, j.nama
         ORDER BY jumlah DESC',
        [$tahun]
    );
}

/** Rekap laporan masyarakat per status. */
function statistik_laporan(): array
{
    return db_one(
        "SELECT COUNT(*) AS total,
                SUM(status = 'Menunggu') AS menunggu,
                SUM(status = 'Diproses') AS diproses,
                SUM(status = 'Selesai') AS selesai,
                SUM(status = 'Ditolak') AS ditolak
         FROM laporan"
    ) ?? [];
}

/** Laporan yang paling baru masuk. */
function laporan_terbaru(int $batas = 6): array
{
    return db_all(
        'SELECT id, nomor, kategori, status, nama_pelapor, keterangan, tanggal_lapor, nop, bidang_id
         FROM laporan ORDER BY tanggal_lapor DESC, id DESC LIMIT ' . max(1, min(50, $batas))
    );
}

/** Bidang yang paling butuh tindakan: belum ada jenis hak atau perlu verifikasi. */
function bidang_perlu_verifikasi(int $batas = 8): array
{
    return db_all(
        "SELECT b.id, b.kode, b.tipe, b.luas_m2, b.status, bl.kode AS blok, du.nama AS dusun,
                jh.nama AS hak_nama, h.pemegang_nama
         FROM bidang b
         LEFT JOIN blok bl ON bl.id = b.blok_id
         LEFT JOIN dusun du ON du.id = b.dusun_id
         LEFT JOIN hak h ON h.bidang_id = b.id
         LEFT JOIN jenis_hak jh ON jh.id = h.jenis_hak_id
         WHERE b.status = 'perlu_verifikasi'
         ORDER BY b.kode LIMIT " . max(1, min(50, $batas))
    );
}

/** Aktivitas terbaru dari audit_log; hanya Super Admin yang membuka halaman ini. */
function aktivitas_terbaru(int $batas = 12): array
{
    return db_all(
        'SELECT a.aksi, a.objek, a.objek_id, a.keterangan, a.waktu,
                u.nama_lengkap AS pelaksana
         FROM audit_log a
         LEFT JOIN pengguna u ON u.id = a.pengguna_id
         ORDER BY a.id DESC LIMIT ' . max(1, min(50, $batas))
    );
}

/**
 * Potongan JOIN yang dipakai bersama oleh lapisan peta, daftar bidang, dan
 * detail bidang. Jangan ditulis ulang di halaman supaya kolomnya seragam.
 */
const BIDANG_JOIN = '
    FROM bidang b
    LEFT JOIN blok bl              ON bl.id = b.blok_id
    LEFT JOIN dusun du             ON du.id = b.dusun_id
    LEFT JOIN jenis_penggunaan jp  ON jp.id = b.jenis_penggunaan_id
    LEFT JOIN hak h                ON h.bidang_id = b.id
    LEFT JOIN jenis_hak jh         ON jh.id = h.jenis_hak_id
    LEFT JOIN objek_pajak o        ON o.bidang_id = b.id';

/**
 * Saring bidang dari parameter query. Nilai hanya lewat placeholder.
 * Kembaran berstatus 'duplikat' disembunyikan dari peta dan daftar supaya luas
 * tidak terhitung ganda; tetap muncul bila status itu dipilih di saringan.
 * @return array{0: string, 1: array} [kondisi WHERE, param]
 */
function bidang_filter(array $q): array
{
    $status = trim((string) ($q['status'] ?? ''));
    if ($status === 'duplikat' || $status === 'dihapus') {
        $kondisi = ['b.status = ?'];
        $param = [$status];
    } else {
        $kondisi = ["b.status NOT IN ('dihapus', 'duplikat')"];
        $param = [];
        if ($status !== '') {
            $kondisi[] = 'b.status = ?';
            $param[] = $status;
        }
    }

    if (!empty($q['tipe']) && in_array($q['tipe'], ['tanah', 'lain'], true)) {
        $kondisi[] = 'b.tipe = ?';
        $param[] = $q['tipe'];
    }
    if (!empty($q['dusun'])) {
        $kondisi[] = 'b.dusun_id = ?';
        $param[] = (int) $q['dusun'];
    }
    if (!empty($q['blok'])) {
        $kondisi[] = 'bl.kode = ?';
        $param[] = (string) $q['blok'];
    }
    if (!empty($q['tanpaBlok'])) {
        $kondisi[] = 'b.blok_id IS NULL';
    }
    if (!empty($q['hak'])) {
        $kondisi[] = 'jh.kode = ?';
        $param[] = (string) $q['hak'];
    }
    if (!empty($q['cari'])) {
        $kondisi[] = '(b.kode LIKE ? OR b.nib LIKE ? OR b.nis LIKE ? OR b.nomor_daftar LIKE ?
                      OR h.pemegang_nama LIKE ? OR o.nama_wp LIKE ? OR o.nop LIKE ?)';
        $kata = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $q['cari']) . '%';
        array_push($param, $kata, $kata, $kata, $kata, $kata, $kata, $kata);
    }
    if (!empty($q['punyaNop'])) {
        $kondisi[] = 'o.nop IS NOT NULL';
    }

    return ['WHERE ' . implode(' AND ', $kondisi), $param];
}

/** Satu halaman daftar bidang beserta jumlah total baris yang cocok. */
function daftar_bidang(array $q, int $halaman, int $perHalaman): array
{
    [$where, $param] = bidang_filter($q);
    $total = (int) db_value('SELECT COUNT(DISTINCT b.id) ' . BIDANG_JOIN . ' ' . $where, $param);
    $mulai = ($halaman - 1) * $perHalaman;

    $items = db_all(
        "SELECT b.id, b.kode, b.tipe, b.nib, b.nis, b.nomor_daftar, b.luas_m2, b.status, b.pusat_lon, b.pusat_lat,
                bl.kode AS blok, du.nama AS dusun, jp.nama AS penggunaan,
                jh.kode AS hak_kode, jh.nama AS hak_nama, h.pemegang_nama, h.no_sertifikat,
                o.nop, o.nama_wp,
                (SELECT s.status_bayar FROM sppt s WHERE s.nop = o.nop
                  ORDER BY s.tahun_pajak DESC LIMIT 1) AS status_bayar
         " . BIDANG_JOIN . ' ' . $where . "
         ORDER BY b.kode LIMIT {$perHalaman} OFFSET {$mulai}",
        $param
    );

    return ['total' => $total, 'items' => $items];
}

/**
 * Blok kandidat untuk bidang yang belum punya blok: blok yang bersentuhan tepi
 * (≤ 0,5 m) dan blok terdekat dari titik tengah. Indeks spasial bidang sengaja
 * diabaikan karena MariaDB 10.4 melempar ER_READ_ONLY_TRANSACTION (1207) begitu
 * indeks itu dipakai di join self-nya (MDEV-26123).
 *
 * @param array<int, array<string, mixed>> $baris minimal berisi id, pusat_lon, pusat_lat
 * @return array<int, array{tepi: string[], terdekat: array{kode: string, jarak_m: float}|null}>
 */
function kandidat_blok_bidang(array $baris): array
{
    if ($baris === []) {
        return [];
    }

    $id = array_map(static fn(array $b): int => (int) $b['id'], $baris);
    $tanya = implode(',', array_fill(0, count($id), '?'));

    $tepi = [];
    $barisTepi = db_all(
        "SELECT DISTINCT t.id, bl.kode
           FROM bidang t
           JOIN bidang b IGNORE INDEX (spn_bidang_geom)
             ON MBRIntersects(b.geom, t.geom)
            AND ST_Distance(b.geom, t.geom) * 111320.0 <= ?
           JOIN blok bl ON bl.id = b.blok_id
          WHERE t.id IN ({$tanya})
            AND b.status NOT IN ('dihapus', 'duplikat')
            AND b.blok_id IS NOT NULL AND b.geom IS NOT NULL
          ORDER BY t.id, bl.kode",
        array_merge([0.5], $id)
    );
    foreach ($barisTepi as $r) {
        $tepi[(int) $r['id']][] = (string) $r['kode'];
    }

    $dekat = db()->prepare(
        'SELECT bl.kode, ROUND(ST_Distance(bl.geom, ST_GeomFromText(?, 4326)) * 111320.0, 1) AS meter
           FROM blok bl
          WHERE bl.geom IS NOT NULL
          ORDER BY meter LIMIT 1'
    );

    $hasil = [];
    foreach ($baris as $b) {
        $idBidang = (int) $b['id'];
        $terdekat = null;
        if ($b['pusat_lon'] !== null && $b['pusat_lat'] !== null) {
            $dekat->execute(['POINT(' . (float) $b['pusat_lon'] . ' ' . (float) $b['pusat_lat'] . ')']);
            $d = $dekat->fetch();
            if (is_array($d)) {
                $terdekat = ['kode' => (string) $d['kode'], 'jarak_m' => (float) $d['meter']];
            }
        }
        $hasil[$idBidang] = ['tepi' => $tepi[$idBidang] ?? [], 'terdekat' => $terdekat];
    }

    return $hasil;
}

/**
 * Detail satu bidang: identitas, hak, pajak, peralihan, laporan, dan Buku C.
 * Dipakai api/bidang.php dan bidang.php supaya keduanya menampilkan angka
 * dan riwayat yang sama.
 */
function detail_bidang(int $idBidang): ?array
{
    $utama = db_one(
        "SELECT b.id, b.kode, b.tipe, b.nib, b.nis, b.nomor_daftar, b.luas_m2, b.status, b.sumber, b.catatan,
                b.dibuat_pada, b.diubah_pada, b.dusun_id, b.blok_id, b.jenis_penggunaan_id,
                b.pusat_lon, b.pusat_lat,
                bl.kode AS blok, du.nama AS dusun, jp.nama AS penggunaan,
                ST_AsGeoJSON(b.geom) AS geojson
         FROM bidang b
         LEFT JOIN blok bl             ON bl.id = b.blok_id
         LEFT JOIN dusun du            ON du.id = b.dusun_id
         LEFT JOIN jenis_penggunaan jp ON jp.id = b.jenis_penggunaan_id
         WHERE b.id = ? LIMIT 1",
        [$idBidang]
    );
    if ($utama === null) {
        return null;
    }
    $geo = json_decode((string) $utama['geojson'], true);
    $utama['geojson'] = is_array($geo) ? $geo : null;

    $utama['hak'] = db_all(
        'SELECT h.id, h.jenis_hak_id, h.no_sertifikat, h.no_hak_pakai, h.pemegang_nama,
                h.diperoleh_pada, h.catatan, h.buku_c_id,
                jh.kode AS hak_kode, jh.nama AS hak_nama, bc.nomor AS buku_c_nomor
         FROM hak h
         JOIN jenis_hak jh ON jh.id = h.jenis_hak_id
         LEFT JOIN buku_c bc ON bc.id = h.buku_c_id
         WHERE h.bidang_id = ? ORDER BY h.id DESC',
        [$idBidang]
    );
    $utama['pajak'] = db_all(
        'SELECT o.nop, o.nama_wp, o.alamat_op, s.tahun_pajak, s.pajak_ditetapkan, s.terbayar,
                s.sisa, s.status_bayar, s.tanggal_bayar
         FROM objek_pajak o
         LEFT JOIN sppt s ON s.nop = o.nop
         WHERE o.bidang_id = ? ORDER BY s.tahun_pajak DESC',
        [$idBidang]
    );
    $utama['peralihan'] = db_all(
        'SELECT p.id, p.kode, p.tanggal, p.status, p.pihak_pertama, p.pihak_kedua, p.nilai_rp,
                j.nama AS jenis
         FROM peralihan p
         JOIN jenis_peralihan j ON j.id = p.jenis_peralihan_id
         WHERE p.bidang_id = ? ORDER BY p.tanggal DESC LIMIT 10',
        [$idBidang]
    );
    $utama['laporan'] = db_all(
        'SELECT id, nomor, kategori, status, nama_pelapor, keterangan, tanggal_lapor
         FROM laporan WHERE bidang_id = ? ORDER BY tanggal_lapor DESC LIMIT 10',
        [$idBidang]
    );
    $utama['buku_c'] = db_all(
        'SELECT id, nomor, jenis, nama_pemegang, berkas_pdf FROM buku_c
         WHERE bidang_id = ? OR id IN (SELECT buku_c_id FROM hak WHERE bidang_id = ?)
         ORDER BY nomor LIMIT 5',
        [$idBidang, $idBidang]
    );

    return $utama;
}

// ---------------------------------------------------------------------------
// SPPT / PBB
// ---------------------------------------------------------------------------

/** Kondisi WHERE untuk daftar SPPT dari parameter query. */
function sppt_filter(array $q): array
{
    $kondisi = ['s.tahun_pajak = ?'];
    $param = [(int) ($q['tahun'] ?? 0)];

    if (in_array($q['status'] ?? '', ['Lunas', 'Belum Bayar', 'Sebagian'], true)) {
        $kondisi[] = 's.status_bayar = ?';
        $param[] = $q['status'];
    }
    if (!empty($q['dusun'])) {
        $kondisi[] = 'o.dusun_id = ?';
        $param[] = (int) $q['dusun'];
    }
    if (!empty($q['blok'])) {
        $kondisi[] = 'o.blok_id = ?';
        $param[] = (int) $q['blok'];
    }
    if (!empty($q['cari'])) {
        $kondisi[] = '(o.nop LIKE ? OR o.nama_wp LIKE ? OR o.alamat_op LIKE ?)';
        $kata = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $q['cari']) . '%';
        array_push($param, $kata, $kata, $kata);
    }

    return ['WHERE ' . implode(' AND ', $kondisi), $param];
}

/** Daftar SPPT satu tahun pajak plus ringkasan ketetapannya. */
function daftar_sppt(array $q, int $halaman, int $perHalaman): array
{
    [$where, $param] = sppt_filter($q);
    $mulai = ($halaman - 1) * $perHalaman;

    $ringkas = db_one(
        "SELECT COUNT(*) AS jumlah, SUM(s.pajak_ditetapkan) AS ketetapan,
                SUM(s.terbayar) AS terbayar, SUM(s.sisa) AS piutang
         FROM sppt s JOIN objek_pajak o ON o.nop = s.nop {$where}",
        $param
    ) ?? [];

    $items = db_all(
        "SELECT s.id, s.nop, s.tahun_pajak, s.pajak_ditetapkan, s.terbayar, s.sisa,
                s.status_bayar, s.tanggal_bayar, o.nama_wp, o.alamat_op, o.luas_bumi_m2,
                o.bidang_id, o.blok_id, du.nama AS dusun, bl.kode AS blok, b.kode AS bidang_kode
         FROM sppt s
         JOIN objek_pajak o ON o.nop = s.nop
         LEFT JOIN dusun du ON du.id = o.dusun_id
         LEFT JOIN blok bl ON bl.id = o.blok_id
         LEFT JOIN bidang b ON b.id = o.bidang_id
         {$where}
         ORDER BY s.sisa DESC, o.nama_wp ASC
         LIMIT {$perHalaman} OFFSET {$mulai}",
        $param
    );

    return [
        'total' => (int) ($ringkas['jumlah'] ?? 0),
        'ringkas' => $ringkas,
        'items' => $items,
    ];
}

/** Objek pajak + riwayat SPPT + pencatatan pembayarannya. */
function detail_objek_pajak(string $nop): ?array
{
    $objek = db_one(
        'SELECT o.nop, o.nama_wp, o.alamat_wp, o.alamat_op, o.luas_bumi_m2, o.luas_bng_m2,
                o.dusun_id, du.nama AS dusun, o.blok_id, bl.kode AS blok, bl.nomor AS nomor_blok,
                o.bidang_id, b.kode AS bidang_kode, o.catatan
         FROM objek_pajak o
         LEFT JOIN dusun du ON du.id = o.dusun_id
         LEFT JOIN blok bl ON bl.id = o.blok_id
         LEFT JOIN bidang b ON b.id = o.bidang_id
         WHERE o.nop = ? LIMIT 1',
        [$nop]
    );
    if ($objek === null) {
        return null;
    }
    return [
        'objek' => $objek,
        'sppt' => db_all(
            'SELECT id, tahun_pajak, njop_pbb, pajak_ditetapkan, terbayar, sisa,
                    status_bayar, tanggal_bayar, pemungut, sumber
             FROM sppt WHERE nop = ? ORDER BY tahun_pajak DESC',
            [$nop]
        ),
        'pembayaran' => db_all(
            'SELECT pb.id, pb.tanggal_bayar, pb.jumlah, pb.metode, pb.referensi, pb.keterangan
             FROM pembayaran pb
             JOIN sppt s ON s.id = pb.sppt_id
             WHERE s.nop = ? ORDER BY pb.tanggal_bayar DESC, pb.id DESC LIMIT 50',
            [$nop]
        ),
    ];
}

/**
 * Calon NOP untuk sebuah bidang: objek pajak seblok yang belum tertaut.
 *
 * Urutannya memakai skor nama pemegang (lihat skor_nama), jadi calon yang paling
 * mungkin tampil paling atas. Itu saran, bukan keputusan: satu nama bisa dipakai
 * beberapa wajib pajak, dan tidak ada kunci bersama antara nib digitasi dan
 * persil SPPT yang sudah terbukti. Petugas tetap yang menautkan.
 *
 * @return array<int, array<string, mixed>>
 */
function calon_nop_bidang(int $blokId, ?string $namaPemegang = null, int $batas = 12): array
{
    if ($blokId <= 0) {
        return [];
    }
    $calon = db_all(
        'SELECT nop, nama_wp, persil, urut, pajak_ditetapkan FROM (
            SELECT o.nop, o.nama_wp, SUBSTRING(o.nop,15,2) AS persil,
                    SUBSTRING(o.nop,17,2) AS urut,
                    (SELECT s.pajak_ditetapkan FROM sppt s WHERE s.nop = o.nop
                      ORDER BY s.tahun_pajak DESC LIMIT 1) AS pajak_ditetapkan
              FROM objek_pajak o
             WHERE o.blok_id = ? AND o.bidang_id IS NULL
             ORDER BY o.nop
         ) calon
         LIMIT 400',
        [$blokId]
    );
    foreach ($calon as $i => $c) {
        $calon[$i]['skor'] = skor_nama($namaPemegang, $c['nama_wp']);
    }
    usort($calon, static fn(array $x, array $y): int => [$y['skor'], $x['nop']] <=> [$x['skor'], $y['nop']]);
    return array_slice($calon, 0, max(1, $batas));
}

// ---------------------------------------------------------------------------
// BUKU C DESA
// ---------------------------------------------------------------------------
/** Kondisi WHERE daftar Buku C dari parameter query. */
function buku_c_filter(array $q): array
{
    $kondisi = [];
    $param = [];

    if (!empty($q['cari'])) {
        $kondisi[] = '(bc.nama_pemegang LIKE ? OR CAST(bc.nomor AS CHAR) LIKE ? OR bc.nomor_persil LIKE ?)';
        $kata = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $q['cari']) . '%';
        array_push($param, $kata, $kata, $kata);
    }
    if (!empty($q['dusun'])) {
        $kondisi[] = 'bc.dusun_id = ?';
        $param[] = (int) $q['dusun'];
    }
    if (!empty($q['nomor'])) {
        $kondisi[] = 'bc.nomor = ?';
        $param[] = (int) $q['nomor'];
    }
    if (in_array($q['jenis'] ?? '', ['C-1', 'C-2', 'C-3'], true)) {
        $kondisi[] = 'bc.jenis = ?';
        $param[] = $q['jenis'];
    }
    if (!empty($q['tanpa_pemegang'])) {
        $kondisi[] = "bc.nama_pemegang = ''";
    }

    return [$kondisi === [] ? '' : 'WHERE ' . implode(' AND ', $kondisi), $param];
}

/** Satu halaman indeks Buku C. */
function daftar_buku_c(array $q, int $halaman, int $perHalaman): array
{
    [$where, $param] = buku_c_filter($q);
    $mulai = ($halaman - 1) * $perHalaman;

    return [
        'total' => (int) db_value('SELECT COUNT(*) FROM buku_c bc ' . $where, $param),
        'items' => db_all(
            "SELECT bc.id, bc.nomor, bc.jenis, bc.nama_pemegang, bc.nomor_persil, bc.luas_m2,
                    bc.tahun_daftar, bc.berkas_pdf, du.nama AS dusun,
                    b.id AS bidang_id, b.kode AS bidang_kode
             FROM buku_c bc
             LEFT JOIN dusun du ON du.id = bc.dusun_id
             LEFT JOIN bidang b ON b.id = bc.bidang_id
             {$where}
             ORDER BY bc.nomor LIMIT {$perHalaman} OFFSET {$mulai}",
            $param
        ),
    ];
}

/** Entri Buku C beserta hak, bidang, dan peralihan yang merujuknya. */
function detail_buku_c(int $id): ?array
{
    $baris = db_one(
        'SELECT bc.id, bc.nomor, bc.jenis, bc.nama_pemegang, bc.nomor_persil, bc.luas_m2,
                bc.tahun_daftar, bc.berkas_pdf, bc.catatan, bc.dibuat_pada, bc.diubah_pada,
                bc.dusun_id, bc.bidang_id, bc.pemegang_id,
                du.nama AS dusun, b.kode AS bidang_kode, pem.nama AS pemegang_tb
         FROM buku_c bc
         LEFT JOIN dusun du ON du.id = bc.dusun_id
         LEFT JOIN bidang b ON b.id = bc.bidang_id
         LEFT JOIN pemegang pem ON pem.id = bc.pemegang_id
         WHERE bc.id = ? LIMIT 1',
        [$id]
    );
    if ($baris === null) {
        return null;
    }
    $baris['hak'] = db_all(
        'SELECT h.id, h.bidang_id, h.no_sertifikat, h.pemegang_nama, h.diperoleh_pada,
                jh.kode AS hak_kode, jh.nama AS hak_nama, b.kode AS bidang_kode
         FROM hak h
         JOIN jenis_hak jh ON jh.id = h.jenis_hak_id
         LEFT JOIN bidang b ON b.id = h.bidang_id
         WHERE h.buku_c_id = ? ORDER BY h.id DESC',
        [$id]
    );
    $baris['peralihan'] = db_all(
        'SELECT p.id, p.kode, p.tanggal, p.status, p.pihak_pertama, p.pihak_kedua, j.nama AS jenis
         FROM peralihan p
         JOIN jenis_peralihan j ON j.id = p.jenis_peralihan_id
         WHERE p.buku_c_id = ? ORDER BY p.tanggal DESC LIMIT 20',
        [$id]
    );
    return $baris;
}

// ---------------------------------------------------------------------------
// PERALIHAN HAK
// ---------------------------------------------------------------------------

/** Kondisi WHERE daftar peralihan dari parameter query. */
function peralihan_filter(array $q): array
{
    $kondisi = [];
    $param = [];

    if (!empty($q['status'])) {
        $kondisi[] = 'p.status = ?';
        $param[] = $q['status'];
    }
    if (!empty($q['jenis'])) {
        $kondisi[] = 'j.id = ?';
        $param[] = (int) $q['jenis'];
    }
    if (!empty($q['dusun'])) {
        $kondisi[] = 'p.dusun_id = ?';
        $param[] = (int) $q['dusun'];
    }
    if (!empty($q['tahun'])) {
        $kondisi[] = 'YEAR(p.tanggal) = ?';
        $param[] = (int) $q['tahun'];
    }
    if (!empty($q['cari'])) {
        $kondisi[] = '(p.kode LIKE ? OR p.pihak_pertama LIKE ? OR p.pihak_kedua LIKE ?
                        OR p.no_akta LIKE ? OR p.nop LIKE ? OR b.kode LIKE ?)';
        $kata = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $q['cari']) . '%';
        array_push($param, $kata, $kata, $kata, $kata, $kata, $kata);
    }

    return [$kondisi === [] ? '' : 'WHERE ' . implode(' AND ', $kondisi), $param];
}

/** Dasar FROM yang dipakai bersama daftar, hitung, dan detail. */
const PERALIHAN_DASAR = '
    FROM peralihan p
    JOIN jenis_peralihan j ON j.id = p.jenis_peralihan_id
    LEFT JOIN bidang b     ON b.id = p.bidang_id
    LEFT JOIN dusun du     ON du.id = p.dusun_id
    LEFT JOIN buku_c bc    ON bc.id = p.buku_c_id
    LEFT JOIN pengguna pg  ON pg.id = p.dicatat_oleh';

/** Satu halaman daftar peralihan. */
function daftar_peralihan(array $q, int $halaman, int $perHalaman): array
{
    [$where, $param] = peralihan_filter($q);
    $mulai = ($halaman - 1) * $perHalaman;

    return [
        'total' => (int) db_value('SELECT COUNT(*) ' . PERALIHAN_DASAR . ' ' . $where, $param),
        'items' => db_all(
            "SELECT p.id, p.kode, p.tanggal, p.status, p.pihak_pertama, p.pihak_kedua,
                    p.nilai_rp, p.luas_m2, p.no_akta, p.nop, j.nama AS jenis,
                    du.nama AS dusun, b.kode AS bidang_kode, b.id AS bidang_id
             " . PERALIHAN_DASAR . ' ' . $where . "
             ORDER BY p.tanggal DESC, p.id DESC LIMIT {$perHalaman} OFFSET {$mulai}",
            $param
        ),
    ];
}

/** Detail satu peralihan lengkap dengan keterkaitannya. */
function detail_peralihan(int $id): ?array
{
    $baris = db_one(
        'SELECT p.*, j.nama AS jenis, du.nama AS dusun, b.kode AS bidang_kode,
                bc.nomor AS buku_c_nomor, pg.nama_lengkap AS pencatat
         ' . PERALIHAN_DASAR . ' WHERE p.id = ? LIMIT 1',
        [$id]
    );
    return $baris === null ? null : $baris;
}

// ---------------------------------------------------------------------------
// LAPORAN MASYARAKAT
// ---------------------------------------------------------------------------

const LAPORAN_KATEGORI = ['Sengketa', 'Tumpang tindih', 'Data salah', 'Peralihan belum tercatat', 'Lainnya'];
const LAPORAN_STATUS = ['Menunggu', 'Diproses', 'Selesai', 'Ditolak'];

/** Kondisi WHERE daftar laporan. */
function laporan_filter(array $q): array
{
    $kondisi = [];
    $param = [];

    if (in_array($q['status'] ?? '', LAPORAN_STATUS, true)) {
        $kondisi[] = 'l.status = ?';
        $param[] = $q['status'];
    }
    if (in_array($q['kategori'] ?? '', LAPORAN_KATEGORI, true)) {
        $kondisi[] = 'l.kategori = ?';
        $param[] = $q['kategori'];
    }
    if (!empty($q['cari'])) {
        $kondisi[] = '(l.nomor LIKE ? OR l.nama_pelapor LIKE ? OR l.nop LIKE ? OR l.keterangan LIKE ?)';
        $kata = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $q['cari']) . '%';
        array_push($param, $kata, $kata, $kata, $kata);
    }

    return [$kondisi === [] ? '' : 'WHERE ' . implode(' AND ', $kondisi), $param];
}

/** Dasar FROM daftar laporan. */
const LAPORAN_DASAR = '
    FROM laporan l
    LEFT JOIN bidang b ON b.id = l.bidang_id
    LEFT JOIN pengguna u ON u.id = l.ditangani_oleh';

/** Satu halaman laporan. Nomor telepon disamarkan untuk selain pengelola. */
function daftar_laporan(array $q, int $halaman, int $perHalaman, bool $kelola): array
{
    [$where, $param] = laporan_filter($q);
    $mulai = ($halaman - 1) * $perHalaman;

    $items = db_all(
        "SELECT l.id, l.nomor, l.kategori, l.status, l.nama_pelapor, l.telepon, l.nop,
                l.keterangan, l.tanggal_lapor, l.tanggal_selesai, l.catatan_penanganan,
                l.bidang_id, b.kode AS bidang_kode, u.nama_lengkap AS penangan,
                ST_Y(l.geom) AS lat, ST_X(l.geom) AS lon
         " . LAPORAN_DASAR . ' ' . $where . "
         ORDER BY FIELD(l.status, 'Menunggu', 'Diproses', 'Selesai', 'Ditolak'),
                  l.tanggal_lapor DESC
         LIMIT {$perHalaman} OFFSET {$mulai}",
        $param
    );

    if (!$kelola) {
        foreach ($items as &$laporan) {
            $laporan['telepon'] = $laporan['telepon'] === null ? null : 'tersimpan';
        }
        unset($laporan);
    }

    return ['total' => (int) db_value('SELECT COUNT(*) ' . LAPORAN_DASAR . ' ' . $where, $param), 'items' => $items];
}

/** Detail satu laporan; telepon hanya dibuka untuk pengelola. */
function detail_laporan(int $id, bool $kelola): ?array
{
    $baris = db_one(
        'SELECT l.*, b.kode AS bidang_kode, u.nama_lengkap AS penangan,
                ST_Y(l.geom) AS lat, ST_X(l.geom) AS lon
         ' . LAPORAN_DASAR . ' WHERE l.id = ? LIMIT 1',
        [$id]
    );
    if ($baris === null) {
        return null;
    }
    if (!$kelola) {
        $baris['telepon'] = $baris['telepon'] === null ? null : 'tersimpan';
    }
    return $baris;
}

/** Rekap peralihan satu tahun: jumlah per status dan nilai totalnya. */
function statistik_peralihan(int $tahun): array
{
    return db_one(
        "SELECT COUNT(*) AS total,
                SUM(status IN ('Diajukan','Verifikasi')) AS menunggu,
                SUM(status = 'Selesai') AS selesai,
                SUM(status IN ('Ditolak','Dicabut')) AS batal,
                ROUND(SUM(nilai_rp)) AS nilai
         FROM peralihan WHERE YEAR(tanggal) = ?",
        [$tahun]
    ) ?? ['total' => 0, 'menunggu' => 0, 'selesai' => 0, 'batal' => 0, 'nilai' => 0];
}
