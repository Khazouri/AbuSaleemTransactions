<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only record of every workflow move; populated by Stage 14. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_stage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_stage_id')->nullable()
                ->constrained('workflow_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()
                ->constrained('workflow_stages')->nullOnDelete();
            $table->string('action', 100);
            $table->text('comment')->nullable();
            $table->foreignId('acted_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['request_id', 'acted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_stage_logs');
    }
};
