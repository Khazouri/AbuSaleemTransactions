<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 28 — lets sidebar rows be clustered under a collapsible section
 * heading (e.g. the 9-screen "إدارة الاجتماعات" / meetings-management group)
 * instead of always rendering as flat top-level links.
 *
 * Deliberately NOT reusing the existing (unused) `parent_id` self-reference:
 * that models an arbitrary tree via a real Screen row per group, but the
 * only grouping this system needs is one flat cluster per group slug. A
 * plain nullable string keeps ScreenSeeder / ScreenResource / the frontend
 * doing simple string comparisons instead of id lookups against a headless
 * "group" screen row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            // Null = ungrouped, rendered as a flat top-level sidebar link —
            // unchanged behaviour for every screen seeded before Stage 28.
            $table->string('group', 100)->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn('group');
        });
    }
};
