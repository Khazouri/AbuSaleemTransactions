<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 45 — [D] Art. 10's fixed 5-seat committee roster (chair, legal
 * member, HR-director member, ministry delegate, مقرر اللجنة) as a named
 * attribute of a membership row, alongside the existing open `is_head`
 * headcount rather than replacing it — most committees in this system are
 * still generic R03/R04 rosters, and only the ones modelling the standard's
 * institutional committee need the 5 named seats filled.
 *
 * `unique(['committee_id', 'seat'])` is enforced at the DB level: MySQL and
 * SQLite both treat every NULL as distinct in a unique index, so any number
 * of seatless ("general") members stay unrestricted while two members can
 * never hold the same named seat on the same committee at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('committee_members', function (Blueprint $table) {
            $table->string('seat')->nullable()->after('is_head');
            $table->unique(['committee_id', 'seat']);
        });
    }

    public function down(): void
    {
        Schema::table('committee_members', function (Blueprint $table) {
            $table->dropUnique(['committee_id', 'seat']);
            $table->dropColumn('seat');
        });
    }
};
