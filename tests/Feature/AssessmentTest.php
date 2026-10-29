<?php

use App\Models\User;
use App\Services\AssessmentWriter;
use App\Services\LegacyReadOnlyGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->assessmentDb = tempnam(sys_get_temp_dir(), 'assessment-test-');
    $connection = ['driver' => 'sqlite', 'database' => $this->assessmentDb, 'prefix' => '', 'foreign_key_constraints' => false];
    config()->set('database.connections.legacy', [...$connection, 'username' => 'reader']);
    config()->set('database.connections.legacy_assessment_writer', [...$connection, 'username' => 'writer']);
    config()->set('assessments.write_target', 'production');
    config()->set('assessments.production_writes_enabled', true);
    DB::purge('legacy');
    DB::purge('legacy_assessment_writer');

    Schema::connection('legacy')->create('user', function (Blueprint $table) {
        $table->integer('Id')->primary();
        $table->string('username');
        $table->string('truename');
        $table->integer('IsSA')->default(0);
    });
    Schema::connection('legacy')->create('userroles', function (Blueprint $table) {
        $table->integer('userId');
        $table->integer('roleId');
    });
    Schema::connection('legacy')->create('treeroles', function (Blueprint $table) {
        $table->integer('roleid');
        $table->integer('treeid');
    });
    Schema::connection('legacy')->create('department', function (Blueprint $table) {
        $table->integer('Id')->primary();
        $table->string('objname');
        $table->string('name');
    });
    Schema::connection('legacy')->create('gz_dpleaders', function (Blueprint $table) {
        $table->integer('departmentid');
        $table->integer('userId');
        $table->string('yndelete');
    });
    Schema::connection('legacy')->create('d519', function (Blueprint $table) {
        $table->string('objname');
        $table->string('objjc');
        $table->string('yndelete');
    });
    Schema::connection('legacy')->create('h20', function (Blueprint $table) {
        $table->integer('Id')->primary();
        $table->string('yndelete');
        $table->string('h2002');
        foreach (['h2001', 'h2003', 'h2004', 'h2005', 'h2006', 'h2007', 'h2008', 'h2009', 'h2013', 'h2014', 'h2017', 'h2029'] as $field) {
            $table->string($field)->nullable();
        }
        foreach (['h2025', 'h2030', 'h2026', 'h2031'] as $field) {
            $table->string($field)->default('n');
        }
        $table->text('Memo')->nullable();
        $table->string('updateTime');
    });
    Schema::create('assessment_events', function (Blueprint $table) {
        $table->id();
        $table->integer('legacy_assessment_id');
        $table->string('employee_number');
        $table->integer('actor_user_id');
        $table->string('action');
        $table->string('status')->default('pending');
        $table->text('note')->nullable();
        $table->string('result_code')->nullable();
        $table->timestamp('created_at');
    });

    DB::connection('legacy')->table('user')->insert([
        ['Id' => 1, 'username' => 'E001', 'truename' => '甲'],
        ['Id' => 2, 'username' => 'E002', 'truename' => '乙'],
        ['Id' => 3, 'username' => 'R001', 'truename' => '审核甲'],
        ['Id' => 4, 'username' => 'R002', 'truename' => '审核乙'],
    ]);
    DB::connection('legacy')->table('userroles')->insert([
        ['userId' => 1, 'roleId' => 1], ['userId' => 2, 'roleId' => 1],
        ['userId' => 3, 'roleId' => 2], ['userId' => 4, 'roleId' => 2],
    ]);
    DB::connection('legacy')->table('treeroles')->insert([
        ['roleid' => 1, 'treeid' => 379], ['roleid' => 2, 'treeid' => 380],
    ]);
    DB::connection('legacy')->table('department')->insert([
        ['Id' => 10, 'objname' => 'D1', 'name' => '信息技术部'],
        ['Id' => 20, 'objname' => 'D2', 'name' => '财务部'],
    ]);
    DB::connection('legacy')->table('gz_dpleaders')->insert([
        ['departmentid' => 10, 'userId' => 3, 'yndelete' => '0'],
        ['departmentid' => 20, 'userId' => 4, 'yndelete' => '0'],
    ]);
    DB::connection('legacy')->table('d519')->insert([
        ['objname' => '1', 'objjc' => '优秀', 'yndelete' => '0'],
        ['objname' => '2', 'objjc' => '合格', 'yndelete' => '0'],
    ]);
    DB::connection('legacy')->table('h20')->insert([
        ['Id' => 100, 'h2001' => '甲', 'h2002' => 'E001', 'h2003' => 'D1', 'h2029' => '103)2025', 'Memo' => '<p>旧总结</p><script>alert(1)</script>', 'yndelete' => '0', 'updateTime' => '2026-01-01 00:00:00'],
        ['Id' => 101, 'h2001' => '乙', 'h2002' => 'E002', 'h2003' => 'D2', 'h2029' => '103)2025', 'Memo' => '<p>乙的总结</p>', 'yndelete' => '0', 'updateTime' => '2026-01-01 00:00:00'],
    ]);
    app(LegacyReadOnlyGuard::class)->attach(DB::connection('legacy'));
});

