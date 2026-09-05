<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The committee's binding, tallied outcome for one agenda item. */
class Decision extends Model
{
    protected $fillable = [
        'meeting_request_id',
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
