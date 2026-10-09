<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 46 — [D] Art. 22's compiled pre-meeting memo, one per agenda item
 * (not per meeting, unlike `meeting_minutes` — a matter is drafted before
 * it's even inserted into an agenda). `content` is a two-part JSON blob:
 * `derived` (recomputed fresh by PresentationMemoCompiler on every generate)
 * and `authored` (free text a human writes, preserved across regenerates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_memos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_request_id')->unique()
                ->constrained('meeting_requests')->cascadeOnDelete();
            $table->json('content');
            $table->foreignId('generated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_memos');
    }
};
