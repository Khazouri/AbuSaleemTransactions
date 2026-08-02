<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 23 — the in-app channel's store.
 *
 * This is Laravel's own `database` notification channel schema, kept to its
 * standard shape (uuid key, morph notifiable, json `data`, nullable `read_at`)
 * so `$user->notifications`, `unreadNotifications` and `markAsRead()` work
 * without a custom model. The bilingual title/body live inside `data`, written
 * by each notification's toArray(): a notification is a snapshot of what was
 * said at the time, not a live view of the record it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell's two queries: newest-first for one user, and the
            // unread count for that same user.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
