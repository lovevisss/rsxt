<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class AdminOperationRollback
{
    public function __construct(private readonly AdminOperationLog $log, private readonly PositionWriter $positions,
        private readonly GradeWriter $grades, private readonly DepartmentWriter $departments) {}

    public function rollback(int $id, User $actor): void
    {
        $operation = $this->log->row($id);
        abort_if($operation === null, 404);
        abort_unless($operation->status === 'active', 409, '此操作不可回滚或已回滚。');
        $module = $operation->module;
        $connection = $module === 'administrators' ? DB::connection() : DB::connection($this->positions->connectionName());
        $connection->transaction(function () use ($connection, $id, $actor): void {
            $logConnection = $this->log->connection($connection);
            $operation = $logConnection->table($this->log->table($connection))->where('id', $id)->lockForUpdate()->first();
            abort_unless($operation !== null && $operation->status === 'active', 409, '此操作不可回滚或已回滚。');
            $items = json_decode($operation->items, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(is_array($items) && array_is_list($items) && $items !== [], 409, '缺少完整操作快照。');
            foreach ($items as $item) {
                abort_unless(is_array($item) && isset($item['record_id'])
                    && array_key_exists('before', $item) && array_key_exists('after', $item), 409, '操作快照格式无效。');
            }
            if ($operation->module === 'administrators') {
                $this->rollbackAdministrators($connection, $items);
            } else {
                $this->rollbackRecords($connection, $operation->module, $items);
            }
            $logConnection->table($this->log->table($connection))->where('id', $id)->update([
                'status' => 'rolled_back', 'rolled_back_at' => now(), 'rolled_back_by' => $actor->Id,
            ]);
            $this->log->record($connection, $operation->module, 'rollback', $actor->Id,
                $operation->employee_number, $items, $id, 'rollback');
        });
    }

    /** @param list<array<string, mixed>> $items */
    private function rollbackAdministrators(Connection $connection, array $items): void
    {
        foreach ($items as $item) {
            $number = $item['record_id'];
            $current = $connection->table('personnel_admins')->where('employee_number', $number)->lockForUpdate()->first();
            $after = $item['after'];
            abort_unless(($current === null ? null : (array) $current) == $after, 409, '管理员名单已有后续变更。');
        }
        foreach (array_reverse($items) as $item) {
            $number = $item['record_id'];
            if ($item['before'] === null) {
                abort_if($connection->table('personnel_admins')->count() <= 1, 409, '不能回滚最后一名管理员。');
                $connection->table('personnel_admins')->where('employee_number', $number)->delete();
            } else {
                $connection->table('personnel_admins')->insert($item['before']);
            }
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function rollbackRecords(Connection $connection, string $module, array $items): void
    {
        abort_unless(in_array($module, ['position', 'grade', 'department'], true), 409);
        $table = match ($module) {
            'position' => 'h06', 'grade' => 'h17', 'department' => 'h54'
        };
        $people = [];
        foreach ($items as $item) {
            $current = $this->log->snapshot($connection, $module, (int) $item['record_id'], true);
            abort_unless($current == $item['after'], 409, '目标记录已有后续修改，请先处理较新的操作。');
            $this->checkDuplicate($connection, $module, $item);
            foreach ([$item['before'], $item['after']] as $state) {
                if ($state !== null) {
                    $number = (string) $state[match ($module) {
                        'position' => 'h0602', 'grade' => 'h1702', 'department' => 'h5402'
                    }];
                    $people[$number] = true;
                }
            }
        }
        $numbers = array_keys($people);
        sort($numbers, SORT_STRING);
        foreach ($numbers as $number) {
            abort_unless($connection->table('h50')->where('h5002', $number)->where('yndelete', '0')
                ->lockForUpdate()->exists(), 409, '受影响人员的有效档案不存在。');
            if ($module === 'department') {
                abort_unless($connection->table('user')->where('username', $number)->where('isUse', 1)
                    ->lockForUpdate()->exists(), 409, '受影响人员的启用账号不存在。');
            }
        }
        foreach (array_reverse($items) as $item) {
            $id = (int) $item['record_id'];
            if ($item['before'] === null) {
                $connection->table($table)->where('Id', $id)->update(['yndelete' => '1', 'updateTime' => now()]);
            } else {
                $restore = $item['before'];
                unset($restore['Id']);
                $connection->table($table)->where('Id', $id)->update($restore);
            }
        }
        if ($module === 'position') {
            $pairs = [];
            foreach ($items as $item) {
                foreach ([$item['before'], $item['after']] as $state) {
                    if ($state !== null) {
                        $pairs[$state['h0602'].'|'.$state['h0615']] = [$state['h0602'], $state['h0615']];
                    }
                }
            }
            foreach ($pairs as [$number, $type]) {
                $this->positions->syncProfile($connection, $number, $type);
            }
        } elseif ($module === 'grade') {
            foreach ($numbers as $number) {
                $this->grades->syncProfile($connection, $number);
            }
        } else {
            foreach ($numbers as $number) {
                $this->departments->syncCurrent($connection, $number);
            }
        }
    }

    /** @param array<string, mixed> $item */
    private function checkDuplicate(Connection $connection, string $module, array $item): void
    {
        $before = $item['before'];
        if ($before === null || $before['yndelete'] !== '0') {
            return;
        }
        $id = (int) $item['record_id'];
        if ($module === 'position') {
            $exists = $connection->table('h06')->where('h0602', $before['h0602'])
                ->where('h0615', $before['h0615'])->where('h0609', $before['h0609'])
                ->where('yndelete', '0')->where('Id', '<>', $id)->exists();
        } elseif ($module === 'department') {
            $exists = $connection->table('h54')->where('h5402', $before['h5402'])
                ->where('h5404', $before['h5404'])->where('h5405', $before['h5405'])
                ->where('yndelete', '0')->where('Id', '<>', $id)->exists();
        } else {
            $signature = fn ($row) => implode('|', array_map(fn ($field) => trim((string) ($row[$field] ?? '')),
                ['h1704', 'h1705', 'h1710', 'h1706', 'h1707', 'h1711']));
            $exists = $connection->table('h17')->where('h1702', $before['h1702'])
                ->where('yndelete', '0')->where('Id', '<>', $id)->get()
                ->contains(fn ($row) => $signature((array) $row) === $signature($before));
        }
        abort_if($exists, 409, '恢复旧记录会与当前有效记录重复。');
    }
}
