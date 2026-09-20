<?php

namespace Tests\Feature;

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
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\RecordsStructuredDecisions;
use Tests\RunsStudySequence;
use Tests\TestCase;

/**
 * [D] Appendix 15's unified numbering, and the قيد: the moment a request
 * stops being an intake receipt and becomes a numbered committee file.
 *
 * The two halves are tested together because they are one rule: a number
 * granted at the wrong moment is exactly what Art. 15 ("ولا يعد مجرد تقديم
 * الطلب إلى الرئيس المباشر قيدًا") forbids, and Art. 20 defines the right one:
 * المقرر's act, after completeness — the `requirements_check → approve` hop.
 * (Stage 97 restored it there after the 2026-09-18 move to `register`.)
 */
class UnifiedNumberingTest extends TestCase
{
    use PassesControlGates;
    use RecordsStructuredDecisions;
    use RefreshDatabase;
    use RunsStudySequence;

    /**
     * Art. 15 — intake produces a receipt and NO reference; the reference
     * appears on the hop that establishes completeness, in Appendix 15's own
     * shape, and does not change afterwards.
     */
    public function test_the_reference_is_granted_at_the_completeness_check_not_at_intake(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        $employee = $this->userWithRole('R01');
        $manager = $this->userWithRole('R02');
        $employee->manager_id = $manager->id;
        $employee->save();

        $created = $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests', [
                'title' => 'طلب ترقية',
                'department_id' => Department::where('code', 'ADM')->value('id'),
                'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
                'decision_grade' => 9,
                // Stage 85 — Appendix 57's mandatory rows became a submission
                // rule; this test's subject is the numbering, so it clears
                // that rule rather than restating it.
                'attachments' => $this->mandatoryAttachments(RequestType::where('code', 'PROM')->firstOrFail()),
            ])
            ->assertCreated()
            ->assertJsonPath('data.reference_number', null)
            ->assertJsonPath('data.intake_receipt_number', 'PM-RCV/'.now()->format('Y').'/000001');

        $requestRecord = Request::findOrFail($created->json('data.id'));

        // Walk the pre-قيد half. Nothing along it may mint a reference: every
        // one of these stages is what Art. 15 calls "not a قيد".
        $service = app(WorkflowService::class);
        $service->transition($requestRecord, 'forward', $manager);
        $this->assertNull($requestRecord->refresh()->reference_number);

        $service->transition($requestRecord, 'route_to_hr', $manager);
        $this->assertNull($requestRecord->refresh()->reference_number);

        // Stage 87 — the HR registrar is R12, not R05.
        $service->transition($requestRecord, 'register', $this->userWithRole('R12'));
        $requestRecord->refresh();
        $this->assertNull($requestRecord->reference_number);
        // Art. 38 code 04 (تحت فحص الاكتمال): delivered to be checked, not yet
        // checked — the receiving body's acceptance is not the قيد.
        $this->assertSame('in_review', $requestRecord->status->code);
        $this->assertSame('requirements_check', $requestRecord->currentStage->code);

        $requestRecord->jurisdiction_test = $this->jurisdictionAnswers();
        $requestRecord->save();

        // Stage 97 — THE قيد is المقرر's act, after completeness (Art. 20,
        // Appendix 6 row 5). Art. 38's code 06 — "اكتملت المتطلبات ومنحت رقمًا
        // مرجعيًا" is one event, so the status and the number arrive together.
        $service->transition($requestRecord, 'approve', $this->userWithRole('R02'));
        $requestRecord->refresh();
        $this->assertSame('registered', $requestRecord->status->code);
        $this->assertSame('PM-COM/'.now()->format('Y').'/0001', $requestRecord->reference_number);
        // The receipt survives the قيد: it is the employee's own record of a
        // submission that really did happen before registration.
        $this->assertSame('PM-RCV/'.now()->format('Y').'/000001', $requestRecord->intake_receipt_number);
        $this->assertSame(1, Request::whereNotNull('reference_number')->count());
    }

    /**
     * Art. 99 / النموذج 05 — "ولا يجوز منح أكثر من رقم أساسي لنفس المعاملة
     * لمجرد انتقالها بين مراحل العمل". A file returned for missing documents
     * re-walks the whole registration chain, so this is the path that would
     * mint a second number if the allocation were unconditional.
     */
    public function test_a_returned_file_keeps_its_first_reference_when_it_comes_back(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $service = app(WorkflowService::class);

        // The return loop re-walks the two manager-gated hops, and only the
        // submitter's own manager may use those — there is no admin
        // override — so this fixture needs a real creator with a real
        // manager. The test's subject is number stability, not the gate.
        $manager = User::factory()->create(['is_active' => true]);
        $employee = $this->userWithRole('R01');
        $employee->manager_id = $manager->id;
        $employee->save();

        $requestRecord = $this->requestAt('requirements_check', 'in_review');
        $requestRecord->created_by_user_id = $employee->id;
        $requestRecord->save();

        $service->transition($requestRecord, 'approve', $reviewer);
        $first = $requestRecord->refresh()->reference_number;
        $this->assertSame('PM-COM/'.now()->format('Y').'/0001', $first);

        // Back for missing documents, all the way to the front of the chain,
        // then forward again through the same registration hop.
        $service->transition($requestRecord, 'reject_review', $reviewer, 'ناقص');
        $service->transition($requestRecord, 'return_missing_docs', $reviewer, 'مستند مفقود');
        $requestRecord->refresh();

        $service->transition($requestRecord, 'submit', $employee);
        $service->transition($requestRecord, 'forward', $manager);
        $service->transition($requestRecord, 'route_to_hr', $manager);
        $service->transition($requestRecord, 'register', $this->userWithRole('R12'));
        $service->transition($requestRecord, 'approve', $reviewer);

        $requestRecord->refresh();
        $this->assertSame('registered', $requestRecord->status->code);
        $this->assertSame($first, $requestRecord->reference_number);
        $this->assertSame(1, Request::whereNotNull('reference_number')->count());
    }

    /** Appendix 15's request series increments within the year, globally. */
    public function test_the_reference_series_increments_across_requests(): void
    {
        $this->seed(DatabaseSeeder::class);
        $reviewer = $this->userWithRole('R02');
        $service = app(WorkflowService::class);

        foreach ([1, 2, 3] as $sequence) {
            $requestRecord = $this->requestAt('requirements_check', 'in_review');
            $service->transition($requestRecord, 'approve', $reviewer);

            $this->assertSame(
                'PM-COM/'.now()->format('Y').'/'.sprintf('%04d', $sequence),
                $requestRecord->refresh()->reference_number,
            );
        }
    }

    /**
     * Appendix 15's PM-MTG series, minted by the server. Appendix 8 refuses a
     * محضر whose "رقم الاجتماع" does not match, which a value a human retypes
     * per meeting cannot guarantee.
     */
    public function test_meeting_numbers_are_server_minted_and_ignore_client_input(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);

        foreach ([1, 2] as $sequence) {
            $this->actingAs($head, 'sanctum')
                ->postJson('/api/meetings', [
                    'committee_id' => $committee->id,
                    'meeting_number' => 'رقم يكتبه المستخدم',
                    'title' => "اجتماع {$sequence}",
                    'scheduled_at' => now()->addDays($sequence)->toDateTimeString(),
                ])
                ->assertCreated()
                ->assertJsonPath('data.meeting_number', 'PM-MTG/'.now()->format('Y').'/'.sprintf('%02d', $sequence));
        }
    }

    /**
     * Appendix 15's PM-MIN series. The number identifies the document, not one
     * compilation of it, so regenerating a draft must not consume a new one.
     */
    public function test_minutes_are_numbered_once_and_keep_that_number_across_regenerates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');
        $meeting = $this->meetingFor($head);

        $first = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk()
            ->assertJsonPath('data.minutes_number', 'PM-MIN/'.now()->format('Y').'/01')
            ->json('data.minutes_number');

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk()
            ->assertJsonPath('data.minutes_number', $first);
    }

    /**
     * Art. 89 — "يخصص داخل المحضر لكل معاملة قرار أو نتيجة مستقلة" whose first
     * element is رقم القرار, so the number has to be inside the compiled
     * محضر, not only on the live decisions row. Appendix 12's register wants
     * the same number, which is why it also reaches the export.
     */
    public function test_a_decision_is_numbered_and_that_number_reaches_the_minutes_and_the_register(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');
        $meeting = $this->meetingFor($head, $member);
        $agendaItem = $this->agendaItemOn($meeting);

        foreach ([$head, $member] as $voter) {
            $this->actingAs($voter, 'sanctum')
                ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
                ->assertCreated();
        }

        $expected = 'PM-DEC/'.now()->format('Y').'/001';

        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", $this->decisionPayload('approve'), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.decision_number', $expected);

        $this->assertSame($expected, Decision::firstOrFail()->decision_number);

        $minutes = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk()
            ->json('data.content.agenda_items.0.decision.decision_number');
        $this->assertSame($expected, $minutes);

        $register = $this->actingAs($this->userWithRole('R08'), 'sanctum')
            ->getJson('/api/decisions')
            ->assertOk();
        $this->assertSame($expected, $register->json('data.0.decision_number'));
    }

    private function jurisdictionAnswers(): array
    {
        return [
            'has_legal_basis' => true,
            'employee_covered' => true,
            'within_municipal_jurisdiction' => true,
            'committee_decides' => true,
            'final_approval_authority' => 'عميد البلدية',
            'requires_central_approval' => false,
        ];
    }

    private function requestAt(string $stageCode, string $statusCode): Request
    {
        return Request::create([
            'title' => 'طلب اختبار الترقيم',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'submitted_at' => now(),
            'decision_grade' => 9,
            'jurisdiction_test' => $this->jurisdictionAnswers(),
        ]);
    }

    private function meetingFor(User $head, ?User $member = null): Meeting
    {
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        if ($member !== null) {
            $committee->members()->create(['user_id' => $member->id]);
        }

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اختبار الترقيم',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);

        foreach ([$head, $member] as $attendee) {
            if ($attendee !== null) {
                $meeting->attendees()->create(['user_id' => $attendee->id, 'attended' => true]);
            }
        }

        return $meeting;
    }

    private function agendaItemOn(Meeting $meeting): MeetingRequest
    {
        $requestRecord = $this->requestAt('receive_from_committee', 'ready');
        $requestRecord->reference_number = 'PM-COM/'.now()->format('Y').'/9999';
        $requestRecord->save();

        // Stage 68's agenda gate needs a passing legal review on file.
        RequestLegalReview::create([
            'request_id' => $requestRecord->id,
            'verdict' => 'sound_ready',
            'reviewed_by_user_id' => $this->userWithRole('R11')->id,
            'reviewed_at' => now(),
        ]);

        $agendaItem = MeetingRequest::create([
            'meeting_id' => $meeting->id,
            'request_id' => $requestRecord->id,
            'item_type' => 'employee_request',
            'agenda_order' => 1,
        ]);
        // Stage 82 — [D] Art. 85's study sequence now gates voting; see
        // Tests\RunsStudySequence for why it is written directly here.
        $this->completeStudySequence($agendaItem);

        return $agendaItem;
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
