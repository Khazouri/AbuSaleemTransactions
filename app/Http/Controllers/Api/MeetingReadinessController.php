<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\ConveneMeetingRequest;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Services\MeetingReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Stage 33 — the pre-meeting readiness gate: read the four percentages plus
 * quorum and exceptions (`show`), and convene the meeting (`convene`), which
 * requires a reason only when readiness has unresolved exceptions. `convene`
 * rides `meeting_readiness,edit` (R03-only per ScreenRolePermissionSeeder),
 * which is itself the "R03 exceptional override" gate — no extra role check
 * is needed here.
 */
class MeetingReadinessController extends Controller
{
    public function show(Meeting $meeting, MeetingReadinessService $readiness): JsonResponse
    {
        return response()->json(['data' => $readiness->compute($meeting)]);
    }

    public function convene(ConveneMeetingRequest $request, Meeting $meeting, MeetingReadinessService $readiness): MeetingResource
    {
        if ($meeting->status !== 'scheduled') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن مباشرة اجتماع ليس في حالة \"مجدول\".'],
            ]);
        }

        $verdict = $readiness->compute($meeting);
        $reason = trim((string) $request->validated('reason'));

        if (! $verdict['ready'] && $reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['الاجتماع غير جاهز — يجب إدخال سبب لتجاوز الجاهزية والمباشرة.'],
            ]);
        }

        $meeting->update([
            'convened_at' => now(),
            'convened_by_user_id' => $request->user()->id,
            'readiness_override_reason' => $verdict['ready'] ? null : $reason,
            // Stage 73 — freeze the committee's quorum/majority rules as they
            // stand at the moment the sitting opens. Art. 84 makes صحة
            // الانعقاد a fact about *this* sitting, so a later edit to the
            // بطاقة تعريف اللجنة must not retroactively change whether a
            // meeting already held was validly convened. Null when nothing
            // was ever transcribed — an absent rule stays absent rather than
            // being frozen as an empty one.
            'voting_rules_snapshot' => $meeting->committee?->votingRules()->toArray(),
        ]);

        return new MeetingResource($meeting->fresh()->load(['committee:id,name_ar,name_en', 'convenedBy:id,name']));
    }
}
