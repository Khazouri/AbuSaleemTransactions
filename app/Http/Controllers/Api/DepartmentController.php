<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD for the department tree (الإدارات).
 *
 * Departments are master data: created rarely, referenced everywhere
 * (users, transactions, reference numbers). So this leans towards preserving
 * records — deactivate rather than delete, and refuse deletes that would
 * strand children or staff.
 */
class DepartmentController extends Controller
{
    /**
     * Every department as a flat list; the SPA builds the tree.
     *
     * Deliberately unpaginated: an org chart is small, and the tree can't be
     * assembled from a partial list — a child whose parent fell on page two
     * would have nowhere to attach.
     */
    public function index(): AnonymousResourceCollection
    {
        $departments = Department::query()
            // Two aggregate subqueries instead of loading the relations —
            // the UI only needs the counts, to decide whether deletion is safe.
            ->withCount(['users', 'children'])
            ->orderBy('name_ar')
            ->get();

        return DepartmentResource::collection($departments);
    }

    /**
     * Create a department. Returns 201 with the new row.
     */
    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::create($request->validated());

        // loadCount so the response matches the shape index() returns and the
        // SPA can drop it straight into its list.
        return (new DepartmentResource($department->loadCount(['users', 'children'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a department, including moving it to a different parent.
     * The cycle check lives in UpdateDepartmentRequest.
     */
    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        $department->update($request->validated());

        return new DepartmentResource($department->loadCount(['users', 'children']));
    }

    /**
     * Flip is_active (تفعيل / تعطيل).
     *
     * The normal way to retire a department: it stops being offered for new
     * assignments while every historical reference to it still resolves.
     */
    public function toggleActive(Department $department): DepartmentResource
    {
        $department->update(['is_active' => ! $department->is_active]);

        return new DepartmentResource($department->loadCount(['users', 'children']));
    }

    /**
     * Soft-delete a department.
     *
     * Blocked while it still has sub-departments or staff. Soft deletes don't
     * cascade, so removing a parent would leave its children pointing at a
     * hidden row — present in the table but absent from the tree, and
     * invisible in the UI. Forcing the caller to empty it first keeps the
     * tree consistent.
     *
     * 422 (not 403): the request is understood and permitted, the data just
     * isn't in a state that allows it.
     */
    public function destroy(Department $department): JsonResponse
    {
        if ($department->children()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف إدارة تحتوي على إدارات فرعية. انقل أو احذف الإدارات الفرعية أولاً.',
            ], 422);
        }

        if ($department->users()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف إدارة مرتبطة بمستخدمين. انقل المستخدمين إلى إدارة أخرى أولاً.',
            ], 422);
        }

        $department->delete();

        return response()->json(null, 204);
    }
}
