<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestTracking\IndexTrackedRequest;
use App\Http\Resources\RequestTrackingResource;
use App\Models\Request;
use App\Services\EmployeeNoticeRegister;
use App\Services\Performance\TimeCardCompiler;
use App\Services\ReportMetricsService;
use App\Services\RequestTimelineCompiler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stage 89 — «متابعة طلباتي», the screen [G]'s employee note 4 tells the
 * employee to use.
 *
 * Packaging, not new data. Every field this controller returns already existed
 * and was already visible to the person who filed the request; what did not
 * exist was a place that says it in their terms. The only list route before
 * this one is GET /requests — the internal work queue, whose framing is "work I
 * can act on" and whose UI exposes no search box at all, even though its
 * `search` param has been there since Stage 20.
 *
 * **Scoped by ownership, not through RequestVisibility.** Stage 95 — the
 * caller is either the filer or صاحب العلاقة, which is that service's own
 * *first and narrowest* clause, so every row this controller returns is by
 * construction a row the request workspace would also open — the tracking list can never offer a file
 * that 404s when the employee clicks into it. Re-running the full visibility
 * query would widen the population to work the caller merely has a role on,
 * which is the queue this screen exists to be distinct from.
 *
 * That ownership rule has no admin exemption: «طلباتي» means mine, so R08 gets
 * a 404 here on somebody else's file and reaches it through /requests/{id} like
 * always. A file filed on an employee's behalf is «mine» to BOTH of them —
 * the employee it is about is tracking their own matter, and the clerk is
 * tracking work they filed.
 */
class RequestTrackingController extends Controller
{
    /** The caller's own files, newest first. */
    public function index(IndexTrackedRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $scope = $filters['scope'] ?? 'all';

        $requests = $this->ownedBy($request)
            ->with([
                'subject:id,name',
                'requestType:id,code,name_ar,name_en,decision_grade_threshold,default_administrative_route',
                'department:id,name_ar,name_en,code',
                'status:id,code,name_ar,name_en,color',
                // Stage 52 — target_days_* feed stageTimeliness(), which is
                // what carries this screen's «الوقت المتوقع للمرحلة» answer.
                'currentStage:id,order_no,code,name_ar,name_en,target_days_min,target_days_max',
                // No column restriction — latestOfMany's generated join needs
                // the full row shape or its column names collide, the gotcha
                // Stages 52 and 74 both recorded.
                'latestStageLog',
            ])
            // "Concluded" is ReportMetricsService's own union of completed and
            // abandoned outcomes — the set overdueQuery() already treats as no
            // longer anyone's job. Read rather than restated: a fourth
            // definition of finished, invented for a screen filter, is exactly
            // the drift the comments on the other three warn about.
            ->when($scope !== 'all', fn (Builder $query) => $query->whereHas(
                'status',
                fn (Builder $status) => $scope === 'concluded'
                    ? $status->whereIn('code', ReportMetricsService::CONCLUDED_STATUSES)
                    : $status->whereNotIn('code', ReportMetricsService::CONCLUDED_STATUSES),
            ))
            // The tracking number is what the employee actually holds — a
            // PM-RCV receipt before the قيد and a PM-COM reference after it —
            // so both columns are searched, along with the subject they wrote.
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('reference_number', 'like', "%{$search}%")
                    ->orWhere('intake_receipt_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%"),
            ))
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        // The same resource the panel uses: its three registers are gated on
        // having actually been computed, so a list row carries the tracking
        // block (status, step, responsibility, expected date, is_concluded)
        // and none of the per-file histories.
        return RequestTrackingResource::collection($requests);
    }

    /** One of the caller's own files, with the three registers Stage 89 names. */
    public function show(HttpRequest $request, Request $requestRecord): RequestTrackingResource
    {
        // 404 rather than 403, and for every role: this endpoint's whole
        // subject is "files I filed", so a file belonging to someone else is
        // not a permission problem here, it simply is not on this screen. Same
        // shape Stage 88's draft endpoints use.
        abort_unless(
            $requestRecord->created_by_user_id === $request->user()->id
                || $requestRecord->subject_user_id === $request->user()->id,
            404,
        );

        $requestRecord->load([
            'subject:id,name',
            'requestType:id,code,name_ar,name_en,decision_grade_threshold,default_administrative_route',
            'department:id,name_ar,name_en,code',
            'status:id,code,name_ar,name_en,color',
            'currentStage:id,order_no,code,name_ar,name_en,target_days_min,target_days_max',
            'latestStageLog',
            // Art. 100's timeline. `responsible_role_id` is in these column
            // lists because the nested responsibleRole eager load cannot
            // resolve without its own foreign key — the restricted-select
            // gotcha Stages 52 and 74 both recorded.
            'stageLogs' => fn ($query) => $query->orderBy('acted_at')->orderBy('id'),
            'stageLogs.fromStage:id,order_no,code,name_ar,name_en,responsible_role_id',
            'stageLogs.toStage:id,order_no,code,name_ar,name_en,responsible_role_id',
            'stageLogs.actedBy:id,name,department_id',
            'stageLogs.actedBy.department:id,name_ar,name_en',
            'stageLogs.fromStage.responsibleRole:id,name_ar,name_en',
            'stageLogs.toStage.responsibleRole:id,name_ar,name_en',
            // Stage 71 — legallyTimeBound() reads this to decide whether a
            // late stage is حرج rather than merely red. Omitting it would make
            // every delay read as red on this screen and critical on the
            // workspace, for the same file.
            'latestLegalReview',
        ]);

        $requestRecord->setAttribute(
            'art_100_timeline',
            app(RequestTimelineCompiler::class)->compile($requestRecord),
        );
        $requestRecord->setAttribute(
            'employee_notices',
            app(EmployeeNoticeRegister::class)->for($requestRecord),
        );
        $requestRecord->setAttribute(
            'time_card',
            app(TimeCardCompiler::class)
                ->forRequest($requestRecord)
                ->toArray(app()->getLocale() === 'en' ? 'en' : 'ar'),
        );

        return new RequestTrackingResource($requestRecord);
    }

    /** @return Builder<Request> */
    private function ownedBy(HttpRequest $request): Builder
    {
        return Request::query()->where(fn (Builder $mine) => $mine
            ->where('created_by_user_id', $request->user()->id)
            ->orWhere('subject_user_id', $request->user()->id));
    }
}
