<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCREENS (شاشات النظام) — the 22 pages of the application
 * ---------------------------------------------------------------------------
 * A registry of every screen the SPA can show: dashboard, transactions,
 * meetings, the various approval screens, users, settings, and so on.
 *
 * Two jobs:
 *   1. Drives the sidebar. The Vue nav is built from this table (Stage 5), so
 *      adding a screen is a data change, not a hard-coded menu edit.
 *   2. Provides the rows of the permission matrix — screen_role_permissions
 *      is literally "this screen x this role".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screens', function (Blueprint $table) {
            $table->id();

            // Stable key used by the permission matrix and by the API's
            // "can this user open screen X?" check. Renaming one silently
            // detaches its permissions, so treat these as fixed.
            $table->string('code', 100)->unique();

            $table->string('name_ar');
            $table->string('name_en')->nullable();

            // The Vue router path this screen maps to, e.g. /transactions.
            // Keeping it here is what lets the sidebar and the route guard
            // agree on which screen the user is looking at.
            $table->string('route', 150)->nullable();

            // Icon name for the sidebar entry.
            $table->string('icon', 100)->nullable();

            // Optional grouping, so screens can be nested under a menu header.
            $table->foreignId('parent_id')->nullable()
                ->constrained('screens')->nullOnDelete();

            // Sidebar ordering.
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Hide a screen from the whole system without deleting its
            // permission rows.
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screens');
    }
};
