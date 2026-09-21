<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A staff-affairs request travelling through the workflow defined by
 * `workflow_stages` — twelve stages since Stage 57 cut two of the original
 * fourteen; read WorkflowStageSeeder rather than trusting a number here.
 *
 * `status` answers how the request is doing; `currentStage` answers where it
 * is. Their histories are separate because exception handling can change one
 * without necessarily changing the other.
 */
class Request extends Model
{
    use HasFactory;

    /**
     * Stage 95 — a request that states no صاحب العلاقة is about whoever
     * filed it, which is what every request created before this stage meant
     * and what an ordinary self-filed intake still means.
     *
     * On every save, not only at creation: several fixtures assign the
     * creator after the row exists, and "about whoever filed it" is true at
     * any moment, not just the first one.
     *
     * Defaulted here rather than coalesced at each read site so the column
     * can be queried directly: the visibility scope, Appendix 16's duplicate
     * search and the tracking scope are all SQL, and a COALESCE in each of
     * them would buy nothing. It is also why a fixture that builds a Request
     * without naming a subject stays truthful with no change at all.
     */
    protected static function booted(): void
    {
        static::saving(function (self $requestRecord): void {
            // Guarded rather than a bare ??=: a partially-selected model
            // (the restricted eager loads several registers use) carries
            // neither column, and assigning null there would mark the
            // attribute dirty and wipe a real subject on the next save.
            if ($requestRecord->subject_user_id === null && $requestRecord->created_by_user_id !== null) {
                $requestRecord->subject_user_id = $requestRecord->created_by_user_id;
            }
        });
    }

