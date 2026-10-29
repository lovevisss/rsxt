<?php

use App\Models\User;
use App\Services\LeaveWriter;
use App\Services\LegacyReadOnlyGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->leaveDb = tempnam(sys_get_temp_dir(), 'leave-test-');
    $connection = ['driver' => 'sqlite', 'database' => $this->leaveDb, 'prefix' => '', 'foreign_key_constraints' => false];
    config()->set('database.connections.legacy', [...$connection, 'username' => 'reader']);
    config()->set('database.connections.legacy_leave_writer', [...$connection, 'username' => 'writer']);
    config()->set('leave.write_target', 'production');
    config()->set('leave.production_writes_enabled', true);
    DB::purge('legacy');
    DB::purge('legacy_leave_writer');

    Schema::connection('legacy')->create('user', function (Blueprint $table) {
        $table->integer('Id')->primary();
        $table->string('username');
        $table->string('truename');
        $table->integer('departmentId');
        $table->integer('isuse')->default(1);
        $table->integer('IsSA')->default(0);
    });
    Schema::connection('legacy')->create('h50', function (Blueprint $table) {
        $table->integer('Id')->primary();
        $table->string('h5002');
        $table->string('yndelete')->default('0');
    });
    Schema::connection('legacy')->create('department', function (Blueprint $table) {
        $table->integer('Id')->primary();
        $table->string('objname');
        $table->string('name');
    });
    Schema::connection('legacy')->create('userroles', function (Blueprint $table) { $table->integer('userId'); $table->integer('roleId'); });
    Schema::connection('legacy')->create('treeroles', function (Blueprint $table) { $table->integer('roleid'); $table->integer('treeid'); });
    Schema::connection('legacy')->create('gz_dpleaders', function (Blueprint $table) {
        $table->integer('departmentid'); $table->integer('userId'); $table->string('yndelete');
    });
    Schema::connection('legacy')->create('modules', function (Blueprint $table) {
        $table->integer('Id')->primary(); $table->string('dbTable'); $table->integer('isuse');
        $table->string('userIds'); $table->string('ModuleName');
    });
    Schema::connection('legacy')->create('workflowmodule_users', function (Blueprint $table) {
        $table->integer('workflowModuleId'); $table->integer('UserId'); $table->integer('SeriaNo');
    });
    Schema::connection('legacy')->create('gz_auditors', function (Blueprint $table) {
        $table->increments('Id'); $table->string('tablename'); $table->integer('tableId');
        $table->integer('auditorId'); $table->string('auditorName'); $table->integer('step');
        $table->string('workflowType')->nullable(); $table->integer('workflowId')->nullable();
        $table->integer('workflowUserid')->nullable(); $table->integer('creatorid')->nullable();
        $table->string('auditorIds')->nullable(); $table->string('AuditorNames')->nullable();
    });
    Schema::connection('legacy')->create('ud10', function (Blueprint $table) {
        $table->integer('Id')->primary(); $table->string('name'); $table->integer('xuhao');
    });
    Schema::connection('legacy')->create('ud09', function (Blueprint $table) {
        $table->integer('Id')->primary(); $table->string('name'); $table->integer('xuhao');
    });
    Schema::connection('legacy')->create('a34', function (Blueprint $table) {
        $table->increments('Id');
        foreach (['a3401', 'a3402', 'a3404', 'a3406', 'a3410', 'a3412', 'a3413', 'a3430', 'a3433', 'a3440', 'a3452', 'issubmit', 'yndelete', 'updateTime'] as $field) {
            $table->string($field)->nullable();
        }
        $table->integer('CreatorId')->nullable();
    });
    Schema::connection('legacy')->create('a35', function (Blueprint $table) {
        $table->increments('Id'); $table->integer('parentid');
        foreach (['a3501', 'a3502', 'a3503', 'a3504', 'a3505', 'a3506', 'a3508', 'a3509', 'a3511', 'a3537', 'a3552', 'yndelete'] as $field) {
            $table->string($field)->nullable();
        }
        $table->integer('CreatorId')->nullable();
    });
    Schema::connection('legacy')->create('a38', function (Blueprint $table) {
        $table->increments('Id');
        foreach (['a3801', 'a3802', 'a3804', 'a3805', 'a3806', 'a3807', 'a3808', 'a3809', 'a3810', 'a3811', 'a3812', 'a3813', 'a3814', 'a3830', 'a3833', 'issubmit', 'yndelete', 'updateTime'] as $field) {
            $table->string($field)->nullable();
        }
        $table->integer('CreatorId')->nullable();
    });
    Schema::create('leave_events', function (Blueprint $table) {
        $table->increments('id'); $table->string('source'); $table->string('kind'); $table->integer('legacy_id');
        $table->integer('actor_user_id'); $table->string('action'); $table->string('note')->nullable(); $table->timestamp('created_at');
    });
    Schema::create('leave_attachments', function (Blueprint $table) {
        $table->increments('id'); $table->string('source'); $table->integer('legacy_detail_id'); $table->string('path');
        $table->string('original_name'); $table->string('mime'); $table->integer('size'); $table->timestamp('created_at');
    });

    DB::connection('legacy')->table('user')->insert([
        ['Id' => 1, 'username' => 'E1', 'truename' => '申请人', 'departmentId' => 10],
        ['Id' => 2, 'username' => 'E2', 'truename' => '同部门同事', 'departmentId' => 10],
        ['Id' => 3, 'username' => 'R1', 'truename' => '部门领导', 'departmentId' => 10],
        ['Id' => 4, 'username' => 'R2', 'truename' => '第二审批人', 'departmentId' => 10],
        ['Id' => 5, 'username' => 'E5', 'truename' => '其他部门', 'departmentId' => 20],
    ]);
    DB::connection('legacy')->table('h50')->insert([
        ['Id' => 11, 'h5002' => 'E1'], ['Id' => 12, 'h5002' => 'E2'],
        ['Id' => 13, 'h5002' => 'R1'], ['Id' => 14, 'h5002' => 'R2'], ['Id' => 15, 'h5002' => 'E5'],
    ]);
    DB::connection('legacy')->table('department')->insert([
        ['Id' => 10, 'objname' => 'D1', 'name' => '本部门'],
        ['Id' => 20, 'objname' => 'D2', 'name' => '其他部门'],
    ]);
    DB::connection('legacy')->table('userroles')->insert([
        ['userId' => 1, 'roleId' => 1], ['userId' => 2, 'roleId' => 1],
        ['userId' => 3, 'roleId' => 2], ['userId' => 4, 'roleId' => 2],
        ['userId' => 5, 'roleId' => 1],
    ]);
    foreach ([408, 409, 411, 426] as $menu) {
        DB::connection('legacy')->table('treeroles')->insert(['roleid' => 1, 'treeid' => $menu]);
    }
    foreach ([412, 413] as $menu) {
        DB::connection('legacy')->table('treeroles')->insert(['roleid' => 2, 'treeid' => $menu]);
    }
    DB::connection('legacy')->table('gz_dpleaders')->insert(['departmentid' => 10, 'userId' => 3, 'yndelete' => '0']);
    DB::connection('legacy')->table('modules')->insert(['Id' => 10, 'dbTable' => 'a34', 'isuse' => 1, 'userIds' => ',1,2,5,', 'ModuleName' => '两级请假流程']);
    DB::connection('legacy')->table('workflowmodule_users')->insert([
        ['workflowModuleId' => 10, 'UserId' => 9999, 'SeriaNo' => 1],
        ['workflowModuleId' => 10, 'UserId' => 4, 'SeriaNo' => 2],
    ]);
    DB::connection('legacy')->table('ud10')->insert([['Id' => 1, 'name' => '病假', 'xuhao' => 10], ['Id' => 2, 'name' => '事假', 'xuhao' => 20]]);
    DB::connection('legacy')->table('ud09')->insert([['Id' => 1, 'name' => '上午半天', 'xuhao' => 10], ['Id' => 3, 'name' => '全天', 'xuhao' => 30]]);
    app(LegacyReadOnlyGuard::class)->attach(DB::connection('legacy'));
});

