<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommitteeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description' => $this->description,
            'is_active' => $this->is_active,

            // Counts explain why a delete might be blocked (meetings held),
            // same reasoning as DepartmentResource's users_count/children_count.
            'members_count' => $this->whenCounted('members'),
            'meetings_count' => $this->whenCounted('meetings'),

            'members' => CommitteeMemberResource::collection($this->whenLoaded('members')),
        ];
    }
}
