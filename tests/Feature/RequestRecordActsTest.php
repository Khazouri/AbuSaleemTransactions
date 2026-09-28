<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RequestController;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestCorrection;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\EmployeeNoticeService;
use App\Services\Lifecycle\CorrectionRules;
use App\Services\Lifecycle\WithdrawalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Decision wizard, sub-project 3 — a file's records and its own content, as
 * the request payload offers them. Same no-drift rule as RequestActsTest.
 */
class RequestRecordActsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_extracted_refusals_answer_as_their_endpoints_did(): void
    {
        $recorder = $this->userWithRole('R02');
        $file = $this->fileAt('receive_from_committee', 'ready');
        $correction = RequestCorrection::create([
            'request_id' => $file->id, 'error_kind' => 'name', 'detail' => 'خطأ', 'incorrect_value' => 'أ',
            'corrected_value' => 'ب', 'recorded_by_user_id' => $recorder->id,
        ]);

        $this->assertSame(
            'لا يعتمد مذكرة التصحيح من حررها؛ يلزم اعتمادها من مسؤول آخر.',
            app(CorrectionRules::class)->approvalRefusal($correction, $recorder),
        );
        $this->assertNull(app(CorrectionRules::class)->approvalRefusal($correction, $this->userWithRole('R02')));

        $this->assertNull(app(WithdrawalService::class)->filingRefusal($file));
        DB::table('request_withdrawals')->insert(['request_id' => $file->id, 'reason' => 'رغبة', 'requested_at' => now()]);
        $this->assertSame('يوجد طلب سحب لم يبت فيه بعد.', app(WithdrawalService::class)->filingRefusal($file));

        // Created directly, so no status history: Art. 101 names no moment.
        $this->assertSame(
            'لا تستوجب حالة المعاملة الحالية إشعارًا وفق المادة 101.',
            app(EmployeeNoticeService::class)->issueRefusal($file),
        );

        $this->assertSame(
            'لا يمكن إعادة عرض طلب لم تُختتم إجراءاته بعد.',
            RequestController::reopenRefusal($file, $recorder),
        );
        $this->assertSame(
            'لا يجوز لمقدّم الطلب أو صاحب العلاقة إعادة فتح الطلب بنفسه.',
            RequestController::reopenRefusal($file, $file->createdBy),
        );

        $this->assertNull($file->attachmentRefusal());
    }

    // --- helpers ------------------------------------------------------------

    /** @return array{available: list<array<string, mixed>>, blocked: list<array<string, mixed>>} */
    private function acts(User $actor, Request $file): array
    {
        return $this->actingAs($actor->fresh(), 'sanctum')
            ->getJson("/api/requests/{$file->id}")
            ->assertOk()
            ->json('data.acts');
    }

    /** @return list<string> */
    private function available(User $actor, Request $file): array
    {
        return array_column($this->acts($actor, $file)['available'], 'action');
    }

    private function blockedReason(User $actor, Request $file, string $action): ?string
    {
        return collect($this->acts($actor, $file)['blocked'])->firstWhere('action', $action)['reason'] ?? null;
    }

    private function fileAt(string $stageCode, string $statusCode, bool $decided = false, ?User $creator = null): Request
    {
        $creator ??= $this->userWithRole('R01');

        $file = Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numerify('####'),
            'title' => 'معاملة '.fake()->unique()->numerify('###'),
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now()->subMonth(),
        ]);

        if ($decided) {
            $meeting = Meeting::create([
                'committee_id' => Committee::create(['name_ar' => 'لجنة'])->id,
                'title' => 'اجتماع',
                'status' => 'completed',
                'scheduled_at' => now()->subWeek(),
            ]);
            $item = MeetingRequest::create(['meeting_id' => $meeting->id, 'request_id' => $file->id, 'agenda_order' => 1]);
            Decision::create(['meeting_request_id' => $item->id, 'outcome' => 'approve', 'decided_at' => now()->subWeek()]);
        }

        return $file->fresh();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
