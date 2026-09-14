<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Screen (شاشة) — one of the 22 pages in the application.
 *
 * Screens are data, not hard-coded menu entries. The sidebar is generated from
 * this table (Stage 5), and each screen forms one axis of the permission
 * matrix, the other being Role.
 *
 * @property string $code Stable key, e.g. 'request_details'
 * @property string $name_ar
 * @property string|null $route Matching Vue router path
 * @property string|null $group Stage 28: sidebar cluster slug, e.g. 'meetings_management'
 * @property int $sort_order
 * @property bool $is_active
 */
class Screen extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'route',
        'icon',
        'parent_id',
        'group',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** Parent menu entry, when screens are grouped in the sidebar. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Screen::class, 'parent_id');
    }

    /** Screens nested beneath this one. */
    public function children(): HasMany
    {
        return $this->hasMany(Screen::class, 'parent_id');
    }

    /**
     * This screen's permission rows — one per role, so the count follows
     * RoleSeeder rather than being fixed. The screen's slice of the matrix.
     */
    public function rolePermissions(): HasMany
    {
        return $this->hasMany(ScreenRolePermission::class);
    }
}
