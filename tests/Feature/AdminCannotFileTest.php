<?php

namespace Tests\Feature;

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
use Tests\TestCase;

/**
 * The system admin files nothing and submits nothing (user decision
 * 2026-10-07), even though every account also holds R01.
 */
class AdminCannotFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_an_admin_holding_r01_too_cannot_reach_intake(): void
    {
        $admin = $this->userWithRoles(['R01', 'R08']);

        $this->assertSame(
            [],
            array_keys(array_filter($admin->screenPermissions()['request_intake'])),
        );

        $this->actingAs($admin, 'sanctum');
        $this->postJson('/api/requests', [])->assertForbidden();
        $this->getJson('/api/requests/intake-options')->assertForbidden();
        $this->getJson('/api/requests/drafts')->assertForbidden();
    }

    public function test_an_admin_cannot_submit_an_employees_returned_request(): void
    {
        $filer = $this->userWithRoles(['R01']);
        $admin = $this->userWithRoles(['R01', 'R08']);
        $requestRecord = Request::create([
            'title' => 'طلب مُعاد',
            'department_id' => $filer->department_id,
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'returned')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'created_by_user_id' => $filer->id,
            'submitted_at' => now(),
        ]);

        $this->assertFalse(app(WorkflowService::class)->availableActions($requestRecord, $admin)->contains('submit'));
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
