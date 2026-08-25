<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 31 — the agenda outgrows "an ordered list of transactions": an item
 * now carries a type, a priority, an estimated duration, and — for the two
 * non-`employee_request` types — its own subject/department, since it has no
 * transaction to borrow those from.
 *
 * `transaction_id` moves to nullable via `->change()` (a separate
 * Schema::table call, since a column being altered can't also be freshly
 * declared in the same blueprint as the new columns) — Laravel 11 compiles
 * that natively for both MySQL and SQLite without a `doctrine/dbal`
 * dependency, which this repo doesn't have installed. The existing foreign
 * key and the `(meeting_id, transaction_id)` unique index are preserved;
 * MySQL and SQLite both treat every NULL in a unique index as distinct, so
 * several admin items with no transaction can coexist on the same meeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_transactions', function (Blueprint $table) {
            $table->string('item_type')->default('employee_request')->after('transaction_id');
            $table->string('priority')->nullable()->after('item_type');
            $table->unsignedInteger('estimated_minutes')->nullable()->after('priority');
            $table->string('subject')->nullable()->after('estimated_minutes');
            $table->foreignId('department_id')->nullable()->after('subject')
                ->constrained('departments')->nullOnDelete();
        });

        Schema::table('meeting_transactions', function (Blueprint $table) {
            $table->foreignId('transaction_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['item_type', 'priority', 'estimated_minutes', 'subject']);
        });

        Schema::table('meeting_transactions', function (Blueprint $table) {
            $table->foreignId('transaction_id')->nullable(false)->change();
        });
    }
};
