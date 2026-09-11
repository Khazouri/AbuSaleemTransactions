<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stage 83 — one written withdrawal request, [D] Appendices 68 and 69.
 *
 * Appendix 68 governs a withdrawal filed **before** the committee has decided
 * ("تقفل المعاملة بسبب (سحب الطلب)"), Appendix 69 one filed after ("**بعد صدور
 * نتيجة اللجنة لا يحذف القرار ولا تمحى المعاملة**"). Which of the two applies
 * is not a choice — it is `decision_existed_at_filing`, snapshotted when the
 * employee files so a later re-presentation cannot retroactively change what
 * this round was.
 *
 * @property int $id
 * @property int $request_id
 * @property string $reason The employee's own written request
 * @property bool $decision_existed_at_filing
 * @property string|null $outcome One of self::OUTCOMES
 * @property Carbon $requested_at
 * @property Carbon|null $determined_at
 */
class RequestWithdrawal extends Model
{
    /** Appendix 68 step 4 — the matter was purely personal and withdrawable. */
    public const OUTCOME_GRANTED = 'granted';

    /**
     * Appendix 68's closing paragraph — "أما إذا كان الموضوع قد تحول إلى إجراء
     * إداري لا يتوقف على رغبة الموظف، **فلا يؤدي طلب السحب تلقائيًا إلى
     * إنهائه**". The file continues; the request is still recorded.
     */
    public const OUTCOME_CONTINUES = 'refused_administrative_continuation';

    /**
     * Appendix 69 — after a decision the request is recorded and its legal
     * effect determined, while "يبقى المحضر الأصلي محفوظًا باعتباره وثيقة
     * رسمية لما وقع بالفعل". Changes no status at all.
     */
    public const OUTCOME_RECORDED_ONLY = 'recorded_only';

    /** @var array<string, string> */
    public const OUTCOMES = [
        self::OUTCOME_GRANTED => 'قبول السحب وإقفال المعاملة',
        self::OUTCOME_CONTINUES => 'استمرار الإجراء رغم طلب السحب',
        self::OUTCOME_RECORDED_ONLY => 'تسجيل الطلب وتحديد أثره القانوني',
    ];

    /** The reason written into the status history when a withdrawal is granted. */
    public const CLOSURE_REASON = 'سحب الطلب';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'decision_existed_at_filing' => false,
    ];

    protected function casts(): array
    {
        return [
            'decision_existed_at_filing' => 'boolean',
            'requested_at' => 'datetime',
            'determined_at' => 'datetime',
        ];
    }

    public function requestRecord(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function determinedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'determined_by_user_id');
    }

    public function outcomeLabel(): ?string
    {
        return $this->outcome === null ? null : (self::OUTCOMES[$this->outcome] ?? $this->outcome);
    }
}
