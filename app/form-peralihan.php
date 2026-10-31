<?php
declare(strict_types=1);

/**
 * Sebagian formulir peralihan yang dipakai halaman daftar (catat baru) dan
 * detail (perbarui). Disisipkan di dalam elemen <form> halaman, jadi tidak
 * punya tag form sendiri. Variabel $p berisi data lama bila sedang mengedit.
 */
$isiLama = static fn(string $kunci): string => (string) ($p[$kunci] ?? '');
?>
                <div class="baris-form">
                    <div class="lapangan">
                        <label for="p-tanggal">Tanggal peralihan <span class="redup">*</span></label>
                        <input id="p-tanggal" name="tanggal" type="date" required
                               value="<?= e($isiLama('tanggal') ?: date('Y-m-d')) ?>">
                    </div>
                    <div class="lapangan">
                        <label for="p-jenis">Jenis peralihan <span class="redup">*</span></label>
                        <select id="p-jenis" name="jenis_peralihan_id" required>
                            <?php foreach (daftar_jenis_peralihan() as $j): ?>
                                <option value="<?= e($j['id']) ?>"
                                    <?= (int) ($p['jenis_peralihan_id'] ?? 0) === (int) $j['id'] ? 'selected' : '' ?>>
                                    <?= e($j['nama']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="baris-form">
                    <div class="lapangan">
                        <label for="p-pertama">Pihak pertama (asal) <span class="redup">*</span></label>
                        <input id="p-pertama" name="pihak_pertama" maxlength="150" required value="<?= e($isiLama('pihak_pertama')) ?>">
                    </div>
                    <div class="lapangan">
                        <label for="p-kedua">Pihak kedua (tujuan) <span class="redup">*</span></label>
                        <input id="p-kedua" name="pihak_kedua" maxlength="150" required value="<?= e($isiLama('pihak_kedua')) ?>">
                    </div>
                </div>
                <div class="baris-form">
                    <div class="lapangan">
                        <label for="p-akta">Nomor akta / PPAT / SK</label>
                        <input id="p-akta" name="no_akta" maxlength="80" value="<?= e($isiLama('no_akta')) ?>">
                    </div>
                    <div class="lapangan">
                        <label for="p-pejabat">Pejabat yang menerbitkan</label>
                        <input id="p-pejabat" name="pejabat" maxlength="120" value="<?= e($isiLama('pejabat')) ?>">
                    </div>
                </div>
                <div class="baris-form">
                    <div class="lapangan">
                        <label for="p-bidang">Nomor bidang</label>
                        <input id="p-bidang" name="bidang_id" type="number" min="1" value="<?= e($isiLama('bidang_id')) ?>">
                        <p class="petunjuk">Isi id bidang hasil digitasi, atau cari lewat daftar bidang.</p>
                    </div>
                    <div class="lapangan">
                        <label for="p-nop">NOP</label>
                        <input id="p-nop" name="nop" maxlength="24" value="<?= e($isiLama('nop')) ?>">
                    </div>
                </div>
                <div class="baris-form">
                    <div class="lapangan">
                        <label for="p-luas">Luas dialihkan (m&sup2;)</label>
                        <input id="p-luas" name="luas_m2" type="number" step="0.01" min="0" value="<?= e($isiLama('luas_m2')) ?>">
                    </div>
                    <div class="lapangan">
                        <label for="p-nilai">Nilai peralihan (Rp)</label>
                        <input id="p-nilai" name="nilai_rp" type="number" step="1" min="0" value="<?= e($isiLama('nilai_rp')) ?>">
                    </div>
                </div>
