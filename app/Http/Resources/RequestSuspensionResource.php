<?php

namespace App\Http\Resources;

use App\Models\RequestSuspension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stage 78 — one round of [D] Art. 105's إيقاف إجرائي.
 *
 * @mixin RequestSuspension
 */
class RequestSuspensionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ground' => $this->ground,
            'detail' => $this->detail,
            'suspended_from_status' => $this->suspendedFromStatus ? [
                'code' => $this->suspendedFromStatus->code,
                'name_ar' => $this->suspendedFromStatus->name_ar,
                'name_en' => $this->suspendedFromStatus->name_en,
            ] : null,
            'suspended_by' => $this->suspendedBy ? [
                'id' => $this->suspendedBy->id,
                'name' => $this->suspendedBy->name,
            ] : null,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            // Null until the legal review Art. 105 mandates has actually
            // reported back — which is exactly what the lift gate reads.
            'resolution_action' => $this->resolution_action,
            'resolution_note' => $this->resolution_note,
            'resolved_by' => $this->resolvedBy ? [
                'id' => $this->resolvedBy->id,
                'name' => $this->resolvedBy->name,
            ] : null,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
