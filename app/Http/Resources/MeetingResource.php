<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a Meeting for both the list screen (counts only) and the single
 * meeting workspace (full agenda + attendee rows), depending on what the
 * controller eager-loaded/withCount()'d — the same whenLoaded/whenCounted
 * pattern DepartmentResource and CommitteeResource use.
 */
class MeetingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'meeting_number' => $this->meeting_number,
            'title' => $this->title,
            'meeting_type' => $this->meeting_type,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'location' => $this->location,
            'expected_duration_minutes' => $this->expected_duration_minutes,
            'agenda_deadline' => $this->agenda_deadline?->toIso8601String(),
            'description' => $this->description,
            // Stage 82 — Appendix 24's "مبرر إداري موثق" for an agenda that
            // departs from Art. 83's own ordering.
            'agenda_order_justification' => $this->agenda_order_justification,
            'status' => $this->status,
            'convened_at' => $this->convened_at?->toIso8601String(),
            'readiness_override_reason' => $this->readiness_override_reason,
            'convened_by' => $this->whenLoaded('convenedBy', fn () => $this->convenedBy ? [
                'id' => $this->convenedBy->id,
                'name' => $this->convenedBy->name,
            ] : null),
            'committee' => $this->whenLoaded('committee', fn () => [
                'id' => $this->committee->id,
                'name_ar' => $this->committee->name_ar,
                'name_en' => $this->committee->name_en,
                // Stage 48 — this committee's own tashkil decision grants (or
                // withholds) its rapporteur a substantive vote.
                'rapporteur_votes' => $this->committee->rapporteur_votes,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'chairman' => $this->whenLoaded('chairman', fn () => $this->chairman ? [
                'id' => $this->chairman->id,
                'name' => $this->chairman->name,
            ] : null),
            'rapporteur' => $this->whenLoaded('rapporteur', fn () => $this->rapporteur ? [
                'id' => $this->rapporteur->id,
                'name' => $this->rapporteur->name,
            ] : null),

            'attendees_count' => $this->whenCounted('attendees'),
            'agenda_items_count' => $this->whenCounted('agendaItems'),
            // Stage 36 — a cheap status badge (the detail screen's link into
            // the dedicated minutes screen); the full document lives there.
            'minutes_status' => $this->whenLoaded('meetingMinutes', fn () => $this->meetingMinutes?->status),

            'attendees' => MeetingAttendeeResource::collection($this->whenLoaded('attendees')),
            'agenda_items' => MeetingRequestResource::collection($this->whenLoaded('agendaItems')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
