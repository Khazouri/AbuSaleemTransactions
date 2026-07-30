<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attachment\StoreAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Stage 12 private-file upload endpoint for an existing transaction. */
class AttachmentController extends Controller
{
    public function store(StoreAttachmentRequest $request, Transaction $transaction): JsonResponse
    {
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
}
