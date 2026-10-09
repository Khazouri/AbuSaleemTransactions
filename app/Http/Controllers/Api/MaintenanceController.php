<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MaintenanceCommandException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Maintenance\RunMaintenanceCommandRequest;
use App\Http\Resources\MaintenanceRunResource;
use App\Models\MaintenanceRun;
use App\Services\Maintenance\EnvironmentProbe;
use App\Services\Maintenance\MaintenanceBootstrapper;
use App\Services\Maintenance\MaintenanceCommandCatalog;
use App\Services\Maintenance\MaintenanceRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The maintenance console — deployment operations for a host with no shell.
 *
 * Reason this exists at all: on cPanel shared hosting there is frequently no
 * SSH, so after uploading a release there is no way to run `php artisan
 * migrate`, and a schema change that never ran surfaces to users as a column
 * that does not exist. This screen is that missing step.
 *
 * Three gates, doing three different jobs:
 *
 *   1. `screen.permission:maintenance,<action>` on every route. The screen is
 *      seeded with empty grants, which in ScreenRolePermissionSeeder already
 *      means R08-only — the same shape `settings` and `backup` use.
 *   2. A destructive tier inside run(): commands that drop data additionally
 *      need `maintenance,approve` AND the caller echoing back a confirmation
 *      phrase the API dictates. One endpoint, one code path — mirrors how
 *      `decisions` splits `add` (cast a vote) from `approve` (record one).
 *   3. config('maintenance.enabled'), so the console can be switched off for
 *      good once a deployment has settled.
 *
 * index() deliberately keeps working while the console is disabled: it is
 * read-only diagnostics, and a screen that can say "this is switched off" is
 * more useful than one that returns a bare 403 the SPA renders as a generic
 * failure.
 */
class MaintenanceController extends Controller
{
    /**
     * The one-time bootstrap page, shown before anything runs.
     *
     * Served as plain HTML from a browser rather than as JSON, because the
     * whole premise is an administrator with no shell — no curl, no artisan,
     * nothing but a URL bar. The markup is an inline string rather than a Blade
     * view on purpose: Blade compiles into storage/framework/views, and on the
     * broken deployment this page exists to rescue, that directory may not be
     * writable yet.
     */
    public function bootstrapForm(Request $request, MaintenanceBootstrapper $bootstrapper): Response
    {
        $token = $this->bootstrapToken($request);

        return $this->html($this->bootstrapPage($bootstrapper, $token, null));
    }

    /** Runs the bootstrap and reports, step by step, how far it got. */
    public function bootstrap(Request $request, MaintenanceBootstrapper $bootstrapper): Response
    {
        $token = $this->bootstrapToken($request);

        $steps = $bootstrapper->run();

        return $this->html($this->bootstrapPage($bootstrapper, $token, $steps));
    }

    /**
     * Validates the bootstrap token, or ends the request.
     *
     * Three conditions, and each 404s rather than 403s — the same reasoning
     * DevTestUserController uses: a 403 confirms that an unauthenticated
     * endpoint capable of migrating the database exists at this URL, which is
     * information worth withholding from anyone who does not already hold the
     * token.
     */
    private function bootstrapToken(Request $request): string
    {
        $configured = (string) config('maintenance.bootstrap_token');
        $supplied = (string) ($request->input('token') ?? $request->query('token') ?? '');

        // A short token on a public endpoint that can rebuild the database is
        // the actual risk here, so a weak one is refused outright rather than
        // accepted with a warning nobody reads.
        abort_if($configured === '' || strlen($configured) < 24, 404);
        abort_unless(hash_equals($configured, $supplied), 404);

        // Self-disabling: once the console is reachable through the ordinary
        // authenticated screen, this door closes on its own.
        abort_unless(app(MaintenanceBootstrapper::class)->isNeeded(), 404);

        return $supplied;
    }

