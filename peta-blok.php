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

    <div class="peta-wadah">
        <div id="petaBlok" class="peta" aria-label="Peta batas blok Desa Gedong"></div>
    </div>
    <div class="legenda">
        <span><i style="background:#14603a"></i>Batas blok ukur</span>
        <span><i style="background:#a06a12"></i>Batas blok perkiraan (garis putus-putus)</span>
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
        maxZoom: 20,
        attribution: '&copy; OpenStreetMap'
    }).addTo(peta);

    const grup = L.featureGroup().addTo(peta);

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
