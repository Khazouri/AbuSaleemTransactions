<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stage 68 — one round of [D] Art. 21's pre-meeting legal review on an
 * ordinary request, carrying Appendix 22's بطاقة السند القانوني plus
 * النموذج 06's five-outcome verdict.
 *
 * A request may accumulate several of these: Art. 21 / [E] stage 08 route a
 * blocking verdict back for completion or correction and then to
 * *re-review*, and the earlier opinion must stay readable in the file. Only
 * the latest one gates anything — see Request::latestLegalReview().
 *
 * @property string $verdict One of self::VERDICTS
 * @property string|null $legal_note ملاحظة العضو القانوني — required for every
 *                                   verdict except `sound_ready`
 */
class RequestLegalReview extends Model
{
    /**
     * [D] Art. 21's five procedural outcomes, in the order the article lists
     * them, each mapped to whether the file may go on to the agenda.
     *
     * `present_with_note` permits presentation because that outcome's whole
     * point is that the matter IS put to the committee with the legal issue
     * stated ("مسألة قانونية تستوجب العرض على اللجنة مع بيانها") — treating it
     * as a blocker would contradict its own wording.
     *
     * `jurisdiction_note` blocks but does NOT declare عدم اختصاص: Art. 14 (ب)
     * is explicit that the legal member's opinion "لا يحل محل مداولة اللجنة أو
     * تصويتها، كما لا يمنح العضو القانوني سلطة منفردة في قبول الطلب أو رفضه" —
     * only the committee (Stage 49) or the pre-committee jurisdiction test
     * (Stage 54) may actually declare a matter outside its jurisdiction.
     */
    public const VERDICTS = [
        'sound_ready',          // سليم قانونيًا وجاهز للعرض
        'needs_document',       // يحتاج إلى استكمال مستند أو بيان
        'needs_clarification',  // يحتاج إلى إيضاح قانوني أو إداري
        'jurisdiction_note',    // توجد ملاحظة بشأن الاختصاص
        'present_with_note',    // مسألة قانونية تستوجب العرض على اللجنة مع بيانها
    ];

    /** The verdicts that let a request reach a meeting agenda. */
    public const PERMITTING_VERDICTS = ['sound_ready', 'present_with_note'];

    /** Appendix 22 — اختصاص اللجنة: قرار · توصية · رأي · دراسة فقط. */
    public const COMMITTEE_MANDATES = ['decision', 'recommendation', 'opinion', 'study_only'];

    /** Appendix 22 — هل يلزم اعتماد مركزي؟ نعم · لا · يحتاج إلى تحقق. */
    public const CENTRAL_APPROVAL_ANSWERS = ['yes', 'no', 'needs_verification'];

    protected $fillable = [
        'request_id',
        'primary_legislation',
        'article_reference',
        'supplementary_decision',
        'committee_mandate',
        'approving_body',
        'requires_central_approval',
        'legal_deadline',
        'prohibiting_conditions',
        'verdict',
        'legal_note',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function permitsAgenda(): bool
    {
        return in_array($this->verdict, self::PERMITTING_VERDICTS, strict: true);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
