<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\IndexNotificationRequest;
use App\Http\Requests\Notification\UpdateNotificationSettingsRequest;
use App\Http\Resources\NotificationResource;
use App\Models\NotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Stage 23 — the bell, the list, and the preferences behind them.
 *
 * Every method here is scoped to $request->user() and nothing accepts a user
 * id. The `notifications` screen permission decides whether someone has a
 * working bell at all; it never decides whose notifications they see, so a
 * broadened grant can't turn into a window onto someone else's queue.
 *
 * Notifications are never created or deleted over HTTP — they are written by
 * NotificationDispatcher in response to real events. The only mutation a user
 * can make to one is marking it read.
 */
class NotificationController extends Controller
{
    public function index(IndexNotificationRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $notifications = $request->user()
            ->notifications()
            ->when($filters['unread'] ?? false, fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return NotificationResource::collection($notifications);
    }

    /**
     * The badge on the bell. Its own endpoint because the topbar polls it on
     * every screen and has no use for the payloads themselves.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['unread' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    /**
     * Mark one notification read.
     *
     * The id is matched inside the caller's own relation, so an id belonging
     * to someone else is a 404 rather than a silent cross-user write.
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $row = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return response()->json(['data' => new NotificationResource($row->refresh())]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $marked = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $marked]]);
    }

    /**
     * The caller's preference matrix, always complete: every event type is
     * present whether or not they have ever changed it (see
     * NotificationSetting::matrixFor), so the screen needs no defaults of
     * its own that could drift from the server's.
     */
    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'channels' => NotificationSetting::CHANNELS,
                'event_types' => array_keys(NotificationSetting::EVENT_TYPES),
                'settings' => NotificationSetting::matrixFor($user),
                'phone' => $user->phone,
            ],
        ]);
    }

    /**
     * Save the caller's preferences.
     *
     * Rows are upserted rather than replaced wholesale: a partial payload
     * updates only the events it names, which keeps a screen from silently
     * resetting an event type it didn't know about yet.
     */
    public function updateSettings(UpdateNotificationSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data) {
            foreach ($data['settings'] as $row) {
                NotificationSetting::updateOrCreate(
                    ['user_id' => $user->id, 'event_type' => $row['event_type']],
                    [
                        'in_app' => $row['in_app'],
                        'email' => $row['email'],
                        'sms' => $row['sms'],
                    ],
                );
            }

            // array_key_exists, not isset: sending null is how a user removes
            // their number, and isset() would read that as "not sent".
            if (array_key_exists('phone', $data)) {
                $user->forceFill(['phone' => $data['phone']])->save();
            }
        });

        return $this->settings($request);
    }
}
