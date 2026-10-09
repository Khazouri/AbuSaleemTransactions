<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 27 — the user guide's content.
 *
 * Shaped after `templates`: a stable `code`, bilingual pairs where Arabic is
 * required and English optional, and an `is_active` flag. The screen was
 * seeded in Stage 3 with view/print for everyone and add/edit/delete for R08,
 * which only makes sense against content someone can actually edit — this is
 * that content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guide_articles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            // Free text rather than a lookup table: the guide's sections are
            // editorial, and a new one shouldn't need a migration.
            $table->string('category')->nullable();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('body_ar');
            $table->text('body_en')->nullable();
            $table->integer('sort_order')->default(0);
            // A draft: written but not yet shown to readers. GuideArticleController
            // hides these from anyone without the edit grant.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_articles');
    }
};
