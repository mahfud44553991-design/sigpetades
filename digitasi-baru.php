<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
wajib_masuk();

$csrf = csrf_token();
$pesan = $_SESSION['pesan_digitasi'] ?? '';
unset($_SESSION['pesan_digitasi']);

$pratinjau = null;
$simpanPratinjau = $_SESSION['penanda_bidang'] ?? null;
if (is_array($simpanPratinjau)
    && (int) ($simpanPratinjau['pengguna_id'] ?? 0) === (int) auth_user_id()
    && time() - (int) ($simpanPratinjau['waktu'] ?? 0) <= 1800
    && preg_match('/POLYGON\s*\(\(([^()]+)\)/', (string) ($simpanPratinjau['wkt'] ?? ''), $cocok)
) {
    $cincin = [];
    foreach (explode(',', $cocok[1]) as $pasangan) {
        $bagian = preg_split('/\s+/', trim($pasangan));
        if (count($bagian) >= 2 && is_numeric($bagian[0]) && is_numeric($bagian[1])) {
            $cincin[] = [(float) $bagian[0], (float) $bagian[1]];
        }
    }
    if (count($cincin) >= 4) {
        $pratinjau = [
            'kode' => (string) ($simpanPratinjau['kode'] ?? ''),
            'luas' => round((float) ($simpanPratinjau['luas'] ?? 0), 2),
            'jenis' => (string) ($simpanPratinjau['jenis'] ?? 'tertutup'),
            'cincin' => $cincin,
        ];
    }
}

$peringatanPratinjau = '';
if ($pratinjau !== null) {
    $angka = static function ($nilai): ?float {
        return is_numeric($nilai) ? (float) $nilai : null;
    };
    $dariPratinjau = isset($_GET['dari']) && is_string($_GET['dari']) ? $_GET['dari'] : '';
    $xPenanda = $angka($_GET['x'] ?? null);
    $yPenanda = $angka($_GET['y'] ?? null);
    $xSesi = $angka($simpanPratinjau['x'] ?? null);
    $ySesi = $angka($simpanPratinjau['y'] ?? null);
    $cocok = $dariPratinjau === 'pratinjau'
        && $xPenanda !== null && $yPenanda !== null
        && $xSesi !== null && $ySesi !== null
        && hypot($xPenanda - $xSesi, $yPenanda - $ySesi) <= 0.5;
    if (!$cocok) {
        if ($dariPratinjau === 'pratinjau') {
            $peringatanPratinjau = 'Pratinjau bidang tidak dimuat karena bukan milik penanda saat ini. '
                . 'Di Peta Induk, letakkan penanda lalu klik “Pratinjau bidang”, baru masuk ke Digitasi Obyek Baru.';
        }
        $pratinjau = null;
    }
}

sip_header('Digitasi Obyek Baru', 'digitasi');
sip_header('Digitasi Obyek Baru', 'digitasi', ['leaflet' => true]);
?>

