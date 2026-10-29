<?php

namespace App\Services;

use Closure;
use Dotenv\Dotenv;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

class InstanceDataCopy
{
    /** @param array<string,mixed> $settings */
    public function connect(array $settings, bool $withDatabase = true): PDO
    {
        $dsn = 'mysql:host='.$settings['host'].';port='.$settings['port'].';charset=utf8mb4';
        if ($withDatabase && isset($settings['database'])) {
            $dsn .= ';dbname='.$settings['database'];
        }

        return new PDO($dsn, $settings['username'], $settings['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 15,
        ]);
    }

    public function quote(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    /** @param array<string,string|int> $settings */
    public function rewriteEnvironment(string $environment, array $settings): string
    {
        foreach ($settings as $key => $value) {
            $line = $key.'="'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
            $environment = preg_replace('/^[\t ]*(?:export[\t ]+)?'.preg_quote($key, '/').'[\t ]*=[^\r\n]*(?:\r?\n|$)/m', '', $environment);
            $environment = rtrim($environment).PHP_EOL.$line.PHP_EOL;
        }
        $parsed = Dotenv::parse($environment);
        foreach ($settings as $key => $value) {
            if (($parsed[$key] ?? null) !== (string) $value) {
                throw new RuntimeException('Environment setting did not round-trip safely: '.$key);
            }
        }

        return $environment;
    }

    public function query(PDO $db, string $sql): PDOStatement
    {
        $statement = $db->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Database query could not be prepared.');
        }

        return $statement;
    }

    public function prepare(PDO $db, string $sql): PDOStatement
    {
        $statement = $db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Database statement could not be prepared.');
        }

        return $statement;
    }

    /** @return array{rows:int,sha256:string,chunks:list<string>} */
    public function fingerprint(PDO $db, string $table): array
    {
        return $this->readRows($db, $table);
    }

    /** @return array{rows:int,sha256:string,chunks:list<string>} */
    private function readRows(PDO $db, string $table, ?Closure $consume = null): array
    {
        $hashes = [];
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $rows = $this->query($db, 'SELECT * FROM '.$table);
            while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $hashes[] = hash('sha256', serialize(array_values($row)));
                if ($consume !== null) {
                    $consume($row);
                }
            }
            $rows->closeCursor();
        } finally {
            $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        sort($hashes, SORT_STRING);
        $chunks = array_map(fn (array $chunk): string => hash('sha256', implode('', $chunk)), array_chunk($hashes, 1000));

        return ['rows' => count($hashes), 'sha256' => hash('sha256', implode('', $chunks)), 'chunks' => $chunks];
    }

    /** @return array{rows:int,sha256:string,chunks:list<string>} */
    public function copyTable(PDO $source, PDO $target, string $sourceTable, string $targetTable): array
    {
        $insert = null;
        $target->beginTransaction();
        try {
            $fingerprint = $this->readRows($source, $sourceTable, function (array $row) use ($target, $targetTable, &$insert): void {
                if ($insert === null) {
                    $columns = implode(',', array_map($this->quote(...), array_keys($row)));
                    $parameters = implode(',', array_fill(0, count($row), '?'));
                    $insert = $this->prepare($target, "INSERT INTO {$targetTable} ({$columns}) VALUES ({$parameters})");
                }
                $insert->execute(array_values($row));
            });
            $target->commit();

            return $fingerprint;
        } catch (Throwable $error) {
            if ($target->inTransaction()) {
                $target->rollBack();
            }
            throw $error;
        }
    }

    public function tableDdl(string $ddl, string $name, string $schema): string
    {
        $quoted = $this->quote($name);
        $ddl = str_replace('CREATE TABLE '.$quoted, 'CREATE TABLE '.$this->quote($schema).'.'.$quoted, $ddl);
        if (! str_starts_with($ddl, 'CREATE TABLE '.$this->quote($schema).'.')) {
            throw new RuntimeException('Unrecognized CREATE TABLE definition.');
        }
        if (str_contains(strtoupper($ddl), 'ENGINE=INNODB')) {
            $ddl = preg_replace('/ROW_FORMAT=(?:COMPACT|REDUNDANT)/i', 'ROW_FORMAT=DYNAMIC', $ddl);
            if (! str_contains(strtoupper($ddl), 'ROW_FORMAT=')) {
                $ddl .= ' ROW_FORMAT=DYNAMIC';
            }
        }

        return $ddl;
    }
}
