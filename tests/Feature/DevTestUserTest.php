<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The login screen's test-account picker, which is served by the backend so
 * that APP_ENV — not whichever machine ran `npm run build` — decides whether
 * the panel appears.
 *
 * The suite itself runs as APP_ENV=testing (phpunit.xml), so the default state
 * of every test here is the hidden one; the local case is opted into
 * explicitly by rebinding the environment.
 */
class DevTestUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rebind the container's environment for the request under test.
     *
     * app()->environment() reads a binding resolved at bootstrap rather than
     * config('app.env') live, so setting the config alone would not move it.
     */
    private function asEnvironment(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
    }

    public function test_the_endpoint_is_absent_outside_a_local_environment(): void
    {
        // 404 rather than 403: production should not confirm that an endpoint
        // listing known-password accounts exists at this path at all.
        $this->getJson('/api/dev/test-users')->assertNotFound();

        $this->asEnvironment('production');
        $this->getJson('/api/dev/test-users')->assertNotFound();
    }

    public function test_it_lists_every_seeded_account_in_a_local_environment(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(TestUserSeeder::class);
        $this->asEnvironment('local');

        $response = $this->getJson('/api/dev/test-users')->assertOk();

        $response->assertJsonPath('password', TestUserSeeder::PASSWORD)
            ->assertJsonCount(count(TestUserSeeder::TEST_USERS), 'users')
            ->assertJsonPath('users.0.email', TestUserSeeder::TEST_USERS[0][0])
            ->assertJsonPath('users.0.seeded', true);

        // The inactive account must stay in the list: its refusal is a thing
        // TEST_PLAN.md checks, and it can only be checked if it's clickable.
        $this->assertContains(
            'inactive.user@abusaleem.test',
            array_column($response->json('users'), 'email'),
        );
    }

    public function test_it_reports_accounts_that_have_not_been_seeded(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->asEnvironment('local');

        // Without TestUserSeeder having run, every row is missing — the panel
        // says so instead of answering "bad credentials" on every click.
        $seeded = array_column($this->getJson('/api/dev/test-users')->assertOk()->json('users'), 'seeded');

        $this->assertSame([], array_filter($seeded));
    }

    public function test_the_payload_carries_no_credentials_beyond_the_shared_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(TestUserSeeder::class);
        $this->asEnvironment('local');

        $body = $this->getJson('/api/dev/test-users')->assertOk()->getContent();

        // The response is built from the seeder's static list, never from User
        // rows, so no hash or token can travel with it.
        $this->assertStringNotContainsString('$2y$', $body);
        $this->assertStringNotContainsString('remember_token', $body);
    }
}
