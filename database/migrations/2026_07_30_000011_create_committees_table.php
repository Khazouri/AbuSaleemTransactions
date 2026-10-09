<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMMITTEES (اللجان)
 * ---------------------------------------------------------------------------
 * A standing body (e.g. لجنة المشتريات) that reviews requests in meetings.
 * Master data like `departments`: created rarely, referenced by `meetings`, so
 * it leans towards preserving records — soft delete plus a controller-level
 * block (see CommitteeController::destroy) rather than a hard delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committees', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committees');
    }
};
