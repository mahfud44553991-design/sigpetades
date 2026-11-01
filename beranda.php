<?php
declare(strict_types=1);

/**
 * Beranda publik SIPETADES, disusun mengikuti "Gambar Antarmuka.png": cap
 * pemerintah desa di kiri atas, judul dua warna, empat pintu layanan, panel
 * bidang di kanan, dan pita biru bertagline di kanan bawah.
 *
 * Panel kanan bukan gambar hiasan: isinya geometri asli dari MariaDB supaya yang
 * dilihat orang di halaman depan sama dengan yang nanti dibuka petugas di peta.
 * Geometri diambil apa adanya karena MariaDB 10.4 tidak punya `ST_Simplify`, dan
 * 235 bidang hanya 59 KB — masih layak dibaca tanpa login.
 */

require __DIR__ . '/app/bootstrap.php';

$masuk = sudah_masuk();
$pengguna = pengguna_aktif();

$desa = setelan('nama_desa', 'Gedong');
$kecamatan = setelan('nama_kecamatan', 'Patean');
$kabupaten = setelan('nama_kabupaten', 'Kendal');
$namaSistem = setelan('nama_sistem', (string) sip_config('app.nama', 'SIPETADES'));

$bidang = statistik_bidang();
$tahun = (int) setelan('tahun_pbb_aktif', '2026');
$pbb = statistik_pbb($tahun);
$jumlahBlok = (int) db_value('SELECT COUNT(*) FROM blok');
$jumlahPemegang = (int) db_value(
    "SELECT COUNT(DISTINCT TRIM(h.pemegang_nama)) FROM hak h
      JOIN bidang b ON b.id = h.bidang_id AND b.status NOT IN ('dihapus', 'duplikat')
     WHERE h.pemegang_nama IS NOT NULL AND TRIM(h.pemegang_nama) <> ''"
);

// Satu blok penuh sebagai sampel: bidang-bidangnya berdempetan, jadi panel kanannya
// terbaca seperti petak sawah, bukan titik-titik acak.
$contoh = db_one(
    "SELECT b.blok_id, bl.nama, COUNT(*) AS jumlah
     FROM bidang b JOIN blok bl ON bl.id = b.blok_id
     WHERE b.status NOT IN ('dihapus', 'duplikat') AND b.geom IS NOT NULL AND b.tipe = 'tanah'
     GROUP BY b.blok_id, bl.nama ORDER BY jumlah DESC LIMIT 1"
);

$fitur = [];
if ($contoh !== null) {
    foreach (db_all(
        "SELECT b.id, b.kode, b.luas_m2, b.status,
                ST_AsGeoJSON(b.geom) AS geojson,
                ST_X(ST_Centroid(b.geom)) AS lon, ST_Y(ST_Centroid(b.geom)) AS lat,
                (SELECT jh.kode FROM hak h JOIN jenis_hak jh ON jh.id = h.jenis_hak_id
                  WHERE h.bidang_id = b.id ORDER BY h.id DESC LIMIT 1) AS hak_kode,
                (SELECT jh.nama FROM hak h JOIN jenis_hak jh ON jh.id = h.jenis_hak_id
                  WHERE h.bidang_id = b.id ORDER BY h.id DESC LIMIT 1) AS hak_nama,
                jp.nama AS penggunaan
         FROM bidang b
         LEFT JOIN jenis_penggunaan jp ON jp.id = b.jenis_penggunaan_id
         WHERE b.blok_id = ? AND b.status NOT IN ('dihapus', 'duplikat') AND b.geom IS NOT NULL
         ORDER BY b.id LIMIT 260",
        [(int) $contoh['blok_id']]
    ) as $b) {
        $geo = json_decode((string) $b['geojson'], true);
        if (!is_array($geo)) {
            continue;
        }
        $fitur[] = [
            'id' => (int) $b['id'],
            'kode' => (string) $b['kode'],
            'luas' => $b['luas_m2'] === null ? null : (float) $b['luas_m2'],
            'status' => (string) $b['status'],
            'hak' => $b['hak_nama'],
            'hak_kode' => $b['hak_kode'],
            'penggunaan' => $b['penggunaan'],
            'lon' => (float) $b['lon'],
            'lat' => (float) $b['lat'],
            'geo' => $geo,
        ];
    }
}

// Bidang yang ditonjolkan di kartu info: cari yang paling lengkap datanya.
$pilihan = null;
foreach ($fitur as $f) {
    if ($f['hak'] !== null && $f['luas'] !== null && $pilihan === null) {
        $pilihan = $f;
    }
}
$pilihan ??= $fitur[0] ?? null;

