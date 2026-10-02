<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating and renaming roles from the Roles & Permissions screen. The code is
 * what the workflow, the seeders and the matrix key off, so the part worth
 * pinning is that the client can never choose or change it.
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }

    public function test_the_admin_creates_roles_with_server_assigned_codes(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/roles', ['name_ar' => 'مدقق', 'name_en' => 'Auditor', 'code' => 'R08'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'C01')
            ->assertJsonPath('data.name_ar', 'مدقق');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/roles', ['name_ar' => 'مراقب'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'C02');
    }

    public function test_editing_a_role_changes_its_names_but_never_its_code(): void
    {
        $role = Role::where('code', 'R01')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/roles/{$role->id}", ['name_ar' => 'موظف البلدية', 'code' => 'X99'])
            ->assertOk()
            ->assertJsonPath('data.name_ar', 'موظف البلدية')
            ->assertJsonPath('data.code', 'R01');
    }

    public function test_a_role_without_an_arabic_name_is_refused_in_arabic(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/roles', ['name_en' => 'Auditor'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name_ar.0', 'الاسم العربي للدور مطلوب.');
    }

    public function test_a_user_without_the_roles_screen_is_refused(): void
    {
        $employee = User::factory()->create();
        $employee->roles()->attach(Role::where('code', 'R01')->value('id'));
        $role = Role::where('code', 'R01')->firstOrFail();

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/roles', ['name_ar' => 'مدقق'])
            ->assertForbidden();
        $this->actingAs($employee, 'sanctum')
            ->putJson("/api/roles/{$role->id}", ['name_ar' => 'x'])
            ->assertForbidden();
    }

    public function test_a_new_role_opens_exactly_the_screens_it_is_granted(): void
    {
        $roleId = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/roles', ['name_ar' => 'مدقق'])
            ->json('data.id');
        $screen = Screen::where('code', 'audit_log')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/screen-role-permissions', ['items' => [[
                'screen_id' => $screen->id, 'role_id' => $roleId,
                'can_view' => true, 'can_add' => false, 'can_edit' => false, 'can_delete' => false,
                'can_approve' => false, 'can_print' => false, 'can_export' => false,
            ]]])
            ->assertOk();

        $user = User::factory()->create();
        $user->roles()->attach($roleId);

        $this->assertTrue($user->hasScreenPermission('audit_log', 'can_view'));
        $this->assertFalse($user->hasScreenPermission('audit_log', 'can_edit'));
        $this->assertFalse($user->hasScreenPermission('users', 'can_view'));
    }
}
