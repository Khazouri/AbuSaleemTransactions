<?php

namespace App\Http\Resources;

use App\Models\MeetingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The committee's binding, tallied outcome for an agenda item. */
class DecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Stage 70 — Art. 89's رقم القرار (Appendix 15's PM-DEC series).
            'decision_number' => $this->decision_number,
            'outcome' => $this->outcome,
            'votes_approve_count' => $this->votes_approve_count,
            'votes_reject_count' => $this->votes_reject_count,
            'votes_defer_count' => $this->votes_defer_count,
            // Stage 35 — three richer outcomes alongside the original three.
            'votes_conditional_approval_count' => $this->votes_conditional_approval_count,
            'votes_legal_opinion_count' => $this->votes_legal_opinion_count,
            'votes_refer_other_body_count' => $this->votes_refer_other_body_count,
            // Stage 49 — a seventh outcome: the matter is outside the
            // committee's jurisdiction entirely, distinct from asking another
            // body for input (refer_other_body above).
            'votes_no_jurisdiction_count' => $this->votes_no_jurisdiction_count,
            // Stage 63 — Art. 75 point 5's five-outcome appeal vocabulary,
            // recorded by DecisionController::recordAppealDecision instead of
            // record() — always zero for an employee_request decision.
            'votes_appeal_accept_count' => $this->votes_appeal_accept_count,
            'votes_appeal_partial_accept_count' => $this->votes_appeal_partial_accept_count,
            'votes_appeal_reject_count' => $this->votes_appeal_reject_count,
            'votes_appeal_refer_count' => $this->votes_appeal_refer_count,
            'votes_appeal_redo_count' => $this->votes_appeal_redo_count,
            // Stage 41 — counted like any other vote, but never a plurality
            // leader: DecisionController::record never lets it drive a
            // workflow transition, so it stays outside the outcome list.
            'votes_abstain_count' => $this->votes_abstain_count,
            // Art. 89's الملاحظات اللازمة — since Stage 74 gave the four
            // drafting parts columns of their own, this is genuinely notes
            // again rather than the place all of them were crammed.
            'comment' => $this->comment,
            // Stage 74 — Art. 90's قرار/توصية/رأي, which the article forbids
            // using interchangeably.
            'instrument' => $this->instrument,
            // Stage 74 — Appendix 27's four parts, which together with the
            // decision number, the request number and the outcome are Art.
            // 89's six per-decision elements.
            'decision_subject' => $this->decision_subject,
            'decision_facts' => $this->decision_facts,
            'decision_basis' => $this->decision_basis,
            'decision_operative' => $this->decision_operative,
            // Stage 74 — Appendix 28's professional refusal reason; null
            // except on the outcomes Art. 91 requires reasoning for.
            'refusal_reason_code' => $this->refusal_reason_code,
            // Stage 74 — Art. 34's five deferral fields; null except on a
            // `defer`, where the first four are mandatory.
            'deferral_reason' => $this->deferral_reason,
            'deferral_required_completion' => $this->deferral_required_completion,
            'deferral_responsible_body' => $this->deferral_responsible_body,
            'deferral_required_document' => $this->deferral_required_document,
            'deferral_legal_period' => $this->deferral_legal_period,
            // Stage 50 — [D] Art. 28's minutes-content list: which body a
            // referral was made to, distinct from the free-text comment.
            'referral_authority' => $this->referral_authority,
            'decided_at' => $this->decided_at,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy ? [
                'id' => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ] : null),
            'template' => $this->whenLoaded('template', fn () => $this->template ? [
                'id' => $this->template->id,
                'code' => $this->template->code,
                'name_ar' => $this->template->name_ar,
                'name_en' => $this->template->name_en,
            ] : null),
            // Stage 25 — the register lists decisions away from the meeting
            // that produced them, so it needs enough context to link back.
            // Loaded only there: the in-meeting UI already knows which meeting
            // and request it is looking at, and omitting the key keeps that
            // payload the shape Stage 21 built.
            'context' => $this->whenLoaded(
                'meetingRequest',
                fn () => $this->contextFor($this->meetingRequest),
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    private function contextFor(?MeetingRequest $agendaItem): ?array
    {
        if ($agendaItem === null) {
            return null;
        }

        $meeting = $agendaItem->relationLoaded('meeting') ? $agendaItem->meeting : null;
        $requestRecord = $agendaItem->relationLoaded('request') ? $agendaItem->request : null;
        $appeal = $agendaItem->relationLoaded('appeal') ? $agendaItem->appeal : null;
        $committee = $meeting?->relationLoaded('committee') ? $meeting->committee : null;

        return [
            'agenda_item_id' => $agendaItem->id,
            'request' => $requestRecord === null ? null : [
                'id' => $requestRecord->id,
                'reference_number' => $requestRecord->reference_number,
                'title' => $requestRecord->title,
            ],
            // Stage 63 — the appeal riding an `appeal` item, mirroring the
            // `request` block above.
            'appeal' => $appeal === null ? null : [
                'id' => $appeal->id,
                'appellant' => $appeal->relationLoaded('appellant') && $appeal->appellant ? [
                    'id' => $appeal->appellant->id,
                    'name' => $appeal->appellant->name,
                ] : null,
                'original_request' => $appeal->relationLoaded('originalRequest') && $appeal->originalRequest ? [
                    'id' => $appeal->originalRequest->id,
                    'reference_number' => $appeal->originalRequest->reference_number,
                    'title' => $appeal->originalRequest->title,
                ] : null,
            ],
            'meeting' => $meeting === null ? null : [
                'id' => $meeting->id,
                'title' => $meeting->title,
                'scheduled_at' => $meeting->scheduled_at,
            ],
            'committee' => $committee === null ? null : [
                'id' => $committee->id,
                'name_ar' => $committee->name_ar,
                'name_en' => $committee->name_en,
            ],
        ];
    }
}
