<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row each time a user opens a request file's full preview. The two
 * document gates read it, so a verdict can only be given on a file its
 * reviewer actually opened, and the audit log records each open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['attachment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_previews');
    }
};
