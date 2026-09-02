<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * Stage 52 — the log row that put this request at its current stage. Every
     * transition that changes current_stage_id writes a matching stage-log
     * row in the same DB transaction (WorkflowService/CommitteeStatusService),
     * so the latest row's acted_at is definitionally the current-stage entry
     * time — no separate "stage entered at" column needed.
     */
    public function latestStageLog(): HasOne
    {
        return $this->hasOne(RequestStageLog::class)->latestOfMany('acted_at');
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

    /**
     * Stage 51 — [A] §7's "المستندات الناقصة" flag, derived from status
     * rather than a separate structured checklist: `incomplete` (Stage 16's
     * return_missing_docs) and `completion_required` (Stage 29's committee
     * sub-state) are the two statuses that mean "the file is not ready".
     */
    public function documentsComplete(): bool
    {
        return ! in_array($this->status?->code, ['incomplete', 'completion_required'], true);
    }

    /**
     * Stage 52 — a non-binding, per-stage soft-SLA indicator, distinct from
     * isOverdue()'s hard per-type deadline. Null when the current stage has
     * no sourced target (see WorkflowStageSeeder) — the absence of a target
     * is never rendered as "on target".
     *
     * The four-bucket ratio scheme (elapsed / target_days_max) is a
     * documented judgment call — the source names the four buckets
     * (أخضر/أصفر/أحمر/حرج) but not their boundaries. See AGENT_NOTES.md.
     */
    public function stageTimeliness(): ?array
    {
        $stage = $this->currentStage;

        if ($stage === null || $stage->target_days_max === null) {
            return null;
        }

        $enteredAt = $this->latestStageLog?->acted_at;

        if ($enteredAt === null) {
            return null;
        }

        $elapsedDays = max(0, $enteredAt->copy()->startOfDay()->diffInDays(now()->startOfDay()));
        $ratio = $elapsedDays / $stage->target_days_max;

        $level = match (true) {
            $ratio <= 1.0 => 'green',
            $ratio <= 1.5 => 'yellow',
            $ratio <= 2.0 => 'red',
            default => 'critical',
        };

        return [
            'level' => $level,
            'elapsed_days' => $elapsedDays,
            'target_days_min' => $stage->target_days_min,
            'target_days_max' => $stage->target_days_max,
        ];
    }
}
