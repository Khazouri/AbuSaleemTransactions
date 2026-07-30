<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact transaction payload for the Stage 11 searchable list. */
class TransactionResource extends JsonResource
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
            'transaction_type' => $this->transactionType ? [
                'id' => $this->transactionType->id,
                'code' => $this->transactionType->code,
                'name_ar' => $this->transactionType->name_ar,
                'name_en' => $this->transactionType->name_en,
            ] : null,
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
