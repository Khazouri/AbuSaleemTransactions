<?php

namespace App\Providers;

use App\Contracts\DatabaseDumper;
use App\Contracts\SmsSender;
use App\Models\ApprovalReferral;
use App\Models\ApprovalReturn;
use App\Models\AuditLog;
use App\Models\Decision;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatusHistory;
use App\Observers\AuditObserver;
use App\Observers\ReportCacheObserver;
use App\Observers\RequestStatusNoticeObserver;
use App\Services\Backup\MysqlDumper;
use App\Services\Sms\LogSmsSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Stage 23 — SMS drivers, selected by config rather than by environment
     * checks so a real gateway can be added as one more entry here without
     * touching the notifications that use it.
     *
     * @var array<string, class-string<SmsSender>>
     */
    private const SMS_DRIVERS = [
        'log' => LogSmsSender::class,
    ];

    /**
     * Stage 26 — how each database driver gets dumped, keyed by connection
     * driver rather than by environment. Only MySQL is implemented because it
     * is the only connection this application actually runs on; the test suite
     * binds its own fake over this, which is the whole point of the contract.
     *
     * @var array<string, class-string<DatabaseDumper>>
     */
    private const DUMPERS = [
        'mysql' => MysqlDumper::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Unknown driver falls back to the log sender rather than failing to
        // boot: a misconfigured gateway should degrade the SMS channel, not
        // take the whole application down.
        $this->app->bind(
            SmsSender::class,
            self::SMS_DRIVERS[config('services.sms.driver')] ?? LogSmsSender::class,
        );

        // No fallback here, unlike SMS above: silently "backing up" with a
        // dumper that doesn't understand the connection would produce an
        // archive that only fails when someone tries to restore from it. A
        // driver with no dumper must fail loudly at resolve time.
        $this->app->bind(DatabaseDumper::class, function () {
            $driver = config('database.connections.'.config('database.default').'.driver');

            $dumper = self::DUMPERS[$driver] ?? null;

            abort_if($dumper === null, 500, "لا يوجد برنامج نسخ احتياطي مهيأ لقاعدة بيانات من نوع [{$driver}].");

            return $this->app->make($dumper);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stage 22 — audit trail. One shared observer for every audited model,
        // since the row it writes is the same shape whatever the model is. The
        // registry lives on AuditLog because the viewer's model filter reads
        // the same list.
        foreach (AuditLog::AUDITED_MODELS as $model) {
            $model::observe(AuditObserver::class);
        }

        // Stage 24 — invalidate the cached dashboard aggregates on any
        // request write.
        //
        // Stage 81 widened this beyond `Request`: Art. 106's indicators also
        // read decisions, sittings, agenda items and Stage 80's approval
        // referrals, and a referral is recorded WITHOUT moving the request's
        // stage or status — so watching requests alone would leave نسبة
        // القرارات المعادة stale with nothing to invalidate it.
        foreach ([Request::class, Decision::class, Meeting::class, MeetingRequest::class, ApprovalReferral::class, ApprovalReturn::class] as $model) {
            $model::observe(ReportCacheObserver::class);
        }

        // Stage 79 — [D] Art. 101's twelve notification moments. The
        // article describes states ("بحسب مرحلة المعاملة"), and
        // request_status_history is the append-only record that a request
        // reached one — written by every service that can move a status.
        // Observing the row rather than the eight writers is what makes
        // "status-only moves fire nothing" structurally unrepeatable.
        RequestStatusHistory::observe(RequestStatusNoticeObserver::class);
    }
}
