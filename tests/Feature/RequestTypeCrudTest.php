<?php

namespace Tests\Feature;

use App\Models\Request;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowTransition;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The request-type catalogue (أنواع الطلبات) — CRUD, and the delete guard that
 * makes it master data rather than a free-form list.
 *
 * The guard is the part worth testing hardest. Both foreign keys pointing at
 * `request_types` fail QUIETLY on their own: `requests.request_type_id` is
 * nullOnDelete (the type would vanish off every historical request without an
 * error) and `workflow_transitions.request_type_id` is cascadeOnDelete (that
 * type's own overrides would go with it). So these tests assert the refusal
 * AND that the referencing row survives it.
 */
class RequestTypeCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_an_admin_can_create_read_update_and_deactivate_a_type(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/request-types', [
                'code' => 'TEST',
                'name_ar' => 'نوع تجريبي',
                'name_en' => 'Test type',
                'default_sla_days' => 12,
                'decision_grade_threshold' => 10,
                'default_has_financial_impact' => true,
                'default_administrative_route' => 'hr',
                'legal_basis_ar' => 'المادة 1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'TEST')
            ->assertJsonPath('data.default_sla_days', 12)
            ->assertJsonPath('data.default_has_financial_impact', true)
            ->assertJsonPath('data.is_active', true)
            // The counts index() returns, so the screen can explain a refused
            // delete before anyone attempts one.
            ->assertJsonPath('data.requests_count', 0)
            ->assertJsonPath('data.workflow_transitions_count', 0);

        $id = $created->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/request-types/{$id}", [
                'code' => 'TEST',
                'name_ar' => 'نوع تجريبي معدّل',
                'default_sla_days' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('data.name_ar', 'نوع تجريبي معدّل')
            ->assertJsonPath('data.default_sla_days', 20);

        // Deactivating is the documented way to retire a type; it must not
        // remove the row, only take it out of the intake picker.
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/request-types/{$id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('request_types', ['id' => $id, 'is_active' => false]);

        // An inactive type disappears from intake but stays on the admin list,
        // which is the whole point of preferring it to a delete. Asked as an
        // employee, since the admin has no intake screen (2026-10-07).
        $this->actingAs($this->userWithRole('R01'), 'sanctum')
            ->getJson('/api/requests/intake-options')
            ->assertOk()
            ->assertJsonMissing(['code' => 'TEST']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/request-types')
            ->assertOk()
            ->assertJsonFragment(['code' => 'TEST']);
    }

    public function test_an_unreferenced_type_can_be_deleted(): void
    {
        $type = RequestType::create(['code' => 'ORPHAN', 'name_ar' => 'بلا ارتباط']);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/request-types/{$type->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('request_types', ['id' => $type->id]);
    }

    /**
     * requests.request_type_id is nullOnDelete: without this guard the delete
     * would succeed and silently strip the type off the request.
     */
    public function test_a_type_with_requests_cannot_be_deleted_and_the_request_keeps_its_type(): void
    {
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        $requestRecord = Request::create([
            'title' => 'طلب ترقية',
            'request_type_id' => $type->id,
            'created_by_user_id' => $this->admin()->id,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/request-types/{$type->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن حذف نوع طلب مرتبط بطلبات قائمة. عطّل النوع بدلاً من حذفه ليختفي من نموذج التقديم مع بقاء الطلبات السابقة سليمة.');

        $this->assertDatabaseHas('request_types', ['id' => $type->id]);
        $this->assertDatabaseHas('requests', [
            'id' => $requestRecord->id,
            'request_type_id' => $type->id,
        ]);
    }

    /**
     * workflow_transitions.request_type_id is cascadeOnDelete: without this
     * guard the delete would take the type's own workflow overrides with it.
     */
    public function test_a_type_with_workflow_overrides_cannot_be_deleted_and_the_rule_survives(): void
    {
        $type = RequestType::create(['code' => 'OVERRIDE', 'name_ar' => 'نوع بقاعدة خاصة']);

        $rule = WorkflowTransition::create([
            'request_type_id' => $type->id,
            'from_stage_id' => WorkflowStage::where('code', 'requirements_check')->value('id'),
            'to_stage_id' => WorkflowStage::where('code', 'reviewer_review')->value('id'),
            'action' => 'approve',
        ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/request-types/{$type->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن حذف نوع طلب له قواعد سير عمل خاصة به. احذف تلك القواعد أولاً أو عطّل النوع.');

        $this->assertDatabaseHas('request_types', ['id' => $type->id]);
        $this->assertDatabaseHas('workflow_transitions', ['id' => $rule->id]);
    }

    /**
     * [D] Appendix 57's matrix round-trips, and an all-blank condition is
     * stored as NULL rather than as a pair of empty strings — the frontend's
     * documentCondition() tests whether the key is set, so an empty pair would
     * render a blank qualifier chip on every intake checklist row.
     */
    public function test_the_required_documents_matrix_round_trips_and_normalises_a_blank_condition(): void
    {
        $created = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/request-types', [
                'name_ar' => 'نوع بمستندات',
                'required_documents' => [
                    ['ar' => 'صورة القرار', 'en' => 'Copy of the decision', 'group' => 'basic', 'condition' => null],
                    ['ar' => 'الإفادة المالية', 'en' => null, 'group' => 'specific', 'condition' => ['ar' => 'عند الحاجة', 'en' => 'If needed']],
                    // Both halves blank: must come back as null, not as {ar:'', en:''}.
                    ['ar' => 'مستند ثالث', 'en' => null, 'group' => 'specific', 'condition' => ['ar' => '', 'en' => '']],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.required_documents.0.group', 'basic')
            ->assertJsonPath('data.required_documents.1.condition.ar', 'عند الحاجة')
            ->assertJsonPath('data.required_documents.2.condition', null);

        $type = RequestType::findOrFail($created->json('data.id'));

        $this->assertCount(3, $type->required_documents);
        $this->assertNull($type->required_documents[2]['condition']);
    }

    /**
     * The «مطلوب» box is stored per row; a row sent without it keeps the old
     * reading (no condition = required), so an older client cannot quietly
     * make every document optional.
     */
    public function test_the_required_flag_round_trips_and_defaults_from_the_condition(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/request-types', [
                'name_ar' => 'نوع بعلامة الإلزام',
                'required_documents' => [
                    ['ar' => 'مستند غير مطلوب', 'group' => 'basic', 'condition' => null, 'required' => false],
                    ['ar' => 'مستند مشروط مطلوب', 'group' => 'basic', 'condition' => ['ar' => 'عند الحاجة'], 'required' => true],
                    ['ar' => 'مستند بلا علامة', 'group' => 'specific', 'condition' => null],
                    ['ar' => 'مستند مشروط بلا علامة', 'group' => 'specific', 'condition' => ['ar' => 'عند الحاجة']],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.required_documents.0.required', false)
            ->assertJsonPath('data.required_documents.1.required', true)
            ->assertJsonPath('data.required_documents.2.required', true)
            ->assertJsonPath('data.required_documents.3.required', false);
    }

    public function test_validation_rejects_a_duplicate_code_an_unknown_group_and_an_unknown_route(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/request-types', ['code' => 'PROM', 'name_ar' => 'مكرر'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/request-types', [
                'name_ar' => 'تصنيف غير معروف',
                'required_documents' => [['ar' => 'مستند', 'group' => 'conditional']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('required_documents.0.group');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/request-types', [
                'name_ar' => 'جهة غير معروفة',
                'default_administrative_route' => 'mayor',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_administrative_route');

        // A type keeping its own code is not a collision.
        $existing = RequestType::where('code', 'PROM')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/request-types/{$existing->id}", ['code' => 'PROM', 'name_ar' => 'ترقية'])
            ->assertOk();
    }

    /**
     * The screen is seeded with an empty grants entry, which in
     * ScreenRolePermissionSeeder means R08 only — the same shape every other
     * administration screen uses.
     */
    public function test_only_r08_may_reach_the_catalogue(): void
    {
        $type = RequestType::where('code', 'LEAV')->firstOrFail();

        // One from each corner of the matrix: intake, review, ministry.
        foreach (['R01', 'R02', 'R06'] as $roleCode) {
            $user = $this->userWithRole($roleCode);

            $this->actingAs($user, 'sanctum')->getJson('/api/request-types')->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/request-types', ['name_ar' => 'محاولة'])
                ->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->putJson("/api/request-types/{$type->id}", ['name_ar' => 'محاولة'])
                ->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->patchJson("/api/request-types/{$type->id}/toggle-active")
                ->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->deleteJson("/api/request-types/{$type->id}")
                ->assertForbidden();
        }

        $this->assertDatabaseHas('request_types', ['id' => $type->id, 'is_active' => true]);
    }

    public function test_saving_a_type_keeps_each_documents_folder(): void
    {
        // Workflow designer — slice 0. EmploymentFilePreparationService and
        // mandatorySubmitterDocuments() read `section`; a save from the
        // Request Types screen used to drop it, so HR's card silently lost
        // every service-file row.
        $type = RequestType::where('code', 'PROM')->firstOrFail();
        $documents = $type->required_documents;
        $serviceFileRows = collect($documents)->where('section', 'service_file')->count();
        $this->assertGreaterThan(0, $serviceFileRows);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/request-types/{$type->id}", [
                'code' => $type->code,
                'name_ar' => $type->name_ar,
                'required_documents' => $documents,
            ])
            ->assertOk();

        $this->assertSame(
            $serviceFileRows,
            collect($type->fresh()->required_documents)->where('section', 'service_file')->count(),
        );
    }

    public function test_an_unknown_document_folder_is_refused(): void
    {
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/request-types/{$type->id}", [
                'code' => $type->code,
                'name_ar' => $type->name_ar,
                'required_documents' => [['ar' => 'مستند', 'group' => 'basic', 'section' => 'nowhere']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['required_documents.0.section' => 'مجلد المستند غير معروف.']);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
