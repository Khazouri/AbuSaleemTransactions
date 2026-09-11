<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One command run from the maintenance console.
 *
 * @property string $command A MaintenanceCommandCatalog code, never raw argv.
 * @property string $kind artisan|shell
 * @property string $status running|completed|failed
 * @property int|null $exit_code
 * @property string|null $output
 * @property string|null $error
 * @property int|null $duration_ms
 */
class MaintenanceRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'command',
        'kind',
        'status',
        'exit_code',
        'output',
        'error',
        'duration_ms',
        'ran_by_user_id',
        'finished_at',
    ];

    /**
     * `status` is set explicitly on every write path, but a row created without
     * one would read as PHP null on the returned model even though the database
     * default applies — the same Eloquent gotcha GuideArticle/RequestSpecialCase
     * already declare $attributes for.
     */
    protected $attributes = [
        'status' => self::STATUS_RUNNING,
    ];

    protected function casts(): array
    {
        return [
            'exit_code' => 'integer',
            'duration_ms' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function ranBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ran_by_user_id');
    }

    /**
     * A run left at `running` long after it started was almost certainly killed
     * by max_execution_time rather than still working — the screen says so
     * instead of showing a spinner forever.
     */
    public function isStale(): bool
    {
        return $this->status === self::STATUS_RUNNING
            && $this->created_at !== null
            && $this->created_at->addSeconds((int) config('maintenance.lock_seconds'))->isPast();
    }
}
