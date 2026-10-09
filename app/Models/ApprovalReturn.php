<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stage 77 — one round of [D] Art. 94's إجراء إعادة معالجة: the approving body
 * sent the file back, and this row proves both سبب الإعادة and الإجراء الذي
 * اتخذ بشأنها.
 *
 * A request may accumulate several of these — Art. 94's loop has no limit, and
 * a formal return can be corrected, re-referred and returned again. Only the
 * newest *unresolved* one gates anything; see Request::openApprovalReturn().
 *
 * @property string $return_kind One of self::KINDS
 * @property string $return_reason_code One of the keys of self::REASONS
 */
class ApprovalReturn extends Model
{
    /** Appendix 34 — "تصنف الإعادة إلى: إعادة شكلية · إعادة موضوعية". */
    public const KIND_FORMAL = 'formal';

    public const KIND_SUBSTANTIVE = 'substantive';

    public const KINDS = [self::KIND_FORMAL, self::KIND_SUBSTANTIVE];

    /**
     * Art. 94's six named reasons merged with Appendix 34's own examples,
     * mapped to the kind each source assigns it.
     *
     * Art. 94: نقص مستند · ملاحظة قانونية · طلب إعادة دراسة · عدم الاختصاص ·
     * خطأ في الصياغة · نقص في البيانات.
     * Appendix 34, شكلية: توقيع ناقص · خطأ رقمي · نقص مرفق.
     * Appendix 34, موضوعية: طلب إعادة دراسة · ملاحظة قانونية · تعارض في
     * الاختصاص · اعتراض على نتيجة.
     *
     * Art. 94's "نقص مستند" and Appendix 34's "نقص مرفق" are the same thing and
     * are one code; "عدم الاختصاص" and "تعارض في الاختصاص" likewise.
     *
     * `other` carries no kind of its own because both appendix lists are
     * introduced with "مثل" — an exhaustive enum would close a list the source
     * deliberately leaves open, the reasoning Stage 74 used for Appendix 28's
     * refusal codes. Its kind is whatever the recorder states; every other
     * code's stated kind must match the one below, so "توقيع ناقص، موضوعية"
     * cannot be recorded.
     *
     * @var array<string, array{ar: string, kind: string|null}>
     */
    public const REASONS = [
        // --- إعادة شكلية -----------------------------------------------------
        'missing_signature' => ['ar' => 'توقيع ناقص', 'kind' => self::KIND_FORMAL],
        'numeric_error' => ['ar' => 'خطأ رقمي', 'kind' => self::KIND_FORMAL],
        'missing_document' => ['ar' => 'نقص مستند أو مرفق', 'kind' => self::KIND_FORMAL],
        'drafting_error' => ['ar' => 'خطأ في الصياغة', 'kind' => self::KIND_FORMAL],
        'incomplete_data' => ['ar' => 'نقص في البيانات', 'kind' => self::KIND_FORMAL],
        // --- إعادة موضوعية ---------------------------------------------------
        'restudy_requested' => ['ar' => 'طلب إعادة دراسة', 'kind' => self::KIND_SUBSTANTIVE],
        'legal_observation' => ['ar' => 'ملاحظة قانونية', 'kind' => self::KIND_SUBSTANTIVE],
        'jurisdiction_conflict' => ['ar' => 'تعارض في الاختصاص أو عدم الاختصاص', 'kind' => self::KIND_SUBSTANTIVE],
        'result_objection' => ['ar' => 'اعتراض على نتيجة', 'kind' => self::KIND_SUBSTANTIVE],
        // --- "مثل" ------------------------------------------------------------
        'other' => ['ar' => 'سبب آخر تبينه جهة الاعتماد', 'kind' => null],
    ];

    protected $fillable = [
        'request_id',
        'returned_from_stage_id',
        'return_kind',
        'return_reason_code',
        'return_note',
        'letter_number',
        'received_at',
        'recorded_by_user_id',
        'resolution_action',
        'resolution_target_stage_id',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    /** The Arabic label for a reason code, for the audit trail's own prose. */
    public static function reasonLabel(string $code): string
    {
        return self::REASONS[$code]['ar'] ?? $code;
    }

    public function requestRecord(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id');
    }

    public function returnedFromStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'returned_from_stage_id');
    }

    public function resolutionTargetStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'resolution_target_stage_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
