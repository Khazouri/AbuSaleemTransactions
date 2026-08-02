<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 26 — the record of every snapshot taken.
 *
 * The archive itself lives on a disk; this table is the index the screen reads,
 * so a failed run is still visible instead of vanishing into the log. That is
 * why `status` exists at all: a row is written either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('disk');
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('includes_files')->default(true);
            $table->string('status')->default('completed');
            $table->text('error')->nullable();
            // Nullable: the nightly scheduled run has no human actor behind it.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Retention pruning and the screen both read newest-first.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
