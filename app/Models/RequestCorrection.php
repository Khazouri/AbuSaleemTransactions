<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stage 83 — [D] Appendix 53's مذكرة تصحيح معتمدة.
 *
 * Two moments, because "معتمدة" is the appendix's own word: a recorded memo
 * corrects nothing until it is approved. **Nothing on the request or on the
 * decision is rewritten** — "فيتم تصحيحه بمذكرة تصحيح معتمدة، **دون تغيير
 * جوهر النتيجة**" means the correction is a document added to the file, so the
 * incorrect and corrected values are held here and the original record stands
 * exactly as it was issued.
 *
 * Only the appendix's five *material* kinds ever reach `error_kind`; its six
 * substantive ones are refused by Lifecycle\CorrectionRules and routed to the
 * formal review the appendix itself names — which in this system is Stage 66's
 * reopen, whose ReopenReasonCatalog code is already literally
 * `material_error_correction` / تصحيح خطأ جوهري.
 *
 * @property int $id
 * @property int $request_id
 * @property string $error_kind One of Lifecycle\CorrectionRules::MATERIAL_KINDS
 * @property Carbon|null $approved_at
 */
class RequestCorrection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
