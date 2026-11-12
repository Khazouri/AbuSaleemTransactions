<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Learning centre — narrated walkthroughs.
/**
 * One row per recorded clip. The catalogue (code, titles, which screen and
 * action it belongs to, who may watch) is seeded and re-seedable; the file
 * columns stay null until R08 uploads the MP4, which is why the two groups of
 * columns are kept apart: a re-seed may rewrite the first and never the second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walkthrough_videos', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('screen_code');
            $table->string('action', 20)->default('view');
            // Role codes (plus the token 'manager'), only for workflow tasks:
            // those are granted by workflow_transitions per role, not by the grid.
            $table->json('roles')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walkthrough_videos');
    }
};
