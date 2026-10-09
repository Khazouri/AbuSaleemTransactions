<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestCorrection;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Stage 83 — [D] Appendix 53's قواعد تصحيح الخطأ المادي. */
class RequestCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_material_error_is_recorded_as_a_memo_and_changes_nothing_on_the_request(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->decidedRequest();
        $originalTitle = $requestRecord->title;
        $originalReference = $requestRecord->reference_number;

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/corrections", [
                'error_kind' => 'name',
                'detail' => 'ورد اسم الموظف في القرار بصيغة غير مطابقة للسجل المدني.',
                'incorrect_value' => 'محمد علي',
                'corrected_value' => 'محمد علي أحمد',
                'memo_reference' => 'مذكرة تصحيح 12/2026',
            ])
            ->assertCreated();

        // "دون تغيير جوهر النتيجة" — the memo is a document on the file, and
        // the original record stands exactly as issued.
        $requestRecord->refresh();
        $this->assertSame($originalTitle, $requestRecord->title);
        $this->assertSame($originalReference, $requestRecord->reference_number);
        $this->assertSame(1, $requestRecord->corrections()->count());
    }

    /**
     * "أما إذا أثر الخطأ على ... فلا يعالج باعتباره خطأً ماديًا، بل يعاد
     * للمسار الرسمي للمراجعة" — refused, with that route named.
     */
    public function test_a_substantive_error_is_refused_and_pointed_at_the_formal_route(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->decidedRequest();

        foreach (['decision_reason', 'intended_employee', 'entitlement', 'grade', 'result', 'approving_body'] as $kind) {
            $this->actingAs($rapporteur, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/corrections", [
                    'error_kind' => $kind,
                    'detail' => 'خطأ يمس جوهر القرار.',
                    'incorrect_value' => 'أ',
                    'corrected_value' => 'ب',
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('error_kind');
        }

        $this->assertSame(0, RequestCorrection::count());
    }

    /** "بمذكرة تصحيح **معتمدة**" — an unapproved memo corrects nothing. */
    public function test_a_memo_is_not_effective_until_approved_and_never_by_its_own_author(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $head = $this->userWithRole('R03');
        $requestRecord = $this->decidedRequest();

        $id = $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/corrections", [
                'error_kind' => 'date',
                'detail' => 'خطأ في تاريخ الجلسة.',
                'incorrect_value' => '2026/01/01',
                'corrected_value' => '2026/02/01',
            ])->json('data.id');

        $this->assertNull(RequestCorrection::find($id)->approved_at);

        // Appendix 19's own separation rule: "لا يكون ... مراجع الملف هو صاحب
        // القرار المنفرد بشأنه".
        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/corrections/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('correction');

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/corrections/{$id}/approve")
            ->assertOk();

        $this->assertNotNull(RequestCorrection::find($id)->approved_at);

        // One-shot, like every other approval in this codebase.
        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/corrections/{$id}/approve")
            ->assertStatus(422);
    }

    public function test_the_memo_reaches_the_lifecycle_read_and_a_foreign_memo_404s(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $head = $this->userWithRole('R03');
        $requestRecord = $this->decidedRequest();
        $other = $this->decidedRequest();

        $id = $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/corrections", [
                'error_kind' => 'reference_number',
                'detail' => 'خطأ في الرقم المرجعي المدون بالمحضر.',
                'incorrect_value' => 'PM-COM/2026/0001',
                'corrected_value' => 'PM-COM/2026/0010',
            ])->json('data.id');

        $this->actingAs($head, 'sanctum')
            ->patchJson("/api/requests/{$other->id}/corrections/{$id}/approve")
            ->assertNotFound();

        $corrections = $this->actingAs($rapporteur, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/lifecycle")
            ->assertOk()
            ->json('data.corrections');

        $this->assertCount(1, $corrections);
        $this->assertSame('خطأ في الرقم المرجعي', $corrections[0]['kind_label']);
    }

    // --- fixtures ----------------------------------------------------------

    private function decidedRequest(): Request
    {
        $employee = $this->userWithRole('R01');

        return Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب اختبار التصحيح',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'final_approved')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'final_approval_archiving')->value('id'),
            'created_by_user_id' => $employee->id,
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
