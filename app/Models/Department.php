<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Department (الإدارة / القسم)
 *
 * A node in the municipality's org tree. Self-referencing: `parent_id` points
 * at the department above, which is what makes the structure a hierarchy
 * rather than a flat list.
 *
 * @property string      $name_ar
 * @property string|null $name_en
 * @property string|null $code       Short code (ADM, ENG...) used in reference numbers
 * @property int|null    $parent_id
 * @property bool        $is_active
 */
class Department extends Model
{
    /** SoftDeletes: a "deleted" department is hidden but still resolvable
     *  by the transactions and users that reference it. */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name_ar',
        'name_en',
        'code',
        'parent_id',
        'is_active',
    ];

    /**
     * Cast the tinyint(1) column to a real PHP bool, so `$dept->is_active`
     * is true/false rather than 1/0.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** The department directly above this one. Null for the root. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /**
     * Departments sitting directly beneath this one.
     * Load recursively with ->with('children.children') when rendering the tree.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }

    /** Employees assigned to this department. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
