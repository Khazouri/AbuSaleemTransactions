<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable bilingual text templates.
 *
 * Stage 10 only manages this content. Later workflow and notification stages
 * can decide which template to render without changing the stored structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('subject_ar')->nullable();
            $table->string('subject_en')->nullable();
            $table->longText('body_ar');
            $table->longText('body_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
