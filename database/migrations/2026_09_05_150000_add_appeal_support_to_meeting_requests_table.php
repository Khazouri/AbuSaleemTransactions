<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 63, Track J — appeals ride the existing agenda/vote/signature
 * machinery via a fourth `item_type` value (`appeal`), sitting alongside
 * `employee_request`/`administrative`/`emerging` (Stage 31) rather than a
 * parallel mechanism. `request_id` is already nullable (Stage 31), so this
 * only needs the mirror column: `appeal_id`, cascadeOnDelete (an agenda slot
 * is meaningless once the appeal it presents is gone, the same reasoning
 * `request_id` already gets) and unique per meeting the same way
 * `request_id` already is — MySQL/SQLite both treat every NULL in a unique
 * index as distinct, so this coexists safely with every other item type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->foreignId('appeal_id')->nullable()->after('request_id')
                ->constrained('appeals')->cascadeOnDelete();
            $table->unique(['meeting_id', 'appeal_id']);
        });
    }

    public function down(): void
    {
        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->dropUnique(['meeting_id', 'appeal_id']);
            $table->dropConstrainedForeignId('appeal_id');
        });
    }
};
