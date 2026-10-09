<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 83 — the columns the four new tables do not own.
 *
 * - `attachments.validity_checks` + who/when — Appendix 31's nine
 *   التحقق من صحة المستندات, recorded per document. A json map rather than
 *   nine columns for the same reason Stage 78's gate cards are: the answer set
 *   is the appendix's own list, and a reword of it must produce *unanswered*
 *   items rather than silently wrong ones.
 *
 * - `meeting_requests.priority_reason_code` — Appendix 33's five enumerated
 *   grounds for عاجل, structuring the free-text column Stage 82 created and
 *   explicitly handed to this stage ("Appendix 33's five enumerated urgent
 *   reasons are Stage 83's, and that stage should structure this column rather
 *   than add a second one beside it"). The free text stays as the recorded
 *   مبرر — "**ويثبت سبب الاستعجال في النظام**" asks for both.
 *
 * - `requests.prior_relation` + `prior_request_id` — Appendix 16's own
 *   classification of a new file raised after an earlier one on the same
 *   subject closed. Only two of its four values ever reach the column: تظلم
 *   and إعادة عرض are refused at intake and routed to the mechanisms that
 *   already exist for them (Track J's appeals, Stage 66's reopen), which is
 *   the literal reading of "ثم يصنف وفق طبيعته الصحيحة".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->json('validity_checks')->nullable()->after('file_section');
            $table->foreignId('validity_checked_by_user_id')->nullable()->after('validity_checks')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('validity_checked_at')->nullable()->after('validity_checked_by_user_id');
        });

        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->string('priority_reason_code', 40)->nullable()->after('priority');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->string('prior_relation', 40)->nullable()->after('description');
            $table->foreignId('prior_request_id')->nullable()->after('prior_relation')
                ->constrained('requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prior_request_id');
            $table->dropColumn('prior_relation');
        });

        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->dropColumn('priority_reason_code');
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('validity_checked_by_user_id');
            $table->dropColumn(['validity_checks', 'validity_checked_at']);
        });
    }
};
