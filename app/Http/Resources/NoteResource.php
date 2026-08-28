<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Note payload deliberately excludes any user fields not needed by the conversation. */
class NoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'body' => $this->body,
            'is_internal' => $this->is_internal,
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
