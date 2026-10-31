<?php
declare(strict_types=1);

/**
 * Laporan lengkap basis data SIPETADES sebagai sekumpulan berkas CSV.
 *
 *   php tools/ekspor.php                  semua bagian ke storage/laporan-<waktu>/
 *   php tools/ekspor.php --tahun=2025     status bayar PBB diambil dari tahun itu
 *
 * Isinya: keterangan, rekap status, rekap per blok (termasuk kondisi garis
 * batas), daftar seluruh bidang, daftar bidang kembar, komposisi hak, dan daftar
 * kerja persil yang belum terpetakan.
 * Berkas ditulis dengan BOM dan pemisah titik-koma supaya angka desimal dan
 * huruf beraksen Indonesia tidak rusak saat dibuka Excel.
 *
 * Hanya membaca. Tidak ada satu pun tabel yang diubah, jadi aman dijalankan
 * kapan saja; hasilnya tetap dicatat ke audit_log sebagai ekspor. Namanya
 * `ekspor.php` karena `laporan.php` di akar sudah dipakai halaman laporan warga.
 */

require_once __DIR__ . '/lib-impor.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

$tahunPajak = null;
foreach ($argv as $argumen) {
    if (str_starts_with((string) $argumen, '--tahun=')) {
        $tahunPajak = (int) substr((string) $argumen, 8);
    }
}

/** Tulis satu berkas CSV (BOM + pemisah titik-koma) dan kembalikan banyaknya baris data. */
function csvTulis(string $path, array $kepala, iterable $baris): int
{
    $aliran = fopen($path, 'wb');
    if ($aliran === false) {
        throw new RuntimeException('Berkas gagal dibuka untuk ditulis: ' . $path);
    }
    fwrite($aliran, "\xEF\xBB\xBF");
    fputcsv($aliran, $kepala, ';');
    $jumlah = 0;
    foreach ($baris as $b) {
        fputcsv($aliran, array_values((array) $b), ';');
        $jumlah++;
    }
    fclose($aliran);

    return $jumlah;
}

$waktu = date('Ymd-His');
$folder = SIP_ROOT . '/storage/laporan-' . $waktu;
if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
    throw new RuntimeException('Folder laporan tidak bisa dibuat: ' . $folder);
}

// Bagian paling berat lebih dulu, supaya yang ringan tidak menunggu.
// kolom nomor_daftar baru dibuat oleh `impor.php daftar`, jadi basis data yang
// belum sempat naik skemanya tetap bisa diekspor
$punyaKolomDaftar = (int) db_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bidang' AND COLUMN_NAME = 'nomor_daftar'"
) > 0;

$bidang = db_all(
    'SELECT v.kode, v.tipe, v.status, v.blok, v.nomor_blok, v.dusun, v.jenis_penggunaan,
            v.luas_m2, v.nib, b.nis, ' . ($punyaKolomDaftar ? 'b.nomor_daftar' : 'NULL AS nomor_daftar') . ',
            v.jenis_hak, v.pemegang_nama, v.no_sertifikat, v.no_hak_pakai,
            v.diperoleh_pada, v.buku_c_nomor, v.nama_wp, v.nop,
            (SELECT s.status_bayar FROM sppt s
              WHERE s.nop = v.nop AND (? IS NULL OR s.tahun_pajak = ?)
           ORDER BY s.tahun_pajak DESC LIMIT 1) AS status_bayar,
            v.pusat_lat, v.pusat_lon, b.sumber, b.catatan, v.diubah_pada
       FROM v_bidang v JOIN bidang b ON b.id = v.id
   ORDER BY v.nomor_blok IS NULL, v.nomor_blok, v.kode',
    [$tahunPajak, $tahunPajak]
);

