<?php

namespace App\Http\Resources;

use App\Models\ApprovalReferral;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stage 80 — one entry in [D] Art. 30's سجل الإحالات للاعتماد, both halves.
 *
 * @mixin ApprovalReferral
 */
class ApprovalReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Art. 30's outward three.
            'referred_at' => $this->referred_at?->toDateString(),
            'letter_number' => $this->letter_number,
            'referred_to_body' => $this->referred_to_body,
            'referred_from_stage' => $this->referredFromStage ? [
                'code' => $this->referredFromStage->code,
                'name_ar' => $this->referredFromStage->name_ar,
                'name_en' => $this->referredFromStage->name_en,
            ] : null,
            'recorded_by' => $this->recordedBy ? [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ] : null,
            'recorded_at' => $this->created_at?->toIso8601String(),
            // Art. 30's inward three — null until the approving body answered.
            'result_outcome' => $this->result_outcome,
            'result_received_at' => $this->result_received_at?->toDateString(),
            'approval_decision_number' => $this->approval_decision_number,
            'result_note' => $this->result_note,
            'result_recorded_by' => $this->resultRecordedBy ? [
                'id' => $this->resultRecordedBy->id,
                'name' => $this->resultRecordedBy->name,
            ] : null,
            'result_recorded_at' => $this->result_recorded_at?->toIso8601String(),
        ];
    }
}
