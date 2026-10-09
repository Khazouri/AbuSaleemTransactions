<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permission (الصلاحية) — one coarse capability, e.g. "requests.delete".
 *
 * This is the capability-level layer, useful for business-rule checks inside
 * services. The screen-level grid (ScreenRolePermission) is what governs UI
 * access and API route access — see the note in the permissions migration for
 * how the two layers divide the work.
 *
 * @property string $key Dot-notation, e.g. 'decisions.final_approve'
 * @property string $name_ar
 * @property string|null $group UI grouping: requests, workflow, admin...
 */
class Permission extends Model
{
    protected $fillable = [
        'key',
        'name_ar',
        'name_en',
        'group',
    ];

    /** Roles that have been granted this capability. */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }
}
