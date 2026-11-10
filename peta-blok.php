<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

wajib_masuk();
$jumlah = (int) db_value('SELECT COUNT(*) FROM blok');

sip_header('Peta blok', 'peta', ['leaflet' => true]);
sip_judul(
    'Pemetaan',
    'Peta blok desa',
    'Peta khusus batas blok. Klik blok untuk melihat status batas dan estimasi jumlah bidang.'
);
?>

<section class="kartu kartu-peta">
    <header class="kepala-kartu">
        <div>
            <h2>Batas blok</h2>
            <p><span id="jumlahBlok"><?= e(angka($jumlah)) ?></span> blok terdaftar</p>
        </div>
        <div class="baris-tombol">
            <button class="tombol tombol-kecil" type="button" id="pasangkanSemuaBlok">Seluruh blok</button>
            <a class="tombol tombol-kecil" href="<?= e(sip_url('peta.php')) ?>">Peta bidang</a>
        </div>
    </header>

    <div class="lapisan-peta" role="group" aria-label="Lapisan peta">
        <label class="chip-lapisan">
            <input type="checkbox" id="tampilJalanBlok" autocomplete="off" checked>
            <i style="background:#8b7b68"></i>Jalan
            <b id="jumlahJalanBlok"></b>
        </label>
        <label class="chip-lapisan">
            <input type="checkbox" id="tampilSungaiBlok" autocomplete="off" checked>
            <i style="background:#087b83"></i>Sungai &amp; saluran
            <b id="jumlahSungaiBlok"></b>
        </label>
    </div>

    <div class="peta-wadah">
        <div id="petaBlok" class="peta" aria-label="Peta batas blok Desa Gedong"></div>
    </div>
    <div class="legenda">
        <span><i style="background:#14603a"></i>Batas blok ukur</span>
        <span><i style="background:#a06a12"></i>Batas blok perkiraan (garis putus-putus)</span>
        <span><i style="background:#8b7b68"></i>Jalan (OpenStreetMap)</span>
        <span><i style="background:#087b83"></i>Sungai &amp; saluran (garis dari label DXF)</span>
    </div>
    <p class="petunjuk" id="infoPetaBlok">Memuat batas blok…</p>
</section>

