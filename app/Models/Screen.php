<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Screen extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'route',
        'icon',
        'parent_id',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Screen::class, 'parent_id');
    }

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(ScreenRolePermission::class);
    }
}
