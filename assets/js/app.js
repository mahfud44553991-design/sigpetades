/* ==========================================================================
   SIPETADES — utilitas bersama
   Dipakai semua halaman. Tidak Dependensi selain Leaflet di halaman peta.
   ========================================================================== */
(function () {
    'use strict';

    const SIP = window.SIP || { base: '', csrf: '', masuk: false, kelola: false, peta: {}, data: {} };
    window.SIP = SIP;

    /* -------------------------------------------------- bilah menu mobile */
    const tombolMenu = document.querySelector('[data-buka-menu]');
    const menu = document.querySelector('[data-menu]');
    if (tombolMenu && menu) {
        tombolMenu.addEventListener('click', function () {
            const terbuka = menu.classList.toggle('terbuka');
            tombolMenu.setAttribute('aria-expanded', terbuka ? 'true' : 'false');
        });
    }

    /* -------------------------------------------------- pembantu umum */
    function url(path, params) {
        const dasar = SIP.base + '/' + String(path).replace(/^\/+/, '');
        if (!params) return dasar;
        const query = new URLSearchParams();
        Object.keys(params).forEach(function (k) {
            const v = params[k];
            if (v !== null && v !== undefined && v !== '') query.set(k, v);
        });
        const teks = query.toString();
        return teks ? dasar + '?' + teks : dasar;
    }

    /**
     * Panggil endpoint JSON.
     * options: { method, query, json, body, headers }
     */
    async function api(path, options) {
        const opts = options || {};
        const metode = (opts.method || 'GET').toUpperCase();
        const headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});

        if (metode !== 'GET' && metode !== 'HEAD') {
            headers['X-CSRF-Token'] = SIP.csrf;
        }

        let body = opts.body;
        if (opts.json !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(opts.json);
        }

        let response;
        try {
            response = await fetch(url(path, opts.query), {
                method: metode,
                headers: headers,
                body: body,
                credentials: 'same-origin',
                cache: 'no-store'
            });
        } catch (error) {
            throw new Error('Tidak dapat menghubungi server. Periksa koneksi lalu muat ulang halaman.');
        }

        const teks = await response.text();
        let isi;
        try { isi = teks ? JSON.parse(teks) : {}; }
        catch (error) {
            throw new Error('Balasan server tidak dapat dibaca (HTTP ' + response.status + ').');
        }

        if (!response.ok || isi.status === 'gagal') {
            const error = new Error(isi.error || 'Permintaan gagal (HTTP ' + response.status + ').');
            error.status = response.status;
            error.isi = isi;
            throw error;
        }
        return isi;
    }

    function kirimFormulir(form, payloadTambahan) {
        const data = new FormData(form);
        const isi = {};
        data.forEach(function (nilai, kunci) { isi[kunci] = nilai; });
        return Object.assign(isi, payloadTambahan || {});
    }

    /* -------------------------------------------------- notifikasi */
    function pesan(teks, jenis) {
        const wadah = document.querySelector('.seru-wadah') || document.querySelector('main');
        if (!wadah) return;
        const kotak = document.createElement('div');
        kotak.className = 'seru ' + (jenis === 'gagal' ? 'seru-bahaya' : jenis === 'info' ? 'seru-info' : 'seru-seru');
        kotak.setAttribute('role', 'status');
        kotak.textContent = teks;
        wadah.insertBefore(kotak, wadah.firstChild);
        setTimeout(function () { kotak.remove(); }, 7000);
        kotak.addEventListener('click', function () { kotak.remove(); });
    }

    /* -------------------------------------------------- modal */
    function bukaModal(id) {
        const el = typeof id === 'string' ? document.getElementById(id) : id;
        if (!el) return;
        el.classList.add('tampil');
        document.body.style.overflow = 'hidden';
        const fokus = el.querySelector('input:not([type=hidden]), select, textarea, button');
        if (fokus) setTimeout(function () { fokus.focus(); }, 40);
    }

    function tutupModal(id) {
        const el = typeof id === 'string' ? document.getElementById(id) : id;
        if (!el) return;
        el.classList.remove('tampil');
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (event) {
        const pembuka = event.target.closest('[data-modal-buka]');
        if (pembuka) { event.preventDefault(); bukaModal(pembuka.getAttribute('data-modal-buka')); return; }

        const penutup = event.target.closest('[data-modal-tutup]');
        if (penutup) { event.preventDefault(); tutupModal(penutup.getAttribute('data-modal-tutup') || penutup.closest('.modal-latar')); return; }

        if (event.target.classList && event.target.classList.contains('modal-latar')) tutupModal(event.target);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal-latar.tampil').forEach(tutupModal);
        }
    });

    /* -------------------------------------------------- format tampilan */
    function angka(nilai, desimal) {
        const d = desimal || 0;
        const t = Number(nilai || 0);
        return t.toLocaleString('id-ID', { minimumFractionDigits: d, maximumFractionDigits: d });
    }

    function rupiah(nilai) { return 'Rp ' + angka(nilai, 0); }

    function tanggalTeks(iso) {
        if (!iso) return '—';
        const waktu = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(waktu.getTime())) return '—';
        const bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        return waktu.getDate() + ' ' + bulan[waktu.getMonth()] + ' ' + waktu.getFullYear();
    }

    function esc(teks) {
        return String(teks === null || teks === undefined ? '' : teks)
            .replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
    }

    function kosongkan(el) {
        const kotak = el && el.querySelector ? el : document.getElementById(el);
        if (kotak) kotak.innerHTML = '';
        return kotak;
    }

    function tampilanKosong(el, judul, penjelasan) {
        const kotak = kosongkan(el);
        if (kotak) {
            kotak.innerHTML = '<div class="kosong"><strong>' + esc(judul) + '</strong>' + esc(penjelasan || '') + '</div>';
        }
    }

    /* -------------------------------------------------- konfirmasi berbahaya */
    document.addEventListener('submit', function (event) {
        const form = event.target;
        const peringatan = form.getAttribute('data-konfirmasi');
        if (peringatan && !window.confirm(peringatan)) {
            event.preventDefault();
            return;
        }
        if (peringatan) {
            form.querySelectorAll('button[type=submit]').forEach(function (b) {
                b.disabled = true;
                b.insertAdjacentHTML('beforeend', ' <span class="tanda-muat"></span>');
                setTimeout(function () { b.disabled = false; }, 8000);
            });
        }
    });

    /* -------------------------------------------------- tab sederhana */
    document.addEventListener('click', function (event) {
        const tab = event.target.closest('[data-tab]');
        if (!tab) return;
        const nama = tab.getAttribute('data-tab');
        document.querySelectorAll('[data-tab]').forEach(function (t) { t.classList.remove('aktif'); });
        tab.classList.add('aktif');
        document.querySelectorAll('[data-panel]').forEach(function (p) {
            p.hidden = p.getAttribute('data-panel') !== nama;
        });
    });

    /* -------------------------------------------------- export */
    function unduhBerkas(nama, isi, tipe) {
        const blob = new Blob([isi], { type: tipe || 'application/json;charset=utf-8' });
        const tautan = document.createElement('a');
        tautan.href = URL.createObjectURL(blob);
        tautan.download = nama;
        document.body.appendChild(tautan);
        tautan.click();
        setTimeout(function () {
            URL.revokeObjectURL(tautan.href);
            tautan.remove();
        }, 1500);
    }

    window.Sip = {
        url: url, api: api, pesan: pesan, kirimFormulir: kirimFormulir,
        bukaModal: bukaModal, tutupModal: tutupModal,
        angka: angka, rupiah: rupiah, tanggal: tanggalTeks, esc: esc,
        kosongkan: kosongkan, tampilanKosong: tampilanKosong, unduhBerkas: unduhBerkas
    };
})();
