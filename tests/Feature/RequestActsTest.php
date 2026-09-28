<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ApprovalReferralService;
use App\Services\ApprovalReturnService;
use App\Services\RequestClosureService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\ClosesRequests;
use Tests\TestCase;

/**
 * Decision wizard, sub-project 3 — a decided file's remaining life, as the
 * request payload offers it. Every blocked reason must be the sentence the
 * endpoint itself answers with for the same state (the no-drift rule).
 */
class RequestActsTest extends TestCase
{
    use ClosesRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_archiving_is_refused_before_a_final_path_by_the_services_own_reason(): void
    {
        $file = $this->fileAt('final_approval_archiving', 'in_execution', decided: true);
        $reason = 'لا يؤرشف الملف قبل بلوغ المعاملة أحد مساراتها النهائية.';

        $this->assertSame($reason, app(RequestClosureService::class)->archiveRefusal($file));

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->patchJson("/api/requests/{$file->id}/archive/committee-file", ['location' => 'خزانة 1'])
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);
    }

    public function test_resolving_a_formal_return_off_an_approval_stage_is_refused_before_anything_moves(): void
    {
        $file = $this->fileAt('final_approval_archiving', 'final_approved');
        $this->openReturn($file, 'formal');
        $reason = 'لا يمكن إعادة الإحالة إلى جهة الاعتماد من هذه المرحلة.';

        $this->assertSame($reason, app(ApprovalReturnService::class)->resolveRefusal($file));

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->patchJson("/api/requests/{$file->id}/approval-return/resolve", ['resolution_action' => 'صحح'])
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);
    }

    public function test_an_open_referral_is_part_of_the_referral_refusal(): void
    {
        $file = $this->fileAt('approval_by_authority', 'awaiting_municipal_approval');
        $referrals = app(ApprovalReferralService::class);

        $this->assertNull($referrals->refusalReason($file));
        $this->openReferral($file);
        $this->assertSame('توجد إحالة للاعتماد لم تثبت نتيجتها بعد.', $referrals->refusalReason($file->fresh()));
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

    private function openReturn(Request $file, string $kind): void
    {
        DB::table('approval_returns')->insert([
            'request_id' => $file->id, 'return_kind' => $kind, 'return_reason_code' => 'other',
            'return_note' => 'نقص', 'received_at' => now(),
        ]);
    }

    private function openReferral(Request $file): int
    {
        return DB::table('approval_referrals')->insertGetId([
            'request_id' => $file->id, 'letter_number' => 'ك/1', 'referred_to_body' => 'عميد البلدية', 'referred_at' => now(),
        ]);
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