$pintu = [
    ['peta.php', 'Peta Interaktif', 'Bidang, batas blok, dan citra satelit dalam satu tampilan.',
        '<path d="M9 3 3 6v15l6-3 6 3 6-3V3l-6 3-6-3Z"/><path d="M9 3v15M15 6v15"/>'],
    ['bidang.php', 'Data Bidang Tanah', 'Kode bidang, NIB, luas, dan status verifikasi tiap persil.',
        '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>'],
    ['buku-c.php', 'Data Pemilik', 'Pemegang hak, Buku C Desa, dan riwayat peralihan hak.',
        '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0M16 6.5a2.6 2.6 0 0 1 0 5M18 20a5.6 5.6 0 0 0-3-5"/>'],
    ['laporan.php', 'Laporan & Statistik', 'Rekap PBB per dusun, realisasi, dan piutang satu tahun pajak.',
        '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'],
];

$sampelBlok = $contoh['nama'] ?? ('Desa ' . $desa);

sip_header('Beranda', 'beranda', ['penuh' => true, 'leaflet' => true]);
?>
<section class="hero">
    <div class="hero-isi">
        <div class="hero-kiri">
            <div class="cap">
                <img src="<?= e(sip_base_url()) ?>/logo-kabupaten-kendal.png"
                     alt="Lambang Kabupaten Kendal" class="cap-lambang">
                <span class="cap-garis" aria-hidden="true"></span>
                <p class="cap-teks">
                    <strong>PEMERINTAH DESA <?= e(mb_strtoupper($desa, 'UTF-8')) ?></strong>
                    <span>KECAMATAN <?= e(mb_strtoupper($kecamatan, 'UTF-8')) ?></span>
                    <span>KABUPATEN <?= e(mb_strtoupper($kabupaten, 'UTF-8')) ?></span>
                </p>
            </div>

            <h1 class="judul-hero">
                <span class="judul-pin" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                    </svg>
                </span>
                <span class="judul-atas">Sistem Informasi Geografis</span>
                <span class="judul-bawah">Pertanahan Desa</span>
            </h1>

            <p class="tagline-hero">
                Data pertanahan yang akurat, transparan dan mudah diakses
                untuk pembangunan desa yang lebih baik.
            </p>
            <span class="gores-hero" aria-hidden="true"></span>

            <div class="pintu-layanan">
                <?php foreach ($pintu as [$berkas, $label, $kategori, $alur]): ?>
                    <a class="pintu" href="<?= e(sip_url($berkas)) ?>">
                        <span class="pintu-ikon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                                 stroke-linecap="round" stroke-linejoin="round"><?= $alur ?></svg>
                        </span>
                        <span class="pintu-teks"><strong><?= e($label) ?></strong></span>
                        <span class="pintu-kategori"><?= e($kategori) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="hero-tindak">
                <?php if ($masuk): ?>
                    <a class="tombol tombol-utama tombol-besar" href="<?= e(sip_url('index.php')) ?>">Buka dasbor</a>
                <?php else: ?>
                    <a class="tombol tombol-utama tombol-besar" href="<?= e(sip_url('login.php')) ?>">Masuk perangkat desa</a>
                <?php endif; ?>
                <span class="hero-sambutan">
                    <?php if ($masuk): ?>
                        Sedang masuk sebagai <strong><?= e($pengguna['nama_lengkap']) ?></strong>.
                    <?php else: ?>
                        Peta dan data bidang dapat dibaca siapa saja; perubahan data hanya untuk perangkat desa.
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <div class="hero-kanan">
            <div class="panel-peta">
                <div id="peta-hero" class="peta-hero" aria-label="Contoh bidang tanah di <?= e($sampelBlok) ?>"></div>
                <div class="kompas" aria-hidden="true">
                    <svg viewBox="0 0 60 60" fill="none" stroke="currentColor" stroke-width="1.6">
                        <circle cx="30" cy="30" r="21"/>
                        <path d="M30 6v8M30 46v8M6 30h8M46 30h8"/>
                        <path d="m30 14 4 16-4 16-4-16Z" fill="currentColor" stroke="none" opacity=".55"/>
                        <path d="M30 14v32" stroke-width="1"/>
                    </svg>
                    <b>U</b><i>T</i><s>S</s><u>B</u>
                </div>
                <div class="setir-peta" role="group" aria-label="Kontrol peta">
                    <button type="button" id="perbesar" aria-label="Perbesar">+</button>
                    <button type="button" id="perkecil" aria-label="Perkecil">&minus;</button>
                    <button type="button" id="pusatkan" aria-label="Kembali ke seluruh blok">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
                            <circle cx="12" cy="12" r="4"/><path d="M12 2v4M12 18v4M2 12h4M18 12h4" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                <div class="kartu-bidang" id="kartuBidang">
                    <p class="kartu-bidang-kepala">
                        <span class="kartu-bidang-ikon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9">
                                <path d="M12 3 4 7v10l8 4 8-4V7Z"/><path d="M12 3v18M4 7l8 4 8-4"/>
                            </svg>
                        </span>
                        <strong id="cbJudul">Bidang <?= e($pilihan['kode'] ?? '') ?></strong>
                    </p>
                    <dl class="kartu-bidang-isi">
                        <dt>Luas</dt><dd id="cbLuas">—</dd>
                        <dt>Jenis Hak</dt><dd id="cbHak">—</dd>
                        <dt>Pemilik</dt><dd id="cbPemilik">—</dd>
                        <dt>Penggunaan</dt><dd id="cbPenggunaan">—</dd>
                    </dl>
                    <p class="kartu-bidang-kaki"><span id="cbBlok">—</span> · klik bidang lain untuk mengganti</p>
                </div>
            </div>
        </div>
    </div>

    <div class="pita">
        <div class="pita-dalam">
            <p class="pita-merek">
                <span class="pita-daun" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 22C7 18 4 14 4 9.5A7.5 7.5 0 0 1 12 2a7.5 7.5 0 0 1 8 7.5C20 14 17 18 12 22Zm0-3.5c3-3 4.6-6 4.9-9A5 5 0 0 0 12 4.2a5 5 0 0 0-4.9 5.3C7.4 12.5 9 15.5 12 18.5Z"/></svg>
                </span>
                <span class="pita-teks">
                    <strong>Desa <?= e($desa) ?></strong>
                    <small>Maju Desa, Sejahtera Masyarakat</small>
                </span>
            </p>
            <dl class="pita-angka">
                <div><dt>Bidang tercatat</dt><dd><?= e(angka((int) $bidang['total'])) ?></dd></div>
                <div><dt>Luas terpetakan</dt><dd><?= e(angka((float) $bidang['total_luas'])) ?> m&sup2;</dd></div>
                <div><dt>Blok peta</dt><dd><?= e(angka($jumlahBlok)) ?></dd></div>
                <div><dt>Pemegang tercatat</dt><dd><?= e(angka($jumlahPemegang)) ?></dd></div>
            </dl>
        </div>
    </div>
