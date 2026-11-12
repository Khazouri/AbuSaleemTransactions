<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

// Learning centre — narrated walkthroughs.
/**
 * One catalogue entry for a recorded walkthrough. Who may watch it is decided
 * here and nowhere else: the list, the per-screen lookup and the stream all
 * call isVisibleTo().
 *
 * @property string $code
 * @property string $screen_code
 * @property string $action
 * @property list<string>|null $roles
 * @property string|null $path
 */
class WalkthroughVideo extends Model
{
    /** In `roles`: the viewer has at least one live direct report. */
    public const MANAGER = 'manager';

    protected $fillable = [
        'code', 'title_ar', 'title_en', 'description_ar', 'description_en',
        'screen_code', 'action', 'roles', 'sort_order',
        'disk', 'path', 'mime_type', 'size_bytes', 'duration_seconds',
        'uploaded_by', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    /**
     * A row with no file is invisible to everyone; otherwise the viewer needs
     * the learning centre itself and the row's action on its screen (so the designer flag, stand-in grants and
     * every other rule folded into screenPermissions() apply for free) and,
     * when `roles` is set, one of those roles or the manager token.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->path === null) {
            return false;
        }

        // The stream trusts this method alone, so revoking the centre itself must end playback too.
        if (! $user->hasScreenPermission('learning_center', 'can_view')) {
            return false;
        }

        if (! ($user->screenPermissions()[$this->screen_code]['can_'.$this->action] ?? false)) {
            return false;
        }

        if ($this->roles === null) {
            return true;
        }

        foreach ($this->roles as $role) {
            if ($role === self::MANAGER ? $this->manages($user) : $user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /** SoftDeletes on User already leaves out a deleted report. */
    private function manages(User $user): bool
    {
        return $user->directReports()->where('is_active', true)->exists();
    }

    /**
     * ponytail: filters ~45 uploaded rows in PHP; make it a query scope if the
     * catalogue ever grows large.
     *
     * @return Collection<int, self>
     */
    public static function visibleTo(User $user): Collection
    {
        $user->loadMissing('roles');

        return static::query()->whereNotNull('path')->orderBy('sort_order')->get()
            ->filter(fn (self $video) => $video->isVisibleTo($user))
            ->values();
    }
}
