<?php
declare(strict_types=1);

/** Koneksi MariaDB tunggal untuk seluruh request. */
function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $cfg = sip_config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset'] ?? 'utf8mb4'
    );

    try {
        $connection = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $exception) {
        error_log('SIPETADES gagal koneksi database: ' . $exception->getMessage());
        throw new RuntimeException(sprintf(
            'Tidak bisa terhubung ke database "%s" di %s:%d. Pastikan MySQL/MariaDB XAMPP '
            . 'berjalan dan sql/01_skema.sql sudah diimport. Sebab asli: %s',
            $cfg['name'],
            $cfg['host'],
            $cfg['port'],
            $exception->getMessage()
        ));
    }

    return $connection;
}

/** Jalankan statement prepared dan kembalikan hasilnya. */
function db_query(string $sql, array $param = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($param);
    return $statement;
}

function db_all(string $sql, array $param = []): array
{
    return db_query($sql, $param)->fetchAll();
}

function db_one(string $sql, array $param = []): ?array
{
    $row = db_query($sql, $param)->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $param = []): mixed
{
    $value = db_query($sql, $param)->fetchColumn();
    return $value === false ? null : $value;
}

function db_exec(string $sql, array $param = []): int
{
    return db_query($sql, $param)->rowCount();
}

/**
 * Bangun klausa WHERE dinamis. Setiap filter ditulis sebagai kondisi dengan
 * satu tanda tanya, misalnya 'b.tipe = ?' atau 'b.luas_m2 >= ?', plus
 * nilainya. Nilai tidak pernah disisipkan ke SQL, selalu lewat placeholder.
 *
 * @param array<int, array{0: string, 1: mixed}> $filter pasangan (kondisi SQL, nilai)
 * @return array{0: string, 1: array} potongan SQL dan parameternya
 */
function db_where(array $filter, string $awalan = 'WHERE'): array
{
    $potongan = [];
    $param = [];
    foreach ($filter as $index => [$kondisi, $nilai]) {
        if ($nilai === null || $nilai === '') {
            continue;
        }
        $nama = 'p' . $index;
        $potongan[] = str_replace('?', ':' . $nama, $kondisi);
        $param[$nama] = $nilai;
    }

    return [
        $potongan === [] ? '' : $awalan . ' ' . implode(' AND ', $potongan),
        $param,
    ];
}