</section>

<section class="wadah hero-bawah">
    <div class="grid grid-4">
        <div class="kartu ringkas">
            <small>Bidang perlu verifikasi</small>
            <strong><?= e(angka((int) $bidang['perlu_verifikasi'])) ?></strong>
            <p><?= e(angka((int) $bidang['tanah'])) ?> bidang tanah dan <?= e(angka((int) $bidang['lain'])) ?> obyek
                non-tanah tergambar pada peta master; status perlu verifikasi sebagian besar melekat pada
                obyek non-tanah, bukan temuan lapangan.</p>
        </div>
        <div class="kartu ringkas">
            <small>Realisasi PBB <?= e($tahun) ?></small>
            <strong><?= e(angka($pbb['persen'], 2)) ?>%</strong>
            <p><?= e(rupiah($pbb['terbayar'])) ?> masuk dari <?= e(rupiah($pbb['ketetapan'])) ?> ketetapan
                atas <?= e(angka($pbb['objek'])) ?> NOP.</p>
        </div>
        <div class="kartu ringkas">
            <small>Piutang PBB <?= e($tahun) ?></small>
            <strong><?= e(rupiah($pbb['piutang'])) ?></strong>
            <p><?= e(angka($pbb['belum'])) ?> NOP belum lunas; perincian per dusun ada di dasbor perangkat desa.</p>
        </div>
        <div class="kartu ringkas">
            <small>Sumber data</small>
            <strong><?= e(angka($jumlahBlok)) ?> blok</strong>
            <p>Batas bidang diambil dari peta master digitalisasi desa, nomor induk dari register
                NIB, dan pajak dari daftar SPPT tahun berjalan.</p>
        </div>
    </div>
