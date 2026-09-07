<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CommitteeStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestLegalReview\StoreRequestLegalReviewRequest;
use App\Http\Resources\RequestLegalReviewResource;
use App\Http\Resources\RequestResource;
use App\Models\Request;
use App\Models\RequestLegalReview;
use App\Services\CommitteeStatusService;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stage 68 — [D] Art. 21 / [E] stage 08: the pre-meeting legal review, the one
 * numbered step of the approved flow that had no implementation at all
 * (`legal_review` existed only on `Appeal`, from Stage 62).
 *
 * Four actions, split across two permission tiers per Appendix 6's RACI row
 * for المراجعة القانونية (العضو القانوني = مسؤول، مقرر اللجنة = منسق):
 *   - requestReview()  `legal_review,edit`  — the rapporteur hands the file over
 *   - store()          `legal_review,add`   — the legal member records a verdict
 *   - index()/show()   `legal_review,view`  — Art. 21 requires the recorded
 *                                             opinion to be readable by the
 *                                             committee at study time
 *
 * None of these ever touches current_stage_id. The review sits inside the
 * `receive_from_committee` stage as agenda preparation (between Art. 20's قيد
 * and Art. 22's presentation memo), so its statuses are status-only moves
 * through CommitteeStatusService — the same reasoning Stage 29 recorded for
 * every other committee sub-status.
 *
 * The agenda gate itself is NOT here: it lives in
 * MeetingController::addAgendaItem(), because Stage 44 already established
 * that an item can be inserted without CommitteeStatusService::place_on_agenda
 * ever firing, so gating the service alone would be trivially bypassable.
 */
class RequestLegalReviewController extends Controller
{
    /** [D] Art. 21's queue: files currently with the legal member. */
    public function index(HttpRequest $request, CommitteeStatusService $committeeStatus): AnonymousResourceCollection
    {
        $requests = $committeeStatus->legalReviewQueueQuery()
            ->with([
                'department:id,name_ar,name_en,code',
                // Appendix 21's per-subject legal basis rides the type and
                // pre-fills Appendix 22's card, so the queue carries it too.
                'requestType:id,code,name_ar,name_en,legal_basis_ar,legal_basis_note_ar',
                'status:id,code,name_ar,name_en,color',
                'currentStage:id,order_no,code,name_ar,name_en',
                'createdBy:id,name',
                'latestLegalReview.reviewedBy:id,name',
            ])
            ->withCount(['attachments', 'legalReviews'])
            ->orderBy('submitted_at')
            ->paginate(min((int) $request->integer('per_page', 20) ?: 20, 100))
            ->withQueryString();

        return RequestResource::collection($requests);
    }

    /**
     * One request's full review history, oldest first — Art. 21's "ويثبت الرأي
     * أو الملاحظة القانونية في الملف بما يسمح لأعضاء اللجنة بالاطلاع عليها عند
     * الدراسة" is why every round is returned, not just the latest.
     *
     * `legal_basis` echoes Appendix 21's per-type row so the review form can
     * pre-fill Appendix 22's card rather than making the legal member retype a
     * statute citation the manual already fixes for that subject.
     */
    public function show(Request $requestRecord): array
    {
        $requestRecord->loadMissing([
            'requestType:id,code,name_ar,name_en,legal_basis_ar,legal_basis_note_ar',
            'legalReviews.reviewedBy:id,name',
        ]);

        return [
            'data' => [
                'request' => [
                    'id' => $requestRecord->id,
                    'reference_number' => $requestRecord->reference_number,
                    'title' => $requestRecord->title,
                ],
                'legal_basis' => [
                    'primary_legislation' => $requestRecord->requestType?->legal_basis_ar,
                    'procedural_note' => $requestRecord->requestType?->legal_basis_note_ar,
                ],
                'reviews' => RequestLegalReviewResource::collection(
                    $requestRecord->legalReviews->sortBy('id')->values(),
                )->resolve(),
            ],
        ];
    }

    /** The rapporteur hands the completed file to the legal member (Art. 38's code 07). */
    public function requestReview(HttpRequest $request, Request $requestRecord, CommitteeStatusService $committeeStatus): RequestResource
    {
        try {
            $requestRecord = $committeeStatus->move(
                $requestRecord,
                'send_to_legal_review',
                $request->user(),
            );
        } catch (CommitteeStatusTransitionException $exception) {
            throw ValidationException::withMessages(['action' => [$exception->getMessage()]]);
        }

        return new RequestResource($requestRecord->load(['department', 'requestType', 'status', 'currentStage']));
    }

    /**
     * Record one round of the review. The status move rides the same DB
     * transaction as the row insert, so a stored verdict and the status it
     * implies can never disagree — the property the agenda gate depends on.
     */
    public function store(
        StoreRequestLegalReviewRequest $request,
        Request $requestRecord,
        CommitteeStatusService $committeeStatus,
    ) {
        $validated = $request->validated();
        $actor = $request->user();

        $permits = in_array($validated['verdict'], RequestLegalReview::PERMITTING_VERDICTS, strict: true);

        try {
            $review = DB::transaction(function () use ($requestRecord, $validated, $actor, $committeeStatus, $permits) {
                // move() locks the request row and enforces that it is
                // actually at `under_legal_review`; running it FIRST means a
                // request that was never handed over never gets a review row
                // written for it either.
                $committeeStatus->move(
                    $requestRecord,
                    $permits ? 'pass_legal_review' : 'fail_legal_review',
                    $actor,
                    // fail_legal_review requires a comment; the legal member's
                    // own note is that comment, and the FormRequest has already
                    // guaranteed it is present for every blocking verdict.
                    $validated['legal_note'] ?? null,
                );

                return $requestRecord->legalReviews()->create([
                    ...$validated,
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                ]);
            });
        } catch (CommitteeStatusTransitionException $exception) {
            throw ValidationException::withMessages(['verdict' => [$exception->getMessage()]]);
        }

        return (new RequestLegalReviewResource($review->load('reviewedBy:id,name')))
            ->response()->setStatusCode(201);
    }
}
