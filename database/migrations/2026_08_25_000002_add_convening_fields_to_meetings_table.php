<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 33 — the readiness control center's gate. `convened_at` is a
 * forward-compatible flag: nothing today reads it, but Stage 34's live
 * runner can require it before letting a chair start running agenda items,
 * which is exactly the "nothing today -> a pre-meeting gate" mechanism this
 * stage exists to build. `readiness_override_reason` is only ever set
 * alongside `convened_at` when readiness had unresolved exceptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dateTime('convened_at')->nullable()->after('minutes');
            $table->foreignId('convened_by_user_id')->nullable()
                ->after('convened_at')->constrained('users')->nullOnDelete();
            $table->text('readiness_override_reason')->nullable()->after('convened_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('convened_by_user_id');
            $table->dropColumn(['convened_at', 'readiness_override_reason']);
        });
    }
};
