<?php

use App\Models\User;
use App\Services\LegacyReadOnlyGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('database.connections.legacy', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('user', function (Blueprint $table) {
        $table->integer('Id')->primary();
        $table->string('username');
        $table->string('truename');
        $table->string('email')->nullable();
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
    Schema::connection('legacy')->create('gzsys', function (Blueprint $table) {
        $table->string('tableName');
        $table->string('FieldName');
        $table->string('FieldShowName');
        $table->integer('Sequence');
    });
    Schema::connection('legacy')->create('h50', function (Blueprint $table) {
        $table->integer('Id')->primary();
        $table->string('yndelete');
        $profileColumns = collect(config('personnel_profile.sections'))->flatMap(fn ($section) => array_keys($section['fields']))->all();
        foreach (array_unique([...$profileColumns, 'h5001', 'h5002']) as $column) {
            $table->string($column)->nullable();
        }
    });
    Schema::connection('legacy')->create('h55', function (Blueprint $table) {
        $table->integer('Id')->primary();
        $table->string('h5503');
        $table->string('h5504')->nullable();
        $table->string('yndelete');
    });
    Schema::connection('legacy')->create('department', function (Blueprint $table) {
        $table->string('objname');
        $table->string('name');
    });
    Schema::connection('legacy')->create('d202', function (Blueprint $table) {
        $table->string('objname');
        $table->string('objjc');
    });

    DB::connection('legacy')->table('user')->insert([
        ['Id' => 1, 'username' => 'E001', 'truename' => '甲', 'isUse' => 1],
        ['Id' => 2, 'username' => 'E002', 'truename' => '乙', 'isUse' => 1],
    ]);
    DB::connection('legacy')->table('userroles')->insert(['userId' => 1, 'roleId' => 5]);
    DB::connection('legacy')->table('treeroles')->insert(['roleid' => 5, 'treeid' => 303]);
    DB::connection('legacy')->table('h50')->insert([
        ['Id' => 11, 'h5001' => '甲的档案', 'h5002' => 'E001', 'h5003' => 'D1', 'h5005' => '22)男', 'h5009' => '330326', 'yndelete' => '0'],
        ['Id' => 12, 'h5001' => '乙的档案', 'h5002' => 'E002', 'h5003' => null, 'h5005' => null, 'h5009' => null, 'yndelete' => '0'],
    ]);
    DB::connection('legacy')->table('h55')->insert([
        'Id' => 9, 'h5503' => 'E001',
        'h5504' => 'photo.pnghttp://rsgl.zufedfc.edu.cn:8080//useruploadfiles/photo.png',
        'yndelete' => '0',
    ]);
    DB::connection('legacy')->table('department')->insert(['objname' => 'D1', 'name' => '信息技术部']);
    DB::connection('legacy')->table('d202')->insert(['objname' => '330326', 'objjc' => '浙江省温州市平阳县']);
    DB::connection('legacy')->table('gzsys')->insert(['tableName' => 'h50', 'FieldName' => 'h5001', 'FieldShowName' => '姓名', 'Sequence' => 10]);
    app(LegacyReadOnlyGuard::class)->attach(DB::connection('legacy'));
});

test('a query string user id cannot authenticate a visitor', function () {
    $this->get('/dashboard?userId=1')->assertRedirect(route('login'));
    $this->get('/sso/callback?userId=1')->assertBadRequest();
});

test('unconfigured portal shows a login page and local preview requires a signed URL', function () {
    config()->set('personnel.local_preview', true);
    config()->set('personnel.preview_user_id', 1);
    app()->detectEnvironment(fn () => 'local');

    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Personnel/Login')
        ->where('previewUrl', fn ($url) => is_string($url) && str_starts_with($url, '/preview?')));
    $this->get('/preview')->assertForbidden();

    $signed = URL::temporarySignedRoute('preview.login', now()->addMinutes(5), absolute: false);
    $this->get($signed)->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs(User::find(1));
});

test('local preview stays disabled outside local environment', function () {
    config()->set('personnel.local_preview', true);
    config()->set('personnel.preview_user_id', 1);
    app()->detectEnvironment(fn () => 'production');

    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Personnel/Login')
        ->where('previewUrl', null));
});

test('CAS ticket validation logs in an enabled legacy user', function () {
    config()->set('personnel.cas.validate_url', 'https://portal.example.test/cas/serviceValidate');
    config()->set('personnel.cas.service_url', 'https://hr.example.test/sso/callback');
    Http::fake(['portal.example.test/*' => Http::response('<cas:serviceResponse xmlns:cas="http://www.yale.edu/tp/cas"><cas:authenticationSuccess><cas:user>E001</cas:user></cas:authenticationSuccess></cas:serviceResponse>')]);

    $this->get('/sso/callback?ticket=ST-valid')->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs(User::find(1));
});

test('menu permission and employee number isolate personal records', function () {
    $this->actingAs(User::find(1));

    $this->get('/personnel/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Personnel/Profile')
            ->where('record.h5001', '甲的档案')
            ->where('record.h5003', '信息技术部')
            ->where('record.h5005', '男')
            ->where('record.h5009', '浙江省温州市平阳县')
            ->etc());

    $this->get('/personnel/leave')->assertForbidden();
});

test('profile sections expose only their own fields', function () {
    $this->actingAs(User::find(1));

    $this->get('/personnel/profile?section=education')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Personnel/Profile')
        ->where('activeSection', 'education')
        ->missing('record.h5001')
        ->has('record.h5031')
        ->etc());
    $this->get('/personnel/profile?section=unknown')->assertNotFound();
});

test('personal portrait and document routes use the signed in employees record', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6V6cAAAAASUVORK5CYII=');
    Http::fake(['rsgl.zufedfc.edu.cn:8080/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

    $this->actingAs(User::find(1));
    $this->get('/personnel/profile/portrait')->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get('/personnel/profile/print')->assertOk()->assertSee('甲的档案')->assertSee('调动类型')->assertDontSee('乙的档案');
    $pdf = $this->get('/personnel/profile/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    $this->actingAs(User::find(2));
    $this->get('/personnel/profile/portrait')->assertForbidden();
    $this->get('/personnel/profile/pdf')->assertForbidden();
});

test('legacy writes are blocked before database execution', function () {
    expect(fn () => DB::connection('legacy')->table('user')->where('Id', 1)->update(['truename' => '改名']))
        ->toThrow(RuntimeException::class);
    expect(DB::connection('legacy')->table('user')->where('Id', 1)->value('truename'))->toBe('甲');
});
