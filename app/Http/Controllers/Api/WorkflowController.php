<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowResource;
use App\Http\Resources\WorkflowVersionResource;
use App\Models\RequestStatus;
use App\Models\Role;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Workflow designer — slice 2: the read side of the designer screen. */
class WorkflowController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $label = ['id', 'code', 'name_ar', 'name_en'];

        return WorkflowResource::collection(
            Workflow::query()
                ->with([
                    'versions' => fn ($versions) => $versions->withCount('stages')->orderByDesc('number'),
                    'requestTypes:id,workflow_id,code,name_ar,name_en',
                ])
                ->orderBy('id')
                ->get(),
        )->additional([
            // Workflow designer — slice 3. What a step or an outcome may name:
            // read here, because a designer need not hold the Roles screen.
            'options' => [
                'roles' => Role::query()->orderBy('code')->get($label),
                'statuses' => RequestStatus::query()->orderBy('id')->get($label),
            ],
        ]);
    }

    public function showVersion(WorkflowVersion $workflowVersion): WorkflowVersionResource
    {
        return new WorkflowVersionResource($workflowVersion->load(WorkflowVersionResource::WITH));
    }
}
