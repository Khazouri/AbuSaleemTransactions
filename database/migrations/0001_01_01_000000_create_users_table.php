<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Which department the employee belongs to (الإدارة التابع لها).
            // Declared as a plain column here rather than foreignId() because
            // this migration runs FIRST — the departments table doesn't exist
            // yet. The actual foreign key is attached in the departments
            // migration once its table is in place.
            $table->unsignedBigInteger('department_id')->nullable()->index();

            // Lets an admin disable an account without deleting it. Checked on
            // every login (AuthController::login) so a deactivated employee is
            // locked out immediately, even with correct credentials.
            $table->boolean('is_active')->default(true);

            $table->rememberToken();

            // Soft delete: staff records stay referenced by transactions,
            // approvals and audit logs long after the person leaves.
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
