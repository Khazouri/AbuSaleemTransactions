<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The committee's binding, tallied outcome for one agenda item. */
class Decision extends Model
{
    protected $fillable = [
        'meeting_request_id',
        'decision_number',
        'template_id',
        'outcome',
        'votes_approve_count',
        'votes_reject_count',
        'votes_defer_count',
        'votes_conditional_approval_count',
        'votes_legal_opinion_count',
        'votes_refer_other_body_count',
        'votes_no_jurisdiction_count',
        'votes_abstain_count',
        // Stage 63 — Art. 75 point 5's five-outcome appeal vocabulary; see
        // DecisionController::APPEAL_OUTCOMES.
        'votes_appeal_accept_count',
        'votes_appeal_partial_accept_count',
        'votes_appeal_reject_count',
        'votes_appeal_refer_count',
        'votes_appeal_redo_count',
        'comment',
        // Stage 74 — Art. 90's قرار/توصية/رأي, which the article forbids
        // using interchangeably; DecisionStructureRules::INSTRUMENTS.
        'instrument',
        // Stage 74 — Appendix 27's four parts (موضوع / وقائع / سند / منطوق),
        // which also complete Art. 89's six-element per-decision record and
        // free `comment` above to be its own الملاحظات اللازمة.
        'decision_subject',
        'decision_facts',
        'decision_basis',
        'decision_operative',
        // Stage 74 — Appendix 28's professional refusal reason;
        // DecisionReasoningRules::REFUSAL_REASON_CODES.
        'refusal_reason_code',
        // Stage 74 — Art. 34's five deferral fields, which is what makes
        // Appendix 29's ban on a bare "تأجيل للمراجعة" enforceable.
        'deferral_reason',
        'deferral_required_completion',
        'deferral_responsible_body',
        'deferral_required_document',
        'deferral_legal_period',
        // Stage 50 — [D] Art. 28's minutes-content list: which body a
        // referral (refer_other_body/no_jurisdiction, typically) was made
        // to, distinct from the free-text comment.
        'referral_authority',
        'decided_by_user_id',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function meetingRequest(): BelongsTo
    {
        return $this->belongsTo(MeetingRequest::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** Stage 35 — which reusable text, if any, the recorded comment started from. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
