<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_admins', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 64)->unique();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamps();
        });
        Schema::create('position_profile_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 64);
            $table->string('type', 2);
            $table->string('position', 255)->nullable();
            $table->string('appointed_at', 255)->nullable();
            $table->timestamps();
            $table->unique(['employee_number', 'type']);
        });
        Schema::create('position_change_events', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 64)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('action', 32);
            $table->json('details')->nullable();
            $table->timestamp('created_at');
        });
        foreach (['20150301', '20201003'] as $number) {
            DB::table('personnel_admins')->insertOrIgnore(['employee_number' => $number, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('position_change_events');
        Schema::dropIfExists('position_profile_baselines');
        Schema::dropIfExists('personnel_admins');
    }
};
