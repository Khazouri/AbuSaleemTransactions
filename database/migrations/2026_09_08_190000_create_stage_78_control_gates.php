<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 78 — [D] Appendix 63's four mandatory control gates, plus Art. 103's
 * pre-execution soundness checklist and Art. 105's suspension.
 *
 * Appendix 63 makes blocking the system's own job — "وتمنع المنظومة
 * الإلكترونية الانتقال إذا كانت متطلبات البوابة غير مكتملة" — and names four
 * points. Two already exist: gate 2 (قبل جدول الأعمال) is Stage 33's
 * MeetingReadinessService plus Stage 68's agenda gate, and gate 4 (قبل
 * الإقفال) is Stage 75's RequestClosureService, which is Appendix 47's audit
 * and Appendix 48's refusals in full. This migration carries the other two,
 * and the two articles that sit between them.
 *
 * Every column here follows Stage 75/76's card shape (a json record plus its
 * own who/when pair) rather than a spread of scalar columns, because each is
 * a checklist recorded once by one person at one moment. The suspension is
 * the exception and is a table for Stage 77's reason: Art. 105 describes an
 * *action* with a cause and a consequence, and a file can hit it more than
 * once — an overwritten column would destroy the previous round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            // --- Gate 1 (قبل القيد) -------------------------------------
            // Appendix 20's question for this gate is "هل الوقائع والوثائق
            // صحيحة ومكتملة؟", and Stage 72 already holds Appendix 57's real
            // per-type document matrix on request_types.required_documents
            // while enforcing none of it. This is the officer's answer per
            // required document, read by the gate on the قيد hop.
            $table->json('intake_gate')->nullable()->after('jurisdiction_test');
            $table->foreignId('intake_gate_checked_by_user_id')->nullable()->after('intake_gate')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('intake_gate_checked_at')->nullable()->after('intake_gate_checked_by_user_id');

            // --- Art. 103 (الثانية) — قائمة فحص سلامة القرار --------------
            // "قبل إحالة النتيجة للتنفيذ يتم التحقق من" twelve things. Eight
            // of them derive from state this system already holds; the record
            // stores all twelve so it reads as the article's own list.
            $table->json('execution_soundness')->nullable()->after('executed_at');
            $table->foreignId('execution_soundness_checked_by_user_id')->nullable()->after('execution_soundness')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('execution_soundness_checked_at')->nullable()
                ->after('execution_soundness_checked_by_user_id');
        });

        Schema::table('meeting_minutes', function (Blueprint $table) {
            // --- Gate 3 (قبل الاعتماد) ------------------------------------
            // Appendix 8's sixteen ضوابط جودة المحضر, answered at the moment
            // the head reviews the draft — "لا يحال محضر اللجنة للاعتماد قبل
            // التحقق من". No separate who/when: reviewed_by_user_id and
            // reviewed_at already are both.
            $table->json('quality_checks')->nullable()->after('review_comment');
        });

        Schema::create('request_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();

            // The status the file was frozen on, so lifting can put it back
            // exactly where Art. 105 interrupted it rather than guessing.
            $table->foreignId('suspended_from_status_id')->nullable()
                ->constrained('request_statuses')->nullOnDelete();

            // Art. 105's own two grounds: "أن معلومة جوهرية غير صحيحة أو أن
            // مستندًا أساسيًا محل شك".
            $table->string('ground', 40);
            $table->text('detail');

            $table->foreignId('suspended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at');

            // The second moment. "ويحال الموضوع للمراجعة القانونية والجهة
            // المختصة قبل ترتيب أثر جديد عليه" — nobody knows the outcome at
            // the moment of the first, so the resolution is written later and
            // is what routes the file.
            $table->string('resolution_action', 40)->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();
            $table->index(['request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_suspensions');

        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->dropColumn('quality_checks');
        });

        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('intake_gate_checked_by_user_id');
            $table->dropConstrainedForeignId('execution_soundness_checked_by_user_id');
            $table->dropColumn([
                'intake_gate',
                'intake_gate_checked_at',
                'execution_soundness',
                'execution_soundness_checked_at',
            ]);
        });
    }
};
