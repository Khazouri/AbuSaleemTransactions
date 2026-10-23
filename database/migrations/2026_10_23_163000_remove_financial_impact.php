<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «الأثر المالي» removed from the system (user decision 2026-10-09): Stage
 * 47's flag on requests and request types, its notification preference, and
 * the two Appendix 57 document rows that only asked for it.
 *
 * The document rows are pruned here rather than by re-running
 * RequestTypeSeeder, because that seeder's updateOrCreate would also reset
 * anything customised on the Request Types screen — and a cPanel host only
 * ever runs migrations.
 */
return new class extends Migration
{
    /** @var array<string, string> request type code => the Arabic name of the row to drop */
    private const DOCUMENT_ROWS = [
        'SECD' => 'الأثر المالي',
        'SETL' => 'البيانات المالية',
    ];

    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn('has_financial_impact');
        });

        Schema::table('request_types', function (Blueprint $table) {
            $table->dropColumn('default_has_financial_impact');
        });

        DB::table('notification_settings')->where('event_type', 'financial_impact_review')->delete();

        foreach (self::DOCUMENT_ROWS as $code => $name) {
            $type = DB::table('request_types')->where('code', $code)->first(['id', 'required_documents']);

            if ($type === null || $type->required_documents === null) {
                continue;
            }

            $rows = array_values(array_filter(
                json_decode($type->required_documents, true),
                fn (array $row) => ($row['ar'] ?? null) !== $name,
            ));

            DB::table('request_types')->where('id', $type->id)
                ->update(['required_documents' => json_encode($rows, JSON_UNESCAPED_UNICODE)]);
        }
    }

    /** Restores the columns only; the deleted settings and document rows are not recreated. */
    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->boolean('has_financial_impact')->default(false)->after('decision_grade');
        });

        Schema::table('request_types', function (Blueprint $table) {
            $table->boolean('default_has_financial_impact')->default(false)->after('decision_grade_threshold');
        });
    }
};
