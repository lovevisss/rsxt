<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('applicant_user_id');
            $table->string('employee_number', 64)->index();
            $table->unsignedBigInteger('legacy_profile_id');
            $table->string('active_employee_number', 64)->nullable()->unique();
            $table->string('status', 32)->index();
            $table->text('reason');
            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('reviewer_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('apply_error')->nullable();
            $table->timestamps();
        });
        Schema::create('profile_correction_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->index();
            $table->string('field_key', 16);
            $table->string('field_label', 80);
            $table->text('old_raw')->nullable();
            $table->text('new_raw')->nullable();
            $table->unique(['request_id', 'field_key']);
        });
        Schema::create('profile_correction_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->index();
            $table->string('disk', 40);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 80);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
        Schema::create('profile_correction_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 40);
            $table->text('note')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_correction_events');
        Schema::dropIfExists('profile_correction_files');
        Schema::dropIfExists('profile_correction_changes');
        Schema::dropIfExists('profile_correction_requests');
    }
};
