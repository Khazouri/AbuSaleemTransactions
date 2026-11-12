<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LearningCenter\UploadVideoRequest;
use App\Http\Resources\WalkthroughVideoResource;
use App\Models\User;
use App\Models\WalkthroughVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// Learning centre — narrated walkthroughs.
/**
 * Everyone with the screen watches what WalkthroughVideo::isVisibleTo() lets
 * them; R08 (the screen's `edit`) uploads. Playback uses a signed URL because
 * a <video> element cannot send the bearer token.
 */
class LearningCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canEdit = $user->hasScreenPermission('learning_center', 'can_edit');

        return response()->json([
            'data' => WalkthroughVideoResource::collection(WalkthroughVideo::visibleTo($user))->resolve(),
            'manage' => $canEdit
                ? WalkthroughVideoResource::collection(WalkthroughVideo::orderBy('sort_order')->get())->resolve()
                : null,
            'max_upload_bytes' => UploadVideoRequest::maxUploadBytes(),
        ]);
    }

    public function forScreen(Request $request, string $screenCode): JsonResponse
    {
        $videos = WalkthroughVideo::visibleTo($request->user())->where('screen_code', $screenCode)->values();

        return response()->json(['data' => WalkthroughVideoResource::collection($videos)->resolve()]);
    }

    public function upload(UploadVideoRequest $request, WalkthroughVideo $walkthroughVideo): WalkthroughVideoResource
    {
        $file = $request->file('file');
        $old = [$walkthroughVideo->disk, $walkthroughVideo->path];
        $path = $file->storeAs('walkthrough-videos', "{$walkthroughVideo->id}-".Str::uuid().'.mp4', 'local');

        $walkthroughVideo->forceFill([
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'video/mp4',
            'size_bytes' => $file->getSize(),
            'duration_seconds' => (int) $request->input('duration_seconds'),
            'uploaded_by' => $request->user()->id,
            'uploaded_at' => now(),
        ])->save();

        // Only once the row points at the new file: a failed save must not cost us the old clip.
        $this->forget(...$old);

        return new WalkthroughVideoResource($walkthroughVideo);
    }

    public function destroyFile(WalkthroughVideo $walkthroughVideo): WalkthroughVideoResource
    {
        $old = [$walkthroughVideo->disk, $walkthroughVideo->path];

        $walkthroughVideo->forceFill([
            'disk' => null, 'path' => null, 'mime_type' => null, 'size_bytes' => null,
            'duration_seconds' => null, 'uploaded_by' => null, 'uploaded_at' => null,
        ])->save();

        $this->forget(...$old);

        return new WalkthroughVideoResource($walkthroughVideo);
    }

    public function play(Request $request, WalkthroughVideo $walkthroughVideo): JsonResponse
    {
        abort_unless($walkthroughVideo->isVisibleTo($request->user()), 404);

        // Signed relative: behind a TLS-terminating proxy the request's scheme and
        // host differ from what the browser calls, which would break an absolute signature.
        $path = URL::temporarySignedRoute(
            'learning-center.stream',
            now()->addHours(2),
            ['walkthroughVideo' => $walkthroughVideo->id, 'user' => $request->user()->id],
            absolute: false,
        );

        return response()->json(['url' => url($path)]);
    }

    /**
     * Outside auth:sanctum (the signature is the credential). The grant is
     * re-read on every request, so a role removed after the URL was issued
     * stops the stream rather than waiting out the two hours.
     */
    public function stream(Request $request, WalkthroughVideo $walkthroughVideo): BinaryFileResponse
    {
        $user = User::find($request->query('user'));
        abort_unless($user && $user->is_active && $walkthroughVideo->isVisibleTo($user), 404);

        abort_unless(Storage::disk($walkthroughVideo->disk)->exists($walkthroughVideo->path), 404);

        return response()->file(
            Storage::disk($walkthroughVideo->disk)->path($walkthroughVideo->path),
            ['Content-Type' => 'video/mp4'],
        );
    }

    private function forget(?string $disk, ?string $path): void
    {
        if ($disk && $path) {
            Storage::disk($disk)->delete($path);
        }
    }
}
