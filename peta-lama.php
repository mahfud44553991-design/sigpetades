<?php
declare(strict_types=1);

/**
 * Penampil layer DXF dari arsip aplikasi lama
 * (files/sig-pertanahan-gedong-lokal/data/dxf-layers).
 *
 * Geometri memakai koordinat lokal hasil pengukuran dengan L.CRS.Simple,
 * bukan EPSG:4326, jadi halaman ini sengaja tidak memakai basemap GPS.
 */

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/tools/lib-impor.php';

$pengguna = wajib_masuk();

$berkasMeta = __DIR__ . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/metadata.json';
$meta = is_file($berkasMeta) ? json_decode((string) file_get_contents($berkasMeta), true) : null;
$rentang = is_array($meta['extent'] ?? null) ? $meta['extent'] : null;
$jumlah = static fn (string $kunci): int => (int) ($meta['layers'][$kunci]['features'] ?? 0);
$jumlahBatasBlok = (int) db_value('SELECT COUNT(*) FROM blok');
$blokPerkiraan = db_all('SELECT nomor, kode FROM blok WHERE batas_perkiraan = 1 ORDER BY nomor');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wajib_masuk([PERAN_SUPER]);
    wajib_csrf();

    $lapisan = post_str('lapisan_bidang');
    try {
        set_time_limit(0);
        $hasil = imporPoligonPetaInduk($lapisan);
        audit(
            'impor',
            'bidang',
            'peta-induk-' . $lapisan,
            'Buat bidang dari semua poligon layer peta induk ' . $hasil['nama_lapisan'],
            null,
            array_diff_key($hasil, ['galat' => 1])
        );
        if ($hasil['jumlah_galat'] > 0) {
            flash_set('gagal', angka((int) $hasil['dibuat']) . ' bidang dibuat; '
                . angka((int) $hasil['jumlah_galat']) . ' poligon bermasalah. '
                . implode(' ', array_slice($hasil['galat'], 0, 3)));
        } else {
            $ringkasan = angka((int) $hasil['dibuat']) . ' bidang dibuat dari layer '
                . $hasil['nama_lapisan'] . '; ' . angka((int) $hasil['dilewati'])
                . ' poligon duplikat dilewati.';
            if ((int) $hasil['poligon_dari_garis'] > 0) {
                $ringkasan .= ' ' . angka((int) $hasil['poligon_dari_garis'])
                    . ' poligon disusun dari garis; ' . angka((int) $hasil['garis_tidak_menutup'])
                    . ' garis tidak membentuk bidang tertutup dan dilewati.';
            }
            if ((int) $hasil['bukan_poligon'] > 0) {
                $ringkasan .= ' ' . angka((int) $hasil['bukan_poligon'])
                    . ' geometri non-poligon tidak diimpor.';
            }
            flash_set('berhasil', $ringkasan);
        }
    } catch (Throwable $error) {
        flash_set('gagal', 'Pembuatan bidang gagal: ' . $error->getMessage());
    }
    redirect('peta-lama.php');
}

$jumlahPoligonLayer = [
    '010500' => (int) ($meta['layers']['010500']['types']['polygon'] ?? 0),
    '020100' => (int) ($meta['layers']['020100']['types']['polygon'] ?? 0),
    '020400' => (int) ($meta['layers']['020400']['types']['polygon'] ?? 0),
    'batas-blok' => (int) ($meta['layers']['BATAS BLOK']['types']['polygon'] ?? 0),
    'bidang-hm' => (int) ($meta['layers']['BIDANG HM']['types']['polygon'] ?? 0),
    'calon-bidang' => (int) ($meta['layers']['CALON BIDANG']['features'] ?? 0),
    'hm' => (int) ($meta['layers']['HM']['types']['polygon'] ?? 0),
    'lb-lai' => (int) ($meta['layers']['lb_lai']['types']['polygon'] ?? 0),
];
$jumlahPoligonLayer['020100'] = (int) ($meta['layers']['020100']['features'] ?? 0);

sip_header('Peta Induk', 'peta-lama', ['leaflet' => true]);
sip_judul(
    'Peta Induk',
    'Peta DXF Desa Gedong',
    'Layer MASTER.dxf hasil konversi dari aplikasi lama. Koordinatnya lokal hasil pengukuran, '
        . 'bukan titik GPS, sehingga tidak ditumpuk di atas peta jalan atau citra satelit.'
);
?>

