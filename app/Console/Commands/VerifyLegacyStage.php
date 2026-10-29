<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class VerifyLegacyStage extends Command
{
    protected $signature = 'personnel:verify-stage';

    protected $description = 'Compare exact row counts in the legacy database and its isolated staging copy';

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
        $destination->statement("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $query = 'SELECT TABLE_NAME AS name, TABLE_TYPE AS kind FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME';
        $sourceTables = $source->select($query, [$legacy]);
        $stageTables = $destination->select($query, [$stage]);
        $sourceNames = array_values(array_map(fn ($table) => $table->name, array_filter($sourceTables, fn ($table) => $table->kind === 'BASE TABLE')));
        $stageNames = array_values(array_map(fn ($table) => $table->name, array_filter($stageTables, fn ($table) => $table->kind === 'BASE TABLE')));
        sort($sourceNames, SORT_STRING);
        sort($stageNames, SORT_STRING);

        if ($sourceNames !== $stageNames) {
            $this->error('The staging table list differs from the legacy source.');
            $this->line('Missing: '.implode(', ', array_diff($sourceNames, $stageNames)));
            $this->line('Extra: '.implode(', ', array_diff($stageNames, $sourceNames)));

            return self::FAILURE;
        }

        $mismatches = [];
        foreach ($sourceNames as $name) {
            $quoted = '`'.str_replace('`', '``', $name).'`';
            $sourceCount = (int) $source->selectOne("SELECT COUNT(*) AS n FROM {$quoted}")->n;
            $stageCount = (int) $destination->selectOne("SELECT COUNT(*) AS n FROM `{$stage}`.{$quoted}")->n;
            if ($sourceCount !== $stageCount) {
                $mismatches[] = "{$name}: legacy={$sourceCount}, stage={$stageCount}";
            }
        }

        foreach ($mismatches as $mismatch) {
            $this->error($mismatch);
        }

        $sourceViews = array_values(array_map(fn ($view) => $view->name, array_filter($sourceTables, fn ($view) => $view->kind === 'VIEW')));
        $stageViews = array_values(array_map(fn ($view) => $view->name, array_filter($stageTables, fn ($view) => $view->kind === 'VIEW')));
        sort($sourceViews, SORT_STRING);
        sort($stageViews, SORT_STRING);
        $this->line(sprintf('Checked %d tables: %d row-count mismatches.', count($sourceNames), count($mismatches)));
        $this->line(sprintf('Views: legacy=%d, stage=%d.', count($sourceViews), count($stageViews)));
        if ($sourceViews !== $stageViews) {
            $this->warn('Views requiring review: '.implode(', ', array_diff($sourceViews, $stageViews)));
        } else {
            $unverified = [];
            $definitions = $source->select('SELECT TABLE_NAME AS name, VIEW_DEFINITION AS definition FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?', [$legacy]);
            $definitionByName = collect($definitions)->keyBy('name');
            foreach ($sourceViews as $name) {
                $quoted = '`'.str_replace('`', '``', $name).'`';
                try {
                    try {
                        $sourceCount = (int) $source->selectOne("SELECT COUNT(*) AS n FROM {$quoted}")->n;
                    } catch (Throwable) {
                        $definition = $definitionByName->get($name)->definition;
                        $sourceCount = (int) $source->selectOne("SELECT COUNT(*) AS n FROM ({$definition}) AS source_view")->n;
                    }
                    $stageCount = (int) $destination->selectOne("SELECT COUNT(*) AS n FROM `{$stage}`.{$quoted}")->n;
                    if ($sourceCount !== $stageCount) {
                        $mismatches[] = "view {$name}: legacy={$sourceCount}, stage={$stageCount}";
                        $this->error(end($mismatches));
                    }
                } catch (Throwable $error) {
                    $unverified[] = $name;
                    $this->warn("View {$name} could not be counted (error {$error->getCode()}).");
                    if ($this->output->isVerbose()) {
                        $this->line($error->getMessage());
                    }
                }
            }
            $this->line(sprintf('Views verified: %d/%d.', count($sourceViews) - count($unverified), count($sourceViews)));
        }

        return $mismatches === [] && $sourceViews === $stageViews && ($unverified ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}
