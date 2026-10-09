<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One immutable workflow action on a request, written by Stage 14. */
class RequestStageLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['acted_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'to_stage_id');
    }

    public function actedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
