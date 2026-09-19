<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signatures are removed from the system — each attendee's sign-off on a
 * meeting's minutes is now a plain confirmation click, no drawn/uploaded
 * image. The `meeting_minute_signatures` table, its `signed_at` timestamp,
 * and the whole draft → pending_signatures → approved lifecycle are
 * unchanged; only the column that once held a stored PNG's path is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_minute_signatures', function (Blueprint $table) {
            $table->dropColumn('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_minute_signatures', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('user_id');
        });
    }
};
