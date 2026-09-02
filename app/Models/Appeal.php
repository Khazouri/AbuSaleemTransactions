<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Appeal (تظلم) — Stage 58, Track J: a contest against an already-decided
 * Request, never a Request itself and never routed through WorkflowService.
 * See STAGE_PLAN.md Track J's intro for the two scope decisions this rests
 * on (a new entity, not a RequestType; its own status machine, not the
 * 14-stage workflow_stages table).
 *
 * @property int|null $appellant_user_id
 * @property int $original_request_id
 * @property int|null $original_decision_id
 * @property string|null $original_decision_reference Free-text fallback for
 *                                                    a decided matter that has no `decisions` row (e.g.
 *                                                    Stage 54's reject_formally at requirements_check).
 * @property Carbon|null $original_decision_date
 * @property int|null $appeal_status_id
 */
class Appeal extends Model
{
    protected $fillable = [
        'appellant_user_id',
        'original_request_id',
        'original_decision_id',
        'original_decision_reference',
        'original_decision_date',
        'appeal_status_id',
    ];

    protected function casts(): array
    {
        return [
            'original_decision_date' => 'date',
        ];
    }

    public function appellant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appellant_user_id');
    }

    public function originalRequest(): BelongsTo
    {
        return $this->belongsTo(Request::class, 'original_request_id');
    }

    public function originalDecision(): BelongsTo
    {
        return $this->belongsTo(Decision::class, 'original_decision_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(AppealStatus::class, 'appeal_status_id');
    }
}
