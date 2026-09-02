<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->json('required_documents')->nullable()->after('default_has_financial_impact');
        });
    }

    public function down(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->dropColumn('required_documents');
        });
    }
};
