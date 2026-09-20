<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lifecycle\DetermineWithdrawalRequest;
use App\Http\Requests\Lifecycle\RecordDocumentValidityRequest;
use App\Http\Requests\Lifecycle\ResolveDocumentConflictRequest;
use App\Http\Requests\Lifecycle\ResolveSpecialCaseRequest;
use App\Http\Requests\Lifecycle\StoreCorrectionRequest;
use App\Http\Requests\Lifecycle\StoreDocumentConflictRequest;
use App\Http\Requests\Lifecycle\StoreSpecialCaseRequest;
use App\Http\Requests\Lifecycle\StoreWithdrawalRequest;
use App\Models\Attachment;
use App\Models\Request;
use App\Models\RequestCorrection;
use App\Models\RequestDocumentConflict;
use App\Models\RequestSpecialCase;
use App\Models\RequestWithdrawal;
use App\Models\User;
use App\Services\Lifecycle\CorrectionRules;
use App\Services\Lifecycle\DocumentConflictService;
use App\Services\Lifecycle\DocumentValidityRules;
use App\Services\Lifecycle\DuplicatePolicy;
use App\Services\Lifecycle\SpecialCaseRules;
use App\Services\Lifecycle\WithdrawalService;
use App\Services\RequestVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Validation\ValidationException;

/**
 * Stage 83 — the lifecycle edge cases [D] anticipates: document conflicts
 * (Appendix 30), document validity (Appendix 31), material-error corrections
 * (Appendix 53), the six special cases (Appendix 60), withdrawal before and
 * after a decision (Appendices 68/69), and Appendix 16's duplicate check.
 *
 * Its own controller rather than six more methods on RequestController, which
 * already carries twenty: these are sub-resources of a request with their own
 * write rules, and the house convention is one controller per resource.
 *
 * **No new screen and no permission change.** Recording and determining ride
 * `meeting_outputs,edit` (R02 المقرر + R03), the grant Stages 75/76/77/80 have
 * already used for the rapporteur's own determinations about a file; filing a
 * withdrawal rides `notes_attachments,add`, because Appendix 68's first step
 * is the employee adding a written request to their own file. Reading rides
 * `request_details,view` and is additionally scoped by RequestVisibility, so
 * the permission decides whether the screen works and never whose file is
 * visible.
 */
class RequestLifecycleController extends Controller
{
    /**
     * [D] Appendix 16's own search — "يقوم النظام أو مقرر اللجنة بالبحث برقم
     * الموظف وموضوع المعاملة" — so the intake screen can show the answer before
     * the submitter fills a form the server would refuse.
     */
    public function duplicateCheck(HttpRequest $request, DuplicatePolicy $policy): JsonResponse
    {
        $typeId = (int) $request->query('request_type_id', 0);

        if ($typeId === 0) {
            return response()->json(['data' => ['prior_requests' => [], 'open_prior' => null]]);
        }

        // Stage 95 — the prior files of صاحب العلاقة, so the intake screen
        // shows the same answer the server will act on when a clerk is
        // filing on an employee's behalf.
        $subject = User::query()->find((int) $request->query('subject_user_id', 0)) ?? $request->user();

        if (! $subject->is($request->user())
            && ! $request->user()->hasScreenPermission('request_intake', 'can_approve')) {
            $subject = $request->user();
        }

        $priors = $policy->priorRequests($subject, $typeId);

        return response()->json(['data' => [
            'prior_requests' => $priors->map(fn (Request $prior) => [
                'id' => $prior->id,
                'tracking_number' => $prior->trackingNumber(),
                'title' => $prior->title,
                'status' => $prior->status?->name_ar,
                'concluded' => $policy->isConcluded($prior),
                'submitted_at' => $prior->submitted_at?->toIso8601String(),
            ])->values(),
            'open_prior' => $priors->first(fn (Request $prior) => ! $policy->isConcluded($prior))?->only(['id', 'title']),
            'relations' => collect(DuplicatePolicy::RELATIONS)
                ->map(fn (array $labels, string $code) => [
                    'code' => $code,
                    'ar' => $labels['ar'],
                    'en' => $labels['en'],
                    // The two the appendix routes to another mechanism; the
                    // screen greys them out rather than letting a submitter
                    // pick an answer the server will refuse.
                    'redirected' => array_key_exists($code, DuplicatePolicy::REDIRECTED_RELATIONS),
                ])->values(),
        ]]);
    }