afterEach(function () {
    DB::disconnect('legacy'); DB::disconnect('legacy_leave_writer'); @unlink($this->leaveDb);
});

function leaveVersion(string $kind, int $id): string
{
    return app(LeaveWriter::class)->version(DB::connection('legacy')->table($kind)->where('Id', $id)->first());
}

test('archived applications and delegated leave details remain visible to their respective users', function () {
    DB::connection('legacy_leave_writer')->table('a34')->insert([
        'Id' => 100, 'CreatorId' => 1, 'a3401' => '1)申请人', 'a3410' => '2',
        'a3413' => '202402', 'a3452' => 'y', 'issubmit' => 'y', 'yndelete' => '1',
    ]);
    DB::connection('legacy_leave_writer')->table('a35')->insert([
        'Id' => 200, 'parentid' => 100, 'a3511' => '2', 'a3506' => '12)同部门同事',
        'a3501' => '1)病假', 'a3502' => '2024-02-26', 'a3503' => '2024-03-01', 'yndelete' => '0',
    ]);
    $this->actingAs(User::find(1));
    $this->get('/personnel/leave')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Personnel/Leave/Index')->has('records.data', 1));
    $this->actingAs(User::find(2));
    $this->get('/personnel/leave/details')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Personnel/Leave/Index')->has('records.data', 1));
    $this->get('/personnel/leave/a34/100')->assertOk();
    $this->actingAs(User::find(5));
    $this->get('/personnel/leave/a34/100')->assertForbidden();
});

