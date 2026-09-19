<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 44 — the live runner's quick-info panel ([C] §6's five non-notes
 * tabs: ملخص الطلب | بيانات الموظف | الدراسة | المرفقات | الطلبات السابقة).
 * See MeetingController::agendaItemContext()'s docblock for why this reads
 * through `meeting_live,view` rather than RequestVisibility — an R04
 * committee member is not necessarily the request's creator or its current
 * actionable assignee, only R03 (the decision-recording role) always is.
 */
class MeetingAgendaItemContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_returns_request_employee_study_attachments_and_previous_requests(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, , $meeting, $agendaItem, , $requestRecord, $employee] = $this->committeeMeetingWithRequestItem();

        // An earlier, unrelated request by the same employee.
        $previous = $this->requestByEmployee($employee, 'طلب سابق لنفس الموظف');

        RequestStageLog::create([
            'request_id' => $requestRecord->id,
            'from_stage_id' => WorkflowStage::where('code', 'reviewer_review')->value('id'),
            'to_stage_id' => WorkflowStage::where('code', 'observations')->value('id'),
            'action' => 'forward',
            'comment' => 'يحتاج مراجعة إضافية',
            'acted_by_user_id' => $head->id,
            'acted_at' => now()->subDays(2),
        ]);

        $attachment = Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => 'attachments/test.pdf',
            'original_name' => 'ملف.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'uploaded_by_user_id' => $head->id,
        ]);

        // R04 (not the creator, not the decision-recording role) can still
        // read the context — the point of not gating this on RequestVisibility.
        $response = $this->actingAs($member, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/context")
            ->assertOk();

        $response->assertJsonPath('data.request.id', $requestRecord->id)
            ->assertJsonPath('data.employee.id', $employee->id)
            ->assertJsonPath('data.employee.name', $employee->name)
            ->assertJsonPath('data.study.0.comment', 'يحتاج مراجعة إضافية')
            ->assertJsonPath('data.attachments.0.id', $attachment->id)
            ->assertJsonPath('data.previous_requests.0.id', $previous->id);
    }

    public function test_context_is_refused_for_a_non_request_agenda_item(): void
    {
        $this->seed(DatabaseSeeder::class);
        [, , $committee, $meeting, , $adminItem] = $this->committeeMeetingWithRequestItem(withAdminItem: true);

        // Membership gate — seated, so the request reaches the item-type check
        // this test is actually about rather than stopping at the gate.
        $chair = $this->userWithRole('R03');
        $committee->members()->create(['user_id' => $chair->id]);

        $this->actingAs($chair, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$adminItem->id}/context")
            ->assertStatus(422);
    }

    public function test_attachment_stream_works_for_a_member_who_is_not_the_creator_or_assignee(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, , $meeting, $agendaItem, , $requestRecord] = $this->committeeMeetingWithRequestItem();

        Storage::fake('local');
        Storage::disk('local')->put('attachments/test.pdf', 'stub');

        $attachment = Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => 'attachments/test.pdf',
            'original_name' => 'ملف.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 4,
            'uploaded_by_user_id' => $head->id,
        ]);

        $this->actingAs($member, 'sanctum')
            ->get("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/attachments/{$attachment->id}")
            ->assertOk();
    }

    public function test_attachment_stream_404s_for_an_attachment_belonging_to_a_different_request(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting, $agendaItem] = $this->committeeMeetingWithRequestItem();

        $otherRequest = $this->requestByEmployee($this->userWithRole('R01'), 'طلب آخر');
        $attachment = Attachment::create([
            'request_id' => $otherRequest->id,
            'disk' => 'local',
            'path' => 'attachments/other.pdf',
            'original_name' => 'ملف آخر.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 4,
            'uploaded_by_user_id' => $head->id,
        ]);

        $this->actingAs($head, 'sanctum')
            ->get("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/attachments/{$attachment->id}")
            ->assertStatus(404);
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting, 4: MeetingRequest, 5: ?MeetingRequest, 6: Request, 7: User} */
    private function committeeMeetingWithRequestItem(bool $withAdminItem = false): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $employee = $this->userWithRole('R01');

        $committee = Committee::create(['name_ar' => 'لجنة اختبار سياق البند']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اختبار السياق',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $requestRecord = $this->requestByEmployee($employee, 'طلب معروض على اللجنة');
        $agendaItem = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);

        $adminItem = null;
        if ($withAdminItem) {
            $adminItem = $meeting->agendaItems()->create([
                'item_type' => 'administrative',
                'subject' => 'بند إداري',
                'agenda_order' => 2,
            ]);
        }

        return [$head, $member, $committee, $meeting, $agendaItem, $adminItem, $requestRecord, $employee];
    }

    private function requestByEmployee(User $employee, string $title): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 999999),
            'title' => $title,
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $employee->id,
            'submitted_at' => now()->subDays(5),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
