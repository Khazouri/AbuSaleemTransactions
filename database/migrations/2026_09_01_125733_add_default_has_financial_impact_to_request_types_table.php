<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->boolean('default_has_financial_impact')->default(false)->after('decision_grade_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->dropColumn('default_has_financial_impact');
        });
    }
};