$rekapStatus = db_all(
    'SELECT status, COUNT(*) AS bidang, SUM(tipe = "tanah") AS bidang_tanah,
            SUM(tipe = "lain") AS bidang_lain, ROUND(SUM(luas_m2)) AS luas_m2,
            SUM(nib IS NOT NULL AND nib <> "") AS punya_nib,
            SUM(nis IS NOT NULL AND nis <> "") AS punya_nis,
            SUM(blok_id IS NULL) AS tanpa_blok,
            ROUND(AVG(luas_m2)) AS rata_luas
       FROM bidang GROUP BY status ORDER BY bidang DESC'
);

// Luas garis blok: ST_Area() MariaDB 10.4 mengembalikan 0 untuk geometri geodetik
// (SRID 4326), jadi WKT-nya dibaca ulang sebagai geometri Cartesian lalu dikonversi
// ke meter persegi dengan faktor Plate Carree pada lintang titik tengah blok.
$rekapBlok = db_all(
    'SELECT bl.kode AS blok, bl.nomor, du.nama AS dusun,
            COUNT(b.id) AS bidang,
            SUM(b.status = "aktif") AS aktif,
            SUM(b.status = "perlu_verifikasi") AS perlu_verifikasi,
            SUM(b.status = "duplikat") AS duplikat,
            SUM(b.status = "dihapus") AS dihapus,
            ROUND(SUM(b.luas_m2)) AS luas_tercatat,
            ROUND(SUM(CASE WHEN b.status NOT IN ("duplikat", "dihapus") THEN b.luas_m2 END)) AS luas_tanpa_kembar,
            ROUND(ST_Area(ST_GeomFromText(ST_AsText(bl.geom)))
                  * 110574.0 * 111320.0 * COS(RADIANS(ST_Y(ST_Centroid(bl.geom))))
                  ) AS luas_garis_blok,
            ST_NumGeometries(bl.geom) AS bagian_garis,
            bl.batas_tersedia, bl.batas_perkiraan, bl.sumber_perkiraan
       FROM blok bl
       LEFT JOIN bidang b ON b.blok_id = bl.id AND b.status <> "dihapus"
       LEFT JOIN dusun du ON du.id = bl.dusun_id
   GROUP BY bl.id, bl.kode, bl.nomor, du.nama, bl.geom, bl.batas_tersedia,
            bl.batas_perkiraan, bl.sumber_perkiraan
   ORDER BY bl.nomor'
);

$komposisiHak = db_all(
    'SELECT jh.kode AS kode_hak, jh.nama AS jenis_hak, COUNT(h.id) AS bidang,
            ROUND(SUM(b.luas_m2)) AS luas_m2,
            SUM(h.no_sertifikat IS NOT NULL AND h.no_sertifikat <> "") AS ada_nomor_sertifikat,
            SUM(h.pemegang_nama IS NOT NULL AND h.pemegang_nama <> "") AS ada_pemegang,
            SUM(h.buku_c_id IS NOT NULL) AS tertaut_buku_c,
            SUM(b.status = "aktif") AS masih_aktif,
            SUM(b.status = "perlu_verifikasi") AS perlu_verifikasi,
            SUM(b.status = "duplikat") AS duplikat
       FROM jenis_hak jh
       LEFT JOIN hak h ON h.jenis_hak_id = jh.id
       LEFT JOIN bidang b ON b.id = h.bidang_id AND b.status <> "dihapus"
   GROUP BY jh.kode, jh.nama
   ORDER BY bidang DESC, jh.kode'
);

// Bidang kembar: kelompoknya dibangun dari DUA pembanding. `ST_Equals` menangkap
// poligon yang identik secara topologi tapi masih menyimpan titik awal atau arah
// putaran berbeda sehingga sidik teks WKT menolaknya; `pasanganKembarTepat()`
// menangkap yang tempatnya sama persis namun beda pada titik terakhirnya, sehingga
// justru ST_Equals yang menolaknya. Aturan penandaan `duplikat` dan `kembar-tepat`
// memakai kedua pembanding itu, jadi laporan ini cocok dengan status di basis data.
$menurutId = [];
foreach (db_all(
    'SELECT b.id, b.kode, b.tipe, b.status, b.nib, b.luas_m2,
            bl.kode AS blok, b.catatan
       FROM bidang b LEFT JOIN blok bl ON bl.id = b.blok_id
      WHERE b.status <> "dihapus"
   ORDER BY b.kode'
) as $b) {
    $menurutId[(int) $b['id']] = $b;
}
$equals = pasanganKembarGeometri(false);
$irisan = array_map(
    static fn(array $p): array => ['ai' => (int) $p['id_a'], 'bi' => (int) $p['id_b']],
    pasanganKembarTepat()
);
$grup = grupkanPasangan(array_merge($equals, $irisan), $menurutId);
usort($grup, static fn(array $a, array $x): int => [count($x), $a[0]['kode']] <=> [count($a), $x[0]['kode']]);

