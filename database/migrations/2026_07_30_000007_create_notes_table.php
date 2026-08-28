<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Conversation notes attached to a request; the UI arrives in Stage 13. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(true);
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
