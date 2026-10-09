<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Decision;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\RequestWithdrawal;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 83 — [D] Appendix 68 (سحب الموظف لطلبه) and Appendix 69 (انسحاب الطلب
 * بعد صدور قرار اللجنة).
 */
class RequestWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_granted_withdrawal_closes_the_file_with_the_appendixs_own_reason(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$employee, $requestRecord] = $this->undecidedRequest();
        $rapporteur = $this->userWithRole('R02');

        $id = $this->actingAs($employee, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/withdrawals", [
                'reason' => 'أطلب سحب معاملتي لظرف شخصي.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertFalse(RequestWithdrawal::find($id)->decision_existed_at_filing);

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/withdrawals/{$id}/determine", [
                'outcome' => RequestWithdrawal::OUTCOME_GRANTED,
                'determination_note' => 'لا يوجد سبب قانوني يستوجب استمرار الإجراء تلقائياً.',
            ])
            ->assertOk();

        $this->assertSame('cancelled', $requestRecord->fresh()->status->code);

        // "تقفل المعاملة بسبب (سحب الطلب)" — the reason is on the record.
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'reason' => RequestWithdrawal::CLOSURE_REASON.' — أطلب سحب معاملتي لظرف شخصي.',
        ]);
    }

    /**
     * "أما إذا كان الموضوع قد تحول إلى إجراء إداري لا يتوقف على رغبة الموظف،
     * فلا يؤدي طلب السحب تلقائيًا إلى إنهائه."
     */
    public function test_an_administrative_matter_continues_despite_the_withdrawal_request(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$employee, $requestRecord] = $this->undecidedRequest();
        $rapporteur = $this->userWithRole('R02');
        $statusBefore = $requestRecord->status_id;

        $id = $this->actingAs($employee, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/withdrawals", ['reason' => 'أطلب السحب.'])
            ->json('data.id');

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/withdrawals/{$id}/determine", [
                'outcome' => RequestWithdrawal::OUTCOME_CONTINUES,
                'determination_note' => 'تحول الموضوع إلى إجراء إداري لا يتوقف على رغبة الموظف.',
            ])
            ->assertOk();

        $this->assertSame($statusBefore, $requestRecord->fresh()->status_id);
    }

    /**
     * Appendix 69 enforced as a refusal: "**بعد صدور نتيجة اللجنة لا يحذف
     * القرار ولا تمحى المعاملة**".
     */
    public function test_after_a_decision_the_file_can_only_be_recorded_never_closed_on_withdrawal(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$employee, $requestRecord] = $this->undecidedRequest();
        $decision = $this->decide($requestRecord);
        $rapporteur = $this->userWithRole('R02');
        $statusBefore = $requestRecord->status_id;

        $id = $this->actingAs($employee, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/withdrawals", ['reason' => 'لم أعد أرغب في الاستمرار.'])
            ->json('data.id');

        $this->assertTrue(RequestWithdrawal::find($id)->decision_existed_at_filing);

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/withdrawals/{$id}/determine", [
                'outcome' => RequestWithdrawal::OUTCOME_GRANTED,
                'determination_note' => 'قبول السحب.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('outcome');

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/withdrawals/{$id}/determine", [
                'outcome' => RequestWithdrawal::OUTCOME_RECORDED_ONLY,
                'determination_note' => 'سُجل الطلب ولا أثر له على القرار الصادر.',
            ])
            ->assertOk();

        // Nothing is deleted and nothing moves: the decision and the file both
        // stand exactly as they were.
        $this->assertSame($statusBefore, $requestRecord->fresh()->status_id);
        $this->assertDatabaseHas('decisions', ['id' => $decision->id]);
        $this->assertSame(1, Request::whereKey($requestRecord->id)->count());
    }

    public function test_recorded_only_is_not_available_before_a_decision_exists(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$employee, $requestRecord] = $this->undecidedRequest();

        $id = $this->actingAs($employee, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/withdrawals", ['reason' => 'أطلب السحب.'])
            ->json('data.id');

        $this->actingAs($this->userWithRole('R02'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/withdrawals/{$id}/determine", [
                'outcome' => RequestWithdrawal::OUTCOME_RECORDED_ONLY,
                'determination_note' => 'x',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('outcome');
    }

    /** "إذا طلب **الموظف** سحب معاملته" — the requester's own act. */
    public function test_only_the_requester_may_file_and_only_one_may_be_open_at_a_time(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$employee, $requestRecord] = $this->undecidedRequest();
        $stranger = $this->userWithRole('R02');

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/withdrawals", ['reason' => 'سحب نيابة عنه.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        // Appendix 68 step 1 — the request is written, so a blank one is not
        // a withdrawal request at all.
        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/withdrawals", [])
            ->assertStatus(422);

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/withdrawals", ['reason' => 'أطلب السحب.'])
            ->assertCreated();

        $this->actingAs($employee, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/withdrawals", ['reason' => 'وأطلبه مجدداً.'])
            ->assertStatus(422);
    }

    public function test_a_determined_withdrawal_is_one_shot_and_reaches_the_lifecycle_read(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$employee, $requestRecord] = $this->undecidedRequest();
        $rapporteur = $this->userWithRole('R02');

        $id = $this->actingAs($employee, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/withdrawals", ['reason' => 'أطلب السحب.'])
            ->json('data.id');

        $payload = [
            'outcome' => RequestWithdrawal::OUTCOME_GRANTED,
            'determination_note' => 'قبول السحب.',
        ];

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/withdrawals/{$id}/determine", $payload)
            ->assertOk();

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/withdrawals/{$id}/determine", $payload)
            ->assertStatus(422);

        $withdrawals = $this->actingAs($rapporteur, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/lifecycle")
            ->assertOk()
            ->json('data.withdrawals');

        $this->assertCount(1, $withdrawals);
        $this->assertSame('قبول السحب وإقفال المعاملة', $withdrawals[0]['outcome_label']);
    }

    // --- fixtures ----------------------------------------------------------

    /** @return array{0: User, 1: Request} */
    private function undecidedRequest(): array
    {
        $employee = $this->userWithRole('R01');

        $requestRecord = Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب اختبار السحب',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'ready')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $employee->id,
            'submitted_at' => now(),
        ]);

        return [$employee, $requestRecord];
    }

    private function decide(Request $requestRecord): Decision
    {
        $head = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع',
            'scheduled_at' => now()->subDay(),
            'created_by_user_id' => $head->id,
        ]);

        $item = $meeting->agendaItems()->create([
            'request_id' => $requestRecord->id,
            'agenda_order' => 1,
        ]);

        return Decision::create([
            'meeting_request_id' => $item->id,
            'outcome' => 'approve',
            'decided_by_user_id' => $head->id,
            'decided_at' => now(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
