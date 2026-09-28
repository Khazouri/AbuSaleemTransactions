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

    public function test_the_soundness_certification_is_the_rapporteurs_and_not_hrs(): void
    {
        $file = $this->fileAt('final_approval_archiving', 'final_approved', decided: true);

        $this->assertContains('execution_soundness', $this->available($this->userWithRole('R02'), $file));
        $this->assertNotContains('execution_soundness', $this->available($this->userWithRole('R12'), $file));
    }

    public function test_execute_is_offered_only_in_execution_and_names_the_decided_item(): void
    {
        $hr = $this->userWithRole('R12');
        $file = $this->fileAt('final_approval_archiving', 'final_approved', decided: true);

        $this->assertNotContains('execute', $this->available($hr, $file));

        $file->update(['status_id' => RequestStatus::where('code', 'in_execution')->value('id')]);
        $act = collect($this->acts($hr, $file)['available'])->firstWhere('action', 'execute');
        $item = $file->meetingRequests()->first();

        $this->assertSame(['id' => $item->id, 'meeting_id' => $item->meeting_id, 'label' => null], $act['target']);
        $this->assertSame('after_decision', $act['family']);
    }

    public function test_each_file_is_archived_by_its_owner(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $hr = $this->userWithRole('R12');
        $decided = $this->fileAt('final_approval_archiving', 'executed', decided: true);
        $undecided = $this->fileAt('requirements_check', 'outside_jurisdiction');

        $this->assertContains('archive_committee_file', $this->available($rapporteur, $decided));
        $this->assertNotContains('archive_service_file', $this->available($rapporteur, $decided));
        $this->assertContains('archive_service_file', $this->available($hr, $decided));
        // Only a recorded decision makes the service file owed.
        $this->assertNotContains('archive_service_file', $this->available($hr, $undecided));
    }

    public function test_close_is_blocked_with_the_closure_refusal_until_both_files_are_archived(): void
    {
        $hr = $this->userWithRole('R12');
        $file = $this->fileAt('final_approval_archiving', 'executed', decided: true);
        $reason = 'لا تغلق المعاملة قبل أرشفة ملف اللجنة من قبل المقرر.';

        $this->assertSame($reason, $this->blockedReason($hr, $file, 'close'));
        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$file->id}/close", $this->closurePayload())
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);

        $this->archiveFiles($file);
        $this->assertContains('close', $this->available($hr, $file));
    }

    public function test_a_file_in_execution_shows_why_it_cannot_close(): void
    {
        $file = $this->fileAt('final_approval_archiving', 'in_execution', decided: true);

        $this->assertSame(
            'لا يجوز إقفال معاملة تحت التنفيذ؛ يثبت تنفيذ الأثر أولاً.',
            $this->blockedReason($this->userWithRole('R02'), $file, 'close'),
        );
    }

    public function test_a_return_is_recorded_then_resolved_and_each_refusal_matches_its_endpoint(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $file = $this->fileAt('approval_by_authority', 'awaiting_municipal_approval');

        $this->assertContains('record_approval_return', $this->available($rapporteur, $file));
        $this->assertNotContains('resolve_approval_return', $this->available($rapporteur, $file));

        $this->openReturn($file, 'formal');
        $reason = 'توجد إعادة من جهة الاعتماد لم يثبت بعد الإجراء المتخذ بشأنها.';

        $this->assertSame($reason, $this->blockedReason($rapporteur, $file, 'record_approval_return'));
        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$file->id}/approval-return", [
                'return_kind' => 'formal', 'return_reason_code' => 'missing_signature',
                'return_note' => 'نقص', 'received_at' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);

        $resolve = collect($this->acts($rapporteur, $file)['available'])->firstWhere('action', 'resolve_approval_return');
        $this->assertSame('formal', $resolve['return_kind']);
    }

    public function test_a_formal_return_off_an_approval_stage_reports_the_endpoints_reason(): void
    {
        $file = $this->fileAt('final_approval_archiving', 'final_approved');
        $this->openReturn($file, 'formal');

        $this->assertSame(
            'لا يمكن إعادة الإحالة إلى جهة الاعتماد من هذه المرحلة.',
            $this->blockedReason($this->userWithRole('R02'), $file, 'resolve_approval_return'),
        );
    }

    public function test_an_open_referral_blocks_a_second_and_offers_its_own_result(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $file = $this->fileAt('approval_by_authority', 'awaiting_municipal_approval');
        $referralId = $this->openReferral($file);
        $reason = 'توجد إحالة للاعتماد لم تثبت نتيجتها بعد.';

        $this->assertSame($reason, $this->blockedReason($rapporteur, $file, 'record_approval_referral'));
        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$file->id}/approval-referrals", [
                'referred_at' => now()->toDateString(), 'letter_number' => 'ك/2', 'referred_to_body' => 'الوزارة',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);

        $result = collect($this->acts($rapporteur, $file)['available'])->firstWhere('action', 'record_referral_result');
        $this->assertSame($referralId, $result['target']['id']);
        $this->assertSame('ك/1 — عميد البلدية', $result['target']['label']);
    }

    public function test_the_lift_waits_for_a_legal_review_with_the_endpoints_reason(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $file = $this->fileAt('final_approval_archiving', 'in_execution', decided: true);

        $this->assertContains('suspend', $this->available($rapporteur, $file));

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$file->id}/suspend", ['ground' => 'document_in_doubt', 'detail' => 'شك'])
            ->assertOk();
        $reason = 'لا يرفع الإيقاف قبل إثبات مراجعة قانونية بعد تاريخ الإيقاف.';

        $this->assertNotContains('suspend', $this->available($rapporteur, $file));
        $this->assertSame($reason, $this->blockedReason($rapporteur, $file, 'lift_suspension'));
        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$file->id}/suspend/lift", ['resolution_action' => 'fact_confirmed'])
            ->assertStatus(422)
            ->assertJsonPath('message', $reason);
    }

    public function test_the_filer_is_offered_nothing_after_the_decision(): void
    {
        $filer = $this->userWithRole('R01');
        $file = $this->fileAt('final_approval_archiving', 'executed', decided: true, creator: $filer);

        $acts = $this->acts($filer, $file);
        $this->assertSame([], collect([...$acts['available'], ...$acts['blocked']])->where('family', 'after_decision')->all());
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
