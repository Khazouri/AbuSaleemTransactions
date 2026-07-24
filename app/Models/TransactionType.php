<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TransactionType (نوع المعاملة) — promotion, leave, grievance, etc.
 *
 * Beyond labelling, type carries two rules:
 *   - default_sla_days          how long the transaction may take (Stage 17)
 *   - decision_grade_threshold  the decision grade at/above which the case must
 *                               go to وزارة الحكم المحلي (Stage 18)
 *
 * @property string|null $code
 * @property int|null    $default_sla_days
 * @property int|null    $decision_grade_threshold  Typically 10
 * @property bool        $is_active
 */
class TransactionType extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'default_sla_days',
        'decision_grade_threshold',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
