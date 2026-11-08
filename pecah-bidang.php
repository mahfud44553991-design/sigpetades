<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
wajib_masuk();

$csrf = csrf_token();

sip_header('Pecah Bidang', 'digitasi', ['leaflet' => true]);
?>

<main class="konten">
    <h1>Pecah Bidang</h1>
    <p class="petunjuk"><a href="digitasi.php">&larr; Kembali ke Panel Tools</a> | Pilih bidang yang akan dipecah, lalu gambar garis pemisah di atas peta.</p>

    <div class="kartu" style="margin-bottom:15px;">
        <h2 style="font-size:16px; margin-bottom:10px;">1. Pilih Bidang</h2>
        <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
            <div style="flex:1; min-width:250px;">
                <label for="bidang-search" style="display:block; margin-bottom:4px; font-weight:600;">Cari bidang (kode, NIB, atau pemilik):</label>
                <input type="text" id="bidang-search" placeholder="Ketik minimal 2 karakter..." autocomplete="off" style="width:100%; padding:8px; border:1px solid var(--garis); border-radius:4px;">
                <div id="hasil-pencarian" style="margin-top:4px; max-height:250px; overflow-y:auto; border:1px solid var(--garis); border-radius:4px; display:none; background:#fff;"></div>
            </div>
            <button type="button" id="btn-tampilkan" class="tombol" disabled>Tampilkan di Peta</button>
        </div>
        <div id="info-bidang" style="margin-top:10px; padding:10px; background:#f0f7f0; border-radius:4px; display:none;">
            <strong>Bidang terpilih:</strong> <span id="info-kode"></span> |
            Luas: <span id="info-luas"></span> m&sup2; |
            Blok: <span id="info-blok"></span>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:15px;">
        <h2 style="font-size:16px; margin-bottom:10px;">2. Gambar Garis Pemisah</h2>
        <p class="petunjuk" style="margin-bottom:10px;">Klik pada peta untuk menambah titik garis pemisah. Garis harus memotong bidang dari sisi ke sisi. Klik kanan untuk hapus titik terakhir.</p>
        <div class="baris-tombol">
            <button type="button" id="btn-mulai-garis" class="tombol sekunder" disabled>Mulai Gambar Garis</button>
            <button type="button" id="btn-selesai-garis" class="tombol sekunder" disabled>Selesai Gambar</button>
            <button type="button" id="btn-undo-garis" class="tombol sekunder" disabled>Undo Titik</button>
            <button type="button" id="btn-hapus-garis" class="tombol sekunder" disabled>Hapus Garis</button>
            <button type="button" id="btn-pisah" class="tombol utama" disabled>Pecah Bidang</button>
            <span id="info-garis" style="margin-left:10px; color:var(--redup); font-size:13px;"></span>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:15px;">
        <div id="peta-pecah" style="width:100%; height:500px; border-radius:4px; border:1px solid var(--garis);"></div>
    </div>

    <div class="kartu" id="bagian-hasil" style="margin-bottom:15px; display:none;">
        <h2 style="font-size:16px; margin-bottom:10px;">3. Hasil Pecah Bidang</h2>
        <p class="petunjuk">Periksa hasil pecahan bidang di bawah. Setiap bagian akan menjadi bidang baru.</p>
        <div id="daftar-hasil"></div>
        <form id="form-simpan-pecah" style="margin-top:15px;">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" id="bidang-asal-id" name="bidang_asal_id" value="">
            <input type="hidden" id="hasil-geojson" name="hasil_geojson" value="">
            <div class="baris-tombol">
                <button type="submit" class="tombol utama">Simpan Bidang Baru</button>
                <button type="button" id="btn-batal" class="tombol sekunder">Batal</button>
            </div>
        </form>
    </div>
</main>

