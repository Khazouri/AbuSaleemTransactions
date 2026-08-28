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
use Tests\TestCase;

class RequestWorkspaceVisibilityTest extends TestCase
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

        $own = $this->request('direct_manager_review', 'in_review', $reviewer);
        $assigned = $this->request('requirements_check', 'in_review', $employee);
        $hidden = $this->request('direct_manager_review', 'in_review', $otherEmployee);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson('/api/requests')
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
        $requestRecord = $this->request('direct_manager_review', 'in_review', $owner);
        $attachment = Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => "attachments/{$requestRecord->id}/private.pdf",
            'original_name' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12,
        ]);
        Storage::disk('local')->put($attachment->path, 'private-preview');

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertNotFound();
        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/notes")
            ->assertNotFound();
        $this->actingAs($reviewer, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('blocked.pdf', 5, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->actingAs($reviewer, 'sanctum')
            ->get(route('requests.attachments.preview', [
                'requestRecord' => $requestRecord,
                'attachment' => $attachment,
            ]))
            ->assertNotFound();
    }

    private function request(string $stageCode, string $statusCode, User $creator): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب خصوصية مساحة العمل',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
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
