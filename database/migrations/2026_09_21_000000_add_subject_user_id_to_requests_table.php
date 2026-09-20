<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 95 — صاحب العلاقة: the person a request is ABOUT, which until now was
 * always assumed to be the person who filed it.
 *
 * [D] Appendix 6's rows 1, 2, 3 and 14 each name a party relative to the
 * employee the matter concerns — الرئيس المباشر is *that employee's* manager,
 * not the clerk's — and `requests` carried only `created_by_user_id`, so a file
 * raised on an employee's behalf routed to the filer's own manager, silently.
 *
 * **Nullable, and backfilled to the creator.** Every row that already exists
 * was filed by its own subject (nothing could express anything else), so the
 * backfill below is a statement of fact rather than a guess. New rows get the
 * same default from Request's `creating` hook, which is what lets every read
 * site use this column directly instead of coalescing at twenty query sites —
 * and what keeps every fixture built with `Request::create()` truthful with no
 * change at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->foreignId('subject_user_id')->nullable()->after('created_by_user_id')
                ->constrained('users')->nullOnDelete();
        });

        DB::table('requests')->update(['subject_user_id' => DB::raw('created_by_user_id')]);
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_user_id');
        });
    }
};
