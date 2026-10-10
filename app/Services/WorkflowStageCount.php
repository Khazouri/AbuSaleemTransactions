<?php

namespace App\Services;

use App\Models\Workflow;
use App\Models\WorkflowStage;

/**
 * Stage 93 — the denominator for the employee-facing "step N of TOTAL"
 * figure (see RequestResource::toArray()'s `stage_progress`, which carries
 * the reconciliation decision itself — this class is deliberately just the
 * memoised count behind it).
 *
 * A container singleton rather than a class-level static: PHPUnit runs the
 * whole suite in one process, and a raw static would leak a count cached by
 * an early test into a later one whose fixture seeds a different number of
 * `workflow_stages` rows. `$this->app` — and therefore any singleton hung
 * off it — is rebuilt fresh for every test method, so the memo can never
 * outlive the data it was built from.
 */
class WorkflowStageCount
{
    /** @var array<int, int> stage id (0 = none) => count */
    private array $totals = [];

    /**
     * The published Standard version's stage count, never hard-coded — see
     * WorkflowStageSeeder. Workflow designer — slice 3: given a stage, the
     * count of that stage's own version, since versions differ in length (a
     * custom step adds one); without one, the published Standard version,
     * which is what reports divide by.
     */
    public function total(?int $stageId = null): int
    {
        return $this->totals[$stageId ?? 0] ??= $stageId === null
            ? Workflow::standard()->publishedVersion->stages()->count()
            : WorkflowStage::query()
                ->where('workflow_version_id', WorkflowStage::query()->whereKey($stageId)->select('workflow_version_id'))
                ->count();
    }
}
