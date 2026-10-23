<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The managed documents list (قائمة المستندات) a request type picks its
 * Appendix 57 rows from (user decision 2026-10-23). Each entry's id is the
 * permanent number the Request Types screen shows; a type row keeps its own
 * copy of the entry's wording and points back with `document_id`.
 */
class DocumentCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_every_seeded_document_row_points_at_one_catalogue_entry(): void
    {
        foreach (RequestType::all() as $type) {
            foreach ($type->required_documents as $row) {
                $document = Document::find($row['document_id'] ?? null);

                $this->assertNotNull($document, "{$type->code}: «{$row['ar']}» has no catalogue entry");
                $this->assertSame($row['ar'], $document->name_ar);
            }
        }

        // The shared basics are listed once, not once per type.
        $this->assertSame(1, Document::where('name_ar', 'البيانات الوظيفية')->count());
    }

    public function test_the_catalogue_rides_on_the_request_types_payload(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/request-types')
            ->assertOk()
            ->assertJsonPath('documents.0.id', Document::orderBy('id')->value('id'))
            ->assertJsonStructure(['documents' => [['id', 'name_ar', 'name_en', 'group', 'condition_ar', 'condition_en', 'required', 'is_active']]]);
    }

    public function test_an_admin_adds_edits_and_deactivates_an_entry(): void
    {
        $admin = $this->admin();

        $id = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/documents', [
                'name_ar' => 'شهادة خبرة',
                'name_en' => 'Experience certificate',
                'group' => 'specific',
                'condition_ar' => 'عند الحاجة',
                'required' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/documents/{$id}", ['name_ar' => 'شهادة الخبرة', 'group' => 'basic', 'required' => true])
            ->assertOk()
            ->assertJsonPath('data.name_ar', 'شهادة الخبرة')
            ->assertJsonPath('data.required', true);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/documents/{$id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_an_entry_is_validated_in_arabic(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/documents', ['name_ar' => 'البيانات الوظيفية', 'group' => 'other'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name_ar' => 'يوجد مستند بهذا الاسم في القائمة.',
                'group' => 'تصنيف المستند غير معروف.',
            ]);
    }

    public function test_only_a_holder_of_the_request_types_grant_may_write(): void
    {
        $employee = User::factory()->create(['is_active' => true]);
        $employee->roles()->attach(Role::where('code', 'R01')->value('id'));

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/documents', ['name_ar' => 'مستند', 'group' => 'basic'])
            ->assertForbidden();
    }

    public function test_a_type_row_must_name_one_catalogue_entry_once(): void
    {
        $document = Document::firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/request-types', [
                'name_ar' => 'نوع بلا رقم',
                'required_documents' => [
                    ['ar' => 'مستند يدوي', 'group' => 'basic'],
                    ['document_id' => $document->id, 'ar' => $document->name_ar, 'group' => 'basic'],
                    ['document_id' => $document->id, 'ar' => $document->name_ar, 'group' => 'basic'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'required_documents.0.document_id' => 'اختر المستند من قائمة المستندات.',
                'required_documents.1.document_id' => 'المستند مكرر في هذا النوع.',
            ]);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/request-types', [
                'name_ar' => 'نوع برقم',
                'required_documents' => [['document_id' => $document->id, 'ar' => $document->name_ar, 'group' => 'basic']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.required_documents.0.document_id', $document->id);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@abusaleem.test')->firstOrFail();
    }
}
