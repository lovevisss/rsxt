<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_profile_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 64);
            $table->string('type', 2);
            $table->string('grade', 255)->nullable();
            $table->timestamps();
            $table->unique(['employee_number', 'type']);
        });
        Schema::create('grade_change_events', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 64);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 32);
            $table->json('details')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_change_events');
        Schema::dropIfExists('grade_profile_baselines');
    }
};
