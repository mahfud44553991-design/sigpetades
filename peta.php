<?php
declare(strict_types=1);

/**
 * Peta GIS Desa Gedong. Geometri dibaca dari MariaDB lewat api/bidang.php,
 * bukan dari berkas GeoJSON mentah, supaya ukuran halaman tetap kecil berapa
 * pun jumlah bidang.
 */

require __DIR__ . '/app/bootstrap.php';

$pengguna = wajib_masuk();
$kelola = boleh_kelola();

$blok = db_all('SELECT kode, nomor FROM blok ORDER BY nomor, kode');
$dusun = daftar_dusun();
$hak = daftar_jenis_hak();

sip_header('Peta pertanahan', 'peta', ['leaflet' => true]);
sip_judul(
    'Pemetaan',
    'Peta bidang tanah desa',
    'Klik bidang untuk melihat pemilik, jenis hak, dan status PBB-nya. '
        . 'Bidang bertanda "perlu verifikasi" berasal dari digitasi yang belum dikonfirmasi lapangan.'
);
?>

<div class="grid grid-sidebar">
    <div>
        <section class="kartu">
            <header class="kepala-kartu">
                <div>
                    <h2>Lapisan peta</h2>
                    <p>Sumber: <?= e(angka((int) db_value("SELECT COUNT(*) FROM bidang WHERE status NOT IN ('dihapus', 'duplikat')"))) ?> bidang &middot;
                        <?= e(count($blok)) ?> blok</p>
                </div>
                <div class="baris-tombol">
                    <button class="tombol tombol-kecil" type="button" id="pasangkanSemua">Muat semua</button>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('bidang.php')) ?>">Daftar bidang</a>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('peta-lama.php')) ?>">Peta DXF lama</a>
                </div>
            </header>

            <div class="peta-wadah">
                <div id="peta" aria-label="Peta bidang tanah Desa Gedong"></div>
                <div class="lapisan-kontrol">
                    <label class="cek"><input type="checkbox" id="tampilBidang" checked> Bidang tanah</label>
                    <label class="cek"><input type="checkbox" id="tampilBlok"> Batas blok</label>
                    <label class="cek"><input type="checkbox" id="tampilBatas" checked> Batas desa</label>
                    <?php if ($kelola): ?>
                        <label class="cek"><input type="checkbox" id="tampilLaporan"> Titik laporan</label>
                    <?php endif; ?>
                </div>
            </div>

            <div class="legenda" id="legendaPeta"></div>
            <p class="petunjuk" id="infoMuat">Menyiapkan data peta…</p>
        </section>

        <section class="kartu">
            <header class="kepala-kartu">
                <div>
                    <h2>Bidang pada tampilan ini</h2>
                    <p>Klik nama bidang untuk memusatkan peta</p>
                </div>
                <span class="lencana lencana-abu" id="hitungTampil">0</span>
            </header>
            <div class="daftar-bidang-mini" id="daftarBidang">
                <p class="kosong">Belum ada bidang termuat.</p>
            </div>
        </section>
    </div>

    <aside>
        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Saring peta</h2></div></header>
            <form id="formSaring">
                <div class="lapangan">
                    <label for="cari">Cari pemegang, kode bidang, atau NOP</label>
                    <input id="cari" name="cari" type="search" placeholder="mis. SUMARNI atau HM-0001">
                </div>
                <div class="baris-form">
                    <div class="lapangan">
                        <label for="blok">Blok</label>
                        <select id="blok" name="blok">
                            <option value="">Semua blok</option>
                            <option value="__tanpa">Tanpa blok</option>
                            <?php foreach ($blok as $b): ?>
                                <option value="<?= e($b['kode']) ?>"><?= e($b['kode']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lapangan">
                        <label for="dusun">Dusun</label>
                        <select id="dusun" name="dusun">
                            <option value="">Semua dusun</option>
                            <?php foreach ($dusun as $d): ?>
                                <option value="<?= e($d['id']) ?>"><?= e($d['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="baris-form">
                    <div class="lapangan">
                        <label for="hak">Jenis hak</label>
                        <select id="hak" name="hak">
                            <option value="">Semua jenis</option>
                            <?php foreach ($hak as $h): ?>
                                <option value="<?= e($h['kode']) ?>"><?= e($h['kode'] . ' — ' . $h['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lapangan">
                        <label for="status">Status bidang</label>
                        <select id="status" name="status">
                            <option value="">Semua status</option>
                            <option value="aktif">Aktif</option>
                            <option value="perlu_verifikasi">Perlu verifikasi</option>
                            <option value="duplikat">Duplikat</option>
                        </select>
                    </div>
                </div>
                <div class="lapangan">
                    <label for="tipe">Jenis obyek</label>
                    <select id="tipe" name="tipe">
                        <option value="">Bidang tanah dan non-tanah</option>
                        <option value="tanah">Hanya bidang tanah</option>
                        <option value="lain">Hanya obyek non-tanah</option>
                    </select>
                </div>
                <div class="baris-tombol">
                    <button class="tombol tombol-utama" type="submit">Terapkan</button>
                    <button class="tombol" type="reset">Bersihkan</button>
                </div>
            </form>
        </section>

        <section class="kartu pengaturan-peta">
            <header class="kepala-kartu">
                <div><h2>Pengaturan peta</h2><p>Tersimpan di peramban ini</p></div>
                <button class="tombol tombol-kecil" type="button" id="aturUlangPengaturan">Atur ulang</button>
            </header>
            <div class="lapangan">
                <label for="petaDasar">Peta dasar</label>
                <select id="petaDasar">
                    <option value="jalan">Peta jalan (OpenStreetMap)</option>
                    <option value="satelit">Citra satelit (Esri)</option>
                </select>
            </div>
            <div class="lapangan">
                <label for="modeWarna">Warnai bidang menurut</label>
                <select id="modeWarna">
                    <option value="hak">Jenis hak</option>
                    <option value="pbb">Status PBB</option>
                    <option value="status">Status bidang</option>
                </select>
            </div>
            <div class="lapangan">
                <label for="kepekatan">Kepekatan isian bidang</label>
                <div class="kepekatan-baris">
                    <input id="kepekatan" type="range" min="0" max="60" step="1" value="28">
                    <output id="nilaiKepekatan" for="kepekatan">28%</output>
                </div>
            </div>
            <label class="cek jarak-atas"><input type="checkbox" id="labelBidang"> Tampilkan kode bidang</label>
            <div class="baris-tombol jarak-atas">
                <button class="tombol" type="button" id="simpanTampilan">Simpan tampilan peta</button>
            </div>
            <p class="petunjuk">Pusat dan zoom disimpan setelah tombol ditekan. Pengaturan tidak mengubah data desa.</p>
        </section>

        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Catatan pemakaian</h2></div></header>
            <p class="penjelasan">
                Peta hanya menampilkan <?= e(angka(6000)) ?> bidang pertama dari hasil saringan.
                Persempit dengan blok atau kata kunci bila jumlah bidang pada satu tampilan
                melebihi batas itu, atau buka halaman daftar bidang untuk melihat semua.
            </p>
            <p class="petunjuk">
                Digitasi batas bidang dilakukan di perangkat GIS lalu dimuat ulang melalui
                menu impor data; peta ini tidak untuk menggambar batas baru.
            </p>
        </section>
    </aside>
</div>

<?php
$naskah = <<<'JS'
<script>
(function () {
    'use strict';
    const KUNCI = 'sip.peta.pengaturan.v1';
    const BAWAAN = {
        dasar: 'jalan', warna: 'hak', kepekatan: 28, label: false,
        bidang: true, blok: false, batas: true, laporan: false, tampilan: null
    };
    const BATAS_LABEL = 800;

    function bacaSetelan() {
        let isi = {};
        try {
            const tersimpan = JSON.parse(localStorage.getItem(KUNCI) || 'null');
            if (tersimpan && typeof tersimpan === 'object') isi = tersimpan;
        } catch (e) { /* localStorage tidak tersedia */ }
        const s = Object.assign({}, BAWAAN, isi);
        if (s.dasar !== 'jalan' && s.dasar !== 'satelit') s.dasar = BAWAAN.dasar;
        if (s.warna !== 'hak' && s.warna !== 'pbb' && s.warna !== 'status') s.warna = BAWAAN.warna;
        s.kepekatan = Math.max(0, Math.min(60, Number(s.kepekatan) || 0));
        ['label', 'bidang', 'blok', 'batas', 'laporan'].forEach((k) => { s[k] = !!s[k]; });
        return s;
    }

    let setelan = bacaSetelan();

    function simpanSetelan() {
        try { localStorage.setItem(KUNCI, JSON.stringify(setelan)); } catch (e) { /* mode privat */ }
    }

    const peta = L.map('peta', { preferCanvas: true }).setView([SIP.peta.lat, SIP.peta.lon], SIP.peta.zoom);
    const grup = {
        bidang: L.layerGroup(), blok: L.layerGroup(), batas: L.layerGroup(), laporan: L.layerGroup()
    };

    const dasar = {
        jalan: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 20,
            attribution: '&copy; OpenStreetMap'
        }),
        satelit: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 20,
            attribution: 'Citra &copy; Esri'
        })
    };

    function pakaiDasar(nama) {
        Object.keys(dasar).forEach((k) => {
            if (peta.hasLayer(dasar[k])) peta.removeLayer(dasar[k]);
        });
        (dasar[nama] || dasar.jalan).addTo(peta);
    }

    const WARNA = {
        hijau: '#1f9d4d', biru: '#0f4c81', coklat: '#8a5a10',
        abu: '#9aa8a2', merah: '#b42318', kuning: '#c47f00', gelap: '#6b7280'
    };

    function warnaBidang(p) {
        if (p.status === 'perlu_verifikasi') return WARNA.merah;
        if (setelan.warna === 'pbb') {
            if (p.pbb === 'Lunas') return WARNA.hijau;
            if (p.pbb === 'Sebagian') return WARNA.kuning;
            if (p.pbb === 'Belum Bayar') return WARNA.merah;
            return WARNA.abu;
        }
        if (setelan.warna === 'status') {
            if (p.status === 'aktif') return WARNA.hijau;
            if (p.status === 'duplikat') return WARNA.gelap;
            return WARNA.abu;
        }
        if (p.hak_kode === 'HM') return WARNA.hijau;
        if (p.hak_kode === 'HP' || p.hak_kode === 'HGB' || p.hak_kode === 'HGU') return WARNA.biru;
        if (p.hak_kode === 'KDH' || p.hak_kode === 'BCD') return WARNA.coklat;
        return WARNA.abu;
    }

    function gayaBidang(p) {
        const w = warnaBidang(p);
        const isi = setelan.kepekatan / 100;
        return {
            color: w, weight: 1, opacity: .85,
            fillColor: w, fillOpacity: p.tipe === 'tanah' ? isi : isi * .35,
            dashArray: p.status === 'perlu_verifikasi' ? '4 3' : null
        };
    }

    const LEGENDA = {
        hak: [[WARNA.hijau, 'Hak Milik'], [WARNA.biru, 'Hak Pakai / HGB / HGU'],
            [WARNA.coklat, 'Milik desa / Buku C'], [WARNA.abu, 'Belum tercatat'],
            [WARNA.merah, 'Perlu verifikasi']],
        pbb: [[WARNA.hijau, 'PBB lunas'], [WARNA.kuning, 'PBB sebagian'],
            [WARNA.merah, 'PBB belum bayar'], [WARNA.abu, 'Tanpa SPPT']],
        status: [[WARNA.hijau, 'Aktif'], [WARNA.merah, 'Perlu verifikasi'],
            [WARNA.gelap, 'Duplikat'], [WARNA.abu, 'Lainnya']]
    };

    const daftar = document.getElementById('daftarBidang');
    const info = document.getElementById('infoMuat');
    const hitung = document.getElementById('hitungTampil');
    let fiturTerakhir = [];
    let sudahMemusat = false;

    function gambarLegenda() {
        const isi = LEGENDA[setelan.warna] || LEGENDA.hak;
        document.getElementById('legendaPeta').innerHTML = isi.map(([w, t]) =>
            '<span><i style="background:' + w + '"></i>' + Sip.esc(t) + '</span>'
        ).join('') + '<span><i class="contoh-batas-desa"></i>Batas desa (010500)</span>';
    }

    // L.LayerGroup tidak punya getBounds(), jadi batas dihitung dari lapisan anak.
    function batasGrup(g) {
        const b = L.latLngBounds([]);
        g.eachLayer((l) => {
            if (l.getBounds) { b.extend(l.getBounds()); }
            else if (l.getLatLng) { b.extend(l.getLatLng()); }
        });
        return b;
    }

    function ringkas(t) {
        return t && t.length > 60 ? t.slice(0, 60) + '…' : (t || '');
    }

    function teksKandidat(k) {
        if (!k) return '';
        const bagian = [];
        if (k.tepi && k.tepi.length) {
            bagian.push('Tepi: ' + k.tepi.map((s) => Sip.esc(s)).join(', '));
        }
        if (k.terdekat) {
            bagian.push('Terdekat: ' + Sip.esc(k.terdekat.kode)
                + ' (' + Sip.angka(k.terdekat.jarak_m, 1) + ' m)');
        }
        return bagian.length ? '<p class="petunjuk">Blok kandidat — ' + bagian.join(' &middot; ') + '</p>' : '';
    }

    function isianSaring() {
        const f = document.getElementById('formSaring');
        const blok = f.blok.value;
        return {
            cari: f.cari.value.trim(),
            blok: blok === '__tanpa' ? '' : blok,
            tanpa_blok: blok === '__tanpa' ? 1 : '',
            dusun: f.dusun.value,
            hak: f.hak.value,
            status: f.status.value,
            tipe: f.tipe.value
        };
    }

    function gambarDaftar(features) {
        if (!features.length) {
            daftar.innerHTML = '<p class="kosong">Tidak ada bidang yang cocok dengan saringan ini.</p>';
            return;
        }
        const baris = features.slice(0, 400).map((f) => {
            const p = f.properties;
            return '<button type="button" data-lompat="' + p.id + '">'
                + '<strong>' + Sip.esc(p.kode) + '</strong>'
                + '<span class="redup"> ' + Sip.esc(ringkas(p.pemegang || p.hak_nama || 'tanpa data')) + '</span>'
                + '<span class="rapek-kanan">' + (p.luas ? Sip.angka(p.luas) + ' m²' : '—') + '</span>'
                + '</button>';
        }).join('');
        daftar.innerHTML = baris
            + (features.length > 400 ? '<p class="petunjuk">Hanya 400 pertama yang didaftarkan.</p>' : '');
    }

    function pasangLabel() {
        if (setelan.label && fiturTerakhir.length > BATAS_LABEL) {
            setelan.label = false;
            simpanSetelan();
            document.getElementById('labelBidang').checked = false;
            Sip.pesan('Label kode dimatikan karena tampilan memuat lebih dari '
                + Sip.angka(BATAS_LABEL) + ' bidang. Persempit saringan untuk memakainya.', 'info');
        }
        grup.bidang.eachLayer((l) => {
            if (!l.feature) return;
            l.unbindTooltip();
            if (setelan.label) {
                l.bindTooltip(l.feature.properties.kode, {
                    permanent: true, direction: 'center', className: 'label-bidang', opacity: 1
                });
                l.openTooltip();
            }
        });
    }

    function gambarGaya() {
        grup.bidang.eachLayer((l) => {
            if (l.feature) l.setStyle(gayaBidang(l.feature.properties));
        });
    }

    async function muatBidang(pusatkan = false) {
        info.textContent = 'Mengambil geometri bidang…';
        const data = await Sip.api('api/bidang.php', { query: Object.assign({ lapisan: 'bidang', maks: 6000 }, isianSaring()) });
        grup.bidang.clearLayers();
        fiturTerakhir = data.features || [];

        fiturTerakhir.forEach((f) => {
            L.geoJSON(f, { style: gayaBidang }).eachLayer((l) => {
                const p = f.properties;
                l.bindPopup(
                    '<div class="popup-bidang"><strong>' + Sip.esc(p.kode) + '</strong>'
                    + '<p>' + Sip.esc(p.hak_nama || 'Jenis hak belum tercatat') + '</p>'
                    + '<p>Pemegang: ' + Sip.esc(p.pemegang || 'belum tercatat') + '</p>'
                    + (p.nis ? '<p>NIS alas hak: ' + Sip.esc(p.nis) + '</p>' : '')
                    + (p.nomor_daftar ? '<p>Nomor daftar 080201: ' + Sip.esc(p.nomor_daftar) + '</p>' : '')
                    + '<p>Luas: ' + (p.luas ? Sip.angka(p.luas) + ' m²' : 'belum dihitung') + '</p>'
                    + '<p>Blok: ' + Sip.esc(p.blok || '—') + ' &middot; Dusun: ' + Sip.esc(p.dusun || '—') + '</p>'
                    + teksKandidat(p.blok_kandidat)
                    + (p.nop ? '<p>NOP: ' + Sip.esc(p.nop) + ' (' + Sip.esc(p.pbb || 'tanpa SPPT') + ')</p>' : '')
                    + '<p><a href="' + Sip.url('bidang.php', { id: p.id }) + '">Buka detail bidang</a></p></div>'
                );
                l.on('click', () => {
                    peta.flyToBounds(l.getBounds().pad(.4), { duration: .4 });
                    daftar.querySelectorAll('button').forEach((b) => {
                        b.classList.toggle('aktif', Number(b.dataset.lompat) === p.id);
                    });
                });
                l.addTo(grup.bidang);
            });
        });

        hitung.textContent = Sip.angka(fiturTerakhir.length) + ' bidang';
        gambarDaftar(fiturTerakhir);
        pasangLabel();

        // Pusat peta di setelan hanya titik awal; tampilan pertama selalu
        // disesuaikan dengan sebaran bidang yang benar-benar dimuat. Menerapkan
        // saringan juga memusatkan ulang, sebab blok seperti BLOK 14 isinya
        // bidang-bidang kecil yang hilang bila peta masih seukuran seluruh desa.
        if ((pusatkan || !sudahMemusat) && fiturTerakhir.length) {
            const batas = batasGrup(grup.bidang);
            if (batas.isValid()) {
                sudahMemusat = true;
                peta.fitBounds(batas.pad(.05));
            }
        }

        info.textContent = fiturTerakhir.length
            ? (data.terpotong
                ? 'Ditampilkan ' + Sip.angka(fiturTerakhir.length) + ' bidang (terpotong pada batas muat). Persempit saringan untuk melihat sisanya.'
                : 'Ditampilkan ' + Sip.angka(fiturTerakhir.length) + ' bidang.')
            : 'Tidak ada bidang pada saringan ini.';
    }

    async function muatBlok() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'blok' } });
        grup.blok.clearLayers();
        (data.features || []).forEach((f) => {
            L.geoJSON(f, {
                style: { color: '#14603a', weight: 2, dashArray: '6 4', fillOpacity: 0 }
            }).bindTooltip(f.properties.kode, { sticky: true }).addTo(grup.blok);
        });
    }

    async function muatBatas() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'batas' } });
        grup.batas.clearLayers();
        (data.features || []).forEach((f) => {
            L.geoJSON(f, {
                style: { color: '#1f4e79', weight: 2, dashArray: '8 5', fillOpacity: 0 },
                interactive: false
            }).addTo(grup.batas);
        });
    }

    async function muatLaporan() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'laporan' } });
        grup.laporan.clearLayers();
        (data.features || []).forEach((f) => {
            const p = f.properties;
            L.circleMarker(f.geometry.coordinates.reverse(), {
                radius: 7, color: '#b42318', fillColor: '#ffd9d3', fillOpacity: .9, weight: 2
            }).bindPopup(
                '<strong>' + Sip.esc(p.nomor) + '</strong><p>' + Sip.esc(p.kategori) + ' — ' + Sip.esc(p.status) + '</p>'
                + '<p>' + Sip.esc(ringkas(p.keterangan)) + '</p>'
                + '<p><a href="' + Sip.url('laporan.php') + '">Kelola laporan</a></p>'
            ).addTo(grup.laporan);
        });
    }

    function aktifkan(nama, hidup) {
        if (hidup) { if (!peta.hasLayer(grup[nama])) grup[nama].addTo(peta); }
        else peta.removeLayer(grup[nama]);
    }

    function terapkanSetelan() {
        document.getElementById('petaDasar').value = setelan.dasar;
        document.getElementById('modeWarna').value = setelan.warna;
        const slider = document.getElementById('kepekatan');
        slider.value = setelan.kepekatan;
        document.getElementById('nilaiKepekatan').textContent = setelan.kepekatan + '%';
        document.getElementById('labelBidang').checked = setelan.label;
        document.getElementById('tampilBidang').checked = setelan.bidang;
        document.getElementById('tampilBlok').checked = setelan.blok;
        document.getElementById('tampilBatas').checked = setelan.batas;
        const kotakLaporan = document.getElementById('tampilLaporan');
        if (kotakLaporan) kotakLaporan.checked = setelan.laporan;

        pakaiDasar(setelan.dasar);
        gambarLegenda();
        aktifkan('bidang', setelan.bidang);
        aktifkan('blok', setelan.blok);
        aktifkan('batas', setelan.batas);
        aktifkan('laporan', setelan.laporan);
        if (setelan.blok && !grup.blok.getLayers().length) {
            muatBlok().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
        if (setelan.batas && !grup.batas.getLayers().length) {
            muatBatas().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
        if (kotakLaporan && setelan.laporan && !grup.laporan.getLayers().length) {
            muatLaporan().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
    }

    document.getElementById('tampilBidang').addEventListener('change', (e) => {
        setelan.bidang = e.target.checked;
        simpanSetelan();
        aktifkan('bidang', setelan.bidang);
    });
    document.getElementById('tampilBlok').addEventListener('change', (e) => {
        setelan.blok = e.target.checked;
        simpanSetelan();
        aktifkan('blok', setelan.blok);
        if (setelan.blok && !grup.blok.getLayers().length) {
            muatBlok().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
    });
    document.getElementById('tampilBatas').addEventListener('change', (e) => {
        setelan.batas = e.target.checked;
        simpanSetelan();
        aktifkan('batas', setelan.batas);
        if (setelan.batas && !grup.batas.getLayers().length) {
            muatBatas().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
    });
    const kotakLaporan = document.getElementById('tampilLaporan');
    if (kotakLaporan) {
        kotakLaporan.addEventListener('change', (e) => {
            setelan.laporan = e.target.checked;
            simpanSetelan();
            aktifkan('laporan', setelan.laporan);
            if (setelan.laporan && !grup.laporan.getLayers().length) {
                muatLaporan().catch((err) => Sip.pesan(err.message, 'gagal'));
            }
        });
    }

    document.getElementById('petaDasar').addEventListener('change', (e) => {
        setelan.dasar = e.target.value;
        simpanSetelan();
        pakaiDasar(setelan.dasar);
    });
    document.getElementById('modeWarna').addEventListener('change', (e) => {
        setelan.warna = e.target.value;
        simpanSetelan();
        gambarLegenda();
        gambarGaya();
    });

    const sliderKepekatan = document.getElementById('kepekatan');
    let rafGaya = 0;
    sliderKepekatan.addEventListener('input', () => {
        setelan.kepekatan = Number(sliderKepekatan.value);
        document.getElementById('nilaiKepekatan').textContent = setelan.kepekatan + '%';
        if (!rafGaya) {
            rafGaya = requestAnimationFrame(() => { rafGaya = 0; gambarGaya(); });
        }
    });
    sliderKepekatan.addEventListener('change', simpanSetelan);

    document.getElementById('labelBidang').addEventListener('change', (e) => {
        if (e.target.checked && fiturTerakhir.length > BATAS_LABEL) {
            e.target.checked = false;
            Sip.pesan('Tampilan memuat ' + Sip.angka(fiturTerakhir.length)
                + ' bidang. Persempit saringan sebelum menampilkan label kode.', 'info');
            return;
        }
        setelan.label = e.target.checked;
        simpanSetelan();
        pasangLabel();
    });

    document.getElementById('simpanTampilan').addEventListener('click', () => {
        const pusat = peta.getCenter();
        setelan.tampilan = {
            lat: Number(pusat.lat.toFixed(6)),
            lon: Number(pusat.lng.toFixed(6)),
            zoom: peta.getZoom()
        };
        simpanSetelan();
        Sip.pesan('Tampilan peta disimpan di peramban ini.');
    });

    document.getElementById('aturUlangPengaturan').addEventListener('click', () => {
        setelan = Object.assign({}, BAWAAN);
        simpanSetelan();
        terapkanSetelan();
        pasangLabel();
        sudahMemusat = false;
        muatBidang(true).catch((err) => {
            Sip.pesan(err.message, 'gagal');
            info.textContent = 'Data bidang gagal dimuat.';
        });
    });

    daftar.addEventListener('click', (e) => {
        const tombol = e.target.closest('[data-lompat]');
        if (!tombol) return;
        const id = Number(tombol.dataset.lompat);
        const fitur = fiturTerakhir.find((f) => f.properties.id === id);
        if (!fitur) return;
        const batas = L.geoJSON(fitur).getBounds();
        peta.flyToBounds(batas.pad(.6), { duration: .5 });
    });

    const form = document.getElementById('formSaring');
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        muatBidang(true).catch((err) => { Sip.pesan(err.message, 'gagal'); info.textContent = 'Data bidang gagal dimuat.'; });
    });
    form.addEventListener('reset', () => setTimeout(() => muatBidang(true), 0));

    document.getElementById('pasangkanSemua').addEventListener('click', () => {
        const batas = batasGrup(grup.bidang);
        peta.flyToBounds(batas.isValid() ? batas.pad(.05) : peta.getBounds(), { duration: .5 });
    });

    if (setelan.tampilan && typeof setelan.tampilan.lat === 'number' && typeof setelan.tampilan.lon === 'number') {
        peta.setView([setelan.tampilan.lat, setelan.tampilan.lon], setelan.tampilan.zoom || SIP.peta.zoom);
        sudahMemusat = true;
    }
    terapkanSetelan();

    muatBidang().catch((err) => {
        Sip.pesan(err.message, 'gagal');
        info.textContent = 'Data bidang gagal dimuat.';
    });
})();
</script>
JS;

sip_footer(['leaflet' => true, 'kaki' => $naskah]);
