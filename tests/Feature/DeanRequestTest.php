<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\ActionRequiredNotification;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * العميد's own request skips the direct-manager step and goes straight to HR
 * (user decision 2026-10-07).
 */
class DeanRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_deans_request_goes_straight_to_hr_at_intake(): void
    {
        Notification::fake();
        $manager = $this->userWithRoles(['R01']);
        $dean = $this->userWithRoles(['R01', 'R07']);
        $dean->update(['manager_id' => $manager->id]);

        $this->actingAs($dean, 'sanctum')
            ->postJson('/api/requests', [
                'title' => 'طلب العميد',
                'request_type_id' => RequestType::where('code', 'ALLW')->value('id'),
                'decision_grade' => 9,
            ])
            ->assertCreated()
            ->assertJsonPath('data.current_stage.code', 'receive_and_register')
            ->assertJsonPath('data.status.code', 'routed_to_hr');

        // A manager set on the dean's account is never asked to act on it.
        Notification::assertNotSentTo($manager, ActionRequiredNotification::class);
    }

    public function test_the_deans_returned_request_goes_straight_back_to_hr(): void
    {
        $dean = $this->userWithRoles(['R01', 'R07']);
        $requestRecord = Request::create([
            'title' => 'طلب مُعاد',
            'department_id' => $dean->department_id,
            'request_type_id' => RequestType::where('code', 'ALLW')->value('id'),
            'status_id' => RequestStatus::where('code', 'returned')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'created_by_user_id' => $dean->id,
            'submitted_at' => now(),
        ]);

        $moved = app(WorkflowService::class)->transition($requestRecord, 'submit', $dean);

        $this->assertSame('receive_and_register', $moved->currentStage->code);
        $this->assertSame('routed_to_hr', $moved->status->code);
    }

    public function test_an_ordinary_employees_request_still_waits_for_the_manager(): void
    {
        $employee = $this->userWithRoles(['R01']);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/requests', [
                'title' => 'طلب موظف',
                'request_type_id' => RequestType::where('code', 'ALLW')->value('id'),
                'decision_grade' => 9,
            ])
            ->assertCreated()
            ->assertJsonPath('data.current_stage.code', 'direct_manager_review');
    }

    /** @param  list<string>  $roleCodes */
    private function userWithRoles(array $roleCodes): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::whereIn('code', $roleCodes)->pluck('id'));

        return $user;
    }
}
