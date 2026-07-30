<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A configurable application value, addressed by a stable key. */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];
}
