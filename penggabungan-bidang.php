<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
wajib_masuk();

$csrf = csrf_token();

sip_header('Penggabungan Bidang', 'digitasi', ['leaflet' => true]);
?>

<main class="konten">
    <h1>Penggabungan Bidang</h1>
    <p class="petunjuk"><a href="digitasi.php">&larr; Kembali ke Panel Tools</a> | Pilih 2 atau lebih bidang yang berdekatan untuk digabungkan menjadi satu bidang baru.</p>

    <div class="kartu" style="margin-bottom:15px;">
        <h2 style="font-size:16px; margin-bottom:10px;">1. Cari dan Pilih Bidang</h2>
        <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
            <div style="flex:1; min-width:250px;">
                <label for="bidang-search" style="display:block; margin-bottom:4px; font-weight:600;">Cari bidang (kode, NIB, atau pemilik):</label>
                <input type="text" id="bidang-search" placeholder="Ketik minimal 2 karakter..." autocomplete="off" style="width:100%; padding:8px; border:1px solid var(--garis); border-radius:4px;">
                <div id="hasil-pencarian" style="margin-top:4px; max-height:250px; overflow-y:auto; border:1px solid var(--garis); border-radius:4px; display:none; background:#fff;"></div>
            </div>
            <button type="button" id="btn-tambah" class="tombol" disabled>Tambahkan ke Pilihan</button>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:15px;">
        <h2 style="font-size:16px; margin-bottom:10px;">2. Bidang Dipilih <span id="jumlah-dipilih" style="color:var(--redup); font-weight:normal; font-size:14px;">(0 bidang)</span></h2>
        <div id="daftar-dipilih" style="min-height:60px; padding:10px; background:#f9f9f9; border-radius:4px; border:1px dashed var(--garis);">
            <p style="color:var(--redup); margin:0; font-size:14px;">Belum ada bidang dipilih. Cari dan tambahkan bidang di atas.</p>
        </div>
        <div class="baris-tombol" style="margin-top:10px;">
            <button type="button" id="btn-tampilkan-semua" class="tombol sekunder" disabled>Tampilkan di Peta</button>
            <button type="button" id="btn-hapus-semua" class="tombol sekunder" disabled>Hapus Semua Pilihan</button>
            <button type="button" id="btn-gabung" class="tombol utama" disabled>Gabungkan Bidang</button>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:15px;">
        <div id="peta-gabung" style="width:100%; height:500px; border-radius:4px; border:1px solid var(--garis);"></div>
    </div>

    <div class="kartu" id="bagian-hasil" style="margin-bottom:15px; display:none;">
        <h2 style="font-size:16px; margin-bottom:10px;">3. Hasil Penggabungan</h2>
        <div id="info-hasil" style="padding:10px; background:#f0f7f0; border-radius:4px; margin-bottom:15px;"></div>
        <form id="form-simpan-gabung" style="margin-top:15px;">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" id="asal-ids" name="asal_ids" value="">
            <input type="hidden" id="gabung-geojson" name="gabung_geojson" value="">

            <div class="grid-2" style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">
                <div class="baris-form">
                    <label for="kode-baru" style="display:block; margin-bottom:4px; font-weight:600;">Kode bidang baru *</label>
                    <input type="text" id="kode-baru" name="kode_baru" required placeholder="Contoh: HM-0999" style="width:100%; padding:8px; border:1px solid var(--garis); border-radius:4px;">
                </div>
                <div class="baris-form">
                    <label for="nib-baru" style="display:block; margin-bottom:4px; font-weight:600;">NIB baru</label>
                    <input type="text" id="nib-baru" name="nib_baru" placeholder="Opsional" style="width:100%; padding:8px; border:1px solid var(--garis); border-radius:4px;">
                </div>
                <div class="baris-form">
                    <label for="jenis-hak-baru" style="display:block; margin-bottom:4px; font-weight:600;">Jenis Hak *</label>
                    <select id="jenis-hak-baru" name="jenis_hak_id" required style="width:100%; padding:8px; border:1px solid var(--garis); border-radius:4px;">
                        <option value="">— pilih —</option>
                        <?php
                        $jenis_hak = db()->query("SELECT id, kode, nama FROM jenis_hak ORDER BY kode");
                        foreach ($jenis_hak as $jh):
                        ?>
                        <option value="<?= $jh['id'] ?>"><?= htmlspecialchars($jh['kode'] . ' — ' . $jh['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="baris-form">
                    <label for="blok-baru" style="display:block; margin-bottom:4px; font-weight:600;">Blok</label>
                    <select id="blok-baru" name="blok_id" style="width:100%; padding:8px; border:1px solid var(--garis); border-radius:4px;">
                        <option value="">— tanpa blok —</option>
                        <?php
                        $blok_list = db()->query("SELECT id, nama FROM blok ORDER BY nama");
                        foreach ($blok_list as $b):
                        ?>
                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="baris-form">
                    <label for="dusun-baru" style="display:block; margin-bottom:4px; font-weight:600;">Dusun</label>
                    <select id="dusun-baru" name="dusun_id" style="width:100%; padding:8px; border:1px solid var(--garis); border-radius:4px;">
                        <option value="">— tanpa dusun —</option>
                        <?php
                        $dusun_list = db()->query("SELECT id, nama FROM dusun ORDER BY nama");
                        foreach ($dusun_list as $d):
                        ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="baris-form">
                    <label for="luas-baru" style="display:block; margin-bottom:4px; font-weight:600;">Luas (m&sup2;)</label>
                    <input type="number" id="luas-baru" name="luas_m2" step="0.01" placeholder="Otomatis dari gabungan" readonly style="width:100%; padding:8px; border:1px solid var(--garis); border-radius:4px; background:#f5f5f5;">
                </div>
            </div>

            <div class="baris-form" style="margin-top:10px;">
                <label for="catatan" style="display:block; margin-bottom:4px; font-weight:600;">Catatan</label>
                <textarea id="catatan" name="catatan" rows="3" placeholder="Gabungan dari bidang: ..." style="width:100%; padding:8px; border:1px solid var(--garis); border-radius:4px;"></textarea>
            </div>

            <div class="baris-tombol" style="margin-top:15px;">
                <button type="submit" class="tombol utama">Simpan Bidang Gabungan</button>
                <button type="button" id="btn-batal" class="tombol sekunder">Batal</button>
            </div>
        </form>
    </div>
</main>

<style>
.item-dipilih {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    margin-bottom: 6px;
    background: #fff;
    border: 1px solid var(--garis);
    border-radius: 4px;
    font-size: 14px;
}
.item-dipilih .info { flex: 1; }
.item-dipilih .hapus {
    background: none;
    border: none;
    color: #c00;
    cursor: pointer;
    font-size: 18px;
    padding: 0 5px;
}
.bidang-aktif { color: #1f6b50; font-weight: 600; }
.bidang-tidakaktif { color: #999; }
</style>

<script src="https://unpkg.com/@turf/turf@6/turf.min.js"></script>
<script>
(function() {
    'use strict';

    const peta = L.map('peta-gabung').setView([-7.09, 110.07], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 20,
        attribution: '&copy; OSM'
    }).addTo(peta);

    let bidangDipilih = [];
    let layerBidang = {};
    let layerGabungan = null;
    let semuaBidangLayer = null;

    const searchInput = document.getElementById('bidang-search');
    const hasilPencarian = document.getElementById('hasil-pencarian');
    let searchTimeout = null;
    let hasilTerakhir = [];

    // Load semua bidang sebagai context
    fetch('api/bidang.php?lapisan=bidang')
        .then(r => r.json())
        .then(data => {
            semuaBidangLayer = L.geoJSON(data, {
                style: { color: '#999', weight: 1, fillColor: '#ddd', fillOpacity: 0.15 },
                onEachFeature: function(feature, layer) {
                    if (feature.properties) {
                        layer.bindTooltip(feature.properties.kode || '-', { sticky: true });
                    }
                }
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
                    hasilTerakhir = data.data || [];
                    if (!data.sukses || hasilTerakhir.length === 0) {
                        hasilPencarian.innerHTML = '<div style="padding:10px;color:#666;">Tidak ditemukan</div>';
                        hasilPencarian.style.display = 'block';
                        return;
                    }
                    let html = '';
                    hasilTerakhir.forEach(b => {
                        const sudahDipilih = bidangDipilih.some(x => x.id === b.id);
                        const kelas = sudahDipilih ? 'bidang-tidakaktif' : 'bidang-aktif';
                        const label = sudahDipilih ? b.label + ' (sudah dipilih)' : b.label;
                        html += '<div class="item-hasil ' + kelas + '" data-id="' + b.id + '" style="padding:10px;border-bottom:1px solid #eee;cursor:' + (sudahDipilih ? 'default' : 'pointer') + ';" ' +
                            (sudahDipilih ? '' : 'onmouseover="this.style.background=\'#f5f5f5\'" onmouseout="this.style.background=\'white\'"') +
                            '>' + label + '</div>';
                    });
                    hasilPencarian.innerHTML = html;
                    hasilPencarian.style.display = 'block';

                    document.querySelectorAll('.item-hasil:not(.bidang-tidakaktif)').forEach(item => {
                        item.addEventListener('click', function() {
                            const id = this.dataset.id;
                            const found = hasilTerakhir.find(b => String(b.id) === id);
                            if (found) {
                                searchInput.value = found.kode;
                                searchInput.dataset.selectedId = found.id;
                                document.getElementById('btn-tambah').disabled = false;
                            }
                            hasilPencarian.style.display = 'none';
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

    // Tambah bidang ke pilihan
    document.getElementById('btn-tambah').addEventListener('click', function() {
        const id = searchInput.dataset.selectedId;
        if (!id) return;

        if (bidangDipilih.some(b => b.id === id)) {
            alert('Bidang sudah ada dalam pilihan.');
            return;
        }

        const found = hasilTerakhir.find(b => String(b.id) === id);
        if (!found) return;

        // Fetch geometri
        fetch('api/bidang.php?id=' + id)
            .then(r => r.json())
            .then(data => {
                if (!data.item || !data.item.geojson) {
                    alert('Bidang tidak memiliki geometri.');
                    return;
                }

                const item = {
                    id: found.id,
                    kode: found.kode,
                    luas: found.luas_m2,
                    blok_nama: found.blok_nama,
                    geojson: data.item.geojson
                };
                bidangDipilih.push(item);

                const warna = ['#2196F3', '#FF9800', '#4CAF50', '#E91E63', '#9C27B0', '#00BCD4'];
                const idx = (bidangDipilih.length - 1) % warna.length;
                const layer = L.geoJSON({ type: 'Feature', geometry: data.item.geojson, properties: {} }, {
                    style: { color: warna[idx], weight: 2, fillColor: warna[idx], fillOpacity: 0.25 }
                }).addTo(peta);
                layerBidang[item.id] = layer;

                searchInput.value = '';
                searchInput.dataset.selectedId = '';
                this.disabled = true;
                updateDaftarDipilih();
            });
    });

    function updateDaftarDipilih() {
        const daftar = document.getElementById('daftar-dipilih');
        const jumlah = document.getElementById('jumlah-dipilih');
        jumlah.textContent = '(' + bidangDipilih.length + ' bidang)';

        if (bidangDipilih.length === 0) {
            daftar.innerHTML = '<p style="color:var(--redup); margin:0; font-size:14px;">Belum ada bidang dipilih.</p>';
            document.getElementById('btn-tampilkan-semua').disabled = true;
            document.getElementById('btn-hapus-semua').disabled = true;
            document.getElementById('btn-gabung').disabled = true;
            return;
        }

        let html = '';
        bidangDipilih.forEach((b, i) => {
            html += '<div class="item-dipilih">' +
                '<div class="info"><strong>' + (i + 1) + '. ' + b.kode + '</strong> — Luas: ' + (b.luas || 0).toLocaleString('id-ID') + ' m&sup2; | Blok: ' + (b.blok_nama || '-') + '</div>' +
                '<button class="hapus" data-id="' + b.id + '" title="Hapus">&times;</button>' +
                '</div>';
        });
        daftar.innerHTML = html;

        document.querySelectorAll('.hapus').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                bidangDipilih = bidangDipilih.filter(b => b.id !== id);
                if (layerBidang[id]) {
                    peta.removeLayer(layerBidang[id]);
                    delete layerBidang[id];
                }
                updateDaftarDipilih();
                if (layerGabungan) { peta.removeLayer(layerGabungan); layerGabungan = null; }
                document.getElementById('bagian-hasil').style.display = 'none';
            });
        });

        document.getElementById('btn-tampilkan-semua').disabled = false;
        document.getElementById('btn-hapus-semua').disabled = false;
        document.getElementById('btn-gabung').disabled = bidangDipilih.length < 2;
    }

    // Tampilkan semua di peta
    document.getElementById('btn-tampilkan-semua').addEventListener('click', function() {
        const group = L.featureGroup(Object.values(layerBidang));
        peta.fitBounds(group.getBounds(), { padding: [40, 40] });
    });

    // Hapus semua pilihan
    document.getElementById('btn-hapus-semua').addEventListener('click', function() {
        if (!confirm('Hapus semua pilihan bidang?')) return;
        Object.values(layerBidang).forEach(l => peta.removeLayer(l));
        layerBidang = {};
        bidangDipilih = [];
        updateDaftarDipilih();
        if (layerGabungan) { peta.removeLayer(layerGabungan); layerGabungan = null; }
        document.getElementById('bagian-hasil').style.display = 'none';
    });

    // Gabungkan bidang
    document.getElementById('btn-gabung').addEventListener('click', function() {
        if (bidangDipilih.length < 2) {
            alert('Pilih minimal 2 bidang untuk digabungkan.');
            return;
        }

        try {
            let gabungan = null;
            bidangDipilih.forEach(b => {
                const feature = { type: 'Feature', geometry: b.geojson, properties: {} };
                if (!gabungan) {
                    gabungan = feature;
                } else {
                    gabungan = turf.union(gabungan, feature);
                }
            });

            if (!gabungan) {
                alert('Gagal menggabungkan bidang.');
                return;
            }

            const luasTotal = turf.area(gabungan);
            const warna = '#673AB7';

            if (layerGabungan) peta.removeLayer(layerGabungan);
            layerGabungan = L.geoJSON(gabungan, {
                style: { color: warna, weight: 3, fillColor: warna, fillOpacity: 0.3 }
            }).addTo(peta);
            peta.fitBounds(layerGabungan.getBounds(), { padding: [40, 40] });

            const kodeBidang = bidangDipilih.map(b => b.kode).join(', ');
            document.getElementById('info-hasil').innerHTML =
                '<strong>Hasil gabungan:</strong> ' + bidangDipilih.length + ' bidang digabungkan<br>' +
                '<strong>Bidang asal:</strong> ' + kodeBidang + '<br>' +
                '<strong>Luas gabungan:</strong> ' + luasTotal.toFixed(2) + ' m&sup2;';

            document.getElementById('asal-ids').value = JSON.stringify(bidangDipilih.map(b => b.id));
            document.getElementById('gabung-geojson').value = JSON.stringify(gabungan.geometry);
            document.getElementById('luas-baru').value = luasTotal.toFixed(2);
            document.getElementById('catatan').value = 'Gabungan dari bidang: ' + kodeBidang;

            document.getElementById('bagian-hasil').style.display = 'block';
        } catch (err) {
            alert('Error menggabungkan bidang: ' + err.message);
            console.error(err);
        }
    });

    // Simpan hasil gabungan
    document.getElementById('form-simpan-gabung').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);

        fetch('api/gabung_bidang.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.sukses) {
                alert('Bidang gabungan berhasil disimpan: ' + data.kode);
                window.location.href = 'bidang.php?id=' + data.id;
            } else {
                alert('Gagal: ' + (data.pesan || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
    });

    document.getElementById('btn-batal').addEventListener('click', function() {
        if (layerGabungan) { peta.removeLayer(layerGabungan); layerGabungan = null; }
        document.getElementById('gabung-geojson').value = '';
        document.getElementById('bagian-hasil').style.display = 'none';
    });
})();
</script>

<?php sip_footer(['leaflet' => true]); ?>
