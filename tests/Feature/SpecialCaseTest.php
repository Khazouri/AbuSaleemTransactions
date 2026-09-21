<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestSpecialCase;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\Lifecycle\SpecialCaseRules;
use App\Services\RequestClosureService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ClosesRequests;
use Tests\TestCase;

/** Stage 83 — [D] Appendix 60's six الحالات الخاصة والاستثنائية. */
class SpecialCaseTest extends TestCase
{
    use ClosesRequests;
    use RefreshDatabase;

    public function test_every_case_requires_the_determinations_the_appendix_names(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('final_approved', 'final_approval_archiving');

        foreach (SpecialCaseRules::DETERMINATIONS as $kind => $fields) {
            // Every field but the last, so exactly one is missing.
            $partial = collect($fields)
                ->map(fn (array $field) => isset($field['options']) ? array_key_first($field['options']) : 'نص')
                ->slice(0, count($fields) - 1)
                ->all();

            $this->actingAs($rapporteur, 'sanctum')
                ->postJson("/api/requests/{$requestRecord->id}/special-cases", [
                    'case_kind' => $kind,
                    'determinations' => $partial,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('determinations');
        }

        $this->assertSame(0, RequestSpecialCase::count());
    }

    public function test_a_determination_with_a_fixed_answer_set_refuses_an_unknown_value(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('final_approved', 'final_approval_archiving');

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/special-cases", [
                'case_kind' => RequestSpecialCase::KIND_SERVICE_ENDED,
                'determinations' => [
                    'legal_effect' => 'ربما',
                    'legal_effect_note' => 'غير محدد.',
                ],
            ])
            ->assertStatus(422);
    }

