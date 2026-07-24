<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('order_no')->unique(); // 1..11
            $table->string('code', 50)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            // Indicative owner of the stage. The authoritative per-action role
            // check lives in workflow_transitions.required_role_id (Stage 14).
            $table->foreignId('responsible_role_id')->nullable()
                ->constrained('roles')->nullOnDelete();
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stages');
    }
};
