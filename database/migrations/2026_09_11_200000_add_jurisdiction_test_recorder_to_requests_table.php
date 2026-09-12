<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 84 — the who/when pair [D] Art. 45's jurisdiction test never had.
 * ---------------------------------------------------------------------------
 * `requests.jurisdiction_test` was the only per-request control record in this
 * table with no recorder and no timestamp: `intake_gate`, `execution_soundness`,
 * `execution` and `closure` all carry one. Stage 78's own migration states that
 * convention in its docblock ("a json record plus its own who/when pair")
 * while anchoring `intake_gate` with `->after('jurisdiction_test')` — i.e.
 * directly beside the record that breaks it.
 *
 * The shape is copied column-for-column from the appeals side, which has had
 * exactly these two since Stage 62
 * (2026_09_05_100000_add_jurisdiction_test_and_legal_review_to_appeals_table),
 * so the two halves of "who answered the jurisdiction question" finally read
 * the same on both models.
 *
 * Nullable with no backfill on purpose: a row recorded before this stage has
 * no honest answer for who recorded it, and inventing one would be worse than
 * reporting none — the same call Stage 80 made for `attachments.file_section`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('jurisdiction_tested_by_user_id')->nullable()->after('jurisdiction_test')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('jurisdiction_tested_at')->nullable()->after('jurisdiction_tested_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jurisdiction_tested_by_user_id');
            $table->dropColumn('jurisdiction_tested_at');
        });
    }
};
