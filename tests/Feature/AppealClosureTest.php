<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\NotificationSetting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\AppealDecidedNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\RecordsStructuredDecisions;
use Tests\RunsStudySequence;
use Tests\TestCase;

/**
 * Stage 65, Track J — Art. 75 point 6: the appellant's written notice of the
 * appeal's final result, plus [D] Arts. 34–37's closure-field list
 * (AppealController::close(), the sole writer of `appeals.closure`).
 *
 * An appeal can close from three different starting points: either terminal
 * branch (`rejected` at Stage 60, `outside_jurisdiction` at Stage 62) or
 * `committee_presentation` once Stage 64 has already executed the outcome —
 * every test below is explicit about which one it's exercising.
 */
class AppealClosureTest extends TestCase
{
    use RecordsStructuredDecisions;
    use RefreshDatabase;
    use RunsStudySequence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_closing_from_the_rejected_branch_notifies_the_appellant_and_records_the_closure(): void
    {
        Notification::fake();

        $employee = $this->userWithRole('R01');
        $verifier = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'rejected');

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertOk()
            ->assertJsonPath('data.status.code', 'notified_closed')
            ->assertJsonPath('data.closure.final_result_code', 'rejected')
            ->assertJsonPath('data.closure.approving_body', 'عمادة البلدية')
            ->assertJsonPath('data.closure.file_storage_location', 'الأرشيف الإلكتروني - م.ت.٢٠٢٦-٠١٢')
            ->assertJsonPath('data.closure.notice_status', 'notified')
            ->assertJsonPath('data.closure.closed_by.id', $verifier->id);

        $this->assertDatabaseHas('appeals', [
            'id' => $appeal->id,
            'closed_by_user_id' => $verifier->id,
        ]);
        $this->assertNotNull($appeal->fresh()->closed_at);

