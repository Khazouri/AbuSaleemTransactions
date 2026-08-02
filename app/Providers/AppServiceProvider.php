<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Models\AuditLog;
use App\Observers\AuditObserver;
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
    }
}
