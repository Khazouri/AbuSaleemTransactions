<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the request type's [D] Appendix 57 recommended documents a
 * submitter's own file provides.
 *
 * The value is IntakeGateService::documentKey()'s slug — the same identifier
 * Stage 78's intake gate already stores its per-document answers under, so the
 * officer's completeness check and the employee's uploads name the same rows
 * rather than two parallel vocabularies for one matrix.
 *
 * Nullable, and deliberately so: rows written before this migration have no
 * honest answer, and a file uploaded later in the cycle by the committee
 * (AttachmentController's own path) is not answering the intake matrix at all.
 * The literal string `other` is a real answer, not an absence — it is what a
 * submitter picks for material no matrix row names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('required_document_key')->nullable()->after('file_section');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('required_document_key');
        });
    }
};