<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
<script>
(function() {
    'use strict';

    const peta = L.map('peta-pecah').setView([-7.09, 110.07], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 20,
        attribution: '&copy; OSM'
    }).addTo(peta);

    let bidangDipilih = null;
    let layerBidang = null;
    let titikGaris = [];
    let layerGaris = null;
    let layerHasil = null;
    let modeGambar = false;
    let semuaBidangLayer = null;

    const searchInput = document.getElementById('bidang-search');
    const hasilPencarian = document.getElementById('hasil-pencarian');
    let searchTimeout = null;

    // Load semua bidang sebagai context layer
    fetch('api/bidang.php?lapisan=bidang')
        .then(r => r.json())
        .then(data => {
            semuaBidangLayer = L.geoJSON(data, {
                style: { color: '#999', weight: 1, fillColor: '#ddd', fillOpacity: 0.2 }
            }).addTo(peta);
            if (data.features && data.features.length > 0) {
                peta.fitBounds(semuaBidangLayer.getBounds(), { padding: [20, 20] });
            }
        });

    // AJAX search
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        if (query.length < 2) {
            hasilPencarian.style.display = 'none';
            return;
        }
        searchTimeout = setTimeout(() => {
            fetch('api/cari_bidang.php?q=' + encodeURIComponent(query))
                .then(r => r.json())
                .then(data => {
                    if (!data.sukses || data.data.length === 0) {
                        hasilPencarian.innerHTML = '<div style="padding:10px;color:#666;">Tidak ditemukan</div>';
                        hasilPencarian.style.display = 'block';
                        return;
                    }
                    let html = '';
                    data.data.forEach(b => {
                        html += '<div class="item-hasil" data-id="' + b.id + '" data-kode="' + b.kode + '" data-luas="' + b.luas_m2 + '" data-blok="' + (b.blok_nama || '-') + '" style="padding:10px;border-bottom:1px solid #eee;cursor:pointer;" onmouseover="this.style.background=\'#f5f5f5\'" onmouseout="this.style.background=\'white\'">' + b.label + '</div>';
                    });
                    hasilPencarian.innerHTML = html;
                    hasilPencarian.style.display = 'block';
                    document.querySelectorAll('.item-hasil').forEach(item => {
                        item.addEventListener('click', function() {
                            bidangDipilih = {
                                id: this.dataset.id,
                                kode: this.dataset.kode,
                                luas: parseFloat(this.dataset.luas) || 0,
                                blok: this.dataset.blok
                            };
                            document.getElementById('btn-tampilkan').disabled = false;
                            hasilPencarian.style.display = 'none';
                            searchInput.value = this.textContent.trim();
                        });
                    });
                });
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !hasilPencarian.contains(e.target)) {
            hasilPencarian.style.display = 'none';
        }
    });

    // Tampilkan bidang di peta
    document.getElementById('btn-tampilkan').addEventListener('click', function() {
        if (!bidangDipilih) return;
        fetch('api/bidang.php?id=' + bidangDipilih.id)
            .then(r => r.json())
            .then(data => {
                if (!data.item || !data.item.geojson) {
                    alert('Bidang tidak memiliki geometri.');
                    return;
                }
                const geojson = data.item.geojson;
                if (layerBidang) peta.removeLayer(layerBidang);
                layerBidang = L.geoJSON({ type: 'Feature', geometry: geojson, properties: {} }, {
                    style: { color: '#1f6b50', weight: 3, fillColor: '#1f6b50', fillOpacity: 0.25 }
                }).addTo(peta);
                peta.fitBounds(layerBidang.getBounds(), { padding: [40, 40] });

                document.getElementById('info-bidang').style.display = 'block';
                document.getElementById('info-kode').textContent = bidangDipilih.kode;
                document.getElementById('info-luas').textContent = bidangDipilih.luas.toLocaleString('id-ID');
                document.getElementById('info-blok').textContent = bidangDipilih.blok;
                document.getElementById('bidang-asal-id').value = bidangDipilih.id;

                document.getElementById('btn-mulai-garis').disabled = false;
            });
    });

    // Mode gambar garis
    document.getElementById('btn-mulai-garis').addEventListener('click', function() {
        modeGambar = true;
        titikGaris = [];
        updateInfoGaris();
        this.disabled = true;
        document.getElementById('btn-selesai-garis').disabled = false;
        document.getElementById('btn-undo-garis').disabled = false;
        document.getElementById('btn-hapus-garis').disabled = false;
        document.getElementById('btn-pisah').disabled = true;
        peta.getContainer().style.cursor = 'crosshair';
    });

    document.getElementById('btn-selesai-garis').addEventListener('click', function() {
        modeGambar = false;
        peta.getContainer().style.cursor = '';
        this.disabled = true;
        document.getElementById('btn-mulai-garis').disabled = false;
        if (titikGaris.length >= 2) {
            document.getElementById('btn-pisah').disabled = false;
        }
    });

    document.getElementById('btn-undo-garis').addEventListener('click', function() {
        if (titikGaris.length > 0) {
            titikGaris.pop();
            updateGaris();
            updateInfoGaris();
        }
    });

    document.getElementById('btn-hapus-garis').addEventListener('click', function() {
        titikGaris = [];
        if (layerGaris) { peta.removeLayer(layerGaris); layerGaris = null; }
        if (layerHasil) { peta.removeLayer(layerHasil); layerHasil = null; }
        updateInfoGaris();
        document.getElementById('btn-pisah').disabled = true;
        document.getElementById('bagian-hasil').style.display = 'none';
    });

    // Klik peta untuk tambah titik garis
    peta.on('click', function(e) {
        if (!modeGambar) return;
        titikGaris.push([e.latlng.lng, e.latlng.lat]);
        updateGaris();
        updateInfoGaris();
    });

    // Klik kanan untuk undo
    peta.getContainer().addEventListener('contextmenu', function(e) {
        if (!modeGambar) return;
        e.preventDefault();
        if (titikGaris.length > 0) {
            titikGaris.pop();
            updateGaris();
            updateInfoGaris();
        }
    });

    function updateGaris() {
        if (layerGaris) peta.removeLayer(layerGaris);
        if (titikGaris.length < 2) {
            layerGaris = null;
            return;
        }
        const latlngs = titikGaris.map(t => [t[1], t[0]]);
        layerGaris = L.polyline(latlngs, { color: '#e53935', weight: 3, dashArray: '8,4' }).addTo(peta);
    }

    function updateInfoGaris() {
        document.getElementById('info-garis').textContent = titikGaris.length + ' titik garis';
    }

    // Pecah bidang
    document.getElementById('btn-pisah').addEventListener('click', function() {
        if (!layerBidang || titikGaris.length < 2) {
            alert('Pilih bidang dan gambar garis pemisah terlebih dahulu.');
            return;
        }

        const bidangGeoJSON = layerBidang.toGeoJSON();
        const bidangFeature = bidangGeoJSON.features ? bidangGeoJSON.features[0] : bidangGeoJSON;

        // Buffer garis agar memotong polygon
        const garisFeature = {
            type: 'Feature',
            geometry: {
                type: 'LineString',
                coordinates: titikGaris
            }
        };

        try {
            const garisBuffer = turf.buffer(garisFeature, 0.00001, { units: 'kilometers' });
            const hasil = turf.difference(bidangFeature, garisBuffer);

            if (!hasil) {
                alert('Garis tidak memotong bidang. Pastikan garis melintasi bidang dari sisi ke sisi.');
                return;
            }

            tampilkanHasil(hasil);
        } catch (err) {
            alert('Error memecah bidang: ' + err.message);
            console.error(err);
        }
    });

    function tampilkanHasil(hasil) {
        if (layerHasil) peta.removeLayer(layerHasil);

        let bagian = [];
        if (hasil.geometry.type === 'MultiPolygon') {
            hasil.geometry.coordinates.forEach((coords, i) => {
                bagian.push({
                    type: 'Feature',
                    geometry: { type: 'Polygon', coordinates: coords },
                    properties: { bagian: i + 1 }
                });
            });
        } else if (hasil.geometry.type === 'Polygon') {
            bagian.push({
                type: 'Feature',
                geometry: hasil.geometry,
                properties: { bagian: 1 }
            });
        }

        if (bagian.length < 2) {
            alert('Garis pemisah tidak membagi bidang menjadi minimal 2 bagian. Coba gambar garis yang berbeda.');
            return;
        }

        const warna = ['#2196F3', '#FF9800', '#4CAF50', '#E91E63', '#9C27B0'];
        layerHasil = L.geoJSON({ type: 'FeatureCollection', features: bagian }, {
            style: function(feature) {
                const idx = (feature.properties.bagian - 1) % warna.length;
                return { color: warna[idx], weight: 2, fillColor: warna[idx], fillOpacity: 0.3 };
            },
            onEachFeature: function(feature, layer) {
                const luas = turf.area(feature) ;
                layer.bindTooltip('Bagian ' + feature.properties.bagian + '<br>Luas: ' + luas.toFixed(1) + ' m&sup2;', { sticky: true });
            }
        }).addTo(peta);

        // Daftar hasil
        const daftar = document.getElementById('daftar-hasil');
        let html = '<div style="display:grid; gap:10px;">';
        bagian.forEach((b, i) => {
            const luas = turf.area(b);
            html += '<div style="padding:10px; border:1px solid var(--garis); border-radius:4px; border-left:4px solid ' + warna[i % warna.length] + ';">' +
                '<strong>Bagian ' + (i + 1) + '</strong> — Luas: ' + luas.toFixed(2) + ' m&sup2;' +
                '<div style="margin-top:5px;">' +
                '<label>Kode bidang baru: <input type="text" name="kode_' + i + '" placeholder="Auto" style="padding:4px; border:1px solid var(--garis); border-radius:3px; width:150px;"></label>' +
                '</div>' +
                '</div>';
        });
        html += '</div>';
        daftar.innerHTML = html;

        document.getElementById('hasil-geojson').value = JSON.stringify(hasil);
        document.getElementById('bagian-hasil').style.display = 'block';
    }

    // Simpan hasil pecah
    document.getElementById('form-simpan-pecah').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const kodeInputs = document.querySelectorAll('#daftar-hasil input[name^="kode_"]');
        const kodeBaru = [];
        kodeInputs.forEach(input => kodeBaru.push(input.value));
        formData.append('kode_baru', JSON.stringify(kodeBaru));

        fetch('api/pecah_bidang.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.sukses) {
                alert('Bidang berhasil dipecah menjadi ' + data.jumlah + ' bagian.');
                window.location.href = 'bidang.php?id=' + data.bidang_baru[0];
            } else {
                alert('Gagal: ' + (data.pesan || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
    });

    document.getElementById('btn-batal').addEventListener('click', function() {
        if (layerHasil) { peta.removeLayer(layerHasil); layerHasil = null; }
        document.getElementById('bagian-hasil').style.display = 'none';
        document.getElementById('btn-pisah').disabled = false;
    });
})();
</script>

<?php sip_footer(['leaflet' => true]); ?>
