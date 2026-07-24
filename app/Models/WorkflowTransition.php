<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTransition extends Model
{
    protected $fillable = [
        'transaction_type_id',
        'from_stage_id',
        'to_stage_id',
        'action',
        'required_role_id',
        'set_status_id',
        'is_exception',
        'requires_comment',
        'order_no',
    ];

    protected function casts(): array
    {
        return [
            'is_exception' => 'boolean',
            'requires_comment' => 'boolean',
        ];
    }

    public function transactionType(): BelongsTo
    {
        return $this->belongsTo(TransactionType::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'to_stage_id');
    }

    public function requiredRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'required_role_id');
    }

    public function setStatus(): BelongsTo
    {
        return $this->belongsTo(TransactionStatus::class, 'set_status_id');
    }
}
