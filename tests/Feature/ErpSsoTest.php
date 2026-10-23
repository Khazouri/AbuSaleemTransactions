<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Single sign-on from the EmployeePlus ERP: an OAuth 2.0 authorization-code
 * flow with PKCE, the ERP as the authorization server. Only a single-use code
 * crosses the browser; we redeem it server to server and keep nothing of the
 * ERP's but its user id.
 */
class ErpSsoTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk-padding';

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

    private function fakeErp(int|string|null $sub = 42): void
    {
        Http::fake([
            'erp.test/connect/token' => Http::response(['access_token' => 'erp-access-token', 'token_type' => 'Bearer']),
            'erp.test/connect/userinfo' => Http::response($sub === null ? [] : ['sub' => (string) $sub]),
        ]);
    }

    private function userWithRole(string $code, array $attributes = []): User
    {
        $user = User::factory()->create(['is_active' => true, ...$attributes]);
        $user->roles()->attach(Role::where('code', $code)->value('id'));

        return $user;
    }

    private function redeem(): TestResponse
    {
        return $this->postJson('/api/auth/erp/callback', ['code' => 'one-time-code', 'code_verifier' => self::VERIFIER]);
    }

    public function test_config_is_absent_until_every_erp_value_is_set(): void
    {
        $this->getJson('/api/auth/erp/config')
            ->assertOk()
            ->assertExactJson([
                'authorize_url' => 'https://erp.test/connect/authorize',
                'client_id' => 'abusaleem',
                'redirect_uri' => 'https://spa.test/erp-callback',
            ]);

        config(['services.erp.client_secret' => null]);
        $this->getJson('/api/auth/erp/config')->assertNotFound();
        $this->redeem()->assertNotFound();
    }

    public function test_a_linked_employee_signs_in_with_an_expiring_token(): void
    {
        $this->fakeErp(42);
        $employee = $this->userWithRole('R01', ['erp_user_id' => 42]);

        $token = $this->redeem()
            ->assertOk()
            ->assertJsonPath('user.id', $employee->id)
            ->json('token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $employee->id);

        $stored = $employee->tokens()->sole();
        $this->assertSame('erp', $stored->name);
        $this->assertEqualsWithDelta(now()->addHours(12)->timestamp, $stored->expires_at->timestamp, 5);

        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://erp.test/connect/token'
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === 'one-time-code'
            && $r['code_verifier'] === self::VERIFIER
            && $r['redirect_uri'] === 'https://spa.test/erp-callback'
            && $r['client_id'] === 'abusaleem'
            && $r['client_secret'] === 'top-secret');
        Http::assertSent(fn (ClientRequest $r) => $r->url() === 'https://erp.test/connect/userinfo'
            && $r->hasHeader('Authorization', 'Bearer erp-access-token'));

        // The ERP's access token has payroll-level power over the ERP; it is
        // used once and never kept.
        $this->assertSame(0, DB::table('personal_access_tokens')->where('token', 'like', '%erp-access-token%')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('new_values', 'like', '%erp-access-token%')->count());
    }

    public function test_an_expired_erp_token_is_refused(): void
    {
        $this->fakeErp(42);
        $this->userWithRole('R01', ['erp_user_id' => 42]);
        $token = $this->redeem()->json('token');

        $this->travel(13)->hours();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_an_unlinked_erp_account_is_refused_with_its_id(): void
    {
        $this->fakeErp(77);

        $this->redeem()->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertStringContainsString('77', $this->redeem()->json('errors.code.0'));
    }

    public function test_an_inactive_account_is_refused(): void
    {
        $this->fakeErp(42);
        $this->userWithRole('R01', ['erp_user_id' => 42, 'is_active' => false]);

        $this->redeem()->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_system_admin_never_signs_in_through_the_erp(): void
    {
        $this->fakeErp(42);
        $admin = $this->userWithRole('R08', ['erp_user_id' => 42]);

        $this->redeem()->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_an_erp_failure_fails_closed(): void
    {
        $this->userWithRole('R01', ['erp_user_id' => 42]);

        Http::fake(['erp.test/connect/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->redeem()->assertStatus(422)->assertJsonValidationErrors('code');

        Http::fake(['erp.test/connect/token' => Http::response('boom', 500)]);
        $this->redeem()->assertStatus(422)->assertJsonValidationErrors('code');

        Http::fake(['erp.test/*' => fn () => throw new ConnectionException('timed out')]);
        $this->redeem()->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_userinfo_without_a_numeric_sub_fails_closed(): void
    {
        $this->userWithRole('R01', ['erp_user_id' => 42]);

        $this->fakeErp(null);
        $this->redeem()->assertStatus(422)->assertJsonValidationErrors('code');

        Http::fake([
            'erp.test/connect/token' => Http::response(['access_token' => 'x']),
            'erp.test/connect/userinfo' => Http::response(['sub' => '42abc']),
        ]);
        $this->redeem()->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_the_callback_requires_a_pkce_verifier(): void
    {
        $this->postJson('/api/auth/erp/callback', ['code' => 'one-time-code', 'code_verifier' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code_verifier');
        Http::assertNothingSent();
    }

    public function test_a_signed_in_user_links_their_own_erp_account(): void
    {
        $this->fakeErp(42);
        $employee = $this->userWithRole('R01');

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/auth/erp/link', ['code' => 'one-time-code', 'code_verifier' => self::VERIFIER])
            ->assertOk()
            ->assertJsonPath('data.erp_user_id', 42);

        $this->assertSame(42, $employee->fresh()->erp_user_id);
    }

    public function test_an_erp_account_already_linked_elsewhere_cannot_be_linked_again(): void
    {
        $this->fakeErp(42);
        $this->userWithRole('R01', ['erp_user_id' => 42]);
        $other = $this->userWithRole('R01');

        $this->actingAs($other, 'sanctum')
            ->postJson('/api/auth/erp/link', ['code' => 'one-time-code', 'code_verifier' => self::VERIFIER])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertNull($other->fresh()->erp_user_id);
    }

    public function test_the_admin_sets_and_clears_the_link_on_the_users_screen(): void
    {
        $admin = $this->userWithRole('R08');
        $employee = $this->userWithRole('R01');
        $this->userWithRole('R01', ['erp_user_id' => 99]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$employee->id}", ['erp_user_id' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors('erp_user_id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$employee->id}", ['erp_user_id' => 42])
            ->assertOk()
            ->assertJsonPath('data.erp_user_id', 42);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/users/{$employee->id}", ['erp_user_id' => null])
            ->assertOk();
        $this->assertNull($employee->fresh()->erp_user_id);
    }
}
