<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screens', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('route', 150)->nullable();
            $table->string('icon', 100)->nullable();
            $table->foreignId('parent_id')->nullable()
                ->constrained('screens')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screens');
    }
};
