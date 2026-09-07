<?php

namespace Tests;

use App\Services\RequestClosureService;

/**
 * Stage 75 — the Art. 37 closure card and Appendix 47 audit a request now
 * needs to reach code 20, for tests whose subject is something else (the
 * execution funnel, the appeal hold) but which have to get past
 * RequestClosureService to finish their own story.
 *
 * Tests whose subject IS the closure rules build their own deliberately
 * incomplete payloads instead — see RequestClosureTest.
 */
trait ClosesRequests
{
    /**
     * A complete, valid closure payload: Art. 37's card plus a clean sweep of
     * Appendix 47's ten closer-answered checks.
     *
     * @param  array<string, mixed>  $overrides  card-level overrides
     * @param  array<string, string>  $auditOverrides  per-check overrides
     * @return array<string, mixed>
     */
    protected function closurePayload(array $overrides = [], array $auditOverrides = []): array
    {
        $audit = array_fill_keys(RequestClosureService::CLOSER_CHECKS, 'yes');

        return [
            'final_decision_number' => 'PM-DEC/2026/001',
            'approving_body' => 'عميد البلدية',
            'execution_date' => now()->toDateString(),
            'executing_body' => 'إدارة الموارد البشرية',
            'file_storage_location' => 'أرشيف قسم شؤون الموظفين — خزانة 3',
            'audit' => [...$audit, ...$auditOverrides],
            ...$overrides,
        ];
    }
}
