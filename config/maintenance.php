<?php

/*
|--------------------------------------------------------------------------
| Maintenance console
|--------------------------------------------------------------------------
|
| Settings for the admin maintenance screen (`maintenance`), which lets an
| R08 administrator run a FIXED, allowlisted set of deployment commands from
| the browser — the only route to `php artisan migrate` on a cPanel shared
| host with no SSH access.
|
| NOTE: this is unrelated to Laravel's own maintenance MODE (`php artisan
| down`), which is configured under `app.maintenance`. Different key, no
| collision.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    |
    | Defaults to ON, deliberately. A console that ships disabled is useless on
    | the exact kind of host it exists for — one with no shell to go and enable
    | it. Set MAINTENANCE_CONSOLE_ENABLED=false once a deployment is settled and
    | the screen is no longer needed; every endpoint then refuses with 403.
    |
    */

    'enabled' => filter_var(env('MAINTENANCE_CONSOLE_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Shell commands
    |--------------------------------------------------------------------------
    |
    | Composer/npm need a real subprocess, which shared hosting frequently
    | forbids by putting proc_open in disable_functions. Turning this off hides
    | that whole class of command rather than offering buttons that cannot work.
    | Artisan commands are unaffected — they run in-process and need no shell.
    |
    */

    'shell_enabled' => filter_var(env('MAINTENANCE_SHELL_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | One-time bootstrap token
    |--------------------------------------------------------------------------
    |
    | Unlocks the unauthenticated bootstrap page that creates the console's own
    | table and screen row — the step that cannot be done from inside the
    | console, because the console does not exist yet. Empty (the default) means
    | the page does not answer at all.
    |
    | This is the ONE unauthenticated endpoint in the system that can change the
    | database, so treat the value as a password: at least 24 characters of
    | randomness, and shorter values are refused rather than merely discouraged.
    | It also closes itself the moment the console becomes reachable, so it is
    | not something to remember to switch off — but clear the value anyway once
    | the deployment has settled.
    |
    */

    'bootstrap_token' => (string) env('MAINTENANCE_BOOTSTRAP_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Binary paths
    |--------------------------------------------------------------------------
    |
    | Configurable rather than hardcoded, for the same reason
    | config/backup.php makes mysqldump configurable: on cPanel none of these
    | are on the PATH of the web user at their plain names. Typical values:
    |
    |   php      /opt/cpanel/ea-php82/root/usr/bin/php
    |   composer /opt/cpanel/composer/bin/composer   (or a composer.phar path)
    |   npm      /home/<account>/nodevenv/<app>/20/bin/npm
    |
    | The screen probes each one and reports which actually answer, so a wrong
    | path shows up as "not available" instead of as a failed run.
    |
    */

    'binaries' => [
        'php' => env('MAINTENANCE_PHP_PATH', PHP_BINARY ?: 'php'),
        'composer' => env('MAINTENANCE_COMPOSER_PATH', 'composer'),
        'npm' => env('MAINTENANCE_NPM_PATH', 'npm'),
        'node' => env('MAINTENANCE_NODE_PATH', 'node'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | `timeout` bounds a subprocess. It is capped further at runtime by PHP's
    | own max_execution_time, which on shared hosting is usually the binding
    | constraint and is why the run row is written BEFORE the command starts.
    |
    | `max_output_bytes` truncates stored output: `composer install` is
    | comfortably verbose, and a history table is not a log server.
    |
    */

    'timeout' => (int) env('MAINTENANCE_TIMEOUT', 300),

    'max_output_bytes' => (int) env('MAINTENANCE_MAX_OUTPUT', 65536),

    /*
    | How long one run may hold the lock that stops a second run starting
    | alongside it. Two concurrent `composer install`s corrupt vendor/.
    */

    'lock_seconds' => (int) env('MAINTENANCE_LOCK_SECONDS', 600),

];
