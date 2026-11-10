<?php
declare(strict_types=1);

/**
 * Peta GIS Desa Gedong. Geometri dibaca dari MariaDB lewat api/bidang.php,
 * bukan dari berkas GeoJSON mentah, supaya ukuran halaman tetap kecil berapa
 * pun jumlah bidang.
 *
 * Tata letak peta: bidang tanah dan obyek non-tanah adalah dua lapisan
 * terpisah. Obyek non-tanah (3.341 dari 4.396 geometri) dimatikan secara
 * bawaan supaya persil tanah tidak tenggelam di antara jalan, saluran, dan
 * anotasi. Warna bawaan mengikuti blok agar batas antarblok terbaca.
 */

require __DIR__ . '/app/bootstrap.php';

$pengguna = wajib_masuk();
$kelola = boleh_kelola();

$blok = db_all('SELECT kode, nomor FROM blok ORDER BY nomor, kode');
$dusun = daftar_dusun();
$hak = daftar_jenis_hak();

$ringkas = db_one(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(tipe = 'tanah'), 0) AS tanah
     FROM bidang WHERE status NOT IN ('dihapus', 'duplikat')"
) ?? ['total' => 0, 'tanah' => 0];
$jmlLaporan = $kelola ? (int) db_value('SELECT COUNT(*) FROM laporan') : 0;

sip_header('Peta pertanahan', 'peta', ['leaflet' => true]);
sip_judul(
    'Pemetaan',
    'Peta bidang tanah desa',
    'Klik bidang untuk melihat pemilik, jenis hak, dan status PBB-nya. '
        . 'Warna bawaan mengikuti blok peta.'
);
?>