<main class="konten">
    <h1>Digitasi Obyek Baru</h1>
    <p class="petunjuk"><a href="digitasi.php">← Kembali ke Panel Tools</a> | Pilih bidang referensi, unggah gambar peta, atur batas koordinat, klik untuk membuat polygon, lalu simpan sebagai bidang baru.</p>

    <?php if ($pesan): ?>
        <div class="pesan info"><?= htmlspecialchars($pesan) ?></div>
    <?php endif; ?>

    <?php if ($peringatanPratinjau !== ''): ?>
        <div class="seru seru-bahaya" role="status"><?= htmlspecialchars($peringatanPratinjau) ?></div>
    <?php endif; ?>

    <div class="kartu digitasi-kontainer">
        <h2>0. Pilih Bidang Referensi</h2>
        <p class="petunjuk">Klik pada peta untuk memilih lokasi. Sistem akan mencari bidang terdekat dalam radius yang ditentukan. Atribut (blok, dusun, jenis hak) akan otomatis terisi.</p>
        
        <div class="baris-form" style="margin-bottom:10px;">
            <label for="radius-search">Radius pencarian (meter):</label>
            <select id="radius-search" style="padding:8px; border:1px solid #ddd; border-radius:4px;">
                <option value="50">50 meter</option>
                <option value="100" selected>100 meter</option>
                <option value="150">150 meter</option>
                <option value="200">200 meter</option>
                <option value="500">500 meter</option>
                <option value="1000">1 km</option>
            </select>
            <button type="button" id="btn-cari-ulang" class="tombol sekunder" style="margin-left:10px;" disabled>Cari Ulang di Lokasi Terakhir</button>
        </div>
        
        <div id="peta-referensi" style="width:100%; height:400px; border:1px solid #ddd; border-radius:4px; margin-bottom:10px;"></div>
        
        <div id="info-klik" style="padding:10px; background:#e3f2fd; border-radius:4px; margin-bottom:10px;">
            <strong>Instruksi:</strong> Klik pada peta untuk mencari bidang terdekat di lokasi tersebut.
        </div>
        
        <div id="hasil-pencarian" style="max-height:300px; overflow-y:auto; border:1px solid #ddd; border-radius:4px; display:none; margin-bottom:10px;"></div>
        
        <input type="hidden" id="lokasi-lat" value="">
        <input type="hidden" id="lokasi-lon" value="">
        
        <div id="info-referensi" style="margin-top:10px; padding:10px; background:#f0f0f0; border-radius:4px; display:none;">
            <strong>Bidang referensi:</strong> <span id="ref-kode"></span>
        </div>

        <details style="margin-top:15px;">
            <summary class="petunjuk" style="cursor:pointer;">Skema: apa yang dilakukan radius pencarian</summary>
            <p class="petunjuk" style="margin-top:10px;">Lingkaran biru putus-putus adalah radius pencarian dari titik yang diklik. Bidang di dalamnya dijadikan kandidat referensi dan atributnya (blok, dusun, jenis hak) ikut terisi.</p>
            <img src="assets/img/skema-radius.svg" alt="Skema garis radius pencarian pada peta bidang" style="max-width:100%; border:1px solid #ddd; border-radius:4px;">
        </details>

        <?php if ($pratinjau): ?>
            <?php
            $labelJenis = [
                'tertutup' => 'poligon tertutup',
                'garis-tertutup' => 'garis bidang tertutup',
                'garis' => 'rangkaian garis',
                'garis-baru' => 'garis bidang baru dari garis sekitar',
            ][$pratinjau['jenis']] ?? 'geometri 020100';
            ?>
            <div id="pratinjau-peta-induk" style="margin-top:10px; padding:10px; background:#eef6ff; border:1px solid #b9d6f5; border-radius:4px;">
                <strong>Polygon dari Peta Induk 020100:</strong>
                <span><?= e($pratinjau['kode']) ?></span>
                · <?= number_format($pratinjau['luas'], 2, ',', '.') ?> m² · <?= e($labelJenis) ?>
                <p class="petunjuk" style="margin:6px 0;">Muat polygon ini ke kanvas untuk membuat bidang baru. Titik bisa disunting; kode dan luas terisi otomatis — ubah kode bila sudah dipakai.</p>
                <button type="button" id="btn-muat-pratinjau" class="tombol">Muat Polygon ke Kanvas</button>
                <span id="status-pratinjau" class="info-teks"></span>
            </div>
            <script type="application/json" id="data-pratinjau"><?= json_encode($pratinjau, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <?php endif; ?>
    </div>

    <div class="kartu digitasi-kontainer">
        <h2>1. Unggah Gambar</h2>
        <p class="petunjuk">Unggah scan peta (JPG/PNG) atau file DXF sebagai latar digitasi. Bagian 2–4 muncul setelah latar berhasil dimuat.</p>
        <form id="form-upload" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <div class="baris-form">
                <label for="gambar">Pilih gambar (JPG/PNG) atau file DXF:</label>
                <input type="file" id="gambar" name="gambar" accept="image/jpeg,image/png,.dxf" required>
            </div>
            <button type="submit" class="tombol">Unggah</button>
            <span id="info-latar" class="info-teks">Belum ada latar.</span>
        </form>
    </div>

    <div class="kartu digitasi-kontainer" id="bagian-batas" style="display:none;">
        <h2>2. Atur Bounding Box</h2>
        <p class="petunjuk">Masukkan koordinat sudut gambar untuk konversi pixel ke GPS.</p>
        <div class="grid-2">
            <div class="baris-form">
                <label for="lat-min">Latitude bawah (south):</label>
                <input type="number" id="lat-min" step="0.0000001" value="-7.1100" required>
            </div>
            <div class="baris-form">
                <label for="lat-max">Latitude atas (north):</label>
                <input type="number" id="lat-max" step="0.0000001" value="-7.0700" required>
            </div>
            <div class="baris-form">
                <label for="lon-min">Longitude kiri (west):</label>
                <input type="number" id="lon-min" step="0.0000001" value="110.0400" required>
            </div>
            <div class="baris-form">
                <label for="lon-max">Longitude kanan (east):</label>
                <input type="number" id="lon-max" step="0.0000001" value="110.1000" required>
            </div>
        </div>
        <button type="button" id="btn-terapkan-batas" class="tombol">Terapkan Batas</button>
    </div>

    <div class="kartu digitasi-kontainer" id="bagian-digitasi" style="display:none;">
        <h2>3. Digitasi Polygon</h2>
        <p class="petunjuk">Klik pada gambar untuk menambah titik. Klik kanan untuk hapus titik terakhir. Gunakan zoom untuk memperbesar/memperkecil.</p>

        <div class="canvas-kontainer" style="position:relative;overflow:hidden;width:100%;height:500px;border:1px solid #ddd;">
            <div id="canvas-wrapper" style="transform-origin:0 0;position:absolute;top:0;left:0;">
                <canvas id="canvas-digitasi"></canvas>
            </div>
        </div>

        <div class="baris-tombol">
            <button type="button" id="btn-zoom-in" class="tombol sekunder" title="Zoom In">🔍+</button>
            <button type="button" id="btn-zoom-out" class="tombol sekunder" title="Zoom Out">🔍-</button>
            <button type="button" id="btn-zoom-reset" class="tombol sekunder" title="Reset Zoom">↺ Reset</button>
            <span id="info-zoom" style="margin-left:10px;color:#666;">100%</span>
            <button type="button" id="btn-undo" class="tombol sekunder">Undo Titik Terakhir</button>
            <button type="button" id="btn-hapus-semua" class="tombol sekunder">Hapus Semua Titik</button>
            <span id="info-titik" class="info-teks">0 titik</span>
        </div>

        <div class="baris-tombol" style="margin-top:10px;">
            <span style="color:#666;margin-right:10px;">Navigasi:</span>
            <button type="button" id="btn-pan-up" class="tombol sekunder" title="Geser Atas">▲</button>
            <button type="button" id="btn-pan-down" class="tombol sekunder" title="Geser Bawah">▼</button>
            <button type="button" id="btn-pan-left" class="tombol sekunder" title="Geser Kiri">◀</button>
            <button type="button" id="btn-pan-right" class="tombol sekunder" title="Geser Kanan">▶</button>
            <button type="button" id="btn-pan-reset" class="tombol sekunder" title="Reset Posisi">⌂ Pusat</button>
        </div>

        <div id="daftar-titik" class="daftar-titik"></div>
    </div>

    <div class="kartu digitasi-kontainer" id="bagian-atribut" style="display:none;">
        <h2>4. Atribut Bidang</h2>
        <form id="form-simpan">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" id="geojson-polygon" name="geojson">
            <input type="hidden" id="bidang-referensi-id" name="bidang_referensi_id" value="">

            <div class="grid-2">
                <div class="baris-form">
                    <label for="kode">Kode Bidang *</label>
                    <input type="text" id="kode" name="kode" required placeholder="Contoh: HM-0181">
                </div>
                <div class="baris-form">
                    <label for="nib">NIB</label>
                    <input type="text" id="nib" name="nib" placeholder="Contoh: 04649">
                </div>
                <div class="baris-form">
                    <label for="jenis-hak">Jenis Hak *</label>
                    <select id="jenis-hak" name="jenis_hak_id" required>
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
                    <label for="luas">Luas (m²)</label>
                    <input type="number" id="luas" name="luas_m2" step="0.01" placeholder="Otomatis dari polygon">
                </div>
                <div class="baris-form">
                    <label for="blok">Blok</label>
                    <select id="blok" name="blok_id">
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
                    <label for="dusun">Dusun</label>
                    <select id="dusun" name="dusun_id">
                        <option value="">— tanpa dusun —</option>
                        <?php
                        $dusun_list = db()->query("SELECT id, nama FROM dusun ORDER BY nama");
                        foreach ($dusun_list as $d):
                        ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="baris-form">
                <label for="catatan">Catatan</label>
                <textarea id="catatan" name="catatan" rows="3"></textarea>
            </div>

            <div class="baris-tombol">
                <button type="submit" class="tombol utama">Simpan Bidang</button>
            </div>
        </form>
    </div>
</main>

<style>
.digitasi-kontainer { margin-bottom: 20px; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
.canvas-kontainer {
    border: 2px solid var(--garis);
    border-radius: 4px;
    overflow: auto;
    max-height: 600px;
    margin: 15px 0;
    background: #f5f5f5;
}
#canvas-digitasi { cursor: crosshair; display: block; }
.daftar-titik {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid var(--garis);
    border-radius: 4px;
    padding: 10px;
    margin-top: 10px;
    font-size: 12px;
    font-family: monospace;
}
.daftar-titik div { padding: 2px 0; border-bottom: 1px dashed #eee; }
.info-teks { margin-left: 10px; color: var(--redup); font-size: 13px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/dxf-parser@1.1.2/dist/dxf-parser.js"></script>

<script>
(function() {
    'use strict';

    const canvas = document.getElementById('canvas-digitasi');
    const ctx = canvas.getContext('2d');
    let gambar = null;
    let titik = [];
    let batas = null;
    let bidangReferensi = null;
    let zoomLevel = 1;
    let panX = 0;
    let panY = 0;
    const MIN_ZOOM = 0.25;
    const MAX_ZOOM = 5;
    const ZOOM_STEP = 0.25;
    const PAN_STEP = 50;

    const dataPratinjau = (function() {
        const node = document.getElementById('data-pratinjau');
        if (!node) return null;
        try {
            return JSON.parse(node.textContent);
        } catch (err) {
            console.error('Data pratinjau tidak terbaca:', err);
            return null;
        }
    })();

    function updateZoom(newZoom) {
        zoomLevel = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, newZoom));
        const wrapper = document.getElementById('canvas-wrapper');
        wrapper.style.transform = `translate(${panX}px, ${panY}px) scale(${zoomLevel})`;
        wrapper.style.width = (canvas.width * zoomLevel) + 'px';
        wrapper.style.height = (canvas.height * zoomLevel) + 'px';
        document.getElementById('info-zoom').textContent = Math.round(zoomLevel * 100) + '%';
    }

    function updatePan() {
        const wrapper = document.getElementById('canvas-wrapper');
        wrapper.style.transform = `translate(${panX}px, ${panY}px) scale(${zoomLevel})`;
    }

    function resetPan() {
        panX = 0;
        panY = 0;
        updatePan();
    }

    document.getElementById('btn-zoom-in').addEventListener('click', () => updateZoom(zoomLevel + ZOOM_STEP));
    document.getElementById('btn-zoom-out').addEventListener('click', () => updateZoom(zoomLevel - ZOOM_STEP));
    document.getElementById('btn-zoom-reset').addEventListener('click', () => { updateZoom(1); resetPan(); });

    document.getElementById('btn-pan-up').addEventListener('click', () => { panY += PAN_STEP; updatePan(); });
    document.getElementById('btn-pan-down').addEventListener('click', () => { panY -= PAN_STEP; updatePan(); });
    document.getElementById('btn-pan-left').addEventListener('click', () => { panX += PAN_STEP; updatePan(); });
    document.getElementById('btn-pan-right').addEventListener('click', () => { panX -= PAN_STEP; updatePan(); });
    document.getElementById('btn-pan-reset').addEventListener('click', resetPan);

    // Keyboard navigation (arrow keys)
    document.addEventListener('keydown', function(e) {
        // Skip jika sedang ketik di input/textarea
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

        switch (e.key) {
            case 'ArrowUp': e.preventDefault(); panY += PAN_STEP; updatePan(); break;
            case 'ArrowDown': e.preventDefault(); panY -= PAN_STEP; updatePan(); break;
            case 'ArrowLeft': e.preventDefault(); panX += PAN_STEP; updatePan(); break;
            case 'ArrowRight': e.preventDefault(); panX -= PAN_STEP; updatePan(); break;
        }
    });

    // Mouse wheel zoom
    canvas.addEventListener('wheel', function(e) {
        e.preventDefault();
        const delta = e.deltaY > 0 ? -ZOOM_STEP : ZOOM_STEP;
        updateZoom(zoomLevel + delta);
    }, { passive: false });

    // Leaflet map untuk pilih lokasi pencarian bidang terdekat
    const petaReferensi = L.map('peta-referensi').setView([-7.09, 110.07], 15);
    window.petaReferensi = petaReferensi; // Expose untuk debugging/testing
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(petaReferensi);

    let markerLokasi = null;
    let circleRadius = null;
    let lokasiTerakhir = null;
    const hasilPencarian = document.getElementById('hasil-pencarian');

    // Load semua bidang sebagai context layer
    fetch('api/bidang.php?lapisan=bidang&maks=9000')
        .then(res => res.json())
        .then(data => {
            L.geoJSON(data, {
                style: { color: '#999', weight: 1, fillColor: '#ddd', fillOpacity: 0.2 }
            }).addTo(petaReferensi);
        });

    // Klik peta untuk cari bidang terdekat
    petaReferensi.on('click', function(e) {
        const lat = e.latlng.lat;
        const lon = e.latlng.lng;
        lokasiTerakhir = { lat, lon };
        
        // Update marker
        if (markerLokasi) {
            markerLokasi.setLatLng(e.latlng);
        } else {
            markerLokasi = L.marker(e.latlng).addTo(petaReferensi);
        }
        
        // Update circle radius
        const radius = parseInt(document.getElementById('radius-search').value);
        if (circleRadius) {
            circleRadius.setRadius(radius);
            circleRadius.setLatLng(e.latlng);
        } else {
            circleRadius = L.circle(e.latlng, {
                radius: radius,
                color: '#2196F3',
                fillColor: '#2196F3',
                fillOpacity: 0.1
            }).addTo(petaReferensi);
        }
        
        // Simpan lokasi ke hidden input
        document.getElementById('lokasi-lat').value = lat;
        document.getElementById('lokasi-lon').value = lon;
        
        // Cari bidang terdekat
        cariBidangTerdekat(lat, lon, radius);
        
        // Enable tombol cari ulang
        document.getElementById('btn-cari-ulang').disabled = false;
        
        // Update info klik
        document.getElementById('info-klik').innerHTML = `<strong>Lokasi dipilih:</strong> ${lat.toFixed(6)}, ${lon.toFixed(6)} | Radius: ${radius}m`;
    });

    // Tombol cari ulang
    document.getElementById('btn-cari-ulang').addEventListener('click', function() {
        if (lokasiTerakhir) {
            const radius = parseInt(document.getElementById('radius-search').value);
            // Update circle radius
            if (circleRadius) {
                circleRadius.setRadius(radius);
            }
            cariBidangTerdekat(lokasiTerakhir.lat, lokasiTerakhir.lon, radius);
        }
    });

    // Fungsi cari bidang terdekat
    function cariBidangTerdekat(lat, lon, radius) {
        hasilPencarian.innerHTML = '<div style="padding:10px;color:#666;">Mencari bidang terdekat...</div>';
        hasilPencarian.style.display = 'block';
        
        fetch(`api/cari_bidang_terdekat.php?lat=${lat}&lon=${lon}&radius=${radius}&limit=20`)
            .then(res => res.json())
            .then(data => {
                if (!data.sukses) {
                    hasilPencarian.innerHTML = '<div style="padding:10px;color:#c00;">'
                        + (data.pesan || data.error || 'Pencarian bidang gagal.') + '</div>';
                    return;
                }
                if (data.data.length === 0) {
                    hasilPencarian.innerHTML = '<div style="padding:10px;color:#666;">Tidak ada bidang ditemukan dalam radius ' + radius + 'm dari lokasi ini.</div>';
                    return;
                }

                let html = '<div style="padding:10px; background:#f5f5f5; border-bottom:1px solid #ddd;"><strong>' + data.jumlah + ' bidang ditemukan dalam radius ' + radius + 'm:</strong></div>';
                data.data.forEach(b => {
                    html += `<div class="item-hasil" data-id="${b.id}" data-blok="${b.blok_id || ''}" data-dusun="${b.dusun_id || ''}" data-label="${b.label.replace(/"/g, '&quot;')}" style="padding:10px;border-bottom:1px solid #eee;cursor:pointer;transition:background 0.2s;" onmouseover="this.style.background='#e3f2fd'" onmouseout="this.style.background='white'">
                        ${b.label}
                    </div>`;
                });

                hasilPencarian.innerHTML = html;

                // Attach click handlers
                document.querySelectorAll('.item-hasil').forEach(item => {
                    item.addEventListener('click', function() {
                        const id = this.dataset.id;
                        const blokId = this.dataset.blok;
                        const dusunId = this.dataset.dusun;
                        const label = this.dataset.label;

                        bidangReferensi = {
                            id: id,
                            blok_id: blokId,
                            dusun_id: dusunId
                        };

                        document.getElementById('bidang-referensi-id').value = id;
                        document.getElementById('ref-kode').textContent = label;
                        document.getElementById('info-referensi').style.display = 'block';

                        document.getElementById('blok').value = blokId || '';
                        document.getElementById('dusun').value = dusunId || '';

                        hasilPencarian.style.display = 'none';
                        
                        // Highlight selected item
                        document.querySelectorAll('.item-hasil').forEach(el => el.style.background = 'white');
                        this.style.background = '#c8e6c9';
                    });
                });
            })
            .catch(err => {
                console.error('Error searching bidang:', err);
                hasilPencarian.innerHTML = '<div style="padding:10px;color:#c00;">Error mencari bidang</div>';
            });
    }

    // Bagian 2-4 baru berguna setelah latar digitasi tersedia
    function bukaBagian(namaLatar) {
        document.getElementById('bagian-batas').style.display = 'block';
        document.getElementById('bagian-digitasi').style.display = 'block';
        document.getElementById('bagian-atribut').style.display = 'block';
        document.getElementById('info-latar').textContent = 'Latar dimuat: ' + namaLatar
            + ' (' + canvas.width + '×' + canvas.height + ' px)';
        document.getElementById('bagian-batas').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function muatPratinjau() {
        if (!dataPratinjau || !Array.isArray(dataPratinjau.cincin)) return;

        const cincin = dataPratinjau.cincin.map(c => [Number(c[0]), Number(c[1])]);
        if (cincin.length > 1) {
            const awal = cincin[0];
            const akhir = cincin[cincin.length - 1];
            if (awal[0] === akhir[0] && awal[1] === akhir[1]) cincin.pop();
        }
        if (cincin.length < 3) return;

        const lons = cincin.map(c => c[0]);
        const lats = cincin.map(c => c[1]);
        const lonMin = Math.min(...lons), lonMax = Math.max(...lons);
        const latMin = Math.min(...lats), latMax = Math.max(...lats);
        const marginLon = Math.max((lonMax - lonMin) * 0.1, 0.00002);
        const marginLat = Math.max((latMax - latMin) * 0.1, 0.00002);
        const bulat = v => Number(v.toFixed(7));

        batas = {
            lonMin: bulat(lonMin - marginLon),
            lonMax: bulat(lonMax + marginLon),
            latMin: bulat(latMin - marginLat),
            latMax: bulat(latMax + marginLat)
        };
        document.getElementById('lon-min').value = batas.lonMin;
        document.getElementById('lon-max').value = batas.lonMax;
        document.getElementById('lat-min').value = batas.latMin;
        document.getElementById('lat-max').value = batas.latMax;

        const rasio = (batas.latMax - batas.latMin) / (batas.lonMax - batas.lonMin);
        let tinggi = Math.round(900 * rasio);
        if (tinggi < 400) tinggi = 400;
        if (tinggi > 1400) tinggi = 1400;
        canvas.width = Math.max(100, Math.round(tinggi / rasio));
        canvas.height = tinggi;

        gambar = null;
        titik = cincin.map(c => ({
            lon: c[0],
            lat: c[1],
            x: (c[0] - batas.lonMin) / (batas.lonMax - batas.lonMin) * canvas.width,
            y: (batas.latMax - c[1]) / (batas.latMax - batas.latMin) * canvas.height
        }));

        document.getElementById('bagian-batas').style.display = 'block';
        document.getElementById('bagian-digitasi').style.display = 'block';
        document.getElementById('bagian-atribut').style.display = 'block';

        const kode = document.getElementById('kode');
        if (!kode.value) kode.value = dataPratinjau.kode || '';
        const luas = document.getElementById('luas');
        if (!luas.value && dataPratinjau.luas) luas.value = dataPratinjau.luas;

        updateZoom(1);
        resetPan();
        const wadah = document.querySelector('.canvas-kontainer');
        if (wadah) {
            const pas = Math.min(
                1,
                (wadah.clientWidth - 4) / canvas.width,
                (wadah.clientHeight - 4) / canvas.height
            );
            updateZoom(pas);
        }
        gambarUlang();
        updateInfo();
        document.getElementById('status-pratinjau').textContent =
            'Polygon dimuat: ' + titik.length + ' titik. Periksa lalu simpan sebagai bidang baru.';
    }

    const tombolPratinjau = document.getElementById('btn-muat-pratinjau');
    if (tombolPratinjau) tombolPratinjau.addEventListener('click', muatPratinjau);

    // Upload gambar atau DXF
    document.getElementById('form-upload').addEventListener('submit', function(e) {
        e.preventDefault();
        const file = document.getElementById('gambar').files[0];
        if (!file) return;

        const isDxf = file.name.toLowerCase().endsWith('.dxf');

        if (isDxf) {
            // Handle DXF file
            const reader = new FileReader();
            reader.onload = function(ev) {
                try {
                    const parser = new DxfParser();
                    const dxfData = parser.parse(ev.target.result);
                    renderDxf(dxfData);
                    bukaBagian(file.name);
                } catch (err) {
                    alert('Error parsing DXF: ' + err.message);
                    console.error(err);
                }
            };
            reader.readAsText(file);
        } else {
            // Handle image file (PNG/JPG)
            const reader = new FileReader();
            reader.onload = function(ev) {
                const img = new Image();
                img.onload = function() {
                    gambar = img;
                    canvas.width = img.width;
                    canvas.height = img.height;
                    ctx.drawImage(img, 0, 0);
                    bukaBagian(file.name);
                };
                img.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        }
    });

    function renderDxf(dxfData) {
        if (!dxfData || !dxfData.entities) {
            alert('File DXF tidak memiliki entities');
            return;
        }

        // Calculate bounds
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;

        function updateBounds(x, y) {
            minX = Math.min(minX, x);
            minY = Math.min(minY, y);
            maxX = Math.max(maxX, x);
            maxY = Math.max(maxY, y);
        }

        dxfData.entities.forEach(entity => {
            if (entity.type === 'LINE') {
                updateBounds(entity.vertices[0].x, entity.vertices[0].y);
                updateBounds(entity.vertices[1].x, entity.vertices[1].y);
            } else if (entity.type === 'CIRCLE' || entity.type === 'ARC') {
                const r = entity.radius;
                updateBounds(entity.center.x - r, entity.center.y - r);
                updateBounds(entity.center.x + r, entity.center.y + r);
            } else if (entity.type === 'LWPOLYLINE' || entity.type === 'POLYLINE') {
                entity.vertices.forEach(v => updateBounds(v.x, v.y));
            }
        });

        const width = maxX - minX;
        const height = maxY - minY;
        const padding = 50;
        if (!isFinite(width) || !isFinite(height) || width <= 0 || height <= 0) {
            alert('File DXF tidak memiliki koordinat yang dapat digambar');
            return;
        }
        // Units DXF bisa berupa meter UTM; batasi agar kanvas tidak melewat
        // batas ukuran maksimum browser (±32.767 px) yang membuat render kosong.
        const skalaMuat = Math.min(1, 3900 / Math.max(width, height));
        canvas.width = Math.round(width * skalaMuat + padding * 2);
        canvas.height = Math.round(height * skalaMuat + padding * 2);

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = 'white';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        const scale = Math.min((canvas.width - padding * 2) / width, (canvas.height - padding * 2) / height);
        const offsetX = (canvas.width - width * scale) / 2;
        const offsetY = (canvas.height - height * scale) / 2;

        function transform(x, y) {
            return {
                x: (x - minX) * scale + offsetX,
                y: canvas.height - ((y - minY) * scale + offsetY)
            };
        }

        ctx.strokeStyle = '#333';
        ctx.lineWidth = 1;

        dxfData.entities.forEach(entity => {
            if (entity.type === 'LINE') {
                const p1 = transform(entity.vertices[0].x, entity.vertices[0].y);
                const p2 = transform(entity.vertices[1].x, entity.vertices[1].y);
                ctx.beginPath();
                ctx.moveTo(p1.x, p1.y);
                ctx.lineTo(p2.x, p2.y);
                ctx.stroke();
            } else if (entity.type === 'CIRCLE') {
                const center = transform(entity.center.x, entity.center.y);
                const radius = entity.radius * scale;
                ctx.beginPath();
                ctx.arc(center.x, center.y, radius, 0, 2 * Math.PI);
                ctx.stroke();
            } else if (entity.type === 'ARC') {
                const center = transform(entity.center.x, entity.center.y);
                const radius = entity.radius * scale;
                const startAngle = (entity.startAngle || 0) * Math.PI / 180;
                const endAngle = (entity.endAngle || 360) * Math.PI / 180;
                ctx.beginPath();
                ctx.arc(center.x, center.y, radius, -endAngle, -startAngle, true);
                ctx.stroke();
            } else if (entity.type === 'LWPOLYLINE' || entity.type === 'POLYLINE') {
                if (entity.vertices.length > 0) {
                    ctx.beginPath();
                    const first = transform(entity.vertices[0].x, entity.vertices[0].y);
                    ctx.moveTo(first.x, first.y);
                    for (let i = 1; i < entity.vertices.length; i++) {
                        const p = transform(entity.vertices[i].x, entity.vertices[i].y);
                        ctx.lineTo(p.x, p.y);
                    }
                    if (entity.shape) ctx.closePath();
                    ctx.stroke();
                }
            }
        });

        gambar = { isDxf: true, data: dxfData };
    }

    // Terapkan batas
    document.getElementById('btn-terapkan-batas').addEventListener('click', function() {
        batas = {
            latMin: parseFloat(document.getElementById('lat-min').value),
            latMax: parseFloat(document.getElementById('lat-max').value),
            lonMin: parseFloat(document.getElementById('lon-min').value),
            lonMax: parseFloat(document.getElementById('lon-max').value)
        };
        alert('Batas diterapkan. Silakan klik pada gambar untuk menambah titik polygon.');
    });

    // Klik canvas untuk tambah titik
    canvas.addEventListener('click', function(e) {
        if (!batas) {
            alert('Terapkan batas koordinat terlebih dahulu.');
            return;
        }

        const rect = canvas.getBoundingClientRect();
        const x = ((e.clientX - rect.left) / zoomLevel) - (panX / zoomLevel);
        const y = ((e.clientY - rect.top) / zoomLevel) - (panY / zoomLevel);

        // Konversi pixel ke koordinat
        const lon = batas.lonMin + (x / canvas.width) * (batas.lonMax - batas.lonMin);
        const lat = batas.latMax - (y / canvas.height) * (batas.latMax - batas.latMin);

        titik.push({ x, y, lat, lon });
        gambarUlang();
        updateInfo();
    });

    // Klik kanan untuk undo
    canvas.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        if (titik.length > 0) {
            titik.pop();
            gambarUlang();
            updateInfo();
        }
    });

    // Undo button
    document.getElementById('btn-undo').addEventListener('click', function() {
        if (titik.length > 0) {
            titik.pop();
            gambarUlang();
            updateInfo();
        }
    });

    // Hapus semua
    document.getElementById('btn-hapus-semua').addEventListener('click', function() {
        if (confirm('Hapus semua titik?')) {
            titik = [];
            gambarUlang();
            updateInfo();
        }
    });

    function gambarUlang() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        if (!gambar) {
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        } else if (gambar.isDxf) {
            // Re-render DXF
            renderDxf(gambar.data);
        } else {
            // Render image
            ctx.drawImage(gambar, 0, 0);
        }

        if (titik.length === 0) return;

        // Gambar polygon
        ctx.beginPath();
        ctx.moveTo(titik[0].x, titik[0].y);
        for (let i = 1; i < titik.length; i++) {
            ctx.lineTo(titik[i].x, titik[i].y);
        }
        if (titik.length > 2) {
            ctx.closePath();
            ctx.fillStyle = 'rgba(76, 175, 80, 0.3)';
            ctx.fill();
        }
        ctx.strokeStyle = '#4CAF50';
        ctx.lineWidth = 2;
        ctx.stroke();

        // Gambar titik
        titik.forEach((t, i) => {
            ctx.beginPath();
            ctx.arc(t.x, t.y, 5, 0, Math.PI * 2);
            ctx.fillStyle = '#f44336';
            ctx.fill();
            ctx.strokeStyle = '#fff';
            ctx.lineWidth = 2;
            ctx.stroke();

            // Nomor titik
            ctx.fillStyle = '#fff';
            ctx.font = 'bold 12px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(i + 1, t.x, t.y);
        });
    }

    function updateInfo() {
        document.getElementById('info-titik').textContent = titik.length + ' titik';

        const daftar = document.getElementById('daftar-titik');
        daftar.innerHTML = '';
        titik.forEach((t, i) => {
            const div = document.createElement('div');
            div.textContent = `Titik ${i + 1}: (${t.lat.toFixed(7)}, ${t.lon.toFixed(7)})`;
            daftar.appendChild(div);
        });

        // Update GeoJSON
        if (titik.length > 2) {
            const coords = titik.map(t => [t.lon, t.lat]);
            coords.push(coords[0]); // tutup polygon
            const geojson = {
                type: 'Polygon',
                coordinates: [coords]
            };
            document.getElementById('geojson-polygon').value = JSON.stringify(geojson);
        } else {
            document.getElementById('geojson-polygon').value = '';
        }
    }

    // Simpan bidang
    document.getElementById('form-simpan').addEventListener('submit', function(e) {
        e.preventDefault();

        if (titik.length < 3) {
            alert('Minimal 3 titik untuk membuat polygon.');
            return;
        }

        const formData = new FormData(this);

        fetch('api/digitasi.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.sukses) {
                alert('Bidang berhasil disimpan: ' + data.kode);
                window.location.reload();
            } else {
                alert('Gagal: ' + (data.pesan || 'Unknown error'));
            }
        })
        .catch(err => {
            alert('Error: ' + err.message);
        });
    });

    if (dataPratinjau && new URLSearchParams(location.search).get('dari') === 'pratinjau') {
        muatPratinjau();
    }
})();
</script>

<?php sip_footer(['leaflet' => true]); ?>
