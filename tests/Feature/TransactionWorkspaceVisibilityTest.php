<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Role;
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

class TransactionWorkspaceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_workspace_lists_own_submissions_and_currently_assigned_work_only(): void
    {
        $this->seed(DatabaseSeeder::class);

        $reviewer = $this->userWithRole('R02');
        $employee = $this->userWithRole('R01');
        $otherEmployee = $this->userWithRole('R01');
        $otherEmployee->manager_id = $this->userWithRole('R05')->id;
        $otherEmployee->save();

        $own = $this->transaction('direct_manager_review', 'in_review', $reviewer);
        $assigned = $this->transaction('requirements_check', 'in_review', $employee);
        $hidden = $this->transaction('direct_manager_review', 'in_review', $otherEmployee);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['id' => $own->id])
            ->assertJsonFragment(['id' => $assigned->id])
            ->assertJsonMissing(['id' => $hidden->id]);
    }

    public function test_an_unassigned_user_cannot_open_or_mutate_another_users_workspace(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        $reviewer = $this->userWithRole('R02');
        $owner = $this->userWithRole('R01');
        $owner->manager_id = $this->userWithRole('R05')->id;
        $owner->save();
        $transaction = $this->transaction('direct_manager_review', 'in_review', $owner);
        $attachment = Attachment::create([
            'transaction_id' => $transaction->id,
            'disk' => 'local',
            'path' => "attachments/{$transaction->id}/private.pdf",
            'original_name' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);
        Storage::disk('local')->put($attachment->path, 'private-preview');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/transactions/{$transaction->id}")
            ->assertNotFound();
        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/transactions/{$transaction->id}/notes")
            ->assertNotFound();
        $this->actingAs($reviewer, 'sanctum')
            ->post("/api/transactions/{$transaction->id}/attachments", [
                'file' => UploadedFile::fake()->create('blocked.pdf', 5, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->actingAs($reviewer, 'sanctum')
            ->get(route('transactions.attachments.preview', [
                'transaction' => $transaction,
                'attachment' => $attachment,
            ]))
            ->assertNotFound();
    }

    private function transaction(string $stageCode, string $statusCode, User $creator): Transaction
    {
        return Transaction::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'معاملة خصوصية مساحة العمل',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
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
