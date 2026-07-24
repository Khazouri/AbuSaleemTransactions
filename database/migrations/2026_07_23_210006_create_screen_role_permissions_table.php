<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 22 screens x 8 roles x 7 actions matrix.
     * Edited via the Stage 8 grid; enforced on both sides in Stage 9.
     */
    public function up(): void
    {
        Schema::create('screen_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screen_id')->constrained('screens')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_add')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_approve')->default(false);
            $table->boolean('can_print')->default(false);
            $table->boolean('can_export')->default(false);
            $table->timestamps();

            $table->unique(['screen_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_role_permissions');
    }
};
