<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 76, Track K — [D] Appendix 70's دليل التنفيذ and النموذج 17's
 * أمر تنفيذ قرار وظيفي.
 *
 * Column shape on `requests` deliberately mirrors Stage 75's closure columns:
 * `executed_at` IS the execution date and `executed_by_user_id` the officer
 * who recorded it, so the `execution` JSON carries النموذج 17's remaining card
 * fields (الجهة المنفذة · الإجراء المنفذ · تاريخ سريان الأثر · جهة الاعتماد ·
 * رقم الاعتماد · تاريخ الاعتماد · بيان الأثر المالي) and `execution_checklist`
 * its seven متابعة التنفيذ answers.
 *
 * `attachments.execution_evidence_type` is what makes Appendix 70 enforceable
 * rather than attested: "لا يكفي أن تقول الجهة المنفذة: (تم التنفيذ) بل يجب
 * إرفاق دليل التنفيذ". A non-null value marks that document as دليل التنفيذ and
 * records which of the appendix's eight kinds it is. It rides the existing
 * table rather than a second one — Stage 59's separate `appeal_attachments`
 * exists because an appeal is a different parent, whereas execution evidence
 * belongs to the same `requests` row whose document set the detail screen and
 * Stage 72's checklist card already render as one list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->json('execution')->nullable()->after('closed_at');
            $table->json('execution_checklist')->nullable()->after('execution');
            $table->foreignId('executed_by_user_id')->nullable()->after('execution_checklist')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable()->after('executed_by_user_id');
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->string('execution_evidence_type', 40)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('execution_evidence_type');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('executed_by_user_id');
            $table->dropColumn(['execution', 'execution_checklist', 'executed_at']);
        });
    }
};
