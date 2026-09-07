<?php

namespace Tests;

use App\Models\Attachment;
use App\Models\Request;
use App\Services\RequestExecutionService;

/**
 * Stage 76 — the النموذج 17 card, متابعة التنفيذ answers and Appendix 70
 * evidence a request now needs to reach code 19, for tests whose subject is
 * something else (the outputs funnel, the appeal hold) but which have to get
 * past RequestExecutionService to finish their own story.
 *
 * Tests whose subject IS the execution rules build their own deliberately
 * incomplete payloads instead — see RequestExecutionTest. Same split, and same
 * reason, as Stage 75's ClosesRequests.
 */
trait ExecutesRequests
{
    /**
     * A complete, valid execution payload, with a real attachment on the
     * request standing in for Appendix 70's دليل التنفيذ — the evidence has to
     * be an actual document, which is the whole point of the stage.
     *
     * @param  array<string, mixed>  $overrides  card-level overrides
     * @param  array<string, string>  $checklistOverrides  per-check overrides
     * @return array<string, mixed>
     */
    protected function executionPayload(
        Request $requestRecord,
        array $overrides = [],
        array $checklistOverrides = [],
    ): array {
        $checklist = array_fill_keys(RequestExecutionService::EXECUTOR_CHECKS, 'yes');

        return [
            'executing_body' => 'قسم شؤون الموظفين',
            'action_taken' => 'صدر القرار الإداري ونُفذ أثره اعتباراً من تاريخ السريان.',
            'effective_date' => now()->toDateString(),
            'approving_body' => 'عميد البلدية',
            'approval_number' => 'AP-2026-014',
            'approval_date' => now()->subDay()->toDateString(),
            'checklist' => [...$checklist, ...$checklistOverrides],
            'evidence' => [[
                'attachment_id' => $this->executionEvidenceAttachment($requestRecord)->id,
                'evidence_type' => 'administrative_decision',
            ]],
            ...$overrides,
        ];
    }

    /** A stored document on the request, to be nominated as دليل التنفيذ. */
    protected function executionEvidenceAttachment(Request $requestRecord): Attachment
    {
        return Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => "attachments/{$requestRecord->id}/execution-".uniqid().'.pdf',
            'original_name' => 'قرار إداري بالتنفيذ.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'label' => 'دليل التنفيذ',
        ]);
    }
}
