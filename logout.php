<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

keluar();
flash_set('info', 'Anda sudah keluar. Sampai jumpa.');
redirect('login.php');
