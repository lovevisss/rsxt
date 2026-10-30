<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use stdClass;

class DepartmentWriter
{
    public function __construct(private readonly PositionWriter $positionWriter) {}

    public function connectionName(): string
    {
        return $this->positionWriter->connectionName();
    }

    public function readConnectionName(): string
    {
        return $this->positionWriter->readConnectionName();
    }

    public function available(): bool
    {
        try {
            $this->connectionName();

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function version(stdClass $row): string
    {
        return hash('sha256', serialize([$row->Id, $row->updateTime, $row->yndelete,
            $row->h5401, $row->h5402, $row->h5403, $row->h5404,
            $row->h5405, $row->h5406, $row->h5407]));
    }

    /** @param array<string, string|null> $row */
    public function duplicateExists(array $row, ?string $connectionName = null, ?int $exceptId = null): bool
    {
        $query = DB::connection($connectionName ?? $this->readConnectionName())->table('h54')
            ->where('h5402', $row['employee_number'])->where('h5404', $row['target_department'])
            ->where('h5405', $row['changed_at'])->where('yndelete', '0');
        if ($exceptId !== null) {
            $query->where('Id', '<>', $exceptId);
        }

        return $query->exists();
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
                $this->lockIdentity($connection, $row['employee_number']);
                if ($this->duplicateExists($row, $this->connectionName())) {
                    $skipped++;

                    continue;
                }
                $this->rememberBaseline($connection, $row['employee_number']);
                $id = $connection->table('h54')->insertGetId([...$this->attributes($row),
                    'CreatorId' => $actor->Id, 'yndelete' => '0']);
                $this->syncCurrent($connection, $row['employee_number']);
                $this->event($connection, 'created', $row['employee_number'], $id, $actor->Id, $row);
                $created[] = ['id' => $id, 'row' => $row];
            }
        });

