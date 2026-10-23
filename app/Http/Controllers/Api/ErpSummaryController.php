<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\MeetingResource;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\RequestTrackingResource;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\User;
use App\Services\Erp\ErpIdentity;
use App\Services\MeetingVisibility;
use App\Services\ReportMetricsService;
use App\Services\Tasks\PendingTaskCollector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;

/**
 * One employee's state here, read by the EmployeePlus ERP (user decision
 * 2026-10-23): pending tasks, their requests, upcoming meetings and
 * notifications, each as a count plus the latest few rows.
 *
 * The ERP sends the employee's own ERP access token, not a shared key, so a
 * call can only ever name the employee signed in to the ERP — the same proof
 * the SSO callback rests on. The callback's three refusals apply, and each
 * section needs the screen's `view` grant, so the ERP never shows more than
 * the SPA would show the same account.
 */
class ErpSummaryController
{
    private const RECENT = 10;

    public function __invoke(
        HttpRequest $request,
        ErpIdentity $erp,
        PendingTaskCollector $tasks,
        MeetingVisibility $meetings,
    ): JsonResponse {
        ErpAuthController::abortUnlessConfigured();

        $token = $request->bearerToken();
        abort_if(blank($token), 401, 'رمز ERP مطلوب.');

        try {
            $erpUserId = $erp->userIdFromAccessToken($token);
        } catch (ConnectionException) {
            abort(503, 'تعذّر الاتصال بنظام ERP. حاول لاحقاً.');
        }
        abort_if($erpUserId === null, 401, 'رمز ERP غير صالح.');

        $user = User::query()->where('erp_user_id', $erpUserId)->first();
        abort_if($user === null, 404, 'حساب ERP غير مربوط بحساب في هذا النظام.');
        abort_unless($user->is_active, 403, 'هذا الحساب غير مفعّل.');
        abort_if($user->hasRole('R08'), 403, 'حساب مدير النظام لا يُقرأ عبر ERP.');

        $sections = [
            'tasks' => ['my_tasks', fn () => $tasks->collect($user)],
            'requests' => ['request_tracking', fn () => $this->requests($user)],
            'meetings' => ['meetings', fn () => $this->meetings($meetings, $user)],
            'notifications' => ['notifications', fn () => $this->notifications($user)],
        ];

        $data = ['user' => ['id' => $user->id, 'name' => $user->name, 'employee_number' => $user->employee_number]];
        foreach ($sections as $key => [$screen, $build]) {
            if ($user->hasScreenPermission($screen, 'can_view')) {
                $data[$key] = $build();
            }
        }

        return response()->json(['data' => $data]);
    }

    private function requests(User $user): array
    {
        $open = fn ($status) => $status->whereNotIn('code', ReportMetricsService::CONCLUDED_STATUSES);

        $recent = Request::query()->ownedBy($user)
            // The tracking list's own eager loads, so a row reads the same here.
            ->with([
                'subject:id,name',
                'requestType:id,code,name_ar,name_en,decision_grade_threshold,default_administrative_route',
                'department:id,name_ar,name_en,code',
                'status:id,code,name_ar,name_en,color',
                'currentStage:id,order_no,code,name_ar,name_en,target_days_min,target_days_max',
                'latestStageLog',
            ])
            ->latest()
            ->limit(self::RECENT)
            ->get();

        return [
            'open' => Request::query()->ownedBy($user)->whereHas('status', $open)->count(),
            'recent' => RequestTrackingResource::collection($recent),
        ];
    }

    private function meetings(MeetingVisibility $visibility, User $user): array
    {
        $upcoming = $visibility->apply(Meeting::query(), $user, withGuests: true)
            ->whereIn('status', [Meeting::STATUS_PENDING_CONFIRMATION, 'scheduled'])
            ->whereDate('scheduled_at', '>=', today());

        return [
            'upcoming' => (clone $upcoming)->count(),
            'next' => MeetingResource::collection(
                $upcoming->with('committee:id,name_ar,name_en')->orderBy('scheduled_at')->limit(self::RECENT)->get(),
            ),
        ];
    }

    private function notifications(User $user): array
    {
        return [
            'unread' => $user->unreadNotifications()->count(),
            'recent' => NotificationResource::collection($user->notifications()->latest()->limit(self::RECENT)->get()),
        ];
    }
}
