<?php

namespace App\Services\Lifecycle;

use App\Models\Request;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Stage 83 — [D] Appendix 16's سياسة عدم ازدواجية المعاملات.
 *
 * "إذا قدم الموظف موضوعًا سبق قيده: يقوم النظام أو مقرر اللجنة بالبحث برقم
 * الموظف وموضوع المعاملة. وعند وجود ملف مفتوح لنفس الموضوع: **لا تنشأ معاملة
 * جديدة.** بل تلحق المستندات بالمعاملة القائمة."
 *
 * **The search is by creator + request type, and that is the structured
 * موضوع this system has.** Track K's own scope decision (1) puts the
 * employment record outside this application, so a request's creator *is* its
 * رقم الموظف; and the request type is the only structured statement of what a
 * file is about. Matching on the free-text title as well would be a
 * similarity heuristic reporting a guess as a finding — the same call Stage 81
 * made when it declined to group free-text shortfall reasons into a statistic.
 *
 * **Two of the appendix's four post-closure classifications are refusals with
 * a pointer, not values.** "أما إذا كان الموضوع السابق مغلقًا، فيحدد هل
 * الجديد: تظلم · إعادة عرض · طلب جديد بسبب واقعة جديدة · استكمال لقرار سابق.
 * **ثم يصنف وفق طبيعته الصحيحة.**" In this system a تظلم is Track J's
 * `appeals` row and an إعادة عرض is Stage 66's reopen on the existing file —
 * neither is a new `requests` row at all — so choosing either is refused with
 * the correct route named. That refusal *is* the classification rule; letting
 * a تظلم through as a fresh request would be the duplication the appendix
 * exists to prevent, wearing a different label.
 */
class DuplicatePolicy
{
    /**
     * A file is "مفتوح" while it has not concluded.
     *
     * Deliberately the whole concluded set rather than only Stage 75's closure
     * record: a `cancelled` file never gets a closure card, and a
     * `not_approved` one that Art. 37 has not closed yet is finished as to its
     * subject — a new request about that subject is a fresh matter, which is
     * exactly the case the appendix's four classifications cover.
     */
    private const CONCLUDED_STATUSES = [
        'completed_closed', 'archived', 'cancelled',
        'not_approved', 'outside_jurisdiction', 'rejected',
        'decision_withdrawn', 'decision_amended',
    ];

    /** The appendix's own four, in its order. */
    public const RELATIONS = [
        'appeal' => ['ar' => 'تظلم من نتيجة سابقة', 'en' => 'Appeal against a previous result'],
        're_presentation' => ['ar' => 'إعادة عرض للموضوع نفسه', 'en' => 'Re-presentation of the same matter'],
        'new_incident' => ['ar' => 'طلب جديد بسبب واقعة جديدة', 'en' => 'A new request arising from a new fact'],
        'completion_of_previous' => ['ar' => 'استكمال لقرار سابق', 'en' => 'Completion of a previous decision'],
    ];

    /**
     * The two the appendix classifies as something this system already models
     * elsewhere, with the route each must take instead.
     *
     * @var array<string, string>
     */
    public const REDIRECTED_RELATIONS = [
        'appeal' => 'التظلم من نتيجة سابقة لا يقدم كمعاملة جديدة؛ يقيد كتظلم على المعاملة السابقة من شاشة التظلمات.',
        're_presentation' => 'إعادة عرض الموضوع نفسه لا تقدم كمعاملة جديدة؛ يعاد فتح المعاملة السابقة وفق أسباب إعادة العرض.',
    ];

    /** @return Collection<int, Request> */
    public function priorRequests(User $actor, int $requestTypeId): Collection
    {
        return Request::query()
            ->where('created_by_user_id', $actor->getKey())
            ->where('request_type_id', $requestTypeId)
            ->with(['status:id,code,name_ar,name_en', 'currentStage:id,code,name_ar,name_en'])
            ->orderByDesc('id')
            ->get();
    }

    /** The open file a new request on this subject would duplicate, if any. */
    public function openPriorRequest(User $actor, int $requestTypeId): ?Request
    {
        return $this->priorRequests($actor, $requestTypeId)
            ->first(fn (Request $prior) => ! $this->isConcluded($prior));
    }

    public function isConcluded(Request $requestRecord): bool
    {
        return in_array($requestRecord->status?->code, self::CONCLUDED_STATUSES, true);
    }

    /**
     * The Arabic reason this intake may not proceed, or null.
     *
     * Ordered the way the appendix reads: an open file refuses outright and
     * names the file to attach to, and only when every prior file is closed
     * does the classification question arise at all.
     *
     * @param  array<string, mixed>  $data  the validated intake payload
     */
    public function refusalReason(User $actor, array $data): ?string
    {
        $typeId = (int) ($data['request_type_id'] ?? 0);

        if ($typeId === 0) {
            return null;
        }

        $priors = $this->priorRequests($actor, $typeId);

        if ($priors->isEmpty()) {
            return null;
        }

        $open = $priors->first(fn (Request $prior) => ! $this->isConcluded($prior));

        if ($open !== null) {
            return 'يوجد ملف مفتوح لنفس الموضوع ('.$open->trackingNumber().
                ')؛ لا تنشأ معاملة جديدة، بل تلحق المستندات بالمعاملة القائمة.';
        }

        $relation = $data['prior_relation'] ?? null;

        if ($relation === null) {
            return 'سبق قيد معاملة لنفس الموضوع وأقفلت؛ يجب تحديد صفة الطلب الجديد قبل قيده.';
        }

        return self::REDIRECTED_RELATIONS[$relation] ?? null;
    }
}
