<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Committee (اللجنة) — a standing body that reviews requests in meetings.
 *
 * @property string $name_ar
 * @property string|null $name_en
 * @property string|null $description
 * @property bool $is_active
 */
class Committee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name_ar',
        'name_en',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommitteeMember::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    /** Members currently eligible to be invited to a new meeting. */
    public function activeMembers(): HasMany
    {
        return $this->members()->whereHas('user', fn ($query) => $query->where('is_active', true));
    }
}
