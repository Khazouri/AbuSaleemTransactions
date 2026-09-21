<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rule only the request's own filer may use — the relationship-gated sibling
 * of `requires_submitter_manager`. It exists for `submit`: a request the
 * direct manager returned goes back to whoever filed it, whatever their role,
 * and to nobody else. A role gate (R01) could not say that: it stranded an
 * on-behalf filer (R02/R05) and exposed every returned file to every R01.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->boolean('requires_creator')->default(false)->after('requires_submitter_manager');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_transitions', function (Blueprint $table) {
            $table->dropColumn('requires_creator');
        });
    }
};