        return ['created' => count($created), 'skipped' => $skipped];
    }

    /** @param array<string, string|null> $row */
    public function change(int $id, array $row, string $version, User $actor): void
    {
        $connection = DB::connection($this->connectionName());
        $oldNumber = null;
        $connection->transaction(function () use ($connection, $id, $row, $version, $actor, &$oldNumber): void {
            $existing = $connection->table('h54')->where('Id', $id)->where('yndelete', '0')->lockForUpdate()->first();
            abort_if($existing === null, 404);
            abort_unless(hash_equals($this->version($existing), $version), 409, '记录已变化，请刷新后重试。');
            $oldNumber = (string) $existing->h5402;
            $this->lockIdentity($connection, $oldNumber);
            if ($row['employee_number'] !== $oldNumber) {
                $this->lockIdentity($connection, $row['employee_number']);
            }
            abort_if($this->duplicateExists($row, $this->connectionName(), $id), 422, '同一职工、目标部门和日期的记录已存在。');
            $this->rememberBaseline($connection, $oldNumber);
            $this->rememberBaseline($connection, $row['employee_number']);
            $connection->table('h54')->where('Id', $id)->update([...$this->attributes($row), 'updateTime' => now()]);
            $this->syncCurrent($connection, $oldNumber);
            if ($row['employee_number'] !== $oldNumber) {
                $this->syncCurrent($connection, $row['employee_number']);
            }
            $this->event($connection, 'updated', $row['employee_number'], $id, $actor->Id,
                ['old_employee_number' => $oldNumber, 'row' => $row]);
        });
    }

    public function delete(int $id, string $version, User $actor): void
    {
        $connection = DB::connection($this->connectionName());
        $number = null;
        $connection->transaction(function () use ($connection, $id, $version, $actor, &$number): void {
            $existing = $connection->table('h54')->where('Id', $id)->where('yndelete', '0')->lockForUpdate()->first();
            abort_if($existing === null, 404);
            abort_unless(hash_equals($this->version($existing), $version), 409, '记录已变化，请刷新后重试。');
            $number = (string) $existing->h5402;
            $this->lockIdentity($connection, $number);
            $this->rememberBaseline($connection, $number);
            $connection->table('h54')->where('Id', $id)->update(['yndelete' => '1', 'updateTime' => now()]);
            $this->syncCurrent($connection, $number);
            $this->event($connection, 'deleted', $number, $id, $actor->Id, []);
        });
    }

    public function refreshDue(?string $today = null): int
    {
        $today ??= CarbonImmutable::now('Asia/Shanghai')->toDateString();
        $changed = 0;
        foreach (DB::connection($this->connectionName())->table($this->baselineTable())->pluck('employee_number') as $number) {
            if ($this->refreshEmployee((string) $number, $today)) {
                $changed++;
            }
        }

        return $changed;
    }

    public function refreshEmployee(string $number, ?string $today = null): bool
    {
        if (! DB::connection($this->connectionName())->table($this->baselineTable())->where('employee_number', $number)->exists()) {
            return false;
        }
        $today ??= CarbonImmutable::now('Asia/Shanghai')->toDateString();
        $connection = DB::connection($this->connectionName());
        $changed = false;
        $connection->transaction(function () use ($connection, $number, $today, &$changed): void {
            $profile = $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')
                ->lockForUpdate()->first(['Id']);
            $account = $connection->table('user')->where('username', $number)->where('isUse', 1)
                ->lockForUpdate()->first(['Id']);
            if ($profile !== null && $account !== null) {
                $changed = $this->syncCurrent($connection, $number, $today);
                if ($changed) {
                    $this->event($connection, 'scheduled_refresh', $number, null, null, ['date' => $today]);
                }
            }
        });

        return $changed;
    }

    private function lockIdentity(Connection $connection, string $number): void
    {
        abort_unless($connection->table('h50')->where('h5002', $number)->where('yndelete', '0')
            ->lockForUpdate()->exists(), 422, '职工档案不存在。');
        abort_unless($connection->table('user')->where('username', $number)->where('isUse', 1)
            ->lockForUpdate()->exists(), 422, '启用中的门户账号不存在。');
    }

    private function rememberBaseline(Connection $connection, string $number): void
    {
        $profile = $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')->first(['h5003']);
        $account = $connection->table('user')->where('username', $number)->where('isUse', 1)->first(['departmentId']);
        $connection->table($this->baselineTable())->insertOrIgnore([
            'employee_number' => $number, 'profile_department' => $profile->h5003,
            'account_department_id' => $account->departmentId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function syncCurrent(Connection $connection, string $number, ?string $today = null): bool
    {
        $today ??= CarbonImmutable::now('Asia/Shanghai')->toDateString();
        $latest = $connection->table('h54 as change')
            ->join('department as target', 'target.objname', '=', 'change.h5404')
            ->where('change.h5402', $number)->where('change.yndelete', '0')
            ->whereNotNull('change.h5405')->where('change.h5405', '<>', '')
            ->where('change.h5405', '<=', $today)
            ->orderByDesc('change.h5405')->orderByDesc('change.Id')
            ->first(['change.h5404', 'target.Id as target_id']);
        $baseline = $connection->table($this->baselineTable())->where('employee_number', $number)->first();
        $profileCode = $latest ? $latest->h5404 : $baseline?->profile_department;
        $accountId = $latest ? $latest->target_id : $baseline?->account_department_id;
        $profile = $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')->first(['h5003']);
        $account = $connection->table('user')->where('username', $number)->where('isUse', 1)->first(['departmentId']);
        $changed = false;
        if ($profile !== null && $profile->h5003 !== $profileCode) {
            $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')
                ->update(['h5003' => $profileCode, 'updateTime' => now()]);
            $changed = true;
        }
        if ($account !== null && (int) $account->departmentId !== (int) $accountId) {
            $connection->table('user')->where('username', $number)->where('isUse', 1)
                ->update(['departmentId' => $accountId, 'updatetime' => now()]);
            $changed = true;
        }

        return $changed;
    }

    /** @param array<string, string|null> $row
     * @return array<string, string|null>
     */
    private function attributes(array $row): array
    {
        return ['h5401' => $row['name'], 'h5402' => $row['employee_number'],
            'h5403' => $row['source_department'], 'h5404' => $row['target_department'],
            'h5405' => $row['changed_at'], 'h5406' => $row['note'], 'h5407' => $row['reason']];
    }

    private function baselineTable(): string
    {
        return config('positions.write_target') === 'stage' ? 'department_profile_baselines' : 'rsxt.department_profile_baselines';
    }

    /** @param array<string, mixed> $details */
    private function event(Connection $connection, string $action, string $number, ?int $id, ?int $actorId, array $details): void
    {
        $table = config('positions.write_target') === 'stage' ? 'department_change_events' : 'rsxt.department_change_events';
        $connection->table($table)->insert([
            'employee_number' => $number, 'record_id' => $id, 'actor_user_id' => $actorId,
            'action' => $action, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
