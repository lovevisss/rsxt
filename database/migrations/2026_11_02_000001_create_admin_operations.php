<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_operations', function (Blueprint $table) {
            $table->id();
            $table->string('module', 32);
            $table->string('action', 32);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('employee_number', 64)->nullable();
            $table->json('items');
            $table->unsignedInteger('item_count')->default(0);
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('related_operation_id')->nullable();
            $table->string('legacy_source', 40)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('rolled_back_at')->nullable();
            $table->unsignedBigInteger('rolled_back_by')->nullable();
            $table->index(['module', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['employee_number', 'created_at']);
            $table->unique(['legacy_source', 'legacy_id']);
        });
        foreach (['position_change_events' => 'position', 'grade_change_events' => 'grade',
            'department_change_events' => 'department'] as $source => $module) {
            if (! Schema::hasTable($source)) {
                continue;
            }
            DB::table($source)->orderBy('id')->chunkById(500, function ($events) use ($source, $module): void {
                $rows = [];
                foreach ($events as $event) {
                    $rows[] = ['module' => str_starts_with($event->action, 'admin_') ? 'administrators' : $module,
                        'action' => $event->action, 'actor_user_id' => $event->actor_user_id,
                        'employee_number' => $event->employee_number, 'items' => json_encode([
                            ['record_id' => $event->record_id, 'details' => $event->details],
                        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'item_count' => 1,
                        'status' => 'legacy', 'legacy_source' => $source, 'legacy_id' => $event->id,
                        'created_at' => $event->created_at];
                }
                DB::table('admin_operations')->insert($rows);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_operations');
    }
};
