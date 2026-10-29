<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class LeaveWriter
{
    public function connectionName(): string
    {
        $target = config('leave.write_target');
        if ($target === 'stage') {
            $stage = config('database.connections.legacy_stage_writer');
            $production = config('database.connections.legacy');
            if ($stage['host'] === $production['host'] && $stage['database'] === $production['database']) {
                throw new RuntimeException('The leave stage database is not isolated.');
            }

            return 'legacy_stage_writer';
        }
        if ($target === 'production' && config('leave.production_writes_enabled')
            && config('database.connections.legacy_leave_writer.username') !== ''
            && config('database.connections.legacy_leave_writer.username') !== config('database.connections.legacy.username')) {
            return 'legacy_leave_writer';
        }

        throw new RuntimeException('Leave writes are disabled.');
    }

    public function readConnectionName(): string
    {
        return config('leave.write_target') === 'stage' ? $this->connectionName() : 'legacy';
    }

    public function sourceName(): string
    {
        return config('leave.write_target') === 'stage' ? 'stage' : 'production';
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

    public function version(stdClass $row, ?string $connectionName = null): string
    {
        $details = [];
        if (property_exists($row, 'a3410')) {
            $query = DB::connection($connectionName ?? $this->readConnectionName())
                ->table('a35')->where('parentid', $row->Id)->orderBy('Id');
            if ($connectionName !== null) {
                $query->lockForUpdate();
            }
            $details = $query->get(['Id', 'a3501', 'a3502', 'a3503', 'a3504', 'a3505',
                'a3506', 'a3509', 'a3511', 'a3552', 'yndelete'])->toArray();
        }

        return hash('sha256', serialize([
            $row->updateTime, $row->issubmit, $row->yndelete,
            $row->a3433 ?? $row->a3833 ?? '', $row->a3452 ?? $row->a3813 ?? '',
            $row->a3406 ?? $row->a3811 ?? '', $row->a3412 ?? $row->a3812 ?? '',
            $details,
        ]));
    }

    /** @return array{type:string,steps:list<array{id:int,name:string,step:int}>} */
    public function route(User $applicant, string $departmentCode): array
    {
        $db = DB::connection($this->readConnectionName());
        $modules = $db->table('modules')->where('dbTable', 'a34')->where('isuse', 1)->get();
        $matching = $modules->filter(fn ($module) => in_array((string) $applicant->Id,
            array_filter(explode(',', (string) $module->userIds)), true))
            ->sortBy(fn ($module) => count(array_filter(explode(',', (string) $module->userIds))));
        $module = $matching->first();
        abort_if($module === null, 422, '旧系统未配置您的请假审批流程。');
        $steps = $db->table('workflowmodule_users')->where('workflowModuleId', $module->Id)
            ->orderBy('SeriaNo')->get();
        abort_if($steps->isEmpty(), 422, '请假审批流程没有审批人。');
        $route = [];
        foreach ($steps as $step) {
            $id = (int) $step->UserId;
            if ($id === 9999) {
                $leaders = $db->table('gz_dpleaders as leader')
                    ->join('department as department', 'department.Id', '=', 'leader.departmentid')
                    ->where('department.objname', $departmentCode)->where('leader.yndelete', '0')
                    ->pluck('leader.userId');
                abort_unless($leaders->count() === 1, 422, '部门审批人配置不完整或不唯一。');
                $id = (int) $leaders->first();
            }
            $reviewer = $db->table('user')->where('Id', $id)->where('isuse', 1)->first(['Id', 'truename']);
            abort_if($reviewer === null || $id === (int) $applicant->Id, 422, '审批人配置无效。');
            $route[] = ['id' => $id, 'name' => (string) $reviewer->truename, 'step' => (int) $step->SeriaNo];
        }

        return ['type' => (string) $module->ModuleName, 'steps' => $route];
    }

    /** @param list<array{id:int,name:string,step:int}> $route */
    public function createAuditors(ConnectionInterface $db, string $kind, int $id, array $route, string $type, int $applicantId): void
    {
        $ids = implode(',', array_column($route, 'id'));
        $names = implode(',', array_column($route, 'name'));
        foreach ($route as $step) {
            $db->table('gz_auditors')->insert([
                'tablename' => $kind, 'tableId' => $id, 'auditorId' => $step['id'],
                'auditorName' => $step['name'], 'step' => $step['step'],
                'workflowType' => $type, 'workflowId' => $id,
                'workflowUserid' => $applicantId, 'creatorid' => $step['id'],
                'auditorIds' => $ids, 'AuditorNames' => $names,
            ]);
        }
    }

    public function event(string $kind, int $id, User $actor, string $action, ?string $note = null): void
    {
        DB::table('leave_events')->insert([
            'source' => $this->sourceName(), 'kind' => $kind,
            'legacy_id' => $id, 'actor_user_id' => $actor->Id,
            'action' => $action, 'note' => $note, 'created_at' => now(),
        ]);
    }
}
