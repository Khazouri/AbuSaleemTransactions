<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stage 78 — one round of [D] Art. 105's procedural suspension.
 *
 * Art. 105 is one sentence and it describes an action, not a flag: "إذا ظهر
 * قبل الاعتماد أو التنفيذ أن معلومة جوهرية غير صحيحة أو أن مستندًا أساسيًا محل
 * شك: **يوقف التنفيذ فورًا من الناحية الإجرائية ويحال الموضوع للمراجعة
 * القانونية والجهة المختصة قبل ترتيب أثر جديد عليه**."
 *
 * A history rather than a column, for Stage 77's reason: the article names a
 * cause *and* a consequence, nobody knows the second at the moment of the
 * first, and a file can be suspended more than once. Only the newest
 * unresolved row gates anything — see Request::openSuspension().
 *
 * @property string $ground One of self::GROUNDS
 * @property string|null $resolution_action One of self::RESOLUTIONS
 */
class RequestSuspension extends Model
{
    /** Art. 105's own two grounds, and there are no others in the article. */
    public const GROUND_INCORRECT_FACT = 'incorrect_material_fact';

    public const GROUND_DOCUMENT_IN_DOUBT = 'document_in_doubt';

    /** @var array<string, string> */
    public const GROUNDS = [
        self::GROUND_INCORRECT_FACT => 'معلومة جوهرية غير صحيحة',
        self::GROUND_DOCUMENT_IN_DOUBT => 'مستند أساسي محل شك',
    ];

    /**
     * What the legal review concluded, and therefore where the file goes.
     *
     * The article says the matter is referred "للمراجعة القانونية والجهة
     * المختصة **قبل ترتيب أثر جديد عليه**" — so a suspension ends either with
     * the doubt cleared (the file resumes exactly where it was frozen) or
     * with the matter going back to the committee, which is Art. 78's إعادة
     * العرض and is the same branch Stage 77's substantive return already
     * takes. There is deliberately no third "cancel it" outcome: ending a
     * matter is a committee decision, not a consequence of a document check.
     *
     * @var array<string, string>
     */
    public const RESOLUTIONS = [
        'fact_confirmed' => 'ثبتت صحة المعلومة أو المستند، وتستأنف المعاملة مسارها',
        'referred_to_committee' => 'أعيد الموضوع إلى اللجنة لإعادة العرض',
    ];

    protected $fillable = [
        'request_id',
        'suspended_from_status_id',
        'ground',
        'detail',
        'suspended_by_user_id',
        'suspended_at',
        'resolution_action',
        'resolution_note',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public static function groundLabel(string $ground): string
    {
        return self::GROUNDS[$ground] ?? $ground;
    }

    public function requestRecord(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'request_id');
    }

    public function suspendedFromStatus(): BelongsTo
    {
        return $this->belongsTo(RequestStatus::class, 'suspended_from_status_id');
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by_user_id');
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
