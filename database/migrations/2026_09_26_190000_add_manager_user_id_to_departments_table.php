<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The department head shown by the Users screen's hierarchy view. A label
 * only: approvals follow each employee's own users.manager_id, never this.
 * nullOnDelete covers a hard delete; the soft-delete path is cleared by
 * UserController, since a soft delete fires no FK action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('manager_user_id')->nullable()->after('parent_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_user_id');
        });
    }
};
