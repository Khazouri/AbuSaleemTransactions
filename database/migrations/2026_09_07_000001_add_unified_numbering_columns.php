<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 70 (Track K) — [D] Appendix 15's unified numbering, and the intake
 * receipt that Art. 15's "handing the request to the direct manager is not a
 * قيد" rule creates a need for.
 *
 * Four artifacts carry a number under Appendix 15: the request
 * (PM-COM/YEAR/0001), the meeting (PM-MTG/YEAR/01), the minutes
 * (PM-MIN/YEAR/01) and the decision (PM-DEC/YEAR/001). `meetings
 * .meeting_number` already exists (Stage 30) as free text a scheduler typed;
 * it only gains the unique index here, because from Stage 70 on it is
 * server-minted and a duplicate would defeat Appendix 8's own pre-approval
 * check ("تطابق رقم الاجتماع").
 *
 * `intake_receipt_number` is deliberately a SEPARATE column rather than an
 * early value of `reference_number`: Art. 20 grants the reference only after
 * completeness is established, and النموذج 05 forbids more than one basic
 * number per request. Keeping them apart is what lets the UI say, truthfully,
 * that the receipt is not a registration.
 *
 * Every column is nullable — a request that has not reached the قيد has no
 * reference, a meeting scheduled before this stage has no minted number — and
 * unique, which in both MySQL and SQLite treats each NULL as distinct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->string('intake_receipt_number', 50)->nullable()->unique()->after('reference_number');
        });

        Schema::table('decisions', function (Blueprint $table) {
            $table->string('decision_number', 50)->nullable()->unique()->after('meeting_request_id');
        });

        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->string('minutes_number', 50)->nullable()->unique()->after('meeting_id');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->unique('meeting_number');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique(['meeting_number']);
        });

        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->dropUnique(['minutes_number']);
            $table->dropColumn('minutes_number');
        });

        Schema::table('decisions', function (Blueprint $table) {
            $table->dropUnique(['decision_number']);
            $table->dropColumn('decision_number');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropUnique(['intake_receipt_number']);
            $table->dropColumn('intake_receipt_number');
        });
    }
};
