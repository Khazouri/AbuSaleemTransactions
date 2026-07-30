<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal discussion attached to a transaction; populated in Stage 13. */
class Note extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
