<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

class AdminOperationLog
{
    /** @var array<string, list<string>> */
    public const FIELDS = [
        'position' => ['Id', 'h0601', 'h0602', 'h0603', 'h0604', 'h0605', 'h0606', 'h0607', 'h0608', 'h0609', 'h0615', 'CreatorId', 'yndelete', 'updateTime'],
        'grade' => ['Id', 'h1701', 'h1702', 'h1703', 'h1704', 'h1705', 'h1706', 'h1707', 'h1708', 'h1709', 'h1710', 'h1711', 'CreatorId', 'yndelete', 'updateTime'],
        'department' => ['Id', 'h5401', 'h5402', 'h5403', 'h5404', 'h5405', 'h5406', 'h5407', 'CreatorId', 'yndelete', 'updateTime'],
    ];

    public function table(Connection $connection): string
    {
        return $connection->getDriverName() === 'sqlite' ? 'admin_operations' : 'rsxt.admin_operations';
    }

    public function connection(Connection $writer): Connection
    {
        return $writer->getDriverName() === 'sqlite' ? DB::connection() : $writer;
    }

    public function baselineTable(Connection $connection, string $module): string
    {
        $table = match ($module) {
            'position' => 'position_profile_baselines',
            'grade' => 'grade_profile_baselines',
            'department' => 'department_profile_baselines',
            default => throw new InvalidArgumentException('未知操作模块。'),
        };

        return $connection->getDriverName() === 'sqlite' ? $table : 'rsxt.'.$table;
    }

    /** @return array<string, mixed>|null */
    public function snapshot(Connection $connection, string $module, int $id, bool $lock = false): ?array
    {
        $table = match ($module) {
            'position' => 'h06', 'grade' => 'h17', 'department' => 'h54',
            default => throw new InvalidArgumentException('未知操作模块。')
        };
        $query = $connection->table($table)->where('Id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first(self::FIELDS[$module]);

        return $row === null ? null : (array) $row;
    }

    /** @param list<array{record_id:int|string,before:array<string,mixed>|null,after:array<string,mixed>|null}> $items */
    public function record(Connection $connection, string $module, string $action, ?int $actorId,
        ?string $number, array $items, ?int $relatedId = null, string $status = 'active'): int
    {
        return $this->connection($connection)->table($this->table($connection))->insertGetId([
            'module' => $module, 'action' => $action, 'actor_user_id' => $actorId,
            'employee_number' => $number, 'items' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'item_count' => count($items), 'status' => $status, 'related_operation_id' => $relatedId,
            'created_at' => now(),
        ]);
    }

    public function reader(): Connection
    {
        return DB::connection();
    }

    public function row(int $id): ?stdClass
    {
        return $this->reader()->table('admin_operations')->where('id', $id)->first();
    }
}
