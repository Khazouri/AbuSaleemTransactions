<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stage 80 — one referral of a committee result to an approving body, i.e.
 * [D] Art. 30's own register: "ويسجل مقرر اللجنة: تاريخ الإحالة · رقم كتاب
 * الإحالة · الجهة المحال إليها · تاريخ ورود النتيجة · رقم قرار الاعتماد أو
 * المستند النهائي · أي ملاحظات أو توجيهات صادرة عن جهة الاعتماد."
 *
 * This is Art. 98's register 7 (سجل الإحالات للاعتماد) and the only one of the
 * twelve that needed a table of its own — every other register reads something
 * a prior stage already writes.
 *
 * Deliberately NOT a gate: the article says "ويسجل", not "ولا يحال قبل", so
 * nothing refuses an approval transition because no referral was recorded.
 * Appendix 63's control gates are Stage 78's and there are four of them.
 *
 * @property string $referred_to_body الجهة المحال إليها
 * @property string|null $result_outcome One of self::OUTCOMES, once known
 */
class ApprovalReferral extends Model
{
    /**
     * What came back. Art. 30 does not enumerate outcomes, but the system has
     * exactly two: the approving body approved, or it sent the file back —
     * the latter being Stage 77's own إعادة, whose reason and re-processing
     * live in `approval_returns` rather than being restated here.
     *
     * @var array<string, string>
     */
    public const OUTCOMES = [
        'approved' => 'اعتُمد',
        'returned' => 'أُعيد من جهة الاعتماد',
    ];

    protected $fillable = [
        'request_id',
        'referred_from_stage_id',
        'referred_at',
        'letter_number',
        'referred_to_body',
        'recorded_by_user_id',
        'result_received_at',
        'approval_decision_number',
        'result_note',
        'result_outcome',
        'result_recorded_by_user_id',
        'result_recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'referred_at' => 'date',
            'result_received_at' => 'date',
            'result_recorded_at' => 'datetime',
        ];
    }

    /** True until the approving body's answer has been recorded. */
    public function isOpen(): bool
    {
        return $this->result_outcome === null;
    }

    /**
     * Named per AGENTS.md, matching Stage 77's ApprovalReturn — and the
     * foreign key is stated explicitly for the same reason: Laravel would
     * otherwise derive `request_record_id` from the method name and resolve
     * every row to null.
     */
    public function requestRecord(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id');
    }

    public function referredFromStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'referred_from_stage_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function resultRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'result_recorded_by_user_id');
    }
}
