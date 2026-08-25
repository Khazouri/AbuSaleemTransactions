<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 34 — the live runner's discussion feed for one agenda item. Shaped
 * after `notes` (see 2026_07_30_000007_create_notes_table.php): same
 * cascade/nullOnDelete pair, but append-only — no `is_internal` split, since
 * a meeting's discussion feed has no public/private audience the way a
 * transaction's notes do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_discussion_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_transaction_id')->constrained()->cascadeOnDelete();
            $table->text('note');
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['meeting_transaction_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_discussion_notes');
    }
};
