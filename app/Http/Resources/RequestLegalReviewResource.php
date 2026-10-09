<?php

namespace App\Http\Resources;

use App\Models\RequestLegalReview;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stage 68 — one recorded round of [D] Art. 21's pre-meeting legal review:
 * Appendix 22's بطاقة السند القانوني plus النموذج 06's verdict.
 *
 * @mixin RequestLegalReview
 */
class RequestLegalReviewResource extends JsonResource
{
    public function toArray(HttpRequest $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,

            // Appendix 22 — بطاقة السند القانوني
            'primary_legislation' => $this->primary_legislation,
            'article_reference' => $this->article_reference,
            'supplementary_decision' => $this->supplementary_decision,
            'committee_mandate' => $this->committee_mandate,
            'approving_body' => $this->approving_body,
            'requires_central_approval' => $this->requires_central_approval,
            'legal_deadline' => $this->legal_deadline,
            'prohibiting_conditions' => $this->prohibiting_conditions,

            // النموذج 06 / Art. 21
            'verdict' => $this->verdict,
            'legal_note' => $this->legal_note,
            // Whether THIS review would let the request onto an agenda. The
            // agenda gate reads the latest review only, so on a historic row
            // this is a record of what that round concluded, not a live
            // statement about the request.
            'permits_agenda' => $this->permitsAgenda(),

            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ]),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
