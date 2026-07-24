<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROLE_USER — pivot linking users to roles (many-to-many)
 * ---------------------------------------------------------------------------
 * One user may hold several roles: a committee head (R03) is often also a
 * committee member (R04). Conversely one role covers many users.
 *
 * Laravel's naming convention for a pivot is the two table names, singular and
 * alphabetical — hence "role_user" rather than "user_role". Sticking to the
 * convention is what lets belongsToMany() work without extra configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();

            // constrained() infers the referenced table from the column name
            // (user_id -> users, role_id -> roles).
            // cascadeOnDelete: removing a user or role clears its assignments
            // rather than leaving orphan pivot rows.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            // Guards against assigning the same role to a user twice, which
            // would otherwise duplicate the role in permission lookups.
            $table->unique(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
