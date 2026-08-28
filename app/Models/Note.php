<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal discussion attached to a request; populated in Stage 13. */
class Note extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
