<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Transaction;
use App\Services\TransactionVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Stage 12 private-file upload endpoint for an existing transaction. */
class AttachmentController extends Controller
{
    public function store(StoreAttachmentRequest $request, Transaction $transaction, TransactionVisibility $visibility): JsonResponse
    {
        abort_unless($visibility->canView($request->user(), $transaction), 404);

        $file = $request->file('file');
        $disk = 'local';

        // Hash-based names prevent a user-supplied filename from becoming a
        // path, while a transaction directory keeps private storage inspectable.
        $path = $file->store("attachments/{$transaction->id}", $disk);

        try {
            $attachment = Attachment::create([
                'transaction_id' => $transaction->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'label' => $request->validated('label'),
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
    public function preview(Request $request, Transaction $transaction, Attachment $attachment, TransactionVisibility $visibility): StreamedResponse
    {
        abort_unless($attachment->transaction_id === $transaction->id, 404);
        abort_unless($visibility->canView($request->user(), $transaction), 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type],
            'inline',
        );
    }
}
