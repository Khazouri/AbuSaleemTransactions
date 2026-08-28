<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact request payload for the Stage 11 searchable list. */
class RequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference_number' => $this->reference_number,
            'title' => $this->title,
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name_ar' => $this->department->name_ar,
                'name_en' => $this->department->name_en,
                'code' => $this->department->code,
            ] : null,
            'request_type' => $this->requestType ? [
                'id' => $this->requestType->id,
                'code' => $this->requestType->code,
                'name_ar' => $this->requestType->name_ar,
                'name_en' => $this->requestType->name_en,
                'decision_grade_threshold' => $this->requestType->decision_grade_threshold,
            ] : null,
            'decision_grade' => $this->decision_grade,
            'requires_ministry_approval' => $this->requiresMinistryApproval(),
            'status' => $this->status ? [
                'code' => $this->status->code,
                'name_ar' => $this->status->name_ar,
                'name_en' => $this->status->name_en,
                'color' => $this->status->color,
            ] : null,
            'current_stage' => $this->currentStage ? [
                'order_no' => $this->currentStage->order_no,
                'code' => $this->currentStage->code,
                'name_ar' => $this->currentStage->name_ar,
                'name_en' => $this->currentStage->name_en,
            ] : null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'overdue_at' => $this->overdue_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
