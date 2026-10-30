<?php

use App\Models\User;
use App\Services\DepartmentSpreadsheet;
use App\Services\DepartmentWriter;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->withoutVite();
    Artisan::call('migrate');
    foreach (['legacy', 'legacy_stage_writer'] as $name) {
        config()->set("database.connections.$name", ['driver' => 'sqlite', 'database' => ':memory:',
            'host' => $name, 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge($name);
        $schema = Schema::connection($name);
        $schema->create('user', function (Blueprint $table) {
            $table->integer('Id')->primary();
            $table->string('username');
            $table->string('truename');
            $table->integer('isUse');
            $table->integer('IsSA')->default(0);
            $table->integer('departmentId')->nullable();
            $table->string('updatetime')->nullable();
        });
        $schema->create('h50', function (Blueprint $table) {
            $table->integer('Id')->primary();
            $table->string('h5001');
            $table->string('h5002');
            $table->string('h5003');
            $table->string('yndelete');
            $table->string('updateTime')->nullable();
        });
        $schema->create('h54', function (Blueprint $table) {
            $table->increments('Id');
            foreach (['h5401', 'h5402', 'h5403', 'h5404', 'h5405', 'h5406', 'h5407', 'yndelete', 'updateTime'] as $field) {
                $table->string($field)->nullable();
            }
            $table->integer('CreatorId')->nullable();
        });
        $schema->create('department', function (Blueprint $table) {
            $table->integer('Id')->primary();
            $table->string('objname');
            $table->string('name');
            $table->integer('isuse');
            $table->string('yndelete');
        });
        $schema->create('userroles', function (Blueprint $table) {
            $table->integer('userId');
            $table->integer('roleId');
        });
        $schema->create('treeroles', function (Blueprint $table) {
            $table->integer('roleid');
            $table->integer('treeid');
        });
        $schema->create('department_profile_baselines', function (Blueprint $table) {
            $table->increments('id');
            $table->string('employee_number')->unique();
            $table->string('profile_department')->nullable();
            $table->integer('account_department_id')->nullable();
            $table->timestamps();
        });
        $schema->create('department_change_events', function (Blueprint $table) {
            $table->increments('id');
            $table->string('employee_number');
            $table->integer('record_id')->nullable();
            $table->integer('actor_user_id')->nullable();
            $table->string('action');
            $table->text('details')->nullable();
            $table->timestamp('created_at');
        });
        DB::connection($name)->table('user')->insert([
            ['Id' => 1, 'username' => '20150301', 'truename' => '管理员', 'isUse' => 1, 'departmentId' => 1],
            ['Id' => 2, 'username' => 'E002', 'truename' => '员工', 'isUse' => 1, 'departmentId' => 1],
        ]);
        DB::connection($name)->table('h50')->insert(['Id' => 11, 'h5001' => '员工', 'h5002' => 'E002', 'h5003' => 'D1', 'yndelete' => '0']);
        DB::connection($name)->table('department')->insert([
            ['Id' => 1, 'objname' => 'D1', 'name' => '部门一', 'isuse' => 1, 'yndelete' => '0'],
            ['Id' => 2, 'objname' => 'D2', 'name' => '部门二', 'isuse' => 1, 'yndelete' => '0'],
            ['Id' => 3, 'objname' => 'D3', 'name' => '部门三', 'isuse' => 1, 'yndelete' => '0'],
        ]);
    }
    config()->set('positions.write_target', 'stage');
    Storage::fake('local');
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function departmentPayload(array $overrides = []): array
{
    return [...['employee_number' => 'E002', 'source_department' => 'D1', 'target_department' => 'D2',
        'changed_at' => '2025-01-01', 'reason' => '岗位调整', 'note' => '备注'], ...$overrides];
}

function departmentWorkbook(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray(DepartmentSpreadsheet::HEADERS, null, 'A1');
    foreach ($rows as $index => $row) {
        $book->getActiveSheet()->fromArray($row, null, 'A'.($index + 2));
    }
    $path = tempnam(sys_get_temp_dir(), 'department-import-');
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();

    return new UploadedFile($path, 'departments.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('admin access, atomic current departments, version check, and fallback', function () {
    $this->actingAs(User::findOrFail(2))->get('/personnel/admin/departments')->assertForbidden();
    $this->actingAs(User::findOrFail(2))->post('/personnel/admin/departments', departmentPayload())->assertForbidden();
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->get('/personnel/admin/departments')->assertOk();
    $this->actingAs($actor)->post('/personnel/admin/departments', departmentPayload())->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5003' => 'D2'], 'legacy_stage_writer');
    $this->assertDatabaseHas('user', ['username' => 'E002', 'departmentId' => 2], 'legacy_stage_writer');
    $row = DB::connection('legacy_stage_writer')->table('h54')->first();
    $this->actingAs($actor)->put('/personnel/admin/departments/'.$row->Id,
        [...departmentPayload(['target_department' => 'D3']), 'version' => str_repeat('0', 64)])->assertStatus(409);
    $this->actingAs($actor)->delete('/personnel/admin/departments/'.$row->Id,
        ['version' => app(DepartmentWriter::class)->version($row)])->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5003' => 'D1'], 'legacy_stage_writer');
    $this->assertDatabaseHas('user', ['username' => 'E002', 'departmentId' => 1], 'legacy_stage_writer');
    expect(DB::connection('legacy_stage_writer')->table('department_change_events')->count())->toBe(2);
});

test('future dates activate in Shanghai refresh and newest deletion falls back', function () {
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->post('/personnel/admin/departments', departmentPayload())->assertRedirect();
    $this->actingAs($actor)->post('/personnel/admin/departments', departmentPayload([
        'target_department' => 'D3', 'changed_at' => '2099-01-01']))->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5003' => 'D2'], 'legacy_stage_writer');
    expect(app(DepartmentWriter::class)->refreshDue('2099-01-01'))->toBe(1);
    $this->assertDatabaseHas('user', ['username' => 'E002', 'departmentId' => 3], 'legacy_stage_writer');
    $newest = DB::connection('legacy_stage_writer')->table('h54')->orderByDesc('Id')->first();
    $this->actingAs($actor)->delete('/personnel/admin/departments/'.$newest->Id,
        ['version' => app(DepartmentWriter::class)->version($newest)])->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5003' => 'D2'], 'legacy_stage_writer');
});

test('same-day changes use record ID and editing an old record preserves its ID', function () {
    $db = DB::connection('legacy_stage_writer');
    $db->table('h54')->insert(['Id' => 90, 'h5401' => '员工', 'h5402' => 'E002',
        'h5403' => 'D1', 'h5404' => 'D2', 'h5405' => '2025-01-01', 'yndelete' => '0']);
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->get('/personnel/admin/departments?department=D1&search=E002')->assertOk();
    $old = $db->table('h54')->where('Id', 90)->first();
    $this->actingAs($actor)->put('/personnel/admin/departments/90', [
        ...departmentPayload(), 'version' => app(DepartmentWriter::class)->version($old),
    ])->assertRedirect();
    $this->actingAs($actor)->post('/personnel/admin/departments', departmentPayload([
        'target_department' => 'D3', 'changed_at' => '2025-01-01']))->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5003' => 'D3'], 'legacy_stage_writer');
    $this->assertDatabaseHas('h54', ['Id' => 90, 'h5404' => 'D2'], 'legacy_stage_writer');
});

test('historical department codes remain editable without changing those codes', function () {
    $db = DB::connection('legacy_stage_writer');
    $db->table('h54')->insert(['Id' => 91, 'h5401' => '员工', 'h5402' => 'E002',
        'h5403' => 'OLD-SOURCE', 'h5404' => 'OLD-TARGET', 'h5405' => '2024-01-01', 'yndelete' => '0']);
    $actor = User::findOrFail(1);
    $old = $db->table('h54')->where('Id', 91)->first();
    $this->actingAs($actor)->put('/personnel/admin/departments/91', [
        ...departmentPayload(['source_department' => 'OLD-SOURCE', 'target_department' => 'OLD-TARGET',
            'changed_at' => '2024-01-01', 'note' => '历史备注校正']),
        'version' => app(DepartmentWriter::class)->version($old),
    ])->assertRedirect();
    $this->assertDatabaseHas('h54', ['Id' => 91, 'h5403' => 'OLD-SOURCE', 'h5404' => 'OLD-TARGET',
        'h5406' => '历史备注校正'], 'legacy_stage_writer');
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5003' => 'D1'], 'legacy_stage_writer');
});

test('invalid department is rejected and audit failure rolls back history and both current departments', function () {
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->post('/personnel/admin/departments', departmentPayload([
        'target_department' => 'UNKNOWN']))->assertSessionHasErrors('target_department');
    expect(DB::connection('legacy_stage_writer')->table('h54')->count())->toBe(0);
    Schema::connection('legacy_stage_writer')->drop('department_change_events');
    try {
        app(DepartmentWriter::class)->createMany([departmentPayload(['name' => '员工'])], $actor);
    } catch (QueryException) {
        // The missing audit table forces a rollback of every business update.
    }
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5003' => 'D1'], 'legacy_stage_writer');
    $this->assertDatabaseHas('user', ['username' => 'E002', 'departmentId' => 1], 'legacy_stage_writer');
    expect(DB::connection('legacy_stage_writer')->table('h54')->count())->toBe(0);
});

test('import blocks invalid batch, skips duplicates and exposes template', function () {
    $actor = User::findOrFail(1);
    $bad = departmentWorkbook([['E002', 'D1', 'D2', '2025-01-01', '', ''], ['missing', 'D1', 'D3', '2025-01-02', '', '']]);
    $this->actingAs($actor)->post('/personnel/admin/departments/import/preview', ['file' => $bad])->assertOk();
    $this->actingAs($actor)->post('/personnel/admin/departments/import/commit',
        ['token' => session('department_import_token')])->assertStatus(422);
    expect(DB::connection('legacy_stage_writer')->table('h54')->count())->toBe(0);
    $good = departmentWorkbook([['E002', 'D1', 'D2', '2025-01-01', '', ''],
        ['E002', 'D1', 'D2', '2025-01-01', 'different', ''], ['E002', 'D1', 'D3', '2025-02-01', '', '']]);
    $this->actingAs($actor)->post('/personnel/admin/departments/import/preview', ['file' => $good])->assertOk();
    $this->actingAs($actor)->post('/personnel/admin/departments/import/commit',
        ['token' => session('department_import_token')])->assertRedirect('/personnel/admin/departments');
    expect(DB::connection('legacy_stage_writer')->table('h54')->count())->toBe(2);
    $response = $this->actingAs($actor)->get('/personnel/admin/departments/template');
    $response->assertOk()->assertDownload('department-import-template.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'department-template-');
    file_put_contents($path, $response->streamedContent());
    $book = IOFactory::load($path);
    expect($book->getSheetCount())->toBe(2)->and($book->getSheet(0)->rangeToArray('A1:F1')[0])->toBe(DepartmentSpreadsheet::HEADERS);
    $book->disconnectWorksheets();
    unlink($path);
});
