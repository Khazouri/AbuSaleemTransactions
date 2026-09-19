<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AppealStatus;
use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestLegalReview;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\RecordsStructuredDecisions;
use Tests\RunsStudySequence;
use Tests\TestCase;

/**
 * Stage 74 — [D] Appendix 27's four decision parts, Art. 90's
 * قرار/توصية/رأي, Art. 34's five deferral fields, Appendix 28 + Art. 91's
 * refusal reasoning, and Appendix 59's seven seeded formulas.
 *
 * Unlike every other decision test, the structure IS the subject here, so
 * these build deliberately incomplete payloads rather than reaching for
 * RecordsStructuredDecisions' complete one — which is used only where a test
 * needs a *valid* decision to get past the gate it is not about.
 */
class DecisionStructureTest extends TestCase
{
    use RecordsStructuredDecisions;
    use RefreshDatabase;
    use RunsStudySequence;

    // ---- Art. 89 / Appendix 27 ------------------------------------------

    public function test_the_subject_and_the_operative_clause_are_required_of_every_outcome(): void
    {
        [$head, $meeting, $agendaItem] = $this->approvedItem();

        // Art. 90 first: nothing is recordable until the committee says
        // which of the three it is issuing.
        $this->recordWith($head, $meeting, $agendaItem, [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يجب تحديد نوع ما أصدرته اللجنة: قرار أو توصية أو رأي.');

        $this->recordWith($head, $meeting, $agendaItem, ['instrument' => 'decision'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يجب بيان موضوع القرار بصياغة محددة.');

        $this->recordWith($head, $meeting, $agendaItem, [
            'instrument' => 'decision',
            'decision_subject' => 'طلب ترقية',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يجب بيان منطوق النتيجة بصورة واضحة وقابلة للتنفيذ.');

        $this->assertSame(0, Decision::count());
    }

    public function test_the_facts_and_the_basis_are_required_of_a_substantive_outcome_only(): void
    {
        [$head, $meeting, $agendaItem] = $this->approvedItem();

        // approve disposes of the matter, so Appendix 27's other two parts bind.
        $this->recordWith($head, $meeting, $agendaItem, [
            'instrument' => 'decision',
            'decision_subject' => 'طلب ترقية',
            'decision_operative' => 'تقرر اللجنة الموافقة وإحالة الملف لاستكمال إجراءات الاعتماد.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يجب إثبات وقائع الموضوع بصورة مختصرة.');

        $this->recordWith($head, $meeting, $agendaItem, [
            'instrument' => 'decision',
            'decision_subject' => 'طلب ترقية',
            'decision_operative' => 'تقرر اللجنة الموافقة وإحالة الملف لاستكمال إجراءات الاعتماد.',
            'decision_facts' => 'أكمل الموظف المدة المقررة وفق كشف الخدمة.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يجب بيان السند الذي استندت إليه اللجنة.');

        // A referral does not dispose of the matter, so it is asked for
        // neither — proving the split is real and not just documented.
        [$referHead, $referMeeting, $referItem] = $this->votedItem('refer_other_body');

        $this->recordWith($referHead, $referMeeting, $referItem, [
            'instrument' => 'recommendation',
            'decision_subject' => 'إحالة الموضوع لجهة مختصة',
            'decision_operative' => 'تقرر اللجنة إحالة الموضوع إلى الإدارة القانونية لإبداء الرأي.',
            'comment' => 'يحال للإدارة القانونية',
        ])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'refer_other_body')
            ->assertJsonPath('data.instrument', 'recommendation')
            ->assertJsonPath('data.decision_facts', null);
    }

    public function test_an_unmeasurable_operative_clause_is_refused_but_the_same_phrase_tied_to_an_action_is_not(): void
    {
        [$head, $meeting, $agendaItem] = $this->votedItem('refer_other_body');

        $base = [
            'instrument' => 'decision',
            'decision_subject' => 'إحالة الموضوع',
            'comment' => 'إحالة',
        ];

        $this->recordWith($head, $meeting, $agendaItem, $base + ['decision_operative' => 'اتخاذ اللازم.'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يكفي منطوق غير قابل للقياس مثل (اتخاذ اللازم) أو (النظر في الموضوع) أو (حسب الإجراءات) دون ربطه بإجراء محدد وواضح.');

        // Appendix 27 bans the phrase "إلا إذا كانت مرتبطة بإجراء محدد
        // وواضح" — so the phrase plus a concrete action must pass.
        $this->recordWith($head, $meeting, $agendaItem, $base + [
            'decision_operative' => 'اتخاذ اللازم نحو إحالة الملف إلى الإدارة القانونية خلال أسبوع.',
        ])
            ->assertCreated();
    }

    // ---- Art. 90 ---------------------------------------------------------

    public function test_the_instrument_is_recorded_and_pre_filled_from_the_legal_card(): void
    {
        [$head, $meeting, $agendaItem] = $this->approvedItem();

        RequestLegalReview::create([
            'request_id' => $agendaItem->request_id,
            'verdict' => 'sound_ready',
            'committee_mandate' => 'recommendation',
            'reviewed_by_user_id' => $head->id,
            'reviewed_at' => now(),
        ]);

        // The pre-fill is a UI hint read off the agenda payload, not a
        // server-side default: the committee is not bound by the legal
        // member's expectation (Art. 14 (ب)).
        $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}")
            ->assertOk()
            ->assertJsonPath('data.agenda_items.0.request.expected_instrument', 'recommendation');

        $this->recordWith($head, $meeting, $agendaItem, $this->decisionPayload('approve', [
            'instrument' => 'opinion',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.instrument', 'opinion');
    }

    public function test_an_unknown_instrument_is_refused(): void
    {
        [$head, $meeting, $agendaItem] = $this->approvedItem();

        $this->recordWith($head, $meeting, $agendaItem, $this->decisionPayload('approve', [
            // Appendix 22's fourth mandate value is not one of Art. 90's
            // three instruments — دراسة فقط is the absence of one.
            'instrument' => 'study_only',
        ]))->assertStatus(422);

        $this->assertSame(0, Decision::count());
    }

    // ---- Art. 34 / Appendix 29 -------------------------------------------

    public function test_a_deferral_requires_art_34s_four_mandatory_fields_and_not_the_optional_fifth(): void
    {
        [$head, $meeting, $agendaItem] = $this->votedItem('defer');

        $base = [
            'instrument' => 'decision',
            'decision_subject' => 'تأجيل البت في الطلب',
            'decision_operative' => 'تؤجل دراسة المعاملة إلى حين استكمال المطلوب.',
            'comment' => 'تأجيل',
        ];

        $expected = [
            'deferral_reason' => 'يجب إثبات سبب التأجيل.',
            'deferral_required_completion' => 'يجب بيان المطلوب استكماله — لا يقبل التأجيل للمراجعة دون بيان المطلوب.',
            'deferral_responsible_body' => 'يجب تحديد الجهة المسؤولة عن الاستكمال.',
            'deferral_required_document' => 'يجب تحديد المستند أو الإفادة المطلوبة.',
        ];

        $payload = $base;
        foreach ($expected as $field => $message) {
            $this->recordWith($head, $meeting, $agendaItem, $payload)
                ->assertStatus(422)
                ->assertJsonPath('message', $message);

            $payload[$field] = 'قيمة';
        }

        // The fifth field alone carries "إن وجدت", so the deferral records
        // without it.
        $this->recordWith($head, $meeting, $agendaItem, $payload)
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'defer')
            ->assertJsonPath('data.deferral_reason', 'قيمة')
            ->assertJsonPath('data.deferral_legal_period', null);
    }

    // ---- Art. 91 / Appendix 28 -------------------------------------------

    public function test_a_refusal_needs_a_professional_reason_and_more_than_a_generic_phrase(): void
    {
        [$head, $meeting, $agendaItem] = $this->votedItem('reject');

        $base = [
            'instrument' => 'decision',
            'decision_subject' => 'عدم الموافقة على الطلب',
            'decision_operative' => 'تقرر اللجنة عدم الموافقة على الطلب.',
            'decision_facts' => 'لعدم الاستحقاق',
            'decision_basis' => 'لمصلحة العمل',
            'comment' => 'رفض مسبب',
        ];

        $this->recordWith($head, $meeting, $agendaItem, $base)
            ->assertStatus(422)
            ->assertJsonPath('message', 'يجب تحديد سبب عدم الموافقة بصورة مهنية.');

        // Appendix 28's list is prefixed with "مثل", so `other` exists — but
        // the reasoning still has to say something.
        $this->recordWith($head, $meeting, $agendaItem, $base + ['refusal_reason_code' => 'other'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا تكفي عبارات عامة مثل (لمصلحة العمل) أو (لعدم الاستحقاق) — يجب بيان السبب الحقيقي وإثباته في الملف.');

        $this->assertSame(0, Decision::count());

        $this->recordWith($head, $meeting, $agendaItem, array_merge($base, [
            'refusal_reason_code' => 'period_condition_unmet',
            'decision_facts' => 'لعدم الاستحقاق — لم يكمل الموظف مدة الثلاث سنوات المقررة في درجته الحالية.',
            'decision_basis' => 'المادة 135 من قانون علاقات العمل.',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'reject')
            ->assertJsonPath('data.refusal_reason_code', 'period_condition_unmet');
    }

    public function test_a_no_jurisdiction_outcome_is_reasoned_on_the_same_terms(): void
    {
        [$head, $meeting, $agendaItem] = $this->votedItem('no_jurisdiction');

        $this->recordWith($head, $meeting, $agendaItem, [
            'instrument' => 'decision',
            'decision_subject' => 'عدم اختصاص اللجنة',
            'decision_operative' => 'تقرر اللجنة إحالة الملف إلى الجهة المختصة.',
            'decision_facts' => 'الموضوع يتعلق بالمرتبات.',
            'decision_basis' => 'اختصاص قسم المرتبات والمزايا.',
            'comment' => 'عدم اختصاص',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يجب تحديد سبب عدم الموافقة بصورة مهنية.');

        $this->recordWith($head, $meeting, $agendaItem, [
            'instrument' => 'decision',
            'decision_subject' => 'عدم اختصاص اللجنة',
            'decision_operative' => 'تقرر اللجنة إحالة الملف إلى الجهة المختصة.',
            'decision_facts' => 'الموضوع يتعلق بالمرتبات.',
            'decision_basis' => 'اختصاص قسم المرتبات والمزايا.',
            'refusal_reason_code' => 'outside_jurisdiction',
            'comment' => 'عدم اختصاص',
        ])->assertCreated();
    }

    // ---- the appeal path -------------------------------------------------

    public function test_an_appeal_decision_is_held_to_the_same_drafting_rules(): void
    {
        [$head, $meeting, $agendaItem] = $this->appealItemVoted('appeal_reject');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [
                'comment' => 'رفض التظلم',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'يجب تحديد نوع ما أصدرته اللجنة: قرار أو توصية أو رأي.');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('appeal_reject', [
                'comment' => 'رفض التظلم',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'appeal_reject')
            ->assertJsonPath('data.refusal_reason_code', 'period_condition_unmet');
    }

    // ---- Appendix 59 -----------------------------------------------------

    public function test_appendix_59s_seven_formulas_are_seeded_and_offered_as_decision_templates(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(7, Template::where('category', Template::CATEGORY_DECISION)->count());

        foreach (range(1, 7) as $index) {
            $this->assertDatabaseHas('templates', [
                'code' => sprintf('DEC-A59-%02d', $index),
                'category' => Template::CATEGORY_DECISION,
                'is_active' => true,
            ]);
        }

        $viewer = $this->userWithRole('R04');
        $codes = collect(
            $this->actingAs($viewer, 'sanctum')->getJson('/api/decisions/filters')->assertOk()->json('data.templates'),
        )->pluck('code');

        $this->assertTrue($codes->contains('DEC-A59-01'));
        $this->assertTrue($codes->contains('DEC-A59-07'));
    }

    public function test_the_deferral_formula_drafts_with_the_requests_own_reference_number(): void
    {
        [$head, $meeting, $agendaItem] = $this->votedItem('defer');
        $template = Template::where('code', 'DEC-A59-03')->firstOrFail();

        $body = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision-draft?template_id={$template->id}&locale=ar")
            ->assertOk()
            ->json('data.body');

        // Appendix 59's own blank for رقم المعاملة is the one place a Stage
        // 42 token replaces the source's dots, so the draft names the real
        // request rather than handing back a row of full stops.
        $this->assertStringContainsString($agendaItem->request->reference_number, $body);
        $this->assertStringNotContainsString('{{', $body);
    }

    // ---- the محضر --------------------------------------------------------

    public function test_the_structure_reaches_the_compiled_minutes(): void
    {
        [$head, $meeting, $agendaItem] = $this->votedItem('defer');

        $this->recordWith($head, $meeting, $agendaItem, $this->decisionPayload('defer', ['comment' => 'تأجيل']))
            ->assertCreated();

        $item = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk()
            ->json('data.content.agenda_items.0.decision');

        $this->assertSame('decision', $item['instrument']);
        $this->assertNotEmpty($item['subject']);
        $this->assertNotEmpty($item['operative']);
        $this->assertSame('إدارة الموارد البشرية', $item['deferral']['responsible_body']);
    }

    // ---- fixtures --------------------------------------------------------

    /** @return array{0: User, 1: Meeting, 2: MeetingRequest} */
    private function approvedItem(): array
    {
        return $this->votedItem('approve');
    }

    /** @return array{0: User, 1: Meeting, 2: MeetingRequest} */
    private function votedItem(string $vote): array
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
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

        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب معروض على اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
        ]);
        $agendaItem = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);
        // Stage 82 — [D] Art. 85's study sequence now gates voting; see
        // Tests\RunsStudySequence for why it is written directly here.
        $this->completeStudySequence($agendaItem);

        foreach ([$head, $member] as $voter) {
            $this->actingAs($voter, 'sanctum')
                ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => $vote])
                ->assertCreated();
        }

        return [$head, $meeting, $agendaItem];
    }

    /** @return array{0: User, 1: Meeting, 2: MeetingRequest} */
    private function appealItemVoted(string $vote): array
    {
        [$head, $meeting] = $this->votedItem('approve');
        $member = $meeting->committee->members()->where('is_head', false)->firstOrFail()->user;

        $appellant = $this->userWithRole('R01');
        $decided = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب سبق البت فيه',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'not_approved')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $appellant->id,
            'submitted_at' => now(),
        ]);

        $appeal = Appeal::create([
            'appellant_user_id' => $appellant->id,
            'original_request_id' => $decided->id,
            'appeal_status_id' => AppealStatus::where('code', 'legal_review')->value('id'),
            'known_at' => now()->subDays(3),
            'appeal_reasons' => 'أسباب الاعتراض',
            'final_request' => 'إعادة النظر في القرار',
        ]);

        $agendaItem = $meeting->agendaItems()->create([
            'item_type' => 'appeal',
            'appeal_id' => $appeal->id,
            'agenda_order' => 2,
        ]);
        // Stage 82 — [D] Art. 85's study sequence now gates voting; see
        // Tests\RunsStudySequence for why it is written directly here.
        $this->completeStudySequence($agendaItem);

        foreach ([$head, $member] as $voter) {
            $this->actingAs($voter, 'sanctum')
                ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => $vote])
                ->assertCreated();
        }

        return [$head, $meeting, $agendaItem];
    }

    /** @param  array<string, mixed>  $payload */
    private function recordWith(User $actor, Meeting $meeting, MeetingRequest $agendaItem, array $payload)
    {
        return $this->actingAs($actor, 'sanctum')
            ->post(
                "/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision",
                $payload,
                ['Accept' => 'application/json'],
            );
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
