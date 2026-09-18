<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_user_can_upload_an_allowed_attachment(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $requestRecord = $this->request();

        $response = $this->actingAs($admin, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('supporting-document.pdf', 120, 'application/pdf'),
                'label' => 'المستند الداعم',
                // Stage 91 — every upload names which [D] Appendix 57 row it
                // answers. This file answers none, so `other` is the explicit
                // answer, and Appendix 14's folder is then a real question.
                'required_document_key' => 'other',
                // Stage 80 — Appendix 14 requires every new upload to name the
                // folder it is filed under.
                'file_section' => 'supporting_documents',
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.request_id', $requestRecord->id)
            ->assertJsonPath('data.original_name', 'supporting-document.pdf')
            ->assertJsonPath('data.size_bytes', 122880)
            ->assertJsonPath('data.label', 'المستند الداعم');

        $attachment = Attachment::firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_it_rejects_disallowed_file_types(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $requestRecord = $this->request();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('not-allowed.txt', 5, 'text/plain'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_it_rejects_files_larger_than_twenty_megabytes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $requestRecord = $this->request();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('too-large.pdf', 20481, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_an_authorized_user_can_preview_a_private_attachment_inline(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $requestRecord = $this->request();
        $attachment = Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => "attachments/{$requestRecord->id}/preview.pdf",
            'original_name' => 'preview.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);
        Storage::disk('local')->put($attachment->path, 'pdf-preview');

        $this->actingAs($admin, 'sanctum')
            ->get(route('requests.attachments.preview', [
                'requestRecord' => $requestRecord,
                'attachment' => $attachment,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename=preview.pdf');
    }

    public function test_preview_rejects_an_attachment_from_a_different_request(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $requestRecord = $this->request();
        $otherRequest = $this->request();
        $attachment = Attachment::create([
            'request_id' => $otherRequest->id,
            'disk' => 'local',
            'path' => "attachments/{$otherRequest->id}/other.pdf",
            'original_name' => 'other.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);
        Storage::disk('local')->put($attachment->path, 'pdf-preview');

        $this->actingAs($admin, 'sanctum')
            ->get(route('requests.attachments.preview', [
                'requestRecord' => $requestRecord,
                'attachment' => $attachment,
            ]))
            ->assertNotFound();
    }

    private function request(): Request
    {
        return Request::create([
            'title' => 'طلب اختبار المرفقات',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'new')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
        ]);
    }
}