test('delegated multi-detail request passes two reviewers and links a cancellation', function () {
    $this->actingAs(User::find(1));
    $this->post('/personnel/leave', ['details' => [
        ['person_id' => 1, 'leave_type' => 1, 'time_type' => 3, 'start' => '2026-11-01', 'end' => '2026-11-03'],
        ['person_id' => 2, 'leave_type' => 2, 'time_type' => 3, 'start' => '2026-11-04', 'end' => '2026-11-05'],
    ]])->assertRedirect();
    $id = (int) DB::connection('legacy')->table('a34')->max('Id');
    expect(DB::connection('legacy')->table('a34')->where('Id', $id)->value('a3410'))->toBe('2');
    expect(DB::connection('legacy')->table('a35')->where('parentid', $id)->count())->toBe(2);
    $this->post("/personnel/leave/a34/$id/submit", ['version' => leaveVersion('a34', $id)])->assertRedirect();
    expect(DB::connection('legacy')->table('a34')->where('Id', $id)->value('a3433'))->toBe('部门领导');
    $this->actingAs(User::find(5));
    $this->post("/personnel/leave/a34/$id/approve", ['version' => leaveVersion('a34', $id)])->assertForbidden();
    $this->actingAs(User::find(3));
    $this->post("/personnel/leave/a34/$id/approve", ['version' => leaveVersion('a34', $id)])->assertRedirect();
    expect(DB::connection('legacy')->table('a34')->where('Id', $id)->value('a3433'))->toBe('第二审批人');
    $this->actingAs(User::find(4));
    $this->post("/personnel/leave/a34/$id/approve", ['version' => leaveVersion('a34', $id)])->assertRedirect();
    expect(DB::connection('legacy')->table('a34')->where('Id', $id)->value('a3452'))->toBe('y');
    expect(DB::connection('legacy')->table('a35')->where('parentid', $id)->where('a3552', 'y')->count())->toBe(2);

    $detail = (int) DB::connection('legacy')->table('a35')->where('parentid', $id)->where('a3511', '2')->value('Id');
    $this->actingAs(User::find(1));
    $this->post('/personnel/leave/cancellations', ['detail_id' => $detail, 'date' => '2026-11-04'])->assertRedirect();
    $cancelId = (int) DB::connection('legacy')->table('a38')->max('Id');
    expect(DB::connection('legacy')->table('a38')->where('Id', $cancelId)->value('a3814'))->toBe((string) $detail);
    expect(DB::connection('legacy')->table('a38')->where('Id', $cancelId)->value('a3805'))->toBe('2');
    $this->post("/personnel/leave/a38/$cancelId/submit", ['version' => leaveVersion('a38', $cancelId)])->assertRedirect();
    $this->actingAs(User::find(3));
    $this->post("/personnel/leave/a38/$cancelId/approve", ['version' => leaveVersion('a38', $cancelId)])->assertRedirect();
    $this->actingAs(User::find(4));
    $this->post("/personnel/leave/a38/$cancelId/approve", ['version' => leaveVersion('a38', $cancelId)])->assertRedirect();
    expect(DB::connection('legacy')->table('a38')->where('Id', $cancelId)->value('a3833'))->toBe('审批结束');
});

test('cross-department delegation fails and stale review cannot overwrite approval', function () {
    $this->actingAs(User::find(1));
    $this->post('/personnel/leave', ['details' => [
        ['person_id' => 5, 'leave_type' => 1, 'time_type' => 3, 'start' => '2026-11-01', 'end' => '2026-11-03'],
    ]])->assertSessionHasErrors('details');
    expect(DB::connection('legacy')->table('a34')->count())->toBe(0);
    $this->post('/personnel/leave', ['details' => [
        ['person_id' => 1, 'leave_type' => 1, 'time_type' => 3, 'start' => '2026-11-01', 'end' => '2026-11-03'],
    ]])->assertRedirect();
    $id = (int) DB::connection('legacy')->table('a34')->max('Id');
    $this->post("/personnel/leave/a34/$id/submit", ['version' => leaveVersion('a34', $id)])->assertRedirect();
    $stale = leaveVersion('a34', $id);
    $this->actingAs(User::find(3));
    $this->post("/personnel/leave/a34/$id/return", ['version' => $stale, 'note' => '补充证明'])->assertRedirect();
    $this->get('/personnel/leave/review')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Personnel/Leave/Index')->has('records', 1));
    $this->get("/personnel/leave/a34/$id")->assertOk();
    $this->post("/personnel/leave/a34/$id/approve", ['version' => $stale])->assertForbidden();
    expect(DB::connection('legacy')->table('a34')->where('Id', $id)->value('issubmit'))->toBe('n');
});

