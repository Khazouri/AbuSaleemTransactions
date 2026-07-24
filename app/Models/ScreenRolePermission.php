<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreenRolePermission extends Model
{
    /** The seven action flags held per screen/role pair. */
    public const ACTIONS = [
        'can_view',
        'can_add',
        'can_edit',
        'can_delete',
        'can_approve',
        'can_print',
        'can_export',
    ];

    protected $fillable = [
        'screen_id',
        'role_id',
        ...self::ACTIONS,
    ];

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
