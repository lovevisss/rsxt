<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

class StageLegacy extends Command
{
    protected $signature = 'personnel:stage {--execute : Copy the legacy schema and data into a new schema on the auxiliary server}';

    protected $description = 'Prepare an isolated copy of legacy tables for write-path testing';

    public function handle(): int
    {
        $stage = (string) config('personnel.stage_database');
        $legacy = (string) config('database.connections.legacy.database');
        $auxiliary = (string) config('database.connections.mysql.database');
        if (! preg_match('/^[a-z][a-z0-9_]{2,63}$/', $stage) || in_array($stage, [$legacy, $auxiliary], true)) {
            throw new RuntimeException('Unsafe staging database name.');
        }
        if (config('database.connections.legacy.host') === config('database.connections.mysql.host')) {
            throw new RuntimeException('The staging and legacy servers must be different.');
        }

        $source = DB::connection('legacy');
        $destination = DB::connection('mysql');
        $tables = $source->select('SELECT TABLE_NAME AS name, TABLE_TYPE AS kind FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_TYPE, TABLE_NAME', [$legacy]);
        $baseTables = array_values(array_filter($tables, fn ($table) => $table->kind === 'BASE TABLE'));
        $views = count($tables) - count($baseTables);
        $existing = $destination->selectOne('SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$stage]);
        if ((int) $existing->n !== 0) {
            throw new RuntimeException("Staging schema {$stage} already exists; it will not be overwritten.");
        }

        $this->line(sprintf('Source: %s (%d tables, %d views). Destination: %s.', $legacy, count($baseTables), $views, $stage));
        if (! $this->option('execute')) {
            $this->warn('Dry run only. Add --execute to create and populate the staging schema.');

            return self::SUCCESS;
        }

        $target = $destination->getPdo();
        $target->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $target->exec('SET SESSION innodb_strict_mode = OFF');
        $target->exec('SET FOREIGN_KEY_CHECKS = 0');
        $target->exec("CREATE DATABASE `{$stage}` CHARACTER SET gbk COLLATE gbk_chinese_ci");

        $name = '[initialization]';
        try {
            foreach ($baseTables as $index => $table) {
                $name = (string) $table->name;
                $quotedName = '`'.str_replace('`', '``', $name).'`';
                $create = $source->selectOne("SHOW CREATE TABLE {$quotedName}");
                $ddl = (array) $create;
                $statement = str_replace("CREATE TABLE {$quotedName}", "CREATE TABLE `{$stage}`.{$quotedName}", (string) ($ddl['Create Table'] ?? ''));
                if (! str_contains($statement, "CREATE TABLE `{$stage}`.")) {
                    throw new RuntimeException("Could not qualify staging table: {$name}");
                }
                if (str_contains(strtoupper($statement), 'ENGINE=INNODB')) {
                    $statement = preg_replace('/ROW_FORMAT=(?:COMPACT|REDUNDANT)/i', 'ROW_FORMAT=DYNAMIC', $statement);
                    if (! str_contains(strtoupper($statement), 'ROW_FORMAT=')) {
                        $statement .= ' ROW_FORMAT=DYNAMIC';
                    }
                }
                $target->exec($statement);

                $reader = $source->getPdo();
                $reader->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $rows = $reader->query("SELECT * FROM {$quotedName}", PDO::FETCH_ASSOC);
                if ($rows === false) {
                    throw new RuntimeException("Could not read legacy table: {$name}");
                }
                $insert = null;
                $count = 0;
                $target->beginTransaction();
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    if ($insert === null) {
                        $columns = array_keys($row);
                        $quoted = implode(',', array_map(fn ($column) => '`'.str_replace('`', '``', $column).'`', $columns));
                        $placeholders = implode(',', array_fill(0, count($columns), '?'));
                        $insert = $target->prepare("INSERT INTO `{$stage}`.{$quotedName} ({$quoted}) VALUES ({$placeholders})");
                    }
                    $insert->execute(array_values($row));
                    $count++;
                }
                $rows->closeCursor();
                $reader->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                $target->commit();
                $this->line(sprintf('%d/%d %s: %d rows', $index + 1, count($baseTables), $name, $count));
            }
        } catch (Throwable $error) {
            if ($target->inTransaction()) {
                $target->rollBack();
            }
            $target->exec("DROP DATABASE `{$stage}`");
            throw new RuntimeException("Staging copy failed at {$name}: {$error->getMessage()}", 0, $error);
        } finally {
            $target->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->warn("Copied base tables. {$views} legacy views require separate compatibility review before workflow tests.");

        return self::SUCCESS;
    }
}
