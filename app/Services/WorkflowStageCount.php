<?php

namespace App\Services;

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
    private ?int $total = null;

    /** The live row count, never hard-coded — see WorkflowStageSeeder. */
    public function total(): int
    {
        return $this->total ??= WorkflowStage::query()->count();
    }
}
