<?php

use App\Models\User;
use App\Services\LegacyReadOnlyGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Artisan::call('migrate');
    foreach (['legacy', 'legacy_stage_writer'] as $connection) {
        config()->set("database.connections.$connection", [
            'driver' => 'sqlite', 'database' => ':memory:', 'host' => $connection,
            'prefix' => '', 'foreign_key_constraints' => false,
        ]);
        DB::purge($connection);
        Schema::connection($connection)->create('user', function (Blueprint $table) {
            $table->integer('Id')->primary();
            $table->string('username');
            $table->string('truename');
            $table->integer('isUse')->default(1);
            $table->integer('IsSA')->default(0);
        });
        Schema::connection($connection)->create('h50', function (Blueprint $table) {
            $table->integer('Id')->primary();
            $table->string('h5002');
            $table->string('yndelete');
            $table->string('updateTime')->nullable();
            foreach (array_merge(...array_values(config('profile_corrections.fields'))) as $key) {
                $table->string($key)->nullable();
            }
        });
        DB::connection($connection)->table('user')->insert([
            ['Id' => 1, 'username' => 'E001', 'truename' => '甲'],
            ['Id' => 2, 'username' => 'E002', 'truename' => '乙'],
            ['Id' => 3, 'username' => 'HR01', 'truename' => '审核员'],
        ]);
        DB::connection($connection)->table('h50')->insert([
            ['Id' => 11, 'h5002' => 'E001', 'h5001' => '甲', 'h5090' => 'A', 'h5084' => '原地址', 'yndelete' => '0'],
            ['Id' => 12, 'h5002' => 'E002', 'h5001' => '乙', 'h5090' => 'B', 'h5084' => null, 'yndelete' => '0'],
        ]);
    }
    Schema::connection('legacy')->create('userroles', function (Blueprint $table) {
        $table->integer('userId');
        $table->integer('roleId');
    });
    Schema::connection('legacy')->create('treeroles', function (Blueprint $table) {
        $table->integer('roleid');
        $table->integer('treeid');
    });
    DB::connection('legacy')->table('userroles')->insert([
        ['userId' => 1, 'roleId' => 5], ['userId' => 1, 'roleId' => 15], ['userId' => 2, 'roleId' => 6], ['userId' => 3, 'roleId' => 15],
    ]);
    DB::connection('legacy')->table('treeroles')->insert([
        ['roleid' => 5, 'treeid' => 303], ['roleid' => 5, 'treeid' => 333],
        ['roleid' => 6, 'treeid' => 303],
    ]);
    foreach (array_unique(array_column(config('profile_corrections.dictionaries'), 'table')) as $dictionary) {
        Schema::connection('legacy')->create($dictionary, function (Blueprint $table) {
            $table->integer('Id')->nullable();
            $table->string('objname')->nullable();
            $table->string('objjc')->nullable();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->string('typeName')->nullable();
        });
    }
    app(LegacyReadOnlyGuard::class)->attach(DB::connection('legacy'));
    config()->set('profile_corrections.write_target', 'stage');
    Storage::fake('local');
});

function correctionUser(int $id): User
{
    return User::findOrFail($id);
}

function submitCorrection($test, array $changes = ['h5084' => '新地址'], array $files = []): void
{
    $test->actingAs(correctionUser(1))->post('/personnel/corrections', [
        'reason' => '信息需要更正', 'changes' => $changes, 'evidence' => $files,
    ])->assertRedirect('/personnel/corrections/1');
}

test('applicant requires both legacy menu permissions and may only submit whitelisted fields', function () {
    $this->actingAs(correctionUser(2))->get('/personnel/corrections')->assertForbidden();
    $this->actingAs(correctionUser(2))->post('/personnel/corrections', [
        'reason' => '更正', 'changes' => ['h5090' => 'Other'],
    ])->assertForbidden();
    $this->actingAs(correctionUser(1))->post('/personnel/corrections', [
        'reason' => '更正', 'changes' => ['h5002' => 'E002'],
    ])->assertSessionHasErrors('changes');
    $this->assertDatabaseCount('profile_correction_requests', 0);
});

test('multiple fields and evidence stay private until whole request is approved', function () {
    submitCorrection($this, ['h5001' => '甲新', 'h5084' => '新地址'], [UploadedFile::fake()->create('proof.pdf', 5, 'application/pdf')]);
    $this->assertDatabaseHas('profile_correction_requests', ['id' => 1, 'status' => 'pending']);
    expect(DB::connection('legacy')->table('h50')->where('Id', 11)->value('h5001'))->toBe('甲');
    $this->actingAs(correctionUser(2))->get('/personnel/corrections/1')->assertForbidden();
    $this->actingAs(correctionUser(2))->get('/personnel/corrections/1/files/1')->assertForbidden();
    $this->actingAs(correctionUser(1))->get('/personnel/corrections/1/files/1')->assertOk();
    $this->actingAs(correctionUser(1))->post('/personnel/corrections', [
        'reason' => '另一单', 'changes' => ['h5090' => 'Another'],
    ])->assertSessionHasErrors('changes');

    $this->actingAs(correctionUser(3))->post('/personnel/admin/profile-corrections/1/approve')->assertRedirect();
    $this->assertDatabaseHas('profile_correction_requests', ['id' => 1, 'status' => 'applied']);
    expect(DB::connection('legacy_stage_writer')->table('h50')->where('Id', 11)->value('h5001'))->toBe('甲新');
    expect(DB::connection('legacy_stage_writer')->table('user')->where('Id', 1)->value('truename'))->toBe('甲新');
    expect(DB::connection('legacy')->table('h50')->where('Id', 11)->value('h5001'))->toBe('甲');
    expect(DB::table('profile_correction_events')->where('request_id', 1)->pluck('action')->all())->toBe(['submitted', 'approved', 'applied']);
});

