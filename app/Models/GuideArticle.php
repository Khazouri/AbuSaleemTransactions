<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One help article — Stage 27.
 *
 * @property string $code
 * @property string|null $category
 * @property string $title_ar
 * @property string $body_ar
 * @property bool $is_active
 */
class GuideArticle extends Model
{
    /**
     * Mirrors the table's own defaults so a freshly created article reports
     * the same values the database holds. Without this the API's 201 body
     * answers `is_active: null` for a row that is in fact published.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'code',
        'category',
        'title_ar',
        'title_en',
        'body_ar',
        'body_en',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
