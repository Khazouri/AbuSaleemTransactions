<?php

namespace Tests\Feature;

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
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\TestCase;

/** Stage 83 — [D] Appendix 16's سياسة عدم ازدواجية المعاملات. */
class DuplicateRequestTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    /**
     * Stage 85 — every submission below now carries real uploads, because
     * Appendix 57's mandatory rows became a submission rule. Faked once here
     * rather than per test so store() never writes into the real local disk.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_an_open_file_on_the_same_subject_refuses_a_second_request(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->employee();

        $this->submit($employee)->assertCreated();

        $this->submit($employee)
            ->assertStatus(422)
            ->assertJsonValidationErrors('request_type_id');

        // The appendix's own consequence: one file, not two.
        $this->assertSame(1, Request::where('created_by_user_id', $employee->id)->count());
    }

    public function test_a_different_subject_is_never_a_duplicate(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->employee();

        $this->submit($employee)->assertCreated();
        $this->submit($employee, 'LEAV')->assertCreated();

        $this->assertSame(2, Request::where('created_by_user_id', $employee->id)->count());
    }

    public function test_another_employees_open_file_is_not_a_duplicate(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->submit($this->employee())->assertCreated();
        // The appendix searches "برقم الموظف وموضوع المعاملة" — the subject
        // alone is not the match.
        $this->submit($this->employee())->assertCreated();
    }

    public function test_a_closed_prior_file_requires_the_new_request_to_be_classified(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->employee();

        $this->closePriorRequest($employee);

        $this->submit($employee)
            ->assertStatus(422)
            ->assertJsonValidationErrors('request_type_id');

        $this->submit($employee, 'PROM', ['prior_relation' => 'new_incident'])
            ->assertCreated()
            ->assertJsonPath('data.responsibility.responsible.code', 'direct_manager');

        $created = Request::where('created_by_user_id', $employee->id)
            ->whereNotNull('prior_relation')
            ->firstOrFail();

        $this->assertSame('new_incident', $created->prior_relation);
        $this->assertNotNull($created->prior_request_id);
    }

    /**
     * The appendix's "ثم يصنف وفق طبيعته الصحيحة" — for two of its four
     * classifications the correct nature is not a new request at all.
     */
    public function test_an_appeal_or_a_re_presentation_is_refused_and_routed_elsewhere(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->employee();
        $this->closePriorRequest($employee);

        foreach (['appeal', 're_presentation'] as $relation) {
            $this->submit($employee, 'PROM', ['prior_relation' => $relation])
                ->assertStatus(422)
                ->assertJsonValidationErrors('request_type_id');
        }

        $this->submit($employee, 'PROM', ['prior_relation' => 'completion_of_previous'])->assertCreated();
    }

    public function test_the_duplicate_check_endpoint_reports_the_open_file_before_submission(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->employee();
        $this->submit($employee)->assertCreated();

        $typeId = RequestType::where('code', 'PROM')->value('id');

        $response = $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/duplicate-check?request_type_id={$typeId}")
            ->assertOk();

        $this->assertNotNull($response->json('data.open_prior'));
        $this->assertCount(1, $response->json('data.prior_requests'));
        $this->assertFalse($response->json('data.prior_requests.0.concluded'));

        // The screen greys out the two the appendix routes elsewhere rather
        // than offering an answer the server would refuse.
        $redirected = collect($response->json('data.relations'))->where('redirected', true)->pluck('code');
        $this->assertEqualsCanonicalizing(['appeal', 're_presentation'], $redirected->all());
    }

    /**
     * A file the committee has already decided is not an open file, even while
     * its execution or approval is still running: the subject was heard, so a
     * new request on it is a fresh matter and Appendix 16's classification
     * applies instead of the open-file refusal.
     */
    public function test_a_file_the_committee_decided_is_not_an_open_duplicate(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->employee();

        foreach (['approve', 'reject'] as $outcome) {
            Request::query()->delete();
            $prior = $this->decidePriorRequest($employee, $outcome, 'in_execution');

            $this->submit($employee)
                ->assertStatus(422)
                ->assertJsonMissing(['يوجد ملف مفتوح لنفس الموضوع ('.$prior->trackingNumber().
                    ')؛ لا تنشأ معاملة جديدة، بل تلحق المستندات بالمعاملة القائمة.']);

            $this->submit($employee, 'PROM', ['prior_relation' => 'new_incident'])->assertCreated();
        }
    }

    /** A deferral, or a decided file back before the committee, is still open. */
    public function test_a_deferred_or_reopened_file_still_refuses_a_second_request(): void
    {
        $this->seed(DatabaseSeeder::class);
        $employee = $this->employee();

        foreach ([['defer', 'deferred'], ['approve', 'reopened_by_appeal']] as [$outcome, $status]) {
            Request::query()->delete();
            $this->decidePriorRequest($employee, $outcome, $status);

            $this->submit($employee, 'PROM', ['prior_relation' => 'new_incident'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('request_type_id');
        }
    }

    // --- fixtures ----------------------------------------------------------

    private function employee(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', 'R01')->value('id'));

        return $user;
    }

    private function submit(User $actor, string $typeCode = 'PROM', array $extra = [])
    {
        $type = RequestType::where('code', $typeCode)->firstOrFail();

        return $this->actingAs($actor, 'sanctum')->post('/api/requests', [
            'title' => 'طلب ترقية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => $type->id,
            'decision_grade' => 9,
            // Stage 85 — this test's subject is Appendix 16's duplicate rule,
            // so the file has to clear Appendix 57's own rule first to reach it.
            'attachments' => $this->mandatoryAttachments($type),
            ...$extra,
        ], ['Accept' => 'application/json']);
    }

    /** A previously-filed request on the same subject that has since concluded. */
    private function closePriorRequest(User $employee): void
    {
        $this->submit($employee)->assertCreated();

        Request::where('created_by_user_id', $employee->id)->update([
            'status_id' => RequestStatus::where('code', 'completed_closed')->value('id'),
        ]);
    }

    /** A prior file on the same subject carrying a committee decision. */
    private function decidePriorRequest(User $employee, string $outcome, string $statusCode): Request
    {
        $this->submit($employee)->assertCreated();
        $prior = Request::where('created_by_user_id', $employee->id)->latest('id')->firstOrFail();
        $prior->update(['status_id' => RequestStatus::where('code', $statusCode)->value('id')]);

        $meeting = Meeting::create([
            'committee_id' => Committee::create(['name_ar' => 'لجنة'])->id,
            'title' => 'اجتماع',
            'scheduled_at' => now()->subWeek(),
            'created_by_user_id' => $employee->id,
        ]);
        Decision::create([
            'meeting_request_id' => MeetingRequest::create([
                'meeting_id' => $meeting->id,
                'request_id' => $prior->id,
                'agenda_order' => 1,
            ])->id,
            'outcome' => $outcome,
            'decided_by_user_id' => $employee->id,
            'decided_at' => now()->subWeek(),
        ]);

        return $prior;
    }
}
