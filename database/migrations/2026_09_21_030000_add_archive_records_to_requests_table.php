<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 100 — [D] Appendix 6 row 15: الأرشفة has two مسؤول, not one.
 *
 * الموارد البشرية «مسؤول ملف الخدمة» and المقرر «مسؤول ملف اللجنة» each record
 * where their own file went, replacing the single free-text
 * `closure.file_storage_location` whoever closed the file used to type. Track K
 * scope decision (1) puts ملف الخدمة outside this app, so the service-file
 * record is where HR filed it, attested by HR — not a model of the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->string('committee_file_location')->nullable()->after('closed_at');
            $table->foreignId('committee_file_archived_by_user_id')->nullable()->after('committee_file_location')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('committee_file_archived_at')->nullable()->after('committee_file_archived_by_user_id');
            $table->string('service_file_location')->nullable()->after('committee_file_archived_at');
            $table->foreignId('service_file_archived_by_user_id')->nullable()->after('service_file_location')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('service_file_archived_at')->nullable()->after('service_file_archived_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('committee_file_archived_by_user_id');
            $table->dropConstrainedForeignId('service_file_archived_by_user_id');
            $table->dropColumn([
                'committee_file_location', 'committee_file_archived_at',
                'service_file_location', 'service_file_archived_at',
            ]);
        });
    }
};
