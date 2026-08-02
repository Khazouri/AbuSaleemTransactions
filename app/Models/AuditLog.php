<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One audited change — Stage 22.
 *
 * Written only by App\Observers\AuditObserver; the app never edits or deletes
 * these rows, so there is no controller write path and no `update` usage.
 */
class AuditLog extends Model
{
    /**
     * Every model whose writes land here.
     *
     * Explicit rather than "every model" so lookup tables (workflow stages,
     * statuses, screens, roles) don't bury real activity under reference data,
     * and so putting a model under audit stays a visible decision. When a later
     * stage adds a resource users can change, add it here — AppServiceProvider
     * attaches the observer to each entry, and the viewer's model filter offers
     * exactly this list. The order is the order the filter shows.
     *
     * @var list<class-string<Model>>
     */
    public const AUDITED_MODELS = [
        // Transactions and everything that moves them through the workflow
        Transaction::class,
        TransactionStageLog::class,
        TransactionStatusHistory::class,
        Approval::class,
        Attachment::class,
        Note::class,

        // Committees, meetings and the decisions they produce
        Committee::class,
        CommitteeMember::class,
        Meeting::class,
        MeetingAttendee::class,
        MeetingTransaction::class,
        Vote::class,
        Decision::class,

        // Master data and access control — low volume, high consequence
        Department::class,
        User::class,
        TransactionType::class,
        ScreenRolePermission::class,
        Setting::class,
        Template::class,
        GuideArticle::class,

        // Stage 26 — taking or destroying a snapshot of the whole system is
        // exactly the low-volume, high-consequence write this trail is for.
        Backup::class,
    ];

    /** The actions the observer records, and the only values the filter accepts. */
    public const ACTIONS = ['created', 'updated', 'deleted', 'restored'];

    /**
     * Kill switch for the observer.
     *
     * Needed because bulk bootstrap writes (DatabaseSeeder) would otherwise
     * bury real user activity under thousands of setup rows. It is an explicit
     * flag rather than a `runningInConsole()` check on purpose: PHPUnit runs in
     * the console too, and feature tests must still produce audit entries.
     */
    protected static bool $auditing = true;

    protected $fillable = [
        'user_id',
        'auditable_type',
        'auditable_id',
        'action',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /** Runs $callback with auditing suppressed, restoring the flag even on failure. */
    public static function withoutAuditing(callable $callback): mixed
    {
        $previous = static::$auditing;
        static::$auditing = false;

        try {
            return $callback();
        } finally {
            static::$auditing = $previous;
        }
    }

    public static function auditingEnabled(): bool
    {
        return static::$auditing;
    }

    /**
     * Short, stable keys the API speaks instead of PHP class names
     * (`transaction_stage_log` => App\Models\TransactionStageLog).
     *
     * The client filters by key and never sends a class string, so no request
     * can aim the query at a class the audit registry doesn't cover.
     *
     * @return array<string, class-string<Model>>
     */
    public static function modelKeys(): array
    {
        $keys = [];

        foreach (self::AUDITED_MODELS as $class) {
            $keys[static::modelKey($class)] = $class;
        }

        return $keys;
    }

    /** @param  class-string<Model>|string  $class */
    public static function modelKey(string $class): string
    {
        return Str::snake(class_basename($class));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Untyped morph: the target may since have been deleted, so this can resolve to null. */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
