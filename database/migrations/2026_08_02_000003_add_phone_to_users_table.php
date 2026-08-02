<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 23 — where the SMS channel delivers to.
 *
 * Nullable and unvalidated at the schema level because it is optional contact
 * detail, not an identity: a user without one simply never gets the SMS
 * channel (see NotificationSetting::channelsFor), rather than being blocked
 * from using the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
