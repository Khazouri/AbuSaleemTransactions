<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MeetingMinutes\ReviewMeetingMinutesRequest;
use App\Http\Requests\MeetingMinutes\StoreMeetingMinuteSignatureRequest;
use App\Http\Resources\MeetingMinutesResource;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\MeetingMinuteSignature;
use App\Services\ApprovalSignatureStorage;
use App\Services\MeetingMinutesCompiler;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stage 36 — the minutes lifecycle: generate (compile a fresh draft) →
 * review (the head approves or sends it back) → sign (each present attendee
 * signs; the last signature auto-approves). Rides the `meeting_minutes`
 * screen's own action tiers — see ScreenRolePermissionSeeder's comment above
 * that screen's row for why `add` covers both generating and signing while
 * `approve` is the head-only review decision.
 */
class MeetingMinutesController extends Controller
{
    public function show(Meeting $meeting): MeetingMinutesResource|JsonResponse
    {
        $minutes = $meeting->meetingMinutes()->with([
            'generatedBy:id,name',
            'reviewedBy:id,name',
            'signatures.user:id,name',
        ])->first();

        if ($minutes === null) {
            return response()->json(['data' => null]);
        }

        return new MeetingMinutesResource($minutes);
    }

    /**
     * (Re)compile the draft from the meeting's current data. Blocked once
     * review has moved the document past `draft` — only `review()`'s
     * `changes_requested` outcome reopens that door, and it leaves status
     * exactly where this method expects to find it.
     */
    public function generate(Meeting $meeting, MeetingMinutesCompiler $compiler, Request $request): MeetingMinutesResource|JsonResponse
    {
        $existing = $meeting->meetingMinutes()->first();
        if ($existing !== null && $existing->status !== MeetingMinutes::STATUS_DRAFT) {
            return response()->json([
                'message' => 'لا يمكن إعادة إنشاء المحضر بعد بدء مراجعته أو اعتماده.',
            ], 422);
        }

        $minutes = MeetingMinutes::updateOrCreate(
            ['meeting_id' => $meeting->id],
            [
                'content' => $compiler->compile($meeting),
                'status' => MeetingMinutes::STATUS_DRAFT,
                'generated_by_user_id' => $request->user()->id,
                'generated_at' => now(),
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'review_comment' => null,
            ],
        );

        // Explicit 200: `updateOrCreate` means this is 201 on the very first
        // call and 200 on every regenerate otherwise (Laravel infers the
        // status from Eloquent's wasRecentlyCreated flag on the resource's
        // model) — an idempotent-feeling "compile the draft" action should
        // answer the same way regardless of which happened underneath.
        return (new MeetingMinutesResource($minutes->load(['generatedBy:id,name'])))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * The head's verdict. `approve` creates one signature row per attendee
     * who actually showed up and moves to pending_signatures — or, if nobody
     * was marked attended, there is nothing to sign and the document is
     * approved outright, the same vacuous-pass pattern the readiness/close
     * gates already use for an empty agenda.
     */
    public function review(ReviewMeetingMinutesRequest $request, Meeting $meeting): MeetingMinutesResource|JsonResponse
    {
        $minutes = $meeting->meetingMinutes()->first();
        if ($minutes === null) {
            return response()->json(['message' => 'لم يتم إنشاء محضر لهذا الاجتماع بعد.'], 404);
        }
        if ($minutes->status !== MeetingMinutes::STATUS_DRAFT) {
            return response()->json(['message' => 'تمت مراجعة هذا المحضر بالفعل.'], 422);
        }

        $actor = $request->user();

        if ($request->validated('decision') === 'changes_requested') {
            $minutes->update(['review_comment' => $request->validated('comment')]);

            return new MeetingMinutesResource($minutes);
        }

        $minutes = DB::transaction(function () use ($minutes, $meeting, $actor, $request) {
            $signerUserIds = $meeting->attendees()->where('attended', true)->pluck('user_id');

            foreach ($signerUserIds as $userId) {
                MeetingMinuteSignature::firstOrCreate([
                    'meeting_minutes_id' => $minutes->id,
                    'user_id' => $userId,
                ]);
            }

            $minutes->update([
                'status' => MeetingMinutes::STATUS_PENDING_SIGNATURES,
                'reviewed_by_user_id' => $actor->id,
                'reviewed_at' => now(),
                'review_comment' => $request->validated('comment'),
            ]);

            if ($signerUserIds->isEmpty()) {
                $minutes->update(['status' => MeetingMinutes::STATUS_APPROVED, 'approved_at' => now()]);
            }

            return $minutes;
        });

        if ($minutes->status === MeetingMinutes::STATUS_APPROVED) {
            app(NotificationDispatcher::class)->minutesApproved($meeting, $actor);
        }

        return new MeetingMinutesResource($minutes->load(['reviewedBy:id,name', 'signatures.user:id,name']));
    }

    /**
     * One attendee's own signature. Once every required signature row has a
     * signed_at, the parent document auto-advances to approved — no separate
     * "finalize" click, mirroring DecisionController::record() needing no
     * confirmation step after the deciding input arrives.
     */
    public function sign(
        StoreMeetingMinuteSignatureRequest $request,
        Meeting $meeting,
        ApprovalSignatureStorage $signatureStorage,
        NotificationDispatcher $notifications,
    ): MeetingMinutesResource|JsonResponse {
        $minutes = $meeting->meetingMinutes()->first();
        if ($minutes === null || $minutes->status !== MeetingMinutes::STATUS_PENDING_SIGNATURES) {
            return response()->json(['message' => 'المحضر ليس بانتظار التوقيع حالياً.'], 422);
        }

        $actor = $request->user();
        $signature = MeetingMinuteSignature::query()
            ->where('meeting_minutes_id', $minutes->id)
            ->where('user_id', $actor->id)
            ->first();

        if ($signature === null) {
            return response()->json(['message' => 'أنت لست ضمن الحضور المطلوب توقيعهم على هذا المحضر.'], 404);
        }
        if ($signature->signed_at !== null) {
            return response()->json(['message' => 'لقد وقّعت على هذا المحضر بالفعل.'], 422);
        }

        $path = $signatureStorage->storeForMeetingMinutes($request->file('signature'), $minutes);
        $signature->update(['signature_path' => $path, 'signed_at' => now()]);

        $minutes->refresh()->load('signatures');
        if ($minutes->allSigned()) {
            $minutes->update(['status' => MeetingMinutes::STATUS_APPROVED, 'approved_at' => now()]);
            $notifications->minutesApproved($meeting, $actor);
        }

        return new MeetingMinutesResource($minutes->load(['reviewedBy:id,name', 'signatures.user:id,name']));
    }

    /** Authenticated read access to one private signature image — same shape as ApprovalSignatureController. */
    public function signatureImage(MeetingMinuteSignature $signature): StreamedResponse
    {
        abort_if(blank($signature->signature_path), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($signature->signature_path), 404);

        return $disk->response(
            $signature->signature_path,
            "meeting-minutes-signature-{$signature->id}.png",
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
