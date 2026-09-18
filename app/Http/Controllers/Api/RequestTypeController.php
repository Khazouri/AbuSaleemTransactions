<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestType\StoreRequestTypeRequest;
use App\Http\Requests\RequestType\UpdateRequestTypeRequest;
use App\Http\Resources\RequestTypeResource;
use App\Models\RequestType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD for the request-type catalogue (أنواع الطلبات).
 *
 * Request types are master data in the same sense departments are: created
 * rarely, referenced everywhere, and carrying rules other stages read — the
 * SLA that becomes a request's due_date (Stage 17), the decision grade that
 * forces ministry escalation (Stage 18), the default financial-impact flag
 * (Stage 47), and [D] Appendix 57's document matrix (Stage 72). So this
 * controller follows DepartmentController rather than a plain apiResource:
 * deactivate instead of delete, and refuse a delete that would erase history.
 */
class RequestTypeController extends Controller
{
    /**
     * Every type as a flat list, inactive rows included.
     *
     * Unpaginated on purpose: this is a dozen rows of catalogue data, and the
     * screen needs all of it at once to show which types are retired.
     */
    public function index(): AnonymousResourceCollection
    {
        $types = RequestType::query()
            // Counts rather than the relations themselves — the screen only
            // needs to know whether a delete would be refused, and why.
            ->withCount(['requests', 'workflowTransitions'])
            ->orderBy('name_ar')
            ->get();

        return RequestTypeResource::collection($types);
    }

    public function store(StoreRequestTypeRequest $request): JsonResponse
    {
        $type = RequestType::create($request->validated());

        // loadCount so the response matches index()'s shape and the SPA can
        // drop the new row straight into its list.
        return (new RequestTypeResource($type->loadCount(['requests', 'workflowTransitions'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateRequestTypeRequest $request, RequestType $requestType): RequestTypeResource
    {
        $requestType->update($request->validated());

        return new RequestTypeResource($requestType->loadCount(['requests', 'workflowTransitions']));
    }

    /**
     * Flip is_active (تفعيل / تعطيل).
     *
     * The normal way to retire a type. It disappears from intake — the type
     * picker reads active rows only — while every request already filed under
     * it keeps resolving, which a delete could not offer.
     */
    public function toggleActive(RequestType $requestType): RequestTypeResource
    {
        $requestType->update(['is_active' => ! $requestType->is_active]);

        return new RequestTypeResource($requestType->loadCount(['requests', 'workflowTransitions']));
    }

    /**
     * Delete a type, but only one nothing references.
     *
     * The two guards below are not defensive: each FK pointing here fails
     * QUIETLY in its own way, which is precisely why the refusal has to
     * happen at this layer.
     *
     *   - requests.request_type_id is nullOnDelete, so deleting a referenced
     *     type would strip the type off every historical request. Nothing
     *     would error; a report grouped by type would simply lose them.
     *   - workflow_transitions.request_type_id is cascadeOnDelete, so the
     *     same delete would take that type's own workflow overrides with it
     *     without anyone being told.
     *
     * 422, not 403: the caller is allowed to do this, the data just isn't in
     * a state that permits it — and the message names which of the two
     * blocked it, since the remedies are different.
     */
    public function destroy(RequestType $requestType): JsonResponse
    {
        if ($requestType->requests()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف نوع طلب مرتبط بطلبات قائمة. عطّل النوع بدلاً من حذفه ليختفي من نموذج التقديم مع بقاء الطلبات السابقة سليمة.',
            ], 422);
        }

        if ($requestType->workflowTransitions()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف نوع طلب له قواعد سير عمل خاصة به. احذف تلك القواعد أولاً أو عطّل النوع.',
            ], 422);
        }

        $requestType->delete();

        return response()->json(null, 204);
    }
}