<div class="grid grid-sidebar">
    <div>
        <section class="kartu">
            <header class="kepala-kartu kepala-peta">
                <div>
                    <h2>Layer DXF</h2>
                    <p>Sumber: files/sig-pertanahan-gedong-lokal/data/dxf-layers</p>
                </div>
                <div class="baris-tombol baris-peta">
                    <select class="pilih-kecil" id="fokusBlokPerkiraan"
                            data-transformasi="<?= e(json_encode(DXF_KE_WGS84, JSON_UNESCAPED_SLASHES)) ?>"
                            aria-label="Pilih blok perkiraan untuk difokuskan">
                        <option value="">Pilih blok perkiraan</option>
                        <?php foreach ($blokPerkiraan as $bl): ?>
                            <option value="<?= e((string) $bl['nomor']) ?>"><?= e($bl['kode']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="tombol tombol-kecil" type="button" id="fokusBlokPerkiraanBtn">Fokus blok</button>
                    <button class="tombol tombol-kecil" type="button" id="pusatkanLama">Pusatkan</button>
                    <button class="tombol tombol-kecil" type="button" id="alatPenunjuk" aria-pressed="false">Penunjuk titik</button>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('peta.php')) ?>">Peta bidang</a>
                </div>
            </header>

            <div class="peta-wadah">
                <div id="petaLama" class="peta" aria-label="Peta DXF koordinat lokal Desa Gedong"></div>
            </div>

            <div class="legenda">
                <span><i style="background:#bed7e8"></i>Bidang tertutup</span>
                <span><i style="background:#14603a; border-radius:50%"></i>Titik tengah bidang</span>
                <span><i style="background:#dc2626; border-radius:50%"></i>Penanda titik</span>
                <span><i style="background:#f87171"></i>Bidang pratinjau</span>
                <span><i style="background:#5b7893"></i>Garis terbuka</span>
                <span><i style="background:#14603a"></i>Batas blok ukur</span>
                <span><i style="background:#a06a12"></i>Batas blok perkiraan</span>
                <span><i style="background:#c2410c"></i>Ruas DXF mentah (referensi)</span>
                <span><i style="background:#087b83"></i>Teks perairan / nama</span>
                <span><i style="background:#3fa383"></i>Bidang HM</span>
                <span><i style="background:#166534"></i>Garis HM</span>
                <span><i style="background:#b45309"></i>Optimalisasi HM</span>
                <span><i style="background:#7c3aed"></i>Lain-lain (lb_lai)</span>
                <span><i style="background:#d97706"></i>Calon bidang</span>
                <span><i style="background:#64748b"></i>Batas desa / jalan / NIB</span>
            </div>
            <p class="petunjuk" id="infoLama">Menyiapkan layer…</p>
        </section>
    </div>

    <aside>
        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>Penanda titik</h2><p>Klik “Penunjuk titik” di atas peta, lalu klik peta</p></div>
            </header>
            <div class="isi-kartu">
                <dl class="daftar-kecil">
                    <li><span class="redup">X</span><span class="rapek-kanan" id="penandaX">–</span></li>
                    <li><span class="redup">Y</span><span class="rapek-kanan" id="penandaY">–</span></li>
                    <li><span class="redup">Luas</span><span class="rapek-kanan" id="penandaLuas">–</span></li>
                    <li><span class="redup">Sumber</span><span class="rapek-kanan" id="penandaSumber">–</span></li>
                    <li><span class="redup">Kode calon</span><span class="rapek-kanan" id="penandaKode">–</span></li>
                </dl>
                <div class="baris-tombol jarak-atas">
                    <button class="tombol tombol-kecil" type="button" id="pratinjauPenanda" disabled>Pratinjau bidang</button>
                    <button class="tombol tombol-kecil tombol-utama" type="button" id="simpanPenanda" style="display:none">Simpan bidang</button>
                    <button class="tombol tombol-kecil" type="button" id="hapusPenanda" disabled>Hapus penanda</button>
                </div>
                <a class="tombol tombol-kecil jarak-atas" id="keDigitasi" href="<?= e(sip_url('digitasi-baru.php', ['dari' => 'pratinjau'])) ?>">Digitasi Obyek Baru</a>
                <p class="petunjuk" id="infoPenanda">Penunjuk belum aktif. Koordinat X/Y berasal dari DXF lokal, bukan GPS.</p>
                <p class="petunjuk">Pratinjau memakai poligon tertutup 020100 yang memuat penanda; bila titik di luar, dipakai garis bidang tertutup (LineString yang ujungnya bertemu), lalu rangkaian garis 020100. Bidang baru berstatus perlu verifikasi, tanpa blok, dan hanya dapat disimpan Super Admin setelah pratinjau.</p>
            </div>
        </section>

        <?php if ($pengguna['peran'] === PERAN_SUPER): ?>
            <section class="kartu">
                <header class="kepala-kartu">
                    <div><h2>Buat bidang dari layer</h2><p>Salin semua poligon tertutup dari satu layer ke daftar bidang.</p></div>
                </header>
                <div class="isi-kartu">
                    <form method="post" action="<?= e(sip_url('peta-lama.php')) ?>"
                          data-konfirmasi="Buat bidang tanah dari semua poligon pada layer yang dipilih? Khusus 020100, garis yang tersambung menjadi poligon tertutup juga dibuatkan bidang; garis terbuka dilewati. Baris baru perlu diverifikasi dan tidak mendapat blok otomatis. Poligon yang sudah ada atau hampir sama akan dilewati.">
                        <?= csrf_input() ?>
                        <div class="lapangan">
                            <label for="lapisan-bidang">Layer sumber</label>
                            <select class="pilih-kecil" id="lapisan-bidang" name="lapisan_bidang">
                                <?php foreach (LAPISAN_POLIGON_PETA_INDUK as $kunci => $sumber): ?>
                                    <option value="<?= e($kunci) ?>">
                                        <?= e($sumber['nama']) ?> (<?= e(angka($jumlahPoligonLayer[$kunci] ?? 0)) ?>
                                        <?= $kunci === '020100' ? 'fitur bidang &amp; garis' : 'poligon' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="petunjuk">
                            Koordinat lokal DXF akan dikonversi ke WGS84. Bidang baru berstatus
                            <strong>perlu verifikasi</strong>, tanpa blok dan jenis hak sementara BLM.
                            Kode dibuat otomatis; impor ulang melewati kode dan poligon yang sudah ada.
                        </p>
                        <p class="petunjuk">
                            Semua poligon layer terpilih dibuat sebagai bidang, termasuk 010500 (batas desa)
                            dan BATAS BLOK. Gunakan hanya jika geometri layer tersebut memang ingin dimasukkan
                            ke daftar bidang.
                        </p>
                        <p class="petunjuk">
                            Khusus 020100, garis yang tersambung menjadi cincin tertutup juga disusun menjadi
                            poligon. Garis terbuka yang tidak membentuk bidang tertutup dilewati.
                        </p>
                        <button class="tombol tombol-utama jarak-atas" type="submit">Buat bidang dari layer</button>
                    </form>
                </div>
            </section>
        <?php endif; ?>

        <section class="kartu">
            <header class="kepala-kartu">
                <div><h2>Tampilkan layer</h2><p>Layer terbesar dimuat lebih dulu</p></div>
            </header>
            <div class="lapisan-kontrol">
                <label class="cek"><input type="checkbox" data-lapisan="020100" checked>
                    020100 — bidang &amp; garis (<?= e(angka($jumlah('020100'))) ?>)</label>
                <label class="cek"><input type="checkbox" id="tampilTitikLama" checked>
                    Titik tengah bidang <b id="jumlahTitikLama" class="redup"></b></label>
                <p class="petunjuk">Satu titik untuk tiap poligon tertutup 020100; membantu menemukan bidang kecil.</p>
                <label class="cek"><input type="checkbox" data-lapisan="batas-blok">
                    Batas blok (<?= e(angka($jumlahBatasBlok)) ?>)</label>
                <p class="petunjuk">24 batas blok terdaftar. Lima batas perkiraan ditandai garis putus-putus.</p>
                <label class="cek"><input type="checkbox" data-lapisan="batas-blok-mentah">
                    Ruas acuan BATAS BLOK (<?= e(angka($jumlah('BATAS BLOK'))) ?>)</label>
                <p class="petunjuk">72 fitur DXF mentah sebagai referensi saja; ruas ini belum memastikan batas ukur tertutup.</p>
                <label class="cek"><input type="checkbox" data-lapisan="batas-perairan">
                    Teks batas perairan (<?= e(angka($jumlah('BATAS PERAIRAN'))) ?>)</label>
                <label class="cek"><input type="checkbox" data-lapisan="tn-nama">
                    Nama pada peta (<?= e(angka($jumlah('tn_nama'))) ?>)</label>
                <p class="petunjuk">Bidang berhak (HM)</p>
                <label class="cek"><input type="checkbox" data-lapisan="bidang-hm">
                    Bidang HM (<?= e(angka($jumlah('BIDANG HM'))) ?>)</label>
                <label class="cek"><input type="checkbox" data-lapisan="hm">
                    Garis HM (<?= e(angka($jumlah('HM'))) ?>)</label>
                <label class="cek"><input type="checkbox" data-lapisan="optimalisasi-hm">
                    Optimalisasi HM (<?= e(angka($jumlah('OPTIMALISASI HM'))) ?>)</label>
                <label class="cek"><input type="checkbox" data-lapisan="lb-lai">
                    Lain-lain / lb_lai (<?= e(angka($jumlah('lb_lai'))) ?>)</label>
                <label class="cek"><input type="checkbox" data-lapisan="010500">
                    010500 — garis luar (<?= e(angka($jumlah('010500'))) ?>)</label>
                <label class="cek"><input type="checkbox" data-lapisan="020400">
                    020400 (<?= e(angka($jumlah('020400'))) ?>)</label>
                <p class="petunjuk">Belum terpetakan</p>
                <label class="cek"><input type="checkbox" data-lapisan="calon-bidang" checked>
                    Calon bidang — garis hampir tertutup (<?= e(angka($jumlah('CALON BIDANG'))) ?>)</label>
                <p class="petunjuk">Label &amp; batas</p>
                <label class="cek"><input type="checkbox" data-lapisan="nib-hm">
                    NIB HM (<?= e(angka($jumlah('NIB HM'))) ?>)</label>
                <label class="cek"><input type="checkbox" data-lapisan="batas-desa">
                    Batas desa (<?= e(angka($jumlah('BATAS DESA'))) ?>)</label>
                <label class="cek"><input type="checkbox" data-lapisan="blok">
                    Blok (<?= e(angka($jumlah('BLOK'))) ?>)</label>
                <label class="cek"><input type="checkbox" data-lapisan="batas-jalan">
                    Batas jalan (<?= e(angka($jumlah('BATAS JALAN'))) ?>)</label>
            </div>
            <p class="petunjuk">Layer 020100 (sekitar 5 MB) dan calon bidang dimuat lebih dulu. Layer
                lain sengaja mati secara bawaan agar peta tetap ringan; aktifkan bila perlu memeriksa
                bidang HM, label NIB, batas desa, atau nama jalan.</p>
            <p class="petunjuk">Calon bidang diturunkan dari garis 020100 yang ujungnya hampir bertemu
                (celah ≤ 1 unit, luas ≥ 20 unit²) dan belum tertutup sebagai bidang. Kandidat yang
                bentuknya sama dengan poligon tertutup dilewati (<?= e(angka((int) ($meta['layers']['CALON BIDANG']['duplicates_skipped'] ?? 0))) ?> duplikat),
                begitu pula yang sudah tertutup bidang HM atau poligon 020100
                (<?= e(angka((int) ($meta['layers']['CALON BIDANG']['already_mapped_skipped'] ?? 0))) ?> kandidat),
                sehingga layer ini hanya berisi bidang yang benar-benar belum terpetakan.</p>
        </section>

        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Catatan koordinat</h2></div></header>
            <p class="penjelasan">
                Angka X/Y berasal dari DXF asli dan belum diketahui sistem koordinat geografisnya.
                Peta ini hanya untuk memeriksa isi arsip, bukan untuk navigasi lapangan atau
                pengukuran luas resmi.
            </p>
            <?php if ($rentang !== null): ?>
                <dl class="daftar-kecil jarak-atas">
                    <li><span class="redup">X</span><span class="rapek-kanan"><?= e(angka((float) $rentang['min_x'], 1)) ?> – <?= e(angka((float) $rentang['max_x'], 1)) ?></span></li>
                    <li><span class="redup">Y</span><span class="rapek-kanan"><?= e(angka((float) $rentang['min_y'], 1)) ?> – <?= e(angka((float) $rentang['max_y'], 1)) ?></span></li>
                </dl>
                <p class="petunjuk">Sumber: <?= e((string) ($meta['source_file'] ?? 'MASTER.dxf')) ?>.
                    <?= e((string) ($meta['coordinate_system'] ?? 'Koordinat lokal.')) ?>.</p>
            <?php endif; ?>
            <p class="petunjuk">Aplikasi lama sendiri berjalan dengan PHP + PostgreSQL/PostGIS lewat Docker,
                sehingga tidak dijalankan dari XAMPP; halaman ini hanya menampilkan layer petanya.</p>
        </section>
    </aside>
</div>

<style>
#petaLama.mode-penunjuk,
#petaLama.mode-penunjuk .leaflet-interactive { cursor: crosshair !important; }
.penanda-pin { background: none; border: none; }
.penanda-pin span {
    display: block;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #dc2626;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(15, 23, 42, .45), 0 0 0 6px rgba(220, 38, 38, .16);
}
@media (max-width: 720px) {
    .kepala-peta { flex-wrap: wrap; }
    .baris-peta { width: 100%; }
    .baris-peta .pilih-kecil { flex: 1 1 100%; width: 100%; }
}
</style>

<?php
$naskah = <<<'JS'
<script>
(function () {
    'use strict';
    const peta = L.map('petaLama', {
        crs: L.CRS.Simple,
        minZoom: -8,
        maxZoom: 5,
        zoomSnap: .25,
        zoomDelta: .5,
        preferCanvas: true
    }).setView([715800, 263000], -3);

    const info = document.getElementById('infoLama');
    const grup = {};
    const grupTitik = L.layerGroup();
    const grupPenanda = L.layerGroup().addTo(peta);
    const grupPratinjau = L.featureGroup().addTo(peta);
    let penanda = null;
    let pratinjau = null;
    let modePenunjuk = false;
    const sorotanBlok = L.featureGroup().addTo(peta);
    const memuat = new Set();

    function ikonLabel(teks, kelas) {
        const isi = Sip.esc(teks || '');
        return L.divIcon({
            className: 'cad-label ' + (kelas || ''),
            html: isi,
            iconSize: [Math.max(20, isi.length * 6), 14],
            iconAnchor: [0, 7]
        });
    }

    function gayaBidangDxf(f) {
        const p = f.properties || {};
        if (p.closed) {
            return { color: '#27618f', weight: .8, fillColor: '#6da5d2', fillOpacity: .10 };
        }
        return { color: '#5b7893', weight: .55, opacity: .72 };
    }

    const KELAS_LABEL = {
        'nib-hm': 'nib-label',
        'batas-desa': 'desa-label',
        'blok': 'blok-label',
        'batas-jalan': 'jalan-label'
    };

    function gayaLapisan(nama) {
        if (nama === 'bidang-hm') {
            return (f) => (f.properties && f.properties.closed)
                ? { color: '#1f7a5c', weight: 1.2, fillColor: '#3fa383', fillOpacity: .18 }
                : { color: '#1f7a5c', weight: .7, opacity: .85 };
        }
        if (nama === 'hm') {
            return (f) => (f.properties && f.properties.closed)
                ? { color: '#166534', weight: 1.8, fillColor: '#4ea87b', fillOpacity: .10 }
                : { color: '#166534', weight: 1.5, opacity: .9 };
        }
        if (nama === 'optimalisasi-hm') return () => ({ color: '#b45309', weight: 1.6, dashArray: '6 4', opacity: .95 });
        if (nama === 'lb-lai') return () => ({ color: '#7c3aed', weight: 1.4, dashArray: '2 4', opacity: .9 });
        if (nama === '010500') return () => ({ color: '#64748b', weight: 1, opacity: .8 });
        if (nama === '020400') return () => ({ color: '#475569', weight: 1, opacity: .85 });
        if (nama === 'calon-bidang') return () => ({
            color: '#d97706', weight: 1.3, dashArray: '4 3', fillColor: '#f59e0b', fillOpacity: .12
        });
        return gayaBidangDxf;
    }

    function bangun(nama, data) {
        if (nama === 'batas-perairan' || nama === 'tn-nama' || KELAS_LABEL[nama]) {
            const kelas = nama === 'batas-perairan' ? 'water-label' : (KELAS_LABEL[nama] || '');
            return L.geoJSON(data, {
                pointToLayer: (f, ll) => {
                    const p = f.properties || {};
                    if (p.label) {
                        return L.marker(ll, {
                            icon: ikonLabel(p.label, kelas),
                            interactive: false,
                            keyboard: false
                        });
                    }
                    return L.circleMarker(ll, { radius: 3, color: '#64748b', weight: 1, fillOpacity: .8 });
                }
            });
        }
        if (nama === 'batas-blok') {
            return L.geoJSON(data, {
                style: (f) => ({
                    color: f.properties && f.properties.batas_perkiraan ? '#a06a12' : '#14603a',
                    weight: (f.properties && f.properties.batas_perkiraan) ? 2.4 : 2,
                    opacity: .95,
                    fillColor: '#5b9b74',
                    fillOpacity: .06,
                    dashArray: f.properties && f.properties.batas_perkiraan ? '7 5' : null
                })
            });
        }
        if (nama === 'batas-blok-mentah') {
            return L.geoJSON(data, {
                style: () => ({
                    color: '#c2410c',
                    weight: 1.2,
                    opacity: .8,
                    dashArray: '3 5',
                    fillOpacity: 0
                })
            });
        }
        return L.geoJSON(data, {
            style: gayaLapisan(nama),
            pointToLayer: (f, ll) => {
                const p = f.properties || {};
                if (p.label) {
                    return L.marker(ll, { icon: ikonLabel(p.label, ''), interactive: false, keyboard: false });
                }
                return L.circleMarker(ll, {
                    radius: 2, color: '#8b6b2d', weight: 1, fillOpacity: .7
                });
            }
        });
    }

    // Satu titik di pusat kotak batas tiap poligon tertutup 020100, supaya
    // bidang kecil tetap terlihat di antara ribuan garis DXF.
    function bangunTitikBidang(lapisan) {
        grupTitik.clearLayers();
        let jumlah = 0;
        lapisan.eachLayer((l) => {
            const p = l.feature && l.feature.properties;
            if (!p || !p.closed || !l.getBounds) return;
            const batas = l.getBounds();
            if (!batas.isValid()) return;
            L.circleMarker(batas.getCenter(), {
                radius: 2.6, weight: 1, color: '#ffffff', opacity: .9,
                fillColor: '#14603a', fillOpacity: 1, interactive: false
            }).addTo(grupTitik);
            jumlah++;
        });
        document.getElementById('jumlahTitikLama').textContent = Sip.angka(jumlah);
    }

    const ikonPenanda = L.divIcon({
        className: 'penanda-pin',
        html: '<span></span>',
        iconSize: [14, 14],
        iconAnchor: [7, 7]
    });

    function titikDiCincin(cincin, x, y) {
        let dalam = false;
        for (let i = 0, j = cincin.length - 1; i < cincin.length; j = i++) {
            const xi = cincin[i][0];
            const yi = cincin[i][1];
            const xj = cincin[j][0];
            const yj = cincin[j][1];
            if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) {
                dalam = !dalam;
            }
        }
        return dalam;
    }

    function bidangDiTitik(latlng) {
        const lapisan = grup['020100'];
        if (!lapisan) return null;
        let ketemu = null;
        lapisan.eachLayer((l) => {
            if (ketemu || !l.getBounds || !l.getBounds().contains(latlng)) return;
            const f = l.feature;
            const g = f && f.geometry;
            if (!g) return;
            let poligon = [];
            if (g.type === 'Polygon') {
                poligon = [g.coordinates];
            } else if (g.type === 'MultiPolygon') {
                poligon = g.coordinates;
            } else if (g.type === 'LineString') {
                const c = g.coordinates || [];
                if (c.length >= 4 && c[0][0] === c[c.length - 1][0] && c[0][1] === c[c.length - 1][1]) {
                    poligon = [[c]];
                }
            }
            for (const p of poligon) {
                let dalam = false;
                for (const cincin of p) {
                    if (titikDiCincin(cincin, latlng.lng, latlng.lat)) dalam = !dalam;
                }
                if (dalam) {
                    ketemu = f;
                    return;
                }
            }
        });
        return ketemu;
    }

    function koordDxf(nilai) {
        return Sip.angka(nilai, 2);
    }

    function bersihkanPratinjau() {
        grupPratinjau.clearLayers();
        pratinjau = null;
        document.getElementById('penandaLuas').textContent = '–';
        document.getElementById('penandaSumber').textContent = '–';
        document.getElementById('penandaKode').textContent = '–';
        document.getElementById('simpanPenanda').style.display = 'none';
    }

    function hapusPratinjauServer() {
        Sip.api('api/penanda-bidang.php', {
            method: 'POST',
            json: { aksi: 'bersihkan' }
        }).catch(() => {});
    }

    function tampilkanPratinjau(isi) {
        bersihkanPratinjau();
        pratinjau = isi;
        const luas = Sip.angka(isi.luas_m2, 2) + ' m²';
        document.getElementById('penandaLuas').textContent = luas;
        document.getElementById('penandaSumber').textContent = isi.jenis === 'tertutup'
            ? 'Poligon tertutup ' + (isi.handle || 'tanpa handle')
            : isi.jenis === 'garis-tertutup'
                ? 'Garis bidang tertutup ' + (isi.handle || 'tanpa handle')
                : 'Rangkaian garis 020100';
        document.getElementById('penandaKode').textContent = isi.kode;

        const lapisan = L.geoJSON(
            { type: 'Feature', geometry: isi.geometri, properties: {} },
            {
                style: {
                    color: '#dc2626',
                    weight: 2.4,
                    opacity: 1,
                    fillColor: '#f87171',
                    fillOpacity: .18,
                    dashArray: isi.jenis === 'garis' ? '6 4' : null
                }
            }
        ).addTo(grupPratinjau);
        const batas = lapisan.getBounds();
        if (batas.isValid()) peta.fitBounds(batas.pad(.35), { maxZoom: 2 });

        const tombol = document.getElementById('simpanPenanda');
        tombol.style.display = isi.boleh_simpan ? '' : 'none';
        if (isi.duplikat) {
            document.getElementById('infoPenanda').textContent = 'Bidang serupa sudah ada: '
                + isi.duplikat.kode + (isi.duplikat.alasan === 'kode'
                    ? ' (kode sama)' : ' (geometri ≥95% sama)') + '. Penyimpanan diblokir.';
        } else if (isi.boleh_simpan) {
            document.getElementById('infoPenanda').textContent = 'Pratinjau siap: ' + isi.kode
                + ' · ' + luas + '. Periksa batas, lalu klik Simpan bidang.';
        } else {
            document.getElementById('infoPenanda').textContent = 'Pratinjau siap: ' + isi.kode
                + ' · ' + luas + '. Akun ini tidak berwenang menyimpan bidang.';
        }
        info.textContent = 'Pratinjau ' + isi.kode + ' · ' + luas + ' — '
            + (isi.jenis === 'tertutup' ? 'poligon tertutup 020100.'
                : isi.jenis === 'garis-tertutup' ? 'garis bidang tertutup 020100.'
                    : 'rangkaian garis 020100.');
    }

    async function mintaPratinjau() {
        if (!penanda) return;
        const tombol = document.getElementById('pratinjauPenanda');
        const titik = penanda.getLatLng();
        tombol.disabled = true;
        document.getElementById('infoPenanda').textContent = 'Menghitung bidang di sekitar penanda…';
        try {
            const isi = await Sip.api('api/penanda-bidang.php', {
                method: 'POST',
                json: { aksi: 'pratinjau', x: titik.lng, y: titik.lat }
            });
            tampilkanPratinjau(isi);
        } catch (err) {
            bersihkanPratinjau();
            document.getElementById('infoPenanda').textContent = err.message;
            info.textContent = 'Pratinjau gagal: ' + err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            tombol.disabled = !penanda;
        }
    }

    async function simpanBidangPenanda() {
        if (!pratinjau) return;
        const tombol = document.getElementById('simpanPenanda');
        const yakin = window.confirm('Simpan bidang ' + pratinjau.kode + ' ('
            + Sip.angka(pratinjau.luas_m2, 2) + ' m²) sebagai bidang perlu verifikasi, tanpa blok?');
        if (!yakin) return;
        tombol.disabled = true;
        try {
            const hasil = await Sip.api('api/penanda-bidang.php', {
                method: 'POST',
                json: { aksi: 'simpan', token: pratinjau.token }
            });
            pratinjau = null;
            tombol.style.display = 'none';
            document.getElementById('infoPenanda').textContent = 'Bidang ' + hasil.kode
                + ' tersimpan sebagai perlu verifikasi (id ' + hasil.id + ').';
            info.textContent = 'Bidang ' + hasil.kode + ' tersimpan — perlu verifikasi, belum punya blok.';
            Sip.pesan(hasil.pesan, 'berhasil');
        } catch (err) {
            document.getElementById('infoPenanda').textContent = err.message;
            Sip.pesan(err.message, 'gagal');
        } finally {
            tombol.disabled = false;
        }
    }

    function pasangPenanda(latlng) {
        if (penanda) {
            penanda.setLatLng(latlng);
        } else {
            penanda = L.marker(latlng, {
                icon: ikonPenanda, interactive: false, keyboard: false, zIndexOffset: 1000
            }).addTo(grupPenanda);
        }
        const f = bidangDiTitik(latlng);
        bersihkanPratinjau();
        hapusPratinjauServer();
        document.getElementById('penandaX').textContent = koordDxf(latlng.lng);
        document.getElementById('penandaY').textContent = koordDxf(latlng.lat);
        document.getElementById('pratinjauPenanda').disabled = false;
        document.getElementById('hapusPenanda').disabled = false;
        const keterangan = f
            ? (f.geometry.type === 'LineString' ? 'di dalam garis bidang tertutup' : 'di dalam bidang tertutup')
                + ' 020100 (handle ' + ((f.properties || {}).dxf_handle || '—') + ').'
            : 'di luar bidang atau garis bidang tertutup 020100.';
        document.getElementById('infoPenanda').textContent = 'Penanda ' + keterangan;
        info.textContent = 'X ' + koordDxf(latlng.lng) + ' · Y ' + koordDxf(latlng.lat) + ' — ' + keterangan;
    }

    const tombolPenunjuk = document.getElementById('alatPenunjuk');

    function aturPenunjuk(hidup) {
        modePenunjuk = hidup;
        tombolPenunjuk.setAttribute('aria-pressed', hidup ? 'true' : 'false');
        tombolPenunjuk.textContent = hidup ? 'Matikan penunjuk' : 'Penunjuk titik';
        tombolPenunjuk.classList.toggle('tombol-utama', hidup);
        document.getElementById('petaLama').classList.toggle('mode-penunjuk', hidup);
        document.getElementById('infoPenanda').textContent = hidup
            ? 'Klik peta untuk menaruh penanda titik.'
            : (penanda ? 'Penunjuk nonaktif. Penanda terakhir tetap ditampilkan.' : 'Penunjuk nonaktif.');
        if (!hidup) info.textContent = 'Layer DXF siap. Koordinat lokal — bukan titik GPS.';
    }

    function titikDiinginkan() {
        const kotakTitik = document.getElementById('tampilTitikLama');
        return !kotakTitik || kotakTitik.checked;
    }

    async function pasang(nama, hidup) {
        if (!hidup) {
            if (grup[nama]) peta.removeLayer(grup[nama]);
            if (nama === '020100') peta.removeLayer(grupTitik);
            return;
        }
        if (grup[nama]) {
            grup[nama].addTo(peta);
            if (nama === '020100' && titikDiinginkan()) grupTitik.addTo(peta);
            return;
        }
        if (memuat.has(nama)) return;
        memuat.add(nama);
        info.textContent = 'Memuat layer ' + nama + '…';
        try {
            const data = await Sip.api('api/peta-lama.php', { query: { lapisan: nama } });
            grup[nama] = bangun(nama, data);
            const kotak = document.querySelector('[data-lapisan="' + nama + '"]');
            if (!kotak || kotak.checked) grup[nama].addTo(peta);
            if (nama === '020100') {
                bangunTitikBidang(grup[nama]);
                if (titikDiinginkan()) grupTitik.addTo(peta);
            }
            info.textContent = 'Layer ' + nama + ' dimuat.';
        } finally {
            memuat.delete(nama);
        }
    }

    function pusatkan() {
        const b = grup['020100'] ? grup['020100'].getBounds() : L.latLngBounds([]);
        if (b.isValid()) peta.fitBounds(b.pad(.025));
    }

    async function fokusBlokPerkiraan() {
        const pemilih = document.getElementById('fokusBlokPerkiraan');
        const nomor = Number(pemilih.value);
        if (!Number.isInteger(nomor) || nomor <= 0) {
            Sip.pesan('Pilih blok perkiraan terlebih dahulu.', 'gagal');
            return;
        }

        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'blok' } });
        const fitur = (data.features || []).find((f) => Number(f.properties.nomor) === nomor);
        if (!fitur || !fitur.geometry) {
            throw new Error('Geometri BLOK ' + nomor + ' tidak ditemukan.');
        }

        const transformasi = JSON.parse(pemilih.dataset.transformasi);
        const lon = transformasi.lon;
        const lat = transformasi.lat;
        const determinan = lon[1] * lat[2] - lon[2] * lat[1];
        if (!Number.isFinite(determinan) || determinan === 0) {
            throw new Error('Transformasi koordinat DXF tidak valid.');
        }

        const bounds = L.latLngBounds([]);
        function tambahKoordinat(koordinat) {
            if (typeof koordinat[0] === 'number') {
                const dLon = koordinat[0] - lon[0];
                const dLat = koordinat[1] - lat[0];
                const x = (dLon * lat[2] - lon[2] * dLat) / determinan;
                const y = (lon[1] * dLat - dLon * lat[1]) / determinan;
                bounds.extend([y, x]);
                return [x, y];
            }
            return koordinat.map(tambahKoordinat);
        }
        const geometriLokal = {
            type: fitur.geometry.type,
            coordinates: tambahKoordinat(fitur.geometry.coordinates)
        };
        if (!bounds.isValid()) {
            throw new Error('Batas koordinat BLOK ' + nomor + ' tidak dapat dihitung.');
        }

        sorotanBlok.clearLayers();
        L.geoJSON(Object.assign({}, fitur, { geometry: geometriLokal }), {
            style: { color: '#dc2626', weight: 4, opacity: 1, fillColor: '#ef4444', fillOpacity: .08 }
        }).addTo(sorotanBlok);
        peta.fitBounds(bounds.pad(.12), { maxZoom: 1 });
        document.getElementById('infoLama').textContent =
            'Peta difokuskan ke BLOK ' + nomor + '. Garis acuan tetap perlu dicocokkan secara visual.';
    }

    document.getElementById('pusatkanLama').addEventListener('click', pusatkan);
    document.getElementById('fokusBlokPerkiraanBtn').addEventListener('click', () => {
        fokusBlokPerkiraan().catch((err) => Sip.pesan(err.message, 'gagal'));
    });

    tombolPenunjuk.addEventListener('click', () => aturPenunjuk(!modePenunjuk));

    peta.on('mousemove', (e) => {
        if (!modePenunjuk) return;
        info.textContent = 'Penunjuk: X ' + koordDxf(e.latlng.lng) + ' · Y ' + koordDxf(e.latlng.lat)
            + ' — klik untuk menaruh penanda.';
    });

    peta.on('click', (e) => {
        if (!modePenunjuk) return;
        pasangPenanda(e.latlng);
    });

    document.getElementById('pratinjauPenanda').addEventListener('click', () => {
        mintaPratinjau();
    });
    document.getElementById('simpanPenanda').addEventListener('click', () => {
        simpanBidangPenanda();
    });

    document.getElementById('hapusPenanda').addEventListener('click', () => {
        grupPenanda.clearLayers();
        penanda = null;
        bersihkanPratinjau();
        hapusPratinjauServer();
        document.getElementById('penandaX').textContent = '–';
        document.getElementById('penandaY').textContent = '–';
        document.getElementById('pratinjauPenanda').disabled = true;
        document.getElementById('hapusPenanda').disabled = true;
        document.getElementById('infoPenanda').textContent = modePenunjuk
            ? 'Klik peta untuk menaruh penanda titik.'
            : 'Penunjuk nonaktif.';
    });

    document.getElementById('keDigitasi').addEventListener('click', async (ev) => {
        ev.preventDefault();
        const tujuan = new URL(ev.currentTarget.href, location.href);
        if (!penanda) {
            hapusPratinjauServer();
            tujuan.searchParams.delete('dari');
            location.href = tujuan.toString();
            return;
        }
        if (!pratinjau) {
            await mintaPratinjau();
            if (!pratinjau) return;
        }
        const titik = penanda.getLatLng();
        tujuan.searchParams.set('dari', 'pratinjau');
        tujuan.searchParams.set('x', String(titik.lng));
        tujuan.searchParams.set('y', String(titik.lat));
        location.href = tujuan.toString();
    });

    document.getElementById('tampilTitikLama').addEventListener('change', (ev) => {
        if (ev.target.checked) {
            if (peta.hasLayer(grup['020100'])) grupTitik.addTo(peta);
            else ev.target.checked = false;
        } else {
            peta.removeLayer(grupTitik);
        }
    });

    const kotak = Array.from(document.querySelectorAll('[data-lapisan]'));
    kotak.forEach((k) => {
        k.addEventListener('change', () => {
            pasang(k.dataset.lapisan, k.checked).catch((err) => {
                k.checked = false;
                Sip.pesan(err.message, 'gagal');
                info.textContent = 'Layer gagal dimuat.';
            });
        });
    });

    (async () => {
        try {
            await pasang('020100', true);
            pusatkan();
            for (const k of kotak) {
                if (k.dataset.lapisan !== '020100' && k.checked) {
                    await pasang(k.dataset.lapisan, true);
                }
            }
            info.textContent = 'Layer DXF siap. Koordinat lokal — bukan titik GPS.';
        } catch (err) {
            Sip.pesan(err.message, 'gagal');
            info.textContent = 'Layer DXF gagal dimuat.';
        }
    })();
})();
</script>
JS;

sip_footer(['leaflet' => true, 'kaki' => $naskah]);
