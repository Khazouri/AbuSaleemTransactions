<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stage 83 — one of [D] Appendix 60's six الحالات الخاصة والاستثنائية.
 *
 * The determinations each kind requires differ so much between them that they
 * live in one json validated per kind by Lifecycle\SpecialCaseRules rather
 * than in eleven sparse columns most rows would leave null.
 *
 * Only the kinds the appendix actually prohibits something for block anything
 * — see SpecialCaseRules::CLOSURE_BLOCKING and ::approveBlockReason().
 *
 * @property int $id
 * @property int $request_id
 * @property string $case_kind One of self::KINDS
 * @property array<string, mixed> $determinations
 * @property bool $halt_progress
 * @property Carbon|null $resolved_at
 */
class RequestSpecialCase extends Model
{
    public const KIND_DEATH = 'employee_death';

    public const KIND_SERVICE_ENDED = 'service_ended';

    public const KIND_TRANSFERRED = 'transferred_during_study';

    public const KIND_LEGISLATION_CHANGED = 'legislation_changed';

    public const KIND_DOCUMENT_LOST = 'document_lost';

    public const KIND_INVALID_DOCUMENT = 'invalid_document_after_decision';

    /** Appendix 60's six, in the appendix's own order. */
    public const KINDS = [
        self::KIND_DEATH => 'وفاة الموظف أثناء نظر المعاملة',
        self::KIND_SERVICE_ENDED => 'انتهاء خدمة الموظف أثناء المعاملة',
        self::KIND_TRANSFERRED => 'نقل الموظف إلى جهة أخرى أثناء دراسة الطلب',
        self::KIND_LEGISLATION_CHANGED => 'تغير التشريع أثناء سير المعاملة',
        self::KIND_DOCUMENT_LOST => 'فقدان مستند من الملف',
        self::KIND_INVALID_DOCUMENT => 'اكتشاف مستند غير صحيح بعد قرار اللجنة',
    ];

    protected $guarded = [];

    /**
     * `halt_progress` defaults false in the database, but a freshly created
     * model reads it as PHP null until refetched unless it is declared here —
     * the Eloquent gotcha Stage 27's GuideArticle and Stage 31's
     * MeetingRequest both had to close.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'halt_progress' => false,
    ];

    protected function casts(): array
    {
        return [
            'determinations' => 'array',
            'halt_progress' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function requestRecord(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->case_kind] ?? $this->case_kind;
    }
}
