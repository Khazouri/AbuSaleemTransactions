<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جهة الاعتماد (user decision 2026-10-17): `dean` or `minister`, picked by the
 * chair when recording an approving decision. It lives on the request, not on
 * the decision, because WorkflowService reads it in the same transition that
 * moves the file to that authority. Null until the committee approves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->string('approving_authority', 16)->nullable()->after('decision_grade');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('approving_authority');
        });
    }
};
