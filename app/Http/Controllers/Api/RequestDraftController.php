<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestDraft\SaveRequestDraftRequest;
use App\Http\Requests\RequestDraft\StoreRequestDraftAttachmentRequest;
use App\Http\Resources\RequestDraftAttachmentResource;
use App\Http\Resources\RequestDraftResource;
use App\Models\RequestDraft;
use App\Models\RequestDraftAttachment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Stage 88 — an intake that can be put down and picked up.
 *
 * RequestIntakeView.vue held everything in plain refs, so a refresh lost every
 * typed field and every chosen file. Fields are autosaved here and files are
 * uploaded as they are chosen, because a browser cannot reconstruct a File
 * across a refresh — and having them on the server is also what lets [G]'s
 * review step show each one back beside the document row it declares.
 *
 * NOTHING HERE TOUCHES `requests`. A draft has no receipt, no status, no
 * stage, no history and no reference number, so it cannot appear in a workflow
 * queue, a visibility scope or a register — by construction rather than by any
 * filter, which is the stage's own load-bearing rule.
 *
 * Every route rides `request_intake,edit`, the grant Stage 88 found seeded and
 * unconsumed (R01/R02/R05 — the employee, the case officer, the admin
 * manager). R03/R04/R06 hold `add` without it, so DRAFTS ARE AN ADDED
 * CAPABILITY, NOT A NEW REQUIREMENT: the intake screen keeps its in-memory
 * behaviour for anyone without `edit` and nobody loses the ability to file.
 * Creating a draft rides `edit` too rather than `add`, since a draft somebody
 * can create and never update is worse than no draft at all.
 */
class RequestDraftController extends Controller
{
    /**
     * Mirrors StoreRequest's own `attachments` cap.
     *
     * Copied rather than referenced for the same reason the mime and size
     * rules are: a draft that could hold more files than submission accepts
     * would be a draft that can never be sent.
     */
    private const MAX_ATTACHMENTS = 30;

    /** The caller's own unsent intakes, newest first. */
    public function index(HttpRequest $request): AnonymousResourceCollection
    {
        $drafts = RequestDraft::query()
            ->where('created_by_user_id', $request->user()->id)
            ->withCount('attachments')
            ->orderByDesc('updated_at')
            ->get();

        return RequestDraftResource::collection($drafts);
    }

    public function store(SaveRequestDraftRequest $request): JsonResponse
    {
        $draft = RequestDraft::create([
            'created_by_user_id' => $request->user()->id,
            'payload' => $request->validated(),
        ]);

        return (new RequestDraftResource($draft->load('attachments')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(HttpRequest $request, RequestDraft $draft): RequestDraftResource
    {
        $this->authorizeOwner($request->user(), $draft);

        return new RequestDraftResource($draft->load('attachments'));
    }

    /**
     * Replace the saved fields wholesale.
     *
     * A replace rather than a merge: the payload IS the form's current state,
     * so a field the employee cleared has to come back cleared. Merging would
     * make emptying a field impossible to save.
     */
    public function update(SaveRequestDraftRequest $request, RequestDraft $draft): RequestDraftResource
    {
        $this->authorizeOwner($request->user(), $draft);

        $draft->update(['payload' => $request->validated()]);

        return new RequestDraftResource($draft->load('attachments'));
    }

    public function destroy(HttpRequest $request, RequestDraft $draft): JsonResponse
    {
        $this->authorizeOwner($request->user(), $draft);

        $this->deleteStoredFiles($draft);
        $draft->delete();

        return response()->json(null, 204);
    }

    public function storeAttachment(
        StoreRequestDraftAttachmentRequest $request,
        RequestDraft $draft,
    ): JsonResponse {
        $this->authorizeOwner($request->user(), $draft);

        if ($draft->attachments()->count() >= self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages([
                'file' => ['لا يمكن إرفاق أكثر من '.self::MAX_ATTACHMENTS.' ملفاً.'],
            ]);
        }

        $file = $request->file('file');
        $disk = 'local';

        // Hash-based names keep a user-supplied filename from becoming a path;
        // the draft directory keeps private storage inspectable and makes the
        // whole draft's residue removable in one call.
        $path = $file->store("request-drafts/{$draft->id}", $disk);

        try {
            $attachment = RequestDraftAttachment::create([
                'request_draft_id' => $draft->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'label' => $request->validated('label'),
                'required_document_key' => $request->validated('required_document_key'),
            ]);
        } catch (Throwable $exception) {
            // Do not leave an orphaned private file when metadata cannot
            // persist — the same compensation AttachmentController applies.
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        // The draft itself has not changed, but it HAS been worked on, and the
        // resume list orders by that — a draft whose only recent activity was
        // an upload should not sink to the bottom of it.
        $draft->touch();

        return (new RequestDraftAttachmentResource($attachment))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Change what one already-uploaded file declares.
     *
     * Only the two answers about the file, never the file itself: re-uploading
     * is how a document is replaced, which keeps the stored bytes and the row
     * describing them from ever disagreeing.
     */
    public function updateAttachment(
        HttpRequest $request,
        RequestDraft $draft,
        RequestDraftAttachment $draftAttachment,
    ): RequestDraftAttachmentResource {
        $this->authorizeOwner($request->user(), $draft);
        abort_unless($draftAttachment->request_draft_id === $draft->id, 404);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'required_document_key' => ['nullable', 'string', 'max:255'],
        ]);

        $draftAttachment->update($validated);
        $draft->touch();

        return new RequestDraftAttachmentResource($draftAttachment);
    }

    public function destroyAttachment(
        HttpRequest $request,
        RequestDraft $draft,
        RequestDraftAttachment $draftAttachment,
    ): JsonResponse {
        $this->authorizeOwner($request->user(), $draft);
        abort_unless($draftAttachment->request_draft_id === $draft->id, 404);

        Storage::disk($draftAttachment->disk)->delete($draftAttachment->path);
        $draftAttachment->delete();
        $draft->touch();

        return response()->json(null, 204);
    }

    /** Stream a draft's own private file, for the review step. */
    public function previewAttachment(
        HttpRequest $request,
        RequestDraft $draft,
        RequestDraftAttachment $draftAttachment,
    ): StreamedResponse {
        $this->authorizeOwner($request->user(), $draft);
        abort_unless($draftAttachment->request_draft_id === $draft->id, 404);
        abort_unless(Storage::disk($draftAttachment->disk)->exists($draftAttachment->path), 404);

        return Storage::disk($draftAttachment->disk)->response(
            $draftAttachment->path,
            $draftAttachment->original_name,
            ['Content-Type' => $draftAttachment->mime_type],
            'inline',
        );
    }

    /**
     * A draft is personal working state, so it is visible to its author alone.
     *
     * Not RequestVisibility: that service answers who may see a FILED request
     * inside the workflow, and an unsent form has no workflow to be assigned
     * within. 404 rather than 403, matching every other per-row refusal here —
     * a 403 would confirm that another employee's draft exists.
     */
    private function authorizeOwner(User $actor, RequestDraft $draft): void
    {
        abort_unless($draft->created_by_user_id === $actor->id, 404);
    }

    /** Remove a draft's whole private directory along with its rows. */
    private function deleteStoredFiles(RequestDraft $draft): void
    {
        Storage::disk('local')->deleteDirectory("request-drafts/{$draft->id}");
    }
}