test('withdrawal and returned applications can be edited and resubmitted', function () {
    $this->actingAs(User::find(1));
    $this->post('/personnel/leave', ['details' => [
        ['person_id' => 1, 'leave_type' => 1, 'time_type' => 3, 'start' => '2026-12-01', 'end' => '2026-12-02'],
    ]])->assertRedirect();
    $id = (int) DB::connection('legacy')->table('a34')->max('Id');
    $this->post("/personnel/leave/a34/$id/submit", ['version' => leaveVersion('a34', $id)])->assertRedirect();
    $this->post("/personnel/leave/a34/$id/withdraw", ['version' => leaveVersion('a34', $id)])->assertRedirect();
    expect(DB::connection('legacy')->table('gz_auditors')->where('tablename', 'a34')->where('tableId', $id)->count())->toBe(0);
    $this->post("/personnel/leave/a34/$id/submit", ['version' => leaveVersion('a34', $id)])->assertRedirect();
    $this->actingAs(User::find(3));
    $this->post("/personnel/leave/a34/$id/return", ['version' => leaveVersion('a34', $id), 'note' => '修改日期'])->assertRedirect();
    $this->actingAs(User::find(1));
    $detailId = (int) DB::connection('legacy')->table('a35')->where('parentid', $id)->value('Id');
    $this->post("/personnel/leave/a34/$id", ['version' => leaveVersion('a34', $id), 'details' => [
        ['id' => $detailId, 'person_id' => 1, 'leave_type' => 1, 'time_type' => 3,
            'start' => '2026-12-03', 'end' => '2026-12-04'],
    ]])->assertRedirect();
    expect(DB::connection('legacy')->table('a35')->where('Id', $detailId)->value('a3502'))->toBe('2026-12-03');
    $this->post("/personnel/leave/a34/$id/submit", ['version' => leaveVersion('a34', $id)])->assertRedirect();
    expect(DB::connection('legacy')->table('a34')->where('Id', $id)->value('issubmit'))->toBe('y');
});

test('attachment access is limited to its leave subject applicant and assigned reviewer', function () {
    Storage::fake('public');
    config()->set('leave.public_attachment_url', 'https://new.example.test/storage');
    $this->actingAs(User::find(1));
    $this->post('/personnel/leave', ['details' => [
        ['person_id' => 1, 'leave_type' => 1, 'time_type' => 3, 'start' => '2026-12-01',
            'end' => '2026-12-02', 'attachment' => UploadedFile::fake()->create('proof.pdf', 2, 'application/pdf')],
        ['person_id' => 2, 'leave_type' => 2, 'time_type' => 3, 'start' => '2026-12-03', 'end' => '2026-12-04'],
    ]])->assertRedirect();
    $id = (int) DB::connection('legacy')->table('a34')->max('Id');
    $detailId = (int) DB::connection('legacy')->table('a35')->where('parentid', $id)->where('a3511', '1')->value('Id');
    $this->get("/personnel/leave/attachments/$detailId")->assertOk();
    $this->actingAs(User::find(2));
    $this->get("/personnel/leave/attachments/$detailId")->assertForbidden();
    $this->get("/personnel/leave/a34/$id")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Personnel/Leave/Show')->has('details', 1));
});

test('a legacy edit to a detail invalidates an open draft form', function () {
    $this->actingAs(User::find(1));
    $this->post('/personnel/leave', ['details' => [
        ['person_id' => 1, 'leave_type' => 1, 'time_type' => 3, 'start' => '2026-12-01', 'end' => '2026-12-02'],
    ]])->assertRedirect();
    $id = (int) DB::connection('legacy')->table('a34')->max('Id');
    $detailId = (int) DB::connection('legacy')->table('a35')->where('parentid', $id)->value('Id');
    $version = leaveVersion('a34', $id);
    DB::connection('legacy_leave_writer')->table('a35')->where('Id', $detailId)->update(['a3505' => '旧系统已修改']);
    $this->post("/personnel/leave/a34/$id", ['version' => $version, 'details' => [
        ['id' => $detailId, 'person_id' => 1, 'leave_type' => 1, 'time_type' => 3,
            'start' => '2026-12-03', 'end' => '2026-12-04'],
    ]])->assertStatus(409);
    expect(DB::connection('legacy')->table('a35')->where('Id', $detailId)->value('a3505'))->toBe('旧系统已修改');
});
