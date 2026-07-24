<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->nullable()->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            // SLA used to compute transactions.due_date (Stage 17).
            $table->unsignedSmallInteger('default_sla_days')->nullable();
            // Decision grade at/above which the transaction must be escalated to
            // the Ministry of Local Governance (grade >= 10 per the workflow spec).
            $table->unsignedSmallInteger('decision_grade_threshold')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_types');
    }
};
