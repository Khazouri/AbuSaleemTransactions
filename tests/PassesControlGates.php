<?php

namespace Tests;

use App\Models\Attachment;
use App\Models\Request;
use App\Models\RequestType;
use App\Models\User;
use App\Services\DocumentCompletenessService;
use App\Services\DocumentReview;
use App\Services\EmploymentFilePreparationService;
use App\Services\IntakeGateService;
use Illuminate\Http\UploadedFile;

/**
 * Stage 78 — what a request now needs to get past [D] Appendix 63's control
 * gates, for tests whose subject is something else (the approval chain, the
 * committee path) but which have to walk through a gate to finish their own
 * story.
 *
 * Tests whose subject IS the gate rules build their own deliberately
 * incomplete payloads instead — see ControlGateTest. Same split, and same
 * reason, as Stage 75's ClosesRequests.
 */
trait PassesControlGates
{
    /**
     * Stage 85 — an intake payload's `attachments` array covering every
     * mandatory [D] Appendix 57 row of the chosen type.
     *
     * For tests that POST /api/requests to get a request to exist at all. The
     * caller needs Storage::fake('local'), since these are real uploads that
     * store() writes to disk.
     *
     * @return list<array{file: UploadedFile, required_document_key: string}>
     */
    protected function mandatoryAttachments(RequestType $type): array
    {
        $keys = array_keys(app(DocumentCompletenessService::class)->mandatoryDocuments($type));

        return array_map(fn (int $index, string $key): array => [
            'file' => UploadedFile::fake()->create("required-{$index}.pdf", 20, 'application/pdf'),
            'required_document_key' => $key,
        ], array_keys($keys), $keys);
    }

    /**
     * Stage 85 — give a request one attachment per mandatory [D] Appendix 57
     * row of its own type.
     *
     * Since Stage 85 a request cannot be filed without them, cannot reach an
     * agenda without them, and a meeting carrying one that lacks them cannot
     * convene — so a fixture built with Request::create() (which bypasses
     * intake validation entirely) has to be given them before it can finish a
     * story about something else. Rows are written straight to `attachments`
     * with no stored file: the completeness rule reads
     * `required_document_key` and nothing else, and a fixture that needs a
     * real file on disk says so itself.
     *
     * Tests whose subject IS the completeness rule build their own
     * deliberately incomplete files instead — see DocumentCompletenessTest.
     *
     * @return list<Attachment>
     */
    protected function supplyRequiredDocuments(Request $requestRecord, ?User $uploader = null): array
    {
        $requestRecord->loadMissing('requestType');

        $mandatory = app(DocumentCompletenessService::class)
            ->mandatoryDocuments($requestRecord->requestType);

        $created = [];
        foreach (array_keys($mandatory) as $index => $key) {
            $created[] = Attachment::create([
                'request_id' => $requestRecord->id,
                'disk' => 'local',
                'path' => "attachments/{$requestRecord->id}/fixture-{$index}.pdf",
                'original_name' => "fixture-{$index}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 1024,
                'required_document_key' => $key,
                'file_section' => $requestRecord->requestType?->sectionForDocument($key)
                    ?? Attachment::DEFAULT_SUBMITTER_SECTION,
                'uploaded_by_user_id' => $uploader?->id ?? $requestRecord->created_by_user_id,
            ]);
        }

        $requestRecord->unsetRelation('attachments');

        return $created;
    }

    /**
     * Answer Appendix 63's بوابة 1 with every attached file approved.
     *
     * Written directly rather than through the endpoint because the callers
     * are mid-walk through a different story, so no preview is needed either;
     * DocumentReviewTest exercises the endpoint and the preview rule.
     */
    protected function passIntakeGate(Request $requestRecord): Request
    {
        $requestRecord->forceFill([
            'intake_gate' => app(IntakeGateService::class)->record($requestRecord, $this->approveEveryFile($requestRecord), true),
            'intake_gate_checked_at' => now(),
        ])->save();

        return $requestRecord->refresh();
    }

    /**
     * Stage 98 — record [D] Appendix 6 row 3's تجهيز الملف الوظيفي with every
     * attached file approved, for the same reason passIntakeGate() above
     * writes directly. EmploymentFilePreparationTest exercises the endpoint.
     */
    protected function prepareEmploymentFile(Request $requestRecord): Request
    {
        $requestRecord->forceFill([
            'employment_file' => app(EmploymentFilePreparationService::class)
                ->record($requestRecord, $this->approveEveryFile($requestRecord), true, true),
            'employment_file_prepared_at' => now(),
        ])->save();

        return $requestRecord->refresh();
    }

    /** @return array<int, string> */
    protected function approveEveryFile(Request $requestRecord): array
    {
        return app(DocumentReview::class)->attachments($requestRecord)
            ->mapWithKeys(fn (Attachment $file): array => [$file->id => 'approved'])
            ->all();
    }
}
