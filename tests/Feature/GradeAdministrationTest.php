<?php

use App\Models\User;
use App\Services\GradeSpreadsheet;
use App\Services\GradeWriter;
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
            $table->string('h5071')->nullable();
            $table->string('h5072')->nullable();
            $table->string('h5019')->nullable();
            $table->string('h5021')->nullable();
            $table->string('h5094')->nullable();
            $table->string('h5073')->nullable();
            $table->string('yndelete');
            $table->string('updateTime')->nullable();
        });
        Schema::connection($connection)->create('h17', function (Blueprint $table) {
            $table->increments('Id');
            foreach (['h1701', 'h1702', 'h1703', 'h1704', 'h1705', 'h1706', 'h1707', 'h1708', 'h1709',
                'h1710', 'h1711', 'yndelete', 'updateTime'] as $field) {
                $table->string($field)->nullable();
            }
            $table->integer('CreatorId')->nullable();
        });
        Schema::connection($connection)->create('d307', function (Blueprint $table) {
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
            ['Id' => 2, 'username' => 'E002', 'truename' => '员工', 'isUse' => 1],
        ]);
        DB::connection($connection)->table('h50')->insert(['Id' => 11, 'h5001' => '员工', 'h5002' => 'E002',
            'h5003' => 'D1', 'h5071' => 'BB-old', 'h5072' => 'DF-old', 'h5019' => 'position-df',
            'h5021' => '2020-01-01', 'h5094' => 'position-bb', 'h5073' => '2020-02-01', 'yndelete' => '0']);
        DB::connection($connection)->table('d307')->insert([
            ['Id' => 1, 'objname' => '1016', 'objjc' => '管理六级', 'yndelete' => '0'],
            ['Id' => 2, 'objname' => '1019', 'objjc' => '管理八级', 'yndelete' => '0'],
            ['Id' => 3, 'objname' => '1020', 'objjc' => '管理九级', 'yndelete' => '0'],
        ]);
        DB::connection($connection)->table('department')->insert(['Id' => 1, 'objname' => 'D1', 'name' => '部门一', 'isuse' => 1]);
    }
    config()->set('positions.write_target', 'stage');
    Storage::fake('local');
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function gradePayload(array $changes = []): array
{
    return [...['employee_number' => 'E002', 'bb_grade' => '1016', 'bb_start' => '2024-01-01',
        'bb_end' => '2099-01-01', 'df_grade' => '1019', 'df_start' => '2024-02-01',
        'df_end' => '', 'note' => ''], ...$changes];
}

function gradeWorkbook(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray(GradeSpreadsheet::HEADERS, null, 'A1');
    foreach ($rows as $index => $row) {
        $book->getActiveSheet()->fromArray($row, null, 'A'.($index + 2));
    }
    $path = tempnam(sys_get_temp_dir(), 'grade-import-');
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();

    return new UploadedFile($path, 'grades.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('only admins can manage grades and a dual-sided record syncs independently', function () {
    $this->actingAs(User::findOrFail(2))->get('/personnel/admin/grades')->assertForbidden();
    $this->actingAs(User::findOrFail(2))->post('/personnel/admin/grades', gradePayload())->assertForbidden();
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->get('/personnel/admin/grades')->assertOk();
    $this->actingAs($actor)->post('/personnel/admin/grades', gradePayload())->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5071' => '1016', 'h5072' => '1019',
        'h5019' => 'position-df', 'h5094' => 'position-bb'], 'legacy_stage_writer');
    $row = DB::connection('legacy_stage_writer')->table('h17')->first();
    expect($row->h1708)->toBe((string) $row->Id);
    $this->actingAs($actor)->put('/personnel/admin/grades/'.$row->Id, [
        ...gradePayload(['bb_grade' => '1020']), 'version' => str_repeat('0', 64),
    ])->assertStatus(409);
    $this->assertDatabaseHas('h17', ['Id' => $row->Id, 'h1704' => '1016'], 'legacy_stage_writer');
    $this->actingAs($actor)->delete('/personnel/admin/grades/'.$row->Id,
        ['version' => app(GradeWriter::class)->version($row)])->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5071' => 'BB-old', 'h5072' => 'DF-old'], 'legacy_stage_writer');
    expect(DB::table('grade_change_events')->where('record_id', $row->Id)->count())->toBe(2);
});

test('expiry refresh excludes ended BB while retaining active DF and preserves history', function () {
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->post('/personnel/admin/grades', gradePayload())->assertRedirect();
    expect(app(GradeWriter::class)->refreshExpired('2099-01-01'))->toBe(1);
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5071' => 'BB-old', 'h5072' => '1019'], 'legacy_stage_writer');
    expect(DB::connection('legacy_stage_writer')->table('h17')->where('yndelete', '0')->count())->toBe(1);
});

