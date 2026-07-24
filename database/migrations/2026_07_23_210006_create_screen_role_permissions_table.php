<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCREEN_ROLE_PERMISSIONS (مصفوفة الصلاحيات) — the permission matrix
 * ---------------------------------------------------------------------------
 * The fine-grained access grid: 22 screens x 8 roles = 176 rows, each holding
 * 7 yes/no action flags. This is the SOURCE OF TRUTH for access control.
 *
 * Read it as a sentence:
 *   "On the [screen], role [role] may [view/add/edit/delete/approve/print/export]."
 *
 * Used in three places:
 *   - Stage 8  the admin grid that edits these rows
 *   - Stage 9  API middleware AND the Vue route guard / v-can directive —
 *              the same rows enforce both sides, so the UI can never offer a
 *              button the API would refuse
 *   - Stage 5  the sidebar only lists screens where can_view is true
 *
 * Default is false everywhere (least privilege): a role gets nothing until a
 * row explicitly grants it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_role_permissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('screen_id')->constrained('screens')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            // The seven actions, matching the columns of the Role Matrix sheet.
            $table->boolean('can_view')->default(false);    // عرض
            $table->boolean('can_add')->default(false);     // إضافة
            $table->boolean('can_edit')->default(false);    // تعديل
            $table->boolean('can_delete')->default(false);  // حذف
            $table->boolean('can_approve')->default(false); // اعتماد / اتخاذ قرار
            $table->boolean('can_print')->default(false);   // طباعة
            $table->boolean('can_export')->default(false);  // تصدير

            $table->timestamps();

            // Exactly one row per screen/role pair. Also makes the Stage 8
            // bulk save safe to run as an upsert.
            $table->unique(['screen_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_role_permissions');
    }
};
