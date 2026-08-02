<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'phone',
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

    // Stage 23 — where SmsChannel delivers to. Named by Laravel's convention
    // (routeNotificationFor<Channel>), so the channel never reads the column
    // itself and a future gateway can route on something else entirely.
    public function routeNotificationForSms(): ?string
    {
        return $this->phone;
    }

    /** Stage 23 — this user's per-event channel preferences. */
    public function notificationSettings(): HasMany
    {
        return $this->hasMany(NotificationSetting::class);
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

    /**
     * Resolved screen x action permission map — the SOURCE OF TRUTH consumed
     * by both CheckScreenPermission (API) and UserResource (so the SPA's
     * router guard and v-can directive see the same answer).
     *
     * Unioned across every role the user holds: if ANY role grants an action
     * on a screen, the user has it. Two queries regardless of role count
     * (roles, then the matching screen_role_permissions rows with their
     * screen eager-loaded) rather than N.
     *
     * @return array<string, array<string, bool>> screen code => action => bool
     */
    public function screenPermissions(): array
    {
        $roleIds = $this->roles()->pluck('roles.id');

        $rows = ScreenRolePermission::query()
            ->with('screen:id,code')
            ->whereIn('role_id', $roleIds)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $code = $row->screen->code;
            $map[$code] ??= array_fill_keys(ScreenRolePermission::ACTIONS, false);
            foreach (ScreenRolePermission::ACTIONS as $action) {
                $map[$code][$action] = $map[$code][$action] || $row->$action;
            }
        }

        return $map;
    }

    /**
     * Does any of the user's roles grant $action (e.g. 'can_view') on the
     * screen identified by $screenCode? The single check CheckScreenPermission
     * runs on every route it guards.
     */
    public function hasScreenPermission(string $screenCode, string $action): bool
    {
        return (bool) ($this->screenPermissions()[$screenCode][$action] ?? false);
    }
}
