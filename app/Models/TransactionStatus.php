<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TransactionStatus (حالة المعاملة) — the condition a transaction is in.
 *
 * Distinct from STAGE: stage is *where* it is in the pipeline, status is *how*
 * it is doing there. A transaction can stay at stage 3 while moving from
 * `in_review` to `incomplete` because documents were found missing.
 *
 * @property string      $code   new, in_review, approved, archived...
 * @property string      $name_ar
 * @property string|null $color  Hex colour for the UI status badge
 */
class TransactionStatus extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'color',
    ];
}
