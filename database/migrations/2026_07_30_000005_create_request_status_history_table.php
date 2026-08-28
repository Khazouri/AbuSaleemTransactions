<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Append-only status timeline, kept separate from stage movement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()
                ->constrained('request_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->nullable()
                ->constrained('request_statuses')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['request_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_status_history');
    }
};
