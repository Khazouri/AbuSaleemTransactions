<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One agenda slot, with just enough of its transaction to render the agenda list. */
class MeetingTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agenda_order' => $this->agenda_order,
            'transaction' => $this->whenLoaded('transaction', fn () => [
                'id' => $this->transaction->id,
                'reference_number' => $this->transaction->reference_number,
                'title' => $this->transaction->title,
                'status' => $this->transaction->status ? [
                    'code' => $this->transaction->status->code,
                    'name_ar' => $this->transaction->status->name_ar,
                    'name_en' => $this->transaction->status->name_en,
                    'color' => $this->transaction->status->color,
                ] : null,
            ]),
        ];
    }
}
