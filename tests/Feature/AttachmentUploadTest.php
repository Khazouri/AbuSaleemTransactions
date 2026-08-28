<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
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
        $transaction = $this->transaction();

        $response = $this->actingAs($admin, 'sanctum')
            ->post("/api/transactions/{$transaction->id}/attachments", [
                'file' => UploadedFile::fake()->create('supporting-document.pdf', 120, 'application/pdf'),
                'label' => 'المستند الداعم',
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.transaction_id', $transaction->id)
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
        $transaction = $this->transaction();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/transactions/{$transaction->id}/attachments", [
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
        $transaction = $this->transaction();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/transactions/{$transaction->id}/attachments", [
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
        $transaction = $this->transaction();
        $attachment = Attachment::create([
            'transaction_id' => $transaction->id,
            'disk' => 'local',
            'path' => "attachments/{$transaction->id}/preview.pdf",
            'original_name' => 'preview.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);
        Storage::disk('local')->put($attachment->path, 'pdf-preview');

        $this->actingAs($admin, 'sanctum')
            ->get(route('transactions.attachments.preview', [
                'transaction' => $transaction,
                'attachment' => $attachment,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename=preview.pdf');
    }

    public function test_preview_rejects_an_attachment_from_a_different_transaction(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $transaction = $this->transaction();
        $otherTransaction = $this->transaction();
        $attachment = Attachment::create([
            'transaction_id' => $otherTransaction->id,
            'disk' => 'local',
            'path' => "attachments/{$otherTransaction->id}/other.pdf",
            'original_name' => 'other.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);
        Storage::disk('local')->put($attachment->path, 'pdf-preview');

        $this->actingAs($admin, 'sanctum')
            ->get(route('transactions.attachments.preview', [
                'transaction' => $transaction,
                'attachment' => $attachment,
            ]))
            ->assertNotFound();
    }

    private function transaction(): Transaction
    {
        return Transaction::create([
            'title' => 'معاملة اختبار المرفقات',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', 'new')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
        ]);
    }
}
