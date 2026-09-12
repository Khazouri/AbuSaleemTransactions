<?php

namespace Tests\Feature;

use App\Models\Attachment;
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
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 46 — [D] Art. 22's compiled presentation memo. See
 * PresentationMemoCompiler's docblock for the derived/authored field split
 * and why `legal_opinion` is deliberately not sourced from a Stage 35
 * committee-voted "legal_opinion" decision outcome.
 */
class PresentationMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_compiles_the_expected_derived_fields(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting, $agendaItem, , $requestRecord, $employee] = $this->committeeMeetingWithRequestItem();

        Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => 'attachments/test.pdf',
            'original_name' => 'ملف.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'uploaded_by_user_id' => $head->id,
        ]);

        $response = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo/generate")
            ->assertOk();

        $response->assertJsonPath('data.derived.reference_number', $requestRecord->reference_number)
            ->assertJsonPath('data.derived.employee.id', $employee->id)
            ->assertJsonPath('data.derived.subject', $requestRecord->title)
            ->assertJsonPath('data.derived.work_unit.id', $employee->department_id)
            ->assertJsonPath('data.derived.referring_body.id', $requestRecord->department_id)
            ->assertJsonCount(1, 'data.derived.key_documents')
            ->assertJsonPath('data.authored.facts_summary', $requestRecord->description)
            ->assertJsonPath('data.authored.legal_opinion', '')
            ->assertJsonPath('data.generated_by.id', $head->id);
    }

    public function test_regenerate_refreshes_derived_fields_but_preserves_edited_authored_fields(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting, $agendaItem] = $this->committeeMeetingWithRequestItem();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo/generate")
            ->assertOk()
            ->assertJsonCount(0, 'data.derived.key_documents');

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo", [
                'legal_opinion' => 'الملف سليم قانونياً.',
            ])
            ->assertOk()
            ->assertJsonPath('data.authored.legal_opinion', 'الملف سليم قانونياً.');

        Attachment::create([
            'request_id' => $agendaItem->request_id,
            'disk' => 'local',
            'path' => 'attachments/new.pdf',
            'original_name' => 'مستند جديد.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 512,
            'uploaded_by_user_id' => $head->id,
        ]);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo/generate")
            ->assertOk()
            ->assertJsonCount(1, 'data.derived.key_documents')
            ->assertJsonPath('data.authored.legal_opinion', 'الملف سليم قانونياً.');
    }

    public function test_prior_decisions_scoped_to_the_same_request_not_the_employees_other_requests(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, $committee, $meeting, $agendaItem, , $requestRecord, $employee] = $this->committeeMeetingWithRequestItem();

        // The same request revisiting the committee via an earlier, already-
        // decided agenda appearance (e.g. after a `defer` self-loop).
        $earlierMeeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع سابق لنفس الطلب',
            'scheduled_at' => now()->subWeek(),
            'created_by_user_id' => $head->id,
        ]);
        $earlierItem = $earlierMeeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);
        Decision::create([
            'meeting_request_id' => $earlierItem->id,
            'outcome' => 'defer',
            'votes_defer_count' => 2,
            'comment' => 'تم التأجيل لاستكمال المستندات.',
            'decided_by_user_id' => $head->id,
            'decided_at' => now()->subWeek(),
        ]);

        // A decision on a *different* request by the same employee — must
        // not leak into this memo's prior_decisions.
        $otherRequest = $this->requestByEmployee($employee, 'طلب آخر لنفس الموظف');
        $otherItem = $earlierMeeting->agendaItems()->create(['request_id' => $otherRequest->id, 'agenda_order' => 2]);
        Decision::create([
            'meeting_request_id' => $otherItem->id,
            'outcome' => 'approve',
            'votes_approve_count' => 2,
            'decided_by_user_id' => $head->id,
            'decided_at' => now()->subWeek(),
        ]);

        // Stage 84 — drafted by the chair rather than the member: the memo is
        // المقرر's under Appendix 6's RACI, and R04 no longer holds the tier.
        // This test is about prior-decision scoping, not about the grant.
        $response = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo/generate")
            ->assertOk();

        $response->assertJsonCount(1, 'data.derived.prior_decisions')
            ->assertJsonPath('data.derived.prior_decisions.0.outcome', 'defer');
    }

    public function test_update_404s_before_a_memo_exists_then_patches_only_submitted_authored_keys(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting, $agendaItem] = $this->committeeMeetingWithRequestItem();

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo", ['legal_opinion' => 'x'])
            ->assertStatus(404);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo/generate")
            ->assertOk();

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo", [
                'committee_question' => 'هل تستحق الترقية؟',
            ])
            ->assertOk()
            ->assertJsonPath('data.authored.committee_question', 'هل تستحق الترقية؟')
            ->assertJsonPath('data.authored.legal_opinion', '');

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo")
            ->assertOk()
            ->assertJsonPath('data.authored.committee_question', 'هل تستحق الترقية؟');
    }

    public function test_all_three_actions_refuse_a_non_request_agenda_item(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting, , $adminItem] = $this->committeeMeetingWithRequestItem(withAdminItem: true);

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$adminItem->id}/presentation-memo")
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$adminItem->id}/presentation-memo/generate")
            ->assertStatus(422);
    }

    /**
     * Stage 84 — the memo is the rapporteur's, not any member's. [D] Appendix
     * 6's RACI makes إعداد مذكرة العرض مقرر اللجنة's own responsibility and
     * Art. 15 (أ) أولًا 11 lists تجهيز ملفات العرض ومذكرات العرض among the
     * pre-meeting duties, so R02 drafts and R04 — who held this tier purely
     * because Stage 46 read "a member acting as مقرر" into it — no longer can.
     */
    public function test_the_rapporteur_generates_a_memo_while_a_member_and_an_outsider_are_refused(): void
    {
        $this->seed(DatabaseSeeder::class);
        [, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithRequestItem();

        $rapporteur = $this->userWithRole('R02');
        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo/generate")
            ->assertOk();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo/generate")
            ->assertStatus(403);

        $outsider = $this->userWithRole('R01');
        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/presentation-memo/generate")
            ->assertStatus(403);
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting, 4: MeetingRequest, 5: ?MeetingRequest, 6: Request, 7: User} */
    private function committeeMeetingWithRequestItem(bool $withAdminItem = false): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $employee = $this->userWithRole('R01');
        // A different department from the request's own ADM below, so the
        // test can prove work_unit and referring_body are genuinely two
        // distinct fields, not the same value read twice.
        $employee->update(['department_id' => Department::where('code', 'ENG')->value('id')]);

        $committee = Committee::create(['name_ar' => 'لجنة اختبار مذكرة العرض']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اختبار مذكرة العرض',
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
            'description' => 'وصف الطلب: '.$title,
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
