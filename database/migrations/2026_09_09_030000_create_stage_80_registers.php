<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 80, Track K — the two pieces of schema Art. 98's twelve registers need
 * that no prior stage wrote. The other eleven registers are views over data
 * that already exists; only these two had nowhere to live.
 *
 * 1. `approval_referrals` — [D] Art. 30's الاعتماد داخل البلدية, whose six
 *    recorded fields are "تاريخ الإحالة · رقم كتاب الإحالة · الجهة المحال
 *    إليها · تاريخ ورود النتيجة · رقم قرار الاعتماد أو المستند النهائي · أي
 *    ملاحظات أو توجيهات صادرة عن جهة الاعتماد". Stage 77 recorded the fourth
 *    and sixth, but only on the *return* path — a file that comes back
 *    approved travels the ordinary `approve` transition and records nothing —
 *    and its own note asked whichever stage built Art. 98's سجل الإحالات
 *    للاعتماد to take the remaining four together rather than fragment the
 *    article across two stages. This is that register.
 *
 *    Many rows per request, on Stage 77's `approval_returns` shape and for the
 *    same two reasons: a file can be referred more than once (a formal return
 *    corrected and re-referred is literally a second إحالة, and the البلدية →
 *    وزارة الحكم المحلي path is two referrals by itself), and the article names
 *    an outward moment and an inward one that nobody can answer at the same
 *    time. The `referred_*` half is written when the file goes out; the
 *    `result_*` half when the answer comes back.
 *
 * 2. `attachments.file_section` — [D] Appendix 14's هيكل الملف الإلكتروني, a
 *    fixed thirteen-folder classification closing with "ويمنع حفظ الملفات
 *    بصورة عشوائية دون تصنيف". Nullable at this layer *only* so rows written
 *    before this stage read honestly as غير مصنف instead of being retro-
 *    assigned a folder nobody chose; StoreAttachmentRequest requires it for
 *    every new upload, which is where the appendix's own prohibition bites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
            // The checkpoint the file was standing on when it was referred, so
            // a البلدية referral and a وزارة referral stay distinguishable
            // after the request has moved on. Stored rather than derived for
            // the reason Stage 77 stored its own: the result can move it.
            $table->foreignId('referred_from_stage_id')->nullable()
                ->constrained('workflow_stages')->nullOnDelete();

            // --- Art. 30, the outward three -----------------------------------
            $table->date('referred_at');            // تاريخ الإحالة
            $table->string('letter_number');        // رقم كتاب الإحالة
            $table->string('referred_to_body');     // الجهة المحال إليها
            $table->foreignId('recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // --- Art. 30, the inward three ------------------------------------
            // Unknown at the moment of the referral, which is why this is a
            // second write rather than six required fields on one form.
            $table->date('result_received_at')->nullable();      // تاريخ ورود النتيجة
            $table->string('approval_decision_number')->nullable(); // رقم قرار الاعتماد أو المستند النهائي
            $table->text('result_note')->nullable();             // أي ملاحظات أو توجيهات
            // approved | returned — what actually came back. A returned result
            // is recorded here as an outcome only; the return's own reason and
            // re-processing stay in Stage 77's `approval_returns`, which is
            // Art. 98's separate register 8.
            $table->string('result_outcome', 20)->nullable();
            $table->foreignId('result_recorded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('result_recorded_at')->nullable();

            $table->timestamps();

            $table->index(['request_id', 'id']);
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->string('file_section', 40)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('file_section');
        });

        Schema::dropIfExists('approval_referrals');
    }
};
