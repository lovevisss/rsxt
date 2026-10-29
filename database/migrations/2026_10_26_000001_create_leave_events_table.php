<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 16);
            $table->string('kind', 8);
            $table->unsignedBigInteger('legacy_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('action', 24);
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['source', 'kind', 'legacy_id']);
        });

        Schema::create('leave_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 16);
            $table->unsignedBigInteger('legacy_detail_id');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 80);
            $table->unsignedInteger('size');
            $table->timestamp('created_at');
            $table->index(['source', 'legacy_detail_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_attachments');
        Schema::dropIfExists('leave_events');
    }
};
