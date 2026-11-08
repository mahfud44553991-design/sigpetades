<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
wajib_masuk();

sip_header('Panel Tools Digitasi', 'digitasi');
?>

<main class="konten">
    <h1>Panel Tools Digitasi</h1>
    <p class="petunjuk">Pilih tools digitasi yang diperlukan untuk mengelola bidang tanah.</p>

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px; margin-top:20px;">

        <a href="digitasi-baru.php" class="kartu tools-kartu" style="text-decoration:none; color:inherit; transition: transform 0.2s, box-shadow 0.2s;">
            <div style="font-size:48px; margin-bottom:10px;">&#x1f58a;</div>
            <h2 style="margin:0 0 8px 0; font-size:20px; color:var(--primer);">Digitasi Obyek Baru</h2>
            <p style="margin:0; color:var(--redup); font-size:14px; line-height:1.5;">
                Buat bidang tanah baru dengan menggambar polygon di atas peta. 
                Upload gambar/DXF, tentukan koordinat batas, dan klik untuk membuat polygon.
            </p>
            <div style="margin-top:15px; font-size:13px; color:var(--primer); font-weight:600;">
                Mulai Digitasi &rarr;
            </div>
        </a>

        <a href="pecah-bidang.php" class="kartu tools-kartu" style="text-decoration:none; color:inherit; transition: transform 0.2s, box-shadow 0.2s;">
            <div style="font-size:48px; margin-bottom:10px;">&#x2702;</div>
            <h2 style="margin:0 0 8px 0; font-size:20px; color:var(--primer);">Pecah Bidang</h2>
            <p style="margin:0; color:var(--redup); font-size:14px; line-height:1.5;">
                Membagi satu bidang menjadi beberapa bidang baru. 
                Pilih bidang yang akan dipecah, lalu gambar garis pemisah.
            </p>
            <div style="margin-top:15px; font-size:13px; color:var(--primer); font-weight:600;">
                Pecah Bidang &rarr;
            </div>
        </a>

        <a href="penggabungan-bidang.php" class="kartu tools-kartu" style="text-decoration:none; color:inherit; transition: transform 0.2s, box-shadow 0.2s;">
            <div style="font-size:48px; margin-bottom:10px;">&#x1f517;</div>
            <h2 style="margin:0 0 8px 0; font-size:20px; color:var(--primer);">Penggabungan Bidang</h2>
            <p style="margin:0; color:var(--redup); font-size:14px; line-height:1.5;">
                Menggabungkan beberapa bidang yang berdekatan menjadi satu bidang baru. 
                Pilih 2 atau lebih bidang yang akan digabungkan.
            </p>
            <div style="margin-top:15px; font-size:13px; color:var(--primer); font-weight:600;">
                Gabung Bidang &rarr;
            </div>
        </a>

    </div>

    <div class="kartu" style="margin-top:30px;">
        <h2 style="font-size:16px; margin-bottom:10px;">Informasi</h2>
        <ul style="margin:0; padding-left:20px; color:var(--redup); font-size:14px; line-height:1.8;">
            <li><strong>Digitasi Obyek Baru:</strong> Untuk pembuatan bidang tanah baru dari peta/patroli lapangan</li>
            <li><strong>Pecah Bidang:</strong> Digunakan ketika satu bidang harus dibagi (misal: waris, jual sebagian)</li>
            <li><strong>Penggabungan Bidang:</strong> Digunakan ketika beberapa bidang digabung (misal: tukar guling, konsolidasi)</li>
        </ul>
    </div>
</main>

<style>
.tools-kartu:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
}
.tools-kartu h2:hover {
    text-decoration: underline;
}
</style>

<?php sip_footer(); ?>