        Notification::assertSentTo(
            $employee,
            AppealDecidedNotification::class,
            fn ($notification, array $channels) => $channels === ['database', 'mail'],
        );
        Notification::assertNotSentTo($verifier, AppealDecidedNotification::class);
    }

    public function test_closing_from_the_outside_jurisdiction_branch_succeeds(): void
    {
        $employee = $this->userWithRole('R01');
        $verifier = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'outside_jurisdiction');

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertOk()
            ->assertJsonPath('data.status.code', 'notified_closed')
            ->assertJsonPath('data.closure.final_result_code', 'outside_jurisdiction');
    }

    public function test_closing_is_refused_from_committee_presentation_before_the_outcome_is_executed(): void
    {
        [$appeal, $verifier] = $this->decidedAppealFixture('appeal_reject');

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertUnprocessable();
    }

    public function test_closing_succeeds_once_the_committee_outcome_has_been_executed_and_echoes_it(): void
    {
        [$appeal, $verifier] = $this->decidedAppealFixture('appeal_accept');

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/execute-outcome")
            ->assertOk();

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertOk()
            ->assertJsonPath('data.status.code', 'notified_closed')
            ->assertJsonPath('data.closure.final_result_code', 'appeal_accept');
    }

    public function test_closing_is_refused_before_the_appeal_has_concluded_at_all(): void
    {
        $employee = $this->userWithRole('R01');
        $verifier = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'legal_review');

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertUnprocessable();
    }

    public function test_closing_is_a_one_shot_action(): void
    {
        $employee = $this->userWithRole('R01');
        $verifier = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'rejected');

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertOk();

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertUnprocessable();
    }

    public function test_the_appellant_cannot_close_their_own_appeal(): void
    {
        // Holds R02 too, so the request clears the appeals.edit permission
        // gate and actually reaches the controller's self-action check — a
        // plain R01-only appellant is blocked earlier by the route
        // middleware itself, same precedent every other Track J action test
        // already established.
        $dualRoleAppellant = $this->userWithRole('R01');
        $dualRoleAppellant->roles()->attach(Role::where('code', 'R02')->value('id'));
        $appeal = $this->appealAt($dualRoleAppellant, 'rejected');

        $this->actingAs($dualRoleAppellant, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertUnprocessable();
    }

    public function test_a_role_without_appeals_edit_is_refused(): void
    {
        $employee = $this->userWithRole('R01');
        $stranger = $this->userWithRole('R01');
        $appeal = $this->appealAt($employee, 'rejected');

        $this->actingAs($stranger, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertForbidden();
    }

    public function test_approving_body_and_file_storage_location_are_required(): void
    {
        $employee = $this->userWithRole('R01');
        $verifier = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'rejected');

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['approving_body', 'file_storage_location']);
    }

    public function test_an_inactive_appellant_is_recorded_as_unreachable_and_is_not_notified(): void
    {
        Notification::fake();

        $employee = $this->userWithRole('R01');
        $verifier = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'rejected');

        $employee->update(['is_active' => false]);

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertOk()
            ->assertJsonPath('data.closure.notice_status', 'appellant_unreachable');

        Notification::assertNotSentTo($employee, AppealDecidedNotification::class);
    }

    public function test_closing_releases_the_hold_on_the_original_requests_own_closure(): void
    {
        $employee = $this->userWithRole('R01');
        $verifier = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'rejected');

        $this->assertTrue(Appeal::openAgainst($appeal->original_request_id));

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertOk();

        $this->assertFalse(Appeal::openAgainst($appeal->original_request_id));
    }

    public function test_notification_honors_the_appellants_disabled_channel_preference(): void
    {
        Notification::fake();

        $employee = $this->userWithRole('R01');
        $verifier = $this->userWithRole('R02');
        $appeal = $this->appealAt($employee, 'rejected');

        NotificationSetting::create([
            'user_id' => $employee->id,
            'event_type' => 'appeal_decided',
            'in_app' => true,
            'email' => false,
            'sms' => false,
        ]);

        $this->actingAs($verifier, 'sanctum')
            ->patchJson("/api/appeals/{$appeal->id}/close", $this->closurePayload())
            ->assertOk();

        Notification::assertSentTo(
            $employee,
            AppealDecidedNotification::class,
            fn ($notification, array $channels) => $channels === ['database'],
        );
    }

    // --- helpers ------------------------------------------------------------

    private function closurePayload(): array
    {
        return [
            'final_decision_number' => 'ق-2026-014',
            'approving_body' => 'عمادة البلدية',
            'execution_date' => now()->addDay()->toDateString(),
            'executing_body' => 'قسم شؤون الموظفين',
            'file_storage_location' => 'الأرشيف الإلكتروني - م.ت.٢٠٢٦-٠١٢',
        ];
    }

    /** @return array{0: Appeal, 1: User} the fresh, committee_presentation appeal and its R02 verifier. */
    private function decidedAppealFixture(string $outcome): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $verifier = $this->userWithRole('R02');

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع عرض التظلمات',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $employee = $this->userWithRole('R01');
        $appeal = $this->appealAt($employee, 'legal_review');

        $agendaItem = $meeting->agendaItems()->create([
            'appeal_id' => $appeal->id,
            'item_type' => 'appeal',
            'agenda_order' => 1,
        ]);
        // Stage 82 — [D] Art. 85's study sequence now gates voting; see
        // Tests\RunsStudySequence for why it is written directly here.
        $this->completeStudySequence($agendaItem);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => $outcome])
            ->assertCreated();
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => $outcome])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload($outcome, ['comment' => 'سبب القرار']))
            ->assertCreated();

        return [$appeal->fresh(), $verifier];
    }

    private function requestFixture(User $creator): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب صدر بشأنه قرار',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'final_approved')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
        ]);
    }

    private function appealAt(User $appellant, string $statusCode): Appeal
    {
        $target = $this->requestFixture($appellant);

        return Appeal::create([
            'appellant_user_id' => $appellant->id,
            'original_request_id' => $target->id,
            'original_decision_reference' => 'قرار اعتماد نهائي',
            'known_at' => now()->subDay(),
            'appeal_reasons' => 'القرار خالف الإجراءات المتبعة.',
            'final_request' => 'إعادة النظر في القرار.',
            'appeal_status_id' => AppealStatus::where('code', $statusCode)->value('id'),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
