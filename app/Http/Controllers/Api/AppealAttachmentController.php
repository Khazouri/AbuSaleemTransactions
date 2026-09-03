<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appeal\StoreAppealAttachmentRequest;
use App\Http\Resources\AppealAttachmentResource;
use App\Models\Appeal;
use App\Models\AppealAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Stage 59, Track J — private supporting-document upload/preview for an
 * Appeal, mirroring AttachmentController's Stage 12 mechanism but scoped to
 * the appeal's own appellant (or R08), not RequestVisibility — an appeal's
 * documents belong to the appeal, not the original request's workspace.
 */
class AppealAttachmentController extends Controller
{
    public function store(StoreAppealAttachmentRequest $request, Appeal $appeal): JsonResponse
    {
        $this->authorizeAccess($request->user(), $appeal);

        $file = $request->file('file');
        $disk = 'local';

        $path = $file->store("appeal-attachments/{$appeal->id}", $disk);

        try {
            $attachment = AppealAttachment::create([
                'appeal_id' => $appeal->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'label' => $request->validated('label'),
                'uploaded_by_user_id' => $request->user()->id,
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        return (new AppealAttachmentResource($attachment))
            ->response()
            ->setStatusCode(201);
    }

    public function preview(HttpRequest $request, Appeal $appeal, AppealAttachment $attachment): StreamedResponse
    {
        $this->authorizeAccess($request->user(), $appeal);

        abort_unless($attachment->appeal_id === $appeal->id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type],
            'inline',
        );
    }

    /** Same scoping AppealController::index() already applies to the list. */
    private function authorizeAccess(User $actor, Appeal $appeal): void
    {
        $isOwner = $appeal->appellant_user_id === $actor->id;
        $isAdmin = $actor->roles()->where('code', 'R08')->exists();

        abort_unless($isOwner || $isAdmin, 404);
    }
}
