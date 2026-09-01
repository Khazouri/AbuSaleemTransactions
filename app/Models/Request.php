<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A staff-affairs request travelling through the 11-stage workflow.
 *
 * `status` answers how the request is doing; `currentStage` answers where it
 * is. Their histories are separate because exception handling can change one
 * without necessarily changing the other.
 */
class Request extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'title',
        'description',
        'department_id',
        'request_type_id',
        'status_id',
        'current_stage_id',
        'created_by_user_id',
        'submitted_at',
        'due_date',
        'decision_grade',
        'has_financial_impact',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'due_date' => 'date',
            'overdue_at' => 'datetime',
            'has_financial_impact' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(RequestStatus::class);
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function stageLogs(): HasMany
    {
        return $this->hasMany(RequestStageLog::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(RequestStatusHistory::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /** Stage 44 — every agenda slot this request has ridden, across meetings. */
    public function meetingRequests(): HasMany
    {
        return $this->hasMany(MeetingRequest::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * Missing legacy grades take the conservative route through ministry.
     * A null threshold explicitly means that this type never needs ministry.
     */
    public function requiresMinistryApproval(): bool
    {
        $threshold = $this->requestType?->decision_grade_threshold;

        return $threshold !== null
            && ($this->decision_grade === null || $this->decision_grade >= $threshold);
    }

    /** A breach becomes official only when the scheduled sweep records it. */
    public function isOverdue(): bool
    {
        return $this->overdue_at !== null;
    }
}
