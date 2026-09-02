<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AppealStatus (حالة التظلم) — Stage 58's own small, sequential status
 * machine for appeals, deliberately separate from RequestStatus and from
 * WorkflowStage. See STAGE_PLAN.md Track J's intro, scope decision (2).
 *
 * @property int $order_no Position in the [A] §9 6-step sequence.
 * @property string $code
 * @property string $name_ar
 * @property string|null $name_en
 * @property string|null $color Hex colour for the UI status badge.
 */
class AppealStatus extends Model
{
    protected $fillable = [
        'order_no',
        'code',
        'name_ar',
        'name_en',
        'color',
    ];
}
