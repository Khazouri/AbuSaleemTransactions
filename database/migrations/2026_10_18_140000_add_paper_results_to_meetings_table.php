<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The committee sits on paper (user decision 2026-10-18): after the meeting
 * the مقرر enters each item's توصية and attaches the scanned محضر in one
 * submit. The محضر is the meeting's own file, not any one request's, so it
 * lives on the meeting row rather than in `attachments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->string('minutes_file_path')->nullable();
            $table->string('minutes_file_name')->nullable();
            $table->string('minutes_file_mime')->nullable();
            $table->timestamp('results_recorded_at')->nullable();
            $table->foreignId('results_recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('results_recorded_by_user_id');
            $table->dropColumn(['minutes_file_path', 'minutes_file_name', 'minutes_file_mime', 'results_recorded_at']);
        });
    }
};
