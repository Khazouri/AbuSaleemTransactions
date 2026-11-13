<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * High open items (2026-11-13) — a rejected «مستند آخر» has no document type
 * for a newer upload to replace it under, so the filer names the file the
 * upload replaces (user decision). Attachments are never deleted, so the
 * link only takes the old file out of the gates' review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('replaces_attachment_id')->nullable()->after('required_document_key')
                ->constrained('attachments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_attachment_id');
        });
    }
};
