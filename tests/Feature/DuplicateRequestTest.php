<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stage 83 — [D] Appendix 16's سياسة عدم ازدواجية المعاملات. */
class DuplicateRequestTest extends TestCase
{
    use RefreshDatabase;

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

    // --- fixtures ----------------------------------------------------------

    private function employee(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', 'R01')->value('id'));

        return $user;
    }

    private function submit(User $actor, string $typeCode = 'PROM', array $extra = [])
    {
        return $this->actingAs($actor, 'sanctum')->postJson('/api/requests', [
            'title' => 'طلب ترقية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', $typeCode)->value('id'),
            'decision_grade' => 9,
            ...$extra,
        ]);
    }

    /** A previously-filed request on the same subject that has since concluded. */
    private function closePriorRequest(User $employee): void
    {
        $this->submit($employee)->assertCreated();

        Request::where('created_by_user_id', $employee->id)->update([
            'status_id' => RequestStatus::where('code', 'completed_closed')->value('id'),
        ]);
    }
}
