<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 68 — [D] Appendix 21's مصفوفة السند القانوني للموضوعات الوظيفية, which
 * insists a committee file must name the *direct* legal basis, not just the
 * statute ("يجب ألا يكتفى في ملفات اللجنة بذكر اسم التشريع فقط").
 *
 * Rides `request_types` rather than a new table: the appendix is a per-subject
 * matrix, and request type is this system's subject. Purely informational —
 * it pre-fills Appendix 22's بطاقة السند القانوني on the legal-review form and
 * is never enforced, matching `required_documents`/`default_administrative_route`.
 *
 * Arabic-only on purpose (no `_en` sibling, unlike name_ar/name_en): these are
 * citations of Libyan statute, and an invented English rendering of
 * "المادة 135 من قانون علاقات العمل" would be worse than none at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->string('legal_basis_ar')->nullable()->after('default_administrative_route');
            $table->string('legal_basis_note_ar')->nullable()->after('legal_basis_ar');
        });
    }

    public function down(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->dropColumn(['legal_basis_ar', 'legal_basis_note_ar']);
        });
    }
};
