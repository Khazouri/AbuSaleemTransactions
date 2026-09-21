<?php

namespace App\Services\Maintenance;

/**
 * Every command the maintenance console is allowed to run, and nothing else.
 *
 * THIS IS AN ALLOWLIST, NOT A SHELL, AND THAT IS THE WHOLE SECURITY MODEL.
 * The browser sends a code from this table and nothing more — no arguments, no
 * flags, no paths, no free text. Nothing supplied by a caller ever reaches
 * Artisan::call() or Symfony\Component\Process; the argv below is fixed here in
 * source. A code that is not a key of COMMANDS is refused before anything runs.
 *
 * Do not "improve" this into a free-text command box. A page that runs an
 * arbitrary string is a remote shell that one stolen administrator session
 * opens; this table is the difference between an operations screen and that.
 *
 * Two kinds, and the distinction is what makes the screen usable on cPanel:
 *
 *   artisan — run IN-PROCESS via Artisan::call(). Needs no subprocess, so it
 *             works even where proc_open/exec sit in disable_functions, which
 *             on shared hosting they usually do. `migrate` lives here, which is
 *             why the console's core purpose is always available.
 *
 *   shell   — a real subprocess (composer, npm). Frequently impossible on
 *             shared hosting; EnvironmentProbe reports whether it is, so the
 *             screen can say so instead of offering a button that 500s.
 */
class MaintenanceCommandCatalog
{
    public const KIND_ARTISAN = 'artisan';

    public const KIND_SHELL = 'shell';

    /**
     * The phrase a caller must type back to run a destructive command. Fixed,
     * and dictated by the API rather than chosen by the client, so a UI bug
     * cannot quietly lower the bar.
     */
    public const CONFIRMATION_PHRASE = 'CONFIRM';

