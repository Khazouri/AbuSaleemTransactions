<?php

namespace App\Services;

use App\Models\Workflow;

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

    /**
     * The published Standard version's stage count, never hard-coded — see
     * WorkflowStageSeeder. One version only: a cloned draft must not double
     * the denominator (workflow designer — slice 1; per-version counts are
     * slice 3's, when a request type can run on another workflow).
     */
    public function total(): int
    {
        return $this->total ??= Workflow::standard()->publishedVersion->stages()->count();
    }
}
