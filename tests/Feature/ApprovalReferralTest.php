<?php

namespace Tests\Feature;

use App\Models\ApprovalReferral;
use App\Models\Department;
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
 * Stage 80 — [D] Art. 30's سجل الإحالات للاعتماد, i.e. Art. 98's register 7.
 *
 * Stage 77 recorded two of the article's six fields, and only on the return
 * path. These are the other four.
 */
class ApprovalReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Art. 30's outward three, recorded without moving the file: the approve
     * transition already put it where it is, and re-stating that here would
     * give one fact two writers.
     */
    public function test_a_referral_records_the_outward_three_fields_and_moves_nothing(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $this->actingAs($recorder, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/approval-referrals", [
                'referred_at' => '2026-09-01',
                'letter_number' => 'ص/2026/118',
                'referred_to_body' => 'عميد البلدية',
            ])
            ->assertOk()
            ->assertJsonPath('data.approval_referrals.0.referred_at', '2026-09-01')
            ->assertJsonPath('data.approval_referrals.0.letter_number', 'ص/2026/118')
            ->assertJsonPath('data.approval_referrals.0.referred_to_body', 'عميد البلدية')
            ->assertJsonPath('data.approval_referrals.0.referred_from_stage.code', 'approval_by_authority')
            ->assertJsonPath('data.approval_referrals.0.recorded_by.id', $recorder->id)
            // Art. 30's inward three are unknown at this moment, and say so.
            ->assertJsonPath('data.approval_referrals.0.result_outcome', null)
            ->assertJsonPath('data.approval_referrals.0.approval_decision_number', null)
            // A second referral cannot be opened while this one is unanswered.
            ->assertJsonPath('data.approval_referral_eligibility.can_record', true);

        $fresh = $requestRecord->fresh();
        $this->assertSame('approval_by_authority', $fresh->currentStage->code);
        $this->assertSame('awaiting_municipal_approval', $fresh->status->code);
        // A register entry is not a transition: nothing moved, nothing logged.
        $this->assertDatabaseMissing('request_stage_logs', ['request_id' => $requestRecord->id]);
    }

    /** Each of the outward three is required — see the Form Request's docblock. */
    public function test_each_outward_field_is_required(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $complete = [
            'referred_at' => '2026-09-01',
            'letter_number' => 'ص/2026/118',
            'referred_to_body' => 'عميد البلدية',
        ];

        foreach (array_keys($complete) as $field) {
            $payload = $complete;
            unset($payload[$field]);

            $this->actingAs($recorder, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/approval-referrals", $payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertSame(0, ApprovalReferral::count());
    }

    /**
     * Art. 30's inward three, recorded separately because nobody knows them at
     * the moment of the referral.
     */
    public function test_the_result_is_recorded_against_the_referral_it_answers(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('local_governance_ministry', 'awaiting_central_approval');
        $referral = $this->referral($requestRecord, $recorder);

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-referrals/{$referral->id}/result", [
                'result_outcome' => 'approved',
                'result_received_at' => '2026-09-20',
                'approval_decision_number' => 'ق/2026/77',
                'result_note' => 'اعتُمد دون تحفظ.',
            ])
            ->assertOk()
            ->assertJsonPath('data.approval_referrals.0.result_outcome', 'approved')
            ->assertJsonPath('data.approval_referrals.0.result_received_at', '2026-09-20')
            ->assertJsonPath('data.approval_referrals.0.approval_decision_number', 'ق/2026/77')
            ->assertJsonPath('data.approval_referrals.0.result_recorded_by.id', $recorder->id);

        // One-shot: an answered referral is not answered twice.
        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-referrals/{$referral->id}/result", [
                'result_outcome' => 'approved',
                'result_received_at' => '2026-09-21',
                'approval_decision_number' => 'ق/2026/78',
            ])
            ->assertStatus(422);
    }

    /**
     * رقم قرار الاعتماد binds only on an approval — a file the body sent back
     * has no اعتماد number, and demanding one would make the honest answer
     * unrecordable.
     */
    public function test_the_approval_decision_number_binds_only_on_an_approval(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');
        $referral = $this->referral($requestRecord, $recorder);

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-referrals/{$referral->id}/result", [
                'result_outcome' => 'approved',
                'result_received_at' => '2026-09-20',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('approval_decision_number');

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-referrals/{$referral->id}/result", [
                'result_outcome' => 'returned',
                'result_received_at' => '2026-09-20',
                'result_note' => 'أعيد لاستكمال توقيع.',
            ])
            ->assertOk()
            ->assertJsonPath('data.approval_referrals.0.result_outcome', 'returned')
            ->assertJsonPath('data.approval_referrals.0.approval_decision_number', null);
    }

    /**
     * Art. 94's loop has no limit, so neither does this register: a corrected
     * formal return re-referred to the same body is a second إحالة, and the
     * first round has to stay readable.
     */
    public function test_rounds_accumulate_rather_than_overwrite(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');

        $first = $this->referral($requestRecord, $recorder, 'ص/2026/1');
        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/approval-referrals/{$first->id}/result", [
                'result_outcome' => 'returned',
                'result_received_at' => '2026-09-10',
            ])
            ->assertOk();

        $this->actingAs($recorder, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/approval-referrals", [
                'referred_at' => '2026-09-12',
                'letter_number' => 'ص/2026/2',
                'referred_to_body' => 'عميد البلدية',
            ])
            ->assertOk()
            ->assertJsonPath('data.approval_referrals.0.letter_number', 'ص/2026/1')
            ->assertJsonPath('data.approval_referrals.1.letter_number', 'ص/2026/2');

        $this->assertSame(2, ApprovalReferral::count());
    }

    /** A second referral cannot be opened while one is still unanswered. */
    public function test_a_second_referral_is_refused_while_one_is_open(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');
        $this->referral($requestRecord, $recorder);

        $this->actingAs($recorder, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/approval-referrals", [
                'referred_at' => '2026-09-12',
                'letter_number' => 'ص/2026/2',
                'referred_to_body' => 'عميد البلدية',
            ])
            ->assertStatus(422);

        $this->assertSame(1, ApprovalReferral::count());
    }

    /** Art. 30 is about a file that is with an approving body. */
    public function test_a_referral_is_refused_outside_the_approval_cycle(): void
    {
        $recorder = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'registered');

        $this->actingAs($recorder, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/approval-referrals", [
                'referred_at' => '2026-09-01',
                'letter_number' => 'ص/2026/118',
                'referred_to_body' => 'عميد البلدية',
            ])
            ->assertStatus(422);

        $this->assertSame(0, ApprovalReferral::count());
    }

    /**
     * Art. 30 addresses this register to مقرر اللجنة, so it rides
     * `meeting_outputs,edit` (R02 + R03) and nobody else.
     */
    public function test_only_the_outputs_edit_grant_may_record_a_referral(): void
    {
        $requestRecord = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');
        $payload = [
            'referred_at' => '2026-09-01',
            'letter_number' => 'ص/2026/118',
            'referred_to_body' => 'عميد البلدية',
        ];

        $this->actingAs($this->userWithRole('R04'), 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/approval-referrals", $payload)
            ->assertForbidden();

        $this->actingAs($this->userWithRole('R03'), 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/approval-referrals", $payload)
            ->assertOk();
    }

    /** A referral belonging to another request is not answerable through this one. */
    public function test_a_referral_from_another_request_is_not_reachable(): void
    {
        $recorder = $this->userWithRole('R02');
        $mine = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');
        $other = $this->requestAt('approval_by_authority', 'awaiting_municipal_approval');
        $referral = $this->referral($other, $recorder);

        $this->actingAs($recorder, 'sanctum')
            ->patchJson("/api/requests/{$mine->id}/approval-referrals/{$referral->id}/result", [
                'result_outcome' => 'approved',
                'result_received_at' => '2026-09-20',
                'approval_decision_number' => 'ق/2026/77',
            ])
            ->assertNotFound();
    }

    private function referral(Request $requestRecord, User $recorder, string $letter = 'ص/2026/118'): ApprovalReferral
    {
        return ApprovalReferral::create([
            'request_id' => $requestRecord->id,
            'referred_from_stage_id' => $requestRecord->current_stage_id,
            'referred_at' => '2026-09-01',
            'letter_number' => $letter,
            'referred_to_body' => 'عميد البلدية',
            'recorded_by_user_id' => $recorder->id,
        ]);
    }

    private function requestAt(string $stageCode, string $statusCode): Request
    {
        $creator = $this->userWithRole('R01');

        return Request::create([
            'reference_number' => 'PM-COM/2026/'.str_pad((string) (Request::count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'معاملة لدى جهة الاعتماد',
            'department_id' => Department::query()->where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::query()->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now()->subMonth(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::query()->where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $user;
    }
}
