<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use stdClass;

class GradeWriter
{
    private const FIELDS = ['bb' => ['grade' => 'h1704', 'start' => 'h1705', 'end' => 'h1710', 'profile' => 'h5071'],
        'df' => ['grade' => 'h1706', 'start' => 'h1707', 'end' => 'h1711', 'profile' => 'h5072']];

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

    public function version(stdClass $record): string
    {
        return hash('sha256', serialize([$record->Id, $record->updateTime, $record->yndelete,
            $record->h1701, $record->h1702, $record->h1703, $record->h1704, $record->h1705,
            $record->h1706, $record->h1707, $record->h1708, $record->h1709,
            $record->h1710, $record->h1711]));
    }

    /** @param array<string, string|null> $row */
    public function duplicateExists(array $row, ?string $connectionName = null, ?int $exceptId = null): bool
    {
        $query = DB::connection($connectionName ?? $this->readConnectionName())->table('h17')
            ->where('h1702', $row['employee_number'])->where('yndelete', '0');
        if ($exceptId !== null) {
            $query->where('Id', '<>', $exceptId);
        }
        foreach ($query->get(['h1704', 'h1705', 'h1706', 'h1707', 'h1710', 'h1711']) as $existing) {
            if ($this->signature((array) $existing) === $this->signature($this->attributes($row))) {
                return true;
            }
        }

        return false;
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
                if ($this->duplicateExists($row, $this->connectionName())) {
                    $skipped++;

                    continue;
                }
                $this->rememberBaselines($connection, $row['employee_number']);
                $id = $connection->table('h17')->insertGetId([...$this->attributes($row),
                    'CreatorId' => $actor->Id, 'yndelete' => '0']);
                $connection->table('h17')->where('Id', $id)->update(['h1708' => (string) $id]);
                $this->syncProfile($connection, $row['employee_number']);
                $created[] = ['id' => $id, 'row' => $row];
            }
        });
        foreach ($created as $item) {
            $this->event('created', $item['row']['employee_number'], $item['id'], $actor->Id, $item['row']);
        }

        return ['created' => count($created), 'skipped' => $skipped];
    }

    /** @param array<string, string|null> $row */
    public function change(int $id, array $row, string $version, User $actor): void
    {
        $connection = DB::connection($this->connectionName());
        $oldNumber = null;
        $connection->transaction(function () use ($connection, $id, $row, $version, &$oldNumber): void {
            $existing = $connection->table('h17')->where('Id', $id)->where('yndelete', '0')->lockForUpdate()->first();
            abort_if($existing === null, 404);
            abort_unless(hash_equals($this->version($existing), $version), 409, '记录已变化，请刷新后重试。');
            $oldNumber = (string) $existing->h1702;
            $this->lockProfile($connection, $oldNumber);
            if ($row['employee_number'] !== $oldNumber) {
                $this->lockProfile($connection, $row['employee_number']);
            }
            abort_if($this->duplicateExists($row, $this->connectionName(), $id), 422, '相同等级和聘任日期的记录已存在。');
            $this->rememberBaselines($connection, $oldNumber);
            $this->rememberBaselines($connection, $row['employee_number']);
            $connection->table('h17')->where('Id', $id)->update([...$this->attributes($row), 'updateTime' => now()]);
            $this->syncProfile($connection, $oldNumber);
            if ($row['employee_number'] !== $oldNumber) {
                $this->syncProfile($connection, $row['employee_number']);
            }
        });
        $this->event('updated', $row['employee_number'], $id, $actor->Id, ['old_employee_number' => $oldNumber, 'row' => $row]);
    }

    public function delete(int $id, string $version, User $actor): void
    {
        $connection = DB::connection($this->connectionName());
        $number = null;
        $connection->transaction(function () use ($connection, $id, $version, &$number): void {
            $existing = $connection->table('h17')->where('Id', $id)->where('yndelete', '0')->lockForUpdate()->first();
            abort_if($existing === null, 404);
            abort_unless(hash_equals($this->version($existing), $version), 409, '记录已变化，请刷新后重试。');
            $number = (string) $existing->h1702;
            $this->lockProfile($connection, $number);
            $this->rememberBaselines($connection, $number);
            $connection->table('h17')->where('Id', $id)->update(['yndelete' => '1', 'updateTime' => now()]);
            $this->syncProfile($connection, $number);
        });
        $this->event('deleted', $number, $id, $actor->Id, []);
    }

    public function refreshExpired(?string $today = null): int
    {
        $today ??= CarbonImmutable::now('Asia/Shanghai')->toDateString();
        $numbers = DB::table('grade_profile_baselines')->distinct()->pluck('employee_number');
        $changed = 0;
        foreach ($numbers as $number) {
            if ($this->refreshEmployee((string) $number, $today)) {
                $changed++;
            }
        }

        return $changed;
    }

    public function refreshEmployee(string $number, ?string $today = null): bool
    {
        if (! DB::table('grade_profile_baselines')->where('employee_number', $number)->exists()) {
            return false;
        }
        $today ??= CarbonImmutable::now('Asia/Shanghai')->toDateString();
        $connection = DB::connection($this->connectionName());
        $changed = false;
        $connection->transaction(function () use ($connection, $number, $today, &$changed): void {
            $profile = $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')
                ->lockForUpdate()->first(['h5071', 'h5072']);
            if ($profile === null) {
                return;
            }
            $changed = $this->syncProfile($connection, $number, $today);
        });
        if ($changed) {
            $this->event('scheduled_refresh', $number, null, null, ['date' => $today]);
        }

        return $changed;
    }

    private function lockProfile(Connection $connection, string $number): void
    {
        abort_unless($connection->table('h50')->where('h5002', $number)->where('yndelete', '0')
            ->lockForUpdate()->exists(), 422, '职工档案不存在。');
    }

    private function rememberBaselines(Connection $connection, string $number): void
    {
        $profile = $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')
            ->first(['h5071', 'h5072']);
        foreach (self::FIELDS as $type => $fields) {
            DB::table('grade_profile_baselines')->insertOrIgnore(['employee_number' => $number, 'type' => $type,
                'grade' => $profile->{$fields['profile']}, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function syncProfile(Connection $connection, string $number, ?string $today = null): bool
    {
        $today ??= CarbonImmutable::now('Asia/Shanghai')->toDateString();
        $updates = [];
        $profile = $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')
            ->first(['h5071', 'h5072']);
        foreach (self::FIELDS as $type => $fields) {
            $latest = $connection->table('h17')->where('h1702', $number)->where('yndelete', '0')
                ->whereNotNull($fields['grade'])->where($fields['grade'], '<>', '')
                ->whereNotNull($fields['start'])->where($fields['start'], '<>', '')
                ->where($fields['start'], '<=', $today)
                ->where(fn ($query) => $query->whereNull($fields['end'])->orWhere($fields['end'], '')
                    ->orWhere($fields['end'], '>', $today))
                ->orderByDesc($fields['start'])->orderByDesc('Id')->first([$fields['grade']]);
            $baseline = DB::table('grade_profile_baselines')->where('employee_number', $number)->where('type', $type)->first();
            $grade = $latest ? $latest->{$fields['grade']} : $baseline?->grade;
            if ($grade !== $profile->{$fields['profile']}) {
                $updates[$fields['profile']] = $grade;
            }
        }
        if ($updates === []) {
            return false;
        }
        $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')
            ->update([...$updates, 'updateTime' => now()]);

        return true;
    }

    /** @param array<string, string|null> $row
     * @return array<string, string|null>
     */
    private function attributes(array $row): array
    {
        return ['h1701' => $row['name'], 'h1702' => $row['employee_number'], 'h1703' => $row['department'],
            'h1704' => $row['bb_grade'], 'h1705' => $row['bb_start'], 'h1710' => $row['bb_end'],
            'h1706' => $row['df_grade'], 'h1707' => $row['df_start'], 'h1711' => $row['df_end'],
            'h1709' => $row['note']];
    }

    /** @param array<string, string|null> $attributes */
    private function signature(array $attributes): string
    {
        return implode('|', array_map(fn ($field) => trim((string) ($attributes[$field] ?? '')),
            ['h1704', 'h1705', 'h1710', 'h1706', 'h1707', 'h1711']));
    }

    /** @param array<string, mixed> $details */
    private function event(string $action, string $number, ?int $id, ?int $actorId, array $details): void
    {
        DB::table('grade_change_events')->insert(['employee_number' => $number, 'record_id' => $id,
            'actor_user_id' => $actorId, 'action' => $action,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
