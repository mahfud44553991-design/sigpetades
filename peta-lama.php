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

$pengguna = wajib_masuk();

$berkasMeta = __DIR__ . '/files/sig-pertanahan-gedong-lokal/data/dxf-layers/metadata.json';
$meta = is_file($berkasMeta) ? json_decode((string) file_get_contents($berkasMeta), true) : null;
$rentang = is_array($meta['extent'] ?? null) ? $meta['extent'] : null;
$jumlah = static fn (string $kunci): int => (int) ($meta['layers'][$kunci]['features'] ?? 0);

sip_header('Peta DXF lama', 'peta', ['leaflet' => true]);
sip_judul(
    'Arsip pemetaan',
    'Peta DXF Desa Gedong',
    'Layer MASTER.dxf hasil konversi dari aplikasi lama. Koordinatnya lokal hasil pengukuran, '
        . 'bukan titik GPS, sehingga tidak ditumpuk di atas peta jalan atau citra satelit.'
);
?>

<div class="grid grid-sidebar">
    <div>
        <section class="kartu">
            <header class="kepala-kartu">
                <div>
                    <h2>Layer DXF</h2>
                    <p>Sumber: files/sig-pertanahan-gedong-lokal/data/dxf-layers</p>
                </div>
                <div class="baris-tombol">
                    <button class="tombol tombol-kecil" type="button" id="pusatkanLama">Pusatkan</button>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('peta.php')) ?>">Peta bidang</a>
                </div>
            </header>

            <div class="peta-wadah">
                <div id="petaLama" class="peta" aria-label="Peta DXF koordinat lokal Desa Gedong"></div>
            </div>

            <div class="legenda">
                <span><i style="background:#bed7e8"></i>Bidang tertutup</span>
                <span><i style="background:#5b7893"></i>Garis terbuka</span>
                <span><i style="background:#c18d2c"></i>Batas blok</span>
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
                <div><h2>Tampilkan layer</h2><p>Layer terbesar dimuat lebih dulu</p></div>
            </header>
            <div class="lapisan-kontrol">
                <label class="cek"><input type="checkbox" data-lapisan="020100" checked>
                    020100 — bidang &amp; garis (<?= e(angka($jumlah('020100'))) ?>)</label>
                <label class="cek"><input type="checkbox" data-lapisan="batas-blok">
                    Batas blok (<?= e(angka($jumlah('BATAS BLOK'))) ?>)</label>
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
                    color: '#c18d2c',
                    weight: (f.properties && f.properties.closed) ? 3 : 1.7,
                    opacity: .95,
                    fillOpacity: .02
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

    async function pasang(nama, hidup) {
        if (!hidup) {
            if (grup[nama]) peta.removeLayer(grup[nama]);
            return;
        }
        if (grup[nama]) {
            grup[nama].addTo(peta);
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
            info.textContent = 'Layer ' + nama + ' dimuat.';
        } finally {
            memuat.delete(nama);
        }
    }

    function pusatkan() {
        const b = grup['020100'] ? grup['020100'].getBounds() : L.latLngBounds([]);
        if (b.isValid()) peta.fitBounds(b.pad(.025));
    }

    document.getElementById('pusatkanLama').addEventListener('click', pusatkan);

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