$kunciGrup = static fn(array $g): string => implode('/',
    array_map(static fn(array $b): string => (string) $b['kode'], $g));
$hanyaEquals = array_map($kunciGrup, grupkanPasangan($equals, $menurutId));

$kembar = [];
foreach ($grup as $nomor => $g) {
    $pembanding = in_array($kunciGrup($g), $hanyaEquals, true)
        ? 'ST_Equals'
        : 'ST_Equals + irisan penuh';
    foreach ($g as $b) {
        $kembar[] = [
            'grup' => $nomor + 1,
            'sebanyak' => count($g),
            'kode' => $b['kode'],
            'tipe' => $b['tipe'],
            'status' => $b['status'],
            'blok' => $b['blok'],
            'nib' => $b['nib'],
            'luas_m2' => $b['luas_m2'],
            'pembanding' => $pembanding,
            'catatan' => $b['catatan'],
        ];
    }
}

$jumlah = [];
$jumlah['03-bidang.csv'] = csvTulis($folder . '/03-bidang.csv', [
    'Kode', 'Tipe', 'Status', 'Blok', 'Nomor blok', 'Dusun', 'Penggunaan', 'Luas m2',
    'NIB', 'NIS', 'Nomor daftar 080201', 'Jenis hak', 'Pemegang', 'Nomor sertifikat', 'Nomor hak pakai', 'Diperoleh',
    'Buku C', 'Wajib pajak', 'NOP', 'Status bayar' . ($tahunPajak ? " {$tahunPajak}" : ' (terbaru)'),
    'Lintang', 'Bujur', 'Sumber data', 'Catatan', 'Diubah pada',
], $bidang);

$jumlah['01-rekap-status.csv'] = csvTulis($folder . '/01-rekap-status.csv', [
    'Status', 'Bidang', 'Bidang tanah', 'Bidang lain', 'Luas m2',
    'Punya NIB', 'Punya NIS', 'Tanpa blok', 'Rata-rata luas m2',
], $rekapStatus);

$jumlah['02-rekap-blok.csv'] = csvTulis($folder . '/02-rekap-blok.csv', [
    'Blok', 'Nomor', 'Dusun', 'Bidang', 'Aktif', 'Perlu verifikasi', 'Duplikat', 'Dihapus',
    'Luas tercatat m2', 'Luas tanpa kembar m2', 'Luas garis blok m2 (perkiraan)', 'Bagian garis',
    'Batas tersedia', 'Batas perkiraan', 'Sumber perkiraan',
], $rekapBlok);

$jumlah['04-bidang-kembar.csv'] = csvTulis($folder . '/04-bidang-kembar.csv', [
    'Grup', 'Sebanyak', 'Kode', 'Tipe', 'Status', 'Blok', 'NIB', 'Luas m2', 'Pembanding', 'Catatan',
], $kembar);

$jumlah['05-komposisi-hak.csv'] = csvTulis($folder . '/05-komposisi-hak.csv', [
    'Kode hak', 'Jenis hak', 'Bidang', 'Luas m2', 'Ada nomor sertifikat', 'Ada pemegang',
    'Tertaut Buku C', 'Masih aktif', 'Perlu verifikasi', 'Duplikat',
], $komposisiHak);

