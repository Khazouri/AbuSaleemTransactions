<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROLES (الأدوار)
 * ---------------------------------------------------------------------------
 * The eight fixed job roles from the system's Role Matrix. These are not
 * free-form: the whole workflow is written against these codes.
 *
 *   R01  موظف                        Employee — submits the request
 *   R02  المقرر                       Reviewer — first reviewer / rapporteur
 *   R03  رئيس اللجنة                  Committee Head — issues committee decisions
 *   R04  عضو اللجنة                   Committee Member — attends and votes
 *   R05  مدير إدارة الشؤون الإدارية    Admin Affairs Manager — audits and approves
 *   R06  وزارة الحكم المحلي            Ministry of Local Governance
 *   R07  المدير العام / العميد          Director General / Dean — final approval
 *   R08  مدير النظام                   System Admin — full access
 *
 * A user can hold more than one role (see the role_user pivot).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            // Stable identifier used throughout the code, e.g. hasRole('R08')
            // and workflow_transitions.required_role_id lookups. Never renumber
            // these — seeders, the permission matrix and the workflow all key
            // off them.
            $table->string('code', 10)->unique(); // R01..R08

            $table->string('name_ar');
            $table->string('name_en')->nullable();

            // Short sentence describing what the role does, shown in the
            // roles/permissions admin screen.
            $table->string('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
