<?php

use App\Models\User;
use App\Services\PositionSpreadsheet;
use App\Services\PositionWriter;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

beforeEach(function () {
    $this->withoutVite();
    Artisan::call('migrate');
    foreach (['legacy', 'legacy_stage_writer'] as $connection) {
        config()->set("database.connections.$connection", ['driver' => 'sqlite', 'database' => ':memory:',
            'host' => $connection, 'prefix' => '', 'foreign_key_constraints' => false]);
        DB::purge($connection);
        Schema::connection($connection)->create('user', function (Blueprint $table) {
            $table->integer('Id')->primary();
            $table->string('username');
            $table->string('truename');
            $table->integer('isUse');
            $table->integer('IsSA')->default(0);
        });
        Schema::connection($connection)->create('h50', function (Blueprint $table) {
            $table->integer('Id')->primary();
            $table->string('h5001');
            $table->string('h5002');
            $table->string('h5003');
            $table->string('h5019')->nullable();
            $table->string('h5021')->nullable();
            $table->string('h5094')->nullable();
            $table->string('h5073')->nullable();
            $table->string('yndelete');
            $table->string('updateTime')->nullable();
        });
        Schema::connection($connection)->create('h06', function (Blueprint $table) {
            $table->increments('Id');
            foreach (['h0601', 'h0602', 'h0603', 'h0604', 'h0605', 'h0606', 'h0607', 'h0608', 'h0609', 'h0615', 'yndelete', 'updateTime'] as $field) {
                $table->string($field)->nullable();
            }
            $table->integer('CreatorId')->nullable();
        });
        Schema::connection($connection)->create('d306', function (Blueprint $table) {
            $table->integer('Id')->primary();
            $table->string('objname');
            $table->string('objjc');
            $table->string('yndelete');
        });
        Schema::connection($connection)->create('department', function (Blueprint $table) {
            $table->integer('Id')->primary();
            $table->string('objname');
            $table->string('name');
            $table->integer('isuse');
        });
        Schema::connection($connection)->create('userroles', function (Blueprint $table) {
            $table->integer('userId');
            $table->integer('roleId');
        });
        Schema::connection($connection)->create('treeroles', function (Blueprint $table) {
            $table->integer('roleid');
            $table->integer('treeid');
        });
        DB::connection($connection)->table('user')->insert([
            ['Id' => 1, 'username' => '20150301', 'truename' => '管理员', 'isUse' => 1],
            ['Id' => 2, 'username' => 'E002', 'truename' => '普通用户', 'isUse' => 1],
        ]);
        DB::connection($connection)->table('h50')->insert([
            'Id' => 11, 'h5001' => '普通用户', 'h5002' => 'E002', 'h5003' => 'D1', 'h5019' => 'old', 'h5021' => '2020-01-01',
            'h5094' => 'bb-old', 'h5073' => '2020-02-01', 'yndelete' => '0',
        ]);
        DB::connection($connection)->table('d306')->insert([
            ['Id' => 1, 'objname' => '14', 'objjc' => '管理岗位', 'yndelete' => '0'],
            ['Id' => 2, 'objname' => '19', 'objjc' => '教师岗位', 'yndelete' => '0'],
        ]);
        DB::connection($connection)->table('department')->insert(['Id' => 1, 'objname' => 'D1', 'name' => '部门一', 'isuse' => 1]);
    }
    config()->set('positions.write_target', 'stage');
    Storage::fake('local');
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function positionPayload(array $changes = []): array
{
    return [...['type' => 'DF', 'employee_number' => 'E002', 'old_position' => '14', 'old_date' => '2023-01-01',
        'new_position' => '19', 'new_date' => '2024-01-01', 'reason' => '岗位调整', 'note' => ''], ...$changes];
}

test('only configured admins may manage positions and last admin cannot be removed', function () {
    $this->actingAs(User::findOrFail(2))->get('/personnel/admin')->assertForbidden();
    $this->actingAs(User::findOrFail(2))->get('/personnel/admin/positions')->assertForbidden();
    $this->actingAs(User::findOrFail(2))->post('/personnel/admin/positions', positionPayload())->assertForbidden();
    DB::table('personnel_admins')->where('employee_number', '20201003')->delete();
    $this->actingAs(User::findOrFail(1))->get('/personnel/admin')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Personnel/Admin/Home')->has('modules.position_admin')->has('modules.grade_admin')
            ->has('modules.department_admin')->has('modules.admin_accounts'));
    $this->actingAs(User::findOrFail(1))->get('/personnel/admin/positions')->assertOk();
    $this->actingAs(User::findOrFail(1))->delete('/personnel/admin/administrators/20150301')->assertStatus(422);
    $this->actingAs(User::findOrFail(1))->post('/personnel/admin/administrators', ['employee_number' => 'E002'])->assertRedirect();
    $this->assertDatabaseHas('personnel_admins', ['employee_number' => 'E002']);
});

test('position changes sync latest profile and deletion restores baseline without affecting BB', function () {
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->post('/personnel/admin/positions', positionPayload())->assertRedirect();
    $this->assertDatabaseHas('h06', ['h0602' => 'E002', 'h0615' => 'DF', 'h0604' => '19'], 'legacy_stage_writer');
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5019' => '19', 'h5021' => '2024-01-01', 'h5094' => 'bb-old'], 'legacy_stage_writer');
    $row = DB::connection('legacy_stage_writer')->table('h06')->first();
    $version = app(PositionWriter::class)->version($row);
    $this->actingAs($actor)->put('/personnel/admin/positions/'.$row->Id, [
        ...positionPayload(['new_position' => '14']), 'version' => str_repeat('0', 64),
    ])->assertStatus(409);
    $this->assertDatabaseHas('h06', ['Id' => $row->Id, 'h0604' => '19'], 'legacy_stage_writer');
    $this->actingAs($actor)->delete('/personnel/admin/positions/'.$row->Id, ['version' => $version])->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5019' => 'old', 'h5021' => '2020-01-01', 'h5094' => 'bb-old'], 'legacy_stage_writer');
    $this->assertDatabaseHas('h06', ['Id' => $row->Id, 'yndelete' => '1'], 'legacy_stage_writer');
    expect(DB::table('admin_operations')->where('module', 'position')->count())->toBe(2);
});

function positionWorkbook(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray(PositionSpreadsheet::HEADERS, null, 'A1');
    foreach ($rows as $index => $row) {
        $book->getActiveSheet()->fromArray($row, null, 'A'.($index + 2));
    }
    $path = tempnam(sys_get_temp_dir(), 'position-import-');
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();

    return new UploadedFile($path, 'positions.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('import validates entire workbook, skips duplicates and commits only valid rows', function () {
    $actor = User::findOrFail(1);
    $bad = positionWorkbook([
        ['DF', 'E002', '14', '2023-01-01', '19', '2024-01-01', '调整', ''],
        ['BB', 'UNKNOWN', '14', '2023-01-01', '19', '2024-03-01', '调整', ''],
    ]);
    $this->actingAs($actor)->post('/personnel/admin/positions/import/preview', ['file' => $bad])->assertOk();
    $token = session('position_import_token');
    $this->actingAs($actor)->post('/personnel/admin/positions/import/commit', ['token' => $token])->assertStatus(422);
    expect(DB::connection('legacy_stage_writer')->table('h06')->count())->toBe(0);

    $good = positionWorkbook([
        ['DF', 'E002', '14', '2023-01-01', '19', '2024-01-01', '调整', ''],
        ['DF', 'E002', '14', '2023-01-01', '19', '2024-01-01', '调整', '重复'],
        ['BB', 'E002', '14', '2023-01-01', '19', '2024-03-01', '调整', ''],
    ]);
    $this->actingAs($actor)->post('/personnel/admin/positions/import/preview', ['file' => $good])->assertOk();
    $token = session('position_import_token');
    $this->actingAs($actor)->post('/personnel/admin/positions/import/commit', ['token' => $token])->assertRedirect('/personnel/admin/positions');
    expect(DB::connection('legacy_stage_writer')->table('h06')->count())->toBe(2);
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5019' => '19', 'h5094' => '19'], 'legacy_stage_writer');
});

test('template keeps the import sheet blank and shows DF and BB examples separately', function () {
    $response = $this->actingAs(User::findOrFail(1))->get('/personnel/admin/positions/template');
    $response->assertOk()->assertDownload('position-import-template.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'position-template-');
    file_put_contents($path, $response->streamedContent());
    $book = IOFactory::load($path);
    expect($book->getSheetCount())->toBe(3)
        ->and($book->getSheet(0)->rangeToArray('A1:H1')[0])->toBe(PositionSpreadsheet::HEADERS)
        ->and(array_filter($book->getSheet(0)->rangeToArray('A2:H2')[0]))->toBe([])
        ->and($book->getSheet(1)->getTitle())->toBe('填写示例')
        ->and((string) $book->getSheet(1)->getCell('A2')->getValue())->toBe('DF')
        ->and((string) $book->getSheet(1)->getCell('A3')->getValue())->toBe('BB')
        ->and((string) $book->getSheet(1)->getCell('B2')->getValue())->toContain('请替换')
        ->and((string) $book->getSheet(1)->getCell('C2')->getValue())->toBe('14')
        ->and((string) $book->getSheet(2)->getCell('A2')->getValue())->toBe('14');
    $book->disconnectWorksheets();
    expect(app(PositionSpreadsheet::class)->parse(new UploadedFile($path, 'template.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)))->toBe([]);
    unlink($path);
});

test('operation log restricts access and rolls back a whole import with conflict protection', function () {
    $actor = User::findOrFail(1);
    $this->actingAs(User::findOrFail(2))->get('/personnel/admin/operations')->assertForbidden();
    $this->actingAs(User::findOrFail(2))->post('/personnel/admin/operations/1/rollback')->assertForbidden();
    $file = positionWorkbook([
        ['DF', 'E002', '14', '2023-01-01', '19', '2024-01-01', '调整', ''],
        ['BB', 'E002', '14', '2023-01-01', '19', '2024-03-01', '调整', ''],
    ]);
    $this->actingAs($actor)->post('/personnel/admin/positions/import/preview', ['file' => $file])->assertOk();
    $this->actingAs($actor)->post('/personnel/admin/positions/import/commit',
        ['token' => session('position_import_token')])->assertRedirect();
    $operation = DB::table('admin_operations')->where('module', 'position')->first();
    expect($operation->action)->toBe('imported')->and($operation->item_count)->toBe(2);
    $this->actingAs($actor)->get('/personnel/admin/operations')->assertOk();
    $this->actingAs($actor)->get('/personnel/admin/operations/'.$operation->id)->assertOk();
    $this->actingAs($actor)->post('/personnel/admin/operations/'.$operation->id.'/rollback')->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5019' => 'old', 'h5094' => 'bb-old'], 'legacy_stage_writer');
    expect(DB::connection('legacy_stage_writer')->table('h06')->where('yndelete', '0')->count())->toBe(0);
    $this->actingAs($actor)->post('/personnel/admin/operations/'.$operation->id.'/rollback')->assertStatus(409);
});

test('later edit blocks rollback of an older action', function () {
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->post('/personnel/admin/positions', positionPayload())->assertRedirect();
    $first = DB::table('admin_operations')->first();
    $row = DB::connection('legacy_stage_writer')->table('h06')->first();
    $this->actingAs($actor)->put('/personnel/admin/positions/'.$row->Id,
        [...positionPayload(['new_position' => '14']), 'version' => app(PositionWriter::class)->version($row)])->assertRedirect();
    $this->actingAs($actor)->post('/personnel/admin/operations/'.$first->id.'/rollback')->assertStatus(409);
    $this->assertDatabaseHas('h06', ['Id' => $row->Id, 'h0604' => '14', 'yndelete' => '0'], 'legacy_stage_writer');
});

test('administrator changes are logged and last administrator cannot be rolled away', function () {
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->post('/personnel/admin/administrators', ['employee_number' => 'E002'])->assertRedirect();
    $operation = DB::table('admin_operations')->where('module', 'administrators')->first();
    $this->actingAs($actor)->post('/personnel/admin/operations/'.$operation->id.'/rollback')->assertRedirect();
    $this->assertDatabaseMissing('personnel_admins', ['employee_number' => 'E002']);
    DB::table('personnel_admins')->where('employee_number', '20201003')->delete();
    $this->actingAs($actor)->post('/personnel/admin/administrators', ['employee_number' => 'E002'])->assertRedirect();
    $latest = DB::table('admin_operations')->where('module', 'administrators')->where('action', 'admin_added')
        ->orderByDesc('id')->first();
    DB::table('personnel_admins')->where('employee_number', '20150301')->delete();
    $this->actingAs(User::findOrFail(2))->post('/personnel/admin/operations/'.$latest->id.'/rollback')->assertStatus(409);
    $this->assertDatabaseHas('personnel_admins', ['employee_number' => 'E002']);
});

test('one changed row blocks rollback of an entire import', function () {
    $actor = User::findOrFail(1);
    $file = positionWorkbook([
        ['DF', 'E002', '14', '2023-01-01', '19', '2024-01-01', '调整', ''],
        ['BB', 'E002', '14', '2023-01-01', '19', '2024-03-01', '调整', ''],
    ]);
    $this->actingAs($actor)->post('/personnel/admin/positions/import/preview', ['file' => $file])->assertOk();
    $this->actingAs($actor)->post('/personnel/admin/positions/import/commit',
        ['token' => session('position_import_token')])->assertRedirect();
    $import = DB::table('admin_operations')->where('action', 'imported')->first();
    $row = DB::connection('legacy_stage_writer')->table('h06')->where('h0615', 'DF')->first();
    $this->actingAs($actor)->put('/personnel/admin/positions/'.$row->Id,
        [...positionPayload(['new_position' => '14']), 'version' => app(PositionWriter::class)->version($row)])->assertRedirect();
    $this->actingAs($actor)->post('/personnel/admin/operations/'.$import->id.'/rollback')->assertStatus(409);
    expect(DB::connection('legacy_stage_writer')->table('h06')->where('yndelete', '0')->count())->toBe(2);
    $this->assertDatabaseHas('admin_operations', ['id' => $import->id, 'status' => 'active']);
});

test('removing an administrator can be rolled back', function () {
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->delete('/personnel/admin/administrators/20201003')->assertRedirect();
    $operation = DB::table('admin_operations')->where('action', 'admin_removed')->first();
    $this->actingAs($actor)->post('/personnel/admin/operations/'.$operation->id.'/rollback')->assertRedirect();
    $this->assertDatabaseHas('personnel_admins', ['employee_number' => '20201003']);
});

test('legacy operation details remain readable but cannot be rolled back', function () {
    $id = DB::table('admin_operations')->insertGetId([
        'module' => 'position', 'action' => 'updated', 'actor_user_id' => 1,
        'employee_number' => 'E002', 'items' => json_encode([['record_id' => 7, 'details' => 'old log']]),
        'item_count' => 1, 'status' => 'legacy', 'created_at' => now(),
    ]);
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->get('/personnel/admin/operations/'.$id)->assertOk();
    $this->actingAs($actor)->post('/personnel/admin/operations/'.$id.'/rollback')->assertStatus(409);
    $this->assertDatabaseHas('admin_operations', ['id' => $id, 'status' => 'legacy']);
});

test('audit write failure rolls back a position change and current profile update', function () {
    Schema::drop('admin_operations');
    try {
        $this->actingAs(User::findOrFail(1))->post('/personnel/admin/positions', positionPayload());
    } catch (QueryException) {
        // The audit table is unavailable, so the business transaction must abort.
    }
    expect(DB::connection('legacy_stage_writer')->table('h06')->count())->toBe(0);
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5019' => 'old'], 'legacy_stage_writer');
});
