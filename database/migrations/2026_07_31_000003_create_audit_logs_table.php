<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUDIT_LOGS (سجل التدقيق) — Stage 22's append-only record of who changed what.
 *
 * Written exclusively by App\Observers\AuditObserver on the models listed in
 * AppServiceProvider::AUDITED_MODELS; nothing in the app updates or deletes a
 * row here, which is the whole point — the trail has to outlive the record it
 * describes. That is also why the morph columns are plain columns with no
 * foreign key: a deleted transaction must not take its audit history with it.
 *
 * `user_id` is nullable because not every change has a signed-in actor behind
 * it (console commands, future queued jobs), and nullOnDelete keeps the entry
 * readable after the actor's account is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');

            // created | updated | deleted | restored — the Eloquent event name.
            $table->string('action', 20);

            // Only the attributes that actually changed, sensitive keys
            // redacted. Null on create (nothing came before) and on delete
            // (nothing comes after).
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            // The viewer's three access paths: one record's history, one
            // person's activity, and the default reverse-chronological feed
            // that the action filter narrows.
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
