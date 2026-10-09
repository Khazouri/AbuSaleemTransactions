<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a Department for the admin screen.
 *
 * Sent as a FLAT list with parent_id rather than pre-nested children. The SPA
 * assembles the tree itself, which means the same payload serves both the tree
 * view and the "parent department" dropdown without a second request.
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'code' => $this->code,
            'parent_id' => $this->parent_id,
            // The head shown by the hierarchy view — a label only; approvals
            // follow each employee's own manager_id, never this.
            'manager_user_id' => $this->manager_user_id,
            'is_active' => $this->is_active,

            // Counts come from withCount() in the controller. whenCounted()
            // omits them if that wasn't used, so the resource never triggers a
            // query of its own.
            //
            // The UI needs these to explain why a department can't be deleted:
            // one that still has sub-departments or staff must be emptied first.
            'users_count' => $this->whenCounted('users'),
            'children_count' => $this->whenCounted('children'),
        ];
    }
}
