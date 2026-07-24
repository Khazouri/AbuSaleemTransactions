<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role (الدور) — one of the eight fixed system roles, R01..R08.
 *
 * Roles are the hinge of the whole authorisation design: permissions attach to
 * roles (never to users), workflow transitions require a role, and the
 * permission matrix is indexed by role. A user gains capabilities purely by
 * holding roles.
 *
 * Always identify a role by its `code` ('R08'), not its database id — ids
 * differ between a fresh seed and production, codes don't.
 *
 * @property string      $code   R01..R08
 * @property string      $name_ar
 * @property string|null $name_en
 */
class Role extends Model
{
    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'description',
    ];

    /**
     * Users holding this role (many-to-many via the role_user pivot).
     * withTimestamps() records when the role was granted.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** Coarse capabilities granted to this role (via permission_role). */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->withTimestamps();
    }

    /**
     * Does this role carry the given capability key, e.g. 'transactions.delete'?
     *
     * Queries the pivot directly instead of loading the whole permission list,
     * which keeps it cheap when checking a single key.
     */
    public function hasPermission(string $key): bool
    {
        return $this->permissions()->where('key', $key)->exists();
    }
}
