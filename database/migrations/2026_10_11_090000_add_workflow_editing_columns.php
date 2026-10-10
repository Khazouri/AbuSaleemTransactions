<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow designer — slice 3. A custom outcome is named by the admin, so
 * its rule carries its own label (a seeded action is named by the SPA's
 * locale files instead). A request type picks the shared workflow its new
 * requests are filed on. Nullable because SQLite cannot add a NOT NULL
 * REFERENCES column; the backfill and RequestType's creating hook keep it
 * set, and intake treats null as Standard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->string('label_ar')->nullable()->after('action');
            $table->string('label_en')->nullable()->after('label_ar');
        });

        Schema::table('request_types', function (Blueprint $table) {
            $table->foreignId('workflow_id')->nullable()->after('id')->constrained()->restrictOnDelete();
        });

        DB::table('request_types')->update([
            'workflow_id' => DB::table('workflows')->where('code', 'standard')->value('id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->dropForeign(['workflow_id']);
            $table->dropColumn('workflow_id');
        });

        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->dropColumn(['label_ar', 'label_en']);
        });
    }
};
