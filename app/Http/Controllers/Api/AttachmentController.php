<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\MeetingRequest;
use App\Models\Request;
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
                // Stage 80 — Appendix 14's folder for this document.
                'file_section' => $request->validated('file_section'),
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