test('daily refresh activates a future appointment when its start date arrives', function () {
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->post('/personnel/admin/grades', gradePayload([
        'bb_grade' => '', 'bb_start' => '', 'bb_end' => '',
        'df_grade' => '1020', 'df_start' => '2099-01-01',
    ]))->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5072' => 'DF-old'], 'legacy_stage_writer');
    expect(app(GradeWriter::class)->refreshExpired('2099-01-01'))->toBe(1);
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5072' => '1020'], 'legacy_stage_writer');
});

test('an existing record keeps its sequence and can be corrected before joining current-grade calculation', function () {
    DB::connection('legacy_stage_writer')->table('h17')->insert([
        'Id' => 90, 'h1701' => '员工', 'h1702' => 'E002', 'h1703' => 'D1',
        'h1704' => '1016', 'h1705' => '', 'h1708' => 'legacy-7', 'yndelete' => '0',
    ]);
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->get('/personnel/admin/grades')->assertOk();
    $row = DB::connection('legacy_stage_writer')->table('h17')->where('Id', 90)->first();
    $this->actingAs($actor)->put('/personnel/admin/grades/90', [
        ...gradePayload(['df_grade' => '', 'df_start' => '', 'bb_end' => '']),
        'version' => app(GradeWriter::class)->version($row),
    ])->assertRedirect();
    $this->assertDatabaseHas('h17', ['Id' => 90, 'h1708' => 'legacy-7', 'h1705' => '2024-01-01'], 'legacy_stage_writer');
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5071' => '1016', 'h5072' => 'DF-old'], 'legacy_stage_writer');
});

test('deleting the newest appointment falls back to the previous one and then the baseline', function () {
    $actor = User::findOrFail(1);
    $this->actingAs($actor)->post('/personnel/admin/grades', gradePayload(['bb_end' => '']))->assertRedirect();
    $this->actingAs($actor)->post('/personnel/admin/grades', gradePayload([
        'bb_grade' => '1020', 'bb_start' => '2025-01-01', 'bb_end' => '',
        'df_grade' => '', 'df_start' => '',
    ]))->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5071' => '1020', 'h5072' => '1019'], 'legacy_stage_writer');
    $newer = DB::connection('legacy_stage_writer')->table('h17')->orderByDesc('Id')->first();
    $this->actingAs($actor)->delete('/personnel/admin/grades/'.$newer->Id,
        ['version' => app(GradeWriter::class)->version($newer)])->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5071' => '1016', 'h5072' => '1019'], 'legacy_stage_writer');
    $older = DB::connection('legacy_stage_writer')->table('h17')->where('yndelete', '0')->first();
    $this->actingAs($actor)->delete('/personnel/admin/grades/'.$older->Id,
        ['version' => app(GradeWriter::class)->version($older)])->assertRedirect();
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5071' => 'BB-old', 'h5072' => 'DF-old'], 'legacy_stage_writer');
});

test('import blocks errors, skips exact duplicates and downloads a reference template', function () {
    $actor = User::findOrFail(1);
    $bad = gradeWorkbook([
        ['E002', '1016', '2024-01-01', '', '1019', '2024-02-01', '', ''],
        ['UNKNOWN', '1016', '2024-01-01', '', '', '', '', ''],
    ]);
    $this->actingAs($actor)->post('/personnel/admin/grades/import/preview', ['file' => $bad])->assertOk();
    $this->actingAs($actor)->post('/personnel/admin/grades/import/commit',
        ['token' => session('grade_import_token')])->assertStatus(422);
    expect(DB::connection('legacy_stage_writer')->table('h17')->count())->toBe(0);
    $good = gradeWorkbook([
        ['E002', '1016', '2024-01-01', '', '1019', '2024-02-01', '', ''],
        ['E002', '1016', '2024-01-01', '', '1019', '2024-02-01', '', 'different note'],
        ['E002', '', '', '', '1020', '2025-01-01', '', ''],
    ]);
    $this->actingAs($actor)->post('/personnel/admin/grades/import/preview', ['file' => $good])->assertOk();
    $this->actingAs($actor)->post('/personnel/admin/grades/import/commit',
        ['token' => session('grade_import_token')])->assertRedirect('/personnel/admin/grades');
    expect(DB::connection('legacy_stage_writer')->table('h17')->count())->toBe(2);
    $this->assertDatabaseHas('h50', ['h5002' => 'E002', 'h5071' => '1016', 'h5072' => '1020'], 'legacy_stage_writer');
    $response = $this->actingAs($actor)->get('/personnel/admin/grades/template');
    $response->assertOk()->assertDownload('grade-import-template.xlsx');
    $path = tempnam(sys_get_temp_dir(), 'grade-template-');
    file_put_contents($path, $response->streamedContent());
    $book = IOFactory::load($path);
    expect($book->getSheetCount())->toBe(2)
        ->and($book->getSheet(0)->rangeToArray('A1:H1')[0])->toBe(GradeSpreadsheet::HEADERS);
    $book->disconnectWorksheets();
    unlink($path);
});