// Daftar kerja persil yang belum terpetakan: cincin MASTER.dxf tanpa baris bidang (A) dan
// nomor register peta yang labelnya berdiri di dalam blok tanpa poligon (B). Ini satu-satunya
// bagian laporan yang membaca peta master, jadi yang paling lama.
$belum = daftarBelumTerpetakan();
$jumlah['06-belum-terpetakan.csv'] = csvTulis($folder . '/06-belum-terpetakan.csv', [
    'Golongan', 'Lapisan', 'Luas m2', 'Blok', 'Sumber garis blok', 'Bujur', 'Lintang',
    'Cacah', 'Catatan geometri', 'Label/nomor di dalamnya',
], $belum['baris']);

$totalBidang = count($bidang);
$luasTercatat = array_sum(array_map(static fn(array $b): float => (float) $b['luas_m2'], $bidang));
$luasBersih = array_sum(array_map(
    static fn(array $b): float => $b['status'] === 'duplikat' ? 0.0 : (float) $b['luas_m2'],
    $bidang
));

// Kolom yang kosong di 03-bidang.csv bukan kesalahan ekspor, tetapi memang belum
// ada datanya — jadi ikut dinyatakan di berkas keterangan supaya tidak disalahartikan.
$isi = db_one(
    'SELECT COUNT(*) total, SUM(dusun_id IS NOT NULL) dusun, SUM(nib IS NOT NULL AND nib <> "") nib,
            SUM(blok_id IS NOT NULL) berblok,
            SUM(EXISTS(SELECT 1 FROM objek_pajak o WHERE o.bidang_id = b.id)) ada_pbb,
            SUM(EXISTS(SELECT 1 FROM hak h WHERE h.no_sertifikat IS NOT NULL AND h.no_sertifikat <> "")) ada_sertifikat
       FROM bidang b WHERE status <> "dihapus"'
);
$tautanPbb = (int) db_value('SELECT COUNT(*) FROM objek_pajak WHERE bidang_id IS NOT NULL');
$totalObjek = (int) db_value('SELECT COUNT(*) FROM objek_pajak');
// kolom nis baru dibuat oleh `impor.php nis`, jadi pembacanya bisa memakai basis
// data yang belum sempat naik skemanya
$punyaKolomNis = (int) db_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bidang' AND COLUMN_NAME = 'nis'"
) > 0;
$isi['nis'] = $punyaKolomNis
    ? (int) db_value('SELECT COUNT(*) FROM bidang WHERE nis IS NOT NULL AND nis <> "" AND status <> "dihapus"')
    : 0;
