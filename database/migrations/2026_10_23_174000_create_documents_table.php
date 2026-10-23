<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The managed documents list a request type picks its Appendix 57 rows from
 * (user decision 2026-10-23). The id is the document's permanent number.
 *
 * Each type row keeps its own copy of the wording and gains `document_id`, so
 * nothing that keys a file by row (index + Arabic label hash) moves: no
 * attachment, gate record or checklist item is re-keyed by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 500)->unique();
            $table->string('name_en', 500)->nullable();
            // The defaults a type row starts from when the document is picked.
            $table->string('group', 20)->default('specific');
            $table->string('condition_ar')->nullable();
            $table->string('condition_en')->nullable();
            $table->boolean('required')->default(true);
            $table->string('section', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // One entry per distinct Arabic name, numbered in the order the
        // existing types list them, so the matrix the shared basics open
        // with gets the lowest numbers.
        $ids = [];

        foreach (DB::table('request_types')->orderBy('id')->get(['id', 'required_documents']) as $type) {
            $rows = json_decode($type->required_documents ?? 'null', true);

            if (! is_array($rows) || $rows === []) {
                continue;
            }

            foreach ($rows as $index => $row) {
                $name = trim((string) ($row['ar'] ?? ''));

                $ids[$name] ??= DB::table('documents')->insertGetId([
                    'name_ar' => $name,
                    'name_en' => $row['en'] ?? null,
                    'group' => $row['group'] ?? 'specific',
                    'condition_ar' => $row['condition']['ar'] ?? null,
                    'condition_en' => $row['condition']['en'] ?? null,
                    // RequestType::isRequired(), restated: the model may change after this migration.
                    'required' => (bool) ($row['required'] ?? (($row['condition'] ?? null) === null)),
                    'section' => $row['section'] ?? null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $rows[$index]['document_id'] = $ids[$name];
            }

            DB::table('request_types')->where('id', $type->id)->update(['required_documents' => json_encode($rows)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('request_types')->get(['id', 'required_documents']) as $type) {
            $rows = json_decode($type->required_documents ?? 'null', true);

            if (is_array($rows)) {
                $rows = array_map(function ($row) {
                    unset($row['document_id']);

                    return $row;
                }, $rows);
                DB::table('request_types')->where('id', $type->id)->update(['required_documents' => json_encode($rows)]);
            }
        }

        Schema::dropIfExists('documents');
    }
};
