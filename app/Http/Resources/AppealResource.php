<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One appeal, as the `appeals` screen sees it — Stage 58. */
class AppealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'appellant' => $this->whenLoaded('appellant', fn () => $this->appellant ? [
                'id' => $this->appellant->id,
                'name' => $this->appellant->name,
            ] : null),
            'original_request' => $this->whenLoaded('originalRequest', fn () => $this->originalRequest ? [
                'id' => $this->originalRequest->id,
                'reference_number' => $this->originalRequest->reference_number,
                'title' => $this->originalRequest->title,
            ] : null),
            'original_decision_id' => $this->original_decision_id,
            'original_decision_reference' => $this->original_decision_reference,
            'original_decision_date' => $this->original_decision_date?->toDateString(),
            // Stage 59 — [A] §9 step 1's real intake fields.
            'known_at' => $this->known_at?->toDateString(),
            'appeal_reasons' => $this->appeal_reasons,
            'final_request' => $this->final_request,
            'new_facts_declaration' => $this->new_facts_declaration,
            'attachments_count' => $this->whenCounted('attachments'),
            'status' => $this->whenLoaded('status', fn () => $this->status ? [
                'code' => $this->status->code,
                'name_ar' => $this->status->name_ar,
                'name_en' => $this->status->name_en,
                'color' => $this->status->color,
            ] : null),
            // Stage 60 — the formal-verification record, present once
            // AppealController::verify() has acted on this appeal.
            'formal_verification' => $this->formal_verification_checks === null ? null : [
                'checks' => $this->formal_verification_checks,
                'reason' => $this->formal_verification_reason,
                'verified_by' => $this->whenLoaded('formalVerifiedBy', fn () => $this->formalVerifiedBy ? [
                    'id' => $this->formalVerifiedBy->id,
                    'name' => $this->formalVerifiedBy->name,
                ] : null),
                'verified_at' => $this->formal_verified_at,
            ],
            // Stage 62 — Art. 77's jurisdiction test, present once
            // AppealController::recordJurisdictionTest() has acted.
            'jurisdiction_test' => $this->jurisdiction_test === null ? null : [
                'competent_body' => $this->jurisdiction_test['competent_body'] ?? null,
                'tested_by' => $this->whenLoaded('jurisdictionTestedBy', fn () => $this->jurisdictionTestedBy ? [
                    'id' => $this->jurisdictionTestedBy->id,
                    'name' => $this->jurisdictionTestedBy->name,
                ] : null),
                'tested_at' => $this->jurisdiction_tested_at,
            ],
            // Stage 62 — Art. 75 point 4's legal-review checklist, present
            // once AppealController::recordLegalReview() has acted.
            'legal_review' => $this->legal_review === null ? null : [
                'checks' => $this->legal_review,
                'reviewed_by' => $this->whenLoaded('legalReviewedBy', fn () => $this->legalReviewedBy ? [
                    'id' => $this->legalReviewedBy->id,
                    'name' => $this->legalReviewedBy->name,
                ] : null),
                'reviewed_at' => $this->legal_reviewed_at,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
