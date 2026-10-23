<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the managed documents list (قائمة المستندات), the list a
 * request type picks its [D] Appendix 57 rows from (user decision 2026-10-23).
 *
 * The id is the document's permanent number on the Request Types screen. A
 * picked row copies this entry's wording into the type and keeps
 * `document_id`, so renaming an entry later leaves the types alone: a type row's
 * Arabic label is part of the key every file answering it is stored under
 * (IntakeGateService::documentKey()), and a rename here must not re-key
 * files across every type at once.
 *
 * Master data, so it is deactivated, never deleted: an inactive entry leaves
 * the picker but stays on every type already listing it.
 */
class Document extends Model
{
    protected $fillable = [
        'name_ar',
        'name_en',
        'group',
        'condition_ar',
        'condition_en',
        'required',
        'section',
        'is_active',
    ];

    /** Restated so create() hands back what the database applied. */
    protected $attributes = [
        'group' => 'specific',
        'required' => true,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The catalogue entry for a type row, made from the row if there is none
     * yet. RequestTypeSeeder links its rows through this, matching by Arabic
     * name exactly as the migration that created the list did.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> the row with its `document_id`
     */
    public static function link(array $row): array
    {
        $document = self::firstOrCreate(['name_ar' => $row['ar']], [
            'name_en' => $row['en'] ?? null,
            'group' => $row['group'] ?? 'specific',
            'condition_ar' => $row['condition']['ar'] ?? null,
            'condition_en' => $row['condition']['en'] ?? null,
            'required' => RequestType::isRequired($row),
            'section' => $row['section'] ?? null,
        ]);

        return [...$row, 'document_id' => $document->id];
    }
}
