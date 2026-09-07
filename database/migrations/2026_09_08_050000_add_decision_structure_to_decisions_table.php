<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 74 — the structure [D] requires of a recorded decision, which a
 * single free-text `comment` was standing in for.
 *
 * Real columns rather than a JSON blob (the Stage 68 Appendix-22 and Stage 73
 * Appendix-65 precedent), because later stages query them: Stage 75's closure
 * record reads the النتيجة النهائية, Stage 80's registers list them and Stage
 * 81's KPIs count refusals by reason.
 *
 * - `instrument` — Art. 90's قرار / توصية / رأي, which the article forbids
 *   using interchangeably; unenforceable while nothing records which was
 *   issued. Shares RequestLegalReview::COMMITTEE_MANDATES' vocabulary so the
 *   legal officer's Appendix 22 answer and the committee's own instrument
 *   speak the same words.
 * - `decision_subject`/`_facts`/`_basis`/`_operative` — Appendix 27's four
 *   parts (موضوع / وقائع / سند / منطوق), which also complete Art. 89's
 *   six-element per-decision record; `comment` is thereby freed to be Art.
 *   89's own الملاحظات اللازمة rather than the place all four were crammed.
 * - `refusal_reason_code` — Appendix 28's professional reason, so that
 *   "لم توافق اللجنة" alone stops being recordable.
 * - the five `deferral_*` — Art. 34's own list, which is what makes Appendix
 *   29's "يمنع استخدام عبارة (تأجيل للمراجعة) دون بيان المطلوب" enforceable.
 *
 * All nullable at the database layer: which of them is required depends on
 * the tallied outcome, which is not known until DecisionController has
 * resolved it, so DecisionStructureRules enforces it there — the same split
 * StoreDecisionRequest's own docblock already documents for comment and
 * signature. The real database held zero decisions when this landed, so
 * nothing needed backfilling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->string('instrument', 20)->nullable()->after('outcome');
            $table->text('decision_subject')->nullable()->after('comment');
            $table->text('decision_facts')->nullable()->after('decision_subject');
            $table->text('decision_basis')->nullable()->after('decision_facts');
            $table->text('decision_operative')->nullable()->after('decision_basis');
            $table->string('refusal_reason_code', 40)->nullable()->after('decision_operative');
            $table->text('deferral_reason')->nullable()->after('refusal_reason_code');
            $table->text('deferral_required_completion')->nullable()->after('deferral_reason');
            $table->string('deferral_responsible_body')->nullable()->after('deferral_required_completion');
            $table->text('deferral_required_document')->nullable()->after('deferral_responsible_body');
            $table->string('deferral_legal_period')->nullable()->after('deferral_required_document');
        });
    }

    public function down(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->dropColumn([
                'instrument',
                'decision_subject',
                'decision_facts',
                'decision_basis',
                'decision_operative',
                'refusal_reason_code',
                'deferral_reason',
                'deferral_required_completion',
                'deferral_responsible_body',
                'deferral_required_document',
                'deferral_legal_period',
            ]);
        });
    }
};
