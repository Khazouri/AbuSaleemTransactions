<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user opened a request file's full preview. Written only by
 * AttachmentController::previewed(); the document gates read it and
 * AuditObserver logs each row.
 */
class AttachmentPreview extends Model
{
    protected $fillable = ['attachment_id', 'user_id'];
}
