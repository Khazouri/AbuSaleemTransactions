<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stage 83 — one round of [D] Appendix 30's إدارة حالات التعارض في المستندات.
 *
 * "إذا ظهر اختلاف بين مستندين رسميين في نفس الملف ... **فلا تعرض المعاملة قبل
 * معالجة التعارض**" — so an unresolved row here is what refuses agenda
 * insertion, and resolving it is the appendix's own sixth step (إعادة الملف
 * للفحص).
 *
 * @property int $id
 * @property int $request_id
 * @property string $conflict_kind One of self::KINDS
 * @property string $detail Appendix 30 step 1 — تحديد المستندات المتعارضة
 * @property array<int, int>|null $attachment_ids
 * @property string|null $authority_consulted Step 2 — مخاطبة الجهة المختصة
 * @property string|null $authoritative_document Step 3 — تحديد المستند المعتمد
 * @property string|null $correction_note Step 4 — تصحيح البيانات في ملف الموظف
 * @property Carbon|null $resolved_at
 */
class RequestDocumentConflict extends Model
{
    /**
     * Appendix 30's own six named differences, in its order, plus `other`.
     *
     * `other` is not a loophole and not an invention: the appendix introduces
     * its list with "**مثل**", so an exhaustive enum would close a list the
     * source deliberately leaves open — the same reading Stage 74 applied to
     * Appendix 28's refusal reasons and Stage 76 to Appendix 70's evidence
     * kinds. Every kind demands the same evidence to resolve, so naming one
     * the appendix did not enumerate buys nobody a shortcut.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        'appointment_date' => 'اختلاف تاريخ التعيين',
        'grade' => 'اختلاف الدرجة',
        'name' => 'اختلاف الاسم',
        'qualification' => 'اختلاف المؤهل',
        'promotion_date' => 'اختلاف تاريخ الترقية',
        'service_period' => 'اختلاف مدة الخدمة',
        'other' => 'اختلاف آخر بين مستندين رسميين',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attachment_ids' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function requestRecord(): BelongsTo
    {
        // Named explicitly: Laravel would otherwise derive `request_record_id`
        // from the method name and silently resolve every row to null — the
        // Stage 80 bug this codebase has already paid for once.
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
        return self::KINDS[$this->conflict_kind] ?? $this->conflict_kind;
    }
}
