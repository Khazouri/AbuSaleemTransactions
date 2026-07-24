<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERMISSION_ROLE — pivot granting capabilities to roles (many-to-many)
 * ---------------------------------------------------------------------------
 * Permissions are attached to ROLES, never directly to users. A user's
 * effective capabilities are the union of the permissions of all roles they
 * hold — see User::hasPermission().
 *
 * That indirection is deliberate: changing what "المقرر" (R02) can do is a
 * single edit here, and every reviewer in the municipality picks it up at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();

            // permission_id -> permissions, role_id -> roles (inferred).
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // A role either has a permission or it doesn't — no duplicates.
            $table->unique(['permission_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
    }
};
