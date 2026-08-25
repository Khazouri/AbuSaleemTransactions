<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEETING_MINUTES — one compiled, reviewed, signed document per meeting.
 *
 * `content` is a structured snapshot (MeetingMinutesCompiler's output),
 * regenerated freely while `status='draft'` and frozen once review moves it
 * past that. `reviewed_by_user_id`/`reviewed_at` stay null while a
 * `review_comment` sits alongside a draft — that combination is how "never
 * reviewed" and "sent back with feedback" are told apart without a fourth
 * status value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('content')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('generated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_minutes');
    }
};
