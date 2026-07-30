<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            // Nullable preserves Stage 18 ledger rows; Stage 19 requires the
            // value at every new approval write boundary.
            $table->string('signature_path')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn('signature_path');
        });
    }
};
