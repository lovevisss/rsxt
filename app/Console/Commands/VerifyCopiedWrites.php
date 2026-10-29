<?php

namespace App\Console\Commands;

use App\Http\Controllers\LeaveController;
use App\Http\Controllers\PersonnelController;
use App\Models\User;
use App\Services\AssessmentWriter;
use App\Services\InstanceDataCopy;
use App\Services\LeaveWriter;
use App\Services\LegacyReadOnlyGuard;
use App\Services\ProfileCorrectionAccess;
use App\Services\ProfileCorrectionService;
use App\Services\ProfileCorrectionWriter;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use stdClass;

class VerifyCopiedWrites extends Command
{
    protected $signature = 'personnel:verify-copy-writes {--execute}';

    protected $description = 'Exercise restricted writers on the unused new copy, restore business rows and roll back audit test data';

    /** @var array<string,mixed> */
    private array $results = [];

    public function handle(InstanceDataCopy $copy): int
    {
        if (! $this->option('execute')) {
            $this->line('Dry run: verify correction, assessment, leave and cancellation before activating the copied instance.');

            return self::SUCCESS;
        }
        if (config('database.connections.mysql.host') === config('data_copy.target.host')) {
            throw new RuntimeException('This test is only allowed before activation of the new instance.');
        }
        $contents = file_get_contents(storage_path('app/private/migration/runtime-credentials.json'));
        if ($contents === false) {
            throw new RuntimeException('Runtime accounts have not been provisioned.');
        }
        $credentials = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        foreach (['mysql' => 'app', 'legacy' => 'reader', 'legacy_profile_writer' => 'profile',
            'legacy_assessment_writer' => 'assessment', 'legacy_leave_writer' => 'leave'] as $name => $kind) {
            config()->set('database.connections.'.$name, [
                ...config('database.connections.'.$name), ...config('data_copy.target'), ...$credentials[$kind],
                'driver' => 'mysql', 'database' => $kind === 'app' ? 'rsxt' : 'uchr',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            ]);
            DB::purge($name);
        }
        config()->set('database.default', 'mysql');
        config()->set('personnel.local_preview', false);
        config()->set('personnel.legacy_write_enabled', false);
        config()->set('profile_corrections.transactional_identity', true);
        foreach (['profile_corrections', 'assessments', 'leave'] as $module) {
            config()->set($module.'.write_target', 'production');
            config()->set($module.'.production_writes_enabled', true);
        }
        config()->set('legacy_files.enabled', true);
        config()->set('legacy_files.remote_fallback', false);
        app(LegacyReadOnlyGuard::class)->attach(DB::connection('legacy'));
        $settings = [...config('data_copy.target'), 'database' => 'uchr'];
        $admin = $copy->connect($settings);
        $admin->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $applicant = User::query()->where('Id', config('personnel.preview_user_id'))->firstOrFail();
        $access = app(ProfileCorrectionAccess::class);
        if (! $access->canApply($applicant)) {
            throw new RuntimeException('The verification employee lacks correction menu permissions.');
        }
        $reviewerId = DB::connection('legacy')->table('userroles as role')->join('user as account', 'account.Id', '=', 'role.userId')
            ->where('role.roleId', 15)->where('account.isUse', 1)->where('account.Id', '!=', $applicant->Id)->value('account.Id');
        $reviewer = User::query()->where('Id', $reviewerId)->firstOrFail();
        if (! $access->isReviewer($reviewer)) {
            throw new RuntimeException('The verification reviewer lacks HR permissions.');
        }
        $profile = DB::connection('legacy')->table('h50')->where('h5002', $applicant->username)->where('yndelete', '0')->first();
        if ($profile === null) {
            throw new RuntimeException('The verification profile is unavailable.');
        }
        $identity = DB::connection('legacy')->table('user')->where('Id', $applicant->Id)->first();
        if ($identity === null) {
            throw new RuntimeException('The verification identity is unavailable.');
        }
        $assessment = DB::connection('legacy')->table('h20')->where('yndelete', '0')->where(function ($query): void {
            $query->whereNull('h2025')->orWhere('h2025', '!=', 'y');
        })->where(function ($query): void {
            $query->whereNull('h2030')->orWhere('h2030', '!=', 'y');
        })->where(function ($query): void {
            $query->whereNull('h2026')->orWhere('h2026', '!=', 'y');
        })->where(function ($query): void {
            $query->whereNull('h2031')->orWhere('h2031', '!=', 'y');
        })->orderByDesc('Id')->first();
        if ($assessment === null) {
            throw new RuntimeException('No unreviewed assessment is available for the reversible check.');
        }
        $leaveId = null;
        $cancellationId = null;
        DB::connection('mysql')->beginTransaction();
        try {
            $service = app(ProfileCorrectionService::class);
            $id = $service->submit($applicant, ['h5084' => '__183_COPY_CHECK__'], 'New instance verification', []);
            $service->withdraw($this->correction($id), $applicant);
            $id = $service->submit($applicant, ['h5084' => '__183_COPY_CHECK__'], 'New instance verification', []);
            $service->reject($this->correction($id), $reviewer, 'Verification rejection');
            $id = $service->submit($applicant, ['h5084' => '__183_COPY_CHECK__'], 'New instance verification', []);
            $service->approve($this->correction($id), $reviewer);
            $this->check(DB::table('profile_correction_requests')->where('id', $id)->value('status') === 'applied', 'Correction approval did not apply.');
            app(ProfileCorrectionWriter::class)->apply((object) [
                'legacy_profile_id' => $profile->Id, 'employee_number' => $applicant->username, 'applicant_user_id' => $applicant->Id,
            ], [(object) ['field_key' => 'h5001', 'old_raw' => $profile->h5001, 'new_raw' => '__183_NAME_CHECK__']]);
            $this->check(DB::connection('legacy')->table('user')->where('Id', $applicant->Id)->value('truename') === '__183_NAME_CHECK__', 'Identity did not update.');
            $request = $this->request($applicant);
            $controller = app(PersonnelController::class);
            $printed = $controller->printProfile($request)->getContent();
            $pdf = $controller->downloadProfile($request)->getContent();
            $this->check($printed !== false && str_contains($printed, '__183_NAME_CHECK__'), 'Print view did not use the updated profile.');
            $this->check($pdf !== false && str_starts_with($pdf, '%PDF-'), 'Updated profile PDF could not be rendered.');
            $this->restore($admin, $copy, 'h50', $profile->Id, ['h5001' => $profile->h5001, 'h5084' => $profile->h5084, 'updateTime' => $profile->updateTime]);
            $this->restore($admin, $copy, 'user', $applicant->Id, ['truename' => $identity->truename, 'updatetime' => $identity->updatetime]);
            $this->results['correction_submit_withdraw_reject_approve_identity_pdf'] = true;

            $writer = app(AssessmentWriter::class);
            foreach ([['saved', ['Memo' => '<p>New instance verification</p>']], ['submitted', ['h2025' => 'y']],
                ['withdrawn', ['h2025' => 'n']], ['submitted', ['h2025' => 'y']],
                ['approved', ['h2030' => 'y', 'h2013' => (string) DB::connection('legacy')->table('d519')->value('objname')]]] as [$action, $updates]) {
                $row = DB::connection('legacy')->table('h20')->where('Id', $assessment->Id)->first();
                $writer->change($row->Id, $row->h2002, $writer->version($row), $action, $updates);
            }
            $this->results['assessment_save_submit_withdraw_approve'] = true;

            $leave = app(LeaveController::class);
            $leaveWriter = app(LeaveWriter::class);
            $before = DB::connection('legacy')->table('a34')->max('Id');
            $leave->save($this->request($applicant, ['details' => [[
                'person_id' => $applicant->Id, 'leave_type' => (int) DB::connection('legacy')->table('ud10')->value('Id'),
                'time_type' => 3, 'start' => '2030-01-02', 'end' => '2030-01-02', 'note' => 'New instance verification',
            ]]]));
            $leaveId = (int) DB::connection('legacy')->table('a34')->where('Id', '>', $before)->where('CreatorId', $applicant->Id)->max('Id');
            $this->check($leaveId > 0, 'Leave draft was not created.');
            foreach (['submit', 'withdraw', 'submit'] as $action) {
                $row = DB::connection('legacy')->table('a34')->where('Id', $leaveId)->first();
                $leave->{$action}($this->request($applicant, ['version' => $leaveWriter->version($row)]), 'a34', $leaveId);
            }
            $this->approveLeave($leave, $leaveWriter, 'a34', $leaveId);
            $detailId = DB::connection('legacy')->table('a35')->where('parentid', $leaveId)->value('Id');
            $leave->saveCancellation($this->request($applicant, ['detail_id' => $detailId, 'date' => '2030-01-02', 'note' => 'New instance verification']));
            $cancellationId = (int) DB::connection('legacy')->table('a38')->where('a3814', $detailId)->max('Id');
            foreach (['submit', 'withdraw', 'submit'] as $action) {
                $row = DB::connection('legacy')->table('a38')->where('Id', $cancellationId)->first();
                $leave->{$action}($this->request($applicant, ['version' => $leaveWriter->version($row)]), 'a38', $cancellationId);
            }
            $this->approveLeave($leave, $leaveWriter, 'a38', $cancellationId);
            $this->results['leave_and_cancellation_create_submit_withdraw_approve'] = true;
        } finally {
            if (DB::connection('mysql')->transactionLevel() > 0) {
                DB::connection('mysql')->rollBack();
            }
            $this->restore($admin, $copy, 'h50', $profile->Id, ['h5001' => $profile->h5001, 'h5084' => $profile->h5084, 'updateTime' => $profile->updateTime]);
            $this->restore($admin, $copy, 'user', $applicant->Id, ['truename' => $identity->truename, 'updatetime' => $identity->updatetime]);
            $this->restore($admin, $copy, 'h20', $assessment->Id, ['Memo' => $assessment->Memo, 'h2025' => $assessment->h2025,
                'h2030' => $assessment->h2030, 'h2013' => $assessment->h2013, 'updateTime' => $assessment->updateTime]);
            foreach (['a34' => $leaveId, 'a38' => $cancellationId] as $kind => $id) {
                if ($id) {
                    $statement = $copy->prepare($admin, 'DELETE FROM `gz_auditors` WHERE tablename=? AND tableId=?');
                    $statement->execute([$kind, $id]);
                    if ($kind === 'a34') {
                        $statement = $copy->prepare($admin, 'DELETE FROM `a35` WHERE parentid=?');
                        $statement->execute([$id]);
                    }
                    $statement = $copy->prepare($admin, 'DELETE FROM '.$copy->quote($kind).' WHERE Id=?');
                    $statement->execute([$id]);
                }
            }
        }
        $this->results['passed'] = true;
        $this->results['target_host'] = config('data_copy.target.host');
        $this->results['completed_at'] = now()->toIso8601String();
        file_put_contents(storage_path('app/private/migration/write-verification.json'), json_encode($this->results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->info('Target write workflows and updated PDF passed; business test changes restored and audit test rows rolled back.');

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $data */
    private function request(User $user, array $data = []): Request
    {
        $request = Request::create('/personnel/leave', 'POST', $data);
        $request->setUserResolver(fn () => $user);
        $session = new Store('copy-verification', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        app('redirect')->setSession($session);

        return $request;
    }

    private function approveLeave(LeaveController $controller, LeaveWriter $writer, string $kind, int $id): void
    {
        for ($step = 0; $step < 20; $step++) {
            $row = DB::connection('legacy')->table($kind)->where('Id', $id)->first();
            if (($kind === 'a34' ? $row->a3433 : $row->a3833) === '审批结束') {
                return;
            }
            $name = $kind === 'a34' ? $row->a3433 : $row->a3833;
            $userId = DB::connection('legacy')->table('gz_auditors')->where('tablename', $kind)->where('tableId', $id)
                ->where('auditorName', $name)->value('auditorId');
            $reviewer = User::query()->where('Id', $userId)->firstOrFail();
            $controller->approve($this->request($reviewer, ['version' => $writer->version($row)]), $kind, $id);
        }
        throw new RuntimeException('Approval flow exceeded the maximum expected steps.');
    }

    /** @param array<string,mixed> $values */
    private function restore(PDO $db, InstanceDataCopy $copy, string $table, int $id, array $values): void
    {
        $columns = implode(',', array_map(fn (string $field): string => $copy->quote($field).'=?', array_keys($values)));
        $statement = $copy->prepare($db, 'UPDATE '.$copy->quote($table).' SET '.$columns.' WHERE Id=?');
        $statement->execute([...array_values($values), $id]);
    }

    private function check(bool $success, string $message): void
    {
        if (! $success) {
            throw new RuntimeException($message);
        }
    }

    private function correction(int $id): stdClass
    {
        $row = DB::table('profile_correction_requests')->where('id', $id)->first();
        if ($row === null) {
            throw new RuntimeException('Verification correction is unavailable.');
        }

        return $row;
    }
}
