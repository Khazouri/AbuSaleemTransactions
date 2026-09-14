<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ScreenRolePermission — one cell of the permission matrix.
 *
 * Each row answers, for a single (screen, role) pair, which of the seven
 * actions that role may perform on that screen — one row per pair, so the
 * matrix is exactly (screens x roles) and grows whenever either list does.
 * Together they are the SOURCE OF TRUTH for access control:
 *
 *   - Stage 5  sidebar shows only screens where can_view is true
 *   - Stage 8  admin grid edits these flags
 *   - Stage 9  API middleware and Vue route guards both read them, so the UI
 *              can never show an action the API would reject
 *
 * @property bool $can_view
 * @property bool $can_add
 * @property bool $can_edit
 * @property bool $can_delete
 * @property bool $can_approve
 * @property bool $can_print
 * @property bool $can_export
 */
class ScreenRolePermission extends Model
{
    /**
     * The seven action columns, in display order.
     *
     * Kept as a constant so the seeder, the Stage 8 bulk-save endpoint and the
     * Stage 9 checks all iterate the same list — adding an eighth action means
     * editing this array and the migration, nothing else.
     */
    public const ACTIONS = [
        'can_view',
        'can_add',
        'can_edit',
        'can_delete',
        'can_approve',
        'can_print',
        'can_export',
    ];

    /** Spread ACTIONS so every flag is mass-assignable in the bulk save. */
    protected $fillable = [
        'screen_id',
        'role_id',
        ...self::ACTIONS,
    ];

    /** Cast all seven flags to real booleans for clean JSON output. */
    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_add' => 'boolean',
            'can_edit' => 'boolean',
            'can_delete' => 'boolean',
            'can_approve' => 'boolean',
            'can_print' => 'boolean',
            'can_export' => 'boolean',
        ];
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
