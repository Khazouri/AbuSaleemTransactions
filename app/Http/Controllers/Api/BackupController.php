<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backup\StoreBackupRequest;
use App\Http\Resources\BackupResource;
use App\Models\Backup;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Stage 26 — the backup screen: take a snapshot, list what exists, fetch one,
 * delete one.
 *
 * The four verbs map onto the four grants the `backup` screen was seeded with
 * (R08 only, on everything). There is deliberately no restore endpoint —
 * reloading the database is an operation an administrator performs on the
 * server, with the system stopped, not something reachable from a browser
 * session.
 */
class BackupController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $backups = Backup::query()
            ->with('createdBy:id,name')
            ->latest()
            ->paginate(20);

        return BackupResource::collection($backups);
    }

    /** Runs the dump inline: an admin who clicks "back up now" wants the result. */
    public function store(StoreBackupRequest $request, BackupService $backups): JsonResponse
    {
        try {
            $backup = $backups->create(
                actor: $request->user(),
                includeFiles: $request->includeFiles(),
            );
        } catch (Throwable $exception) {
            // The service has already recorded a `failed` row, so reloading the
            // list shows what went wrong next to when it was attempted.
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new BackupResource($backup->load('createdBy:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Streams the archive rather than reading it into memory — a snapshot with
     * attachments in it is comfortably larger than the PHP memory limit.
     */
    public function download(Backup $backup): StreamedResponse
    {
        abort_unless($backup->fileExists(), 404, 'لم يعد ملف النسخة الاحتياطية موجوداً.');

        return Storage::disk($backup->disk)->download($backup->path, $backup->filename);
    }

    /** Removes the archive and its row together. */
    public function destroy(Backup $backup): JsonResponse
    {
        $backup->deleteFile();
        $backup->delete();

        return response()->json(['message' => 'تم حذف النسخة الاحتياطية.']);
    }
}
