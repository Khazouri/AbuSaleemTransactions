<?php

namespace App\Http\Resources;

use App\Models\ApprovalReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stage 77 — one round of [D] Art. 94's إجراء إعادة معالجة.
 *
 * @mixin ApprovalReturn
 */
class ApprovalReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_kind' => $this->return_kind,
            'return_reason_code' => $this->return_reason_code,
            'return_note' => $this->return_note,
            'letter_number' => $this->letter_number,
            'received_at' => $this->received_at?->toDateString(),
            'returned_from_stage' => $this->returnedFromStage ? [
                'code' => $this->returnedFromStage->code,
                'name_ar' => $this->returnedFromStage->name_ar,
                'name_en' => $this->returnedFromStage->name_en,
            ] : null,
            'recorded_by' => $this->recordedBy ? [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ] : null,
            'recorded_at' => $this->created_at?->toIso8601String(),
            // Art. 94's second half — null until the re-processing action has
            // actually been taken, which is also what Appendix 48's seventh
            // closure condition reads.
            'resolution_action' => $this->resolution_action,
            'resolution_target_stage' => $this->resolutionTargetStage ? [
                'code' => $this->resolutionTargetStage->code,
                'name_ar' => $this->resolutionTargetStage->name_ar,
                'name_en' => $this->resolutionTargetStage->name_en,
            ] : null,
            'resolved_by' => $this->resolvedBy ? [
                'id' => $this->resolvedBy->id,
                'name' => $this->resolvedBy->name,
            ] : null,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
