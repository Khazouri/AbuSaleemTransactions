<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 75, Track K — [D] Art. 37's closure record for ordinary requests,
 * the gap Stage 65 built for appeals and explicitly declined to retrofit
 * here ("the same gap exists for ordinary requests, which this stage does
 * not close").
 *
 * Column shape deliberately mirrors `appeals`' Stage 65 columns rather than
 * inventing a second one: `closed_at` IS Art. 37's تاريخ الإقفال and
 * `closed_by_user_id` is النموذج 18's مسؤول الإقفال, so the `closure` JSON
 * carries the article's other seven fields (النتيجة النهائية · رقم القرار
 * النهائي · جهة الاعتماد · تاريخ التنفيذ · الجهة المنفذة · حالة الإشعار ·
 * موقع حفظ الملف) under the same key names the appeal card already uses.
 *
 * `closure_audit` has no appeal-side equivalent: it holds Appendix 47's
 * twelve pre-closure checks, which have to be *recorded* rather than merely
 * displayed because Appendix 48's eighth refusal condition ("صدر قرارها ولم
 * يتم تحديث ملف الموظف") is unenforceable without them — Track K's scope
 * decision (1) puts ملف الخدمة outside this application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->json('closure')->nullable()->after('jurisdiction_test');
            $table->json('closure_audit')->nullable()->after('closure');
            $table->foreignId('closed_by_user_id')->nullable()->after('closure_audit')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_user_id');
            $table->dropColumn(['closure', 'closure_audit', 'closed_at']);
        });
    }
};
