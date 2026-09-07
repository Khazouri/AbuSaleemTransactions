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
            'timeline' => $this->whenLoaded('stageLogs', function () {
                return $this->stageLogs->map(fn ($log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'comment' => $log->comment,
                    'from_stage' => $log->fromStage ? [
                        'order_no' => $log->fromStage->order_no,
                        'code' => $log->fromStage->code,
                        'name_ar' => $log->fromStage->name_ar,
                        'name_en' => $log->fromStage->name_en,
                    ] : null,
                    'to_stage' => $log->toStage ? [
                        'order_no' => $log->toStage->order_no,
                        'code' => $log->toStage->code,
                        'name_ar' => $log->toStage->name_ar,
                        'name_en' => $log->toStage->name_en,
                    ] : null,
                    'acted_by' => $log->actedBy ? [
                        'id' => $log->actedBy->id,
                        'name' => $log->actedBy->name,
                    ] : null,
                    'acted_at' => $log->acted_at?->toIso8601String(),
                ])->values();
            }),
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
        ];
    }
}
