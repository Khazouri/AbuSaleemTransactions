<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جهة الاعتماد's paper قرار (user decision 2026-10-25): one اشاري, one date
 * and one set of scanned pages cover every file decided in one submit from
 * the meeting page, so the قرار is its own row and each agenda item points
 * at the one that decided it. A re-decision after the مقرر's return points the
 * item at the new row; the old one stays as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authority_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 100);
            $table->date('decided_on');
            $table->json('pages');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->foreignId('authority_decision_id')->nullable()->constrained('authority_decisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('authority_decision_id');
        });

        Schema::dropIfExists('authority_decisions');
    }
};