    private function html(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @param  array<int, array{step: string, ok: bool, detail: string}>|null  $steps
     */
    private function bootstrapPage(MaintenanceBootstrapper $bootstrapper, string $token, ?array $steps): string
    {
        $e = fn (?string $value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $action = url('/api/maintenance/bootstrap');

        $body = '';

        if ($steps === null) {
            $body = <<<HTML
                <p>هذه الصفحة تُشغَّل مرة واحدة فقط: تنشئ جداول قاعدة البيانات وتمنح شاشة الصيانة لمدير النظام.
                بعدها تُغلق نفسها تلقائياً وتُستخدم الشاشة من داخل النظام بتسجيل الدخول المعتاد.</p>
                <p class="en">This runs once. It applies pending migrations and grants the maintenance
                screen to the system administrator, then closes itself — after this, use the screen from
                inside the app with a normal login.</p>
                <form method="POST" action="{$action}">
                    <input type="hidden" name="token" value="{$e($token)}">
                    <button type="submit">تشغيل الآن &middot; Run now</button>
                </form>
                HTML;
        } else {
            $rows = '';
            foreach ($steps as $step) {
                $state = $step['ok'] ? 'ok' : 'bad';
                $mark = $step['ok'] ? '✓' : '✕';
                $rows .= '<li class="'.$state.'"><strong>'.$mark.' '.$e($step['step']).'</strong>'
                    .'<pre>'.$e($step['detail']).'</pre></li>';
            }

            // Re-asked after the run, so the page reports the state the world is
            // actually in rather than what the steps hoped to achieve.
            $done = ! $bootstrapper->isNeeded();

            $verdict = $done
                ? '<p class="ok-box">تم. سجّل الدخول كمدير النظام وافتح <code>/maintenance</code>.'
                    .'<br><span class="en">Done. Sign in as the system administrator and open <code>/maintenance</code>.'
                    .' This page has now closed itself — remove MAINTENANCE_BOOTSTRAP_TOKEN from .env.</span></p>'
                : '<p class="bad-box">لم تكتمل العملية. راجع الخطوات أعلاه ثم أعد المحاولة.'
                    .'<br><span class="en">Did not complete. Read the failing step above and retry this page.</span></p>';

            $body = '<ul class="steps">'.$rows.'</ul>'.$verdict;
        }

        return <<<HTML
            <!doctype html>
            <html lang="ar" dir="rtl">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title>تهيئة الصيانة · Maintenance bootstrap</title>
            <style>
              body { margin:0; padding:2rem 1rem; background:#f4f5f7; color:#111;
                     font:15px/1.6 system-ui,"Segoe UI",Tahoma,sans-serif; }
              main { max-width:46rem; margin:0 auto; background:#fff; padding:1.5rem 1.75rem;
                     border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,.08); }
              h1 { margin:0 0 1rem; font-size:1.15rem; color:#0f5132; }
              p { margin:0 0 1rem; }
              .en { direction:ltr; text-align:left; color:#555; font-size:.86rem; }
              button { padding:.6rem 1.2rem; border:0; border-radius:8px; background:#0f5132;
                       color:#fff; font-size:.95rem; cursor:pointer; }
              .steps { list-style:none; margin:0 0 1rem; padding:0; }
              .steps li { margin-bottom:.6rem; padding:.6rem .75rem; border-radius:8px;
                          border:1px solid #d7d9dd; }
              .steps li.ok { border-color:#badbcc; background:#f1f9f4; }
              .steps li.bad { border-color:#f5c2c7; background:#fdf2f2; }
              pre { direction:ltr; text-align:left; margin:.4rem 0 0; padding:.5rem .6rem;
                    max-height:16rem; overflow:auto; background:#111; color:#e6e6e6;
                    border-radius:6px; font-size:.75rem; white-space:pre-wrap; word-break:break-word; }
              .ok-box { padding:.75rem .9rem; border-radius:8px; background:#f1f9f4;
                        border:1px solid #badbcc; }
              .bad-box { padding:.75rem .9rem; border-radius:8px; background:#fdf2f2;
                         border:1px solid #f5c2c7; }
              code { font-family:ui-monospace,Menlo,Consolas,monospace; }
            </style>
            </head>
            <body><main>
            <h1>تهيئة لوحة الصيانة · Maintenance bootstrap</h1>
            {$body}
            </main></body></html>
            HTML;
    }

    /** Diagnostics plus the catalogue of what may be run. */
    public function index(Request $request, EnvironmentProbe $probe): JsonResponse
    {
        $enabled = (bool) config('maintenance.enabled');

        return response()->json([
            'data' => [
                'enabled' => $enabled,
                'environment' => $probe->report(),
                // Empty when switched off, so the screen cannot offer a button
                // the run endpoint would refuse.
                'commands' => $enabled ? MaintenanceCommandCatalog::forDisplay() : [],
                'groups' => MaintenanceCommandCatalog::GROUPS,
                'confirmation_phrase' => MaintenanceCommandCatalog::CONFIRMATION_PHRASE,
                'can_run_destructive' => (bool) $request->user()?->hasScreenPermission('maintenance', 'can_approve'),
                'limits' => [
                    'timeout' => (int) config('maintenance.timeout'),
                    'max_output_bytes' => (int) config('maintenance.max_output_bytes'),
                ],
            ],
        ]);
    }

    /** Recent runs, newest first. */
    public function runs(): AnonymousResourceCollection
    {
        $runs = MaintenanceRun::query()
            ->with('ranBy:id,name')
            ->latest('id')
            ->paginate(20);

        return MaintenanceRunResource::collection($runs);
    }

    /** One run with its full captured output. */
    public function show(MaintenanceRun $maintenanceRun): MaintenanceRunResource
    {
        return (new MaintenanceRunResource($maintenanceRun->load('ranBy:id,name')))->withFullOutput();
    }

    /**
     * Runs one allowlisted command.
     *
     * Runs inline rather than queued, and that is deliberate: queueing would
     * need a worker, and a host with no worker running is exactly the situation
     * this screen exists for — `queue:drain` is one of the commands it offers.
     * The trade is that a long command is bounded by the host's own
     * max_execution_time, which the diagnostics panel reports for that reason.
     */
    public function run(RunMaintenanceCommandRequest $request, MaintenanceRunner $runner): JsonResponse
    {
        $code = $request->command();

        if (MaintenanceCommandCatalog::isDestructive($code)) {
            $this->guardDestructive($request);
        }

        try {
            $run = $runner->run($code, $request->user());
        } catch (MaintenanceCommandException $exception) {
            // A refusal is a 422 with the rule's own wording, matching how every
            // other domain exception in this application reaches the SPA.
            throw ValidationException::withMessages(['command' => [$exception->getMessage()]]);
        }

        return (new MaintenanceRunResource($run->load('ranBy:id,name')))
            ->withFullOutput()
            ->response()
            // 200, not 201: the interesting result is what the command did, and
            // a command that exited non-zero still recorded a run. The row's own
            // `status` carries success or failure.
            ->setStatusCode(200);
    }

    /** Clears the run history. The rows are a record, not application data. */
    public function clearHistory(): JsonResponse
    {
        $deleted = MaintenanceRun::query()->delete();

        return response()->json([
            'message' => "تم حذف {$deleted} سجلاً من سجل الصيانة.",
        ]);
    }

    /**
     * The two extra requirements for a command that destroys data.
     *
     * The phrase is compared exactly and is dictated by the API rather than
     * chosen by the client, so a UI change cannot quietly lower the bar; and
     * the `approve` grant is checked here rather than as route middleware
     * because it applies to some commands on this endpoint and not others.
     */
    private function guardDestructive(RunMaintenanceCommandRequest $request): void
    {
        if (! $request->user()?->hasScreenPermission('maintenance', 'can_approve')) {
            throw ValidationException::withMessages([
                'command' => [MaintenanceCommandException::approvalRequired()->getMessage()],
            ]);
        }

        if ($request->confirmation() !== MaintenanceCommandCatalog::CONFIRMATION_PHRASE) {
            throw ValidationException::withMessages([
                'confirmation' => [
                    MaintenanceCommandException::confirmationRequired(
                        MaintenanceCommandCatalog::CONFIRMATION_PHRASE,
                    )->getMessage(),
                ],
            ]);
        }
    }
}
