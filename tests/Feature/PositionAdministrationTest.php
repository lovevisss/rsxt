<?php

use App\Models\User;
use App\Services\PositionSpreadsheet;
use App\Services\PositionWriter;
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
    $this->actingAs(User::findOrFail(2))->get('/personnel/admin/positions')->assertForbidden();
    $this->actingAs(User::findOrFail(2))->post('/personnel/admin/positions', positionPayload())->assertForbidden();
    DB::table('personnel_admins')->where('employee_number', '20201003')->delete();
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
    expect(DB::table('position_change_events')->where('record_id', $row->Id)->count())->toBe(2);
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

test('template downloads as a two-sheet workbook with the agreed columns', function () {
    $response = $this->actingAs(User::findOrFail(1))->get('/personnel/admin/positions/template');
    $response->assertOk()->assertDownload('position-import-template.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'position-template-');
    file_put_contents($path, $response->streamedContent());
    $book = IOFactory::load($path);
    expect($book->getSheetCount())->toBe(2)
        ->and($book->getSheet(0)->rangeToArray('A1:H1')[0])->toBe(PositionSpreadsheet::HEADERS)
        ->and((string) $book->getSheet(1)->getCell('A2')->getValue())->toBe('14');
    $book->disconnectWorksheets();
    unlink($path);
});
