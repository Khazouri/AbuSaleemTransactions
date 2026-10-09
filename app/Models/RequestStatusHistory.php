<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One immutable status change on a request, written by Stage 14. */
class RequestStatusHistory extends Model
{
    // Migration names the append-only table singularly to match the domain
    // phrase; make that explicit so Eloquent does not infer `...histories`.
    protected $table = 'request_status_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(RequestStatus::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(RequestStatus::class, 'to_status_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
