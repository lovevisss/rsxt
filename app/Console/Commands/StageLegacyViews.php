<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class StageLegacyViews extends Command
{
    protected $signature = 'personnel:stage-views {--execute : Create legacy views over staging tables}';

    protected $description = 'Rebuild legacy views against the isolated staging copy';

    public function handle(): int
    {
        $legacy = (string) config('database.connections.legacy.database');
        $stage = (string) config('personnel.stage_database');
        $auxiliary = (string) config('database.connections.mysql.database');
        if (! preg_match('/^[a-z][a-z0-9_]{2,63}$/', $stage) || in_array($stage, [$legacy, $auxiliary], true)) {
            throw new RuntimeException('Unsafe staging database name.');
        }
        if (config('database.connections.legacy.host') === config('database.connections.mysql.host')) {
            throw new RuntimeException('The staging and legacy servers must be different.');
        }

        $source = DB::connection('legacy');
        $destination = DB::connection('mysql');
        $views = $source->select('SELECT TABLE_NAME AS name, VIEW_DEFINITION AS definition FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$legacy]);
        usort($views, fn ($a, $b) => $a->name === 'kucunt' ? -1 : ($b->name === 'kucunt' ? 1 : strcmp($a->name, $b->name)));
        $existing = $destination->select('SELECT TABLE_NAME AS name FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?', [$stage]);
        if ($existing !== []) {
            throw new RuntimeException('Staging views already exist; refusing to overwrite them.');
        }
        $schema = $destination->selectOne('SELECT COUNT(*) AS n FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$stage]);
        if ((int) $schema->n !== 1) {
            throw new RuntimeException('Staging schema does not exist. Run personnel:stage first.');
        }

        $this->line(sprintf('Found %d legacy views for staging.', count($views)));
        if (! $this->option('execute')) {
            $this->warn('Dry run only. Add --execute to create the staging views.');

            return self::SUCCESS;
        }

        $target = $destination->getPdo();
        $created = [];
        try {
            $target->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
            foreach ($views as $view) {
                $name = (string) $view->name;
                $definition = str_replace('`'.$legacy.'`.', '`'.$stage.'`.', (string) $view->definition);
                if (str_contains($definition, '`'.$legacy.'`.')) {
                    throw new RuntimeException("Unconverted source reference in {$name}");
                }
                $quoted = '`'.str_replace('`', '``', $name).'`';
                $target->exec("CREATE ALGORITHM=UNDEFINED SQL SECURITY INVOKER VIEW `{$stage}`.{$quoted} AS {$definition}");
                $created[] = $name;
                $this->line("Created {$name}");
            }
        } catch (Throwable $error) {
            foreach (array_reverse($created) as $name) {
                $quoted = '`'.str_replace('`', '``', $name).'`';
                $target->exec("DROP VIEW `{$stage}`.{$quoted}");
            }
            throw new RuntimeException("Staging views failed: {$error->getMessage()}", 0, $error);
        }

        return self::SUCCESS;
    }
}
