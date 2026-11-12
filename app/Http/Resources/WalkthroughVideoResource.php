<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One walkthrough clip. Never exposes the disk or path: playback goes through a signed URL. */
class WalkthroughVideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'description_ar' => $this->description_ar,
            'description_en' => $this->description_en,
            'screen_code' => $this->screen_code,
            'action' => $this->action,
            'roles' => $this->roles,
            'sort_order' => $this->sort_order,
            'has_file' => $this->path !== null,
            'size_bytes' => $this->size_bytes,
            'duration_seconds' => $this->duration_seconds,
            'uploaded_at' => $this->uploaded_at,
        ];
    }
}
