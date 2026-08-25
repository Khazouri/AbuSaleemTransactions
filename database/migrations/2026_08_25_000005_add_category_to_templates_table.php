<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 35 — the first real consumer of `Template` (decision text) needs a
 * way to ask for "only the templates meant for this". A free string rather
 * than an enum: `Template` may gain other consumers later with their own
 * category names, and this table doesn't own that list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('category', 50)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
