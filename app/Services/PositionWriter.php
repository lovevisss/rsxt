<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class PositionWriter
{
    public function connectionName(): string
    {
        if (config('positions.write_target') === 'stage') {
            $stage = config('database.connections.legacy_stage_writer');
            $production = config('database.connections.legacy');
            if ($stage['host'] === $production['host'] && $stage['database'] === $production['database']) {
                throw new RuntimeException('岗位变动测试库未隔离。');
            }

            return 'legacy_stage_writer';
        }
        $writer = config('database.connections.legacy_position_writer');
        $reader = config('database.connections.legacy');
        if (config('positions.write_target') === 'production' && config('positions.production_writes_enabled')
            && filled($writer['username']) && $writer['username'] !== $reader['username']) {
            return 'legacy_position_writer';
        }

        throw new RuntimeException('岗位变动写入账号尚未配置。');
    }

    public function readConnectionName(): string
    {
        return config('positions.write_target') === 'stage' ? $this->connectionName() : 'legacy';
    }

    public function version(stdClass $row): string
    {
        return hash('sha256', serialize([$row->Id, $row->updateTime, $row->yndelete, $row->h0602,
            $row->h0603, $row->h0604, $row->h0608, $row->h0609, $row->h0606, $row->h0605, $row->h0615]));
    }

    /** @param list<array<string, string|null>> $rows
     * @return array{created:int,skipped:int}
     */
    public function createMany(array $rows, User $actor): array
    {
        $connection = DB::connection($this->connectionName());
        $created = [];
        $skipped = 0;
        $connection->transaction(function () use ($connection, $rows, $actor, &$created, &$skipped): void {
            foreach ($rows as $row) {
                $this->lockProfile($connection, $row['employee_number']);
                if ($connection->table('h06')->where('h0602', $row['employee_number'])->where('h0615', $row['type'])
                    ->where('h0609', $row['new_date'])->where('yndelete', '0')->exists()) {
                    $skipped++;

                    continue;
                }
                $this->rememberBaseline($connection, $row['employee_number'], $row['type']);
                $id = $connection->table('h06')->insertGetId($this->attributes($row, $actor));
                $created[] = ['id' => $id, 'row' => $row];
                $this->syncProfile($connection, $row['employee_number'], $row['type']);
            }
        });
        foreach ($created as $item) {
            $this->event($actor, 'created', $item['row']['employee_number'], $item['id'], $item['row']);
        }

        return ['created' => count($created), 'skipped' => $skipped];
    }

    /** @param array<string, string|null> $row */
    public function change(int $id, array $row, string $version, User $actor): void
    {
        $connection = DB::connection($this->connectionName());
        $previous = null;
        $connection->transaction(function () use ($connection, $id, $row, $version, $actor, &$previous): void {
            $existing = $connection->table('h06')->where('Id', $id)->where('yndelete', '0')->lockForUpdate()->first();
            abort_if($existing === null, 404);
            abort_unless(hash_equals($this->version($existing), $version), 409, '记录已变化，请刷新后重试。');
            $previous = ['employee_number' => $existing->h0602, 'type' => $existing->h0615];
            $this->lockProfile($connection, $previous['employee_number']);
            if ($row['employee_number'] !== $previous['employee_number']) {
                $this->lockProfile($connection, $row['employee_number']);
            }
            $this->rememberBaseline($connection, $previous['employee_number'], $previous['type']);
            $this->rememberBaseline($connection, $row['employee_number'], $row['type']);
            abort_if($connection->table('h06')->where('h0602', $row['employee_number'])->where('h0615', $row['type'])
                ->where('h0609', $row['new_date'])->where('yndelete', '0')->where('Id', '<>', $id)->exists(), 422, '同日期变动记录已存在。');
            $attributes = $this->attributes($row, $actor);
            unset($attributes['CreatorId']);
            $connection->table('h06')->where('Id', $id)->update([...$attributes, 'updateTime' => now()]);
            $this->syncProfile($connection, $previous['employee_number'], $previous['type']);
            if ($previous['employee_number'] !== $row['employee_number'] || $previous['type'] !== $row['type']) {
                $this->syncProfile($connection, $row['employee_number'], $row['type']);
            }
        });
        $this->event($actor, 'updated', $row['employee_number'], $id, ['before' => $previous, 'after' => $row]);
    }

    public function delete(int $id, string $version, User $actor): void
    {
        $connection = DB::connection($this->connectionName());
        $previous = null;
        $connection->transaction(function () use ($connection, $id, $version, &$previous): void {
            $existing = $connection->table('h06')->where('Id', $id)->where('yndelete', '0')->lockForUpdate()->first();
            abort_if($existing === null, 404);
            abort_unless(hash_equals($this->version($existing), $version), 409, '记录已变化，请刷新后重试。');
            $previous = ['employee_number' => $existing->h0602, 'type' => $existing->h0615];
            $this->lockProfile($connection, $existing->h0602);
            $this->rememberBaseline($connection, $existing->h0602, $existing->h0615);
            $connection->table('h06')->where('Id', $id)->update(['yndelete' => '1', 'updateTime' => now()]);
            $this->syncProfile($connection, $existing->h0602, $existing->h0615);
        });
        $this->event($actor, 'deleted', $previous['employee_number'], $id, $previous);
    }

    private function lockProfile(Connection $connection, string $number): void
    {
        abort_unless($connection->table('h50')->where('h5002', $number)->where('yndelete', '0')->lockForUpdate()->exists(), 422, '职工档案不存在。');
    }

    private function rememberBaseline(Connection $connection, string $number, string $type): void
    {
        if (DB::table('position_profile_baselines')->where('employee_number', $number)->where('type', $type)->exists()) {
            return;
        }
        $fields = $type === 'DF' ? ['h5019', 'h5021'] : ['h5094', 'h5073'];
        $profile = $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')->first($fields);
        DB::table('position_profile_baselines')->insertOrIgnore([
            'employee_number' => $number, 'type' => $type, 'position' => $profile->{$fields[0]},
            'appointed_at' => $profile->{$fields[1]}, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function syncProfile(Connection $connection, string $number, string $type): void
    {
        $latest = $connection->table('h06')->where('h0602', $number)->where('h0615', $type)
            ->where('yndelete', '0')->orderByDesc('h0609')->orderByDesc('Id')->first(['h0604', 'h0609']);
        $baseline = DB::table('position_profile_baselines')->where('employee_number', $number)->where('type', $type)->first();
        $fields = $type === 'DF' ? ['h5019', 'h5021'] : ['h5094', 'h5073'];
        $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')->update([
            $fields[0] => $latest ? $latest->h0604 : $baseline?->position,
            $fields[1] => $latest ? $latest->h0609 : $baseline?->appointed_at,
            'updateTime' => now(),
        ]);
    }

    /** @param array<string, string|null> $row
     * @return array<string, string|int|null>
     */
    private function attributes(array $row, User $actor): array
    {
        return ['h0601' => $row['name'], 'h0602' => $row['employee_number'], 'h0603' => $row['old_position'],
            'h0604' => $row['new_position'], 'h0605' => $row['note'], 'h0606' => $row['reason'],
            'h0607' => $row['department'], 'h0608' => $row['old_date'], 'h0609' => $row['new_date'],
            'h0615' => $row['type'], 'CreatorId' => $actor->Id, 'yndelete' => '0'];
    }

    /** @param array<string, mixed> $details */
    private function event(User $actor, string $action, ?string $number, ?int $recordId, array $details): void
    {
        DB::table('position_change_events')->insert(['employee_number' => $number, 'record_id' => $recordId,
            'actor_user_id' => $actor->Id, 'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
}
