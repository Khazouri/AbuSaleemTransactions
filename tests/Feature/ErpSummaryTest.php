<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The ERP reads one employee's state here with that employee's own ERP access
 * token, so it can never read anyone else's, and sees no more than the SPA
 * would show the same account.
 */
class ErpSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        config(['services.erp' => [
            'authorize_url' => 'https://erp.test/connect/authorize',
            'token_url' => 'https://erp.test/connect/token',
            'userinfo_url' => 'https://erp.test/connect/userinfo',
            'client_id' => 'abusaleem',
            'client_secret' => 'top-secret',
            'redirect_uri' => 'https://spa.test/erp-callback',
            'session_hours' => 12,
            'timeout' => 10,
        ]]);
    }

    private function fakeUserinfo(int $sub = 42, int $status = 200): void
    {
        Http::fake(['erp.test/connect/userinfo' => Http::response(['sub' => (string) $sub], $status)]);
    }

    private function summary(?string $token = 'employee-erp-token'): TestResponse
    {
        return $this->withHeaders($token === null ? [] : ['Authorization' => "Bearer {$token}"])
            ->getJson('/api/erp/summary');
    }

    private function userWithRole(string $code, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->roles()->attach(Role::where('code', $code)->value('id'));

        return $user;
    }

    private function requestFor(User $creator, string $statusCode = 'new'): Request
    {
        return Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numerify('####'),
            'title' => 'طلب',
            'description' => 'وصف الطلب.',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
        ]);
    }

    public function test_a_linked_employee_gets_only_their_own_summary(): void
    {
        $this->fakeUserinfo(42);
        $employee = $this->userWithRole('R01', ['erp_user_id' => 42]);
        $colleague = $this->userWithRole('R01');

        $mine = $this->requestFor($employee);
        $this->requestFor($employee, 'completed_closed');
        $this->requestFor($colleague);

        foreach ([null, now()] as $readAt) {
            $employee->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'data' => ['title_ar' => 'إشعار'],
                'read_at' => $readAt,
            ]);
        }

        $response = $this->summary()
            ->assertOk()
            ->assertJsonPath('data.user.id', $employee->id)
            ->assertJsonPath('data.requests.open', 1)
            ->assertJsonCount(2, 'data.requests.recent')
            ->assertJsonPath('data.notifications.unread', 1)
            ->assertJsonCount(2, 'data.notifications.recent')
            ->assertJsonStructure(['data' => ['tasks' => ['sources', 'total', 'truncated']]]);

        $this->assertContains($mine->id, array_column($response->json('data.requests.recent'), 'id'));

        // The forwarded token is the one asked about, so the ERP can only ever
        // read the employee who is signed in to it.
        Http::assertSent(fn (ClientRequest $sent) => $sent->hasHeader('Authorization', 'Bearer employee-erp-token'));
    }

    public function test_a_section_the_account_cannot_view_is_left_out(): void
    {
        $this->fakeUserinfo(42);
        $this->userWithRole('R01', ['erp_user_id' => 42]);

        // An employee with no committee seat has no meetings screen in the SPA.
        $this->summary()->assertOk()->assertJsonMissingPath('data.meetings');
    }

    public function test_the_meetings_section_is_there_for_an_account_that_has_the_screen(): void
    {
        $this->fakeUserinfo(42);
        $this->userWithRole('R02', ['erp_user_id' => 42]);

        $this->summary()->assertOk()->assertJsonPath('data.meetings.upcoming', 0);
    }

    public function test_a_missing_or_refused_token_is_401(): void
    {
        $this->fakeUserinfo(42, 401);
        $this->userWithRole('R01', ['erp_user_id' => 42]);

        $this->summary(null)->assertUnauthorized();
        $this->summary()->assertUnauthorized();
    }

    public function test_an_unlinked_erp_user_is_404(): void
    {
        $this->fakeUserinfo(99);

        $this->summary()->assertNotFound();
    }

    public function test_an_inactive_account_and_an_admin_are_refused(): void
    {
        $this->fakeUserinfo(42);
        $this->userWithRole('R01', ['erp_user_id' => 42, 'is_active' => false]);
        $this->summary()->assertForbidden();

        $this->fakeUserinfo(43);
        $this->userWithRole('R08', ['erp_user_id' => 43]);
        $this->summary()->assertForbidden();
    }

    public function test_the_route_is_absent_until_the_erp_is_configured(): void
    {
        config(['services.erp.userinfo_url' => null]);

        $this->summary()->assertNotFound();
    }

    public function test_an_unreachable_erp_is_503(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->summary()->assertServiceUnavailable();
    }
}
