<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Full read model for one request's Stage 15 workspace. */
class RequestDetailResource extends RequestResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            // Stage 90 — [G]'s «الأسباب», beside the description it used to
            // fold into, so the officer reading the file sees both.
            'reasons' => $this->reasons,
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            // Stage 51 — [A] §7's employee-facing visibility list.
            'documents_complete' => $this->documentsComplete(),
            // Stage 72 — [D] Appendix 57's document matrix for this request's
            // own type. Surfaced on the detail screen, not only at intake,
            // because the officer performing Art. 18's فحص اكتمال الملف at
            // requirements_check is the one who has to judge completeness
            // against it. Deliberately on the detail resource rather than the
            // shared RequestResource, so list payloads stay unchanged.
            'required_documents' => $this->requestType?->required_documents ?? [],
            // Stage 54 — [D] Art. 45's 6-question jurisdiction test, recorded
            // at requirements_check; null until someone has answered it.
            'jurisdiction_test' => $this->jurisdiction_test,
            // Stage 83 — [D] Appendix 16's classification, recorded when this
            // request was raised after an earlier closed file on the same
            // subject. Null for an ordinary first request.
            'prior_relation' => $this->prior_relation,
            'prior_request_id' => $this->prior_request_id,
            // Stage 68 — [D] Art. 21's pre-meeting legal review. Only the
            // latest round is surfaced here (it is the one that gates the
            // agenda); the full history lives behind
            // GET requests/{id}/legal-reviews, per Art. 21's requirement that
            // every recorded opinion stay readable in the file.
            'legal_review' => $this->whenLoaded(
                'latestLegalReview',
                fn () => $this->latestLegalReview
                    ? new RequestLegalReviewResource($this->latestLegalReview)
                    : null,
            ),
            'legal_reviews_count' => $this->whenCounted('legalReviews'),
            'committee_summary' => $this->whenLoaded('meetingRequests', function () {
                $agendaItem = $this->meetingRequests->sortByDesc('id')->first();

                if ($agendaItem === null) {
                    return null;
                }

                return [
                    'meeting_number' => $agendaItem->meeting?->meeting_number,
                    'meeting_date' => $agendaItem->meeting?->scheduled_at?->toDateString(),
                    'agenda_item_number' => $agendaItem->agenda_order,
                    'committee_result' => $agendaItem->decision?->outcome,
                    'decision_date' => $agendaItem->decision?->decided_at?->toDateString(),
                ];
            }),
            // Stage 18 — the authoritative approval chain, separate from the
            // broader timeline that also contains forwards and exceptions.
            'approvals' => ApprovalResource::collection($this->whenLoaded('approvals')),
            // Stage 80 — [D] Art. 100's six columns: التاريخ — الإجراء —
            // المسؤول — **الجهة** — الملاحظة — **المستند المرتبط**. The last two
            // are what this stage added; RequestTimelineCompiler explains why
            // the linked document is derived rather than stored on the log row.
            'timeline' => $this->art_100_timeline ?? [],
            // This is calculated for the signed-in actor by the controller;
            // the service remains the final authority when it executes it.
            'available_actions' => $this->available_actions ?? [],
            // Stage 16 — metadata lets the SPA require reasons and visually
            // distinguish exception commands from normal forward progress.
            'available_transitions' => $this->available_transitions ?? [],
            // Stage 75 — [D] Art. 37's closure record, and Appendix 47's
            // twelve-point audit answered when it was written. Both null until
            // the request is actually closed.
            'closure' => $this->closure === null ? null : [
                ...$this->closure,
                'closed_at' => $this->closed_at?->toIso8601String(),
                'closed_by' => $this->closedBy ? [
                    'id' => $this->closedBy->id,
                    'name' => $this->closedBy->name,
                ] : null,
            ],
            'closure_audit' => $this->closure_audit,
            // Appendix 48's refusal, computed once here so the screen's "why
            // this cannot be closed" and the endpoint's own 422 are the same
            // sentence. Deliberately on the detail resource only — list
            // payloads stay unchanged, per Stage 72's precedent.
            'closure_eligibility' => [
                'can_close' => $this->closure_refusal === null,
                'reason' => $this->closure_refusal,
            ],
            // Stage 76 — النموذج 17's execution card and its seven متابعة
            // التنفيذ answers, read-only here. Recording execution stays on the
            // outputs screen, which is agenda-item-scoped: unlike closure, code
            // 19 is only ever reachable through a decided agenda item.
            'execution' => $this->execution === null ? null : [
                ...$this->execution,
                'executed_at' => $this->executed_at?->toIso8601String(),
                'executed_by' => $this->executedBy ? [
                    'id' => $this->executedBy->id,
                    'name' => $this->executedBy->name,
                ] : null,
            ],
            'execution_checklist' => $this->execution_checklist,
            // Stage 77 — [D] Art. 94's إجراء إعادة معالجة, every round of it.
            // History rather than a latest-only block (unlike `legal_review`
            // above): Art. 98's own سجل القرارات المعادة من جهة الاعتماد is a
            // register of returns, and a formal return corrected and re-referred
            // can legitimately be followed by another one.
            'approval_returns' => ApprovalReturnResource::collection($this->whenLoaded('approvalReturns')),
            // Appendix 34's refusal plus the open round's id, computed by the
            // same service the two endpoints enforce with, so the screen's "why
            // not" and their 422 are the same sentence. Detail resource only —
            // list payloads stay unchanged, per Stage 72's precedent.
            'approval_return_eligibility' => [
                'can_record' => $this->approval_return_refusal === null,
                'reason' => $this->approval_return_refusal,
                'open_return_id' => $this->open_approval_return_id,
            ],
            // Stage 80 — [D] Art. 30's سجل الإحالات للاعتماد (Art. 98's
            // register 7), oldest first. Detail resource only — list payloads
            // stay unchanged, per Stage 72's precedent.
            'approval_referrals' => ApprovalReferralResource::collection($this->whenLoaded('approvalReferrals')),
            'approval_referral_eligibility' => [
                'can_record' => $this->approval_referral_refusal === null,
                'reason' => $this->approval_referral_refusal,
                'open_referral_id' => $this->open_approval_referral_id,
            ],
            // Stage 78 — [D] Appendix 63's four-gate matrix for this file, plus
            // Art. 105's hold. Every refusal here comes from the same service
            // the matching endpoint enforces with, so a gate the screen shows
            // as passed can never be one an endpoint refuses. Detail resource
            // only — list payloads stay unchanged, per Stage 72's precedent.
            'control_gates' => $this->control_gates,
            // Every round of Art. 105's إيقاف إجرائي, oldest first — a history
            // for the same reason approval_returns above is one.
            'suspensions' => RequestSuspensionResource::collection($this->whenLoaded('suspensions')),
            // Stage 79 — [D] Art. 101's register for this file: which of its
            // twelve moments the employee was actually told about, and when.
            // Detail resource only, per Stage 72's precedent — list payloads
            // stay unchanged.
            'employee_notices' => $this->employee_notices ?? [],
            // Stage 81 — [D] Appendix 71's ten measured segments for this
            // file. Detail resource only, per the same precedent: a list
            // payload has no room for ten durations per row, and computing
            // them per row is exactly the N+1 TimeCardCompiler exists to
            // avoid.
            'time_card' => $this->time_card ?? [],
        ];
    }
}
