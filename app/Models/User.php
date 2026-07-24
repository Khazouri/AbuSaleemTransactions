<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * User (المستخدم) — a municipality employee who logs into the system.
 *
 * A user belongs to one department and holds one or more roles. They have no
 * permissions of their own: everything they may do is inherited from the roles
 * attached to them.
 *
 * @property string      $name
 * @property string      $email
 * @property int|null    $department_id
 * @property bool        $is_active     False blocks login (see AuthController)
 */
class User extends Authenticatable
{
    /**
     * HasApiTokens  — issues Sanctum bearer tokens for the Vue SPA
     * Notifiable    — receives the notifications built in Stage 23
     * SoftDeletes   — keeps the record alive for historical transactions
     *
     * @use HasFactory<\Database\Factories\UserFactory>
     */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Columns allowed in mass assignment (User::create([...])).
     * Anything absent here must be set explicitly, which is what stops a
     * crafted request from smuggling in unexpected fields.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'department_id',
        'is_active',
    ];

    /**
     * Never included when the model is serialised to JSON — without this the
     * password hash would leak into every API response.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // 'hashed' auto-bcrypts on assignment, so $user->password = 'x'
            // stores a hash. Never call Hash::make() on top of this.
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** The department this employee works in (الإدارة التابع لها). */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Roles held by this user — the source of all their capabilities. */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /**
     * Does the user hold this role? e.g. hasRole('R08') for System Admin.
     *
     * Reads the already-loaded `roles` collection, so eager-load it
     * (->load('roles')) before calling this in a loop to avoid N+1 queries.
     */
    public function hasRole(string $code): bool
    {
        return $this->roles->contains('code', $code);
    }

    /**
     * Does ANY of the user's roles grant this capability?
     *
     * Effective permissions are the union across all their roles: holding both
     * R03 and R04 gives the combined set, never the intersection.
     */
    public function hasPermission(string $key): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('key', $key))
            ->exists();
    }
}
