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

            <div class="peta-mode" role="group" aria-label="Mode tampilan peta">
                <span class="peta-mode-label">Tampilan</span>
                <button type="button" class="mode-tombol aktif" id="modeData" aria-pressed="true"
                    title="Mode pengolahan data spasial: peta interaktif penuh dengan panel lapisan.">Data View</button>
                <button type="button" class="mode-tombol" id="modeLayout" aria-pressed="false"
                    title="Mode perancangan tata letak: bingkai lembar, judul, legenda, skala, dan arah utara untuk diekspor.">Layout View</button>
                <button type="button" class="mode-tombol" id="modeUkur" aria-pressed="false"
                    title="Alat ukur jarak dan luas: klik peta untuk menambah titik, Escape untuk menutup.">Ukur</button>
                <button type="button" class="mode-tombol" id="modeKoordinat" aria-pressed="false"
                    title="Menuju koordinat lintang dan bujur dalam derajat desimal.">Go To XY</button>
                <span class="peta-mode-info" id="modeInfo">Mode pengolahan data spasial.</span>
            </div>

            <div class="peta-layout-alat" id="layoutAlat" hidden>
                <div class="layout-baris">
                    <span class="peta-nav-label">Jenis peta</span>
                    <label class="layout-lapangan" for="layoutJenis">Jenis lembar
                        <select id="layoutJenis">
                            <option value="bidang-desa">Peta bidang Desa</option>
                            <option value="bidang-blok">Peta bidang per blok</option>
                            <option value="blok">Peta blok</option>
                            <option value="jalan">Peta jalan</option>
                            <option value="sungai">Peta sungai</option>
                            <option value="saluran">Peta selokan/saluran</option>
                        </select>
                    </label>
                    <label class="layout-lapangan" for="layoutBlok" id="layoutBlokLapangan" hidden>Blok
                        <select id="layoutBlok">
                            <?php foreach ($blok as $b): ?>
                                <option value="<?= e($b['kode']) ?>"><?= e(blok_tiga_digit($b['kode'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="layout-catatan">Jenis peta mengatur lapisan, judul, dan pusat lembar.</span>
                </div>
                <div class="layout-baris">
                    <span class="peta-nav-label">Lembar</span>
                    <label class="layout-lapangan" for="layoutJudul">Judul
                        <input type="text" id="layoutJudul" maxlength="80" autocomplete="off">
                    </label>
                    <label class="layout-lapangan" for="layoutSubjudul">Subjudul
                        <input type="text" id="layoutSubjudul" maxlength="140" autocomplete="off">
                    </label>
                    <label class="cek"><input type="checkbox" id="layoutUtara" autocomplete="off" checked> Arah utara</label>
                    <label class="cek"><input type="checkbox" id="layoutLegenda" autocomplete="off" checked> Legenda</label>
                    <label class="cek"><input type="checkbox" id="layoutSkalaBatang" autocomplete="off" checked> Skala batang</label>
                </div>
                <div class="layout-baris">
                    <span class="peta-nav-label">Ekspor</span>
                    <button type="button" class="tombol tombol-kecil" id="eksporPng"
                        title="Simpan lembar peta sebagai gambar PNG (2x ukuran layar).">PNG</button>
                    <button type="button" class="tombol tombol-kecil" id="eksporJpg"
                        title="Simpan lembar peta sebagai gambar JPG (2x ukuran layar).">JPG</button>
                    <button type="button" class="tombol tombol-utama tombol-kecil" id="eksporPeta"
                        title="Buka dialog cetak peramban; pilih Simpan sebagai PDF, ukuran A4 lanskap.">PDF</button>
                    <button type="button" class="tombol tombol-kecil" id="unduhProyek"
                        title="Unduh pengaturan peta dan posisi pandangan sebagai berkas proyek JSON.">Unduh proyek (.json)</button>
                    <button type="button" class="tombol tombol-kecil" id="muatProyek"
                        title="Muat kembali berkas proyek JSON yang pernah diunduh.">Muat proyek</button>
                    <input type="file" id="berkasProyek" accept=".json,application/json" hidden>
                    <span class="layout-catatan">Dokumen .mxd ArcGIS tidak bisa dibuat peramban;
                        proyek peta disimpan sebagai JSON.</span>
                </div>
            </div>

            <div class="peta-nav" id="petaNav" role="group" aria-label="Navigasi peta">
                <span class="peta-nav-label">Navigasi</span>
                <button type="button" class="nav-tombol" id="navZoomMasuk"
                    title="Perbesar peta satu tingkat">Zoom In</button>
                <button type="button" class="nav-tombol" id="navZoomKeluar"
                    title="Perkecil peta satu tingkat">Zoom Out</button>
                <button type="button" class="nav-tombol aktif" id="navGeser" aria-pressed="true"
                    title="Geser peta dengan menarik. Matikan bila klik tidak boleh menggeser peta.">Pan</button>
                <button type="button" class="nav-tombol" id="navPenuh"
                    title="Tampilkan seluruh data yang termuat di peta">Pusatkan</button>
                <button type="button" class="nav-tombol" id="navSebelum" disabled
                    title="Kembali ke tampilan peta sebelumnya">&larr; Sebelum</button>
                <button type="button" class="nav-tombol" id="navSesudah" disabled
                    title="Maju ke tampilan peta sesudahnya">Sesudah &rarr;</button>
                <button type="button" class="nav-tombol" id="navPilih" aria-pressed="false"
                    title="Mode pilih: klik bidang di peta untuk menandai atau melepas pilihan.">Pilih</button>
                <button type="button" class="nav-tombol" id="navAtribut" aria-pressed="false"
                    title="Buka tabel atribut bidang pada tampilan ini.">Tabel atribut</button>
            </div>

            <div class="peta-ukur" id="panelUkur" hidden>
                <div class="ukur-jenis" role="group" aria-label="Jenis ukuran">
                    <button type="button" class="ukur-tombol aktif" id="ukurJarak" aria-pressed="true">Jarak</button>
                    <button type="button" class="ukur-tombol" id="ukurLuas" aria-pressed="false">Luas</button>
                </div>
                <p class="ukur-hasil" id="ukurHasil" aria-live="polite">Klik peta untuk menambah titik ukur.</p>
                <button type="button" class="ukur-aksi" id="ukurHapus" title="Buang semua titik ukur">Hapus titik</button>
                <button type="button" class="ukur-aksi" id="ukurTutup" title="Tutup alat ukur">Tutup</button>
            </div>

            <div class="peta-koordinat" id="panelKoordinat" hidden>
                <div class="koordinat-lapangan">
                    <label for="koordinatLat">Lintang</label>
                    <input id="koordinatLat" type="text" inputmode="decimal" autocomplete="off" spellcheck="false"
                        placeholder="-7,0432" aria-describedby="koordinatPesan">
                </div>
                <div class="koordinat-lapangan">
                    <label for="koordinatLon">Bujur</label>
                    <input id="koordinatLon" type="text" inputmode="decimal" autocomplete="off" spellcheck="false"
                        placeholder="110,0631" aria-describedby="koordinatPesan">
                </div>
                <button type="button" class="koordinat-aksi" id="koordinatMenuju"
                    title="Pusatkan peta ke lintang dan bujur ini">Menuju</button>
                <button type="button" class="koordinat-aksi" id="koordinatTutup"
                    title="Tutup panel koordinat">Tutup</button>
                <p class="koordinat-pesan" id="koordinatPesan" aria-live="polite">Isi lintang dan bujur
                    dalam derajat desimal, boleh dengan koma desimal.</p>
                <span class="koordinat-pusat" id="koordinatPusat">Pusat peta: &mdash;</span>
            </div>

            <div class="peta-wadah" id="wadahPeta">
                <div id="peta" aria-label="Peta bidang tanah Desa Gedong"></div>
                <div class="lembar-peta" id="lembarPeta" hidden>
                    <div class="lembar-utara" aria-hidden="true">
                        <svg viewBox="0 0 40 52" width="34" height="44" role="presentation">
                            <path d="M20 2 L34 44 L20 35 L6 44 Z" fill="#1c2b22"></path>
                        </svg>
                        <span>U</span>
                    </div>
                    <div class="lembar-skala-batang" id="lembarSkalaBatang">
                        <div class="skala-batang-garis" id="skalaBatangGaris"></div>
                        <div class="skala-batang-teks" id="skalaBatangTeks"></div>
                    </div>
                    <div class="lembar-legenda">
                        <h3>Legenda</h3>
                        <div id="lembarLegendaIsi"></div>
                    </div>
                    <div class="lembar-judul">
                        <p class="lembar-eyebrow">Pemerintah Desa <?= e(setelan('nama_desa', 'Gedong')) ?></p>
                        <h3 id="lembarJudul">PETA BIDANG TANAH</h3>
                        <p id="lembarSubjudul">Desa <?= e(setelan('nama_desa', 'Gedong')) ?>, Kecamatan
                            <?= e(setelan('nama_kecamatan', 'Patean')) ?>, Kabupaten
                            <?= e(setelan('nama_kabupaten', 'Kendal')) ?></p>
                        <dl>
                            <div><dt>Skala</dt><dd id="lembarSkala">&mdash;</dd></div>
                            <div><dt>Tanggal</dt><dd><?= e(tanggal(date('Y-m-d'))) ?></dd></div>
                            <div><dt>Sumber</dt><dd>Basis data SIPETADES; peta dasar OpenStreetMap</dd></div>
                        </dl>
                        <p class="lembar-jumlah" id="lembarJumlah"></p>
                    </div>
                </div>
            </div>

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
                    <i style="background:#0f766e"></i>Jalan
                </label>
                <label class="chip-lapisan">
                    <input type="checkbox" id="tampilSelokan" autocomplete="off" checked>
                    <i style="background:#0ea5e9"></i>Selokan
                </label>
                <label class="chip-lapisan" title="722 garis saluran: 689 turunan 020100 + 33 baris Jalan yang menjadi tebing saluran">
                    <input type="checkbox" id="tampilSaluran" autocomplete="off" checked>
                    <i style="background:#0369a1"></i>Saluran
                </label>
                <label class="chip-lapisan" title="850 garis tepi saluran: 689 acuan (sama dengan garis selokan 020100) + 161 tebing lawan nyata 020100">
                    <input type="checkbox" id="tampilTepiSaluran" autocomplete="off" checked>
                    <i style="background:#0c4a6e"></i>Tepi saluran
                    <b><?= e(angka(850)) ?></b>
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

        <section class="kartu" id="kartuAtribut" hidden>
            <header class="kepala-kartu">
                <div>
                    <h2>Tabel atribut bidang</h2>
                    <p>Atribut bidang pada tampilan ini. Klik baris untuk memusatkan peta, centang untuk
                        memilih dan menyorot bidang.</p>
                </div>
                <span class="lencana lencana-abu" id="atributJumlah">0 bidang</span>
            </header>
            <div class="atribut-aksi">
                <button type="button" class="tombol tombol-kecil" id="atributPilihTampil"
                    title="Pilih semua bidang yang tampil di tabel">Pilih semua baris</button>
                <button type="button" class="tombol tombol-kecil" id="atributBersihkan"
                    title="Lepas semua pilihan bidang">Bersihkan pilihan</button>
                <span class="atribut-terpilih" id="atributTerpilih">Belum ada yang terpilih</span>
                <button type="button" class="tombol tombol-kecil" id="atributTutup"
                    title="Tutup tabel atribut">Tutup</button>
            </div>
            <div class="gulir-tabel tabel-atribut">
                <table>
                    <thead>
                        <tr>
                            <th class="tengah">Pilih</th>
                            <th>Kode</th>
                            <th>Blok</th>
                            <th>Hak</th>
                            <th>Pemegang</th>
                            <th class="angka">Luas (m²)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="atributIsi">
                        <tr><td colspan="7" class="kosong">Belum ada bidang termuat.</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="petunjuk" id="atributCatatan">Tabel mengikuti saringan Saring peta.</p>
        </section>
    </div>

    <aside>
        <section class="kartu">
            <header class="kepala-kartu"><div><h2>Saring peta</h2></div></header>
            <form id="formSaring">
                <div class="lapangan">
                    <label for="cari">Cari pemegang, kode bidang, atau NOP</label>
                    <input id="cari" name="cari" type="search" placeholder="mis. SUMARNI atau 3324042008-002-0005">
                </div>
                <div class="baris-form">
                    <div class="lapangan">
                        <label for="blok">Blok</label>
                        <select id="blok" name="blok">
                            <option value="">Semua blok</option>
                            <option value="__tanpa">Tanpa blok</option>
                            <?php foreach ($blok as $b): ?>
                                <option value="<?= e($b['kode']) ?>"><?= e(blok_tiga_digit($b['kode'])) ?></option>
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
            <div class="lapangan">
                <label>Simbol garis lapisan</label>
                <div class="tabel-simbol" id="simbolGaris">
                    <div class="baris-simbol" data-lapis="blok">
                        <span>Batas blok</span>
                        <input type="color" data-warna value="#14603a" aria-label="Warna batas blok">
                        <input type="range" min="0.4" max="4" step="0.1" value="2" data-tebal aria-label="Tebal batas blok">
                        <output data-tebal-nilai>2,0</output>
                        <input type="range" min="10" max="100" step="5" value="95" data-pekat aria-label="Kepekatan garis batas blok">
                        <output data-pekat-nilai>95%</output>
                    </div>
                    <div class="baris-simbol" data-lapis="batas">
                        <span>Batas desa</span>
                        <input type="color" data-warna value="#1f4e79" aria-label="Warna batas desa">
                        <input type="range" min="0.4" max="4" step="0.1" value="2.4" data-tebal aria-label="Tebal batas desa">
                        <output data-tebal-nilai>2,4</output>
                        <input type="range" min="10" max="100" step="5" value="100" data-pekat aria-label="Kepekatan garis batas desa">
                        <output data-pekat-nilai>100%</output>
                    </div>
                    <div class="baris-simbol" data-lapis="jalan">
                        <span>Jalan</span>
                        <input type="color" data-warna value="#0f766e" aria-label="Warna garis jalan">
                        <input type="range" min="0.4" max="4" step="0.1" value="1.1" data-tebal aria-label="Tebal garis jalan">
                        <output data-tebal-nilai>1,1</output>
                        <input type="range" min="10" max="100" step="5" value="85" data-pekat aria-label="Kepekatan garis jalan">
                        <output data-pekat-nilai>85%</output>
                    </div>
                    <div class="baris-simbol" data-lapis="selokan">
                        <span>Selokan</span>
                        <input type="color" data-warna value="#0ea5e9" aria-label="Warna garis selokan">
                        <input type="range" min="0.4" max="4" step="0.1" value="1.4" data-tebal aria-label="Tebal garis selokan">
                        <output data-tebal-nilai>1,4</output>
                        <input type="range" min="10" max="100" step="5" value="95" data-pekat aria-label="Kepekatan garis selokan">
                        <output data-pekat-nilai>95%</output>
                    </div>
                    <div class="baris-simbol" data-lapis="saluran">
                        <span>Saluran</span>
                        <input type="color" data-warna value="#0369a1" aria-label="Warna garis saluran">
                        <input type="range" min="0.4" max="4" step="0.1" value="1.7" data-tebal aria-label="Tebal garis saluran">
                        <output data-tebal-nilai>1,7</output>
                        <input type="range" min="10" max="100" step="5" value="95" data-pekat aria-label="Kepekatan garis saluran">
                        <output data-pekat-nilai>95%</output>
                    </div>
                    <div class="baris-simbol" data-lapis="tepiSaluran">
                        <span>Tepi saluran</span>
                        <input type="color" data-warna value="#0c4a6e" aria-label="Warna garis tepi saluran">
                        <input type="range" min="0.4" max="4" step="0.1" value="1.2" data-tebal aria-label="Tebal garis tepi saluran">
                        <output data-tebal-nilai>1,2</output>
                        <input type="range" min="10" max="100" step="5" value="90" data-pekat aria-label="Kepekatan garis tepi saluran">
                        <output data-pekat-nilai>90%</output>
                    </div>
                    <div class="baris-simbol" data-lapis="sungai">
                        <span>Sungai</span>
                        <input type="color" data-warna value="#1d4ed8" aria-label="Warna garis sungai">
                        <input type="range" min="0.4" max="4" step="0.1" value="2" data-tebal aria-label="Tebal garis sungai">
                        <output data-tebal-nilai>2,0</output>
                        <input type="range" min="10" max="100" step="5" value="95" data-pekat aria-label="Kepekatan garis sungai">
                        <output data-pekat-nilai>95%</output>
                    </div>
                </div>
                <p class="petunjuk">Warna, tebal, dan kepekatan garis tiap lapis. Isian bidang tetap memakai
                    skema warna di atas.</p>
            </div>
            <label class="cek jarak-atas"><input type="checkbox" id="labelBidang" autocomplete="off"> Tampilkan label bidang</label>
            <label class="cek"><input type="checkbox" id="labelBlok" autocomplete="off" checked> Tampilkan nama blok</label>
            <div class="lapangan jarak-atas">
                <label for="labelIsi">Isi label bidang</label>
                <select id="labelIsi" disabled>
                    <option value="pemegang">Nama pemegang</option>
                    <option value="kode">Kode bidang</option>
                    <option value="luas">Luas (m²)</option>
                </select>
            </div>
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
    const GARIS_BAWAAN = {
        blok: { warna: '#14603a', tebal: 2, pekat: 95 },
        batas: { warna: '#1f4e79', tebal: 2.4, pekat: 100 },
        jalan: { warna: '#0f766e', tebal: 1.1, pekat: 85 },
        selokan: { warna: '#0ea5e9', tebal: 1.4, pekat: 95 },
        saluran: { warna: '#0369a1', tebal: 1.7, pekat: 95 },
        tepiSaluran: { warna: '#0c4a6e', tebal: 1.2, pekat: 90 },
        sungai: { warna: '#1d4ed8', tebal: 2, pekat: 95 }
    };
    // Pola putus tetap per lapis; yang bisa diubah hanya warna, tebal, kepekatan.
    const PUTUS_GARIS = { batas: '8 5', sungai: '9 5' };
    const BAWAAN = {
        dasar: 'jalan', warna: 'blok', kepekatan: 55, label: false, labelBlok: true,
        labelIsi: 'pemegang',
        bidang: true, lain: false, blok: true, batas: true, laporan: false,
        jalan: true, selokan: true, saluran: true, tepiSaluran: true, sungai: true, tampilan: null,
        garis: GARIS_BAWAAN,
        layout: {
            jenis: 'bidang-desa', blok: '', judul: '', subjudul: '',
            utara: true, legenda: true, skalaBatang: true
        }
    };
    const MODE = ['blok', 'tipe', 'hak', 'pbb', 'status'];
    const ISI_LABEL = ['pemegang', 'kode', 'luas'];
    // Enam jenis lembar Layout View. Lapis menentukan chip yang dinyalakan;
    // judul hanya dipakai saat jenis diganti, bukan setiap kali masuk Layout.
    const JENIS_LEMBAR = ['bidang-desa', 'bidang-blok', 'blok', 'jalan', 'sungai', 'saluran'];
    const LEMBAR_PRESET = {
        'bidang-desa': {
            label: 'Peta bidang Desa',
            judul: 'PETA BIDANG TANAH DESA',
            lapis: { bidang: true, lain: false, blok: true, jalan: false, selokan: false, saluran: false, tepiSaluran: false, sungai: false }
        },
        'bidang-blok': {
            label: 'Peta bidang per blok',
            judul: 'PETA BIDANG TANAH',
            lapis: { bidang: true, lain: false, blok: true, jalan: false, selokan: false, saluran: false, tepiSaluran: false, sungai: false }
        },
        blok: {
            label: 'Peta blok',
            judul: 'PETA BLOK',
            lapis: { bidang: false, lain: false, blok: true, jalan: false, selokan: false, saluran: false, tepiSaluran: false, sungai: false }
        },
        jalan: {
            label: 'Peta jalan',
            judul: 'PETA JALAN',
            lapis: { bidang: false, lain: false, blok: false, jalan: true, selokan: false, saluran: false, tepiSaluran: false, sungai: false }
        },
        sungai: {
            label: 'Peta sungai',
            judul: 'PETA SUNGAI',
            lapis: { bidang: false, lain: false, blok: false, jalan: false, selokan: false, saluran: false, tepiSaluran: false, sungai: true }
        },
        saluran: {
            label: 'Peta selokan/saluran',
            judul: 'PETA SELOKAN & SALURAN',
            lapis: { bidang: false, lain: false, blok: false, jalan: false, selokan: true, saluran: true, tepiSaluran: true, sungai: false }
        }
    };
    const LAPIS_LEMBAR = ['bidang', 'lain', 'blok', 'jalan', 'selokan', 'saluran', 'tepiSaluran', 'sungai'];
    const BATAS_LABEL = 1500;
    const ZOOM_LABEL = 14;

    function rapikanGaris(isi) {
        const hasil = {};
        Object.keys(GARIS_BAWAAN).forEach((nama) => {
            const b = GARIS_BAWAAN[nama];
            const g = isi && typeof isi[nama] === 'object' ? isi[nama] : {};
            hasil[nama] = {
                warna: /^#[0-9a-f]{6}$/i.test(String(g.warna || '')) ? String(g.warna) : b.warna,
                tebal: Math.max(0.4, Math.min(4, Number(g.tebal) || b.tebal)),
                pekat: Math.max(10, Math.min(100, Math.round(Number(g.pekat) || b.pekat)))
            };
        });
        return hasil;
    }

    function rapikanLayout(isi) {
        const l = isi && typeof isi === 'object' ? isi : {};
        return {
            jenis: JENIS_LEMBAR.indexOf(l.jenis) >= 0 ? l.jenis : 'bidang-desa',
            blok: typeof l.blok === 'string' ? l.blok.slice(0, 40) : '',
            judul: typeof l.judul === 'string' ? l.judul.slice(0, 80) : '',
            subjudul: typeof l.subjudul === 'string' ? l.subjudul.slice(0, 140) : '',
            utara: l.utara !== false,
            legenda: l.legenda !== false,
            skalaBatang: l.skalaBatang !== false
        };
    }

    function rapikanSetelan(isi) {
        const s = Object.assign({}, BAWAAN, isi || {});
        if (s.dasar !== 'jalan' && s.dasar !== 'satelit') s.dasar = BAWAAN.dasar;
        if (MODE.indexOf(s.warna) < 0) s.warna = BAWAAN.warna;
        s.kepekatan = Math.max(45, Math.min(80, Number(s.kepekatan) || 0));
        if (ISI_LABEL.indexOf(s.labelIsi) < 0) s.labelIsi = BAWAAN.labelIsi;
        s.garis = rapikanGaris(s.garis);
        s.layout = rapikanLayout(s.layout);
        ['label', 'labelBlok', 'bidang', 'blok', 'batas', 'laporan', 'jalan', 'selokan', 'saluran', 'tepiSaluran', 'sungai'].forEach((k) => { s[k] = !!s[k]; });
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
        try {
            // Selama Layout View, yang tersimpan tetap pilihan lapis Data View;
            // preset lembar hanya berlaku sementara di layar.
            const isi = modeTampilan === 'layout' && lapisSebelumLembar
                ? Object.assign({}, setelan, lapisSebelumLembar)
                : setelan;
            localStorage.setItem(KUNCI, JSON.stringify(isi));
        } catch (e) { /* mode privat */ }
    }

    const peta = L.map('peta').setView([SIP.peta.lat, SIP.peta.lon], SIP.peta.zoom);
    peta.attributionControl.setPrefix('');

    // Panes menentukan urutan gambar tanpa bergantung urutan addLayer:
    // batas desa paling bawah, lalu blok, jalan, sungai, selokan, saluran, obyek non-tanah, bidang tanah.
    const PANES = { batas: 405, blok: 410, jalan: 412, selokan: 414, sungai: 413, saluran: 416, 'tepi-saluran': 417, lain: 415, tanah: 420, laporan: 430 };
    Object.keys(PANES).forEach((nama) => {
        const pane = peta.createPane('pane-' + nama);
        pane.style.zIndex = String(PANES[nama]);
    });
    peta.getPane('pane-batas').style.pointerEvents = 'none';
    peta.getPane('pane-jalan').style.pointerEvents = 'none';
    peta.getPane('pane-selokan').style.pointerEvents = 'none';
    peta.getPane('pane-saluran').style.pointerEvents = 'none';
    peta.getPane('pane-tepi-saluran').style.pointerEvents = 'none';
    peta.getPane('pane-laporan').style.pointerEvents = 'auto';

    const grup = {
        bidang: L.layerGroup(), lain: L.layerGroup(), blok: L.layerGroup(),
        batas: L.layerGroup(), laporan: L.layerGroup(),
        jalan: L.layerGroup(), selokan: L.layerGroup(), saluran: L.layerGroup(),
        tepiSaluran: L.layerGroup(), sungai: L.layerGroup()
    };

    // 1.940 garis tepi jalan digambar di kanvas supaya pane jalan tidak menumpuk
    // ribuan elemen SVG; label jalannya tetap div di atas kanvas itu.
    const kanvasJalan = L.canvas({ pane: 'pane-jalan', padding: 0.4 });
    const kanvasSelokan = L.canvas({ pane: 'pane-selokan', padding: 0.4 });
    const kanvasSaluran = L.canvas({ pane: 'pane-saluran', padding: 0.4 });
    const kanvasTepiSaluran = L.canvas({ pane: 'pane-tepi-saluran', padding: 0.4 });
    const kanvasSungai = L.canvas({ pane: 'pane-sungai', padding: 0.4 });

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

    function teksBlok(kode) {
        return String(kode || '').replace(/^BLOK\s+(\d+)$/i, (_, nomor) => 'BLOK ' + nomor.padStart(3, '0'));
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

    // Gaya lapis garis dibaca dari pengaturan supaya muat ulang, preset, dan
    // berkas proyek membawa warna, tebal, dan kepekatan yang sama.
    function gayaGaris(nama) {
        const g = setelan.garis[nama] || GARIS_BAWAAN[nama];
        return {
            color: g.warna, weight: g.tebal, opacity: g.pekat / 100,
            fillOpacity: 0, dashArray: PUTUS_GARIS[nama] || null
        };
    }

    const daftar = document.getElementById('daftarBidang');
    const info = document.getElementById('infoMuat');
    const hitung = document.getElementById('hitungTampil');
    const formSaring = document.getElementById('formSaring');
    const modeData = document.getElementById('modeData');
    const modeLayout = document.getElementById('modeLayout');
    const modeInfo = document.getElementById('modeInfo');
    const eksporPeta = document.getElementById('eksporPeta');
    const lembarPeta = document.getElementById('lembarPeta');
    const lembarLegendaIsi = document.getElementById('lembarLegendaIsi');
    const lembarSkala = document.getElementById('lembarSkala');
    const lembarJumlah = document.getElementById('lembarJumlah');
    const lembarJudulIsi = document.getElementById('lembarJudul');
    const lembarSubjudul = document.getElementById('lembarSubjudul');
    const lembarUtara = lembarPeta.querySelector('.lembar-utara');
    const lembarLegenda = lembarPeta.querySelector('.lembar-legenda');
    const lembarSkalaBatang = document.getElementById('lembarSkalaBatang');
    const skalaBatangGaris = document.getElementById('skalaBatangGaris');
    const skalaBatangTeks = document.getElementById('skalaBatangTeks');
    const layoutAlat = document.getElementById('layoutAlat');
    const layoutJenis = document.getElementById('layoutJenis');
    const layoutBlok = document.getElementById('layoutBlok');
    const layoutBlokLapangan = document.getElementById('layoutBlokLapangan');
    const layoutJudul = document.getElementById('layoutJudul');
    const layoutSubjudul = document.getElementById('layoutSubjudul');
    const layoutUtara = document.getElementById('layoutUtara');
    const layoutLegenda = document.getElementById('layoutLegenda');
    const layoutSkalaBatang = document.getElementById('layoutSkalaBatang');
    const eksporPng = document.getElementById('eksporPng');
    const eksporJpg = document.getElementById('eksporJpg');
    const unduhProyek = document.getElementById('unduhProyek');
    const muatProyek = document.getElementById('muatProyek');
    const berkasProyek = document.getElementById('berkasProyek');
    const JUDUL_BAWAAN = lembarJudulIsi.textContent.trim();
    const SUBJUDUL_BAWAAN = lembarSubjudul.textContent.trim().replace(/\s+/g, ' ');
    const modeUkur = document.getElementById('modeUkur');
    const panelUkur = document.getElementById('panelUkur');
    const ukurJarak = document.getElementById('ukurJarak');
    const ukurLuas = document.getElementById('ukurLuas');
    const ukurHasil = document.getElementById('ukurHasil');
    const ukurHapus = document.getElementById('ukurHapus');
    const ukurTutup = document.getElementById('ukurTutup');
    const modeKoordinat = document.getElementById('modeKoordinat');
    const panelKoordinat = document.getElementById('panelKoordinat');
    const koordinatLat = document.getElementById('koordinatLat');
    const koordinatLon = document.getElementById('koordinatLon');
    const koordinatPesan = document.getElementById('koordinatPesan');
    const koordinatPusat = document.getElementById('koordinatPusat');
    const koordinatMenuju = document.getElementById('koordinatMenuju');
    const koordinatTutup = document.getElementById('koordinatTutup');
    const navZoomMasuk = document.getElementById('navZoomMasuk');
    const navZoomKeluar = document.getElementById('navZoomKeluar');
    const navGeser = document.getElementById('navGeser');
    const navPenuh = document.getElementById('navPenuh');
    const navSebelum = document.getElementById('navSebelum');
    const navSesudah = document.getElementById('navSesudah');
    const navPilih = document.getElementById('navPilih');
    const navAtribut = document.getElementById('navAtribut');
    const kartuAtribut = document.getElementById('kartuAtribut');
    const atributIsi = document.getElementById('atributIsi');
    const atributJumlah = document.getElementById('atributJumlah');
    const atributTerpilih = document.getElementById('atributTerpilih');
    const atributCatatan = document.getElementById('atributCatatan');
    const atributPilihTampil = document.getElementById('atributPilihTampil');
    const atributBersihkan = document.getElementById('atributBersihkan');
    const atributTutup = document.getElementById('atributTutup');
    let modeTampilan = 'data';
    let siapAwal = false;
    let lapisSebelumLembar = null;
    let penyegaranLembar = 0;
    // Teks blok "BLOK 0NN" yang menyaring lembar per blok; null = tanpa saringan.
    let blokLembar = null;
    const dataLapisan = { tanah: null, lain: null };
    const filterLapisan = { tanah: null, lain: null };
    const tampil = new Map();
    let terpotong = false;
    let sudahMemusat = false;

    // Lembar "Peta bidang per blok" hanya menggambar bidang milik blok yang
    // dipilih; kode blok bidang ("BLOK 2") disamakan dengan teks pilihan.
    function fiturSaringLembar(fitur) {
        if (!blokLembar) return fitur;
        return fitur.filter((f) => teksBlok(f.properties.blok) === blokLembar);
    }

    function fiturTampil() {
        const hasil = [];
        if (setelan.bidang && dataLapisan.tanah) hasil.push.apply(hasil, fiturSaringLembar(dataLapisan.tanah));
        if (setelan.lain && dataLapisan.lain) hasil.push.apply(hasil, fiturSaringLembar(dataLapisan.lain));
        return hasil;
    }

    function gambarLegenda() {
        const fitur = fiturTampil();
        const hitungDengan = (fn) => fitur.reduce((n, f) => n + (fn(f.properties) ? 1 : 0), 0);
        const butir = (warna, teks, jumlah, kelas) =>
            '<span><i class="' + (kelas || '') + '" style="background:' + warna + '"></i>'
            + Sip.esc(teks) + (jumlah === null ? '' : ' <b>' + Sip.angka(jumlah) + '</b>') + '</span>';
        // Contoh garis memakai warna, tebal, dan pola putus yang sedang dipakai peta.
        const butirGaris = (nama, teks) => {
            const g = setelan.garis[nama];
            const latar = PUTUS_GARIS[nama]
                ? 'repeating-linear-gradient(90deg, ' + g.warna + ' 0 5px, transparent 5px 8px)'
                : g.warna;
            const tebal = Math.max(2, Math.min(4, Math.round(g.tebal)));
            return '<span><i style="height:' + tebal + 'px;border-radius:0;vertical-align:2px;'
                + 'background:' + latar + '"></i>' + Sip.esc(teks) + '</span>';
        };
        const baris = [];

        // Lembar garis (jalan/sungai/saluran) tidak memuat bidang tanah, jadi
        // butir legenda bidang dilewati agar tidak tercetak angka nol.
        if (setelan.bidang || setelan.lain) {
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
        }

        if (setelan.blok) {
            baris.push(butirGaris('blok', 'Batas blok ukur'));
        }
        if (setelan.batas) {
            baris.push(butirGaris('batas', 'Batas desa'));
        }
        if (setelan.jalan) {
            baris.push(butirGaris('jalan', 'Garis jalan'));
        }
        if (setelan.selokan) {
            baris.push(butirGaris('selokan', 'Garis selokan'));
        }
        if (setelan.saluran) {
            baris.push(butirGaris('saluran', 'Saluran'));
        }
        if (setelan.tepiSaluran) {
            baris.push(butirGaris('tepiSaluran', 'Tepi saluran'));
        }
        if (setelan.sungai) {
            baris.push(butirGaris('sungai', 'Sumbu sungai'));
        }
        document.getElementById('legendaPeta').innerHTML = baris.join('');
        if (modeTampilan === 'layout') salinLegenda();
    }

    // Data View: peta interaktif penuh. Layout View: lembar siap ekspor dengan
    // judul, legenda, skala, arah utara; cetak lewat dialog peramban (A4 lanskap).
    function salinLegenda() {
        lembarLegendaIsi.innerHTML = document.getElementById('legendaPeta').innerHTML;
        if (!setelan.bidang && !setelan.lain) {
            const preset = LEMBAR_PRESET[setelan.layout.jenis] || LEMBAR_PRESET['bidang-desa'];
            lembarJumlah.textContent = 'Lembar ' + preset.label.toLowerCase()
                + ' — bidang tanah tidak ditampilkan.';
            return;
        }
        const jumlah = (hitung.textContent || '').trim();
        lembarJumlah.textContent = jumlah && jumlah !== '0'
            ? 'Bidang pada tampilan ini: ' + jumlah
            : 'Bidang pada tampilan ini belum termuat.';
    }

    function perbaruiSkalaLembar() {
        const titik = peta.getCenter();
        const meterPiksel = 156543.03392 * Math.cos(titik.lat * Math.PI / 180) / Math.pow(2, peta.getZoom());
        const penyebut = Math.max(100, Math.round(meterPiksel * 3779.5276 / 100) * 100);
        lembarSkala.textContent = '± 1 : ' + Sip.angka(penyebut);
    }

    // Skala batang memilih jarak bulat 1, 2, atau 5 x 10^n yang paling dekat
    // dengan 110 piksel pada zoom sekarang; batang dibagi empat petak.
    function perbaruiSkalaBatang() {
        if (lembarSkalaBatang.hidden) return;
        const pusat = peta.getCenter();
        const meterPiksel = 156543.03392 * Math.cos(pusat.lat * Math.PI / 180) / Math.pow(2, peta.getZoom());
        const sasaran = meterPiksel * 110;
        const pangkat = Math.pow(10, Math.floor(Math.log10(Math.max(1, sasaran))));
        let meter = pangkat;
        [1, 2, 5, 10].forEach((k) => {
            if (Math.abs(k * pangkat - sasaran) < Math.abs(meter - sasaran)) meter = k * pangkat;
        });
        const lebar = Math.max(40, Math.round(meter / meterPiksel));
        const potong = lebar / 4;
        const petak = [];
        for (let i = 0; i < 4; i++) {
            petak.push('<span style="width:' + potong + 'px;background:'
                + (i % 2 ? '#fff' : '#1c2b22') + '"></span>');
        }
        skalaBatangGaris.innerHTML = petak.join('');
        const label = (m) => m >= 1000
            ? Sip.angka(m / 1000, m % 1000 ? 1 : 0) + ' km'
            : Sip.angka(m) + ' m';
        skalaBatangTeks.innerHTML = '<span>0</span><span>' + label(meter / 2)
            + '</span><span>' + label(meter) + '</span>';
    }

    function terapkanLayout() {
        const l = setelan.layout;
        lembarJudulIsi.textContent = l.judul.trim() || JUDUL_BAWAAN;
        lembarSubjudul.textContent = l.subjudul.trim() || SUBJUDUL_BAWAAN;
        lembarUtara.hidden = !l.utara;
        lembarLegenda.hidden = !l.legenda;
        lembarSkalaBatang.hidden = !l.skalaBatang;
        layoutJenis.value = l.jenis;
        if (l.blok) layoutBlok.value = l.blok;
        if (!layoutBlok.value && layoutBlok.options.length) layoutBlok.selectedIndex = 0;
        layoutBlokLapangan.hidden = l.jenis !== 'bidang-blok';
        layoutJudul.value = l.judul;
        layoutSubjudul.value = l.subjudul;
        layoutUtara.checked = l.utara;
        layoutLegenda.checked = l.legenda;
        layoutSkalaBatang.checked = l.skalaBatang;
        perbaruiSkalaBatang();
    }

    function terapkanSimbolGaris() {
        Object.keys(setelan.garis).forEach((nama) => {
            if (!grup[nama]) return;
            const gaya = gayaGaris(nama);
            grup[nama].eachLayer((l) => { if (l.setStyle) l.setStyle(gaya); });
        });
        gambarLegenda();
    }

    function perbaruiSimbolInput() {
        document.querySelectorAll('#simbolGaris .baris-simbol').forEach((baris) => {
            const g = setelan.garis[baris.dataset.lapis];
            if (!g) return;
            baris.querySelector('[data-warna]').value = g.warna;
            baris.querySelector('[data-tebal]').value = g.tebal;
            baris.querySelector('[data-tebal-nilai]').textContent = g.tebal.toFixed(1).replace('.', ',');
            baris.querySelector('[data-pekat]').value = g.pekat;
            baris.querySelector('[data-pekat-nilai]').textContent = g.pekat + '%';
        });
    }

    function aturModeTampilan(mode) {
        const baru = mode === 'layout' ? 'layout' : 'data';
        const keluarLembar = modeTampilan === 'layout' && baru === 'data';
        modeTampilan = baru;
        const layout = modeTampilan === 'layout';
        document.body.classList.toggle('tampilan-layout', layout);
        modeData.classList.toggle('aktif', !layout);
        modeLayout.classList.toggle('aktif', layout);
        modeData.setAttribute('aria-pressed', String(!layout));
        modeLayout.setAttribute('aria-pressed', String(layout));
        modeInfo.textContent = layout
            ? 'Mode perancangan tata letak: sunting elemen lembar lalu ekspor PDF, PNG, atau JPG.'
            : 'Mode pengolahan data spasial.';
        eksporPeta.hidden = !layout;
        layoutAlat.hidden = !layout;
        lembarPeta.hidden = !layout;
        if (layout) {
            if (ukurAktif) aturUkur(false);
            if (koordinatAktif) aturKoordinat(false);
            if (!lapisSebelumLembar) lapisSebelumLembar = ambilLapis();
            if (siapAwal) terapkanJenisLembar(true, !setelan.layout.judul);
            perbaruiSkalaLembar();
            perbaruiSkalaBatang();
            salinLegenda();
        } else if (keluarLembar) {
            pulihkanLapisDataView();
        }
        window.setTimeout(() => peta.invalidateSize(), 60);
    }

    function ambilLapis() {
        const hasil = {};
        LAPIS_LEMBAR.forEach((k) => { hasil[k] = !!setelan[k]; });
        return hasil;
    }

    // Lembar "per blok" memakai garis luar blok terpilih sebagai bingkai peta.
    // Kode di tabel blok ("BLOK 2") dan di GeoJSON blok ("BLOK 002") berbeda
    // penulisan, jadi keduanya dinormalkan dengan teksBlok().
    function batasBlokKode(kode) {
        const cari = teksBlok(kode);
        const b = L.latLngBounds([]);
        grup.blok.eachLayer((l) => {
            const kodeLapis = l.kodeBlok
                || (l.feature && l.feature.properties ? l.feature.properties.kode : '');
            if (teksBlok(kodeLapis) === cari && l.getBounds) b.extend(l.getBounds());
        });
        return b;
    }

    // Judul yang masih berupa bawaan presets boleh ditimpa saat jenis/blok
    // berganti; judul ketikan pengguna dibiarkan.
    function judulOtomatis(teks) {
        const t = String(teks || '').trim();
        if (!t) return true;
        return Object.keys(LEMBAR_PRESET).some((k) => {
            const judul = LEMBAR_PRESET[k].judul;
            return t === judul || t.indexOf(judul + ' ') === 0;
        });
    }

    function judulJenisLembar(jenis) {
        const preset = LEMBAR_PRESET[jenis] || LEMBAR_PRESET['bidang-desa'];
        if (jenis !== 'bidang-blok') return preset.judul;
        const opt = layoutBlok.options[layoutBlok.selectedIndex];
        const teks = opt ? opt.textContent.trim() : '';
        return teks ? preset.judul + ' ' + teks : preset.judul;
    }

    function pusatkanJenisLembar(jenis) {
        let b = L.latLngBounds([]);
        if (jenis === 'bidang-blok') {
            b = batasBlokKode(setelan.layout.blok);
            if (!b.isValid()) b = batasTampil();
            if (!b.isValid()) b = batasGrup(grup.blok);
        } else if (jenis === 'bidang-desa') {
            b = batasTampil();
        } else if (jenis === 'blok') {
            b = batasGrup(grup.blok);
        } else if (jenis === 'jalan') {
            b = batasGrup(grup.jalan);
        } else if (jenis === 'sungai') {
            b = batasGrup(grup.sungai);
        } else if (jenis === 'saluran') {
            ['selokan', 'saluran', 'tepiSaluran'].forEach((k) => b.extend(batasGrup(grup[k])));
        }
        if (!b.isValid()) b = batasTampil();
        if (b.isValid()) peta.fitBounds(b.pad(jenis === 'bidang-blok' ? .1 : .05), { animate: false });
    }

    // Jenis peta hanya berlaku selama Layout View. Lapis Data View dicadangkan
    // saat masuk dan dikembalikan saat keluar.
    async function terapkanJenisLembar(pusatkan = true, tulisJudul = false) {
        const jenis = setelan.layout.jenis;
        const preset = LEMBAR_PRESET[jenis] || LEMBAR_PRESET['bidang-desa'];
        const tanda = ++penyegaranLembar;
        const bidangSebelum = !!setelan.bidang || !!setelan.lain;
        LAPIS_LEMBAR.forEach((k) => { setelan[k] = !!preset.lapis[k]; });
        if (jenis === 'bidang-blok' && layoutBlok.value) setelan.layout.blok = layoutBlok.value;
        const bidangBerubah = bidangSebelum !== (!!setelan.bidang || !!setelan.lain);
        // Lembar per blok hanya menggambar bidang milik blok yang dipilih.
        const blokBaru = jenis === 'bidang-blok' && setelan.layout.blok
            ? teksBlok(setelan.layout.blok) : null;
        const blokBerubah = blokBaru !== blokLembar;
        blokLembar = blokBaru;
        if (tulisJudul) {
            setelan.layout.judul = judulJenisLembar(jenis);
            setelan.layout.subjudul = '';
        }
        simpanSetelan();

        const tugas = [];
        if (setelan.blok && !grup.blok.getLayers().length) tugas.push(muatBlok());
        if (setelan.jalan && !grup.jalan.getLayers().length) tugas.push(muatJalan());
        if (setelan.selokan && !grup.selokan.getLayers().length) tugas.push(muatSelokan());
        if (setelan.saluran && !grup.saluran.getLayers().length) tugas.push(muatSaluran());
        if (setelan.tepiSaluran && !grup.tepiSaluran.getLayers().length) tugas.push(muatTepiSaluran());
        if (setelan.sungai && !grup.sungai.getLayers().length) tugas.push(muatSungai());
        await Promise.all(tugas.map((t) => t.catch((err) => Sip.pesan(err.message, 'gagal'))));
        if (tanda !== penyegaranLembar) return;

        let muatUlang = false;
        if (setelan.bidang && !dataLapisan.tanah) {
            muatUlang = true;
            await muatBidang(false).catch(gagalMuat);
            if (tanda !== penyegaranLembar) return;
        }
        if ((blokBerubah || bidangBerubah) && !muatUlang) {
            if (setelan.bidang && dataLapisan.tanah) gambarLapisan('tanah');
            if (setelan.lain && dataLapisan.lain) gambarLapisan('lain');
            segarkanTanpaMuat();
            if (!setelan.bidang && !setelan.lain) {
                info.textContent = 'Lembar ' + preset.label.toLowerCase()
                    + ': bidang tanah tidak ditampilkan.';
            }
        }
        terapkanSetelan();
        aktifkan('bidang', setelan.bidang);
        aktifkan('lain', false);
        gambarLegenda();
        if (pusatkan) pusatkanJenisLembar(jenis);
    }

    function pulihkanLapisDataView() {
        if (!lapisSebelumLembar) return;
        const f = lapisSebelumLembar;
        lapisSebelumLembar = null;
        const adaSaring = blokLembar !== null;
        blokLembar = null;
        LAPIS_LEMBAR.forEach((k) => { setelan[k] = !!f[k]; });
        simpanSetelan();
        terapkanSetelan();
        aktifkan('bidang', setelan.bidang);
        aktifkan('lain', setelan.lain);
        if (adaSaring) {
            if (dataLapisan.tanah) gambarLapisan('tanah');
            if (dataLapisan.lain) gambarLapisan('lain');
            segarkanTanpaMuat();
        }
        if (setelan.bidang && !dataLapisan.tanah) muatBidang(false).catch(gagalMuat);
    }

    // Alat ukur: klak-klik menambah titik, Escape atau Tutup mengakhiri.
    // Jarak memakai rumus bola Leaflet (peta.distance); luas memakai kelebihan
    // bola (Chamberlain-Duquette) supaya persil ratusan meter tidak melengkung.
    const grupUkur = L.layerGroup();
    let ukurAktif = false;
    let ukurJenis = 'jarak';
    let ukurTitik = [];
    let ukurDobelZoom = false;

    function luasBola(titik) {
        const R = 6371008.8;
        let jumlah = 0;
        for (let i = 0; i < titik.length; i++) {
            const a = titik[i];
            const b = titik[(i + 1) % titik.length];
            const f1 = a.lat * Math.PI / 180;
            const f2 = b.lat * Math.PI / 180;
            jumlah += (b.lng - a.lng) * Math.PI / 180 * (2 + Math.sin(f1) + Math.sin(f2));
        }
        return Math.abs(jumlah * R * R / 2);
    }

    function teksJarak(meter) {
        return meter >= 1000
            ? Sip.angka(meter / 1000, 2) + ' km'
            : Sip.angka(meter, 1) + ' m';
    }

    function perbaruiHasilUkur() {
        const n = ukurTitik.length;
        if (!n) {
            ukurHasil.textContent = 'Klik peta untuk menambah titik ukur.';
            return;
        }
        let jarak = 0;
        for (let i = 1; i < n; i++) jarak += peta.distance(ukurTitik[i - 1], ukurTitik[i]);
        if (ukurJenis === 'luas' && n >= 3) {
            const keliling = jarak + peta.distance(ukurTitik[n - 1], ukurTitik[0]);
            const luas = luasBola(ukurTitik);
            ukurHasil.textContent = 'Luas ' + Sip.angka(luas, 0) + ' m² ('
                + Sip.angka(luas / 10000, 2) + ' ha) · keliling ' + teksJarak(keliling)
                + ' · ' + n + ' titik.';
        } else if (ukurJenis === 'luas') {
            ukurHasil.textContent = 'Butuh minimal 3 titik. Sudah ' + n + ' titik'
                + (n >= 2 ? ', panjang ' + teksJarak(jarak) : '') + '.';
        } else {
            ukurHasil.textContent = 'Jarak ' + teksJarak(jarak) + ' · ' + n + ' titik'
                + (n > 1 ? ' (' + (n - 1) + ' ruas)' : '') + '.';
        }
    }

    function gambarUkur() {
        grupUkur.clearLayers();
        if (ukurTitik.length) {
            if (ukurJenis === 'luas' && ukurTitik.length >= 3) {
                L.polygon(ukurTitik, {
                    color: '#c2410c', weight: 2, fillColor: '#fb923c', fillOpacity: .22,
                    interactive: false
                }).addTo(grupUkur);
            }
            if (ukurTitik.length > 1) {
                L.polyline(ukurTitik, {
                    color: '#c2410c', weight: 2.5, dashArray: '6 4', interactive: false
                }).addTo(grupUkur);
            }
            ukurTitik.forEach((t) => {
                L.circleMarker(t, {
                    radius: 4, color: '#c2410c', weight: 2,
                    fillColor: '#fff', fillOpacity: 1, interactive: false
                }).addTo(grupUkur);
            });
        }
        perbaruiHasilUkur();
    }

    function aturJenisUkur(jenis) {
        ukurJenis = jenis === 'luas' ? 'luas' : 'jarak';
        ukurJarak.classList.toggle('aktif', ukurJenis === 'jarak');
        ukurLuas.classList.toggle('aktif', ukurJenis === 'luas');
        ukurJarak.setAttribute('aria-pressed', String(ukurJenis === 'jarak'));
        ukurLuas.setAttribute('aria-pressed', String(ukurJenis === 'luas'));
        gambarUkur();
    }

    function aturUkur(hidup) {
        ukurAktif = !!hidup;
        modeUkur.classList.toggle('aktif', ukurAktif);
        modeUkur.setAttribute('aria-pressed', String(ukurAktif));
        panelUkur.hidden = !ukurAktif;
        document.getElementById('wadahPeta').classList.toggle('mode-ukur', ukurAktif);
        if (ukurAktif) {
            if (koordinatAktif) aturKoordinat(false);
            peta.closePopup();
            grupUkur.addTo(peta);
            ukurDobelZoom = peta.doubleClickZoom.enabled();
            if (ukurDobelZoom) peta.doubleClickZoom.disable();
            gambarUkur();
        } else {
            peta.removeLayer(grupUkur);
            if (ukurDobelZoom) { peta.doubleClickZoom.enable(); ukurDobelZoom = false; }
            ukurTitik = [];
            gambarUkur();
        }
    }

    // Go To XY: pusatkan peta ke lintang/bujur derajat desimal. Koma diterima
    // karena papan ketik Indonesia memakai koma sebagai pemisah desimal.
    const grupKoordinat = L.layerGroup();
    let koordinatAktif = false;

    function angkaKoordinat(teks) {
        const bersih = String(teks).trim().replace(/\s+/g, '').replace(',', '.');
        return bersih === '' ? NaN : Number(bersih);
    }

    function perbaruiPusatKoordinat() {
        if (!koordinatAktif) return;
        const c = peta.getCenter();
        koordinatPusat.textContent = 'Pusat peta: ' + Sip.angka(c.lat, 5) + ', ' + Sip.angka(c.lng, 5) + '.';
    }

    function menujuKoordinat() {
        const lat = angkaKoordinat(koordinatLat.value);
        const lon = angkaKoordinat(koordinatLon.value);
        if (koordinatLat.value.trim() === '' || koordinatLon.value.trim() === '') {
            koordinatPesan.textContent = 'Isi lintang dan bujur lebih dahulu.';
            return;
        }
        if (!Number.isFinite(lat) || lat < -90 || lat > 90) {
            koordinatPesan.textContent = 'Lintang harus angka antara -90 dan 90.';
            return;
        }
        if (!Number.isFinite(lon) || lon < -180 || lon > 180) {
            koordinatPesan.textContent = 'Bujur harus angka antara -180 dan 180.';
            return;
        }
        grupKoordinat.clearLayers();
        L.circleMarker([lat, lon], {
            radius: 7, color: '#b42318', weight: 2.5, fillColor: '#fca5a5', fillOpacity: .85
        }).bindPopup('Tujuan: ' + Sip.angka(lat, 5) + ', ' + Sip.angka(lon, 5)).addTo(grupKoordinat);
        grupKoordinat.addTo(peta);
        peta.setView([lat, lon], Math.max(peta.getZoom(), 17));
        koordinatPesan.textContent = 'Menuju ' + Sip.angka(lat, 5) + ', ' + Sip.angka(lon, 5) + '.';
        perbaruiPusatKoordinat();
    }

    function aturKoordinat(hidup) {
        koordinatAktif = !!hidup;
        modeKoordinat.classList.toggle('aktif', koordinatAktif);
        modeKoordinat.setAttribute('aria-pressed', String(koordinatAktif));
        panelKoordinat.hidden = !koordinatAktif;
        if (koordinatAktif) {
            if (ukurAktif) aturUkur(false);
            peta.closePopup();
            perbaruiPusatKoordinat();
            koordinatLat.focus();
        }
    }

    // Navigasi peta: zoom, pan, seluruh data, riwayat pandangan. Riwayat
    // mencatat setiap moveend; melangkah di riwayat tidak menambah catatan baru.
    let riwayatPandangan = [];
    let riwayatPosisi = -1;
    let riwayatDiam = false;
    let riwayatSegeraUlang = false;
    let geserAktif = true;

    function pandanganSama(a, b) {
        return !!a && !!b && a.zoom === b.zoom
            && Math.abs(a.lat - b.lat) < 1e-5 && Math.abs(a.lng - b.lng) < 1e-5;
    }

    function perbaruiTombolRiwayat() {
        navSebelum.disabled = riwayatPosisi <= 0;
        navSesudah.disabled = riwayatPosisi < 0 || riwayatPosisi >= riwayatPandangan.length - 1;
    }

    // Pemusatan otomatis saat halaman dibuka tidak dihitung sebagai satu langkah
    // riwayat: pandangan hasilnya menjadi titik awal, bukan tujuan "Sebelum".
    function aturUlangRiwayat() {
        const c = peta.getCenter();
        riwayatPandangan = [{ lat: c.lat, lng: c.lng, zoom: peta.getZoom() }];
        riwayatPosisi = 0;
        perbaruiTombolRiwayat();
    }

    function catatPandangan() {
        if (riwayatDiam) return;
        const c = peta.getCenter();
        const pos = { lat: c.lat, lng: c.lng, zoom: peta.getZoom() };
        if (riwayatPosisi >= 0 && pandanganSama(riwayatPandangan[riwayatPosisi], pos)) return;
        riwayatPandangan.length = riwayatPosisi + 1;
        riwayatPandangan.push(pos);
        if (riwayatPandangan.length > 60) riwayatPandangan.shift();
        riwayatPosisi = riwayatPandangan.length - 1;
        perbaruiTombolRiwayat();
    }

    function keRiwayat(langkah) {
        const posisi = riwayatPosisi + langkah;
        if (posisi < 0 || posisi >= riwayatPandangan.length) return;
        riwayatPosisi = posisi;
        const pos = riwayatPandangan[posisi];
        riwayatDiam = true;
        peta.setView([pos.lat, pos.lng], pos.zoom, { animate: false });
        riwayatDiam = false;
        perbaruiTombolRiwayat();
    }

    function seluruhData() {
        let batas = batasTampil();
        if (!batas.isValid()) {
            ['batas', 'blok', 'jalan', 'selokan', 'saluran', 'tepiSaluran', 'sungai', 'laporan']
                .forEach((k) => {
                    if (peta.hasLayer(grup[k])) batas.extend(batasGrup(grup[k]));
                });
        }
        if (!batas.isValid()) {
            peta.setView([SIP.peta.lat, SIP.peta.lon], SIP.peta.zoom);
            return;
        }
        peta.flyToBounds(batas.pad(.04), { duration: .45 });
    }

    function aturGeser(hidup) {
        geserAktif = !!hidup;
        navGeser.classList.toggle('aktif', geserAktif);
        navGeser.setAttribute('aria-pressed', String(geserAktif));
        document.getElementById('peta').classList.toggle('tanpa-geser', !geserAktif);
        if (geserAktif) peta.dragging.enable();
        else peta.dragging.disable();
    }

    // Seleksi bidang: klik peta menandai, tabel atribut menampilkan barisnya.
    let pilihAktif = false;
    const terpilih = new Set();

    function gayaTerpilih(p) {
        const g = gayaBidang(p);
        return Object.assign({}, g, {
            color: '#b42318', weight: Math.max(3, (g.weight || 1) + 1.6),
            opacity: 1, fillColor: '#fca5a5',
            fillOpacity: Math.min(1, (g.fillOpacity || 0) + .35), dashArray: null
        });
    }

    function perbaruiPilihan() {
        const n = terpilih.size;
        navPilih.classList.toggle('aktif', pilihAktif);
        navPilih.setAttribute('aria-pressed', String(pilihAktif));
        navPilih.textContent = n ? 'Pilih · ' + Sip.angka(n) : 'Pilih';
        atributTerpilih.textContent = n
            ? Sip.angka(n) + ' bidang terpilih'
            : 'Belum ada yang terpilih';
        atributIsi.querySelectorAll('input[data-atribut]').forEach((k) => {
            k.checked = terpilih.has(Number(k.dataset.atribut));
        });
    }

    function pilihBidang(id, hidup) {
        const v = tampil.get(id);
        if (!v) return;
        const jadi = hidup === undefined ? !terpilih.has(id) : !!hidup;
        if (jadi) terpilih.add(id);
        else terpilih.delete(id);
        v.lapisan.setStyle(jadi ? gayaTerpilih(v.fitur.properties) : gayaBidang(v.fitur.properties));
        if (jadi) v.lapisan.bringToFront();
        perbaruiPilihan();
    }

    function bersihkanPilihan() {
        terpilih.forEach((id) => {
            const v = tampil.get(id);
            if (v) v.lapisan.setStyle(gayaBidang(v.fitur.properties));
        });
        terpilih.clear();
        perbaruiPilihan();
    }

    function aturPilih(hidup) {
        pilihAktif = !!hidup;
        document.getElementById('wadahPeta').classList.toggle('mode-pilih', pilihAktif);
        perbaruiPilihan();
    }

    function gambarAtribut() {
        const fitur = fiturTampil();
        const baris = fitur.slice(0, 500);
        atributJumlah.textContent = Sip.angka(fitur.length) + ' bidang';
        if (!baris.length) {
            atributIsi.innerHTML = '<tr><td colspan="7" class="kosong">'
                + 'Tidak ada bidang yang cocok dengan saringan ini.</td></tr>';
        } else {
            atributIsi.innerHTML = baris.map((f) => {
                const p = f.properties;
                return '<tr data-lompat="' + p.id + '">'
                    + '<td class="tengah"><input type="checkbox" data-atribut="' + p.id + '"'
                    + (terpilih.has(p.id) ? ' checked' : '') + ' aria-label="Pilih '
                    + Sip.esc(p.kode) + '"></td>'
                    + '<td><strong>' + Sip.esc(p.kode) + '</strong>'
                    + (p.kode_internal && p.kode_internal !== p.kode
                        ? '<div class="kecil redup">' + Sip.esc(p.kode_internal) + '</div>' : '')
                    + '</td>'
                    + '<td>' + Sip.esc(p.blok || '—') + '</td>'
                    + '<td>' + Sip.esc(p.hak_nama || p.hak_kode || '—') + '</td>'
                    + '<td>' + Sip.esc(ringkas(p.pemegang || '—')) + '</td>'
                    + '<td class="angka">' + (p.luas ? Sip.angka(p.luas) : '—') + '</td>'
                    + '<td>' + Sip.esc(NAMA_STATUS[p.status] || p.status || '—') + '</td></tr>';
            }).join('');
        }
        atributCatatan.textContent = fitur.length > baris.length
            ? 'Hanya ' + Sip.angka(baris.length) + ' baris pertama yang ditampilkan; '
                + 'persempit saringan untuk melihat sisanya.'
            : 'Tabel mengikuti saringan Saring peta; klik baris untuk memusatkan peta, '
                + 'centang untuk memilih bidang.';
    }

    function aturAtribut(hidup) {
        kartuAtribut.hidden = !hidup;
        navAtribut.classList.toggle('aktif', !!hidup);
        navAtribut.setAttribute('aria-pressed', String(!!hidup));
        if (hidup) gambarAtribut();
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
            bagian.push('Tepi: ' + k.tepi.map((s) => Sip.esc(teksBlok(s))).join(', '));
        }
        if (k.terdekat) {
            bagian.push('Terdekat: ' + Sip.esc(teksBlok(k.terdekat.kode))
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
        if (p.kode_internal && p.kode_internal !== p.kode) tambah('Kode internal', p.kode_internal);
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

    function teksLabel(p) {
        if (setelan.labelIsi === 'kode') return p.kode;
        if (setelan.labelIsi === 'luas') return p.luas ? Sip.angka(p.luas) + ' m²' : p.kode;
        return p.pemegang || p.kode;
    }

    function pasangLabel() {
        if (setelan.label && fiturTampil().length > BATAS_LABEL) {
            setelan.label = false;
            simpanSetelan();
            document.getElementById('labelBidang').checked = false;
            document.getElementById('labelIsi').disabled = true;
            Sip.pesan('Label bidang dimatikan karena tampilan memuat lebih dari '
                + Sip.angka(BATAS_LABEL) + ' bidang. Persempit saringan untuk memakainya.', 'info');
        }
        tampil.forEach((v) => {
            if (v.tipe !== 'tanah') return;
            v.lapisan.unbindTooltip();
            if (setelan.label) {
                v.lapisan.bindTooltip(teksLabel(v.fitur.properties), {
                    permanent: true, direction: 'center', className: 'label-bidang', opacity: 1
                });
                v.lapisan.openTooltip();
            }
        });
    }

    function gambarGaya() {
        tampil.forEach((v) => v.lapisan.setStyle(
            terpilih.has(v.fitur.properties.id) ? gayaTerpilih(v.fitur.properties) : gayaBidang(v.fitur.properties)
        ));
    }

    function segarkanTampilan() {
        const fitur = fiturTampil();
        const tanah = fitur.filter((f) => f.properties.tipe === 'tanah').length;
        hitung.textContent = Sip.angka(tanah) + ' tanah · ' + Sip.angka(fitur.length - tanah) + ' obyek';
        gambarDaftar(fitur);
        pasangLabel();
        gambarLegenda();
        perbaruiPilihan();
        if (!kartuAtribut.hidden) gambarAtribut();
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
        bersihkanPilihan();
        const kunci = tipe === 'tanah' ? 'bidang' : 'lain';
        grup[kunci].clearLayers();
        Array.from(tampil.keys()).forEach((id) => {
            if (tampil.get(id).tipe === tipe) tampil.delete(id);
        });
    }

    function gambarLapisan(tipe) {
        kosongkanLapisan(tipe);
        const fitur = fiturSaringLembar(dataLapisan[tipe] || []);
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
                    if (pilihAktif) {
                        peta.closePopup();
                        pilihBidang(p.id);
                        return;
                    }
                    const batas = l.getBounds();
                    if (batas && batas.isValid()) peta.flyToBounds(batas.pad(.4), { duration: .4 });
                    tandaAktif(p.id);
                });
                l.on('mouseover', () => {
                    if (terpilih.has(p.id)) return;
                    l.setStyle({ weight: tipe === 'tanah' ? 2.6 : 1.8, opacity: 1 });
                    l.bringToFront();
                });
                l.on('mouseout', () => {
                    l.setStyle(terpilih.has(p.id) ? gayaTerpilih(f.properties) : gayaBidang(f.properties));
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
                if (!sudahMemusat) riwayatSegeraUlang = true;
                sudahMemusat = true;
                pusatkanKe(batas);
                window.setTimeout(() => {
                    if (!riwayatSegeraUlang) return;
                    riwayatSegeraUlang = false;
                    aturUlangRiwayat();
                }, 900);
            }
        }

        info.textContent = jumlah
            ? 'Ditampilkan ' + Sip.angka(jumlah) + ' geometri'
                + (terpotong ? ' (terpotong pada batas muat; persempit saringan).' : '.')
            : 'Tidak ada bidang pada saringan ini.';
    }

    function gayaBlok(p) {
        return gayaGaris('blok');
    }

    async function muatBlok() {
        const data = await Sip.api('api/bidang.php', { query: { lapisan: 'blok' } });
        grup.blok.clearLayers();
        (data.features || []).forEach((f) => {
            const geometri = L.geoJSON(f, { pane: 'pane-blok' });
            geometri.kodeBlok = f.properties.kode;
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
                style: gayaGaris('batas'),
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

    // Dua pemanggil (terapkanSetelan dan inisialisasi halaman) bisa memanggil
    // muatJalan() sebelum yang pertama selesai; satu janji dipakai bersama.
    let janjiJalan = null;
    function muatJalan() {
        if (!janjiJalan) {
            janjiJalan = muatJalanSekali().finally(() => { janjiJalan = null; });
        }
        return janjiJalan;
    }

    async function muatJalanSekali() {
        const garis = await Sip.api('api/bidang.php', { query: { lapisan: 'garis-jalan' } });
        grup.jalan.clearLayers();
        kanvasJalan.addTo(peta);
        L.geoJSON(garis, {
            renderer: kanvasJalan,
            pane: 'pane-jalan',
            interactive: false,
            style: gayaGaris('jalan')
        }).addTo(grup.jalan);
    }

    // Pil Selokan membaca garis-selokan 020100 saja; pil Saluran membaca berkas
    // yang sama ditambah 33 baris Jalan yang seluruh segmennya tebing saluran.
    // Satu unduhan dipakai bersama.
    let janjiBerkasSelokan = null;
    function ambilBerkasSelokan() {
        if (!janjiBerkasSelokan) {
            janjiBerkasSelokan = Sip.api('api/bidang.php', { query: { lapisan: 'garis-selokan' } })
                .catch((err) => { janjiBerkasSelokan = null; throw err; });
        }
        return janjiBerkasSelokan;
    }

    let janjiBerkasSaluranJalan = null;
    function ambilBerkasSaluranJalan() {
        if (!janjiBerkasSaluranJalan) {
            janjiBerkasSaluranJalan = Sip.api('api/bidang.php', { query: { lapisan: 'garis-saluran-dari-jalan' } })
                .catch((err) => { janjiBerkasSaluranJalan = null; throw err; });
        }
        return janjiBerkasSaluranJalan;
    }

    async function muatSelokan() {
        const data = await ambilBerkasSelokan();
        grup.selokan.clearLayers();
        kanvasSelokan.addTo(peta);
        L.geoJSON(data, {
            renderer: kanvasSelokan,
            pane: 'pane-selokan',
            interactive: false,
            style: gayaGaris('selokan')
        }).addTo(grup.selokan);
    }

    // Saluran = 689 garis selokan 020100 (semuanya berlabel SALURAN/IRIGASI) + 33
    // baris Jalan yang seluruh segmennya sudah menjadi tebing saluran (1.031,9 m),
    // jadi 722 baris; warna lebih tua.
    async function muatSaluran() {
        const [selokan, tambahan] = await Promise.all([ambilBerkasSelokan(), ambilBerkasSaluranJalan()]);
        const garis = {
            type: 'FeatureCollection',
            features: [...(selokan.features || []), ...(tambahan.features || [])]
        };
        grup.saluran.clearLayers();
        kanvasSaluran.addTo(peta);
        L.geoJSON(garis, {
            renderer: kanvasSaluran,
            pane: 'pane-saluran',
            interactive: false,
            style: gayaGaris('saluran')
        }).addTo(grup.saluran);
    }

    // Tepi saluran = 689 tebing acuan (sama dengan garis selokan 020100) + 161 tebing
    // lawan nyata 020100; warna gelap supaya pasangan di seberang saluran terlihat.
    // Dua pemanggil bisa memuat serentak, jadi satu unduhan dipakai bersama.
    let janjiBerkasTepiSaluran = null;
    function ambilBerkasTepiSaluran() {
        if (!janjiBerkasTepiSaluran) {
            janjiBerkasTepiSaluran = Sip.api('api/bidang.php', { query: { lapisan: 'garis-tepi-saluran' } })
                .catch((err) => { janjiBerkasTepiSaluran = null; throw err; });
        }
        return janjiBerkasTepiSaluran;
    }

    async function muatTepiSaluran() {
        const garis = await ambilBerkasTepiSaluran();
        grup.tepiSaluran.clearLayers();
        kanvasTepiSaluran.addTo(peta);
        L.geoJSON(garis, {
            renderer: kanvasTepiSaluran,
            pane: 'pane-tepi-saluran',
            interactive: false,
            style: gayaGaris('tepiSaluran')
        }).addTo(grup.tepiSaluran);
    }

    async function muatSungai() {
        // Berkas turunan ini tidak ikut git; tanpa berkas, garis sungai saja yang kosong.
        const garis = await Sip.api('api/bidang.php', { query: { lapisan: 'garis-sungai' } })
            .catch(() => ({ features: [] }));
        grup.sungai.clearLayers();
        kanvasSungai.addTo(peta);
        L.geoJSON(garis, {
            renderer: kanvasSungai,
            pane: 'pane-sungai',
            interactive: false,
            style: gayaGaris('sungai')
        }).addTo(grup.sungai);
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
        const pilihanLabel = document.getElementById('labelIsi');
        pilihanLabel.value = setelan.labelIsi;
        pilihanLabel.disabled = !setelan.label;
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
        const kotakSelokan = document.getElementById('tampilSelokan');
        if (kotakSelokan) kotakSelokan.checked = setelan.selokan;
        const kotakSaluran = document.getElementById('tampilSaluran');
        if (kotakSaluran) kotakSaluran.checked = setelan.saluran;
        const kotakTepiSaluran = document.getElementById('tampilTepiSaluran');
        if (kotakTepiSaluran) kotakTepiSaluran.checked = setelan.tepiSaluran;
        const kotakSungai = document.getElementById('tampilSungai');
        if (kotakSungai) kotakSungai.checked = setelan.sungai;

        pakaiDasar(setelan.dasar);
        gambarLegenda();
        aktifkan('laporan', setelan.laporan);
        aktifkan('jalan', setelan.jalan);
        aktifkan('selokan', setelan.selokan);
        aktifkan('saluran', setelan.saluran);
        aktifkan('tepiSaluran', setelan.tepiSaluran);
        aktifkan('sungai', setelan.sungai);
        aktifkan('blok', setelan.blok);
        perbaruiZoom();
        perbaruiSimbolInput();
        terapkanSimbolGaris();
        terapkanLayout();
        if (kotakLaporan && setelan.laporan && !grup.laporan.getLayers().length) {
            muatLaporan().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
        if (kotakJalan && setelan.jalan && !grup.jalan.getLayers().length) {
            muatJalan().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
        if (kotakSelokan && setelan.selokan && !grup.selokan.getLayers().length) {
            muatSelokan().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
        if (kotakSaluran && setelan.saluran && !grup.saluran.getLayers().length) {
            muatSaluran().catch((err) => Sip.pesan(err.message, 'gagal'));
        }
        if (kotakTepiSaluran && setelan.tepiSaluran && !grup.tepiSaluran.getLayers().length) {
            muatTepiSaluran().catch((err) => Sip.pesan(err.message, 'gagal'));
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
            gambarLegenda();
        });
    }

    const kotakSelokan = document.getElementById('tampilSelokan');
    if (kotakSelokan) {
        kotakSelokan.addEventListener('change', (e) => {
            setelan.selokan = e.target.checked;
            simpanSetelan();
            aktifkan('selokan', setelan.selokan);
            if (setelan.selokan && !grup.selokan.getLayers().length) {
                muatSelokan().catch((err) => Sip.pesan(err.message, 'gagal'));
            }
            gambarLegenda();
        });
    }

    const kotakSaluran = document.getElementById('tampilSaluran');
    if (kotakSaluran) {
        kotakSaluran.addEventListener('change', (e) => {
            setelan.saluran = e.target.checked;
            simpanSetelan();
            aktifkan('saluran', setelan.saluran);
            if (setelan.saluran && !grup.saluran.getLayers().length) {
                muatSaluran().catch((err) => Sip.pesan(err.message, 'gagal'));
            }
            gambarLegenda();
        });
    }

    const kotakTepiSaluran = document.getElementById('tampilTepiSaluran');
    if (kotakTepiSaluran) {
        kotakTepiSaluran.addEventListener('change', (e) => {
            setelan.tepiSaluran = e.target.checked;
            simpanSetelan();
            aktifkan('tepiSaluran', setelan.tepiSaluran);
            if (setelan.tepiSaluran && !grup.tepiSaluran.getLayers().length) {
                muatTepiSaluran().catch((err) => Sip.pesan(err.message, 'gagal'));
            }
            gambarLegenda();
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
        document.getElementById('labelIsi').disabled = !setelan.label;
        pasangLabel();
    });
    document.getElementById('labelBlok').addEventListener('change', (e) => {
        setelan.labelBlok = e.target.checked;
        simpanSetelan();
        perbaruiZoom();
    });
    document.getElementById('labelIsi').addEventListener('change', (e) => {
        setelan.labelIsi = e.target.value;
        simpanSetelan();
        pasangLabel();
    });

    const simbolGaris = document.getElementById('simbolGaris');
    let rafSimbol = 0;
    function terapkanBarisSimbol(baris) {
        const g = setelan.garis[baris.dataset.lapis];
        if (!g) return;
        const tebal = Number(baris.querySelector('[data-tebal]').value);
        const pekat = Number(baris.querySelector('[data-pekat]').value);
        g.warna = baris.querySelector('[data-warna]').value;
        g.tebal = tebal;
        g.pekat = pekat;
        baris.querySelector('[data-tebal-nilai]').textContent = tebal.toFixed(1).replace('.', ',');
        baris.querySelector('[data-pekat-nilai]').textContent = pekat + '%';
        if (!rafSimbol) {
            rafSimbol = requestAnimationFrame(() => { rafSimbol = 0; terapkanSimbolGaris(); });
        }
    }
    simbolGaris.addEventListener('input', (e) => {
        const baris = e.target.closest('.baris-simbol');
        if (baris) terapkanBarisSimbol(baris);
    });
    simbolGaris.addEventListener('change', simpanSetelan);

    layoutJudul.addEventListener('input', () => {
        setelan.layout.judul = layoutJudul.value;
        lembarJudulIsi.textContent = layoutJudul.value.trim() || JUDUL_BAWAAN;
    });
    layoutJudul.addEventListener('change', simpanSetelan);
    layoutSubjudul.addEventListener('input', () => {
        setelan.layout.subjudul = layoutSubjudul.value;
        lembarSubjudul.textContent = layoutSubjudul.value.trim() || SUBJUDUL_BAWAAN;
    });
    layoutSubjudul.addEventListener('change', simpanSetelan);
    layoutJenis.addEventListener('change', () => {
        setelan.layout.jenis = layoutJenis.value;
        layoutBlokLapangan.hidden = setelan.layout.jenis !== 'bidang-blok';
        simpanSetelan();
        terapkanJenisLembar(true, true);
    });
    layoutBlok.addEventListener('change', () => {
        setelan.layout.blok = layoutBlok.value;
        simpanSetelan();
        if (setelan.layout.jenis === 'bidang-blok') {
            terapkanJenisLembar(true, judulOtomatis(setelan.layout.judul));
        }
    });
    [[layoutUtara, 'utara'], [layoutLegenda, 'legenda'], [layoutSkalaBatang, 'skalaBatang']].forEach((pasangan) => {
        pasangan[0].addEventListener('change', () => {
            setelan.layout[pasangan[1]] = pasangan[0].checked;
            simpanSetelan();
            terapkanLayout();
        });
    });

    function namaBerkas(ekstensi) {
        const d = new Date();
        const dua = (n) => String(n).padStart(2, '0');
        return 'peta-gedong-' + d.getFullYear() + dua(d.getMonth() + 1) + dua(d.getDate())
            + '-' + dua(d.getHours()) + dua(d.getMinutes()) + '.' + ekstensi;
    }

    function unduhBlob(blob, nama) {
        const url = URL.createObjectURL(blob);
        const tautan = document.createElement('a');
        tautan.href = url;
        tautan.download = nama;
        document.body.appendChild(tautan);
        tautan.click();
        tautan.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1500);
    }

    function kanvasKeBlob(kanvas, jenis, mutu) {
        return new Promise((selesai, gagal) => {
            kanvas.toBlob((blob) => {
                if (blob) selesai(blob);
                else gagal(new Error('kanvas kosong'));
            }, jenis, mutu);
        });
    }

    // Lembar di rasternya ulang dengan html2canvas supaya peta dasar, label,
    // legenda, dan blok judul ikut terekam seperti yang tampil di layar.
    async function eksporGambar(jenis) {
        if (typeof window.html2canvas !== 'function') {
            Sip.pesan('Pustaka ekspor gambar belum termuat; muat ulang halaman.', 'gagal');
            return;
        }
        if (modeTampilan !== 'layout') aturModeTampilan('layout');
        eksporPng.disabled = true;
        eksporJpg.disabled = true;
        salinLegenda();
        perbaruiSkalaLembar();
        perbaruiSkalaBatang();
        try {
            // Satu bingkai jeda agar ubin yang baru tergambar ikut terekam.
            await new Promise((selesai) => window.setTimeout(selesai, 150));
            const wadah = document.getElementById('wadahPeta');
            const kanvas = await window.html2canvas(wadah, {
                backgroundColor: '#ffffff',
                scale: 2,
                useCORS: true,
                imageTimeout: 15000,
                logging: false,
                width: wadah.clientWidth,
                height: wadah.clientHeight,
                windowWidth: document.documentElement.clientWidth,
                windowHeight: document.documentElement.clientHeight,
                scrollX: 0,
                scrollY: 0
            });
            const jpeg = jenis === 'jpeg';
            const blob = await kanvasKeBlob(kanvas, jpeg ? 'image/jpeg' : 'image/png', jpeg ? 0.92 : undefined);
            unduhBlob(blob, namaBerkas(jpeg ? 'jpg' : 'png'));
            Sip.pesan('Lembar peta diekspor sebagai ' + (jpeg ? 'JPG' : 'PNG') + '.');
        } catch (err) {
            Sip.pesan('Ekspor gambar gagal: ' + (err && err.message ? err.message : 'tidak diketahui'), 'gagal');
        } finally {
            eksporPng.disabled = false;
            eksporJpg.disabled = false;
        }
    }

    eksporPng.addEventListener('click', () => eksporGambar('png'));
    eksporJpg.addEventListener('click', () => eksporGambar('jpeg'));

    function susunProyek() {
        const pusat = peta.getCenter();
        return {
            aplikasi: 'SIPETADES',
            jenis: 'proyek-peta',
            versi: 1,
            dibuat: new Date().toISOString(),
            tampilan: {
                lat: Number(pusat.lat.toFixed(6)),
                lon: Number(pusat.lng.toFixed(6)),
                zoom: peta.getZoom()
            },
            setelan: setelan
        };
    }

    unduhProyek.addEventListener('click', () => {
        const isi = JSON.stringify(susunProyek(), null, 2);
        unduhBlob(new Blob([isi], { type: 'application/json' }), 'proyek-' + namaBerkas('json'));
        Sip.pesan('Proyek peta diunduh: pengaturan, simbol, lembar, dan posisi pandangan.');
    });

    muatProyek.addEventListener('click', () => berkasProyek.click());

    berkasProyek.addEventListener('change', async () => {
        const berkas = berkasProyek.files && berkasProyek.files[0];
        berkasProyek.value = '';
        if (!berkas) return;
        try {
            const isi = JSON.parse(await berkas.text());
            if (!isi || isi.jenis !== 'proyek-peta' || typeof isi.setelan !== 'object') {
                throw new Error('Berkas bukan proyek peta SIPETADES.');
            }
            setelan = rapikanSetelan(isi.setelan);
            simpanSetelan();
            terapkanSetelan();
            if (isi.tampilan && typeof isi.tampilan.lat === 'number' && typeof isi.tampilan.lon === 'number') {
                peta.setView([isi.tampilan.lat, isi.tampilan.lon], isi.tampilan.zoom || SIP.peta.zoom);
                sudahMemusat = true;
            }
            muatBidang(true).catch(gagalMuat);
            Sip.pesan('Proyek peta dimuat dari berkas.');
        } catch (err) {
            Sip.pesan(err.message || 'Berkas proyek tidak bisa dibaca.', 'gagal');
        }
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
        setelan = rapikanSetelan({});
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
    peta.on('moveend', () => {
        perbaruiSkalaLembar();
        perbaruiSkalaBatang();
        perbaruiPusatKoordinat();
        if (riwayatSegeraUlang) {
            riwayatSegeraUlang = false;
            aturUlangRiwayat();
        } else {
            catatPandangan();
        }
    });
    modeData.addEventListener('click', () => aturModeTampilan('data'));
    modeLayout.addEventListener('click', () => aturModeTampilan('layout'));
    eksporPeta.addEventListener('click', () => {
        salinLegenda();
        perbaruiSkalaLembar();
        perbaruiSkalaBatang();
        window.setTimeout(() => window.print(), 120);
    });
    modeUkur.addEventListener('click', () => aturUkur(!ukurAktif));
    ukurJarak.addEventListener('click', () => aturJenisUkur('jarak'));
    ukurLuas.addEventListener('click', () => aturJenisUkur('luas'));
    ukurHapus.addEventListener('click', () => { ukurTitik = []; gambarUkur(); });
    ukurTutup.addEventListener('click', () => aturUkur(false));
    peta.on('click', (e) => {
        if (!ukurAktif) return;
        ukurTitik.push(e.latlng);
        gambarUkur();
    });
    modeKoordinat.addEventListener('click', () => aturKoordinat(!koordinatAktif));
    koordinatMenuju.addEventListener('click', menujuKoordinat);
    koordinatTutup.addEventListener('click', () => aturKoordinat(false));
    [koordinatLat, koordinatLon].forEach((el) => {
        el.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            menujuKoordinat();
        });
    });
    navZoomMasuk.addEventListener('click', () => peta.zoomIn());
    navZoomKeluar.addEventListener('click', () => peta.zoomOut());
    navGeser.addEventListener('click', () => aturGeser(!geserAktif));
    navPenuh.addEventListener('click', seluruhData);
    navSebelum.addEventListener('click', () => keRiwayat(-1));
    navSesudah.addEventListener('click', () => keRiwayat(1));
    navPilih.addEventListener('click', () => aturPilih(!pilihAktif));
    navAtribut.addEventListener('click', () => aturAtribut(kartuAtribut.hidden));
    atributTutup.addEventListener('click', () => aturAtribut(false));
    atributPilihTampil.addEventListener('click', () => {
        fiturTampil().slice(0, 500).forEach((f) => {
            const v = tampil.get(f.properties.id);
            if (!v) return;
            terpilih.add(f.properties.id);
            v.lapisan.setStyle(gayaTerpilih(f.properties));
            v.lapisan.bringToFront();
        });
        perbaruiPilihan();
    });
    atributBersihkan.addEventListener('click', bersihkanPilihan);
    atributIsi.addEventListener('change', (e) => {
        const k = e.target.closest('input[data-atribut]');
        if (!k) return;
        pilihBidang(Number(k.dataset.atribut), k.checked);
    });
    atributIsi.addEventListener('click', (e) => {
        const baris = e.target.closest('tr[data-lompat]');
        if (!baris || e.target.closest('input')) return;
        const v = tampil.get(Number(baris.dataset.lompat));
        if (!v) return;
        const batas = v.lapisan.getBounds();
        if (batas && batas.isValid()) {
            // Ubin OSM berhenti di tingkat 19; tanpa batas ini persil kecil berhenti di peta kosong.
            peta.flyToBounds(batas.pad(.6), { duration: .45, maxZoom: 19 });
            tandaAktif(v.fitur.properties.id);
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (koordinatAktif) { aturKoordinat(false); return; }
        if (ukurAktif) aturUkur(false);
    });
    window.addEventListener('resize', rapikanLabelBlok);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(rapikanLabelBlok);
    L.control.scale({ imperial: false, position: 'bottomleft', maxWidth: 120 }).addTo(peta);

    if (setelan.tampilan && typeof setelan.tampilan.lat === 'number' && typeof setelan.tampilan.lon === 'number') {
        peta.setView([setelan.tampilan.lat, setelan.tampilan.lon], setelan.tampilan.zoom || SIP.peta.zoom, { animate: false });
        sudahMemusat = true;
    }
    terapkanSetelan();
    catatPandangan();

    perbaruiSkalaLembar();
    if (new URLSearchParams(window.location.search).get('tampilan') === 'layout') {
        aturModeTampilan('layout');
    }

    aktifkan('batas', true);
    aktifkan('jalan', setelan.jalan);
    aktifkan('selokan', setelan.selokan);
    aktifkan('saluran', setelan.saluran);
    aktifkan('tepiSaluran', setelan.tepiSaluran);
    aktifkan('sungai', setelan.sungai);
    muatBatas().catch((err) => console.warn('Batas gagal dimuat:', err));
    if (setelan.jalan) muatJalan().catch((err) => console.warn('Jalan gagal dimuat:', err));
    if (setelan.selokan) muatSelokan().catch((err) => console.warn('Selokan gagal dimuat:', err));
    if (setelan.saluran) muatSaluran().catch((err) => console.warn('Saluran gagal dimuat:', err));
    if (setelan.tepiSaluran) muatTepiSaluran().catch((err) => console.warn('Tepi saluran gagal dimuat:', err));
    if (setelan.sungai) muatSungai().catch((err) => console.warn('Sungai gagal dimuat:', err));
    muatBidang().catch(gagalMuat).then(() => {
        siapAwal = true;
        if (modeTampilan === 'layout') terapkanJenisLembar(true, !setelan.layout.judul);
    });
})();
</script>
JS;

$naskahH2c = '<script src="' . e(sip_base_url()) . '/assets/js/html2canvas.min.js'
    . e(sip_versi_aset('assets/js/html2canvas.min.js')) . '"></script>' . "\n";
sip_footer(['leaflet' => true, 'kaki' => $naskahH2c . $naskah]);
