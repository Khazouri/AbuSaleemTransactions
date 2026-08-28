<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the first completed SLA sweep that found a request overdue.
 *
 * This deliberately does not replace the workflow status: a breached request
 * still needs its current stage and status to remain truthful while it is
 * escalated and resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->timestamp('overdue_at')->nullable()->after('due_date');
            $table->index(['overdue_at', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropIndex(['overdue_at', 'due_date']);
            $table->dropColumn('overdue_at');
        });
    }
};
