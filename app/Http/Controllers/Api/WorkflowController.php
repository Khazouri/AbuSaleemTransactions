<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowResource;
use App\Http\Resources\WorkflowVersionResource;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Workflow designer — slice 2: the read side of the designer screen. */
class WorkflowController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return WorkflowResource::collection(
            Workflow::query()
                ->with(['versions' => fn ($versions) => $versions->withCount('stages')->orderByDesc('number')])
                ->orderBy('id')
                ->get(),
        );
    }

    public function showVersion(WorkflowVersion $workflowVersion): WorkflowVersionResource
    {
        return new WorkflowVersionResource($workflowVersion->load(WorkflowVersionResource::WITH));
    }
}
