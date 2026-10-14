#!/usr/bin/php
<?php

// migrate.php — CLI schema tool.
//
//   php bin/migrate.php init     Drop every table, then import schema.sql
//   php bin/migrate.php import   Import schema.sql (CREATE IF NOT EXISTS —
//                                safe on a populated database)
//   php bin/migrate.php status   List tables and row counts
//
// schema.sql is the single source of truth; there are no migration files.

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/bootstrap.php';
/** @var PDO $pdo */

$cmd = $argv[1] ?? 'help';
$schemaFile = APP_ROOT . '/database/schema.sql';

if (!is_file($schemaFile)) {
    fwrite(STDERR, "schema.sql not found at $schemaFile\n");
    exit(1);
}

switch ($cmd) {
    case 'init':
        echo "Dropping all tables...\n";
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $pdo->exec("DROP TABLE IF EXISTS `$t`");
            echo "  dropped $t\n";
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        // fall through to import

    case 'import':
        echo "Importing schema.sql...\n";
        $sql = file_get_contents($schemaFile);
        $stmts = split_sql_statements($sql);
        foreach ($stmts as $stmt) {
            $pdo->exec($stmt);
        }
        echo '  executed ' . count($stmts) . " statements\n";
        break;

    case 'status':
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            echo str_pad($t, 28) . $n . "\n";
        }
        break;

    default:
        echo "Usage: php bin/migrate.php [init|import|status]\n";
        exit($cmd === 'help' ? 0 : 1);
}