test('evidence is required for sensitive fields and missing value or invalid length is rejected', function () {
    $this->actingAs(correctionUser(1))->post('/personnel/corrections', [
        'reason' => '更正姓名', 'changes' => ['h5001' => '甲新'],
    ])->assertSessionHasErrors('evidence');
    $this->actingAs(correctionUser(1))->post('/personnel/corrections', [
        'reason' => '更正婚姻', 'changes' => ['h5065' => '12'],
    ])->assertSessionHasErrors('changes.h5065');
    $this->assertDatabaseCount('profile_correction_requests', 0);
});

test('withdraw and rejection release active slot and reject requires reason', function () {
    submitCorrection($this);
    $this->actingAs(correctionUser(1))->post('/personnel/corrections/1/withdraw')->assertRedirect();
    $this->assertDatabaseHas('profile_correction_requests', ['id' => 1, 'status' => 'withdrawn', 'active_employee_number' => null]);
    $this->actingAs(correctionUser(1))->post('/personnel/corrections', [
        'reason' => '再次申请', 'changes' => ['h5090' => 'AA'],
    ])->assertRedirect('/personnel/corrections/2');
    $this->actingAs(correctionUser(3))->post('/personnel/admin/profile-corrections/2/reject')->assertSessionHasErrors('note');
    $this->actingAs(correctionUser(3))->post('/personnel/admin/profile-corrections/2/reject', ['note' => '缺少说明'])->assertRedirect();
    $this->assertDatabaseHas('profile_correction_requests', ['id' => 2, 'status' => 'rejected', 'review_note' => '缺少说明']);
});

test('stale stage data produces conflict without overwriting its newer value', function () {
    submitCorrection($this);
    DB::connection('legacy_stage_writer')->table('h50')->where('Id', 11)->update(['h5084' => '别的流程已改']);
    $this->actingAs(correctionUser(3))->post('/personnel/admin/profile-corrections/1/approve')->assertRedirect();
    $this->assertDatabaseHas('profile_correction_requests', ['id' => 1, 'status' => 'conflict']);
    expect(DB::connection('legacy_stage_writer')->table('h50')->where('Id', 11)->value('h5084'))->toBe('别的流程已改');
});

test('failed write remains retryable and production write target is disabled by default', function () {
    submitCorrection($this);
    config()->set('profile_corrections.write_target', 'disabled');
    $this->actingAs(correctionUser(3))->post('/personnel/admin/profile-corrections/1/approve')->assertStatus(503);
    $this->assertDatabaseHas('profile_correction_requests', ['id' => 1, 'status' => 'pending']);
    config()->set('profile_corrections.write_target', 'stage');
    Schema::connection('legacy_stage_writer')->table('h50', fn (Blueprint $table) => $table->dropColumn('updateTime'));
    $this->actingAs(correctionUser(3))->post('/personnel/admin/profile-corrections/1/approve')->assertRedirect();
    $this->assertDatabaseHas('profile_correction_requests', ['id' => 1, 'status' => 'apply_failed']);
    Schema::connection('legacy_stage_writer')->table('h50', fn (Blueprint $table) => $table->string('updateTime')->nullable());
    $this->actingAs(correctionUser(3))->post('/personnel/admin/profile-corrections/1/retry')->assertRedirect();
    $this->assertDatabaseHas('profile_correction_requests', ['id' => 1, 'status' => 'applied']);
    expect(DB::connection('legacy_stage_writer')->table('h50')->where('Id', 11)->value('h5084'))->toBe('新地址');
});

test('reviewer cannot approve their own application', function () {
    submitCorrection($this);
    $this->actingAs(correctionUser(1))->post('/personnel/admin/profile-corrections/1/approve')->assertForbidden();
    $this->assertDatabaseHas('profile_correction_requests', ['id' => 1, 'status' => 'pending']);
});

test('local preview sessions cannot create or review corrections', function () {
    $this->withSession(['personnel_preview' => true])->actingAs(correctionUser(1))->post('/personnel/corrections', [
        'reason' => '预览', 'changes' => ['h5084' => '新地址'],
    ])->assertForbidden();
    $this->assertDatabaseCount('profile_correction_requests', 0);
    $this->withSession(['personnel_preview' => false]);
    submitCorrection($this);
    $this->withSession(['personnel_preview' => true])->actingAs(correctionUser(3))
        ->post('/personnel/admin/profile-corrections/1/approve')->assertForbidden();
    $this->assertDatabaseHas('profile_correction_requests', ['id' => 1, 'status' => 'pending']);
});

test('older local preview sessions without a flag remain read only', function () {
    app()->detectEnvironment(fn () => 'local');
    $this->withoutMiddleware(PreventRequestForgery::class);
    config()->set('personnel.local_preview', true);
    config()->set('cas.service_url', null);
    $this->actingAs(correctionUser(1))->post('/personnel/corrections', [
        'reason' => '预览', 'changes' => ['h5084' => '新地址'],
    ])->assertForbidden();
    $this->assertDatabaseCount('profile_correction_requests', 0);
});
