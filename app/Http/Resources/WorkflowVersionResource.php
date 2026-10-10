<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Workflow designer — slice 2. One version, shaped for the canvas: its
 * stages and every rule between them, with the role, status and type a rule
 * names resolved to labels, so the SPA draws it without a second lookup.
 */
class WorkflowVersionResource extends JsonResource
{
    /** The eager loads this resource reads; callers pass it to load(). */
    public const WITH = [
        'workflow:id,code,name_ar,name_en',
        'stages.responsibleRole:id,code,name_ar,name_en',
        'transitions.requiredRole:id,code,name_ar,name_en',
        'transitions.setStatus:id,code,name_ar,name_en',
        'transitions.requiredStatus:id,code,name_ar,name_en',
        'transitions.requestType:id,code,name_ar,name_en',
    ];

    public function toArray(Request $request): array
    {
        $label = fn ($model) => $model ? ['code' => $model->code, 'name_ar' => $model->name_ar, 'name_en' => $model->name_en] : null;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'workflow' => ['id' => $this->workflow->id] + $label($this->workflow),
            'stages' => $this->stages->sortBy('order_no')->values()->map(fn ($stage) => [
                'id' => $stage->id,
                'code' => $stage->code,
                'order_no' => $stage->order_no,
                'name_ar' => $stage->name_ar,
                'name_en' => $stage->name_en,
                'target_days_min' => $stage->target_days_min,
                'target_days_max' => $stage->target_days_max,
                'responsible_role' => $label($stage->responsibleRole),
            ]),
            'transitions' => $this->transitions->sortBy([['from_stage_id', 'asc'], ['order_no', 'asc']])->values()->map(fn ($rule) => [
                'id' => $rule->id,
                'from_stage_id' => $rule->from_stage_id,
                'to_stage_id' => $rule->to_stage_id,
                'action' => $rule->action,
                'is_exception' => $rule->is_exception,
                'requires_comment' => $rule->requires_comment,
                'requires_submitter_manager' => $rule->requires_submitter_manager,
                'requires_creator' => $rule->requires_creator,
                'required_role' => $label($rule->requiredRole),
                'set_status' => $label($rule->setStatus),
                'required_status' => $label($rule->requiredStatus),
                'request_type' => $label($rule->requestType),
            ]),
        ];
    }
}
