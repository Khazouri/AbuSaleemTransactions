<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\RequestCreatedNotification;
use App\Notifications\RequestNoticeCopyNotification;
use App\Notifications\RequestNoticeNotification;
use App\Services\NotificationDispatcher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Stage 101 — [D] Appendix 6's مشارك and مطلع cells, the last of the matrix.
 *
 * Several of these cells were already true, only by accident (R12 sees every
 * registered file through the execution grant; a manager's attach rides the
 * employee's own screen grant). They are pinned here so a later change to an
 * unrelated grant cannot silently take a party out of a row.
 */
class ConsultationLayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    // ── Row 2 and row 4: HR مشارك before the قيد ─────────────────────────

    public function test_hr_can_open_and_annotate_an_unregistered_file_at_the_managers_review(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestAt('direct_manager_review', $this->userWithRole('R01'), registered: false);

        $this->actingAs($hr)->getJson("/api/requests/{$requestRecord->id}")->assertOk();
        $this->actingAs($hr)->postJson("/api/requests/{$requestRecord->id}/notes", ['body' => 'ملاحظة الموارد البشرية.'])
            ->assertCreated();
    }

    public function test_hr_can_open_and_annotate_an_unregistered_file_at_the_completeness_check(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestAt('requirements_check', $this->userWithRole('R01'), registered: false);

        $this->actingAs($hr)->getJson("/api/requests/{$requestRecord->id}")->assertOk();
        $this->actingAs($hr)->postJson("/api/requests/{$requestRecord->id}/notes", ['body' => 'ملاحظة الموارد البشرية.'])
            ->assertCreated();
    }

    /** The reach is exactly the stages the rows name, not "anywhere before the قيد". */
    public function test_hr_still_cannot_open_an_unregistered_file_at_administrative_routing(): void
    {
        $requestRecord = $this->requestAt('administrative_routing', $this->userWithRole('R01'), registered: false);

        $this->actingAs($this->userWithRole('R12'))
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertNotFound();
    }

    // ── Rows 6, 7, 9: HR مشارك after the قيد (true through the closer grant) ──

    public function test_hr_can_annotate_a_registered_file_through_legal_review_the_memo_and_the_study(): void
    {
        $hr = $this->userWithRole('R12');
        $creator = $this->userWithRole('R01');

        foreach ([
            ['receive_from_committee', 'under_legal_review'], // row 6
            ['observations', 'in_review'],                     // row 7
            ['receive_from_committee', 'in_meeting'],          // row 9
        ] as [$stage, $status]) {
            $requestRecord = $this->requestAt($stage, $creator, $status);

            $this->actingAs($hr)->postJson("/api/requests/{$requestRecord->id}/notes", ['body' => 'دعم معلومات.'])
                ->assertCreated();
        }
    }

    // ── Row 3: الرئيس المباشر مشارك ────────────────────────────────────

    public function test_the_subjects_manager_can_annotate_while_hr_assembles_the_employment_file(): void
    {
        // A manager holding only the employee role: the relationship, not a
        // privileged role, is what gives them the reach.
        $manager = $this->userWithRole('R01');
        $employee = $this->userWithRole('R01');
        $employee->update(['manager_id' => $manager->id]);
        $requestRecord = $this->requestAt('receive_and_register', $employee, 'routed_to_hr', registered: false);

        $this->actingAs($manager)->postJson("/api/requests/{$requestRecord->id}/notes", ['body' => 'معلومة من الرئيس المباشر.'])
            ->assertCreated();
        $this->actingAs($this->userWithRole('R01'))
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertNotFound();
    }

    // ── Row 6: اللجنة مطلع / Row 7: العضو القانوني مشارك ──────────────────

    public function test_the_committee_chair_can_read_the_legal_review_queue_but_not_write_to_it(): void
    {
        $chair = $this->userWithRole('R03');

        $this->actingAs($chair)->getJson('/api/legal-reviews')->assertOk();
        $this->actingAs($chair)
            ->postJson('/api/requests/'.$this->requestAt('receive_from_committee', $this->userWithRole('R01'), 'under_legal_review')->id.'/legal-reviews', [])
            ->assertForbidden();
        // A plain committee member is deliberately not granted the queue: they
        // would be listed rows they cannot open.
        $this->actingAs($this->userWithRole('R04'))->getJson('/api/legal-reviews')->assertForbidden();
    }

    public function test_the_legal_member_holds_the_memo_write_tier_and_an_ordinary_member_does_not(): void
    {
        $this->assertTrue($this->userWithRole('R11')->hasScreenPermission('meeting_agenda', 'can_add'));
        $this->assertFalse($this->userWithRole('R04')->hasScreenPermission('meeting_agenda', 'can_add'));
        // ...but not the agenda-structure tier: they contribute to the memo, they do not build the agenda.
        $this->assertFalse($this->userWithRole('R11')->hasScreenPermission('meeting_agenda', 'can_edit'));
    }

    // ── Row 1: الرئيس المباشر مطلع, الموارد البشرية مطلع ───────────────────

    public function test_filing_informs_the_subjects_manager_and_hr(): void
    {
        Notification::fake();
        $manager = $this->userWithRole('R02');
        $hr = $this->userWithRole('R12');
        $employee = $this->userWithRole('R01');
        $employee->update(['manager_id' => $manager->id]);
        $requestRecord = $this->requestAt('direct_manager_review', $employee, registered: false);

        app(NotificationDispatcher::class)->requestCreated($requestRecord, $employee);

        Notification::assertSentTo($manager, RequestCreatedNotification::class);
        Notification::assertSentTo($hr, RequestCreatedNotification::class);
    }

    // ── Row 14: الرئيس المباشر مطلع, الموارد البشرية مشارك ─────────────────

    public function test_every_art_101_notice_is_copied_to_the_subjects_manager_and_hr_without_its_body(): void
    {
        Notification::fake();
        $manager = $this->userWithRole('R02');
        $hr = $this->userWithRole('R12');
        $employee = $this->userWithRole('R01');
        $employee->update(['manager_id' => $manager->id]);
        $requestRecord = $this->requestAt('receive_from_committee', $employee, 'in_review');

        $this->moveStatus($requestRecord, 'final_approved');

        Notification::assertSentTo($employee, RequestNoticeNotification::class);
        Notification::assertNotSentTo($employee, RequestNoticeCopyNotification::class);

        foreach ([$manager, $hr] as $party) {
            Notification::assertSentTo($party, RequestNoticeCopyNotification::class, function (RequestNoticeCopyNotification $copy) use ($party, $requestRecord) {
                $payload = $copy->toArray($party);

                $this->assertSame($requestRecord->id, $payload['request_id']);
                $this->assertSame('final_approval', $payload['moment']);
                // Art. 102: the copy names the moment, it never repeats the
                // notice addressed to the employee.
                $this->assertArrayNotHasKey('issued_by', $payload);
                $this->assertStringNotContainsString('نفيدكم', $payload['body_ar']);

                return true;
            });
        }
    }

    public function test_the_actor_is_not_copied_on_their_own_notice(): void
    {
        Notification::fake();
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->requestAt('receive_from_committee', $this->userWithRole('R01'), 'in_review');

        $this->moveStatus($requestRecord, 'final_approved', $hr);

        Notification::assertNotSentTo($hr, RequestNoticeCopyNotification::class);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function requestAt(string $stageCode, User $creator, string $status = 'in_review', bool $registered = true): Request
    {
        return Request::create([
            'reference_number' => $registered ? 'PM-COM/2026/'.fake()->unique()->numerify('####') : null,
            'title' => 'اختبار طبقة الاستشارة والإحاطة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $status)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
        ]);
    }

    private function moveStatus(Request $requestRecord, string $toCode, ?User $actor = null): void
    {
        $to = RequestStatus::where('code', $toCode)->value('id');

        RequestStatusHistory::create([
            'request_id' => $requestRecord->id,
            'from_status_id' => $requestRecord->status_id,
            'to_status_id' => $to,
            'changed_by_user_id' => ($actor ?? $this->userWithRole('R02'))->id,
            'changed_at' => now(),
        ]);

        $requestRecord->forceFill(['status_id' => $to])->save();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
