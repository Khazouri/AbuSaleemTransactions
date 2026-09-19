<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\MeetingVisibility;
use Database\Factories\UserFactory;
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
 * @property string $name
 * @property string $email
 * @property int|null $department_id
 * @property bool $is_active False blocks login (see AuthController)
 */
class User extends Authenticatable
{
    /**
     * HasApiTokens  — issues Sanctum bearer tokens for the Vue SPA
     * Notifiable    — receives the notifications built in Stage 23
     * SoftDeletes   — keeps the record alive for historical requests
     *
     * @use HasFactory<UserFactory>
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
        'manager_id',
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
     * Memoized screenPermissions() result, per model instance.
     *
     * Not an attribute — a plain property, so it never reaches toArray(), the
     * database or a serialized payload. Memoizing matters now that resolving
     * the map also asks whether the user holds a committee seat:
     * RequestVisibility::apply() calls hasScreenPermission() three times and
     * canView() wraps apply(), so an unmemoized map would run that seat query
     * on every one of them.
     *
     * Per-instance rather than static or container-level on purpose: a real
     * request resolves one actor, while a test that seats a user between two
     * calls gets a fresh instance from the database and so is never stale.
     * Where an instance IS mutated in place, call forgetScreenPermissions().
     *
     * @var array<string, array<string, bool>>|null
     */
    private ?array $screenPermissionsCache = null;

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

    /**
     * This employee's direct manager (المدير المباشر) — the actor
     * WorkflowService::actorMayUse resolves for `requires_submitter_manager`
     * rows, and the ONLY actor who may use one — there is no admin override.
     * Nullable, and the consequence of leaving it null is real: that
     * employee's requests cannot be delegated out of direct_manager_review
     * by anyone at all until a live manager is assigned here.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Employees who report directly to this user. */
    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    /**
     * Seats this user holds on committees.
     *
     * Membership gate — the inverse relation that did not exist before it was
     * needed: nothing in the app could ask "which committees is this person
     * on" without querying CommitteeMember directly, which is why the three
     * pre-existing membership checks each spelled that query out inline.
     */
    public function committeeMemberships(): HasMany
    {
        return $this->hasMany(CommitteeMember::class);
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
        if ($this->screenPermissionsCache !== null) {
            return $this->screenPermissionsCache;
        }

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

        return $this->screenPermissionsCache = $this->hideMeetingsSectionIfUnseated($map);
    }

    /**
     * Membership gate — a user with no committee seat does not get the
     * meetings section at all.
     *
     * Applied HERE, inside the resolved map, because this method is the single
     * source of truth every consumer reads: CheckScreenPermission on the API,
     * UserResource for the SPA's router guard and v-can, and ScreenController
     * for the sidebar. Gating any one of those alone would let them disagree —
     * a sidebar that lists links the API refuses, or a guard that admits a
     * route whose every call then 403s.
     *
     * ONLY can_view is suppressed. See MeetingVisibility::GATED_SCREENS for
     * why touching the other flags would break request visibility for R11,
     * R02, R03 and R12.
     *
     * @param  array<string, array<string, bool>>  $map
     * @return array<string, array<string, bool>>
     */
    private function hideMeetingsSectionIfUnseated(array $map): array
    {
        if (app(MeetingVisibility::class)->sitsOnAnyCommittee($this)) {
            return $map;
        }

        foreach (MeetingVisibility::GATED_SCREENS as $code) {
            if (isset($map[$code])) {
                $map[$code]['can_view'] = false;
            }
        }

        return $map;
    }

    /**
     * Drop the memoized permission map.
     *
     * Needed only where roles or committee membership change on an instance
     * that has already answered once — in practice a test, since a real
     * request resolves the actor fresh.
     */
    public function forgetScreenPermissions(): void
    {
        $this->screenPermissionsCache = null;
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
