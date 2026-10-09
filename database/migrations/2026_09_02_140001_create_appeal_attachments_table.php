<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPEAL_ATTACHMENTS — Stage 59, Track J.
 * ---------------------------------------------------------------------------
 * Deliberately a separate table, not the existing `attachments` (which is a
 * hard, non-polymorphic FK to `requests` with cascadeOnDelete) and not a
 * label on the original request's own attachments — an appeal's supporting
 * documents belong to the appeal, and conflating the two would muddle whose
 * document is whose in what is meant to be an adversarial record. Same shape
 * as `attachments`, same upload validation (see StoreAppealAttachmentRequest)
 * and "create the parent first, upload documents as follow-up calls" flow
 * Stage 12/13 already established.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appeal_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appeal_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('label')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('appeal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appeal_attachments');
    }
};
