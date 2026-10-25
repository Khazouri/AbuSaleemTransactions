<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The محضر is scanned pages, images only, in the order the مقرر arranged them
 * (user decision 2026-10-25). An ordered list of paths on the meeting row. The
 * one-file `minutes_file_*` columns stay, so a محضر entered before this (often
 * a PDF) still opens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->json('minutes_pages')->nullable()->after('minutes_file_mime');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('minutes_pages');
        });
    }
};
