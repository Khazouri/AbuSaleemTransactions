<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowVersionResource;
use App\Models\Request;
use App\Services\RequestVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;

/**
 * Workflow designer — slice 2. «عرض مسار العمل» on a request: the version that
 * request runs on, which after a later publish is no longer the newest one.
 * Same visibility rule as the request page itself, so it 404s exactly where
 * the page does.
 */
class RequestWorkflowController extends Controller
{
    public function show(HttpRequest $request, Request $requestRecord, RequestVisibility $visibility): JsonResponse
    {
        abort_unless(config('app.workflow_designer') && $visibility->canView($request->user(), $requestRecord), 404);

        return response()->json(['data' => [
            'current_stage_id' => $requestRecord->current_stage_id,
            'version' => new WorkflowVersionResource($requestRecord->resolveWorkflowVersion()->load(WorkflowVersionResource::WITH)),
        ]]);
    }
}
