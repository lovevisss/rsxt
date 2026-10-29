<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_file_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('source_key', 64)->unique();
            $table->text('source_url');
            $table->string('status', 20)->default('pending')->index();
            $table->string('disk', 30)->default('local');
            $table->string('path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('reference_count')->default(1);
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
        Schema::table('leave_attachments', function (Blueprint $table): void {
            $table->string('disk', 30)->default('public');
        });
    }

    public function down(): void
    {
        Schema::table('leave_attachments', fn (Blueprint $table) => $table->dropColumn('disk'));
        Schema::dropIfExists('legacy_file_mappings');
    }
};
