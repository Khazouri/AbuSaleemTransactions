<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signatures are removed from the system — approving is now a plain
 * confirmation, with nothing drawn or uploaded. The `Approval` ledger row
 * itself (level, role, actor, comment, timestamp) is unaffected; only the
 * column that once held a stored PNG's path is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('comment');
        });
    }
};
