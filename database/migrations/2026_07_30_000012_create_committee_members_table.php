<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMMITTEE_MEMBERS — which users sit on which committee, and who heads it.
 *
 * Cascades on delete both ways: removing a committee or a user removes their
 * membership rows too, since a membership has no meaning without both sides
 * (unlike `departments`, nothing else references this table directly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('committee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_head')->default(false);
            $table->timestamps();

            // One membership row per (committee, user) pair.
            $table->unique(['committee_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_members');
    }
};
