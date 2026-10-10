<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\SaveWorkflowDraftRequest;
use App\Http\Requests\Workflow\StoreWorkflowRequest;
use App\Http\Resources\WorkflowResource;
use App\Http\Resources\WorkflowVersionResource;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use App\Models\WorkflowVersion;
use App\Services\WorkflowDraftWriter;
use App\Services\WorkflowVersionValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Workflow designer — slice 2: the read side of the designer screen; slice 3: drafts. */
class WorkflowController extends Controller
{
    public const DRAFT_EXISTS = 'لهذا المسار مسودة قائمة؛ افتحها أو احذفها أولًا.';

    public const HAS_ERRORS = 'لا يمكن نشر المسودة قبل إصلاح أخطائها.';

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
                // Workflow designer — slice 4. What an attachment item may name.
                // Keys are per type (index + label hash), so the same document
                // can appear under several; each is listed once with its types,
                // and the editor offers the ones the workflow's own types use.
                'documents' => RequestType::query()->where('is_active', true)->orderBy('id')->get()
                    ->flatMap(fn (RequestType $type) => collect($type->documentOptions())
                        ->map(fn (array $document, string $key) => ['key' => $key, 'ar' => $document['ar'], 'en' => $document['en'], 'type_id' => $type->id])
                        ->values())
                    ->groupBy('key')
                    ->map(fn ($rows, string $key) => [
                        'key' => $key,
                        'ar' => $rows->first()['ar'],
                        'en' => $rows->first()['en'],
                        'type_ids' => $rows->pluck('type_id')->values(),
                    ])
                    ->values(),
            ],
        ]);
    }

    public function showVersion(WorkflowVersion $workflowVersion): WorkflowVersionResource
    {
        return new WorkflowVersionResource($workflowVersion->load(WorkflowVersionResource::WITH));
    }

    // Workflow designer — slice 3. A new named workflow, its v1 a draft copy of a settled version.
    public function store(StoreWorkflowRequest $request): JsonResponse
    {
        $data = $request->validated();
        $draft = DB::transaction(function () use ($data) {
            $workflow = Workflow::create(collect($data)->only(['code', 'name_ar', 'name_en'])->all());

            return WorkflowVersion::query()->findOrFail($data['from_version_id'])->cloneAsDraft($workflow);
        });

        return $this->created($draft);
    }

    // Workflow designer — slice 3. One draft per workflow; the row lock stops two clones racing on number.
    public function cloneVersion(WorkflowVersion $workflowVersion): JsonResponse
    {
        $draft = DB::transaction(function () use ($workflowVersion) {
            Workflow::query()->lockForUpdate()->findOrFail($workflowVersion->workflow_id);

            if (WorkflowVersion::query()->where('workflow_id', $workflowVersion->workflow_id)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['version' => [self::DRAFT_EXISTS]]);
            }

            return $workflowVersion->cloneAsDraft();
        });

        return $this->created($draft);
    }

    // Workflow designer — slice 3. The whole draft graph; see WorkflowDraftWriter.
    public function updateVersion(SaveWorkflowDraftRequest $request, WorkflowVersion $workflowVersion, WorkflowDraftWriter $writer): WorkflowVersionResource
    {
        $this->refuseUnlessDraft($workflowVersion);
        $writer->save($workflowVersion, $request->validated());

        return new WorkflowVersionResource($workflowVersion->fresh()->load(WorkflowVersionResource::WITH));
    }

    /**
     * Workflow designer — slice 3. Retire and publish in one transaction under
     * the workflow's lock: between the two, Workflow::publishedVersion would
     * be null and intake would fail.
     */
    public function publishVersion(HttpRequest $request, WorkflowVersion $workflowVersion, WorkflowVersionValidator $validator): JsonResponse|WorkflowVersionResource
    {
        $report = DB::transaction(function () use ($request, $workflowVersion, $validator) {
            Workflow::query()->lockForUpdate()->findOrFail($workflowVersion->workflow_id);
            // The version's own lock too: it waits out a save in progress
            // (WorkflowDraftWriter holds it), so what is checked is what goes live.
            $draft = WorkflowVersion::query()->lockForUpdate()->findOrFail($workflowVersion->id);
            $this->refuseUnlessDraft($draft);

            $report = $validator->report($draft);
            if ($report['errors'] === []) {
                WorkflowVersion::query()->where('workflow_id', $draft->workflow_id)->where('status', 'published')
                    ->update(['status' => 'retired']);
                $draft->update(['status' => 'published', 'published_at' => now(), 'published_by_user_id' => $request->user()->id]);
            }

            return $report;
        });

        if ($report['errors'] !== []) {
            return response()->json(['message' => self::HAS_ERRORS, 'errors' => ['version' => [self::HAS_ERRORS]], 'report' => $report], 422);
        }

        return new WorkflowVersionResource($workflowVersion->fresh()->load(WorkflowVersionResource::WITH));
    }

    // Workflow designer — slice 3. Discarding a draft. No request ever runs on one, so nothing is orphaned.
    public function destroyVersion(WorkflowVersion $workflowVersion): Response
    {
        // Checked under the workflow's lock, which publish also takes: a
        // discard must never delete a version that was published meanwhile.
        DB::transaction(function () use ($workflowVersion) {
            Workflow::query()->lockForUpdate()->findOrFail($workflowVersion->workflow_id);
            $this->refuseUnlessDraft(WorkflowVersion::query()->lockForUpdate()->findOrFail($workflowVersion->id));

            $stageIds = $workflowVersion->stages()->pluck('id');
            WorkflowTransition::query()->whereIn('from_stage_id', $stageIds)->delete();
            WorkflowStage::query()->whereKey($stageIds)->delete();
            $workflowVersion->delete();
        });

        return response()->noContent();
    }

    private function created(WorkflowVersion $draft): JsonResponse
    {
        return (new WorkflowVersionResource($draft->load(WorkflowVersionResource::WITH)))->response()->setStatusCode(201);
    }

    /**
     * A state rule, not a permission: answered as a 422 on `version` so the
     * screen can say why, rather than a 403 that reads as "not allowed".
     */
    private function refuseUnlessDraft(WorkflowVersion $version): void
    {
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages(['version' => [WorkflowVersion::NOT_A_DRAFT]]);
        }
    }
}
