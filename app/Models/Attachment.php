<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadata for a file stored by the upload endpoint added in Stage 12. */
class Attachment extends Model
{
    protected $guarded = [];

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
