<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERMISSIONS (الصلاحيات) — coarse capability list
 * ---------------------------------------------------------------------------
 * A flat catalogue of things a role is allowed to do, e.g. "transactions.delete".
 *
 * IMPORTANT — this system has TWO permission layers, and they answer different
 * questions:
 *
 *   1. permissions + permission_role  (this table)
 *      Coarse, capability-level: "may this role delete transactions at all?"
 *      Convenient for business-rule checks inside services.
 *
 *   2. screen_role_permissions        (Stage 3)
 *      Fine-grained grid: 22 screens x 8 roles x 7 actions
 *      (view/add/edit/delete/approve/print/export). This is the source of
 *      truth the UI and API enforcement use in Stage 9.
 *
 * When the two could disagree, layer 2 wins for anything screen-related.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();

            // Dot-notation capability key, e.g. "transactions.view",
            // "decisions.final_approve". Checked via $user->hasPermission($key).
            $table->string('key', 100)->unique();

            $table->string('name_ar');
            $table->string('name_en')->nullable();

            // Bucket used to group rows in the admin UI: transactions,
            // workflow, admin, reports, audit.
            $table->string('group', 100)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
