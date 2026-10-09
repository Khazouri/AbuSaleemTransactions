<?php

namespace Database\Seeders;

use App\Models\Request;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ArtifactNumberGenerator;
use App\Services\RequestDeadlineService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Test data: five random requests parked on every stage a file can reach.
 * Behind the maintenance console's debug-only «توليد طلبات تجريبية» button.
 *
 *     php artisan db:seed --class=TestRequestSeeder
 *
 * Rows are INSERTED at their stage, not walked there. No gate is passed, so a
 * file at a committee stage has no meeting or decision behind it, and one at
 * an approval stage has no Approval rows. They exist to fill lists and inboxes,
 * not to stand in for a real history. No notification is sent: forty fake
 * "action required" prompts would only bury the real ones.
 *
 * Each run adds another batch. Nothing is deduplicated.
 */
class TestRequestSeeder extends Seeder
{
    private const PER_STAGE = 5;

    /**
     * stage code => the status a file carries while it sits there, read off
     * the WorkflowTransitionSeeder rule that lands on it. administrative_routing
     * and stages 6–8 are absent because no rule reaches them any more (the
     * one-click manager approval, and Stage 102's single committee path).
     */
    public const STAGES = [
        'receive_from_municipality' => 'returned',
        'direct_manager_review' => 'in_review',
        'receive_and_register' => 'routed_to_hr',
        'requirements_check' => 'in_review',
        'receive_from_committee' => 'registered',
        'approval_by_authority' => 'awaiting_municipal_approval',
        'local_governance_ministry' => 'awaiting_central_approval',
        'final_approval_archiving' => 'final_approved',
    ];

    /** The stage where the قيد is made, and with it the PM-COM number. */
    private const FIRST_REGISTERED_STAGE = 'receive_from_committee';

    private const TITLES = [
        'طلب ترقية',
        'طلب إجازة',
        'طلب نقل',
        'طلب تسوية وضع وظيفي',
        'تظلم من تقييم الأداء',
        'طلب ندب',
        'طلب صرف علاوة',
        'طلب تثبيت',
    ];

    public function run(ArtifactNumberGenerator $numbers, RequestDeadlineService $deadlines): void
    {
        // A filer with a manager, so the manager-gated stage-2 files have
        // someone who can actually act on them.
        $filers = User::query()
            ->where('is_active', true)
            ->whereNotNull('manager_id')
            ->whereHas('roles', fn ($q) => $q->where('code', 'R01'))
            ->get();

        if ($filers->isEmpty()) {
            $this->command?->warn('No active R01 user with a manager. Run "Seed test users" first.');

            return;
        }

        $types = RequestType::query()->where('is_active', true)->get();
        $stages = WorkflowStage::query()->whereIn('code', array_keys(self::STAGES))->pluck('id', 'code');
        $statuses = RequestStatus::query()->whereIn('code', self::STAGES)->pluck('id', 'code');

        // Both number series lock their latest row, so the run is one transaction.
        DB::transaction(function () use ($numbers, $deadlines, $filers, $types, $stages, $statuses) {
            $registered = false;

            foreach (self::STAGES as $stageCode => $statusCode) {
                $registered = $registered || $stageCode === self::FIRST_REGISTERED_STAGE;

                for ($i = 1; $i <= self::PER_STAGE; $i++) {
                    $filer = $filers->random();
                    $type = $types->random();
                    $submittedAt = now()->subDays(random_int(1, 60));

                    $requestRecord = Request::create([
                        'intake_receipt_number' => $numbers->nextIntakeReceipt(),
                        'reference_number' => $registered ? $numbers->nextRequestReference() : null,
                        'title' => self::TITLES[array_rand(self::TITLES)]." (تجريبي {$i})",
                        'description' => "طلب تجريبي مولَّد آليًا في مرحلة {$stageCode}.",
                        'department_id' => $filer->department_id,
                        'request_type_id' => $type->id,
                        'status_id' => $statuses[$statusCode],
                        'current_stage_id' => $stages[$stageCode],
                        'created_by_user_id' => $filer->id,
                        'submitted_at' => $submittedAt,
                        'due_date' => $deadlines->dueDateFor($type, $submittedAt),
                        'has_financial_impact' => $type->default_has_financial_impact,
                    ]);

                    // One origin row each, so the timeline does not start empty.
                    RequestStageLog::create([
                        'request_id' => $requestRecord->id,
                        'to_stage_id' => $stages[$stageCode],
                        'action' => 'intake',
                        'acted_by_user_id' => $filer->id,
                        'acted_at' => $submittedAt,
                    ]);
                    RequestStatusHistory::create([
                        'request_id' => $requestRecord->id,
                        'to_status_id' => $statuses[$statusCode],
                        'changed_by_user_id' => $filer->id,
                        'changed_at' => $submittedAt,
                    ]);
                }
            }
        });

        $this->command?->info(count(self::STAGES) * self::PER_STAGE.' test requests created.');
    }
}
