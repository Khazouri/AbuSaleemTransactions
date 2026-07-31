<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Observers\AuditObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