$isi['nomor_daftar'] = $punyaKolomDaftar
    ? (int) db_value('SELECT COUNT(*) FROM bidang
                       WHERE nomor_daftar IS NOT NULL AND nomor_daftar <> "" AND status <> "dihapus"')
    : 0;
// kolom blok_id baru diisi setelah `impor.php taut-blok`, jadi pembacanya bisa
// memakai basis data yang belum sempat naik skemanya
$punyaBlok = (int) db_value(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'objek_pajak' AND COLUMN_NAME = 'blok_id'"
) > 0;
$tautanBlok = $punyaBlok
    ? (int) db_value('SELECT COUNT(*) FROM objek_pajak WHERE blok_id IS NOT NULL')
    : 0;
$tahunSppt = implode(', ', array_column(db_all('SELECT DISTINCT tahun_pajak FROM sppt ORDER BY tahun_pajak'), 'tahun_pajak'));
$bernama = (int) db_value(
    "SELECT COUNT(*) FROM bidang b JOIN hak h ON h.bidang_id = b.id
      WHERE b.status <> 'dihapus' AND h.pemegang_nama IS NOT NULL AND h.pemegang_nama <> ''"
);
// tanah hidup yang benar-benar dipakai laporan: tanpa salinan berstatus duplikat
$bernamaHidup = (int) db_value(
    "SELECT COUNT(*) FROM bidang b JOIN hak h ON h.bidang_id = b.id
      WHERE b.tipe = 'tanah' AND b.status NOT IN ('dihapus', 'duplikat')
        AND h.pemegang_nama IS NOT NULL AND h.pemegang_nama <> ''"
);
$tanahHidup = (int) db_value(
    "SELECT COUNT(*) FROM bidang WHERE tipe = 'tanah' AND status NOT IN ('dihapus', 'duplikat')"
);

$jumlah['00-keterangan.csv'] = csvTulis($folder . '/00-keterangan.csv', [
    'Butir', 'Isi',
], [
    ['Laporan', 'Laporan lengkap basis data SIPETADES'],
    ['Dibuat', date('d/m/Y H:i')],
    ['Basis data', (string) sip_config('db.name', '(tidak dicatat konfigurasi)')],
    ['Jumlah bidang', (string) $totalBidang],
    ['Luas tercatat', number_format($luasTercatat, 0, ',', '.') . ' m2 (semua status kecuali dihapus)'],
    ['Luas tanpa kembar', number_format($luasBersih, 0, ',', '.') . ' m2 (bidang duplikat tidak dihitung)'],
    ['Tahun pajak', $tahunPajak === null ? 'tahun pajak terakhir tiap NOP' : (string) $tahunPajak],
    ['01-rekap-status.csv', 'Jumlah dan luas menurut status'],
    ['02-rekap-blok.csv', 'Menurut blok, termasuk luas dan banyaknya bagian garis batas blok'],
    ['03-bidang.csv', 'Semua bidang: pemilik, hak, penggunaan, PBB, catatan'],
    ['04-bidang-kembar.csv', count($grup) . ' grup bidang yang tempatnya sama persis'
        . ' (' . count($hanyaEquals) . ' grup di antaranya hanya dari ST_Equals)'],
    ['05-komposisi-hak.csv', 'Menurut jenis hak, termasuk yang belum dipakai'],
    ['06-belum-terpetakan.csv', $belum['cincin'] . ' cincin MASTER.dxf yang tidak punya bidang (A) + '
        . $belum['loket'] . ' loket bernomor yang belum digambar (B), dihitung pada radius '
        . number_format($belum['radius'], 1, ',', '.') . ' m; ' . $belum['label']
        . ' label bernomor berdiri di luar poligon mana pun. A dipasang oleh "impor.php dxf-persil" '
        . 'kalau titik tengahnya jatuh di dalam sebuah blok; B tidak bisa dipulihkan alat apa pun '
        . 'karena peta master memang tidak punya ring di sana, jadi ini pekerjaan digitasi lapangan. '
        . 'Angka B bergantung radius: dua label berjarak 4 m dihitung satu persil.'],
    ['Catatan status', 'duplikat = poligon kembar dengan bidang lain yang dibiarkan hidup; '
        . 'perlu_verifikasi = blok atau datanya belum dipastikan petugas'],
    ['Peringatan', 'Dasbor, peta, dan daftar bidang sudah menyaring status duplikat sehingga layar '
        . 'menunjukkan ' . number_format($luasBersih, 0, ',', '.') . ' m2; laporan ini tetap memuat '
        . 'semua barisnya, jadi "Luas tercatat" lebih besar daripada "Luas tanpa kembar".'],
    ['Kelengkapan: dusun', number_format((int) $isi['dusun'], 0, ',', '.') . ' dari '
        . number_format((int) $isi['total'], 0, ',', '.') . ' bidang punya dusun, jadi kolom Dusun kosong'],
    ['Kelengkapan: NIB', number_format((int) $isi['nib'], 0, ',', '.') . ' dari '
        . number_format((int) $isi['total'], 0, ',', '.') . ' bidang punya NIB'],
    ['Kelengkapan: NIS', $punyaKolomNis
        ? number_format((int) $isi['nis'], 0, ',', '.') . ' dari '
            . number_format((int) $isi['total'], 0, ',', '.') . ' bidang punya NIS. NIS adalah angka '
            . 'pada label "NIS : ..." lapisan tn_alashak MASTER.dxf, bukan NIB: nilainya tidak '
            . 'bersinggungan sama sekali dengan nib, jadi disimpan di kolomnya sendiri. '
            . 'Diisi oleh "impor.php nis" yang bawaan hanya menyentuh bidang perlu_verifikasi '
            . '(--semua untuk semua bidang hidup), dan bidang dengan beberapa label menuliskan '
            . 'nomornya dipisah " / " tanpa menentukan nomor mana milik siapa.'
        : 'Kolom bidang.nis belum ada di basis data ini; jalankan "impor.php nis --terapkan".'],
    ['Kelengkapan: nomor daftar 080201', $punyaKolomDaftar
        ? number_format((int) $isi['nomor_daftar'], 0, ',', '.') . ' dari '
            . number_format((int) $isi['total'], 0, ',', '.') . ' bidang punya nomor daftar. Nomor ini '
            . 'dibaca dari label lapisan 080201 pada MASTER.dxf dan BUKAN NIB: pada bidang yang punya '
            . 'keduanya nomornya tidak pernah sama, dan tidak ada nomornya yang dipakai dua bidang. '
            . 'Diisi oleh "impor.php daftar --terapkan".'
        : 'Kolom bidang.nomor_daftar belum ada; jalankan "impor.php daftar --terapkan".'],
    ['Kelengkapan: blok', number_format((int) $isi['berblok'], 0, ',', '.') . ' dari '
        . number_format((int) $isi['total'], 0, ',', '.') . ' bidang punya blok'],
    ['Kelengkapan: PBB', number_format($tautanPbb, 0, ',', '.') . ' dari '
        . number_format($totalObjek, 0, ',', '.') . ' objek pajak tertaut ke satu bidang dan '
        . number_format($tautanBlok, 0, ',', '.') . ' sudah tertaut ke blok peta berdasar NOP.'
        . ' Kolom NOP, Wajib pajak dan Status bayar di 03-bidang.csv masih kosong untuk bidang'
        . ' yang belum ditautkan; calon per bidang disusun menurut nama pemegang peta'
        . ' ("impor.php calon <blok>").'],
    ['Kelengkapan: nama', number_format($bernama, 0, ',', '.') . ' dari '
        . number_format((int) $isi['total'], 0, ',', '.')
        . ' bidang punya nama pemegang (' . number_format($bernamaHidup, 0, ',', '.') . ' dari '
        . number_format($tanahHidup, 0, ',', '.') . ' bidang tanah yang hidup dan tidak diduplikat);'
        . ' sisanya belum kebagian label nama di peta master'],
    ['Kelengkapan: sertifikat', number_format((int) $isi['ada_sertifikat'], 0, ',', '.')
        . ' bidang punya nomor sertifikat'],
    ['Tahun SPPT tersedia', $tahunSppt === '' ? 'belum ada' : $tahunSppt],
]);

audit('ekspor', 'bidang', 'laporan-lengkap', sprintf(
    'Laporan lengkap %d berkas ditulis ke %s (%d bidang, %d grup kembar%s)',
    count($jumlah),
    $folder,
    $totalBidang,
    count($grup),
    $tahunPajak === null ? '' : ', tahun pajak ' . $tahunPajak
));

ksort($jumlah, SORT_NATURAL);
printf("Laporan lengkap: %s\n\n", $folder);
foreach ($jumlah as $nama => $baris) {
    printf("   %-24s %6d baris  %9s byte\n", $nama, $baris,
        number_format((int) filesize($folder . '/' . $nama)));
}
printf("\nBidang: %d   Luas tercatat: %s m2   Luas tanpa kembar: %s m2   Grup kembar: %d\n",
    $totalBidang, number_format($luasTercatat, 0, ',', '.'), number_format($luasBersih, 0, ',', '.'), count($grup));
if ($tahunPajak !== null) {
    printf("Status bayar dibatasi tahun pajak %d; NOP tanpa SPPT tahun itu kosong kolomnya.\n", $tahunPajak);
}
