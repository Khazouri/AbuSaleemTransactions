<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable proof of each successful checkpoint in the approval chain.
 *
 * Stage logs remain the complete workflow history. This narrower ledger makes
 * approval-specific reporting and the Stage 19 signature trail reliable
 * without trying to infer approvals from free-form action history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->text('comment')->nullable();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->index(['request_id', 'level']);
            $table->index(['role_id', 'approved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
