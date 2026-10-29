<?php

use App\Models\User;
use App\Services\LegacyCertificate;
use App\Services\LegacyReadOnlyGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('database.connections.legacy', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::purge('legacy');
    Schema::connection('legacy')->create('user', function (Blueprint $table) {
        $table->integer('Id')->primary();
        $table->string('username');
        $table->string('truename');
        $table->integer('isUse');
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
    foreach (config('personnel_history') as $definition) {
        Schema::connection('legacy')->create($definition['table'], function (Blueprint $table) use ($definition) {
            $table->integer('Id')->primary();
            $table->string('yndelete');
            $table->string('updateTime')->nullable();
            $fields = array_keys(array_merge(...array_values($definition['groups'])));
            foreach (array_unique([$definition['employee'], ...$fields]) as $field) {
                $table->string($field)->nullable();
            }
        });
        foreach ($definition['dictionaries'] as [$dictionary, $column]) {
            if (! Schema::connection('legacy')->hasTable($dictionary)) {
                Schema::connection('legacy')->create($dictionary, function (Blueprint $table) use ($column) {
                    $table->string('objname');
                    $table->string($column);
                });
            }
        }
    }
    DB::connection('legacy')->table('user')->insert([
        ['Id' => 1, 'username' => 'E001', 'truename' => '甲', 'isUse' => 1],
        ['Id' => 2, 'username' => 'E002', 'truename' => '乙', 'isUse' => 1],
    ]);
    DB::connection('legacy')->table('userroles')->insert(['userId' => 1, 'roleId' => 5]);
    DB::connection('legacy')->table('treeroles')->insert(['roleid' => 5, 'treeid' => 336]);
    DB::connection('legacy')->table('d218')->insert(['objname' => '074', 'objjc' => '实验师']);
    for ($i = 1; $i <= 21; $i++) {
        DB::connection('legacy')->table('h70')->insert([
            'Id' => $i, 'h7002' => 'E001', 'yndelete' => '0', 'h7009' => sprintf('2020-01-%02d', $i),
            'h7005' => '074', 'h7026' => $i === 1 ? null : "第 {$i} 条",
            'h7014' => $i === 21 ? 'cert.pnghttp://rsgl.zufedfc.edu.cn:8080//useruploadfiles/cert.png' : null,
        ]);
    }
    DB::connection('legacy')->table('h70')->insert([
        ['Id' => 90, 'h7002' => 'E002', 'yndelete' => '0', 'h7009' => '2025-01-01'],
        ['Id' => 91, 'h7002' => 'E001', 'yndelete' => '1', 'h7009' => '2025-01-01'],
    ]);
    DB::connection('legacy')->table('h60')->insert(['Id' => 30, 'h6002' => 'E001', 'yndelete' => '0', 'h6005' => '2020-06-01', 'h6006' => '503)大学本科']);
    DB::connection('legacy')->table('h53')->insert(['Id' => 40, 'h5302' => 'E001', 'yndelete' => '0', 'updateTime' => '2019-01-02 09:00:00', 'h5311' => '进修内容']);
    app(LegacyReadOnlyGuard::class)->attach(DB::connection('legacy'));
});

test('only authorized tabs appear and direct access is denied', function () {
    $this->actingAs(User::find(1));
    $this->get('/personnel/history')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Personnel/History')->where('activeTab', 'professional')->has('tabs', 1)
        ->where('history.total', 21)->where('history.records.0.id', 21)->etc());
    $this->get('/personnel/history?tab=education')->assertForbidden();
    $this->get('/personnel/history?tab=unknown')->assertNotFound();
    $this->actingAs(User::find(2));
    $this->get('/personnel/history')->assertForbidden();
});

test('pagination compares adjacent business records across pages and keeps baseline', function () {
    $this->actingAs(User::find(1));
    $this->get('/personnel/history')->assertInertia(fn (Assert $page) => $page
        ->where('history.total', 21)->where('history.lastPage', 2)
        ->where('history.records.19.id', 2)->where('history.records.19.changedCount', 2)->etc());
    $this->get('/personnel/history?page=2')->assertInertia(fn (Assert $page) => $page
        ->where('history.records.0.id', 1)->where('history.records.0.baseline', true)
        ->where('history.records.0.groups.0.fields.0.value', '实验师')->etc());
    $this->get('/personnel/history?page=3')->assertNotFound();
    $this->get('/personnel/history?page=0')->assertNotFound();
});

test('other authorized categories resolve prefixed values and missing dates', function () {
    // Fixture-only SQLite mutation bypasses the application connection's read-only guard.
    DB::connection('legacy')->getPdo()->exec('INSERT INTO treeroles (roleid, treeid) VALUES (5, 338), (5, 341)');
    $this->actingAs(User::find(1));
    $this->get('/personnel/history?tab=education')->assertInertia(fn (Assert $page) => $page
        ->where('history.records.0.groups.0.fields.3.value', '大学本科')->etc());
    $this->get('/personnel/history?tab=training')->assertInertia(fn (Assert $page) => $page
        ->where('history.records.0.date', '2019-01-02')->etc());
});

test('certificates require the owners record and an actual image', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6V6cAAAAASUVORK5CYII=');
    Http::fake(['rsgl.zufedfc.edu.cn:8080/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
    $this->actingAs(User::find(1));
    $this->get('/personnel/history/certificate?tab=professional&id=21&field=h7014')->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get('/personnel/history/certificate?tab=professional&id=20&field=h7014')->assertNotFound();
    $this->get('/personnel/history/certificate?tab=professional&id=90&field=h7014')->assertNotFound();
    $this->get('/personnel/history/certificate?tab=professional&id=21&field=h7005')->assertNotFound();
});

test('unavailable certificate returns 404 and unsafe references are rejected', function () {
    Http::fake(['rsgl.zufedfc.edu.cn:8080/*' => Http::response('', 404)]);
    $this->actingAs(User::find(1));
    $this->get('/personnel/history/certificate?tab=professional&id=21&field=h7014')->assertNotFound();

    Http::fake();
    expect(app(LegacyCertificate::class)->fetch('https://evil.example/useruploadfiles/cert.png'))->toBeNull();
    Http::assertNothingSent();
});

test('empty history and same-day records are handled deterministically', function () {
    DB::connection('legacy')->getPdo()->exec("INSERT INTO user (Id, username, truename, isUse, IsSA) VALUES (3, 'E003', '丙', 1, 0)");
    DB::connection('legacy')->getPdo()->exec('INSERT INTO userroles (userId, roleId) VALUES (3, 5)');
    $this->actingAs(User::find(3));
    $this->get('/personnel/history')->assertInertia(fn (Assert $page) => $page
        ->where('history.total', 0)->has('history.records', 0)->etc());

    DB::connection('legacy')->getPdo()->exec("INSERT INTO h70 (Id, h7002, yndelete, h7009) VALUES (22, 'E001', '0', '2020-01-21')");
    $this->actingAs(User::find(1));
    $this->get('/personnel/history')->assertInertia(fn (Assert $page) => $page
        ->where('history.records.0.id', 22)->where('history.records.1.id', 21)->etc());
});
