<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TRANSACTION_STATUSES (حالات المعاملة)
 * ---------------------------------------------------------------------------
 * Status answers "what condition is this transaction in?", which is a
 * different question from STAGE ("where in the pipeline is it?").
 *
 * A transaction at stage 3 (reviewer review) might be `in_review` normally, or
 * `incomplete` if documents are missing — same stage, different status. The
 * two move together but independently, which is why they're separate columns
 * on `transactions` with separate history tables.
 *
 * Happy path:  new -> in_review -> ready -> in_meeting -> decided
 *                  -> approved -> final_approved -> archived
 * Exceptions:  incomplete, returned, rejected, cancelled  (driven by Stage 16)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_statuses', function (Blueprint $table) {
            $table->id();

            // Machine key: new, in_review, approved... Code and seeders match
            // on this rather than on the display name.
            $table->string('code', 50)->unique();

            $table->string('name_ar');
            $table->string('name_en')->nullable();

            // Hex colour for the status badge in the UI, so the palette is
            // configurable from the database instead of hard-coded in Vue.
            $table->string('color', 20)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_statuses');
    }
};