<div class="grid grid-sidebar">
    <div>
        <section class="kartu kartu-peta">
            <header class="kepala-kartu">
                <div>
                    <h2>Lapisan peta</h2>
                    <p>Sumber: <?= e(angka((int) $ringkas['tanah'])) ?> bidang tanah &middot;
                        <?= e(count($blok)) ?> blok</p>
                </div>
                <div class="baris-tombol">
                    <button class="tombol tombol-kecil" type="button" id="pasangkanSemua">Seluruh desa</button>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('peta-blok.php')) ?>">Peta blok</a>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('bidang.php')) ?>">Daftar bidang</a>
                    <a class="tombol tombol-kecil" href="<?= e(sip_url('peta-lama.php')) ?>">Peta DXF lama</a>
                </div>
            </header>

            <div class="lapisan-peta" role="group" aria-label="Lapisan peta">
                <label class="chip-lapisan">
                    <input type="checkbox" id="tampilBidang" autocomplete="off" checked>
                    <i style="background:#1f9d4d"></i>Bidang tanah
                    <b><?= e(angka((int) $ringkas['tanah'])) ?></b>
                </label>
                <label class="chip-lapisan">
                    <input type="checkbox" id="tampilLain" autocomplete="off">
                    <i style="background:#8a7a66"></i>Obyek non-tanah
                    <b><?= e(angka((int) ($ringkas['total'] - $ringkas['tanah']))) ?></b>
                </label>
                <label class="chip-lapisan">
                    <input type="checkbox" id="tampilBlok" autocomplete="off" checked>
                    <i style="background:#14603a"></i>Batas blok
                    <b><?= e(angka(count($blok))) ?></b>
                </label>
                <label class="chip-lapisan">
                    <input type="checkbox" id="tampilJalan" autocomplete="off" checked>
                    <i style="background:#555"></i>Jalan
                </label>
                <label class="chip-lapisan">
                    <input type="checkbox" id="tampilSungai" autocomplete="off" checked>
                    <i style="background:#1e88e5"></i>Sungai
                </label>
                <?php if ($kelola): ?>
                    <label class="chip-lapisan">
                        <input type="checkbox" id="tampilLaporan" autocomplete="off">
                        <i style="background:#b42318"></i>Titik laporan
                        <b><?= e(angka($jmlLaporan)) ?></b>
                    </label>
                <?php endif; ?>
            </div>

            <div class="peta-wadah">
                <div id="peta" aria-label="Peta bidang tanah Desa Gedong"></div>
            </div>

            <div class="legenda legenda-peta" id="legendaPeta"></div>
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
                <p class="petunjuk">Lapisan mana yang dimuat diatur pada tombol di atas peta.</p>
                <div class="baris-tombol">
                    <button class="tombol tombol-utama" type="submit">Terapkan</button>
                    <button class="tombol" type="reset">Bersihkan</button>
                </div>
            </form>
        </section>

        <section class="kartu pengaturan-peta">
            <header class="kepala-kartu">
                <div><h2>Tampilan peta</h2><p>Tersimpan di peramban ini</p></div>
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
                    <option value="blok">Blok peta (warna per blok)</option>
                    <option value="tipe">Jenis obyek (tanah / non-tanah)</option>
                    <option value="hak">Jenis hak</option>
                    <option value="pbb">Status PBB</option>
                    <option value="status">Status bidang</option>
                </select>
            </div>
            <div class="lapangan">
                <label for="kepekatan">Kepekatan isian bidang</label>
                <div class="kepekatan-baris">
                    <input id="kepekatan" type="range" min="45" max="80" step="1" value="55">
                    <output id="nilaiKepekatan" for="kepekatan">55%</output>
                </div>
                <p class="petunjuk">Warna bidang tetap terlihat pada tingkat kepekatan minimum 45%.</p>
            </div>
            <label class="cek jarak-atas"><input type="checkbox" id="labelBidang" autocomplete="off"> Tampilkan nama pemegang (kode jika belum tersedia)</label>
            <label class="cek"><input type="checkbox" id="labelBlok" autocomplete="off" checked> Tampilkan nama blok</label>
            <p class="petunjuk">Nama blok hanya tampil pada zoom dekat (14 ke atas) agar tidak bertumpuk.</p>
            <div class="baris-tombol jarak-atas">
                <button class="tombol" type="button" id="simpanTampilan">Simpan tampilan peta</button>
            </div>
            <p class="petunjuk">Pusat dan zoom disimpan setelah tombol ditekan. Pengaturan tidak mengubah data desa.</p>
            <?php if ($pengguna): ?>
            <hr style="margin: 1rem 0; border: none; border-top: 1px solid var(--garis);">
            <div class="lapangan">
                <label for="namaPreset">Nama preset</label>
                <input type="text" id="namaPreset" placeholder="Contoh: Tampilan blok 5" maxlength="100">
            </div>
            <div class="baris-tombol">
                <button class="tombol tombol-kecil" type="button" id="simpanPresetDb">Simpan ke database</button>
            </div>
            <div class="lapangan jarak-atas">
                <label for="pilihPreset">Preset tersimpan</label>
                <select id="pilihPreset">
                    <option value="">— pilih preset —</option>
                </select>
            </div>
            <div class="baris-tombol">
                <button class="tombol tombol-kecil" type="button" id="muatPreset">Muat</button>
                <button class="tombol tombol-kecil tombol-bahaya" type="button" id="hapusPreset">Hapus</button>
            </div>
            <p class="petunjuk">Preset database bisa dimuat dari browser mana saja setelah login.</p>
            <?php endif; ?>
        </section>

        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Catatan pemakaian</h2></div></header>
            <p class="penjelasan">
                Tiap lapisan memuat paling banyak <?= e(angka(6000)) ?> bidang dari hasil saringan.
                Persempit dengan blok atau kata kunci bila jumlahnya melebihi batas itu,
                atau buka halaman daftar bidang untuk melihat semua.
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
    const KUNCI = 'sip.peta.pengaturan.v2';
    const BAWAAN = {
        dasar: 'jalan', warna: 'blok', kepekatan: 55, label: false, labelBlok: true,
        bidang: true, lain: false, blok: true, batas: true, laporan: false,
        jalan: true, sungai: true, tampilan: null
    };
    const MODE = ['blok', 'tipe', 'hak', 'pbb', 'status'];
    const BATAS_LABEL = 1500;
    const ZOOM_LABEL = 14;

    function rapikanSetelan(isi) {
        const s = Object.assign({}, BAWAAN, isi || {});
        if (s.dasar !== 'jalan' && s.dasar !== 'satelit') s.dasar = BAWAAN.dasar;
        if (MODE.indexOf(s.warna) < 0) s.warna = BAWAAN.warna;
        s.kepekatan = Math.max(45, Math.min(80, Number(s.kepekatan) || 0));
        ['label', 'labelBlok', 'bidang', 'blok', 'batas', 'laporan', 'jalan', 'sungai'].forEach((k) => { s[k] = !!s[k]; });
        // Kunci peninggalan lapisan garis OSM yang sudah dihapus. Tanpa dibuang,
        // localStorage lama dan preset database ikut menyimpannya kembali.
        delete s.osmJalan;
        delete s.osmSungai;
        return s;
    }

    function bacaSetelan() {
        let isi = {};
        try {
            const tersimpan = JSON.parse(localStorage.getItem(KUNCI) || 'null');
            if (tersimpan && typeof tersimpan === 'object') isi = tersimpan;
        } catch (e) { /* localStorage tidak tersedia */ }
        return rapikanSetelan(isi);
    }

    let setelan = bacaSetelan();

    function simpanSetelan() {
        try { localStorage.setItem(KUNCI, JSON.stringify(setelan)); } catch (e) { /* mode privat */ }
    }

    const peta = L.map('peta').setView([SIP.peta.lat, SIP.peta.lon], SIP.peta.zoom);
    peta.attributionControl.setPrefix('');

    // Panes menentukan urutan gambar tanpa bergantung urutan addLayer:
    // batas desa paling bawah, lalu blok, jalan, sungai, obyek non-tanah, bidang tanah.
    const PANES = { batas: 405, blok: 410, jalan: 412, sungai: 413, lain: 415, tanah: 420, laporan: 430 };
    Object.keys(PANES).forEach((nama) => {
        const pane = peta.createPane('pane-' + nama);
        pane.style.zIndex = String(PANES[nama]);
    });
    peta.getPane('pane-batas').style.pointerEvents = 'none';
    peta.getPane('pane-laporan').style.pointerEvents = 'auto';

    const grup = {
        bidang: L.layerGroup(), lain: L.layerGroup(), blok: L.layerGroup(),
        batas: L.layerGroup(), laporan: L.layerGroup(),
        jalan: L.layerGroup(), sungai: L.layerGroup()
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
        abu: '#9aa8a2', merah: '#b42318', kuning: '#c47f00', gelap: '#6b7280',
        lain: '#8a7a66'
    };

    function nomorBlok(kode) {
        const cocok = String(kode || '').match(/(\d+)/);
        return cocok ? Number(cocok[1]) : 0;
    }

    // Sudut emas menyebar 25 blok ke seluruh roda warna tanpa dua blok
    // bersebelahan mendapat rona yang hampir sama.
    function warnaBlok(kode) {
        const nomor = nomorBlok(kode);
        if (!nomor) return WARNA.abu;
        return 'hsl(' + Math.round((nomor * 137.508) % 360) + ', 38%, 44%)';
    }

    function warnaBidang(p) {
        if (setelan.warna === 'blok') {
            return p.blok ? warnaBlok(p.blok) : WARNA.abu;
        }
        if (setelan.warna === 'tipe') {
            return p.tipe === 'tanah' ? WARNA.hijau : WARNA.lain;
        }
        if (setelan.warna === 'pbb') {
            if (p.pbb === 'Lunas') return WARNA.hijau;
            if (p.pbb === 'Sebagian') return WARNA.kuning;
            if (p.pbb === 'Belum Bayar') return WARNA.merah;
            return WARNA.abu;
        }
        if (setelan.warna === 'status') {
            if (p.status === 'aktif') return WARNA.hijau;
            if (p.status === 'perlu_verifikasi') return WARNA.kuning;
            if (p.status === 'duplikat') return WARNA.gelap;
            return WARNA.abu;
        }
        if (p.hak_kode === 'HM') return WARNA.hijau;
        if (p.hak_kode === 'HP' || p.hak_kode === 'HGB' || p.hak_kode === 'HGU') return WARNA.biru;
        if (p.hak_kode === 'KDH' || p.hak_kode === 'BCD') return WARNA.coklat;
        return WARNA.abu;
    }

    function gayaBidang(p) {
        const warna = warnaBidang(p);
        const isi = setelan.kepekatan / 100;
        const lain = p.tipe !== 'tanah';
        return {
            color: warna, weight: lain ? 0.7 : 1.1, opacity: lain ? .7 : .9,
            fillColor: warna, fillOpacity: isi,
            dashArray: lain ? '3 4' : null
        };
    }

    const daftar = document.getElementById('daftarBidang');
    const info = document.getElementById('infoMuat');
    const hitung = document.getElementById('hitungTampil');
    const formSaring = document.getElementById('formSaring');
    const dataLapisan = { tanah: null, lain: null };
    const filterLapisan = { tanah: null, lain: null };
    const tampil = new Map();
    let terpotong = false;
    let sudahMemusat = false;

    function fiturTampil() {
        const hasil = [];
        if (setelan.bidang && dataLapisan.tanah) hasil.push.apply(hasil, dataLapisan.tanah);
        if (setelan.lain && dataLapisan.lain) hasil.push.apply(hasil, dataLapisan.lain);
        return hasil;
    }

    function gambarLegenda() {
        const fitur = fiturTampil();
        const hitungDengan = (fn) => fitur.reduce((n, f) => n + (fn(f.properties) ? 1 : 0), 0);
        const butir = (warna, teks, jumlah, kelas) =>
            '<span><i class="' + (kelas || '') + '" style="background:' + warna + '"></i>'
            + Sip.esc(teks) + (jumlah === null ? '' : ' <b>' + Sip.angka(jumlah) + '</b>') + '</span>';
        const baris = [];

        if (setelan.warna === 'blok') {
            const perBlok = new Map();
            fitur.forEach((f) => {
                const kode = f.properties.blok || 'Tanpa blok';
                perBlok.set(kode, (perBlok.get(kode) || 0) + 1);
            });
            Array.from(perBlok.keys())
                .sort((a, b) => nomorBlok(a) - nomorBlok(b))
                .forEach((kode) => {
                    baris.push(butir(kode === 'Tanpa blok' ? WARNA.abu : warnaBlok(kode), kode, perBlok.get(kode)));
                });
        } else if (setelan.warna === 'tipe') {
            baris.push(butir(WARNA.hijau, 'Bidang tanah', hitungDengan((p) => p.tipe === 'tanah')));
        } else if (setelan.warna === 'pbb') {
            baris.push(butir(WARNA.hijau, 'PBB lunas', hitungDengan((p) => p.pbb === 'Lunas')));
            baris.push(butir(WARNA.kuning, 'PBB sebagian', hitungDengan((p) => p.pbb === 'Sebagian')));
            baris.push(butir(WARNA.merah, 'PBB belum bayar', hitungDengan((p) => p.pbb === 'Belum Bayar')));
            baris.push(butir(WARNA.abu, 'Tanpa SPPT', hitungDengan((p) => !p.pbb)));
            if (!fitur.some((f) => f.properties.pbb)) {
                baris.push('<span class="legenda-catatan">Belum ada NOP yang tertaut ke bidang; rekap PBB ada di halaman e-SPPT.</span>');
            }
        } else if (setelan.warna === 'status') {
            baris.push(butir(WARNA.hijau, 'Aktif', hitungDengan((p) => p.status === 'aktif')));
            baris.push(butir(WARNA.kuning, 'Perlu verifikasi', hitungDengan((p) => p.status === 'perlu_verifikasi')));
            baris.push(butir(WARNA.gelap, 'Duplikat', hitungDengan((p) => p.status === 'duplikat')));
            baris.push(butir(WARNA.abu, 'Lainnya', hitungDengan((p) => ['aktif', 'perlu_verifikasi', 'duplikat'].indexOf(p.status) < 0)));
        } else {
            baris.push(butir(WARNA.hijau, 'Hak Milik', hitungDengan((p) => p.hak_kode === 'HM')));
            baris.push(butir(WARNA.biru, 'Hak Pakai / HGB / HGU', hitungDengan((p) => ['HP', 'HGB', 'HGU'].indexOf(p.hak_kode) >= 0)));
            baris.push(butir(WARNA.coklat, 'Milik desa / Buku C', hitungDengan((p) => ['KDH', 'BCD'].indexOf(p.hak_kode) >= 0)));
            baris.push(butir(WARNA.abu, 'Belum tercatat', hitungDengan((p) => !p.hak_kode || ['HM', 'HP', 'HGB', 'HGU', 'KDH', 'BCD'].indexOf(p.hak_kode) < 0)));
        }

        if (setelan.blok) {
            baris.push('<span><i class="contoh-batas-blok"></i>Batas blok ukur</span>');
        }
        if (setelan.batas) {
            baris.push('<span><i class="contoh-batas-desa"></i>Batas desa</span>');
        }
        document.getElementById('legendaPeta').innerHTML = baris.join('');
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

    function batasTampil() {
        const b = L.latLngBounds([]);
        if (setelan.bidang && peta.hasLayer(grup.bidang)) b.extend(batasGrup(grup.bidang));
        if (setelan.lain && peta.hasLayer(grup.lain)) b.extend(batasGrup(grup.lain));
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

    const NAMA_STATUS = {
        aktif: 'Aktif', perlu_verifikasi: 'Perlu verifikasi',
        duplikat: 'Duplikat', dihapus: 'Dihapus'
    };

    function isiPopup(p) {
        const baris = [];
        const tambah = (k, v) => {
            const teks = v === null || v === undefined ? '' : String(v).trim();
            if (teks !== '') baris.push('<dt>' + Sip.esc(k) + '</dt><dd>' + Sip.esc(teks) + '</dd>');
        };
        tambah('Jenis hak', p.hak_nama || 'belum tercatat');
        tambah('Pemegang', p.pemegang || 'belum tercatat');
        tambah('Luas', p.luas ? Sip.angka(p.luas, p.luas % 1 ? 2 : 0) + ' m²' : 'belum dihitung');
        tambah('Status', NAMA_STATUS[p.status] || p.status);
        tambah('NIS alas hak', p.nis);
        tambah('NIB', p.nib);
        tambah('Nomor daftar 080201', p.nomor_daftar);
        tambah('Blok', p.blok || '—');
        tambah('Dusun', p.dusun || '—');
        if (p.nop) {
            tambah('NOP', p.nop);
            tambah('Status PBB', p.pbb || 'tanpa SPPT');
        }

        return '<div class="popup-peta-isi">'
            + '<p class="popup-peta-judul"><strong>' + Sip.esc(p.kode) + '</strong>'
            + '<span>' + Sip.esc(p.tipe === 'tanah' ? 'bidang tanah' : 'obyek non-tanah') + '</span></p>'
            + '<dl class="popup-isi">' + baris.join('') + '</dl>'
            + teksKandidat(p.blok_kandidat)
            + '<p class="popup-peta-kaki"><a href="' + Sip.url('bidang.php', { id: p.id }) + '">Buka detail bidang</a></p></div>';
    }

    function tandaAktif(id) {
        if (!daftar) return;
        daftar.querySelectorAll('button').forEach((b) => {
            b.classList.toggle('aktif', Number(b.dataset.lompat) === id);
        });
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
        if (setelan.label && fiturTampil().length > BATAS_LABEL) {
            setelan.label = false;
            simpanSetelan();
            document.getElementById('labelBidang').checked = false;
            Sip.pesan('Label bidang dimatikan karena tampilan memuat lebih dari '
                + Sip.angka(BATAS_LABEL) + ' bidang. Persempit saringan untuk memakainya.', 'info');
        }
        tampil.forEach((v) => {
            if (v.tipe !== 'tanah') return;
            v.lapisan.unbindTooltip();
            if (setelan.label) {
                v.lapisan.bindTooltip(v.fitur.properties.pemegang || v.fitur.properties.kode, {
                    permanent: true, direction: 'center', className: 'label-bidang', opacity: 1
                });
                v.lapisan.openTooltip();
            }
        });
    }

    function gambarGaya() {
        tampil.forEach((v) => v.lapisan.setStyle(gayaBidang(v.fitur.properties)));
    }

    function segarkanTampilan() {
        const fitur = fiturTampil();
        const tanah = fitur.filter((f) => f.properties.tipe === 'tanah').length;
        hitung.textContent = Sip.angka(tanah) + ' tanah · ' + Sip.angka(fitur.length - tanah) + ' obyek';
        gambarDaftar(fitur);
        pasangLabel();
        gambarLegenda();
    }

    function kunciFilter(tipe) {
        return JSON.stringify(Object.assign({ lapisan: 'bidang', maks: 6000 }, isianSaring(), { tipe }));
    }

    function isianSaring() {
        const f = formSaring;
        const blok = f.blok.value;
        return {
            cari: f.cari.value.trim(),
            blok: blok === '__tanpa' ? '' : blok,
            tanpa_blok: blok === '__tanpa' ? 1 : '',
            dusun: f.dusun.value,
            hak: f.hak.value,
            status: f.status.value
        };
    }

    function kosongkanLapisan(tipe) {
        const kunci = tipe === 'tanah' ? 'bidang' : 'lain';
        grup[kunci].clearLayers();
        Array.from(tampil.keys()).forEach((id) => {
            if (tampil.get(id).tipe === tipe) tampil.delete(id);
        });
    }

    function gambarLapisan(tipe) {
        kosongkanLapisan(tipe);
        const fitur = dataLapisan[tipe] || [];
        if (!fitur.length) return;
        const kunci = tipe === 'tanah' ? 'bidang' : 'lain';
        L.geoJSON({ type: 'FeatureCollection', features: fitur }, {
            pane: 'pane-' + (tipe === 'tanah' ? 'tanah' : 'lain'),
            style: gayaBidang,
            onEachFeature: (f, l) => {
                const p = f.properties;
                tampil.set(p.id, { lapisan: l, fitur: f, tipe });
                l.bindPopup(isiPopup(p), { maxWidth: 320, className: 'popup-peta' });
                l.on('click', () => {
                    const batas = l.getBounds();
                    if (batas && batas.isValid()) peta.flyToBounds(batas.pad(.4), { duration: .4 });
                    tandaAktif(p.id);
                });
                l.on('mouseover', () => {
                    l.setStyle({ weight: tipe === 'tanah' ? 2.6 : 1.8, opacity: 1 });
                    l.bringToFront();
                });
                l.on('mouseout', () => {
                    l.setStyle(gayaBidang(f.properties));
                });
            }
        }).addTo(grup[kunci]);
    }

    async function muatLapisan(tipe) {
        const kunci = kunciFilter(tipe);
        const data = await Sip.api('api/bidang.php', {
            query: Object.assign({ lapisan: 'bidang', maks: 6000 }, isianSaring(), { tipe })
        });
        dataLapisan[tipe] = data.features || [];
        filterLapisan[tipe] = kunci;
        if (data.terpotong) terpotong = true;
        gambarLapisan(tipe);
    }

    function pusatkanKe(batas) {
        if (batas.isValid()) peta.fitBounds(batas.pad(.05));
    }

    async function muatBidang(pusatkan = false) {
        info.textContent = 'Mengambil geometri bidang…';
        terpotong = false;
        const tugas = [];
        if (setelan.bidang) {
            tugas.push(muatLapisan('tanah'));
            aktifkan('bidang', true);
        } else aktifkan('bidang', false);
        if (setelan.lain) {
            tugas.push(muatLapisan('lain'));
            aktifkan('lain', true);
        } else aktifkan('lain', false);
        if (!tugas.length) {
            segarkanTampilan();
            info.textContent = 'Lapisan bidang dimatikan. Nyalakan di atas peta.';
            return;
        }
        await Promise.all(tugas);
        segarkanTampilan();

        const jumlah = fiturTampil().length;
        if ((pusatkan || !sudahMemusat) && jumlah) {
            const batas = batasTampil();
            if (batas.isValid()) {
                sudahMemusat = true;
                pusatkanKe(batas);
            }
        }

        info.textContent = jumlah
            ? 'Ditampilkan ' + Sip.angka(jumlah) + ' geometri'
                + (terpotong ? ' (terpotong pada batas muat; persempit saringan).' : '.')
            : 'Tidak ada bidang pada saringan ini.';
    }

    function gayaBlok(p) {
        return { color: '#14603a', weight: 2, opacity: .95, fillOpacity: 0 };
    }

    async function muatBlok() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'blok' } });
        grup.blok.clearLayers();
        (data.features || []).forEach((f) => {
            const geometri = L.geoJSON(f, { pane: 'pane-blok' });
            if (!f.properties.batas_perkiraan) {
                geometri.setStyle(gayaBlok(f.properties));
                geometri.bindTooltip(f.properties.kode + ' — batas ukur', { sticky: true })
                    .addTo(grup.blok);
            }

            const tengah = geometri.getBounds().getCenter();
            L.marker(tengah, {
                icon: L.divIcon({
                    className: 'label-blok',
                    html: '<span>' + Sip.esc(f.properties.kode) + '</span>',
                    iconSize: [0, 0]
                }),
                interactive: false, keyboard: false
            }).addTo(grup.blok);
        });
        rapikanLabelBlok();
    }

    async function muatBatas() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'batas' } });
        grup.batas.clearLayers();
        (data.features || []).forEach((f) => {
            L.geoJSON(f, {
                pane: 'pane-batas',
                style: { color: '#1f4e79', weight: 2.4, dashArray: '8 5', fillOpacity: 0 },
                interactive: false
            }).addTo(grup.batas);
        });
    }

    async function muatLaporan() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'laporan' } });
        grup.laporan.clearLayers();
        (data.features || []).forEach((f) => {
            const p = f.properties;
            const koordinat = f.geometry.coordinates;
            L.circleMarker([Number(koordinat[1]), Number(koordinat[0])], {
                pane: 'pane-laporan',
                radius: 7, color: '#b42318', fillColor: '#ffd9d3', fillOpacity: .9, weight: 2
            }).bindPopup(
                '<strong>' + Sip.esc(p.nomor) + '</strong><p>' + Sip.esc(p.kategori) + ' — ' + Sip.esc(p.status) + '</p>'
                + '<p>' + Sip.esc(ringkas(p.keterangan)) + '</p>'
                + '<p><a href="' + Sip.url('laporan.php') + '">Kelola laporan</a></p>'
            ).addTo(grup.laporan);
        });
    }

    async function muatJalan() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'jalan' } });
        grup.jalan.clearLayers();
        (data.features || []).forEach((f) => {
            const p = f.properties;
            const koordinat = f.geometry.coordinates;
            L.marker([Number(koordinat[1]), Number(koordinat[0])], {
                icon: L.divIcon({
                    className: 'label-jalan',
                    html: '<span>' + Sip.esc(p.label) + '</span>',
                    iconSize: [0, 0]
                }),
                pane: 'pane-jalan',
                interactive: false, keyboard: false
            }).addTo(grup.jalan);
        });
    }

    async function muatSungai() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'sungai' } });
        grup.sungai.clearLayers();
        (data.features || []).forEach((f) => {
            const p = f.properties;
            const koordinat = f.geometry.coordinates;
            L.marker([Number(koordinat[1]), Number(koordinat[0])], {
                icon: L.divIcon({
                    className: 'label-sungai',
                    html: '<span>' + Sip.esc(p.label) + '</span>',
                    iconSize: [0, 0]
                }),
                pane: 'pane-sungai',
                interactive: false, keyboard: false
            }).addTo(grup.sungai);
        });
    }

    function aktifkan(nama, hidup) {
        if (hidup) { if (!peta.hasLayer(grup[nama])) grup[nama].addTo(peta); }
        else peta.removeLayer(grup[nama]);
    }

    // Pada tampilan seluruh desa 25 nama blok saling bertumpuk di layar sempit.
    // Label yang bertabrakan disembunyikan; zoom lebih dekat memunculkannya lagi.
    function rapikanLabelBlok() {
        if (!setelan.labelBlok || peta.getZoom() < ZOOM_LABEL) return;
        const kotak = [];
        document.querySelectorAll('#peta .label-blok span').forEach((span) => {
            span.style.visibility = '';
            const r = span.getBoundingClientRect();
            if (!r.width || !r.height) return;
            if (kotak.some((k) => r.left < k.right && k.left < r.right && r.top < k.bottom && k.top < r.bottom)) {
                span.style.visibility = 'hidden';
                return;
            }
            kotak.push(r);
        });
    }

    function perbaruiZoom() {
        const wadah = peta.getContainer();
        wadah.classList.toggle('peta-dekat', peta.getZoom() >= ZOOM_LABEL);
        wadah.classList.toggle('tanpa-label-blok', !setelan.labelBlok);
        rapikanLabelBlok();
    }

    function terapkanSetelan() {
        document.getElementById('petaDasar').value = setelan.dasar;
        document.getElementById('modeWarna').value = setelan.warna;
        const slider = document.getElementById('kepekatan');
        slider.value = setelan.kepekatan;
        document.getElementById('nilaiKepekatan').textContent = setelan.kepekatan + '%';
        document.getElementById('labelBidang').checked = setelan.label;
        document.getElementById('labelBlok').checked = setelan.labelBlok;
        const kotakBidang = document.getElementById('tampilBidang');
        if (kotakBidang) kotakBidang.checked = setelan.bidang;
        const kotakLain = document.getElementById('tampilLain');
        if (kotakLain) kotakLain.checked = setelan.lain;
        const kotakBlok = document.getElementById('tampilBlok');
        if (kotakBlok) kotakBlok.checked = setelan.blok;
        const kotakLaporan = document.getElementById('tampilLaporan');
        if (kotakLaporan) kotakLaporan.checked = setelan.laporan;
        const kotakJalan = document.getElementById('tampilJalan');
        if (kotakJalan) kotakJalan.checked = setelan.jalan;
        const kotakSungai = document.getElementById('tampilSungai');
        if (kotakSungai) kotakSungai.checked = setelan.sungai;

        pakaiDasar(setelan.dasar);
        gambarLegenda();
        aktifkan('laporan', setelan.laporan);
        aktifkan('jalan', setelan.jalan);
        aktifkan('sungai', setelan.sungai);
        aktifkan('blok', setelan.blok);
        perbaruiZoom();
        if (kotakLaporan && setelan.laporan && !grup.laporan.getLayers().length) {
            muatLaporan().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
        if (kotakJalan && setelan.jalan && !grup.jalan.getLayers().length) {
            muatJalan().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
        if (kotakSungai && setelan.sungai && !grup.sungai.getLayers().length) {
            muatSungai().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
        if (kotakBlok && setelan.blok && !grup.blok.getLayers().length) {
            muatBlok().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
    }

    function gagalMuat(err) {
        Sip.pesan(err.message, 'gagal');
        info.textContent = 'Data bidang gagal dimuat.';
    }

    function segarkanTanpaMuat() {
        segarkanTampilan();
        const jumlah = fiturTampil().length;
        if (!setelan.bidang && !setelan.lain) {
            info.textContent = 'Kedua lapisan bidang dimatikan. Nyalakan salah satu di atas peta.';
        } else {
            info.textContent = jumlah
                ? 'Ditampilkan ' + Sip.angka(jumlah) + ' geometri' + (terpotong ? ' (terpotong).' : '.')
                : 'Tidak ada bidang pada saringan ini.';
        }
    }

    const kotakBidang = document.getElementById('tampilBidang');
    if (kotakBidang) {
        kotakBidang.addEventListener('change', (e) => {
            setelan.bidang = e.target.checked;
            simpanSetelan();
            aktifkan('bidang', setelan.bidang);
            if (setelan.bidang && filterLapisan.tanah !== kunciFilter('tanah')) {
                muatLapisan('tanah').catch(gagalMuat);
            } else {
                segarkanTanpaMuat();
            }
        });
    }

    const kotakLain = document.getElementById('tampilLain');
    if (kotakLain) {
        kotakLain.addEventListener('change', (e) => {
            setelan.lain = e.target.checked;
            simpanSetelan();
            aktifkan('lain', setelan.lain);
            if (setelan.lain && filterLapisan.lain !== kunciFilter('lain')) {
                muatLapisan('lain').catch(gagalMuat);
            } else {
                segarkanTanpaMuat();
            }
        });
    }

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

    const kotakBlok = document.getElementById('tampilBlok');
    if (kotakBlok) {
        kotakBlok.addEventListener('change', (e) => {
            setelan.blok = e.target.checked;
            simpanSetelan();
            aktifkan('blok', setelan.blok);
            if (setelan.blok && !grup.blok.getLayers().length) {
                muatBlok().catch((err) => Sip.pesan(err.message, 'gagal'));
            }
            gambarLegenda();
        });
    }

    const kotakJalan = document.getElementById('tampilJalan');
    if (kotakJalan) {
        kotakJalan.addEventListener('change', (e) => {
            setelan.jalan = e.target.checked;
            simpanSetelan();
            aktifkan('jalan', setelan.jalan);
            if (setelan.jalan && !grup.jalan.getLayers().length) {
                muatJalan().catch((err) => Sip.pesan(err.message, 'gagal'));
            }
        });
    }

    const kotakSungai = document.getElementById('tampilSungai');
    if (kotakSungai) {
        kotakSungai.addEventListener('change', (e) => {
            setelan.sungai = e.target.checked;
            simpanSetelan();
            aktifkan('sungai', setelan.sungai);
            if (setelan.sungai && !grup.sungai.getLayers().length) {
                muatSungai().catch((err) => Sip.pesan(err.message, 'gagal'));
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
        if (e.target.checked && fiturTampil().length > BATAS_LABEL) {
            e.target.checked = false;
            Sip.pesan('Tampilan memuat ' + Sip.angka(fiturTampil().length)
                + ' bidang. Persempit saringan sebelum menampilkan label bidang.', 'info');
            return;
        }
        setelan.label = e.target.checked;
        simpanSetelan();
        pasangLabel();
    });
    document.getElementById('labelBlok').addEventListener('change', (e) => {
        setelan.labelBlok = e.target.checked;
        simpanSetelan();
        perbaruiZoom();
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

    // Preset database (hanya untuk pengguna login)
    if (SIP.masuk) {
    const elNamaPreset = document.getElementById('namaPreset');
    const elPilihPreset = document.getElementById('pilihPreset');
    let daftarPreset = [];

    async function muatDaftarPreset() {
        try {
            const hasil = await Sip.api('api/preset.php');
            daftarPreset = hasil.preset || [];
            elPilihPreset.innerHTML = '<option value="">— pilih preset —</option>';
            daftarPreset.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.nama;
                elPilihPreset.appendChild(opt);
            });
        } catch (e) { /* abaikan galat */ }
    }

    document.getElementById('simpanPresetDb').addEventListener('click', async () => {
        const nama = elNamaPreset.value.trim();
        if (!nama) {
            Sip.pesan('Nama preset wajib diisi.', 'gagal');
            return;
        }
        const data = rapikanSetelan(setelan);
        try {
            const id = elPilihPreset.value ? Number(elPilihPreset.value) : null;
            const hasil = await Sip.api('api/preset.php', {
                method: 'POST',
                json: { aksi: 'simpan', id, nama, data }
            });
            Sip.pesan(hasil.pesan || 'Preset disimpan.');
            elNamaPreset.value = '';
            await muatDaftarPreset();
        } catch (e) {
            Sip.pesan(e.message || 'Gagal menyimpan preset.', 'gagal');
        }
    });

    document.getElementById('muatPreset').addEventListener('click', async () => {
        const id = elPilihPreset.value;
        if (!id) {
            Sip.pesan('Pilih preset yang akan dimuat.', 'gagal');
            return;
        }
        try {
            const hasil = await Sip.api('api/preset.php', { query: { id } });
            const preset = hasil.preset;
            if (!preset || !preset.data) {
                Sip.pesan('Data preset tidak valid.', 'gagal');
                return;
            }
            setelan = rapikanSetelan(preset.data);
            simpanSetelan();
            terapkanSetelan();
            if (setelan.tampilan && typeof setelan.tampilan.lat === 'number') {
                peta.setView([setelan.tampilan.lat, setelan.tampilan.lon], setelan.tampilan.zoom || SIP.peta.zoom);
                sudahMemusat = true;
            }
            muatBidang(true).catch(gagalMuat);
            Sip.pesan('Preset "' + preset.nama + '" dimuat.');
        } catch (e) {
            Sip.pesan(e.message || 'Gagal memuat preset.', 'gagal');
        }
    });

    document.getElementById('hapusPreset').addEventListener('click', async () => {
        const id = elPilihPreset.value;
        if (!id) {
            Sip.pesan('Pilih preset yang akan dihapus.', 'gagal');
            return;
        }
        if (!confirm('Hapus preset ini? Tindakan tidak bisa dibatalkan.')) return;
        try {
            const hasil = await Sip.api('api/preset.php', {
                method: 'POST',
                json: { aksi: 'hapus', id: Number(id) }
            });
            Sip.pesan(hasil.pesan || 'Preset dihapus.');
            await muatDaftarPreset();
        } catch (e) {
            Sip.pesan(e.message || 'Gagal menghapus preset.', 'gagal');
        }
    });

    muatDaftarPreset();
    }

    document.getElementById('aturUlangPengaturan').addEventListener('click', () => {
        setelan = Object.assign({}, BAWAAN);
        simpanSetelan();
        terapkanSetelan();
        sudahMemusat = false;
        muatBidang(true).catch(gagalMuat);
    });

    daftar.addEventListener('click', (e) => {
        const tombol = e.target.closest('[data-lompat]');
        if (!tombol) return;
        const id = Number(tombol.dataset.lompat);
        const terdaftar = tampil.get(id);
        if (!terdaftar) return;
        const batas = terdaftar.lapisan.getBounds();
        if (batas && batas.isValid()) {
            peta.flyToBounds(batas.pad(.6), { duration: .5 });
            tandaAktif(id);
        }
    });

    const form = formSaring;
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        muatBidang(true).catch(gagalMuat);
    });
    form.addEventListener('reset', () => setTimeout(() => muatBidang(true).catch(gagalMuat), 0));

    document.getElementById('pasangkanSemua').addEventListener('click', () => {
        const batasBlok = batasGrup(grup.blok);
        const batas = batasBlok.isValid() ? batasBlok : batasTampil();
        if (batas.isValid()) peta.flyToBounds(batas.pad(.04), { duration: .5 });
    });

    peta.on('zoomend', perbaruiZoom);
    window.addEventListener('resize', rapikanLabelBlok);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(rapikanLabelBlok);
    L.control.scale({ imperial: false, position: 'bottomleft', maxWidth: 120 }).addTo(peta);

    if (setelan.tampilan && typeof setelan.tampilan.lat === 'number' && typeof setelan.tampilan.lon === 'number') {
        peta.setView([setelan.tampilan.lat, setelan.tampilan.lon], setelan.tampilan.zoom || SIP.peta.zoom);
        sudahMemusat = true;
    }
    terapkanSetelan();

    aktifkan('batas', true);
    aktifkan('jalan', setelan.jalan);
    aktifkan('sungai', setelan.sungai);
    muatBatas().catch((err) => console.warn('Batas gagal dimuat:', err));
    if (setelan.jalan) muatJalan().catch((err) => console.warn('Jalan gagal dimuat:', err));
    if (setelan.sungai) muatSungai().catch((err) => console.warn('Sungai gagal dimuat:', err));
    muatBidang().catch(gagalMuat);
})();
</script>
JS;

sip_footer(['leaflet' => true, 'kaki' => $naskah]);
