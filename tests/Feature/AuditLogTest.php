<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stage 22 — the observer-written audit trail and its read-only viewer. */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_writes_no_audit_rows(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Bootstrap data isn't user activity: if this ever fails, the viewer
        // opens on hundreds of rows describing the system setting itself up.
        $this->assertSame(0, AuditLog::count());
    }

    public function test_creating_and_updating_a_record_is_audited_with_the_actor_and_the_diff(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/departments', ['name_ar' => 'إدارة التدقيق', 'code' => 'AUD'])
            ->assertCreated();

        $department = Department::where('code', 'AUD')->firstOrFail();

        $created = AuditLog::where('auditable_type', Department::class)
            ->where('auditable_id', $department->id)
            ->where('action', 'created')
            ->firstOrFail();

        $this->assertSame($admin->id, $created->user_id);
        $this->assertNull($created->old_values);
        $this->assertSame('إدارة التدقيق', $created->new_values['name_ar']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/departments/{$department->id}", ['name_ar' => 'إدارة التدقيق الداخلي'])
            ->assertOk();

        $updated = AuditLog::where('auditable_type', Department::class)
            ->where('auditable_id', $department->id)
            ->where('action', 'updated')
            ->firstOrFail();

        // Only what changed, both sides of it — not a full row snapshot.
        $this->assertSame(['name_ar'], array_keys($updated->new_values));
        $this->assertSame('إدارة التدقيق', $updated->old_values['name_ar']);
        $this->assertSame('إدارة التدقيق الداخلي', $updated->new_values['name_ar']);
    }

    public function test_a_no_op_save_records_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->admin();
        $department = Department::where('code', 'ADM')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/departments/{$department->id}", ['name_ar' => $department->name_ar])
            ->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'updated')->count());
    }

    public function test_password_values_are_redacted_before_they_reach_the_log(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum');

        $user = User::create([
            'name' => 'موظف جديد',
            'email' => 'new.employee@abusaleem.test',
            'password' => 'secret-password',
            'is_active' => true,
        ]);

        $log = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->firstOrFail();

        $this->assertSame('********', $log->new_values['password']);
        $this->assertStringNotContainsString('secret-password', json_encode($log->new_values));
    }

    public function test_the_viewer_filters_by_action_and_model(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/departments', ['name_ar' => 'إدارة أولى', 'code' => 'AU1'])
            ->assertCreated();

        $department = Department::where('code', 'AU1')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/departments/{$department->id}", ['name_ar' => 'إدارة أولى معدّلة'])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/audit-logs?model=department&action=updated')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'updated')
            ->assertJsonPath('data.0.model', 'department')
            ->assertJsonPath('data.0.record_id', $department->id)
            ->assertJsonPath('data.0.user.id', $admin->id)
            ->assertJsonPath('data.0.changed_keys', ['name_ar']);

        // A model the client can't name any other way: the filter speaks
        // registry keys, never class strings.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/audit-logs?model=App%5CModels%5CDepartment')
            ->assertStatus(422);
    }

    public function test_a_user_without_the_audit_screen_permission_is_refused(): void
    {
        $this->seed(DatabaseSeeder::class);

        // No roles at all — the audit screen grants `view` to every seeded
        // role, so an unrolled account is the only way to be denied.
        $stranger = User::create([
            'name' => 'بدون صلاحيات',
            'email' => 'stranger@abusaleem.test',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/audit-logs')
            ->assertForbidden();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }
}
