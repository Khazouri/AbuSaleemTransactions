<?php

namespace Tests;

use App\Models\Attachment;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\Request;
use App\Models\RequestType;
use App\Models\User;
use App\Services\DocumentCompletenessService;
use App\Services\ExecutionSoundnessService;
use App\Services\IntakeGateService;
use App\Services\MeetingMinutesCompiler;
use App\Services\MinutesQualityRules;
use Illuminate\Http\UploadedFile;

/**
 * Stage 78 — what a request and a محضر now need to get past [D] Appendix 63's
 * control gates, for tests whose subject is something else (the approval
 * chain, the outputs funnel, the minutes lifecycle) but which have to walk
 * through a gate to finish their own story.
 *
 * Tests whose subject IS the gate rules build their own deliberately
 * incomplete payloads instead — see ControlGateTest. Same split, and same
 * reason, as Stage 75's ClosesRequests and Stage 76's ExecutesRequests.
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
     * Answer Appendix 63's بوابة 1 for this request's own Appendix 57 matrix,
     * every document present.
     *
     * Written directly rather than through the endpoint because the callers
     * are mid-walk through a different story; ControlGateTest exercises the
     * endpoint itself.
     */
    protected function passIntakeGate(Request $requestRecord): Request
    {
        $gate = app(IntakeGateService::class);
        $answers = array_fill_keys(array_keys($gate->requiredDocuments($requestRecord)), 'present');

        $requestRecord->forceFill([
            'intake_gate' => $gate->record($requestRecord, $answers, true),
            'intake_gate_checked_at' => now(),
        ])->save();

        return $requestRecord->refresh();
    }

    /**
     * Art. 103's four attested checks, all `yes`.
     *
     * The other eight are derived from real state and are written by the
     * service, so a fixture that has not actually reached a signed محضر and a
     * completed approval will still — correctly — be refused. That is the
     * point of the gate, and a caller that needs to get past it has to build a
     * complete file rather than tick a box.
     *
     * @return array<string, string>
     */
    protected function soundnessPayload(array $overrides = []): array
    {
        return ['checks' => array_merge(
            array_fill_keys(ExecutionSoundnessService::CERTIFIER_CHECKS, 'yes'),
            $overrides,
        )];
    }

    /**
     * Drive a fixture meeting all the way to an approved محضر.
     *
     * Art. 103's توقيع المحضر reads the real document, so a fixture that needs
     * to refer a result to execution has to actually produce one. Written
     * through the compiler and the model rather than the endpoints because the
     * callers are mid-walk through a different story; MeetingMinutesTest
     * exercises the lifecycle itself.
     *
     * @param  list<User>  $signers
     */
    protected function approveMinutes(Meeting $meeting, User $reviewer, array $signers): MeetingMinutes
    {
        $minutes = MeetingMinutes::create([
            'meeting_id' => $meeting->id,
            'content' => app(MeetingMinutesCompiler::class)->compile($meeting),
            'status' => MeetingMinutes::STATUS_APPROVED,
            'generated_by_user_id' => $reviewer->id,
            'generated_at' => now(),
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'approved_at' => now(),
        ]);

        foreach ($signers as $signer) {
            $minutes->signatures()->create([
                'user_id' => $signer->id,
                'signature_path' => 'meeting-minutes/fixture.png',
                'signed_at' => now(),
            ]);
        }

        return $minutes;
    }

    /**
     * Record Art. 103's checklist for a request whose file is already complete.
     *
     * Deliberately goes through the service rather than writing the twelve
     * answers by hand: the eight derived ones are read from real state either
     * way, so a fixture that has *not* built a complete file is still — and
     * correctly — refused at the gate.
     */
    protected function certifySoundness(Request $requestRecord): Request
    {
        $requestRecord->forceFill([
            'execution_soundness' => app(ExecutionSoundnessService::class)->record(
                $requestRecord,
                array_fill_keys(ExecutionSoundnessService::CERTIFIER_CHECKS, 'yes'),
            ),
            'execution_soundness_checked_at' => now(),
        ])->save();

        return $requestRecord->refresh();
    }

    /**
     * Appendix 8's one reviewer-answered check, for a محضر approval.
     *
     * The other fifteen are not sent at all: fourteen are derived and the
     * sixteenth is enforced by the signature lifecycle, so a caller cannot
     * supply them here even to get past the gate.
     *
     * @return array<string, mixed>
     */
    protected function minutesApprovalPayload(array $overrides = []): array
    {
        return array_merge([
            'decision' => 'approve',
            'quality_checks' => [MinutesQualityRules::REVIEWER_CHECKS[0] => true],
        ], $overrides);
    }
}
