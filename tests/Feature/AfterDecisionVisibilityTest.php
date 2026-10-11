<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The after-decision writes are offered only on a page that already passed
 * RequestVisibility, so a grant holder calling them by id on a file they
 * cannot open must get the same 404 the page would.
 */
class AfterDecisionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_after_decision_writes_404_on_a_file_the_actor_cannot_open(): void
    {
        $this->seed(DatabaseSeeder::class);

        // R02 + R12 hold every grant these routes ride, but a file still in
        // intake (no reference number) is not the committee's yet, and
        // administrative_routing is not one of HR's co-owned stages.
        $rapporteur = $this->userWithRole('R02');
        $rapporteur->roles()->attach(Role::where('code', 'R12')->value('id'));
        $owner = $this->userWithRole('R01');
        $owner->update(['manager_id' => $this->userWithRole('R05')->id]);
        $hidden = Request::create([
            'intake_receipt_number' => 'PM-RCV/'.now()->format('Y').'/'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'ملف خارج نطاق المقرر',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_review')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'administrative_routing')->value('id'),
            'created_by_user_id' => $owner->id,
            'submitted_at' => now(),
        ]);

        $id = $hidden->id;
        $writes = [
            ['patch', "requests/{$id}/reopen"],
            ['patch', "requests/{$id}/close"],
            ['patch', "requests/{$id}/archive/committee-file"],
            ['patch', "requests/{$id}/archive/service-file"],
            ['post', "requests/{$id}/notices/issue"],
            ['patch', "requests/{$id}/approval-return"],
            ['patch', "requests/{$id}/approval-return/resolve"],
            ['post', "requests/{$id}/approval-referrals"],
            ['patch', "requests/{$id}/execution-soundness"],
            ['patch', "requests/{$id}/suspend"],
            ['patch', "requests/{$id}/suspend/lift"],
        ];

        $referral = $hidden->approvalReferrals()->create([
            'referred_at' => now(),
            'letter_number' => 'X-1',
            'referred_to_body' => 'جهة',
            'recorded_by_user_id' => $rapporteur->id,
        ]);
        $writes[] = ['patch', "requests/{$id}/approval-referrals/{$referral->id}/result"];

        $meeting = Meeting::create([
            'committee_id' => Committee::create(['name_ar' => 'لجنة'])->id,
            'title' => 'اجتماع',
            'scheduled_at' => now(),
            'created_by_user_id' => $rapporteur->id,
        ]);
        $agendaItem = $meeting->agendaItems()->create(['request_id' => $id, 'agenda_order' => 1]);
        $writes[] = ['post', "meetings/{$meeting->id}/outputs/{$agendaItem->id}/execute"];

        foreach ($writes as [$method, $uri]) {
            $status = $this->actingAs($rapporteur, 'sanctum')->json($method, "/api/{$uri}")->status();
            $this->assertSame(404, $status, "{$method} {$uri} answered {$status}");
        }
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
