<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APPEALS formal-verification fields — Stage 60, Track J.
 * ---------------------------------------------------------------------------
 * [A] §9 step 2's admissibility check: صفة المتظلم / القرار محل التظلم /
 * المواعيد القانونية / عدم التكرار, each answered once by AppealController's
 * new verify() action and recorded here for "who/when/why".
 *
 * `formal_verification_checks` is a json map of the four boolean/null answers
 * (see App\Services\AppealVerificationService), not four separate columns —
 * mirroring how requests.jurisdiction_test (Stage 54) stores its own
 * all-required-together question set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->json('formal_verification_checks')->nullable()->after('new_facts_declaration');
            $table->text('formal_verification_reason')->nullable()->after('formal_verification_checks');
            $table->foreignId('formal_verified_by_user_id')->nullable()->after('formal_verification_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('formal_verified_at')->nullable()->after('formal_verified_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('appeals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('formal_verified_by_user_id');
            $table->dropColumn(['formal_verification_checks', 'formal_verification_reason', 'formal_verified_at']);
        });
    }
};