    /** Everything Stage 83 records about one file, in one read. */
    public function index(
        HttpRequest $request,
        Request $requestRecord,
        RequestVisibility $visibility,
        DocumentValidityRules $validity,
        WithdrawalService $withdrawals,
    ): JsonResponse {
        abort_unless($visibility->canView($request->user(), $requestRecord), 404);

        $requestRecord->load([
            'documentConflicts.recordedBy:id,name',
            'documentConflicts.resolvedBy:id,name',
            'specialCases.recordedBy:id,name',
            'specialCases.resolvedBy:id,name',
            'corrections.recordedBy:id,name',
            'corrections.approvedBy:id,name',
            'withdrawals.requestedBy:id,name',
            'withdrawals.determinedBy:id,name',
            'attachments.validityCheckedBy:id,name',
        ]);

        return response()->json(['data' => [
            'document_conflicts' => $requestRecord->documentConflicts->map(fn (RequestDocumentConflict $conflict) => [
                'id' => $conflict->id,
                'kind' => $conflict->conflict_kind,
                'kind_label' => $conflict->kindLabel(),
                'detail' => $conflict->detail,
                'attachment_ids' => $conflict->attachment_ids ?? [],
                'recorded_by' => $conflict->recordedBy?->name,
                'recorded_at' => $conflict->created_at?->toIso8601String(),
                'authority_consulted' => $conflict->authority_consulted,
                'authoritative_document' => $conflict->authoritative_document,
                'correction_note' => $conflict->correction_note,
                'resolved_by' => $conflict->resolvedBy?->name,
                'resolved_at' => $conflict->resolved_at?->toIso8601String(),
            ])->values(),
            'special_cases' => $requestRecord->specialCases->map(fn (RequestSpecialCase $case) => [
                'id' => $case->id,
                'kind' => $case->case_kind,
                'kind_label' => $case->kindLabel(),
                'detail' => $case->detail,
                'determinations' => $case->determinations,
                'halt_progress' => $case->halt_progress,
                'recorded_by' => $case->recordedBy?->name,
                'recorded_at' => $case->created_at?->toIso8601String(),
                'resolution_note' => $case->resolution_note,
                'resolved_by' => $case->resolvedBy?->name,
                'resolved_at' => $case->resolved_at?->toIso8601String(),
            ])->values(),
            'corrections' => $requestRecord->corrections->map(fn (RequestCorrection $correction) => [
                'id' => $correction->id,
                'kind' => $correction->error_kind,
                'kind_label' => app(CorrectionRules::class)->label($correction->error_kind),
                'detail' => $correction->detail,
                'incorrect_value' => $correction->incorrect_value,
                'corrected_value' => $correction->corrected_value,
                'memo_reference' => $correction->memo_reference,
                'recorded_by' => $correction->recordedBy?->name,
                'recorded_at' => $correction->created_at?->toIso8601String(),
                'approved_by' => $correction->approvedBy?->name,
                'approved_at' => $correction->approved_at?->toIso8601String(),
            ])->values(),
            'withdrawals' => $requestRecord->withdrawals->map(fn (RequestWithdrawal $withdrawal) => [
                'id' => $withdrawal->id,
                'reason' => $withdrawal->reason,
                'requested_by' => $withdrawal->requestedBy?->name,
                'requested_at' => $withdrawal->requested_at?->toIso8601String(),
                'decision_existed_at_filing' => $withdrawal->decision_existed_at_filing,
                'outcome' => $withdrawal->outcome,
                'outcome_label' => $withdrawal->outcomeLabel(),
                'determination_note' => $withdrawal->determination_note,
                'determined_by' => $withdrawal->determinedBy?->name,
                'determined_at' => $withdrawal->determined_at?->toIso8601String(),
            ])->values(),
            'document_validity' => $requestRecord->attachments->map(fn (Attachment $attachment) => [
                'attachment_id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'card' => $validity->card($attachment),
            ])->values(),
            // The employee can file a withdrawal only while none is pending —
            // a second written request before the first is answered would give
            // the administration two things to determine about one wish.
            'has_open_withdrawal' => $withdrawals->openWithdrawal($requestRecord) !== null,
            'committee_has_decided' => $withdrawals->hasDecision($requestRecord),
        ]]);
    }