    protected $fillable = [
        'reference_number',
        'intake_receipt_number',
        'title',
        'description',
        // Stage 90 — [G]'s own «الأسباب» input, which folded into the
        // free-text `description` until this stage gave it a column.
        'reasons',
        'department_id',
        'request_type_id',
        'status_id',
        'current_stage_id',
        'created_by_user_id',
        // Stage 95 — صاحب العلاقة: who the request is ABOUT, as distinct
        // from who filed it. Nullable, and defaulted to the creator by the
        // booted() hook below, so a self-filed request — every request
        // before this stage, and most after it — carries the two as one
        // person.
        'subject_user_id',
        'submitted_at',
        'due_date',
        'decision_grade',
        'has_financial_impact',
        'jurisdiction_test',
        // Stage 84 — who answered Art. 45's test and when. Written only by
        // RequestController::recordJurisdictionTest(), cleared by reopen().
        'jurisdiction_tested_by_user_id',
        'jurisdiction_tested_at',
        // Stage 83 — [D] Appendix 16's classification of a request raised
        // after an earlier file on the same subject closed. Written once, at
        // intake; only its two "genuinely new" values ever reach the column.
        'prior_relation',
        'prior_request_id',
        // Stage 78 — [D] Appendix 63's بوابة 1 (قبل القيد). Written only by
        // RequestController::recordIntakeGate() and cleared by reopen(),
        // fillable for the same reason the closure/execution cards below are.
        'intake_gate',
        'intake_gate_checked_by_user_id',
        'intake_gate_checked_at',
        'employment_file',
        'employment_file_prepared_by_user_id',
        'employment_file_prepared_at',
        // Stage 75 — [D] Art. 37's closure record. Written only by
        // RequestClosureService (and cleared by RequestController::reopen),
        // but fillable so both write it through one update() call.
        'closure',
        'closure_audit',
        'closed_by_user_id',
        'closed_at',
        // Stage 76 — النموذج 17's execution card and its متابعة التنفيذ
        // checks. Written only by MeetingOutputService::markExecuted() (and
        // cleared by RequestController::reopen), fillable for the same reason
        // the closure columns above are.
        'execution',
        'execution_checklist',
        'executed_by_user_id',
        'executed_at',
        // Stage 78 — [D] Art. 103's قائمة فحص سلامة القرار, recorded before
        // the result may be referred to execution.
        'execution_soundness',
        'execution_soundness_checked_by_user_id',
        'execution_soundness_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'due_date' => 'date',
            'overdue_at' => 'datetime',
            // Stage 71 — Appendix 38's delay ladder. Deliberately NOT
            // fillable: only the nightly sweep writes it (same discipline as
            // `overdue_at`, which a mass-assigned fixture must also set by
            // direct property assignment).
            'escalation_notified_at' => 'datetime',
            'has_financial_impact' => 'boolean',
            // Stage 54 — [D] Art. 45's 6-question jurisdiction test, recorded
            // once at requirements_check and gating that stage's approve/
            // declare_no_jurisdiction/reject_formally outcomes.
            'jurisdiction_test' => 'array',
            'jurisdiction_tested_at' => 'datetime',
            'intake_gate' => 'array',
            'intake_gate_checked_at' => 'datetime',
            'employment_file' => 'array',
            'employment_file_prepared_at' => 'datetime',
            'execution_soundness' => 'array',
            'execution_soundness_checked_at' => 'datetime',
            // Stage 75 — Art. 37's eight closure fields and Appendix 47's
            // twelve-point pre-closure audit.
            'closure' => 'array',
            'closure_audit' => 'array',
            'closed_at' => 'datetime',
            // Stage 76 — Appendix 70's execution proof and النموذج 17's
            // seven-point tracking checklist.
            'execution' => 'array',
            'execution_checklist' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(RequestStatus::class);
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Stage 95 — [D] Appendix 6's صاحب العلاقة.
     *
     * Every party the matrix names is named RELATIVE to this person: row 2's
     * الرئيس المباشر is the subject's own manager, row 3's ملف وظيفي is the
     * subject's file, and Art. 101's «يتم إشعار الموظف» is the subject being
     * told. Never null in practice — booted() defaults it to the creator —
     * but nullable at the database layer for the same reason
     * created_by_user_id is: a removed account must not take the request
     * with it.
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** Stage 75 — النموذج 18's مسؤول الإقفال. */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** Stage 76 — النموذج 17's executing officer. */
    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    /**
     * Stage 84 — who answered [D] Art. 45's jurisdiction test. The appeals
     * side has carried this since Stage 62; the request side did not until
     * now, which left the two halves of gate 1 reading differently.
     */
    public function jurisdictionTestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'jurisdiction_tested_by_user_id');
    }

    /** Stage 78 — who answered [D] Appendix 63's بوابة 1 for this file. */
    public function intakeGateCheckedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'intake_gate_checked_by_user_id');
    }

    /** Stage 98 — who assembled [D] Appendix 6 row 3's الملف الوظيفي. */
    public function employmentFilePreparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employment_file_prepared_by_user_id');
    }

    /** Stage 78 — who certified Art. 103's قائمة فحص سلامة القرار. */
    public function executionSoundnessCheckedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'execution_soundness_checked_by_user_id');
    }

    public function stageLogs(): HasMany
    {
        return $this->hasMany(RequestStageLog::class);
    }

    /**
     * Stage 52 — the log row that put this request at its current stage. Every
     * transition that changes current_stage_id writes a matching stage-log
     * row in the same DB transaction (WorkflowService/CommitteeStatusService),
     * so the latest row's acted_at is definitionally the current-stage entry
     * time — no separate "stage entered at" column needed.
     */
    public function latestStageLog(): HasOne
    {
        return $this->hasOne(RequestStageLog::class)->latestOfMany('acted_at');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(RequestStatusHistory::class);
    }

    /**
     * Stage 68 — every round of [D] Art. 21's pre-meeting legal review, oldest
     * first. Kept as history rather than a single overwritten record because
     * Art. 21 requires the opinion to stay readable in the file for the
     * committee's own study, and [E] stage 08 routes a blocking verdict back
     * for correction and then re-review.
     */
    public function legalReviews(): HasMany
    {
        return $this->hasMany(RequestLegalReview::class);
    }

    /**
     * The only review that gates anything: the agenda-insertion check in
     * MeetingController::addAgendaItem() and MeetingReadinessService both read
     * this one, so a re-review always supersedes whatever came before it.
     */
    public function latestLegalReview(): HasOne
    {
        return $this->hasOne(RequestLegalReview::class)->latestOfMany();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * Stage 77 — every round of [D] Art. 94's إجراء إعادة معالجة, oldest first.
     *
     * History rather than a single record because Art. 94 describes an action a
     * file can go through more than once, and Art. 98's سجل القرارات المعادة
     * من جهة الاعتماد is a register of them. Only the newest unresolved one
     * gates anything — see openApprovalReturn().
     */
    public function approvalReturns(): HasMany
    {
        return $this->hasMany(ApprovalReturn::class);
    }

    /**
     * The unresolved return this request is sitting on, if any.
     *
     * Read by the approve gate in RequestController/ApprovalController and by
     * Appendix 48's seventh closure condition — while this is non-null the
     * approving body's own remark has not been answered yet.
     */
    public function openApprovalReturn(): HasOne
    {
        return $this->hasOne(ApprovalReturn::class)
            ->whereNull('resolved_at')
            ->latestOfMany();
    }

    /**
     * Stage 80 — [D] Art. 30's referrals of a committee result to an approving
     * body, newest last. Art. 98's register 7 (سجل الإحالات للاعتماد).
     *
     * A history for the same reason approvalReturns() is one: the البلدية →
     * وزارة path is two referrals by itself, a corrected formal return is
     * re-referred, and the article names an outward moment and an inward one
     * that nobody can answer at the same time.
     */
    public function approvalReferrals(): HasMany
    {
        return $this->hasMany(ApprovalReferral::class);
    }

    /**
     * Stage 78 — [D] Art. 105's procedural suspensions, newest last.
     *
     * A history for the same reason approvalReturns() is one: the article
     * names a cause and a consequence recorded at two different moments, and
     * a file can be suspended more than once over its life.
     */
    public function suspensions(): HasMany
    {
        return $this->hasMany(RequestSuspension::class);
    }

    /**
     * The unresolved suspension this request is being held by, if any.
     *
     * Read by the approve gate in RequestController/ApprovalController and by
     * the detail screen's preview filter — while this is non-null, Art. 105
     * forbids arranging any new effect on the matter.
     */
    public function openSuspension(): HasOne
    {
        return $this->hasOne(RequestSuspension::class)
            ->whereNull('resolved_at')
            ->latestOfMany();
    }

    /**
     * Stage 83 — [D] Appendix 30's rounds of conflict handling, oldest first.
     *
     * A history rather than a flag: the appendix names six steps performed at
     * two moments — تحديد المستندات المتعارضة, then the external determination
     * that resolves it — and one file can carry more than one conflict.
     */
    public function documentConflicts(): HasMany
    {
        return $this->hasMany(RequestDocumentConflict::class);
    }

    /** Stage 83 — [D] Appendix 60's الحالات الخاصة raised against this file. */
    public function specialCases(): HasMany
    {
        return $this->hasMany(RequestSpecialCase::class);
    }

    /** Stage 83 — [D] Appendix 53's مذكرات التصحيح, approved and pending. */
    public function corrections(): HasMany
    {
        return $this->hasMany(RequestCorrection::class);
    }

    /** Stage 83 — [D] Appendices 68/69's withdrawal requests, oldest first. */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(RequestWithdrawal::class);
    }

    /**
     * Stage 83 — [D] Appendix 16: the closed file this one was raised after,
     * when the submitter classified the new request as arising from a new fact
     * or as completing a previous decision. Null for an ordinary first request.
     */
    public function priorRequest(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'prior_request_id');
    }

    /** Stage 44 — every agenda slot this request has ridden, across meetings. */
    public function meetingRequests(): HasMany
    {
        return $this->hasMany(MeetingRequest::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * Missing legacy grades take the conservative route through ministry.
     * A null threshold explicitly means that this type never needs ministry.
     */
    public function requiresMinistryApproval(): bool
    {
        $threshold = $this->requestType?->decision_grade_threshold;

        return $threshold !== null
            && ($this->decision_grade === null || $this->decision_grade >= $threshold);
    }

    /** A breach becomes official only when the scheduled sweep records it. */
    public function isOverdue(): bool
    {
        return $this->overdue_at !== null;
    }

    /**
     * Stage 70 — the number to quote at the reader, whichever half of the
     * lifecycle the request is in.
     *
     * [D] Art. 20 grants the committee's رقم إشاري only after completeness is
     * established, so a request in the intake/routing half genuinely has no
     * reference number and the intake receipt is the only handle that exists.
     * Every notification reads this rather than `reference_number` directly,
     * which each of them used to cast to string — rendering an empty gap
     * mid-sentence for exactly those early moves.
     */
    public function trackingNumber(): ?string
    {
        return $this->reference_number ?? $this->intake_receipt_number;
    }

    /**
     * Stage 51 — [A] §7's "المستندات الناقصة" flag, derived from status
     * rather than a separate structured checklist: `incomplete` (Stage 16's
     * return_missing_docs) and `completion_required` (Stage 29's committee
     * sub-state) are the two statuses that mean "the file is not ready".
     */
    public function documentsComplete(): bool
    {
        return ! in_array($this->status?->code, ['incomplete', 'completion_required'], true);
    }

    /**
     * Stage 52 — a non-binding, per-stage soft-SLA indicator, distinct from
     * isOverdue()'s hard per-type deadline. Null when the current stage has
     * no sourced target (see WorkflowStageSeeder) — the absence of a target
     * is never rendered as "on target".
     *
     * Stage 71 — the bucket boundaries are now [D] **Appendix 38**'s own,
     * replacing the ratio scheme (≤1.0 / ≤1.5 / ≤2.0) Stage 52 invented while
     * the appendix was unavailable and flagged for outright replacement. That
     * is a change of meaning, not of labels: Appendix 38's **أصفر is "قرب
     * تجاوز المدة"** — approaching the target, i.e. BEFORE it is exceeded —
     * where the old yellow began at 1.0–1.5× the target, already over it.
     *
     *   elapsed <  target_days_min         green    — ضمن المدة
     *   min <= elapsed <= target_days_max  yellow   — قرب تجاوز المدة
     *   elapsed >  target_days_max         red      — متأخرة
     *   red AND legallyTimeBound()         critical — حرج
     *
     * No invented constant survives: every boundary is one of the stage's own
     * seeded Appendix 37 figures, and `target_days_min` — display-only until
     * now — is what marks the tail of the allowance. Most stages seed
     * min == max, so their warning window is the final day.
     *
     * **حرج is a qualitative condition in the source, not a further time
     * bucket**: "إذا ارتبط التأخير بمدة قانونية أو حق وظيفي" — so it is
     * layered on top of red rather than measured past it.
     */
    public function stageTimeliness(): ?array
    {
        $stage = $this->currentStage;

        if ($stage === null || $stage->target_days_max === null) {
            return null;
        }

        $enteredAt = $this->latestStageLog?->acted_at;

        if ($enteredAt === null) {
            return null;
        }

        $elapsedDays = max(0, $enteredAt->copy()->startOfDay()->diffInDays(now()->startOfDay()));
        // A stage may seed a max without a min; treat the max as both.
        $warnFrom = $stage->target_days_min ?? $stage->target_days_max;

        $level = match (true) {
            $elapsedDays > $stage->target_days_max => $this->legallyTimeBound() ? 'critical' : 'red',
            $elapsedDays >= $warnFrom => 'yellow',
            default => 'green',
        };

        return [
            'level' => $level,
            'elapsed_days' => $elapsedDays,
            'target_days_min' => $stage->target_days_min,
            'target_days_max' => $stage->target_days_max,
            // Stage 89 — the same measurement said as a date rather than as a
            // RAG level, because that is what «الوقت المتوقع للمرحلة» asks of
            // it: an employee is owed "expected to reach the next step by
            // ⟨date⟩", not a colour and an escalation rung.
            //
            // The outer bound, not the warning threshold: target_days_max is
            // when the step is expected to be *done*, while target_days_min
            // only marks where the amber window opens. Calendar days, matching
            // the arithmetic above — the sources say أيام عمل, but Stage 17
            // established plain calendar days for the hard SLA and two
            // deadline mechanisms disagreeing about what a day is would be
            // worse than one that is uniformly approximate.
            //
            // Derived here rather than in the browser so there is one
            // derivation: recomputing it client-side from `elapsed_days` would
            // re-derive a date from an already-rounded difference, across
            // whatever timezone the reader happens to be in.
            'expected_by' => $enteredAt->copy()->startOfDay()->addDays($stage->target_days_max)->toDateString(),
            // Stage 71 — how far up Appendix 38's ladder this request has
            // already been escalated at its current stage; null once a
            // transition restarts the clock (see escalatedLevel()).
            'escalation' => $this->escalatedLevel() === null ? null : [
                'level' => $this->escalatedLevel(),
                'notified_at' => $this->escalation_notified_at,
            ],
        ];
    }

    /**
     * Stage 71 — the first limb of Appendix 38's حرج condition ("إذا ارتبط
     * التأخير بمدة قانونية أو حق وظيفي").
     *
     * Answered from Stage 68's `request_legal_reviews.legal_deadline`, which
     * is Appendix 22's own "هل توجد مدة قانونية؟" field: the legal officer
     * fills it in when a statutory deadline exists and leaves it blank when
     * one does not, so a non-empty answer IS the recorded fact. Free text, so
     * "non-empty" is the whole test — worth structuring if a later stage needs
     * to reason about the deadline itself rather than its existence.
     *
     * The condition's second limb (حق وظيفي) has no field anywhere in this
     * schema. It is deliberately left unrepresented rather than proxied off
     * `has_financial_impact` or a request type, which would report a guess as
     * something a human recorded.
     */
    public function legallyTimeBound(): bool
    {
        return trim((string) $this->latestLegalReview?->legal_deadline) !== '';
    }

    /**
     * Stage 71 — the escalation level already announced for the CURRENT
     * stage, or null if none is.
     *
     * A recorded level goes stale the moment the file moves: escalation is
     * per-stage, and every transition (a self-loop included) writes a stage
     * log whose `acted_at` restarts the clock stageTimeliness() measures from.
     * Comparing against that same timestamp resets the ladder with no column
     * of its own and no hook in WorkflowService.
     */
    public function escalatedLevel(): ?string
    {
        if ($this->escalation_level === null || $this->escalation_notified_at === null) {
            return null;
        }

        $enteredAt = $this->latestStageLog?->acted_at;

        if ($enteredAt !== null && $this->escalation_notified_at->lt($enteredAt)) {
            return null;
        }

        return $this->escalation_level;
    }
}
