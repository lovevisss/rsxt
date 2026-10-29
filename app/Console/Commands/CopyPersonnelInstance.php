<?php

namespace App\Console\Commands;

use App\Services\InstanceDataCopy;
use Illuminate\Console\Command;
use PDO;
use RuntimeException;
use Throwable;

class CopyPersonnelInstance extends Command
{
    protected $signature = 'personnel:copy-instance {--execute} {--resume} {--verify}';

    protected $description = 'Copy read-only source snapshots into a new instance; default is inventory only';

    /** @var array<string,mixed> */
    private array $manifest = [];

    public function handle(InstanceDataCopy $copy): int
    {
        $targetSettings = config('data_copy.target');
        $target = $copy->connect($targetSettings, false);
        foreach (config('data_copy.sources') as $source) {
            if ($source['host'] === $targetSettings['host']) {
                throw new RuntimeException('Source and target instances must be different.');
            }
        }
        $path = config('data_copy.manifest');
        if (is_file($path)) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Snapshot manifest could not be read.');
            }
            $this->manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if ($this->manifest['target_host'] !== $targetSettings['host']) {
                throw new RuntimeException('Manifest belongs to another target.');
            }
        }
        if ($this->option('verify')) {
            return $this->verify($target, $copy);
        }
        if (! $this->option('execute')) {
            foreach (config('data_copy.sources') as $schema => $settings) {
                $source = $copy->connect($settings);
                $tables = $copy->query($source, 'SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
                $this->line($schema.': '.count($tables).' objects; source '.$settings['host'].'; target '.$targetSettings['host']);
            }
            $this->warn('Inventory only. --execute creates destination schemas; --resume requires this run manifest.');

            return self::SUCCESS;
        }
        if ($this->manifest !== [] && ! $this->option('resume')) {
            throw new RuntimeException('Manifest already exists. Use --verify or --resume.');
        }
        if ($this->manifest === []) {
            $this->manifest = ['target_host' => $targetSettings['host'], 'started_at' => now()->toIso8601String(), 'schemas' => [], 'attempts' => []];
        }
        $this->manifest['attempts'][] = now()->toIso8601String();
        $this->save();
        $target->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $target->exec('SET SESSION innodb_strict_mode = OFF');
        $target->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        foreach (config('data_copy.sources') as $schema => $settings) {
            $this->copySchema($schema, $settings, $target, $copy);
        }
        $target->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');
        $this->manifest['completed_at'] = now()->toIso8601String();
        $this->save();

        return $this->verify($target, $copy);
    }

    /** @param array<string,mixed> $settings */
    private function copySchema(string $schema, array $settings, PDO $target, InstanceDataCopy $copy): void
    {
        $quotedSchema = $copy->quote($schema);
        $source = $copy->connect($settings);
        $source->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $objects = $copy->query($source, 'SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
            if (! isset($this->manifest['schemas'][$schema])) {
                $check = $copy->prepare($target, 'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
                $check->execute([$schema]);
                if ((int) $check->fetchColumn() !== 0) {
                    throw new RuntimeException("Target schema {$schema} already exists and is not owned by this manifest.");
                }
                $target->exec("CREATE DATABASE {$quotedSchema} CHARACTER SET ".($schema === 'uchr' ? 'gbk COLLATE gbk_chinese_ci' : 'utf8mb4 COLLATE utf8mb4_unicode_ci'));
                $this->manifest['schemas'][$schema] = ['source_host' => $settings['host'], 'source_version' => $copy->query($source, 'SELECT VERSION()')->fetchColumn(), 'snapshot_started_at' => now()->toIso8601String(), 'tables' => [], 'views' => []];
                $this->save();
            }
            foreach ($objects as [$name, $kind]) {
                if ($kind !== 'BASE TABLE') {
                    continue;
                }
                $qualified = $quotedSchema.'.'.$copy->quote($name);
                $entry = $this->manifest['schemas'][$schema]['tables'][$name] ?? null;
                if (($entry['state'] ?? '') === 'complete') {
                    if ($copy->fingerprint($target, $qualified) !== $entry['fingerprint']) {
                        throw new RuntimeException("Completed target table changed: {$schema}.{$name}");
                    }

                    continue;
                }
                if ($entry !== null) {
                    $target->exec('DROP TABLE IF EXISTS '.$qualified);
                }
                $definition = $copy->query($source, 'SHOW CREATE TABLE '.$copy->quote($name))->fetch(PDO::FETCH_NUM);
                if ($definition === false || ! isset($definition[1])) {
                    throw new RuntimeException('Source table definition is unavailable.');
                }
                $ddl = $definition[1];
                $this->manifest['schemas'][$schema]['tables'][$name] = ['state' => 'copying', 'started_at' => now()->toIso8601String()];
                $this->save();
                $target->exec($copy->tableDdl($ddl, $name, $schema));
                $reset = $schema === 'rsxt' && in_array($name, config('data_copy.reset_tables'), true);
                $fingerprint = $reset ? $copy->fingerprint($target, $qualified) : $copy->copyTable($source, $target, $copy->quote($name), $qualified);
                if ($copy->fingerprint($target, $qualified) !== $fingerprint) {
                    throw new RuntimeException("Fingerprint mismatch: {$schema}.{$name}");
                }
                $this->manifest['schemas'][$schema]['tables'][$name] = ['state' => 'complete', 'fingerprint' => $fingerprint, 'reset_transient_data' => $reset];
                $this->save();
                $this->line("{$schema}.{$name}: {$fingerprint['rows']} rows verified");
            }
            if ($schema === 'uchr') {
                $target->exec('ALTER TABLE '.$quotedSchema.'.`user` ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
            }
            $views = $copy->prepare($source, 'SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=?');
            $views->execute([$settings['database']]);
            $pending = $views->fetchAll(PDO::FETCH_ASSOC);
            while ($pending !== []) {
                $failed = [];
                foreach ($pending as $view) {
                    $name = $view['TABLE_NAME'];
                    $definition = str_replace($copy->quote($settings['database']).'.', $quotedSchema.'.', $view['VIEW_DEFINITION']);
                    try {
                        $target->exec('CREATE OR REPLACE SQL SECURITY INVOKER VIEW '.$quotedSchema.'.'.$copy->quote($name).' AS '.$definition);
                        $this->manifest['schemas'][$schema]['views'][$name] = ['rows' => $copy->query($target, 'SELECT COUNT(*) FROM '.$quotedSchema.'.'.$copy->quote($name))->fetchColumn()];
                        $this->save();
                    } catch (Throwable $error) {
                        $failed[] = $view;
                        $this->warn('View deferred: '.$name.' ('.$error->getCode().')');
                    }
                }
                if (count($failed) === count($pending)) {
                    throw new RuntimeException('Views could not be recreated: '.implode(', ', array_column($failed, 'TABLE_NAME')));
                }
                $pending = $failed;
            }
            $source->rollBack();
            $myisam = $copy->prepare($source, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND ENGINE='MyISAM'");
            $myisam->execute([$settings['database']]);
            foreach ($myisam->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $this->manifest['schemas'][$schema]['tables'][$name]['source_drift_after_copy'] = $copy->fingerprint($source, $copy->quote($name)) !== $this->manifest['schemas'][$schema]['tables'][$name]['fingerprint'];
            }
            $this->manifest['schemas'][$schema]['snapshot_finished_at'] = now()->toIso8601String();
            $this->save();
        } finally {
            if ($source->inTransaction()) {
                $source->rollBack();
            }
        }
    }

    private function verify(PDO $target, InstanceDataCopy $copy): int
    {
        if (! isset($this->manifest['completed_at'])) {
            throw new RuntimeException('No completed snapshot manifest is available.');
        }
        $verified = 0;
        $target->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        foreach ($this->manifest['schemas'] as $schema => $data) {
            foreach ($data['tables'] as $name => $entry) {
                $table = $copy->quote($schema).'.'.$copy->quote($name);
                if ($schema === 'rsxt' && $name === 'migrations') {
                    $allowed = implode(',', array_map($target->quote(...), config('data_copy.post_copy_migrations')));
                    $table = '(SELECT * FROM '.$table.' WHERE migration NOT IN ('.$allowed.')) AS captured_migrations';
                }
                if ($copy->fingerprint($target, $table) !== $entry['fingerprint']) {
                    throw new RuntimeException("Verification failed: {$schema}.{$name}");
                }
                $verified++;
            }
            foreach ($data['views'] as $name => $entry) {
                $rows = $copy->query($target, 'SELECT COUNT(*) FROM '.$copy->quote($schema).'.'.$copy->quote($name))->fetchColumn();
                if ($rows !== $entry['rows']) {
                    throw new RuntimeException("View row-count verification failed: {$schema}.{$name}");
                }
            }
        }
        $this->manifest['verified_at'] = now()->toIso8601String();
        $this->save();
        $this->info("Verified {$verified} tables against the captured snapshot, including 1,000-row hash chunks.");

        return self::SUCCESS;
    }

    private function save(): void
    {
        $path = config('data_copy.manifest');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path.'.tmp', json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename($path.'.tmp', $path);
    }
}
