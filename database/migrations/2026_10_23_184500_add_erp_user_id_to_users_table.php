<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The EmployeePlus ERP's user id (the OAuth `sub`), which is how an ERP
 * sign-in finds its account here. Unique, because two accounts sharing one
 * would let one ERP login open either; nullable, because an unlinked account
 * simply cannot sign in through the ERP (user decision 2026-10-23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('erp_user_id')->nullable()->unique()->after('employee_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['erp_user_id']);
            $table->dropColumn('erp_user_id');
        });
    }
};