afterEach(function () {
    DB::disconnect('legacy');
    DB::disconnect('legacy_assessment_writer');
    @unlink($this->assessmentDb);
});

function assessmentVersion(int $id): string
{
    return app(AssessmentWriter::class)->version(DB::connection('legacy')->table('h20')->where('Id', $id)->first());
}

test('only the employee and configured department leader can view a record', function () {
    $this->actingAs(User::find(1));
    $this->get('/personnel/assessments')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Personnel/Assessments/Index')->has('records', 1));
    $this->get('/personnel/assessments/100')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('record.summary', '<p>旧总结</p>')->where('canEdit', true)->etc());
    $this->get('/personnel/assessments/101')->assertForbidden();

    $this->actingAs(User::find(3));
    $this->get('/personnel/assessments/100')->assertOk();
    $this->get('/personnel/assessments/101')->assertForbidden();
    $this->actingAs(User::find(4));
    $this->get('/personnel/assessments/100')->assertForbidden();
});

test('summary submission withdrawal return and approval follow the old h20 flags', function () {
    $this->actingAs(User::find(1));
    $this->post('/personnel/assessments/100/summary', ['version' => assessmentVersion(100), 'summary' => '<h2>总结</h2><p>新内容</p><script>alert(1)</script>'])->assertRedirect();
    expect(DB::connection('legacy')->table('h20')->where('Id', 100)->value('Memo'))->toBe('<h2>总结</h2><p>新内容</p>');
    $version = assessmentVersion(100);
    $this->post('/personnel/assessments/100/submit', ['version' => $version])->assertRedirect();
    expect(DB::connection('legacy')->table('h20')->where('Id', 100)->value('h2025'))->toBe('y');
    $this->post('/personnel/assessments/100/summary', ['version' => $version, 'summary' => '<p>不应保存</p>'])->assertStatus(409);
    $version = assessmentVersion(100);
    $this->post('/personnel/assessments/100/withdraw', ['version' => $version])->assertRedirect();
    expect(DB::connection('legacy')->table('h20')->where('Id', 100)->value('h2025'))->toBe('n');
    $version = assessmentVersion(100);
    $this->post('/personnel/assessments/100/submit', ['version' => $version])->assertRedirect();

    $this->actingAs(User::find(3));
    $version = assessmentVersion(100);
    $this->post('/personnel/assessments/100/return', ['version' => $version, 'note' => '请补充内容'])->assertRedirect();
    expect(DB::connection('legacy')->table('h20')->where('Id', 100)->value('h2025'))->toBe('n');
    $this->assertDatabaseHas('assessment_events', ['legacy_assessment_id' => 100, 'action' => 'returned', 'status' => 'applied', 'note' => '请补充内容']);

    $this->actingAs(User::find(1));
    $version = assessmentVersion(100);
    $this->post('/personnel/assessments/100/submit', ['version' => $version])->assertRedirect();
    $this->actingAs(User::find(3));
    $version = assessmentVersion(100);
    $this->post('/personnel/assessments/100/approve', ['version' => $version, 'result' => '2'])->assertRedirect();
    $record = DB::connection('legacy')->table('h20')->where('Id', 100)->first();
    expect($record->h2030)->toBe('y');
    expect($record->h2013)->toBe('2');
    expect($record->h2026)->toBe('n');
    expect($record->h2031)->toBe('n');
});

test('word download is a real docx with the expected assessment sections', function () {
    $this->actingAs(User::find(1));
    $response = $this->get('/personnel/assessments/100/word')->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($path))->toBe(true);
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    expect($xml)->toContain('职工年度考核表');
    expect($xml)->toContain('个人总结');
    expect($xml)->toContain('旧总结');
    expect($xml)->toContain('信息技术部');
    expect($xml)->toContain('部门考核意见');
    expect($xml)->toContain('审批意见');
    expect($xml)->toContain('个人确认');
    expect($xml)->not->toContain('alert(1)');
    @unlink($path);
});

test('write gate, preview, department scope and result dictionary protect transitions', function () {
    $this->actingAs(User::find(1));
    config()->set('assessments.write_target', 'disabled');
    $this->post('/personnel/assessments/100/submit', ['version' => assessmentVersion(100)])->assertStatus(503);
    config()->set('assessments.write_target', 'production');
    $this->withSession(['personnel_preview' => true]);
    $this->post('/personnel/assessments/100/submit', ['version' => assessmentVersion(100)])->assertForbidden();
    $this->withSession(['personnel_preview' => false]);

    $this->post('/personnel/assessments/100/submit', ['version' => assessmentVersion(100)])->assertRedirect();
    $this->actingAs(User::find(4));
    $this->post('/personnel/assessments/100/approve', ['version' => assessmentVersion(100), 'result' => '2'])->assertForbidden();
    $this->actingAs(User::find(3));
    $this->post('/personnel/assessments/100/approve', ['version' => assessmentVersion(100), 'result' => 'invalid'])->assertSessionHasErrors('result');
    expect(DB::connection('legacy')->table('h20')->where('Id', 100)->value('h2030'))->toBe('n');
});
