<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 23 — per-user, per-event channel preferences.
 *
 * Sparse by design: a user with no row for an event type falls back to
 * NotificationSetting::EVENT_TYPES' defaults. That is why no backfill runs
 * here and why adding a seventh event type later needs no migration — only
 * the people who actually changed something have rows.
 *
 * Deliberately NOT a foreign key on `event_type`: the event registry is code,
 * not data, and a lookup table would let a row exist for an event no
 * notification class can raise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64);

            // In-app is the system of record for what a user was told, so it
            // defaults on; email is opt-out per event and SMS is opt-in, since
            // an SMS costs money and reaches people outside working hours.
            $table->boolean('in_app')->default(true);
            $table->boolean('email')->default(true);
            $table->boolean('sms')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
