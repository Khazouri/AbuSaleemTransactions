<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DEPARTMENTS (الإدارات / الأقسام)
 * ---------------------------------------------------------------------------
 * The municipality's organisational tree: بلدية أبو سليم at the root, with
 * departments beneath it (Administrative Affairs, Engineering, Finance, ...).
 *
 * Why it matters elsewhere:
 *  - Every user belongs to a department (users.department_id).
 *  - Every request is owned by a department (requests.department_id).
 *  - The department `code` becomes the middle segment of a request's
 *    reference number: YYYY-DEPT-000123 (built in Stage 13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();

            // Bilingual names. Arabic is the primary UI language; English is
            // optional and shown when the user toggles to EN (Stage 5).
            $table->string('name_ar');
            $table->string('name_en')->nullable();

            // Short code (ADM, ENG, FIN...). Used inside reference numbers, so
            // it must stay unique. Nullable because the root org has no code
            // requirement of its own.
            $table->string('code', 50)->nullable()->unique();

            // Self-reference: a department may sit under a parent department,
            // which is what makes this a tree. nullOnDelete() means deleting a
            // parent promotes its children to the root rather than deleting them.
            $table->foreignId('parent_id')->nullable()
                ->constrained('departments')->nullOnDelete();

            // Soft disable: keeps history intact when a department is retired.
            $table->boolean('is_active')->default(true);

            // deleted_at — rows are never truly removed, so requests that
            // reference a department keep resolving after "deletion".
            $table->softDeletes();
            $table->timestamps();
        });

        // The users table is created first (Laravel's own 0001_01_01 migration),
        // so its department_id column exists but has no foreign key yet.
        // Now that departments exists, we can attach the constraint.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('department_id')
                ->references('id')->on('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Drop the FK before the table it points at, or MySQL refuses.
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });

        Schema::dropIfExists('departments');
    }
};
