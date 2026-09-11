<?php

namespace Tests\Feature;

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
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 82 — [D] Art. 83's agenda ordering, Appendix 24's per-item fields and
 * its two priority levels.
 */
class AgendaOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_agenda_is_ordered_by_art_83s_own_ranks(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();

        // Inserted in deliberately the wrong order, so the computed sequence
        // cannot accidentally match the insertion order.
        $ready = $this->agendaItem($meeting, $this->readyRequest('READY', now()->subDays(3)), 1);
        $urgent = $this->agendaItem($meeting, $this->request('URGENT'), 2, ['priority' => 'high', 'priority_reason' => 'ضرر وظيفي واضح على الموظف.']);
        $deadline = $this->agendaItem($meeting, $this->request('DEADLINE', legalDeadline: '30 يوماً من تاريخ الإخطار'), 3);
        $deferred = $this->agendaItem($meeting, $this->deferredRequest('DEFER'), 4);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/ordering")
            ->assertOk();

        $this->assertSame(
            [$deferred->id, $deadline->id, $urgent->id, $ready->id],
            $response->json('data.suggested_order'),
        );
        $this->assertFalse($response->json('data.matches_rule'));

        $ranks = collect($response->json('data.items'))->pluck('rank', 'id');
        $this->assertSame(1, $ranks[$deferred->id]);
        $this->assertSame(2, $ranks[$deadline->id]);
        $this->assertSame(3, $ranks[$urgent->id]);
        $this->assertSame(4, $ranks[$ready->id]);
    }

    public function test_complete_files_are_ordered_by_readiness_date_oldest_first(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();

        $newer = $this->agendaItem($meeting, $this->readyRequest('NEW', now()->subDay()), 1);
        $older = $this->agendaItem($meeting, $this->readyRequest('OLD', now()->subDays(10)), 2);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/ordering")
            ->assertOk();

        $this->assertSame([$older->id, $newer->id], $response->json('data.suggested_order'));
    }

    public function test_an_item_art_83_does_not_categorise_stays_at_the_end_in_its_own_order(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();

        $adminA = $meeting->agendaItems()->create([
            'item_type' => 'administrative', 'subject' => 'بند إداري أول', 'agenda_order' => 1,
        ]);
        $adminB = $meeting->agendaItems()->create([
            'item_type' => 'administrative', 'subject' => 'بند إداري ثانٍ', 'agenda_order' => 2,
        ]);
        $ready = $this->agendaItem($meeting, $this->readyRequest('READY', now()->subDay()), 3);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/meetings/{$meeting->id}/agenda/ordering")
            ->assertOk();

        // The complete file comes first (rank 4), and the two uncategorised
        // items keep the chair's own relative arrangement behind it.
        $this->assertSame([$ready->id, $adminA->id, $adminB->id], $response->json('data.suggested_order'));
    }

    public function test_applying_the_order_rewrites_agenda_order_and_clears_the_justification(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();
        $meeting->update(['agenda_order_justification' => 'ترتيب استثنائي بطلب رئيس اللجنة.']);

        $ready = $this->agendaItem($meeting, $this->readyRequest('READY', now()->subDay()), 1);
        $deferred = $this->agendaItem($meeting, $this->deferredRequest('DEFER'), 2);

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/apply-order")
            ->assertOk();

        $this->assertSame(1, $deferred->refresh()->agenda_order);
        $this->assertSame(2, $ready->refresh()->agenda_order);
        $this->assertNull($meeting->refresh()->agenda_order_justification);

        $this->assertTrue(
            $this->actingAs($head, 'sanctum')
                ->getJson("/api/meetings/{$meeting->id}/agenda/ordering")
                ->json('data.matches_rule'),
        );
    }

    public function test_the_profile_carries_appendix_24s_own_fields_and_reports_null_without_a_legal_card(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();

        $withCard = $this->agendaItem($meeting, $this->request('CARD', legalDeadline: '15 يوماً'), 1);
        $adminItem = $meeting->agendaItems()->create([
            'item_type' => 'administrative', 'subject' => 'بند إداري', 'agenda_order' => 2,
        ]);

        $items = collect(
            $this->actingAs($head, 'sanctum')
                ->getJson("/api/meetings/{$meeting->id}/agenda/ordering")
                ->assertOk()
                ->json('data.items'),
        )->keyBy('id');

        $fields = $items[$withCard->id]['fields'];
        $this->assertSame('sound_ready', $fields['legal_opinion']['verdict']);
        $this->assertSame('15 يوماً', $fields['legal_opinion']['legal_deadline']);
        $this->assertSame('decision', $fields['required_instrument']);
        $this->assertSame('عميد البلدية', $fields['expected_approving_body']);
        $this->assertSame('high', $fields['priority_level']);
        $this->assertFalse($fields['previously_presented']);
        $this->assertNotNull($fields['employee_name']);

        // Nothing is fabricated for an item that has no request behind it.
        $adminFields = $items[$adminItem->id]['fields'];
        $this->assertNull($adminFields['legal_opinion']);
        $this->assertNull($adminFields['required_instrument']);
        $this->assertNull($adminFields['expected_approving_body']);
        $this->assertSame('بند إداري', $adminFields['subject']);
    }

    public function test_a_previous_appearance_is_reported_with_its_own_meeting_number(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();
        $requestRecord = $this->deferredRequest('AGAIN');
        $item = $this->agendaItem($meeting, $requestRecord, 1);

        $fields = collect(
            $this->actingAs($head, 'sanctum')
                ->getJson("/api/meetings/{$meeting->id}/agenda/ordering")
                ->json('data.items'),
        )->firstWhere('id', $item->id)['fields'];

        $this->assertTrue($fields['previously_presented']);
        $this->assertSame('PM-MTG/PREV/01', $fields['previous_meeting']['meeting_number']);
        $this->assertSame('defer', $fields['previous_meeting']['outcome']);
    }

    /**
     * Regression: the register pages across every meeting at once, so a
     * sitting and the earlier one it was deferred from routinely land on the
     * same page. An early version excluded every row being profiled from the
     * prior-appearance lookup, which made the register report "لم يسبق عرضه"
     * for a file it was listing twice — found by the smoke run, not the suite.
     */
    public function test_the_agenda_register_reports_a_previous_appearance_on_its_own_page(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();
        $this->agendaItem($meeting, $this->deferredRequest('AGAIN'), 1);

        $rows = collect(
            $this->actingAs($head, 'sanctum')
                ->getJson('/api/registers/agenda')
                ->assertOk()
                ->json('data'),
        );

        // Both sittings are on this page. Exactly one row — the later one —
        // has a predecessor; the earlier sitting has nothing before it.
        $withPredecessor = $rows->filter(fn (array $row) => $row['previous_meeting'] !== null)->values();

        $this->assertCount(2, $rows);
        $this->assertCount(1, $withPredecessor);
        $this->assertSame('PM-MTG/PREV/01', $withPredecessor[0]['previous_meeting']);
        $this->assertSame('نعم', $withPredecessor[0]['previously_presented']);
        $this->assertSame('أولوية عالية', $withPredecessor[0]['priority']);
    }

    public function test_readiness_blocks_convening_on_an_out_of_rule_agenda_until_a_justification_is_recorded(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();
        $this->agendaItem($meeting, $this->readyRequest('READY', now()->subDay()), 1);
        $this->agendaItem($meeting, $this->deferredRequest('DEFER'), 2);

        $codes = fn () => collect(
            $this->actingAs($head, 'sanctum')
                ->getJson("/api/meetings/{$meeting->id}/readiness")
                ->assertOk()
                ->json('data.exceptions'),
        )->pluck('code')->all();

        $this->assertContains('agenda_order_departs_from_rule', $codes());

        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", [
                'agenda_order_justification' => 'قرر رئيس اللجنة تقديم الملف المكتمل لارتباطه باجتماع لاحق.',
            ])
            ->assertOk();

        $this->assertNotContains('agenda_order_departs_from_rule', $codes());
    }

    public function test_priority_accepts_only_appendix_24s_two_levels(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();
        $requestRecord = $this->request('LEVELS');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'request_id' => $requestRecord->id,
                // Stage 31's invented middle level is no longer a level.
                'priority' => 'medium',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('priority');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'request_id' => $requestRecord->id,
                'priority' => 'normal',
            ])
            ->assertCreated()
            ->assertJsonPath('data.priority', 'normal');
    }

    public function test_a_declared_high_priority_records_appendix_24s_own_justification(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();
        $item = $this->agendaItem($meeting, $this->request('REASON'), 1);

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$item->id}", [
                'priority' => 'high',
                // Stage 83 — Appendix 33 now requires the ground as well as the
                // مبرر Appendix 24 asks for, so a declared عالية names one.
                'priority_reason_code' => 'serious_job_harm',
                'priority_reason' => 'تأخير الملف يرتب ضرراً وظيفياً واضحاً على الموظف.',
            ])
            ->assertOk()
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.priority_reason', 'تأخير الملف يرتب ضرراً وظيفياً واضحاً على الموظف.');

        $grounds = collect(
            $this->actingAs($head, 'sanctum')
                ->getJson("/api/meetings/{$meeting->id}/agenda/ordering")
                ->json('data.items'),
        )->firstWhere('id', $item->id);

        $this->assertSame(['declared'], $grounds['priority_grounds']);
        $this->assertSame('high', $grounds['priority_level']);
    }

    /**
     * Stage 83 — [D] Appendix 33: "**لا تعتبر المعاملة مستعجلة لمجرد طلب
     * صاحبها ذلك**. ويمنح وصف (عاجل) فقط إذا: ..." five grounds, "**ويثبت سبب
     * الاستعجال في النظام**". Both halves bind, and normal priority needs
     * neither.
     */
    public function test_a_declared_urgency_needs_one_of_appendix_33s_five_grounds_and_a_recorded_reason(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();
        $item = $this->agendaItem($meeting, $this->request('URGENCY'), 1);
        $url = "/api/meetings/{$meeting->id}/agenda/{$item->id}";

        // The bare request, which the appendix's first sentence rules out.
        $this->actingAs($head, 'sanctum')
            ->patchJson($url, ['priority' => 'high'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('priority');

        // A ground with nothing recorded to substantiate it.
        $this->actingAs($head, 'sanctum')
            ->patchJson($url, ['priority' => 'high', 'priority_reason_code' => 'legal_period'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('priority');

        // A ground the appendix does not enumerate.
        $this->actingAs($head, 'sanctum')
            ->patchJson($url, [
                'priority' => 'high',
                'priority_reason_code' => 'requested_by_employee',
                'priority_reason' => 'طلب الموظف الاستعجال.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('priority_reason_code');

        $this->actingAs($head, 'sanctum')
            ->patchJson($url, [
                'priority' => 'high',
                'priority_reason_code' => 'legal_period',
                'priority_reason' => 'ترتبط المعاملة بمدة قانونية تنتهي خلال أسبوعين.',
            ])
            ->assertOk()
            ->assertJsonPath('data.priority_reason_code', 'legal_period')
            ->assertJsonPath('data.priority_reason_label', 'ترتبط بمدة قانونية');

        // أولوية عادية needs neither half.
        $this->actingAs($head, 'sanctum')
            ->patchJson($url, ['priority' => 'normal', 'priority_reason_code' => null, 'priority_reason' => null])
            ->assertOk();
    }

    /**
     * The rule reads the values the write would leave behind, not the payload
     * alone — otherwise clearing the ground while the level stays عالية would
     * slip past it.
     */
    public function test_clearing_the_ground_on_an_already_urgent_item_is_refused(): void
    {
        $this->seed(DatabaseSeeder::class);

        [$head, $meeting] = $this->committeeAndMeeting();
        $item = $this->agendaItem($meeting, $this->request('MERGED'), 1, [
            'priority' => 'high',
            'priority_reason_code' => 'official_directive',
            'priority_reason' => 'توجيه رسمي بسرعة البت.',
        ]);

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$item->id}", ['priority_reason_code' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('priority');

        // And the same in reverse: raising the level without restating a
        // ground the item already carries is fine.
        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/meetings/{$meeting->id}/agenda/{$item->id}", ['estimated_minutes' => 30])
            ->assertOk();
    }

    // --- fixtures ----------------------------------------------------------

    /** @return array{0: User, 1: Meeting} */
    private function committeeAndMeeting(): array
    {
        $head = $this->userWithRole('R03');

        $committee = Committee::create([
            'name_ar' => 'لجنة شؤون الموظفين',
            // Stage 73 — a committee with no transcribed quorum rule blocks
            // convening on its own, which would drown out the exception this
            // test is actually about.
            'quorum_type' => 'fraction',
            'quorum_numerator' => 1,
            'quorum_denominator' => 2,
            'quorum_comparator' => 'more_than',
            'quorum_text' => 'أكثر من نصف الأعضاء',
        ]);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع ترتيب جدول الأعمال',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);

        return [$head, $meeting];
    }

    private function agendaItem(Meeting $meeting, Request $requestRecord, int $order, array $extra = []): MeetingRequest
    {
        return $meeting->agendaItems()->create([
            'request_id' => $requestRecord->id,
            'agenda_order' => $order,
            ...$extra,
        ]);
    }

    private function request(string $suffix, ?string $legalDeadline = null): Request
    {
        $requestRecord = Request::create([
            'reference_number' => now()->format('Y')."-ADM-{$suffix}-".fake()->unique()->numberBetween(1000, 9999),
            'title' => "طلب {$suffix}",
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now(),
        ]);

        $requestRecord->legalReviews()->create([
            'verdict' => 'sound_ready',
            'committee_mandate' => 'decision',
            'approving_body' => 'عميد البلدية',
            'legal_deadline' => $legalDeadline,
            'reviewed_at' => now(),
        ]);

        return $requestRecord->refresh();
    }

    /** A file that entered Art. 38's `ready` status at a known moment. */
    private function readyRequest(string $suffix, $readyAt): Request
    {
        $requestRecord = $this->request($suffix);

        RequestStatusHistory::create([
            'request_id' => $requestRecord->id,
            'to_status_id' => RequestStatus::where('code', 'ready')->value('id'),
            'changed_at' => $readyAt,
        ]);

        return $requestRecord;
    }

    /** A file the committee deferred at an earlier sitting. */
    private function deferredRequest(string $suffix): Request
    {
        $requestRecord = $this->request($suffix);

        $previousMeeting = Meeting::create([
            'committee_id' => Committee::query()->value('id'),
            'meeting_number' => 'PM-MTG/PREV/01',
            'title' => 'اجتماع سابق',
            'scheduled_at' => now()->subWeek(),
            'created_by_user_id' => $requestRecord->created_by_user_id,
        ]);

        $previousItem = $previousMeeting->agendaItems()->create([
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
        ]);

        Decision::create([
            'meeting_request_id' => $previousItem->id,
            'outcome' => 'defer',
            'decided_by_user_id' => $requestRecord->created_by_user_id,
            'decided_at' => now()->subWeek(),
        ]);

        return $requestRecord;
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
