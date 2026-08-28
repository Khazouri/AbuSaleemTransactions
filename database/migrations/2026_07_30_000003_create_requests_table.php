<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The current, searchable state of each request.
 *
 * The stage/status history lives in separate append-only tables so the current
 * row remains fast to filter while later workflow stages retain an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->id();

            // Filled by Stage 13. Nullable lets this stage list manually
            // entered rows before the intake flow owns reference generation.
            $table->string('reference_number', 50)->nullable()->unique();
            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('department_id')->nullable()
                ->constrained('departments')->nullOnDelete();
            $table->foreignId('request_type_id')->nullable()
                ->constrained('request_types')->nullOnDelete();
            $table->foreignId('status_id')->nullable()
                ->constrained('request_statuses')->nullOnDelete();
            $table->foreignId('current_stage_id')->nullable()
                ->constrained('workflow_stages')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Stage 17 turns the type's SLA into this date. Defining it now
            // keeps the request record stable instead of splitting its
            // lifecycle state across a future companion table.
            $table->timestamp('submitted_at')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('decision_grade')->nullable();
            $table->timestamps();

            $table->index(['status_id', 'created_at']);
            $table->index(['department_id', 'created_at']);
            $table->index(['request_type_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
