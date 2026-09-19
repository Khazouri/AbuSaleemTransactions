<?php

namespace App\Services;

use App\Http\Resources\AppealAttachmentResource;
use App\Http\Resources\DecisionResource;
use App\Http\Resources\NotificationResource;
use App\Models\Appeal;
use App\Models\Attachment;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Stage 61, Track J — a read-only dossier for one appeal: the original
 * request, its presentation memo, its meeting-minutes excerpt, its decision,
 * whatever notification evidence genuinely exists, and the appeal's own
 * documents, all in one place. Reuses PresentationMemoCompiler (Stage 46) and
 * the persisted output of MeetingMinutesCompiler (Stage 36/50) rather than
 * re-deriving their data — see AGENT_NOTES.md for why the memo's derived
 * half is recomputed live while the minutes are read from the persisted,
 * possibly-still-draft document instead of live-recompiled.
 */
class AppealFileCompiler
{
    public function __construct(private readonly PresentationMemoCompiler $presentationMemoCompiler) {}

    /** @return array<string, mixed> */
    public function compile(Appeal $appeal): array
    {
        $appeal->loadMissing([
            'originalRequest.department:id,name_ar,name_en',
            'originalRequest.requestType:id,name_ar,name_en',
            'originalRequest.status:id,code,name_ar,name_en,color',
            'originalRequest.currentStage:id,code,name_ar,name_en',
            'originalRequest.createdBy:id,name',
            'originalRequest.attachments',
            'originalDecision.decidedBy:id,name',
            'originalDecision.template:id,code,name_ar,name_en',
            'originalDecision.meetingRequest.presentationMemo',
            'originalDecision.meetingRequest.meeting.meetingMinutes',
        ]);

        $originalRequest = $appeal->originalRequest;
        $agendaItem = $appeal->originalDecision?->meetingRequest;

        return [
            'original_request' => $this->originalRequest($originalRequest),
            'presentation_memo' => $this->presentationMemo($agendaItem),
            'meeting_minutes' => $this->meetingMinutes($agendaItem),
            'decision' => $appeal->originalDecision
                ? (new DecisionResource($appeal->originalDecision))->resolve()
                : null,
            // The free-text fallback recorded at intake for a decided matter
            // with no `decisions` row (e.g. Stage 54's reject_formally) —
            // already on the Appeal itself, echoed here so the dossier is
            // complete without a second request.
            'decision_reference_fallback' => $appeal->originalDecision ? null : [
                'reference' => $appeal->original_decision_reference,
                'date' => $appeal->original_decision_date?->toDateString(),
            ],
            'appeal_documents' => $appeal->attachments->map(
                fn ($attachment) => (new AppealAttachmentResource($attachment))->resolve(),
            )->values()->all(),
            'notification_evidence' => $this->notificationEvidence($appeal, $originalRequest),
        ];
    }

    /** @return array<string, mixed>|null */
    private function originalRequest(?Request $originalRequest): ?array
    {
        if ($originalRequest === null) {
            return null;
        }

        return [
            'id' => $originalRequest->id,
            'reference_number' => $originalRequest->reference_number,
            'title' => $originalRequest->title,
            'description' => $originalRequest->description,
            'reasons' => $originalRequest->reasons,
            'submitted_at' => $originalRequest->submitted_at?->toIso8601String(),
            'status' => $originalRequest->status ? [
                'code' => $originalRequest->status->code,
                'name_ar' => $originalRequest->status->name_ar,
                'name_en' => $originalRequest->status->name_en,
                'color' => $originalRequest->status->color,
            ] : null,
            'current_stage' => $originalRequest->currentStage ? [
                'code' => $originalRequest->currentStage->code,
                'name_ar' => $originalRequest->currentStage->name_ar,
                'name_en' => $originalRequest->currentStage->name_en,
            ] : null,
            'request_type' => $originalRequest->requestType ? [
                'name_ar' => $originalRequest->requestType->name_ar,
                'name_en' => $originalRequest->requestType->name_en,
            ] : null,
            'department' => $originalRequest->department ? [
                'name_ar' => $originalRequest->department->name_ar,
                'name_en' => $originalRequest->department->name_en,
            ] : null,
            'created_by' => $originalRequest->createdBy ? [
                'id' => $originalRequest->createdBy->id,
                'name' => $originalRequest->createdBy->name,
            ] : null,
            'attachments' => $originalRequest->attachments->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'label' => $attachment->label,
            ])->values()->all(),
        ];
    }

    /**
     * The derived half is always honestly computable and is recomputed live
     * regardless of whether a PresentationMemo row was ever persisted; the
     * authored half only exists once a human actually wrote one.
     *
     * @return array<string, mixed>|null
     */
    private function presentationMemo(?MeetingRequest $agendaItem): ?array
    {
        if ($agendaItem === null) {
            return null;
        }

        $memo = $agendaItem->presentationMemo;

        return [
            'derived' => $this->presentationMemoCompiler->compile($agendaItem),
            'authored' => $memo?->content['authored'] ?? null,
            'generated_at' => $memo?->generated_at?->toIso8601String(),
        ];
    }

    /**
     * Read from the meeting's persisted, possibly-still-draft MeetingMinutes
     * document — never live-recompiled, since minutes carry their own
     * approval lifecycle and a fresh recompile would misrepresent something
     * that may never have been generated/reviewed/signed as though it had.
     *
     * @return array<string, mixed>|null
     */
    private function meetingMinutes(?MeetingRequest $agendaItem): ?array
    {
        $meeting = $agendaItem?->meeting;
        $minutes = $meeting?->meetingMinutes;

        if ($minutes === null) {
            return null;
        }

        $content = $minutes->content ?? [];
        $agendaItemContent = collect($content['agenda_items'] ?? [])
            ->firstWhere('id', $agendaItem->id);

        return [
            'status' => $minutes->status,
            'meeting' => $content['meeting'] ?? null,
            'attendance' => $content['attendance'] ?? null,
            'required_signatories' => $content['required_signatories'] ?? null,
            'agenda_item' => $agendaItemContent,
            'approved_at' => $minutes->approved_at?->toIso8601String(),
        ];
    }

    /**
     * إثبات التبليغ — only in-app notifications leave a real trail today
     * (Stage 23's `notifications` table is Laravel's database-channel store;
     * mail/SMS have no delivery log anywhere in this schema). The appellant
     * is, per AppealEligibility's ownership rule, always the original
     * request's own creator, so their notification history about that
     * request is what "proof of notification" honestly means here.
     *
     * @return array<string, mixed>
     */
    private function notificationEvidence(Appeal $appeal, ?Request $originalRequest): array
    {
        $inApp = collect();

        if ($originalRequest !== null && $appeal->appellant_user_id !== null) {
            $inApp = DatabaseNotification::query()
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $appeal->appellant_user_id)
                ->where('data->request_id', $originalRequest->id)
                ->oldest('created_at')
                ->get();
        }

        return [
            'in_app' => NotificationResource::collection($inApp)->resolve(),
            'email' => [
                'evidence_available' => false,
                'note' => 'لا يحتفظ النظام بسجل تسليم لإشعارات البريد الإلكتروني.',
            ],
            'sms' => [
                'evidence_available' => false,
                'note' => 'لا يحتفظ النظام بسجل تسليم للرسائل النصية القصيرة.',
            ],
        ];
    }
}
