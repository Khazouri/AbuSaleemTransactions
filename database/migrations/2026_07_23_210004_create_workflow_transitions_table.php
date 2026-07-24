<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The data-driven state machine. Intentionally seeded EMPTY here —
     * happy-path rows land in Stage 14, exception rows in Stage 16.
     */
    public function up(): void
    {
        Schema::create('workflow_transitions', function (Blueprint $table) {
            $table->id();
            // null = applies to every transaction type
            $table->foreignId('transaction_type_id')->nullable()
                ->constrained('transaction_types')->cascadeOnDelete();
            $table->foreignId('from_stage_id')->constrained('workflow_stages')->cascadeOnDelete();
            $table->foreignId('to_stage_id')->constrained('workflow_stages')->cascadeOnDelete();
            // approve, reject, forward, return_missing_docs, request_edit, cancel...
            $table->string('action', 100);
            $table->foreignId('required_role_id')->nullable()
                ->constrained('roles')->nullOnDelete();
            $table->foreignId('set_status_id')->nullable()
                ->constrained('transaction_statuses')->nullOnDelete();
            $table->boolean('is_exception')->default(false);
            $table->boolean('requires_comment')->default(false);
            $table->unsignedSmallInteger('order_no')->nullable();
            $table->timestamps();

            $table->index(['from_stage_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transitions');
    }
};
