<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow designer — slice 1. A workflow is a named, shared definition; each
 * version is an immutable snapshot of its stages once published. Stages carry
 * the version; a transition's version is its from-stage's and a request's is
 * its current stage's, so neither needs a column of its own — and a request
 * is pinned to its version simply by pointing at that version's stage rows.
 *
 * The column stays nullable at the database level: SQLite cannot add a
 * REFERENCES column with a non-null default, and only the seeders and
 * WorkflowVersion::cloneAsDraft() ever write a stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 20);
            $table->foreignId('cloned_from_version_id')->nullable()->constrained('workflow_versions')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workflow_id', 'number']);
        });

        $now = now();
        $workflowId = DB::table('workflows')->insertGetId([
            'code' => 'standard',
            'name_ar' => 'المسار القياسي',
            'name_en' => 'Standard',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $versionId = DB::table('workflow_versions')->insertGetId([
            'workflow_id' => $workflowId,
            'number' => 1,
            'status' => 'published',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->foreignId('workflow_version_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::table('workflow_stages')->update(['workflow_version_id' => $versionId]);

        // Codes and order numbers repeat in every version, so they are unique
        // only within one.
        Schema::table('workflow_stages', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropUnique(['order_no']);
            $table->unique(['workflow_version_id', 'code']);
            $table->unique(['workflow_version_id', 'order_no']);
        });
    }

    /** Only works while a single version exists: restoring unique('code') fails once codes repeat. */
    public function down(): void
    {
        Schema::table('workflow_stages', function (Blueprint $table) {
            // MySQL serves the FK from the composite uniques, so the FK must go
            // first or dropping the second unique fails mid-rollback (1553).
            $table->dropForeign(['workflow_version_id']);
            $table->dropUnique(['workflow_version_id', 'code']);
            $table->dropUnique(['workflow_version_id', 'order_no']);
            $table->dropColumn('workflow_version_id');
            $table->unique('code');
            $table->unique('order_no');
        });

        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
    }
};
