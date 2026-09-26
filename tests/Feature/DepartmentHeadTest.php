<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The department head (manager_user_id) shown by the hierarchy view. It is a
 * label only, so what matters is that it can never name someone who is not
 * actually in the department.
 */
class DepartmentHeadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $this->department = Department::create(['name_ar' => 'إدارة الاختبار', 'code' => 'HDT']);
    }

    public function test_an_active_member_can_be_made_head_and_is_returned(): void
    {
        $member = User::factory()->create(['department_id' => $this->department->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/departments/{$this->department->id}", ['manager_user_id' => $member->id])
            ->assertOk()
            ->assertJsonPath('data.manager_user_id', $member->id);
    }

    public function test_the_head_can_be_cleared(): void
    {
        $member = User::factory()->create(['department_id' => $this->department->id]);
        $this->department->update(['manager_user_id' => $member->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/departments/{$this->department->id}", ['manager_user_id' => null])
            ->assertOk()
            ->assertJsonPath('data.manager_user_id', null);
    }

    public function test_a_non_member_or_inactive_member_is_refused(): void
    {
        $outsider = User::factory()->create(['department_id' => null]);
        $inactive = User::factory()->create(['department_id' => $this->department->id, 'is_active' => false]);

        foreach ([$outsider, $inactive] as $candidate) {
            $this->actingAs($this->admin, 'sanctum')
                ->putJson("/api/departments/{$this->department->id}", ['manager_user_id' => $candidate->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('manager_user_id');
        }
    }

    public function test_moving_the_head_to_another_department_clears_the_slot(): void
    {
        $member = User::factory()->create(['department_id' => $this->department->id]);
        $this->department->update(['manager_user_id' => $member->id]);
        $other = Department::create(['name_ar' => 'إدارة أخرى', 'code' => 'HD2']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/users/{$member->id}", ['department_id' => $other->id])
            ->assertOk();

        $this->assertNull($this->department->fresh()->manager_user_id);
    }

    public function test_deleting_the_head_clears_the_slot(): void
    {
        $member = User::factory()->create(['department_id' => $this->department->id]);
        $this->department->update(['manager_user_id' => $member->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/users/{$member->id}")
            ->assertNoContent();

        $this->assertNull($this->department->fresh()->manager_user_id);
    }

    public function test_deactivating_the_head_clears_the_slot(): void
    {
        $member = User::factory()->create(['department_id' => $this->department->id]);
        $this->department->update(['manager_user_id' => $member->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/users/{$member->id}/toggle-active")
            ->assertOk();

        $this->assertNull($this->department->fresh()->manager_user_id);
    }

    public function test_an_edit_that_keeps_the_head_in_place_does_not_clear_it(): void
    {
        $member = User::factory()->create(['department_id' => $this->department->id]);
        $this->department->update(['manager_user_id' => $member->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/users/{$member->id}", ['name' => 'اسم جديد', 'department_id' => $this->department->id])
            ->assertOk();

        $this->assertSame($member->id, $this->department->fresh()->manager_user_id);
    }
}
