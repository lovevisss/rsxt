<?php

use App\Services\InstanceDataCopy;
use App\Services\MigratedLegacyFiles;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('legacy_files.enabled', true);
    Storage::fake('local');
    Schema::create('legacy_file_mappings', function (Blueprint $table) {
        $table->string('source_key')->primary();
        $table->string('status');
        $table->string('disk');
        $table->string('path');
        $table->string('sha256');
        $table->string('mime');
        $table->integer('size');
    });
});

test('legacy aliases and embedded references resolve to the allowed source only', function () {
    $files = app(MigratedLegacyFiles::class);
    expect($files->canonicalUrl('photohttp://localhost:8080//useruploadfiles/a%20b.jpg'))
        ->toBe('http://rsgl.zufedfc.edu.cn:8080/useruploadfiles/a%20b.jpg');
    expect($files->references('<img src="/useruploadfiles/a.jpg"><a href="http://10.1.12.184:8080/useruploadfiles/b.pdf">file</a>'))
        ->toHaveCount(2);
    expect($files->canonicalUrl('useruploadfiles/20180410/template.xml'))
        ->toBe('http://rsgl.zufedfc.edu.cn:8080/useruploadfiles/20180410/template.xml');
    foreach (['http://evil.test/useruploadfiles/a.jpg', 'http://localhost/useruploadfiles/../a.jpg',
        'http://localhost/useruploadfiles/a%2fb.jpg', 'http://localhost/useruploadfiles/a.jpg?redirect=1'] as $url) {
        expect($files->canonicalUrl($url))->toBeNull();
    }
});

test('private file reads require a copied mapping and matching content hash', function () {
    $files = app(MigratedLegacyFiles::class);
    $url = 'http://rsgl.zufedfc.edu.cn:8080/useruploadfiles/a.pdf';
    Storage::disk('local')->put('legacy-files/example', 'private content');
    DB::table('legacy_file_mappings')->insert([
        'source_key' => hash('sha256', $url), 'status' => 'copied', 'disk' => 'local',
        'path' => 'legacy-files/example', 'sha256' => hash('sha256', 'private content'),
        'mime' => 'application/pdf', 'size' => strlen('private content'),
    ]);
    expect($files->fetch($url))->toBe(['content' => 'private content', 'mime' => 'application/pdf']);
    Storage::disk('local')->put('legacy-files/example', 'tampered');
    expect($files->fetch($url))->toBeNull();
    expect($files->fetch('http://rsgl.zufedfc.edu.cn:8080/useruploadfiles/missing.pdf'))->toBeNull();
});

test('copy DDL qualifies destination schema and normalizes old InnoDB row format', function () {
    $copy = app(InstanceDataCopy::class);
    expect($copy->tableDdl('CREATE TABLE `sample` (`Id` int) ENGINE=InnoDB ROW_FORMAT=COMPACT', 'sample', 'uchr'))
        ->toBe('CREATE TABLE `uchr`.`sample` (`Id` int) ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
    expect(fn () => $copy->tableDdl('DROP TABLE sample', 'sample', 'uchr'))->toThrow(RuntimeException::class);
});

test('activation replaces indented duplicate environment entries and preserves quoted values', function () {
    $copy = app(InstanceDataCopy::class);
    $secret = 'test\\path"value!';
    $environment = $copy->rewriteEnvironment("DB_HOST=old\n DB_HOST=older\nexport DB_HOST=oldest\nAPP_NAME=test\n", [
        'DB_HOST' => '10.1.12.183', 'DB_PASSWORD' => $secret,
    ]);
    $parsed = Dotenv\Dotenv::parse($environment);
    expect($parsed['DB_HOST'])->toBe('10.1.12.183');
    expect($parsed['DB_PASSWORD'])->toBe($secret);
    expect(substr_count($environment, 'DB_HOST='))->toBe(1);
});
