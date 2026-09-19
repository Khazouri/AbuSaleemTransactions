<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\MeetingVisibility;
use App\Services\RequestVisibility;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * Membership gate — the regression guards for the two ways hiding the meetings
 * section could have broken something far away from it.
 *
 * Both are real and both are silent. Neither is hypothetical: the gate was
 * written the way it is BECAUSE of them, and a future change that "simplifies"
 * it by suppressing a whole permission row, or by deleting a screen that only
 * looks like a menu entry, springs them again with no error anywhere.
 */
class GatedScreenSideEffectsTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * The invariant that makes every other guard here hold: the gate may
     * suppress can_view and nothing else, ever.
     *
     * Compares an unseated actor's resolved map against a seated one holding
     * the same role. Any difference outside can_view on a gated screen means
     * the gate has started removing capabilities rather than hiding links.
     */
    public function test_the_gate_changes_can_view_and_no_other_flag(): void
    {
        $unseated = $this->userWithRole('R02');
        $seated = $this->userWithRole('R02');
        $this->seatOn(Committee::create(['name_ar' => 'لجنة شؤون الموظفين']), $seated);

        $before = $seated->screenPermissions();
        $after = $unseated->screenPermissions();

        $this->assertSame(array_keys($before), array_keys($after));

        foreach ($before as $code => $actions) {
            foreach ($actions as $action => $granted) {
                if ($action === 'can_view' && in_array($code, MeetingVisibility::GATED_SCREENS, true)) {
                    continue;
                }
                $this->assertSame($granted, $after[$code][$action], "{$code}.{$action} changed");
            }
        }

        // ...and it really did hide them, so the loop above is not vacuous.
        foreach (MeetingVisibility::GATED_SCREENS as $code) {
            $this->assertTrue($before[$code]['can_view'], "seated lost {$code}");
            $this->assertFalse($after[$code]['can_view'], "unseated kept {$code}");
        }
    }

    /**
     * RequestVisibility reads three grants on two meetings-group screens to
     * decide who may open a request: `legal_review,can_add` for the legal
     * reviewer, and `meeting_outputs,can_edit|can_approve` for the closer and
     * the executing body. R12 (HR Manager) holds the last of those and holds no
     * committee seat by design, so a gate that zeroed the row would have
     * revoked execution and closure from the role that performs them.
     */
    public function test_an_unseated_actor_keeps_the_grants_request_visibility_reads(): void
    {
        $hr = $this->userWithRole('R12');
        $legal = $this->userWithRole('R11');
        $rapporteur = $this->userWithRole('R02');

        $this->assertTrue($hr->hasScreenPermission('meeting_outputs', 'can_approve'));
        $this->assertTrue($legal->hasScreenPermission('legal_review', 'can_add'));
        $this->assertTrue($rapporteur->hasScreenPermission('meeting_outputs', 'can_edit'));
    }

    /**
     * The same fact one layer up: an unseated closer still SEES a registered
     * file. This is the assertion that would fail first if the grants above
     * were ever suppressed, and it is the behaviour Stage 92 depends on.
     */
    public function test_an_unseated_closer_still_sees_a_registered_request(): void
    {
        $hr = $this->userWithRole('R12');
        $requestRecord = $this->registeredRequest();

        $this->assertTrue(app(RequestVisibility::class)->canView($hr, $requestRecord));
    }

    /**
     * The five approval screens are a capability registry, not menu entries:
     * RequestController::actorCanApproveCurrentLevel() maps each approval stage
     * to one of their codes and checks can_approve on it. Removing the rows —
     * as opposed to removing their routes — makes that false for everyone, and
     * the approve button disappears at every checkpoint for every role.
     */
    public function test_each_approval_checkpoint_still_grants_its_one_role(): void
    {
        foreach ([
            'reviewer_approval' => 'R02',
            'committee_head_approval' => 'R03',
            'admin_manager_approval' => 'R05',
            'ministry_approval' => 'R06',
            'final_approval' => 'R07',
        ] as $screen => $role) {
            $this->assertTrue(
                $this->userWithRole($role)->hasScreenPermission($screen, 'can_approve'),
                "{$screen} no longer grants {$role}",
            );
        }
    }

    /** The memoized map must not outlive a change made on the same instance. */
    public function test_forgetting_the_cache_picks_up_a_newly_granted_seat(): void
    {
        $user = $this->userWithRole('R03');
        $this->assertFalse($user->screenPermissions()['meetings']['can_view']);

        $this->seatOn(Committee::create(['name_ar' => 'لجنة شؤون الموظفين']), $user);
        $this->assertFalse($user->screenPermissions()['meetings']['can_view'], 'cache should still be warm');

        $user->forgetScreenPermissions();
        $this->assertTrue($user->screenPermissions()['meetings']['can_view']);
    }

    private function registeredRequest(): Request
    {
        return Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numerify('####'),
            'title' => 'طلب مقيد لدى اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'final_approved')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'submitted_at' => now(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
