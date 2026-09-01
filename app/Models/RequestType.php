<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RequestType (نوع الطلب) — promotion, leave, grievance, etc.
 *
 * Beyond labelling, type carries two rules:
 *   - default_sla_days          how long the request may take (Stage 17)
 *   - decision_grade_threshold  the decision grade at/above which the case must
 *                               go to وزارة الحكم المحلي (Stage 18)
 *
 * @property string|null $code
 * @property int|null $default_sla_days
 * @property int|null $decision_grade_threshold Typically 10
 * @property bool $is_active
 * @property bool $default_has_financial_impact Stage 47 — starting value for a
 *                                              new request's own has_financial_impact flag; overridable per request.
 */
class RequestType extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'default_sla_days',
        'decision_grade_threshold',
        'is_active',
        'default_has_financial_impact',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_has_financial_impact' => 'boolean',
        ];
    }
}