</section>
<?php
$naskah = <<<'JS'
<script>
(function () {
    'use strict';
    const DATA = window.SIP_HERO;
    const E = document.getElementById('peta-hero');
    if (!E || typeof L === 'undefined') return;

    const peta = L.map('peta-hero', {
        zoomControl: false, attributionControl: true, scrollWheelZoom: false,
        dragging: true, doubleClickZoom: true, boxZoom: false, keyboard: false,
        touchZoom: false, zoomSnap: .25,
    });
    peta.attributionControl.setPrefix('');

    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19, attribution: 'Citra &copy; Esri',
    }).addTo(peta);

    const palet = { HM: '#1f9d4d', HP: '#0f4c81', HGB: '#0f4c81', HGU: '#0f4c81', KDH: '#b07d2a', BCD: '#b07d2a' };
    const rona = (f) => (f.status === 'perlu_verifikasi' ? '#8a968f' : (palet[f.hak_kode] || '#8a968f'));
    const angka = (v, d) => v == null ? 'belum dihitung'
        : Number(v).toLocaleString('id-ID', { minimumFractionDigits: d || 0, maximumFractionDigits: d || 0 }) + ' m\u00B2';

    let pilih = null;
    const lapisan = [];
    DATA.fitur.forEach((f) => {
        const l = L.geoJSON(f.geo ? { type: 'Feature', geometry: f.geo, properties: {} } : null, {
            style: { color: '#ffffff', weight: .8, opacity: .8, fillColor: rona(f), fillOpacity: .55 },
        });
        l.on('click', () => tampilkan(f));
        l.addTo(peta);
        lapisan.push({ f, l });
    });

    function tampilkan(f) {
        pilih = f;
        lapisan.forEach((x) => x.l.setStyle(x.f === f
            ? { color: '#12351f', weight: 2.4, opacity: 1, fillColor: rona(x.f), fillOpacity: .82 }
            : { color: '#ffffff', weight: .8, opacity: .8, fillColor: rona(x.f), fillOpacity: .55 }));
        document.getElementById('cbJudul').textContent = 'Bidang ' + f.kode;
        document.getElementById('cbLuas').textContent = angka(f.luas, f.luas && f.luas % 1 ? 2 : 0);
        document.getElementById('cbHak').textContent = f.hak || 'belum tercatat';
        document.getElementById('cbPemilik').textContent = 'tersedia untuk petugas';
        document.getElementById('cbPenggunaan').textContent = f.penggunaan || 'belum tercatat';
        document.getElementById('cbBlok').textContent = DATA.blok;
        if (penanda) { penanda.setLatLng([f.lat, f.lon]); } else {
            penanda = L.marker([f.lat, f.lon], {
                icon: L.divIcon({ className: 'penanda-bidang', html: '<i></i>', iconSize: [26, 34], iconAnchor: [13, 32] }),
                interactive: false,
            }).addTo(peta);
        }
    }

    let penanda = null;
    if (DATA.fitur.length) {
        const awal = DATA.fitur.find((f) => f.hak && f.luas) || DATA.fitur[0];
        peta.fitBounds(L.geoJSON({ type: 'FeatureCollection', features: DATA.fitur.map((f) => ({
            type: 'Feature', geometry: f.geo, properties: {},
        })) }).getBounds().pad(.06));
        tampilkan(awal);
    } else {
        peta.setView([DATA.pusat.lat, DATA.pusat.lon], 15);
    }

    document.getElementById('perbesar').addEventListener('click', () => peta.setZoom(peta.getZoom() + 1));
    document.getElementById('perkecil').addEventListener('click', () => peta.setZoom(peta.getZoom() - 1));
    document.getElementById('pusatkan').addEventListener('click', () => {
        if (DATA.fitur.length) {
            peta.fitBounds(L.geoJSON({ type: 'FeatureCollection', features: DATA.fitur.map((f) => ({
                type: 'Feature', geometry: f.geo, properties: {},
            })) }).getBounds().pad(.06));
        }
    });
})();
</script>
JS;
$kaki = '<script>window.SIP_HERO = '
    . json_encode([
        'blok' => $sampelBlok,
        'fitur' => $fitur,
        'pusat' => ['lat' => (float) setelan('peta_lat_pusat', '-7.0872'),
            'lon' => (float) setelan('peta_lon_pusat', '110.0693')],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
    . ';</script>' . $naskah;

sip_footer(['leaflet' => true, 'penuh' => true, 'kaki' => $kaki]);
