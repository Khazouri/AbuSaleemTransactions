<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\HoldsPaperMeetings;
use Tests\PassesControlGates;
use Tests\SitsOnCommittee;
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
    use HoldsPaperMeetings;
    use PassesControlGates;
    use RefreshDatabase;
    use SitsOnCommittee;

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
                'entitled_grade' => 9,
                'grade_date' => '2024-03-01',
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
        // then forward again through the same registration hop. Stage 102 —
        // the approve lands on the committee stage, so the way back starts
        // with the committee's own return_to_study.
        $service->transition($requestRecord, 'return_to_study', $this->userWithRole('R03'), 'ناقص');
        $service->transition($requestRecord, 'return_missing_docs', $reviewer, 'مستند مفقود');
        $requestRecord->refresh();

        $service->transition($requestRecord, 'submit', $employee);
        $service->transition($requestRecord, 'forward', $manager);
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
        // Stage 102 — the مقرر schedules, the five seats must be filled, and
        // the two sittings fall in different months (one a month).
        $rapporteur = $this->userWithRole('R02');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $this->fillFiveSeats($committee, ['rapporteur' => $rapporteur]);

        foreach ([1, 2] as $sequence) {
            $this->actingAs($rapporteur, 'sanctum')
                ->postJson('/api/meetings', [
                    'committee_id' => $committee->id,
                    'meeting_number' => 'رقم يكتبه المستخدم',
                    'title' => "اجتماع {$sequence}",
                    'scheduled_at' => now()->startOfMonth()->addMonths($sequence)->addDays(3)->toDateTimeString(),
                ])
                ->assertCreated()
                ->assertJsonPath('data.meeting_number', 'PM-MTG/'.now()->format('Y').'/'.sprintf('%02d', $sequence));
        }
    }

    /**
     * Art. 89 — every file the committee rules on gets its own رقم القرار.
     * Since 2026-10-18 the paper sitting's results mint it, and Appendix 12's
     * register shows the same number.
     */
    public function test_a_decision_is_numbered_and_that_number_reaches_the_register(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        [$rapporteur, $meeting, [$agendaItem]] = $this->paperMeeting();

        $this->recordResults($rapporteur, $meeting, [
            $agendaItem->id => ['recommendation' => 'approve', 'approving_authority' => 'dean'],
        ])->assertOk();

        $expected = 'PM-DEC/'.now()->format('Y').'/001';
        $this->assertSame($expected, Decision::firstOrFail()->decision_number);

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
            'entitled_grade' => 9,
            'grade_date' => '2024-03-01',
            'jurisdiction_test' => $this->jurisdictionAnswers(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'department_id' => Department::where('code', 'ADM')->value('id')]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
