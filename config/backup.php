<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where snapshots are written — Stage 26
    |--------------------------------------------------------------------------
    |
    | A disk name rather than a path, so shipping backups off-box later is a
    | config change (point this at `s3`) and not a code change. The default
    | `local` disk is private: nothing under storage/app/private is web-served.
    |
    */

    'disk' => env('BACKUP_DISK', 'local'),

    'path' => env('BACKUP_PATH', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | mysqldump
    |--------------------------------------------------------------------------
    |
    | Configurable because PHP here may run on the Windows host or inside the
    | Homestead VM, and the binary is only on the PATH in one of them. Timeout
    | is in seconds; a dump of a large database is legitimately slow, and a
    | process killed halfway writes a truncated .sql file that looks valid.
    |
    */

    'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),

    'timeout' => (int) env('BACKUP_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Contents and retention
    |--------------------------------------------------------------------------
    |
    | Attachments and signatures are included by default: a restored database
    | full of transactions pointing at files that no longer exist is not a
    | restored system. Retention is enforced by `backup:run` after it writes a
    | new snapshot, so the oldest is only dropped once a newer one exists.
    |
    */

    'include_files' => (bool) env('BACKUP_INCLUDE_FILES', true),

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),

    /*
    | Directories on the private disk that get archived alongside the dump.
    */
    'file_directories' => ['attachments', 'signatures'],

];
