<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 88 — an intake that can be put down and picked up.
 *
 * A draft is saved form state, NOT a request. That is the whole reason these
 * are their own tables rather than a `status` value or a null `submitted_at`
 * on `requests`: the stage's own load-bearing rule is that a draft takes no
 * `intake_receipt_number`, writes no stage log, never hops to
 * `direct_manager_review`, and appears in no workflow queue, visibility scope
 * or register. Twenty-eight files query `requests` — every register,
 * RequestVisibility, DuplicatePolicy, ReportMetricsService,
 * MeetingReadinessService, DecisionEligibility, both console sweeps — so
 * putting a draft there means auditing all of them, or fitting a global scope
 * to the application's core entity where a single missed bypass surfaces a
 * half-filled form inside an approval queue or an official register. Here
 * every one of those four constraints is true by construction.
 *
 * `Request` is also in AuditLog::AUDITED_MODELS, so a draft living there would
 * write an audit row per autosave and bump ReportCacheObserver's KPI
 * generation counter each time. RequestDraft is deliberately left out of that
 * list — a half-filled form is not a business event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_drafts', function (Blueprint $table) {
            $table->id();

            // Cascades: a draft is personal working state, meaningless once
            // its author is gone — unlike `requests.created_by_user_id`, which
            // nulls out because a filed request outlives whoever filed it.
            $table->foreignId('created_by_user_id')
                ->constrained('users')->cascadeOnDelete();

            // The intake form's own fields, exactly as typed. Deliberately not
            // columns mirroring `requests`: a draft is incomplete by
            // definition, so every one of them would have to be nullable and
            // would then read as a weakened copy of the real table's
            // invariants. Nothing queries inside this, so a JSON column is
            // honest about what it is.
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['created_by_user_id', 'updated_at']);
        });

        Schema::create('request_draft_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('request_draft_id')
                ->constrained('request_drafts')->cascadeOnDelete();

            // The same shape `attachments` carries, because these rows are
            // promoted into it verbatim at submission — one fewer translation
            // between what the employee uploaded and what the request holds.
            $table->string('disk', 50);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('label')->nullable();

            // Which of the chosen type's [D] Appendix 57 rows this file
            // answers. Nullable here and required at intake: a draft may not
            // have chosen its request type yet, and the keys belong to one
            // type's matrix.
            $table->string('required_document_key')->nullable();

            $table->timestamps();

            $table->index(['request_draft_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_draft_attachments');
        Schema::dropIfExists('request_drafts');
    }
};
