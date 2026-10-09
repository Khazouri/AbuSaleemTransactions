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
 * @property string $name_ar
 * @property string|null $name_en
 * @property string|null $code Short code (ADM, ENG...) used in reference numbers
 * @property int|null $parent_id
 * @property bool $is_active
 */
class Department extends Model
{
    /** SoftDeletes: a "deleted" department is hidden but still resolvable
     *  by the requests and users that reference it. */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name_ar',
        'name_en',
        'code',
        'parent_id',
        'manager_user_id',
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

    /**
     * Ids of every department below this one, at any depth.
     *
     * Exists to stop a cycle. If a department were re-parented under one of its
     * own descendants, that branch would point at itself in a loop: it would
     * vanish from the tree (no path from the root) and any recursive walk over
     * it would never terminate. UpdateDepartmentRequest rejects such a move.
     *
     * Fetches the whole table once and walks it in memory — one query no
     * matter how deep the tree, and an org chart is small enough that loading
     * it entirely is cheaper than a query per level.
     *
     * Iterative rather than recursive, so a malformed tree can't blow the
     * stack.
     *
     * @return array<int> descendant ids (excludes this department)
     */
    public function descendantIds(): array
    {
        // Children grouped by their parent, so lookups below are O(1).
        $byParent = static::query()
            ->select('id', 'parent_id')
            ->get()
            ->groupBy('parent_id');

        $ids = [];
        $queue = [$this->id];

        while ($queue) {
            $currentId = array_pop($queue);

            foreach ($byParent[$currentId] ?? [] as $child) {
                $ids[] = $child->id;
                // Descend into this child on a later pass.
                $queue[] = $child->id;
            }
        }

        return $ids;
    }
}
