<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stage 51 — [A] §7's employee-facing visibility fields on RequestDetailResource. */
class EmployeeRequestVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_never_presented_to_a_committee_has_no_committee_summary(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestOwnedBy($employee, 'requirements_check', 'in_review');

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.committee_summary', null)
            ->assertJsonPath('data.documents_complete', true);
    }

    public function test_an_incomplete_status_reports_documents_not_complete(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestOwnedBy($employee, 'receive_from_municipality', 'incomplete');

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.documents_complete', false);
    }

    public function test_a_completion_required_status_also_reports_documents_not_complete(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestOwnedBy($employee, 'receive_from_committee', 'completion_required');

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.documents_complete', false);
    }

    public function test_an_undecided_agenda_item_reports_the_meeting_but_no_result(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestOwnedBy($employee, 'receive_from_committee', 'in_meeting');
        [$meeting, $agendaItem] = $this->onAgenda($requestRecord);

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.committee_summary.meeting_number', $meeting->meeting_number)
            ->assertJsonPath('data.committee_summary.agenda_item_number', $agendaItem->agenda_order)
            ->assertJsonPath('data.committee_summary.committee_result', null)
            ->assertJsonPath('data.committee_summary.decision_date', null);
    }

    public function test_a_decided_agenda_item_reports_the_full_committee_summary(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestOwnedBy($employee, 'approval_by_authority', 'decided');
        [$meeting, $agendaItem] = $this->onAgenda($requestRecord);
        $head = $this->userWithRole('R03');
        $agendaItem->decision()->create([
            'outcome' => 'approve',
            'votes_approve_count' => 2,
            'decided_by_user_id' => $head->id,
            'decided_at' => now(),
        ]);

        $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.committee_summary.meeting_number', $meeting->meeting_number)
            ->assertJsonPath('data.committee_summary.agenda_item_number', $agendaItem->agenda_order)
            ->assertJsonPath('data.committee_summary.committee_result', 'approve')
            ->assertJsonPath('data.documents_complete', true);

        $this->assertNotNull($requestRecord->fresh());
    }

    /** @return array{0: Meeting, 1: MeetingRequest} */
    private function onAgenda(Request $requestRecord): array
    {
        $head = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'meeting_number' => '2026-07',
            'title' => 'اجتماع اللجنة',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);

        $agendaItem = $meeting->agendaItems()->create([
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
        ]);

        return [$meeting, $agendaItem];
    }

    private function requestOwnedBy(User $owner, string $stageCode, string $statusCode): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب موظف',
            'description' => 'طلب لاختبار الرؤية الخاصة بالموظف.',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $owner->id,
            'submitted_at' => now(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
