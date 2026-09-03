<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadata for a supporting document uploaded against an Appeal — Stage 59. */
class AppealAttachment extends Model
{
    protected $guarded = [];

    public function appeal(): BelongsTo
    {
        return $this->belongsTo(Appeal::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
