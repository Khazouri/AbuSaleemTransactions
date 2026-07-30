<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A bilingual reusable subject/body pair, managed before it is wired to workflow events. */
class Template extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'subject_ar',
        'subject_en',
        'body_ar',
        'body_en',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
