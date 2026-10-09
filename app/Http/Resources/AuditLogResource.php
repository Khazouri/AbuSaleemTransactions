<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the Stage 22 audit viewer.
 *
 * `model` is the registry key rather than the PHP class name: the SPA needs a
 * stable token to label and link by, and leaking internal namespaces into an
 * API payload buys nothing.
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'model' => AuditLog::modelKey($this->auditable_type),
            'record_id' => $this->auditable_id,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            // The union of both sides, so the viewer can render a diff table
            // without the client having to reconcile the two maps itself.
            'changed_keys' => array_values(array_unique(array_merge(
                array_keys($this->old_values ?? []),
                array_keys($this->new_values ?? []),
            ))),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