    /**
     * code => definition
     *
     * artisan: `artisan` (command name) + `params` (the array handed to
     *          Artisan::call, fixed here).
     * shell:   `binary` (a key of config('maintenance.binaries')) + `args`
     *          (fixed argv) + `cwd` (relative to the project root, or null).
     *
     * `destructive` additionally demands the `maintenance,approve` grant and
     * the confirmation phrase above.
     */
    public const COMMANDS = [

        // ---- Database ----------------------------------------------------
        'migrate' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'migrate',
            // --force because a web request is non-interactive: without it the
            // command asks "are you sure?" in production and waits forever on a
            // prompt nobody can answer.
            'params' => ['--force' => true],
            'group' => 'database',
            'destructive' => false,
            'label_ar' => 'تشغيل الترحيلات',
            'label_en' => 'Run migrations',
            'description_ar' => 'تطبيق ملفات الترحيل الجديدة على قاعدة البيانات. آمن ولا يمس البيانات القائمة.',
            'description_en' => 'Applies any pending migrations. Safe — it does not touch existing data.',
        ],
        'migrate:status' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'migrate:status',
            'params' => [],
            'group' => 'database',
            'destructive' => false,
            'label_ar' => 'حالة الترحيلات',
            'label_en' => 'Migration status',
            'description_ar' => 'عرض الترحيلات المطبَّقة والمعلَّقة دون تنفيذ أي شيء.',
            'description_en' => 'Lists applied and pending migrations without running anything.',
        ],
        'db:seed' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'db:seed',
            'params' => ['--force' => true],
            'group' => 'database',
            'destructive' => false,
            'label_ar' => 'إعادة زراعة البيانات المرجعية',
            'label_en' => 'Re-seed reference data',
            'description_ar' => 'تشغيل DatabaseSeeder: الأدوار والشاشات والصلاحيات ومراحل سير العمل. كل البذور تستخدم updateOrCreate فلا تُنشئ صفوفاً مكررة — شغّلها بعد أي ترحيل يضيف شاشة أو صلاحية.',
            'description_en' => 'Runs DatabaseSeeder — roles, screens, permissions, workflow stages. Every seeder uses updateOrCreate, so re-running never duplicates rows. Run it after any migration that adds a screen or a grant.',
        ],
        'db:seed:test-users' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'db:seed',
            'params' => ['--class' => 'TestUserSeeder', '--force' => true],
            'group' => 'database',
            'destructive' => false,
            // Offered (and runnable) only while APP_DEBUG=true: these are
            // known-password logins, which a production host must never mint.
            'debug_only' => true,
            'label_ar' => 'زراعة حسابات الاختبار',
            'label_en' => 'Seed test users',
            'description_ar' => 'إنشاء حساب اختبار لكل دور بكلمة المرور password. متاح فقط عند APP_DEBUG=true.',
            'description_en' => 'Creates one test account per role, password "password". Only available while APP_DEBUG=true.',
        ],
        'migrate:rollback' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'migrate:rollback',
            'params' => ['--force' => true, '--step' => 1],
            'group' => 'database',
            'destructive' => true,
            'label_ar' => 'التراجع عن آخر دفعة ترحيل',
            'label_en' => 'Roll back the last migration batch',
            'description_ar' => 'يتراجع عن خطوة ترحيل واحدة، فيحذف الجداول أو الأعمدة التي أنشأتها تلك الخطوة وكل ما فيها.',
            'description_en' => 'Reverses one migration step, dropping whatever tables or columns that step created — and everything in them.',
        ],
        'migrate:fresh' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'migrate:fresh',
            'params' => ['--force' => true, '--seed' => true],
            'group' => 'database',
            'destructive' => true,
            'label_ar' => 'إعادة بناء قاعدة البيانات من الصفر',
            'label_en' => 'Rebuild the database from scratch',
            'description_ar' => 'يحذف كل الجداول ثم يعيد الترحيل والزراعة. تُفقد كل الطلبات والاجتماعات والقرارات والمستخدمين نهائياً. خذ نسخة احتياطية أولاً.',
            'description_en' => 'Drops every table, then re-migrates and re-seeds. Every request, meeting, decision and user is permanently lost. Take a backup first.',
        ],

        // ---- Caches ------------------------------------------------------
        'optimize:clear' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'optimize:clear',
            'params' => [],
            'group' => 'cache',
            'destructive' => false,
            'label_ar' => 'مسح كل الذواكر المؤقتة',
            'label_en' => 'Clear all caches',
            'description_ar' => 'يمسح ذاكرة الإعدادات والمسارات والعروض والأحداث والتطبيق. أول ما يُجرَّب بعد أي نشر.',
            'description_en' => 'Clears the config, route, view, event and application caches. The first thing to try after any deployment.',
        ],
        'cache:clear' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'cache:clear',
            'params' => [],
            'group' => 'cache',
            'destructive' => false,
            'label_ar' => 'مسح ذاكرة التطبيق',
            'label_en' => 'Clear application cache',
            'description_ar' => 'يمسح ذاكرة التطبيق وحدها، بما فيها مؤشرات التقارير المخزَّنة.',
            'description_en' => 'Clears the application cache only, including the cached report metrics.',
        ],
        'config:clear' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'config:clear',
            'params' => [],
            'group' => 'cache',
            'destructive' => false,
            'label_ar' => 'مسح ذاكرة الإعدادات',
            'label_en' => 'Clear config cache',
            'description_ar' => 'يجعل النظام يقرأ ملف .env من جديد. شغّلها بعد تعديل أي إعداد.',
            'description_en' => 'Makes the system read .env again. Run it after changing any setting.',
        ],
        'optimize' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'optimize',
            'params' => [],
            'group' => 'cache',
            'destructive' => false,
            'label_ar' => 'تهيئة للإنتاج (تخزين الإعدادات والمسارات)',
            'label_en' => 'Optimise for production (cache config + routes)',
            'description_ar' => 'يخزّن الإعدادات والمسارات والعروض مؤقتاً لتسريع الاستجابة. مهم: بعد أي تعديل على ملف .env شغّل «مسح كل الذواكر المؤقتة» وإلا بقيت القيمة القديمة سارية.',
            'description_en' => 'Caches config, routes and views for speed. Important: after ANY .env change run "Clear all caches", or the old value stays in force.',
        ],

        // ---- Application maintenance -------------------------------------
        'storage:link' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'storage:link',
            'params' => [],
            'group' => 'app',
            'destructive' => false,
            'label_ar' => 'إنشاء رابط مجلد التخزين',
            'label_en' => 'Create the storage symlink',
            'description_ar' => 'يربط public/storage بمجلد التخزين. قد يفشل على الاستضافة المشتركة إذا كانت دالة symlink معطَّلة.',
            'description_en' => 'Links public/storage to the storage directory. May fail on shared hosting if symlink() is disabled.',
        ],
        'queue:drain' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'queue:work',
            // Bounded on both axes, deliberately. An unbounded queue:work from a
            // web request would hold the PHP worker until the host killed it,
            // and --stop-when-empty alone still runs for as long as work keeps
            // arriving. This drains what is waiting and returns.
            'params' => ['--stop-when-empty' => true, '--max-time' => 55, '--tries' => 1],
            'group' => 'app',
            'destructive' => false,
            'label_ar' => 'تفريغ طابور المهام (الإشعارات)',
            'label_en' => 'Drain the queue (notifications)',
            'description_ar' => 'ينفّذ المهام المنتظرة ثم يتوقف. إشعارات هذا النظام تُرسل عبر الطابور، فبدون عامل يعمل باستمرار تبقى معلَّقة. محدود بـ 55 ثانية.',
            'description_en' => 'Processes queued jobs then stops. Notifications here are queued, so without a running worker they sit unsent. Bounded to 55 seconds.',
        ],
        'queue:restart' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'queue:restart',
            'params' => [],
            'group' => 'app',
            'destructive' => false,
            'label_ar' => 'إعادة تشغيل عمّال الطابور',
            'label_en' => 'Restart queue workers',
            'description_ar' => 'يطلب من أي عامل طابور يعمل أن ينهي مهمته الحالية ثم يعيد التشغيل بالكود الجديد. شغّلها بعد كل نشر.',
            'description_en' => 'Tells any running queue worker to finish its current job and restart on the new code. Run after every deployment.',
        ],
        'requests:flag-overdue' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'requests:flag-overdue',
            'params' => [],
            'group' => 'app',
            'destructive' => false,
            'label_ar' => 'وسم الطلبات المتأخرة',
            'label_en' => 'Flag overdue requests',
            'description_ar' => 'المسح اليومي لتجاوز المدة. شغّلها يدوياً إن لم تكن مهمة cron مضبوطة على الخادم.',
            'description_en' => 'The daily SLA sweep. Run it by hand if no cron job is configured on the server.',
        ],
        'requests:escalate-delays' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'requests:escalate-delays',
            'params' => [],
            'group' => 'app',
            'destructive' => false,
            'label_ar' => 'تصعيد حالات التأخير',
            'label_en' => 'Escalate delays',
            'description_ar' => 'سلّم التأخير اليومي (أصفر/أحمر/حرج). شغّلها يدوياً إن لم تكن مهمة cron مضبوطة على الخادم.',
            'description_en' => 'The daily delay ladder (yellow/red/critical). Run it by hand if no cron job is configured on the server.',
        ],
        'about' => [
            'kind' => self::KIND_ARTISAN,
            'artisan' => 'about',
            'params' => [],
            'group' => 'diagnostics',
            'destructive' => false,
            'label_ar' => 'معلومات النظام',
            'label_en' => 'System information',
            'description_ar' => 'إصدار Laravel وPHP والبيئة والمشغّلات المستخدَمة وحالة الذواكر المؤقتة.',
            'description_en' => 'Laravel and PHP versions, environment, configured drivers and cache state.',
        ],

        // ---- Dependencies (need a real subprocess) ------------------------
        'composer:install' => [
            'kind' => self::KIND_SHELL,
            'binary' => 'composer',
            'args' => ['install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist', '--no-progress'],
            'cwd' => null,
            'group' => 'dependencies',
            'destructive' => false,
            'label_ar' => 'تثبيت حزم PHP (composer install)',
            'label_en' => 'Install PHP packages (composer install)',
            'description_ar' => 'يثبّت حزم PHP من composer.lock بدون حزم التطوير. كثيراً ما يفشل على الاستضافة المشتركة بسبب حد الذاكرة أو مهلة التنفيذ — البديل المعتمد رفع مجلد vendor/ جاهزاً من جهازك.',
            'description_en' => 'Installs PHP packages from composer.lock without dev dependencies. Often fails on shared hosting on the memory limit or the execution timeout — the reliable fallback is uploading a prebuilt vendor/ directory.',
        ],
        'composer:dump' => [
            'kind' => self::KIND_SHELL,
            'binary' => 'composer',
            'args' => ['dump-autoload', '--optimize', '--no-interaction'],
            'cwd' => null,
            'group' => 'dependencies',
            'destructive' => false,
            'label_ar' => 'إعادة بناء خريطة التحميل التلقائي',
            'label_en' => 'Rebuild the autoloader',
            'description_ar' => 'أخف كثيراً من التثبيت الكامل، ويكفي عادةً بعد رفع ملفات PHP جديدة تحت مجلد app/.',
            'description_en' => 'Far lighter than a full install, and usually enough after uploading new PHP files under app/.',
        ],
        'npm:install' => [
            'kind' => self::KIND_SHELL,
            'binary' => 'npm',
            // NOT --omit=dev: vite is itself a devDependency, so omitting them
            // leaves nothing to build the SPA with. See npm:build.
            'args' => ['install', '--no-audit', '--no-fund'],
            'cwd' => 'frontend',
            'group' => 'dependencies',
            'destructive' => false,
            'label_ar' => 'تثبيت حزم الواجهة (npm install)',
            'label_en' => 'Install frontend packages (npm install)',
            'description_ar' => 'يثبّت حزم الواجهة داخل frontend/ متضمّناً حزم التطوير، لأن vite نفسه حزمة تطوير ولا يمكن البناء بدونه. نادراً ما يكون Node متاحاً على الاستضافة المشتركة.',
            'description_en' => 'Installs frontend packages under frontend/, including dev dependencies — vite is itself a dev dependency and nothing can build without it. Node is rarely available on shared hosting.',
        ],
        'npm:build' => [
            'kind' => self::KIND_SHELL,
            'binary' => 'npm',
            'args' => ['run', 'build'],
            'cwd' => 'frontend',
            'group' => 'dependencies',
            'destructive' => false,
            'label_ar' => 'بناء الواجهة (npm run build)',
            'label_en' => 'Build the frontend (npm run build)',
            'description_ar' => 'ينتج frontend/dist. تنبيه: عنوان الـ API يُحقن وقت البناء من frontend/.env.production، فالبناء على الخادم لا يغيّره — الطريقة المعتمدة هي البناء محلياً ورفع محتويات dist/ وحدها (راجع frontend/DEPLOYMENT.md).',
            'description_en' => 'Produces frontend/dist. Note: the API URL is baked in at build time from frontend/.env.production, so building on the server cannot change it — the supported path is building locally and uploading only the contents of dist/ (see frontend/DEPLOYMENT.md).',
        ],
        'php:version' => [
            'kind' => self::KIND_SHELL,
            'binary' => 'php',
            'args' => ['-v'],
            'cwd' => null,
            'group' => 'diagnostics',
            'destructive' => false,
            'label_ar' => 'إصدار PHP (سطر الأوامر)',
            'label_en' => 'PHP version (CLI)',
            'description_ar' => 'إصدار PHP في سطر الأوامر، وقد يختلف عن إصدار الويب على cPanel.',
            'description_en' => 'The CLI PHP version, which on cPanel is often different from the web one.',
        ],
        'composer:version' => [
            'kind' => self::KIND_SHELL,
            'binary' => 'composer',
            'args' => ['--version', '--no-interaction'],
            'cwd' => null,
            'group' => 'diagnostics',
            'destructive' => false,
            'label_ar' => 'إصدار Composer',
            'label_en' => 'Composer version',
            'description_ar' => 'يتحقق من صحة مسار Composer المهيَّأ في الإعدادات.',
            'description_en' => 'Confirms the configured Composer path is correct.',
        ],
        'npm:version' => [
            'kind' => self::KIND_SHELL,
            'binary' => 'npm',
            'args' => ['--version'],
            'cwd' => null,
            'group' => 'diagnostics',
            'destructive' => false,
            'label_ar' => 'إصدار npm',
            'label_en' => 'npm version',
            'description_ar' => 'يتحقق من صحة مسار npm المهيَّأ في الإعدادات.',
            'description_en' => 'Confirms the configured npm path is correct.',
        ],
    ];

    /** The order groups render in; anything unlisted falls to the end. */
    public const GROUPS = ['database', 'cache', 'app', 'dependencies', 'diagnostics'];

    /**
     * The commands this host may run: debug-only entries drop out unless
     * APP_DEBUG is on, so they are neither listed nor accepted.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function available(): array
    {
        return config('app.debug')
            ? self::COMMANDS
            : array_filter(self::COMMANDS, fn (array $d) => empty($d['debug_only']));
    }

    public static function has(string $code): bool
    {
        return array_key_exists($code, self::available());
    }

    /** @return array<string, mixed>|null */
    public static function find(string $code): ?array
    {
        return self::available()[$code] ?? null;
    }

    public static function isDestructive(string $code): bool
    {
        return (bool) (self::COMMANDS[$code]['destructive'] ?? false);
    }

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::available());
    }

    /**
     * The catalogue as the screen renders it: one entry per command, carrying
     * both labels so switching language relabels without a refetch — the
     * convention every other screen in this application already follows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forDisplay(): array
    {
        $rows = [];

        foreach (self::available() as $code => $definition) {
            $rows[] = [
                'code' => $code,
                'kind' => $definition['kind'],
                'group' => $definition['group'],
                'destructive' => $definition['destructive'],
                'label_ar' => $definition['label_ar'],
                'label_en' => $definition['label_en'],
                'description_ar' => $definition['description_ar'],
                'description_en' => $definition['description_en'],
                // What will actually run, shown verbatim on screen: someone
                // about to drop every table should be able to read the exact
                // command rather than trust a label.
                'preview' => self::preview($code),
            ];
        }

        return $rows;
    }

    /** A readable rendering of the fixed argv a code expands to. */
    public static function preview(string $code): string
    {
        $definition = self::COMMANDS[$code] ?? null;

        if ($definition === null) {
            return '';
        }

        if ($definition['kind'] === self::KIND_ARTISAN) {
            $parts = ['php artisan', $definition['artisan']];

            foreach ($definition['params'] as $name => $value) {
                $parts[] = $value === true ? $name : "{$name}={$value}";
            }

            return implode(' ', $parts);
        }

        return implode(' ', array_merge([$definition['binary']], $definition['args']));
    }
}