    /**
     * "لا تغلق المعاملة تلقائيًا" (وفاة) and "ولا يغلق الملف دون تحديد الأثر
     * القانوني" (انتهاء الخدمة) — the only two of the six that refuse a
     * closure, and both lift once the legal effect has been determined.
     */
    public function test_a_death_or_a_service_ending_refuses_closure_until_it_is_resolved(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $closer = $this->userWithRole('R03');
        $requestRecord = $this->requestAt('outside_jurisdiction', 'requirements_check');

        $caseId = $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/special-cases", [
                'case_kind' => RequestSpecialCase::KIND_DEATH,
                'determinations' => [
                    'legal_effect' => 'تنتقل الحقوق المالية للمستحقين.',
                    'procedure_continues' => 'continues',
                    'transferable_rights' => 'مستحقات نهاية الخدمة.',
                    'legal_review_reference' => 'إحالة رقم 7/2026.',
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            SpecialCaseRules::CLOSURE_BLOCKING[RequestSpecialCase::KIND_DEATH],
            app(RequestClosureService::class)->refusalReason($requestRecord->fresh()->load('status')),
        );

        $this->archiveFiles($requestRecord);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertStatus(422);

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/special-cases/{$caseId}/resolve", [
                'resolution_note' => 'حددت المراجعة القانونية أثر الوفاة واستمرار الإجراء.',
            ])
            ->assertOk();

        $this->archiveFiles($requestRecord);

        $this->actingAs($closer, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertOk();
    }

    /**
     * "يوقف استكمال الإجراء **إذا كان المستند مؤثرًا**" — the qualifier is the
     * gate, so a non-material finding blocks nothing.
     */
    public function test_an_invalid_document_blocks_approval_only_when_it_is_material(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('final_approved', 'final_approval_archiving');

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/special-cases", [
                'case_kind' => RequestSpecialCase::KIND_INVALID_DOCUMENT,
                'determinations' => ['material' => 'no', 'referred_to' => 'الإدارة القانونية.'],
            ])->assertCreated();

        $this->assertNull($this->approveRefusal($requestRecord));

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/special-cases", [
                'case_kind' => RequestSpecialCase::KIND_INVALID_DOCUMENT,
                'determinations' => ['material' => 'yes', 'referred_to' => 'الإدارة القانونية والسلطة المختصة.'],
            ])->assertCreated();

        $this->assertNotNull($this->approveRefusal($requestRecord));
    }

    /**
     * "يوقف الانتقال للمرحلة التالية **عند الحاجة**" — a qualifier, so the halt
     * is the recorder's declared answer rather than an automatic consequence.
     */
    public function test_a_legislative_change_blocks_progress_only_when_the_halt_is_declared(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('final_approved', 'final_approval_archiving');

        $determinations = [
            'legislation_effective_date' => '2026/07/01',
            'impact_note' => 'يغير شروط الترقية.',
            'applicable_law' => 'القانون النافذ وقت نشوء الحق.',
        ];

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/special-cases", [
                'case_kind' => RequestSpecialCase::KIND_LEGISLATION_CHANGED,
                'determinations' => $determinations,
            ])->assertCreated();

        $this->assertNull($this->approveRefusal($requestRecord));

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/special-cases", [
                'case_kind' => RequestSpecialCase::KIND_LEGISLATION_CHANGED,
                'determinations' => $determinations,
                'halt_progress' => true,
            ])->assertCreated();

        $this->assertNotNull($this->approveRefusal($requestRecord));
    }

    /** The appendix names determinations for these two and no prohibition. */
    public function test_a_transfer_or_a_lost_document_records_without_blocking_anything(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('outside_jurisdiction', 'requirements_check');

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/special-cases", [
                'case_kind' => RequestSpecialCase::KIND_TRANSFERRED,
                'determinations' => [
                    'transfer_date' => '2026/03/01',
                    'right_arose_date' => '2025/12/01',
                    'competent_body_at_right' => 'البلدية السابقة.',
                    'body_completing_procedure' => 'البلدية الحالية.',
                ],
            ])->assertCreated();

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/special-cases", [
                'case_kind' => RequestSpecialCase::KIND_DOCUMENT_LOST,
                'determinations' => [
                    'document_note' => 'قرار التعيين الأصلي.',
                    'replacement_attempt' => 'طلب بدل رسمي من الأرشيف المركزي.',
                    'replacement_source' => 'نسخة مصدقة من ديوان البلدية.',
                ],
            ])->assertCreated();

        $this->assertNull($this->approveRefusal($requestRecord));

        $this->archiveFiles($requestRecord);
        $this->actingAs($this->userWithRole('R03'), 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/close", $this->closurePayload())
            ->assertOk();
    }

    public function test_a_resolved_case_cannot_be_resolved_again_and_a_foreign_case_404s(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rapporteur = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('final_approved', 'final_approval_archiving');
        $other = $this->requestAt('final_approved', 'final_approval_archiving');

        $caseId = $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/special-cases", [
                'case_kind' => RequestSpecialCase::KIND_SERVICE_ENDED,
                'determinations' => [
                    'legal_effect' => 'prior_effect_remains',
                    'legal_effect_note' => 'يبقى أثر مالي عن مدة سابقة.',
                ],
            ])->json('data.id');

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$other->id}/special-cases/{$caseId}/resolve", ['resolution_note' => 'x'])
            ->assertNotFound();

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/special-cases/{$caseId}/resolve", ['resolution_note' => 'حُدد الأثر.'])
            ->assertOk();

        $this->actingAs($rapporteur, 'sanctum')
            ->patchJson("/api/requests/{$requestRecord->id}/special-cases/{$caseId}/resolve", ['resolution_note' => 'مرة أخرى.'])
            ->assertStatus(422);
    }

    // --- fixtures ----------------------------------------------------------

    private function approveRefusal(Request $requestRecord): ?string
    {
        return app(SpecialCaseRules::class)->approveRefusal($requestRecord->fresh());
    }

    private function requestAt(string $statusCode, string $stageCode): Request
    {
        $employee = $this->userWithRole('R01');

        return Request::create([
            'reference_number' => 'PM-COM/2026/'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب اختبار الحالات الخاصة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
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
