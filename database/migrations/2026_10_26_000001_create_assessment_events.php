<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('legacy_assessment_id')->index();
            $table->string('employee_number', 64)->index();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('action', 32);
            $table->string('status', 16)->default('pending')->index();
            $table->text('note')->nullable();
            $table->string('result_code', 20)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_events');
    }
};
