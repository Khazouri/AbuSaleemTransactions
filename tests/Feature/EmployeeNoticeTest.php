<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\NotificationSetting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\DecisionRecordedNotification;
use App\Notifications\RequestNoticeNotification;
use App\Services\EmployeeNoticeService;
use App\Services\NotificationDispatcher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Stage 79 — [D] Art. 101's twelve notification moments and Art. 102's limits
 * on what a notice may contain.
 */
class EmployeeNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Art. 101's twelve moments, each fired by the state it names.
     *
     * The point of driving all twelve through the same status-history write is
     * that this is exactly how the eight services that move a status reach the
     * notice — no service has its own path to it, so none can be missed.
     */
    public function test_every_one_of_art_101s_twelve_moments_fires_on_the_status_that_names_it(): void
    {
        $cases = [
            ['registered', 'received', 1],
            ['incomplete', 'documents_missing', 2],
            ['on_agenda', 'placed_on_agenda', 4],
            ['deferred', 'committee_result', 5],
            ['legal_opinion_requested', 'committee_result', 5],
            ['referred_to_other_body', 'committee_result', 5],
            ['awaiting_municipal_approval', 'referred_for_approval', 6],
            ['awaiting_central_approval', 'referred_for_approval', 6],
            ['approved_with_conditions', 'referred_for_approval', 6],
            ['final_approved', 'final_approval', 7],
            ['completion_required', 'returned_for_completion', 8],
            ['returned', 'returned_for_completion', 8],
            ['not_approved', 'not_approved', 9],
            ['rejected', 'not_approved', 9],
            ['outside_jurisdiction', 'no_jurisdiction', 10],
            ['in_execution', 'execution_started', 11],
            ['completed_closed', 'closed', 12],
        ];

        foreach ($cases as [$statusCode, $moment, $number]) {
            Notification::fake();

            $employee = $this->employee();
            $requestRecord = $this->requestFor($employee);

            $this->moveStatus($requestRecord, $statusCode);

            Notification::assertSentTo(
                $employee,
                RequestNoticeNotification::class,
                function (RequestNoticeNotification $notice) use ($employee, $moment, $number, $statusCode) {
                    $payload = $notice->toArray($employee);

                    $this->assertSame($moment, $payload['moment'], "status {$statusCode}");
                    $this->assertSame($number, $payload['moment_number'], "status {$statusCode}");

                    return true;
                },
            );
        }

        // Moment 3 has its own test below — the same status says two different
        // things depending on what the file has been through.
        $this->assertCount(12, EmployeeNoticeService::MOMENTS);
    }

    /**
     * Art. 20 grants the قيد once (Art. 99), so a file that went out on
     * `return_missing_docs` and came back does not get told it was received a
     * second time — reaching `registered` again is literally moment 3, the
     * completeness re-check passing.
     */
    public function test_registered_reads_as_receipt_the_first_time_and_as_completion_after_a_nawaqis_loop(): void
    {
        Notification::fake();

        $employee = $this->employee();
        $requestRecord = $this->requestFor($employee);

        $this->moveStatus($requestRecord, 'registered');
        $this->assertSame('received', $this->lastMoment($employee));

        $this->moveStatus($requestRecord, 'incomplete');
        $this->assertSame('documents_missing', $this->lastMoment($employee));

        $this->moveStatus($requestRecord, 'in_review');
        $this->moveStatus($requestRecord, 'registered');

        $this->assertSame('documents_completed', $this->lastMoment($employee));
    }

    /** The committee's own side of the same loop: the completion arrived and work resumes. */
    public function test_leaving_completion_required_for_a_working_status_reads_as_completion(): void
    {
        Notification::fake();

        $employee = $this->employee();
        $requestRecord = $this->requestFor($employee);

        $this->moveStatus($requestRecord, 'completion_required');
        $this->assertSame('returned_for_completion', $this->lastMoment($employee));

        $this->moveStatus($requestRecord, 'under_discussion');
        $this->assertSame('documents_completed', $this->lastMoment($employee));
    }

    /**
     * WorkflowService stamps a status row on every move "even when adjacent
     * stages share a broad status" — so a row whose from and to are the same
     * status must announce nothing, or the employee would be told the file
     * reached a state it never left.
     */
    public function test_a_status_row_that_does_not_change_the_status_notifies_nobody(): void
    {
        Notification::fake();

        $employee = $this->employee();
        $requestRecord = $this->requestFor($employee);

        $registered = RequestStatus::where('code', 'registered')->value('id');

        RequestStatusHistory::create([
            'request_id' => $requestRecord->id,
            'from_status_id' => $registered,
            'to_status_id' => $registered,
            'reason' => null,
            'changed_by_user_id' => $this->userWithRole('R02')->id,
            'changed_at' => now(),
        ]);

        Notification::assertNothingSent();
    }

    /** An employee acting on their own file is not told what they just did. */
    public function test_the_actor_is_never_notified_about_their_own_act(): void
    {
        Notification::fake();

        $employee = $this->employee();
        $requestRecord = $this->requestFor($employee);

        $this->moveStatus($requestRecord, 'registered', $employee);

        Notification::assertNothingSent();
    }

    /**
     * Three statuses are deliberately not moments. Art. 101 enumerates twelve,
     * and this track's whole purpose is identity with [D] rather than addition
     * — see AGENT_NOTES.md for why Art. 105's suspension and Art. 94's return
     * are not a thirteenth.
     */
    public function test_the_statuses_art_101_does_not_name_announce_nothing(): void
    {
        foreach (['execution_suspended', 'returned_by_approving_body', 'reopened_for_representation', 'executed', 'cancelled', 'under_legal_review'] as $statusCode) {
            Notification::fake();

            $employee = $this->employee();
            $requestRecord = $this->requestFor($employee);

            $this->moveStatus($requestRecord, $statusCode);

            Notification::assertNotSentTo($employee, RequestNoticeNotification::class);
        }
    }

    /**
     * Moment 4 is the one with no status behind it — `place_on_agenda` has no
     * caller, so the notice rides the endpoint that actually inserts the item.
     */
    public function test_agenda_insertion_fires_moment_four_for_a_request_item_and_nothing_for_an_administrative_one(): void
    {
        Notification::fake();

        $employee = $this->employee();
        $requestRecord = $this->requestFor($employee);
        $this->passLegalReview($requestRecord);

        $chair = $this->userWithRole('R03');
        $meeting = $this->meeting($chair);

        $this->actingAs($chair)
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'item_type' => 'employee_request',
                'request_id' => $requestRecord->id,
            ])
            ->assertCreated();

        Notification::assertSentTo(
            $employee,
            RequestNoticeNotification::class,
            fn (RequestNoticeNotification $notice) => $notice->toArray($employee)['moment'] === 'placed_on_agenda',
        );

        Notification::fake();

        $this->actingAs($chair)
            ->postJson("/api/meetings/{$meeting->id}/agenda", [
                'item_type' => 'administrative',
                'subject' => 'بند إداري',
            ])
            ->assertCreated();

        Notification::assertNothingSent();
    }

    /**
     * Art. 102: "لا يتضمن الإشعار … مداولات اللجنة … كيفية تصويت كل عضو".
     *
     * The employee's notice carries no tally, and — the behaviour change this
     * stage makes — the committee's own tally message no longer goes to them
     * at all. النموذج 16's approved wording for عدم الموافقة says only
     * "للأسباب المثبتة في القرار المعتمد", which is exactly what is asserted.
     */
    public function test_the_committees_vote_tally_reaches_the_committee_but_never_the_employee(): void
    {
        Notification::fake();

        $employee = $this->employee();
        $requestRecord = $this->requestFor($employee);
        $member = $this->userWithRole('R04');
        $chair = $this->userWithRole('R03');

        $committee = Committee::create(['name_ar' => 'لجنة', 'name_en' => 'Committee', 'is_active' => true]);
        $committee->members()->create(['user_id' => $member->id, 'is_head' => false]);
        $meeting = $this->meeting($chair, $committee);

        $decision = $this->decisionFor($requestRecord, $meeting, 'reject');

        app(NotificationDispatcher::class)
            ->decisionRecorded($requestRecord, $decision, $meeting, $chair);

        Notification::assertSentTo($member, DecisionRecordedNotification::class);
        Notification::assertNotSentTo($employee, DecisionRecordedNotification::class);

        // What the employee does hear, when the decision lands the file on
        // Art. 38's code 13.
        $this->moveStatus($requestRecord, 'not_approved', $chair);

        Notification::assertSentTo(
            $employee,
            RequestNoticeNotification::class,
            function (RequestNoticeNotification $notice) use ($employee) {
                $body = $notice->toArray($employee)['body_ar'];

                $this->assertStringContainsString('للأسباب المثبتة في القرار المعتمد', $body);
                $this->assertStringNotContainsString('موافقة/رفض', $body);

                return true;
            },
        );
    }

    /**
     * النموذج 16's تأجيل formula prints "(1) … (2) … (3) …" for what must be
     * completed, and Stage 74 already records exactly that on the decision —
     * so the blanks are filled from the record rather than left as dots.
     */
    public function test_the_deferral_notice_carries_the_decisions_own_required_completion(): void
    {
        Notification::fake();

        $employee = $this->employee();
        $requestRecord = $this->requestFor($employee);
        $meeting = $this->meeting($this->userWithRole('R03'));

        $this->decisionFor($requestRecord, $meeting, 'defer', [
            'deferral_reason' => 'نقص مستند',
            'deferral_required_completion' => 'شهادة الخبرة وكشف الخدمة.',
            'deferral_responsible_body' => 'إدارة الموارد البشرية',
        ]);

        $this->moveStatus($requestRecord, 'deferred');

        Notification::assertSentTo(
            $employee,
            RequestNoticeNotification::class,
            function (RequestNoticeNotification $notice) use ($employee) {
                $this->assertStringContainsString(
                    'شهادة الخبرة وكشف الخدمة.',
                    $notice->toArray($employee)['body_ar'],
                );

                return true;
            },
        );
    }

    /**
     * النموذج 16's موافقة formula names the sitting, and carries the Art. 32
     * caution that the result is not yet executable — the one sentence in that
     * formula that must survive.
     */
    public function test_the_referral_notice_names_the_meeting_and_keeps_the_not_yet_final_caution(): void
    {
        Notification::fake();

        $employee = $this->employee();
        $requestRecord = $this->requestFor($employee);
        $meeting = $this->meeting($this->userWithRole('R03'));

        $this->decisionFor($requestRecord, $meeting, 'approve');
        $this->moveStatus($requestRecord, 'awaiting_municipal_approval');

        Notification::assertSentTo(
            $employee,
            RequestNoticeNotification::class,
            function (RequestNoticeNotification $notice) use ($employee, $meeting) {
                $body = $notice->toArray($employee)['body_ar'];

                $this->assertStringContainsString((string) $meeting->meeting_number, $body);
                $this->assertStringContainsString(
                    'لا تعتبر نهائية قابلة للتنفيذ إلا بعد استكمال الاعتماد المطلوب',
                    $body,
                );

                return true;
            },
        );
    }

    /**
     * The two moments where the employee has to act carry the recorded reason
     * (النموذج 04 requires stating what is missing); the rest do not, which is
     * Art. 102's own limit on internal text.
     */
    public function test_only_the_act_on_it_moments_quote_the_recorded_reason(): void
    {
        Notification::fake();

        $employee = $this->employee();
        $requestRecord = $this->requestFor($employee);

        $this->moveStatus($requestRecord, 'incomplete', null, 'ينقص كشف الخدمة.');
        $this->assertStringContainsString('ينقص كشف الخدمة.', $this->lastBody($employee));

        $this->moveStatus($requestRecord, 'outside_jurisdiction', null, 'ملاحظة داخلية لا تخص الموظف.');
        $this->assertStringNotContainsString('ملاحظة داخلية', $this->lastBody($employee));
    }

    /** The notice honours the preferences matrix like every other event. */
    public function test_muting_the_event_silences_every_moment(): void
    {
        Notification::fake();

        $employee = $this->employee();
        NotificationSetting::create([
            'user_id' => $employee->id,
            'event_type' => 'request_notice',
            'in_app' => false,
            'email' => false,
            'sms' => false,
        ]);

        $requestRecord = $this->requestFor($employee);
        $this->moveStatus($requestRecord, 'final_approved');

        // NotificationFake skips a notifiable that resolves to no channels,
        // so a fully-muted event is simply absent — the same assertion shape
        // NotificationTest already uses for a muted `stage_changed`.
        Notification::assertNotSentTo($employee, RequestNoticeNotification::class);
    }

    /**
     * End to end without a fake: the notice really lands, and the request's own
     * screen can read Art. 101's register back off it.
     */
    public function test_the_delivered_notices_reach_the_request_detail_screen(): void
    {
        $employee = $this->employee();
        $requestRecord = $this->requestFor($employee);

        $this->moveStatus($requestRecord, 'registered');
        $this->moveStatus($requestRecord, 'in_execution');

        $response = $this->actingAs($employee)
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        $response->assertJsonCount(2, 'data.employee_notices');
        $response->assertJsonPath('data.employee_notices.0.moment', 'received');
        $response->assertJsonPath('data.employee_notices.0.moment_number', 1);
        $response->assertJsonPath('data.employee_notices.1.moment', 'execution_started');
        $response->assertJsonPath('data.employee_notices.1.moment_number', 11);
    }

    // --- fixtures ---------------------------------------------------------

    private function moveStatus(Request $requestRecord, string $toCode, ?User $actor = null, ?string $reason = null): void
    {
        $requestRecord->refresh();

        $to = RequestStatus::where('code', $toCode)->value('id');

        RequestStatusHistory::create([
            'request_id' => $requestRecord->id,
            'from_status_id' => $requestRecord->status_id,
            'to_status_id' => $to,
            'reason' => $reason,
            'changed_by_user_id' => ($actor ?? $this->userWithRole('R02'))->id,
            'changed_at' => now(),
        ]);

        $requestRecord->forceFill(['status_id' => $to])->save();
    }

    private function lastMoment(User $employee): ?string
    {
        return $this->lastNotice($employee)?->toArray($employee)['moment'] ?? null;
    }

    private function lastBody(User $employee): string
    {
        return $this->lastNotice($employee)?->toArray($employee)['body_ar'] ?? '';
    }

    private function lastNotice(User $employee): ?RequestNoticeNotification
    {
        return Notification::sent($employee, RequestNoticeNotification::class)->last();
    }

    private function employee(): User
    {
        return $this->userWithRole('R01');
    }

    private function requestFor(User $creator): Request
    {
        return Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numerify('####'),
            'title' => 'اختبار إشعارات المادة 101',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'new')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
            'decision_grade' => 10,
        ]);
    }

    private function meeting(User $creator, ?Committee $committee = null): Meeting
    {
        $committee ??= Committee::create([
            'name_ar' => 'لجنة شؤون الموظفين',
            'name_en' => 'Employee Affairs Committee',
            'is_active' => true,
        ]);

        return Meeting::create([
            'committee_id' => $committee->id,
            'meeting_number' => 'PM-MTG/2026/'.fake()->unique()->numerify('##'),
            'title' => 'اجتماع اختباري',
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
            'created_by_user_id' => $creator->id,
        ]);
    }

    /**
     * A recorded decision on this request, built directly rather than through
     * the voting endpoints — this test is about the notice, not about Stage
     * 21's tally, and DecisionStructureRules is exercised in its own file.
     *
     * @param  array<string, mixed>  $extra
     */
    private function decisionFor(Request $requestRecord, Meeting $meeting, string $outcome, array $extra = []): Decision
    {
        $item = $meeting->agendaItems()->create([
            'request_id' => $requestRecord->id,
            'item_type' => 'employee_request',
            'agenda_order' => ($meeting->agendaItems()->max('agenda_order') ?? 0) + 1,
        ]);

        return $item->decision()->create([
            'outcome' => $outcome,
            'instrument' => 'decision',
            'decision_subject' => 'موضوع القرار',
            'decision_operative' => 'منطوق القرار مع إجراء محدد وواضح.',
            'decided_by_user_id' => $meeting->created_by_user_id,
            'decided_at' => now(),
            ...$extra,
        ]);
    }

    /** Stage 68's agenda gate needs a permitting verdict before an item may be inserted. */
    private function passLegalReview(Request $requestRecord): void
    {
        $requestRecord->legalReviews()->create([
            'verdict' => 'sound_ready',
            'committee_mandate' => 'decision',
            'requires_central_approval' => 'no',
            'reviewed_by_user_id' => $this->userWithRole('R11')->id,
            'reviewed_at' => now(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
