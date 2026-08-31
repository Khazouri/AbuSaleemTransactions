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
use App\Models\Template;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 35 — three richer committee-decision outcomes alongside
 * approve/reject/defer, plus recording a decision from a reusable template.
 */
class DecisionOutcomeTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_conditional_approval_advances_the_request_without_a_signature(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'conditional_approval'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'conditional_approval'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [
                'comment' => 'يشترط استكمال التصاريح خلال أسبوع',
            ])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'conditional_approval')
            ->assertJsonPath('data.votes_conditional_approval_count', 2);

        $stageEight = WorkflowStage::where('code', 'approval_by_authority')->firstOrFail();
        $requestRecord = $agendaItem->request()->first()->fresh();

        $this->assertSame($stageEight->id, $requestRecord->current_stage_id);
        $this->assertSame('approved_with_conditions', $requestRecord->status->code);
        $this->assertDatabaseMissing('approvals', ['request_id' => $requestRecord->id]);
    }

    public function test_legal_opinion_self_loops_at_the_committee_stage_and_requires_a_comment(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'legal_opinion'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'legal_opinion'])
            ->assertCreated();

        // No comment supplied: the underlying `request_legal_opinion` exception
        // requires one, the same way `defer` already does.
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [])
            ->assertStatus(422);
        $this->assertDatabaseMissing('decisions', ['meeting_request_id' => $agendaItem->id]);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [
                'comment' => 'بانتظار رأي الإدارة القانونية',
            ])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'legal_opinion');

        $stageSeven = WorkflowStage::where('code', 'receive_from_committee')->firstOrFail();
        $requestRecord = $agendaItem->request()->first()->fresh();

        $this->assertSame($stageSeven->id, $requestRecord->current_stage_id);
        $this->assertSame('legal_opinion_requested', $requestRecord->status->code);
    }

    public function test_refer_to_another_body_self_loops_at_the_committee_stage(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'refer_other_body'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'refer_other_body'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [
                'comment' => 'تحال إلى الجهاز المختص',
            ])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'refer_other_body');

        $stageSeven = WorkflowStage::where('code', 'receive_from_committee')->firstOrFail();
        $requestRecord = $agendaItem->request()->first()->fresh();

        $this->assertSame($stageSeven->id, $requestRecord->current_stage_id);
        $this->assertSame('referred_to_other_body', $requestRecord->status->code);
    }

    public function test_a_decision_records_and_returns_the_template_it_was_drafted_from(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();
        $template = Template::create([
            'code' => 'decision_conditional_approval',
            'category' => Template::CATEGORY_DECISION,
            'name_ar' => 'اعتماد مشروط',
            'body_ar' => 'تمت الموافقة بشرط استكمال المستندات.',
            'is_active' => true,
        ]);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [
                'comment' => 'تأجيل',
                'template_id' => $template->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.template.id', $template->id)
            ->assertJsonPath('data.template.name_ar', 'اعتماد مشروط');

        $this->assertDatabaseHas('decisions', [
            'meeting_request_id' => $agendaItem->id,
            'template_id' => $template->id,
        ]);
    }

    public function test_an_inactive_template_id_is_rejected(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $member, , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();
        $inactive = Template::create([
            'code' => 'decision_inactive',
            'category' => Template::CATEGORY_DECISION,
            'name_ar' => 'قالب غير مفعّل',
            'body_ar' => 'نص',
            'is_active' => false,
        ]);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'defer'])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [
                'comment' => 'تأجيل',
                'template_id' => $inactive->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('decisions', ['meeting_request_id' => $agendaItem->id]);
    }

    public function test_decision_filters_only_lists_active_decision_category_templates(): void
    {
        $this->seed(DatabaseSeeder::class);

        $decisionTemplate = Template::create([
            'code' => 'decision_template_active',
            'category' => Template::CATEGORY_DECISION,
            'name_ar' => 'قالب قرار فعّال',
            'body_ar' => 'نص',
            'is_active' => true,
        ]);
        Template::create([
            'code' => 'decision_template_inactive',
            'category' => Template::CATEGORY_DECISION,
            'name_ar' => 'قالب قرار غير مفعّل',
            'body_ar' => 'نص',
            'is_active' => false,
        ]);
        Template::create([
            'code' => 'general_template',
            'category' => null,
            'name_ar' => 'قالب عام',
            'body_ar' => 'نص',
            'is_active' => true,
        ]);

        $viewer = $this->userWithRole('R04');

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/decisions/filters')
            ->assertOk();

        $templateIds = collect($response->json('data.templates'))->pluck('id');

        $this->assertTrue($templateIds->contains($decisionTemplate->id));
        $this->assertCount(1, $templateIds);
    }

    /**
     * Stage 42 — the draft endpoint merges the agenda item's own request,
     * requester, department and committee data into the template body,
     * rather than returning the template's static text verbatim.
     */
    public function test_decision_draft_interpolates_the_agenda_items_own_data_into_the_template(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , $committee, $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();
        $employee = User::factory()->create(['name' => 'محمد الشريف']);
        $requestRecord = $agendaItem->request()->first();
        $requestRecord->update(['created_by_user_id' => $employee->id]);

        $template = Template::create([
            'code' => 'decision_draft_source',
            'category' => Template::CATEGORY_DECISION,
            'name_ar' => 'اعتماد مشروط',
            'body_ar' => 'بخصوص الطلب {{reference_number}} ({{request_title}}) المقدم من {{employee_name}} '
                .'في {{department}}، وبناءً على قرار {{committee_name}} بتاريخ {{decision_date}}.',
            'is_active' => true,
        ]);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision-draft?template_id={$template->id}")
            ->assertOk();

        $body = $response->json('data.body');

        $this->assertStringContainsString($requestRecord->reference_number, $body);
        $this->assertStringContainsString($requestRecord->title, $body);
        $this->assertStringContainsString('محمد الشريف', $body);
        $this->assertStringContainsString('إدارة الشؤون الإدارية', $body);
        $this->assertStringContainsString($committee->name_ar, $body);
        $this->assertStringNotContainsString('{{', $body);
    }

    public function test_decision_draft_honours_the_requested_locale(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();
        $employee = User::factory()->create(['name' => 'John Employee']);
        $agendaItem->request()->first()->update(['created_by_user_id' => $employee->id]);

        $template = Template::create([
            'code' => 'decision_draft_source_en',
            'category' => Template::CATEGORY_DECISION,
            'name_ar' => 'قالب ثنائي اللغة',
            'body_ar' => 'نص عربي بلا أهمية هنا.',
            'body_en' => 'Regarding the request filed by {{employee_name}} in {{department}}.',
            'is_active' => true,
        ]);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision-draft?template_id={$template->id}&locale=en")
            ->assertOk();

        $body = $response->json('data.body');

        $this->assertStringContainsString('John Employee', $body);
        $this->assertStringContainsString('Administrative Affairs', $body);
    }

    public function test_decision_draft_is_refused_for_a_non_employee_request_item(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , , $meeting] = $this->committeeMeetingWithAgendaItem();
        $adminItem = $meeting->agendaItems()->create([
            'agenda_order' => 2,
            'item_type' => 'administrative',
            'subject' => 'بند إداري',
        ]);
        $template = Template::create([
            'code' => 'decision_draft_source_admin',
            'category' => Template::CATEGORY_DECISION,
            'name_ar' => 'قالب',
            'body_ar' => 'نص',
            'is_active' => true,
        ]);

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$adminItem->id}/decision-draft?template_id={$template->id}")
            ->assertStatus(422);
    }

    public function test_decision_draft_requires_an_active_template(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, , , $meeting, $agendaItem] = $this->committeeMeetingWithAgendaItem();
        $inactive = Template::create([
            'code' => 'decision_draft_source_inactive',
            'category' => Template::CATEGORY_DECISION,
            'name_ar' => 'قالب غير مفعّل',
            'body_ar' => 'نص',
            'is_active' => false,
        ]);

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision-draft?template_id={$inactive->id}")
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision-draft")
            ->assertStatus(422);
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting, 4: MeetingRequest} */
    private function committeeMeetingWithAgendaItem(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة المشتريات']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اتخاذ القرار',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        $requestRecord = $this->requestAtCommitteeStage();
        $agendaItem = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);

        return [$head, $member, $committee, $meeting, $agendaItem];
    }

    private function requestAtCommitteeStage(): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب معروض على اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
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
