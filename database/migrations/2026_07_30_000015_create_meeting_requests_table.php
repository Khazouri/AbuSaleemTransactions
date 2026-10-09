<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEETING_REQUESTS — the agenda: which requests are on a meeting's
 * table, and in what order.
 *
 * `agenda_order` is a plain integer, not a linked list or fractional index —
 * removing an item is allowed to leave a gap, since the agenda always renders
 * sorted by this column regardless of contiguity. Reordering (see
 * MeetingController::reorderAgenda) rewrites it 1..n across the whole set in
 * one request, which is cheap at meeting-agenda scale (a handful of
 * items, not thousands).
 *
 * Stage 21 hangs `decisions`/`votes` off this table's rows, once a committee
 * vote needs to record an outcome against a specific agenda item rather than
 * the request in general.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('agenda_order')->default(1);
            $table->timestamps();

            $table->unique(['meeting_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_requests');
    }
};
