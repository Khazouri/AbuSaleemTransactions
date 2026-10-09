<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Stage 23 — one stored in-app notification.
 *
 * The `data` column is flattened into the payload rather than nested, because
 * everything in it (the bilingual title/body, the ids the bell links to) is
 * what the client renders; a `data.data` wrapper would just add a hop.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'event_type' => $data['event_type'] ?? null,
            'title_ar' => $data['title_ar'] ?? null,
            'title_en' => $data['title_en'] ?? null,
            'body_ar' => $data['body_ar'] ?? null,
            'body_en' => $data['body_en'] ?? null,
            // Present only for the events that have one; the bell uses them to
            // link straight to the record being talked about.
            'request_id' => $data['request_id'] ?? null,
            'meeting_id' => $data['meeting_id'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
