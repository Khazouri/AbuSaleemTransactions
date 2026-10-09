<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 98 — [D] Appendix 6 row 3: تجهيز الملف الوظيفي is الموارد البشرية's,
 * and until now it was the submitter's.
 *
 * The row gives الموظف a literal `—`, yet `DocumentCompletenessService` ran at
 * submission, so the employee had to assemble Appendix 57's mandatory rows —
 * including the employment-record extracts the municipality itself holds —
 * before the request could be created at all. This card is where HR records
 * having assembled them, read by the `receive_and_register → register` hop
 * that R12 already owns (Stage 96 left it the one registering party).
 *
 * Same shape as Stage 78's `intake_gate` beside it, deliberately: both are a
 * checklist over Appendix 57 rows recorded once by one person at one moment,
 * and a reader who knows one should not have to learn a second convention for
 * the other. The two are different cards for different parties at different
 * stages — HR assembles الملف الوظيفي here, المقرر attests to the committee
 * file's completeness there (rows 3 and 4 of the same appendix).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->json('employment_file')->nullable()->after('intake_gate_checked_at');
            $table->foreignId('employment_file_prepared_by_user_id')->nullable()->after('employment_file')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('employment_file_prepared_at')->nullable()
                ->after('employment_file_prepared_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employment_file_prepared_by_user_id');
            $table->dropColumn(['employment_file', 'employment_file_prepared_at']);
        });
    }
};
