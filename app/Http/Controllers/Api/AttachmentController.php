<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestType;
use App\Services\DocumentCompletenessService;
use App\Services\RequestVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Stage 12 private-file upload endpoint for an existing request. */
class AttachmentController extends Controller
{
    public function store(StoreAttachmentRequest $request, Request $requestRecord, RequestVisibility $visibility): JsonResponse
    {
        abort_unless($visibility->canView($request->user(), $requestRecord), 404);

        // Stage 82 — [D] Appendix 25's fifth stage: "ولا يجوز استمرار تعديل
        // الوقائع أو المستندات بعد بدء التصويت". Only while a vote on this
        // request is actually open — see MeetingRequest::openVoteExistsFor(),
        // which is deliberately not a permanent freeze.
        if (MeetingRequest::openVoteExistsFor($requestRecord->id)) {
            return response()->json([
                'message' => 'بدأ التصويت على هذا الموضوع في اللجنة، ولا يجوز إضافة مستندات قبل إثبات النتيجة.',
            ], 422);
        }

        $documentKey = $request->validated('required_document_key');

        // Stage 91 — a named matrix row's folder is DERIVED, and a submitted
        // one is overridden rather than trusted: the same discipline
        // IntakeGateService::derivedAnswers() and ExecutionSoundnessService
        // apply to their own derived answers, since a classification the
        // system can establish is not a human's to contradict. Only `other`
        // leaves the folder genuinely unanswered, which is why that is the one
        // case StoreAttachmentRequest still asks for it.
        $fileSection = $documentKey === RequestType::OTHER_DOCUMENT
            ? $request->validated('file_section')
            : $requestRecord->requestType?->sectionForDocument($documentKey) ?? Attachment::DEFAULT_SUBMITTER_SECTION;

        $file = $request->file('file');
        $disk = 'local';

        // Hash-based names prevent a user-supplied filename from becoming a
        // path, while a request directory keeps private storage inspectable.
        $path = $file->store("attachments/{$requestRecord->id}", $disk);

        try {
            $attachment = Attachment::create([
                'request_id' => $requestRecord->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'label' => $request->validated('label'),
                // Stage 91 — which of [D] Appendix 57's rows this document
                // answers, in the same IntakeGateService::documentKey() slug an
                // intake attachment carries. This one column is the whole
                // stage: without it a file supplied during استكمال النواقص is
                // invisible to the officer's completeness gate, which reads
                // exactly this.
                'required_document_key' => $documentKey,
                // Stage 80 — Appendix 14's folder for this document.
                'file_section' => $fileSection,
                'uploaded_by_user_id' => $request->user()->id,
            ]);
        } catch (Throwable $exception) {
            // Do not leave orphaned private files when metadata cannot persist.
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        return (new AttachmentResource($attachment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Stage 91 — what the upload form has to ask about one more document.
     *
     * The same [D] Appendix 57 matrix intake renders, keyed, plus the two
     * facts that make an استكمال upload actionable rather than merely
     * possible: which rows this file's own attachments already answer, and
     * which MANDATORY rows are still outstanding — in the appendix's own
     * order, because a reader told to bring three documents should be told
     * them in the order the matrix lists them.
     *
     * A lookup rather than a field on RequestDetailResource: both upload
     * callers hand the component only a request id, and the list payload stays
     * untouched (Stage 72's precedent). It rides the grant the upload itself
     * rides, since it exists to render that form and discloses nothing the
     * request workspace does not already show.
     */
    public function documentOptions(
        HttpRequest $request,
        Request $requestRecord,
        RequestVisibility $visibility,
        DocumentCompletenessService $completeness,
    ): JsonResponse {
        abort_unless($visibility->canView($request->user(), $requestRecord), 404);

        $requestRecord->loadMissing('requestType:id,required_documents');

        $type = $requestRecord->requestType;
        $coveredKeys = $completeness->coveredKeys($requestRecord);

        return response()->json([
            'data' => [
                'document_options' => collect($type?->documentOptions() ?? [])
                    ->map(fn (array $document, string $key): array => ['key' => $key, ...$document])
                    ->values(),
                'covered_keys' => $coveredKeys,
                'outstanding' => collect($completeness->uncovered($type, $coveredKeys))
                    ->map(fn (array $document, string $key): array => ['key' => $key, ...$document])
                    ->values(),
            ],
        ]);
    }

    /** Stream a private attachment only after both parent and child are verified. */
    public function preview(HttpRequest $request, Request $requestRecord, Attachment $attachment, RequestVisibility $visibility): StreamedResponse
    {
        abort_unless($attachment->request_id === $requestRecord->id, 404);
        abort_unless($visibility->canView($request->user(), $requestRecord), 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type],
            'inline',
        );
    }
}
