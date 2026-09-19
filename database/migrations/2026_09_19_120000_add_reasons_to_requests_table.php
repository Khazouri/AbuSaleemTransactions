<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 90 — [G] lists «الأسباب» as an input of its own.
 *
 * It folds into the free-text `description` today, so an employee who states
 * why they are asking and an employee who describes what they are asking for
 * write into the same box, and nothing downstream can tell the two apart.
 *
 * Nullable, and NOT required at intake — a deliberate call rather than an
 * oversight. `description` is itself nullable, so splitting a field out of it
 * must not silently raise the bar on the employee; [G] lists this field, it
 * does not mandate it. Existing rows keep null, which is the honest answer for
 * a request filed before the question was asked separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->text('reasons')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('reasons');
        });
    }
};
