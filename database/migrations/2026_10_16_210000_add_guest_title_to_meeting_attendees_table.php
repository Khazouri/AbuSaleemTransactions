<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An extra member the مقرر invites beyond the five seats (user decision
 * 2026-10-16) attends in a صفة typed on the invitation, not on the account, so
 * the same person can come in another capacity next time. A non-null value is
 * also what marks the row as a guest: a seat row never has one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->string('guest_title')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->dropColumn('guest_title');
        });
    }
};
