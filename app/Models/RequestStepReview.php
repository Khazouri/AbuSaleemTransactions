<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workflow designer — slice 4. One completed (or partly completed) checklist
 * for one arrival of a request at a custom step. `answers` is keyed by the
 * item's key; an attachment item's answer is {attachment_id: verdict}.
 */
class RequestStepReview extends Model
{
    protected $fillable = [
        'request_id',
        'workflow_stage_id',
        'arrival_log_id',
        'answers',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['answers' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
