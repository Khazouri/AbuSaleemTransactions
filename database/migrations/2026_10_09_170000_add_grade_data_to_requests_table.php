<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The employee's entitled grade and the date of their current grade, stated
 * at intake beside الدرجة الوظيفية (`decision_grade`) and confirmed by HR on
 * the employment-file card. Nullable because every request filed before this
 * migration has neither; StoreRequest requires both for a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('entitled_grade')->nullable()->after('decision_grade');
            $table->date('grade_date')->nullable()->after('entitled_grade');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn(['entitled_grade', 'grade_date']);
        });
    }
};
