<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\RequestNoticeNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\ClosesRequests;
use Tests\TestCase;

/**
 * Stage 100 — [D] Appendix 6 rows 13, 14 and 15: the three duties nobody held.
 */
class NoticeArchiveOversightTest extends TestCase
{
    use ClosesRequests;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** Row 15 — ملف اللجنة is المقرر's; HR cannot record it. */
    public function test_the_committee_file_is_archived_by_the_rapporteur_only(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('executed', withDecision: true);

        $this->actingAs($this->userWithRole('R12'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/archive/committee-file", ['location' => 'x'])
            ->assertForbidden();

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/archive/committee-file", ['location' => 'أرشيف اللجنة — ملف 4'])
            ->assertOk()
            ->assertJsonPath('data.archive.committee_file.location', 'أرشيف اللجنة — ملف 4')
            ->assertJsonPath('data.archive.committee_file.archived_by.id', $rapporteur->id)
            ->assertJsonPath('data.archive.service_file', null)
            ->assertJsonPath('data.archive.service_file_required', true);
    }

    /** Row 15 — ملف الخدمة is HR's; المقرر cannot record it. */
    public function test_the_service_file_is_archived_by_hr_only(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestAt('executed', withDecision: true);

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/archive/service-file", ['location' => 'x'])
            ->assertForbidden();

        $this->actingAs($hr, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/archive/service-file", ['location' => 'ملف الخدمة 88'])
            ->assertOk()
            ->assertJsonPath('data.archive.service_file.location', 'ملف الخدمة 88')
            ->assertJsonPath('data.archive.service_file.archived_by.id', $hr->id);
    }

    public function test_archiving_is_refused_before_a_final_path(): void
    {
        $requestRecord = $this->requestAt('in_execution', withDecision: true);

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/archive/committee-file", ['location' => 'x'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'لا يؤرشف الملف قبل بلوغ المعاملة أحد مساراتها النهائية.']);
    }

    /** Art. 38's code 20 is مغلقة **ومؤرشفة** — each owed half, by its owner. */
    public function test_closure_waits_for_both_owed_archive_halves(): void
    {
        $closer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('executed', withDecision: true);
        $close = fn () => $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload());

        $close()->assertStatus(422)
            ->assertJsonFragment(['message' => 'لا تغلق المعاملة قبل أرشفة ملف اللجنة من قبل المقرر.']);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/archive/committee-file", ['location' => 'ملف اللجنة']);

        $close()->assertStatus(422)
            ->assertJsonFragment(['message' => 'لا تغلق المعاملة قبل أرشفة ملف الخدمة من قبل الموارد البشرية.']);

        $this->actingAs($this->userWithRole('R12'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/archive/service-file", ['location' => 'ملف الخدمة']);

        $close()->assertOk()
            ->assertJsonPath('data.status.code', 'completed_closed')
            ->assertJsonPath('data.closure_audit.archive_location_set', 'yes');
    }

    /** A pre-committee عدم اختصاص touched no service file, so owes no copy. */
    public function test_a_file_with_no_decision_owes_only_the_committee_file(): void
    {
        $closer = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('outside_jurisdiction');

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/archive/committee-file", ['location' => 'ملف اللجنة'])
            ->assertJsonPath('data.archive.service_file_required', false);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload(auditOverrides: [
                'minutes_approved' => 'not_applicable',
                'executed' => 'not_applicable',
                'service_file_updated' => 'not_applicable',
                'decision_copy_attached' => 'not_applicable',
            ]))
            ->assertOk()
            ->assertJsonPath('data.status.code', 'completed_closed');
    }

    /** Row 14 — المقرر issues the file's current notice in their own name. */
    public function test_the_rapporteur_issues_the_current_notice_in_their_own_name(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('not_approved', withDecision: true);
        $this->recordStatusChange($requestRecord, 'in_review', 'not_approved');
        $employee = $requestRecord->subject;

        Notification::fake();

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/notices/issue")
            ->assertOk();

        Notification::assertSentTo(
            $employee,
            RequestNoticeNotification::class,
            function (RequestNoticeNotification $notice) use ($employee, $rapporteur) {
                $payload = $notice->toArray($employee);

                return $payload['moment'] === 'not_approved' && $payload['issued_by'] === $rapporteur->name;
            },
        );
    }

    public function test_no_notice_is_issued_for_a_state_art_101_does_not_name(): void
    {
        $requestRecord = $this->requestAt('in_review');
        $this->recordStatusChange($requestRecord, 'new', 'in_review');

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/notices/issue")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'لا تستوجب حالة المعاملة الحالية إشعارًا وفق المادة 101.']);

        $this->actingAs($this->userWithRole('R04'), 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/notices/issue")
            ->assertForbidden();
    }

    /** Row 13 — جهة الاعتماد supervises the execution of what it approved. */
    public function test_an_approver_supervises_the_files_they_approved(): void
    {
        $approver = $this->userWithRole('R07');
        $requestRecord = $this->requestAt('in_execution', withDecision: true);

        Approval::create([
            'request_id' => $requestRecord->id,
            'level' => 5,
            'role_id' => Role::query()->where('code', 'R07')->value('id'),
            'approved_by_user_id' => $approver->id,
            'action' => 'approve',
            'approved_at' => now(),
        ]);

        $this->actingAs($approver, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        $this->actingAs($approver, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/notes", ['body' => 'متابعة التنفيذ'])
            ->assertCreated();

        // Another R07 who did not approve this file has no reach over it.
        $this->actingAs($this->userWithRole('R07'), 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertNotFound();
    }

    private function recordStatusChange(Request $requestRecord, string $from, string $to): void
    {
        RequestStatusHistory::create([
            'request_id' => $requestRecord->id,
            'from_status_id' => RequestStatus::where('code', $from)->value('id'),
            'to_status_id' => RequestStatus::where('code', $to)->value('id'),
            'reason' => null,
            'changed_by_user_id' => null,
            'changed_at' => now(),
        ]);
    }

    private function requestAt(string $statusCode, bool $withDecision = false): Request
    {
        $creator = $this->userWithRole('R01');

        $requestRecord = Request::create([
            'reference_number' => 'PM-COM/2026/'.str_pad((string) (Request::count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'معاملة المرحلة 100',
            'department_id' => Department::query()->where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::query()->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now()->subMonth(),
        ]);

        if ($withDecision) {
            $meeting = Meeting::create([
                'committee_id' => Committee::create(['name_ar' => 'لجنة'])->id,
                'title' => 'اجتماع',
                'scheduled_at' => now()->subWeek(),
                'created_by_user_id' => $creator->id,
            ]);
            Decision::create([
                'meeting_request_id' => MeetingRequest::create([
                    'meeting_id' => $meeting->id,
                    'request_id' => $requestRecord->id,
                    'agenda_order' => 1,
                ])->id,
                'outcome' => 'approve',
                'decided_by_user_id' => $creator->id,
                'decided_at' => now()->subWeek(),
            ]);
        }

        return $requestRecord->fresh();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::query()->where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $user;
    }
}
