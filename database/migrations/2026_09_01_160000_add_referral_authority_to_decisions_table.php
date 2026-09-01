<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 50 — [D] Art. 28's minutes-content list expects a record of which
 * authority a matter was referred to; nothing captured that anywhere before
 * this (only the free-text `comment`, unstructured). Optional on every
 * outcome — the committee head fills it in when relevant, same as `comment`
 * itself is optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->string('referral_authority')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('decisions', function (Blueprint $table) {
            $table->dropColumn('referral_authority');
        });
    }
};
