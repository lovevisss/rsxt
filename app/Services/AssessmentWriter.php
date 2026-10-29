<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class AssessmentWriter
{
    public function version(stdClass $row): string
    {
        return hash('sha256', serialize([
            $row->updateTime, $row->h2025, $row->h2030, $row->h2026,
            $row->h2031, $row->h2013, $row->Memo,
        ]));
    }

    public function available(): bool
    {
        try {
            $this->connectionName();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function connectionName(): string
    {
        $target = config('assessments.write_target');
        if ($target === 'stage') {
            $stage = config('database.connections.legacy_stage_writer');
            $production = config('database.connections.legacy');
            if ($stage['host'] === $production['host'] && $stage['database'] === $production['database']) {
                throw new RuntimeException('The assessment stage database is not isolated.');
            }

            return 'legacy_stage_writer';
        }
        if ($target === 'production' && config('assessments.production_writes_enabled')
            && config('database.connections.legacy_assessment_writer.username') !== ''
            && config('database.connections.legacy_assessment_writer.username') !== config('database.connections.legacy.username')) {
            return 'legacy_assessment_writer';
        }

        throw new RuntimeException('Assessment writes are disabled.');
    }

    public function readConnectionName(): string
    {
        return config('assessments.write_target') === 'stage' ? $this->connectionName() : 'legacy';
    }

    /** @param array<string, string> $updates */
    public function change(int $id, string $employeeNumber, string $version, string $action, array $updates): void
    {
        $connection = DB::connection($this->connectionName());
        $connection->transaction(function () use ($connection, $id, $employeeNumber, $version, $action, $updates): void {
            $row = $connection->table('h20')->where('Id', $id)->where('h2002', $employeeNumber)
                ->where('yndelete', '0')->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_unless($this->version($row) === $version, 409, '考核记录已变化，请刷新后重试。');
            $unreviewed = $row->h2030 !== 'y' && $row->h2026 !== 'y' && $row->h2031 !== 'y';
            $allowed = match ($action) {
                'saved' => $row->h2025 !== 'y' && $unreviewed,
                'submitted' => $row->h2025 !== 'y' && $unreviewed && trim(strip_tags((string) $row->Memo)) !== '',
                'withdrawn', 'approved', 'returned' => $row->h2025 === 'y' && $unreviewed,
                default => false,
            };
            abort_unless($allowed, 409, '当前状态不能执行此操作。');
            $connection->table('h20')->where('Id', $id)->where('h2002', $employeeNumber)
                ->update([...$updates, 'updateTime' => now()]);
        });
    }
}