<?php
$naskah = <<<'JS'
<script>
(function () {
    'use strict';
    const peta = L.map('petaBlok', { preferCanvas: true }).setView(
        [SIP.peta.lat, SIP.peta.lon],
        SIP.peta.zoom
    );
    peta.attributionControl.setPrefix('');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(peta);

    const grup = L.featureGroup().addTo(peta);
    const grupJalan = L.layerGroup().addTo(peta);
    const grupSungai = L.layerGroup().addTo(peta);

    ['jalan', 'sungai', 'blok'].forEach((nama, i) => {
        const pane = peta.createPane('pane-' + nama);
        pane.style.zIndex = String(402 + i * 4);
    });

    const NAMA_KELAS_JALAN = {
        primary: 'jalan utama', secondary: 'jalan utama', tertiary: 'jalan penghubung',
        residential: 'jalan permukiman', living_street: 'jalan permukiman',
        service: 'jalan servis', unclassified: 'jalan lain', track: 'jalan tanah'
    };

    function gayaJalan(p) {
        const tebal = {
            primary: 3.4, secondary: 2.8, tertiary: 2.3, residential: 1.6,
            living_street: 1.5, service: 1.3, unclassified: 1.5, track: 1.2
        }[p.kelas] || 1.2;
        return { color: '#8b7b68', weight: tebal, opacity: .85, lineCap: 'round' };
    }

    async function muatJalan() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'jalan' } });
        grupJalan.clearLayers();
        const fitur = data.features || [];
        L.geoJSON({ type: 'FeatureCollection', features: fitur }, {
            pane: 'pane-jalan',
            style: (f) => gayaJalan(f.properties),
            onEachFeature: (f, l) => {
                const p = f.properties;
                l.bindTooltip((p.nama ? Sip.esc(p.nama) + ' — ' : '')
                    + (NAMA_KELAS_JALAN[p.kelas] || p.kelas), { sticky: true, className: 'jalan-label' });
            }
        }).addTo(grupJalan);
        document.getElementById('jumlahJalanBlok').textContent = Sip.angka(fitur.length);
    }

    function keteranganSungai(p) {
        const jenis = p.jenis === 'Saluran' ? 'saluran' : 'sungai';
        let teks = p.nama ? Sip.esc(p.nama) + ' — ' + jenis : jenis.charAt(0).toUpperCase() + jenis.slice(1);
        if (p.panjang_m) teks += ' · ±' + Sip.angka(Math.round(p.panjang_m / 10) * 10) + ' m';
        return teks;
    }

    async function muatSungai() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'sungai' } });
        grupSungai.clearLayers();
        const fitur = data.features || [];
        const garis = fitur.filter((f) => f.geometry.type === 'LineString');
        if (garis.length) {
            L.geoJSON({ type: 'FeatureCollection', features: garis }, {
                pane: 'pane-sungai',
                style: () => ({ color: '#087b83', weight: 1.6, opacity: .8, lineCap: 'round' }),
                onEachFeature: (f, l) => l.bindTooltip(keteranganSungai(f.properties),
                    { sticky: true, className: 'water-label' })
            }).addTo(grupSungai);
        }
        fitur.filter((f) => f.geometry.type === 'Point').forEach((f) => {
            const koordinat = f.geometry.coordinates;
            L.circleMarker([Number(koordinat[1]), Number(koordinat[0])], {
                pane: 'pane-sungai', radius: 2.6, weight: 1,
                color: '#087b83', fillColor: '#087b83', fillOpacity: .7
            }).bindTooltip(keteranganSungai(f.properties), { className: 'water-label', direction: 'top' })
                .addTo(grupSungai);
        });
        document.getElementById('jumlahSungaiBlok').textContent = Sip.angka(fitur.length);
    }

    function nomorBlok(kode) {
        const hasil = String(kode || '').match(/(\d+)/);
        return hasil ? Number(hasil[1]) : 0;
    }

    function warnaBlok(kode) {
        const nomor = nomorBlok(kode);
        return nomor ? 'hsl(' + Math.round((nomor * 137.508) % 360) + ', 38%, 44%)' : '#9aa8a2';
    }

    function isiPopup(properti) {
        const status = properti.batas_perkiraan
            ? 'Perkiraan'
            : (properti.batas_tersedia ? 'Batas ukur tersedia' : 'Belum ada batas ukur');
        const estimasi = properti.estimasi_bidang === null
            ? 'Belum dihitung'
            : Sip.angka(properti.estimasi_bidang) + ' bidang';
        return '<strong>' + Sip.esc(properti.kode) + '</strong>'
            + '<dl class="popup-isi"><dt>Status batas</dt><dd>' + Sip.esc(status) + '</dd>'
            + '<dt>Estimasi bidang</dt><dd>' + Sip.esc(estimasi) + '</dd></dl>'
            + '<a href="' + Sip.url('peta.php') + '">Buka peta bidang</a>';
    }

    async function muatBlok() {
        const info = document.getElementById('infoPetaBlok');
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'blok' } });
        const fitur = data.features || [];
        fitur.forEach((f) => {
            const p = f.properties;
            const warna = warnaBlok(p.kode);
            const layer = L.geoJSON(f, {
                pane: 'pane-blok',
                style: {
                    color: p.batas_perkiraan ? '#a06a12' : warna,
                    weight: p.batas_perkiraan ? 2.5 : 2,
                    opacity: .95,
                    fillColor: warna,
                    fillOpacity: .2,
                    dashArray: p.batas_perkiraan ? '7 5' : null
                }
            }).addTo(grup);
            layer.bindTooltip(Sip.esc(p.kode), { sticky: true });
            layer.bindPopup(isiPopup(p), { maxWidth: 280 });
        });

        document.getElementById('jumlahBlok').textContent = Sip.angka(fitur.length);
        const batas = grup.getBounds();
        if (batas.isValid()) {
            peta.fitBounds(batas.pad(.04));
            info.textContent = 'Klik blok untuk melihat status batas dan estimasi jumlah bidang.';
        } else {
            info.textContent = 'Belum ada geometri batas blok untuk ditampilkan.';
        }
    }

    function pasangLapisan(nama, grupLapisan, muat) {
        const kotak = document.getElementById(nama);
        if (!kotak) return;
        kotak.addEventListener('change', (e) => {
            if (e.target.checked) {
                grupLapisan.addTo(peta);
                if (!grupLapisan.getLayers().length) muat().catch(gagalLapisan);
            } else {
                peta.removeLayer(grupLapisan);
            }
        });
        if (kotak.checked && !grupLapisan.getLayers().length) muat().catch(gagalLapisan);
    }

    function gagalLapisan(err) {
        Sip.pesan(err.message, 'gagal');
    }

    pasangLapisan('tampilJalanBlok', grupJalan, muatJalan);
    pasangLapisan('tampilSungaiBlok', grupSungai, muatSungai);

    document.getElementById('pasangkanSemuaBlok').addEventListener('click', () => {
        const batas = grup.getBounds();
        if (batas.isValid()) peta.flyToBounds(batas.pad(.04), { duration: .5 });
    });

    muatBlok().catch((error) => {
        document.getElementById('infoPetaBlok').textContent = 'Batas blok gagal dimuat.';
        Sip.pesan(error.message, 'gagal');
    });
})();
</script>
JS;

sip_footer(['leaflet' => true, 'kaki' => $naskah]);
