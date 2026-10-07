<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * An attached file is named «<document name> - <employee number>» (user
 * decision 2026-10-07), and nobody files without an employee number.
 */
class AttachmentNamingTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_intake_names_each_file_after_its_document_and_the_employee_number(): void
    {
        $filer = $this->filer('1042');
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        $this->actingAs($filer, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب ترقية',
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [
                    ...$this->mandatoryAttachments($type),
                    [
                        'file' => UploadedFile::fake()->create('scan_0012.PDF', 20, 'application/pdf'),
                        'required_document_key' => RequestType::OTHER_DOCUMENT,
                        'label' => 'شهادة خبرة',
                    ],
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $names = Attachment::pluck('original_name', 'required_document_key');
        $firstMandatory = $this->mandatoryAttachments($type)[0]['required_document_key'];

        $this->assertSame(
            $type->documentOptions()[$firstMandatory]['ar'].' - 1042.pdf',
            $names[$firstMandatory],
        );
        $this->assertSame('شهادة خبرة - 1042.PDF', $names[RequestType::OTHER_DOCUMENT]);
    }

    public function test_intake_is_refused_until_the_filer_has_an_employee_number(): void
    {
        $filer = $this->filer(null);

        $this->actingAs($filer, 'sanctum')
            ->postJson('/api/requests', [
                'title' => 'طلب بلا رقم وظيفي',
                'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
                'decision_grade' => 11,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_number');

        $this->assertDatabaseCount('requests', 0);
    }

    public function test_a_later_upload_takes_its_label_or_the_generic_name(): void
    {
        $filer = $this->filer('77');
        $requestRecord = $this->returnedRequest($filer);

        $this->upload($filer, $requestRecord, 'إفادة')->assertCreated()
            ->assertJsonPath('data.original_name', 'إفادة - 77.pdf');
        $this->upload($filer, $requestRecord, null)->assertCreated()
            ->assertJsonPath('data.original_name', 'مستند آخر - 77.pdf');
    }

    public function test_a_later_upload_is_refused_while_the_employee_has_no_number(): void
    {
        $filer = $this->filer(null);

        $this->upload($filer, $this->returnedRequest($filer), 'إفادة')
            ->assertUnprocessable();

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_characters_a_filename_cannot_hold_are_removed(): void
    {
        $type = new RequestType(['required_documents' => [['ar' => 'قرار/تعيين: نسخة']]]);
        $key = array_key_first($type->documentOptions());

        $this->assertSame(
            'قرارتعيين نسخة - 5.png',
            Attachment::displayName($type, $key, null, new User(['employee_number' => '5']), 'a.png'),
        );
    }

    private function upload(User $actor, Request $requestRecord, ?string $label)
    {
        return $this->actingAs($actor, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('IMG_1.pdf', 20, 'application/pdf'),
                'required_document_key' => RequestType::OTHER_DOCUMENT,
                'file_section' => 'supporting_documents',
                'label' => $label,
            ], ['Accept' => 'application/json']);
    }

    private function returnedRequest(User $filer): Request
    {
        return Request::create([
            'title' => 'طلب معاد',
            'department_id' => $filer->department_id,
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'returned')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'created_by_user_id' => $filer->id,
            'submitted_at' => now(),
        ]);
    }

    private function filer(?string $employeeNumber): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'employee_number' => $employeeNumber,
        ]);
        $user->roles()->attach(Role::where('code', 'R01')->value('id'));

        return $user;
    }
}
