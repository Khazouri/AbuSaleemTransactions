<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الرقم الوظيفي — what an attached file is named after (user decision
 * 2026-10-07). Nullable so an account can exist before HR issues the number;
 * intake, not the schema, is where a missing one is refused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_number', 32)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_number']);
            $table->dropColumn('employee_number');
        });
    }
};
