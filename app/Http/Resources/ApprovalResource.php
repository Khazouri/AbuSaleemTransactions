<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Stable approval-ledger payload shared by queues and transaction details. */
class ApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level' => $this->level,
            'action' => $this->action,
            'comment' => $this->comment,
            'role' => $this->role ? [
                'code' => $this->role->code,
                'name_ar' => $this->role->name_ar,
                'name_en' => $this->role->name_en,
            ] : null,
            'approved_by' => $this->approvedBy ? [
                'id' => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ] : null,
            // The private path never crosses the API boundary. The SPA fetches
            // this endpoint through its bearer-aware HTTP client.
            'signature_url' => $this->signature_path
                ? route('transactions.approvals.signature', [
                    'transaction' => $this->transaction_id,
                    'approval' => $this->id,
                ])
                : null,
            'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