    /** [D] Appendix 30 step 1. */
    public function storeDocumentConflict(
        StoreDocumentConflictRequest $request,
        Request $requestRecord,
        DocumentConflictService $conflicts,
    ): JsonResponse {
        $conflict = $conflicts->record($requestRecord, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $conflict->id]], 201);
    }

    /** [D] Appendix 30 steps 2-4, and with them step 6 — the agenda gate lifting. */
    public function resolveDocumentConflict(
        ResolveDocumentConflictRequest $request,
        Request $requestRecord,
        RequestDocumentConflict $conflict,
        DocumentConflictService $conflicts,
    ): JsonResponse {
        abort_unless($conflict->request_id === $requestRecord->getKey(), 404);

        $conflicts->resolve($conflict, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $conflict->id]]);
    }

    /** [D] Appendix 31's nine checks over one of this file's documents. */
    public function recordDocumentValidity(
        RecordDocumentValidityRequest $request,
        Request $requestRecord,
        Attachment $attachment,
        DocumentValidityRules $rules,
    ): JsonResponse {
        abort_unless($attachment->request_id === $requestRecord->getKey(), 404);

        $checks = $request->validated('checks');

        if (($refusal = $rules->refusalReason($checks)) !== null) {
            throw ValidationException::withMessages(['checks' => [$refusal]]);
        }

        $attachment->update([
            'validity_checks' => $checks,
            'validity_checked_by_user_id' => $request->user()->id,
            'validity_checked_at' => now(),
        ]);

        return response()->json(['data' => [
            'attachment_id' => $attachment->id,
            'verdict' => $rules->verdict($checks),
        ]]);
    }

    /** [D] Appendix 60 — one special case with its own determinations. */
    public function storeSpecialCase(
        StoreSpecialCaseRequest $request,
        Request $requestRecord,
        SpecialCaseRules $rules,
    ): JsonResponse {
        $data = $request->validated();

        if (($refusal = $rules->refusalReason($data['case_kind'], $data['determinations'])) !== null) {
            throw ValidationException::withMessages(['determinations' => [$refusal]]);
        }

        $case = $requestRecord->specialCases()->create([
            'case_kind' => $data['case_kind'],
            'detail' => $data['detail'] ?? null,
            'determinations' => $data['determinations'],
            'halt_progress' => (bool) ($data['halt_progress'] ?? false),
            'recorded_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => ['id' => $case->id]], 201);
    }

    public function resolveSpecialCase(
        ResolveSpecialCaseRequest $request,
        Request $requestRecord,
        RequestSpecialCase $specialCase,
    ): JsonResponse {
        abort_unless($specialCase->request_id === $requestRecord->getKey(), 404);
        abort_if($specialCase->resolved_at !== null, 422, 'سبق معالجة هذه الحالة الخاصة.');

        $specialCase->update([
            'resolution_note' => $request->validated('resolution_note'),
            'resolved_by_user_id' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $specialCase->id]]);
    }

    /** [D] Appendix 53 — record a correction memo, or be told it is not one. */
    public function storeCorrection(
        StoreCorrectionRequest $request,
        Request $requestRecord,
        CorrectionRules $rules,
    ): JsonResponse {
        $data = $request->validated();

        if (($refusal = $rules->refusalReason($data['error_kind'])) !== null) {
            throw ValidationException::withMessages(['error_kind' => [$refusal]]);
        }

        $correction = $requestRecord->corrections()->create([
            'error_kind' => $data['error_kind'],
            'detail' => $data['detail'],
            'incorrect_value' => $data['incorrect_value'],
            'corrected_value' => $data['corrected_value'],
            'memo_reference' => $data['memo_reference'] ?? null,
            'recorded_by_user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => ['id' => $correction->id]], 201);
    }

    /**
     * [D] Appendix 53's "معتمدة" — a memo corrects nothing until it is
     * approved, and the approver is never the recorder.
     *
     * That second rule is Appendix 19's own مصفوفة الفصل بين الصلاحيات: "لا
     * يكون ... مراجع الملف هو صاحب القرار المنفرد بشأنه". Nothing on the
     * request or the decision is rewritten by approving — the memo becomes an
     * approved document on the file, which is what "دون تغيير جوهر النتيجة"
     * asks for.
     */
    public function approveCorrection(
        HttpRequest $request,
        Request $requestRecord,
        RequestCorrection $correction,
    ): JsonResponse {
        abort_unless($correction->request_id === $requestRecord->getKey(), 404);
        abort_if($correction->approved_at !== null, 422, 'سبق اعتماد مذكرة التصحيح.');

        if ($correction->recorded_by_user_id === $request->user()->id) {
            throw ValidationException::withMessages([
                'correction' => ['لا يعتمد مذكرة التصحيح من حررها؛ يلزم اعتمادها من مسؤول آخر.'],
            ]);
        }

        $correction->update([
            'approved_by_user_id' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $correction->id]]);
    }

    /** [D] Appendix 68 step 1 — the employee's own written withdrawal request. */
    public function storeWithdrawal(
        StoreWithdrawalRequest $request,
        Request $requestRecord,
        WithdrawalService $withdrawals,
    ): JsonResponse {
        // "إذا طلب **الموظف** سحب معاملته" — the withdrawal is the requester's
        // own act, so nobody files one on somebody else's file even when they
        // hold the grant to add documents to it.
        if ($requestRecord->created_by_user_id !== $request->user()->id) {
            throw ValidationException::withMessages([
                'reason' => ['طلب سحب المعاملة يقدم من صاحبها.'],
            ]);
        }

        if ($withdrawals->openWithdrawal($requestRecord) !== null) {
            throw ValidationException::withMessages([
                'reason' => ['يوجد طلب سحب لم يبت فيه بعد.'],
            ]);
        }

        $withdrawal = $withdrawals->file($requestRecord, $request->validated('reason'), $request->user());

        return response()->json(['data' => ['id' => $withdrawal->id]], 201);
    }

    /** [D] Appendices 68 step 4 / 69 — the administration's answer. */
    public function determineWithdrawal(
        DetermineWithdrawalRequest $request,
        Request $requestRecord,
        RequestWithdrawal $withdrawal,
        WithdrawalService $withdrawals,
    ): JsonResponse {
        abort_unless($withdrawal->request_id === $requestRecord->getKey(), 404);

        $data = $request->validated();

        if (($refusal = $withdrawals->refusalReason($requestRecord, $withdrawal, $data['outcome'])) !== null) {
            throw ValidationException::withMessages(['outcome' => [$refusal]]);
        }

        $withdrawals->determine($requestRecord, $withdrawal, $data, $request->user());

        return response()->json(['data' => ['id' => $withdrawal->id]]);
    }
}
