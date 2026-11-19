<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$tables = [
    'admins',
    'login_attempts',
    'units',
    'right_categories',
    'districts',
    'villages',
    'borrowers',
    'documents',
    'loans',
    'loan_items',
    'loan_extensions',
    'settings',
    'audit_logs',
];

$sourceHost = getenv('MYSQL_HOST') ?: '127.0.0.1';
$sourcePort = getenv('MYSQL_PORT') ?: '3306';
$sourceName = getenv('MYSQL_NAME') ?: 'btsu';
$sourceUser = getenv('MYSQL_USER') ?: 'root';
$sourcePass = getenv('MYSQL_PASS') ?: '';
$targetHost = getenv('DB_HOST') ?: '';
$targetPort = getenv('DB_PORT') ?: '5432';
$targetName = getenv('DB_NAME') ?: 'postgres';
$targetUser = getenv('DB_USER') ?: 'postgres';
$targetPass = getenv('DB_PASS') ?: '';
$targetSslmode = getenv('DB_SSLMODE') ?: 'require';

if ($targetHost === '' || $targetPass === '') {
    fwrite(STDERR, "Atur DB_HOST dan DB_PASS untuk koneksi PostgreSQL Supabase.\n");
    exit(1);
}

try {
    $source = new PDO(
        "mysql:host={$sourceHost};port={$sourcePort};dbname={$sourceName};charset=utf8mb4",
        $sourceUser,
        $sourcePass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $target = new PDO(
        "pgsql:host={$targetHost};port={$targetPort};dbname={$targetName};sslmode={$targetSslmode}",
        $targetUser,
        $targetPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $target->exec("SET TIME ZONE 'Asia/Jakarta'");

    foreach ($tables as $table) {
        $exists = $target->prepare("SELECT to_regclass(?)");
        $exists->execute(['public.' . $table]);
        $tableExists = $exists->fetchColumn();
        if ($tableExists === false || $tableExists === null) {
            throw new RuntimeException("Tabel public.{$table} tidak ditemukan. Jalankan schema_supabase.sql lebih dahulu.");
        }
        $count = (int) $target->query("SELECT COUNT(*) FROM public.{$table}")->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException("Tabel public.{$table} tidak kosong. Migrasi dibatalkan agar data tidak tertimpa.");
        }
    }

    $target->beginTransaction();
    $migratedCounts = [];
    foreach ($tables as $table) {
        $sourceRows = $source->query("SELECT * FROM `{$table}`");
        $firstRow = $sourceRows->fetch();
        if ($firstRow === false) {
            $migratedCounts[$table] = 0;
            continue;
        }

        $columns = array_values(array_diff(array_keys($firstRow), ['active_document_id']));
        $quotedColumns = implode(', ', array_map(
            static fn(string $column): string => '"' . str_replace('"', '""', $column) . '"',
            $columns
        ));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $target->prepare("INSERT INTO public.\"{$table}\" ({$quotedColumns}) VALUES ({$placeholders})");
        $rowCount = 0;
        do {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $firstRow[$column];
            }
            $insert->execute($values);
            $rowCount++;
        } while (($firstRow = $sourceRows->fetch()) !== false);
        $migratedCounts[$table] = $rowCount;
    }

    foreach ($tables as $table) {
        if ($table === 'settings') {
            continue;
        }
        $target->query(
            "SELECT setval(pg_get_serial_sequence('public.{$table}', 'id'), " .
            "GREATEST(COALESCE(MAX(id), 1), 1), COUNT(*) > 0) FROM public.{$table}"
        );
    }
    $target->commit();

    foreach ($migratedCounts as $table => $count) {
        fwrite(STDOUT, "{$table}: {$count} baris\n");
    }
    fwrite(STDOUT, "Migrasi selesai. Periksa data di Supabase sebelum mengganti aplikasi ke database baru.\n");
} catch (Throwable $error) {
    if (isset($target) && $target instanceof PDO && $target->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, "Migrasi gagal: {$error->getMessage()}\n");
    exit(1);
}
