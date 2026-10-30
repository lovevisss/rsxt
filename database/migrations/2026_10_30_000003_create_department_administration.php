<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_profile_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 64)->unique();
            $table->string('profile_department', 255)->nullable();
            $table->unsignedBigInteger('account_department_id')->nullable();
            $table->timestamps();
        });
        Schema::create('department_change_events', function (Blueprint $table) {
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
        Schema::dropIfExists('department_change_events');
        Schema::dropIfExists('department_profile_baselines');
    }
};
