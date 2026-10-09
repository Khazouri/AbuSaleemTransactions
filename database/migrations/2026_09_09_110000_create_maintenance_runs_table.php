<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of every command run from the maintenance console.
 *
 * A row is written BEFORE the command starts, at status `running`, and updated
 * when it finishes — the same reasoning behind `backups.status`, and it matters
 * more here: the most likely failure on shared hosting is PHP's
 * max_execution_time killing the worker mid-run, which leaves no chance to
 * write anything afterwards. Without the up-front row, exactly the failure an
 * administrator most needs to see would be the one that vanishes.
 *
 * This table IS the audit trail for the console, which is why MaintenanceRun is
 * deliberately absent from AuditLog::AUDITED_MODELS (Stage 68's precedent for
 * RequestLegalReview): it already carries who, when and what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_runs', function (Blueprint $table) {
            $table->id();
            // The catalogue code, e.g. `migrate` or `composer:install`. Not the
            // resolved argv: the catalogue is the authority on what a code
            // means, and storing the expansion would let history disagree with
            // it after an upgrade.
            $table->string('command');
            $table->string('kind');                 // artisan | shell
            $table->string('status')->default('running'); // running|completed|failed
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            // Nullable so deleting a user never takes the record of what they
            // ran with them, matching backups.created_by_user_id.
            $table->foreignId('ran_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_runs');
    }
};
