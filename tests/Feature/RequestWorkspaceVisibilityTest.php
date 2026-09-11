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
                // Stage 80 — a valid payload, deliberately: the point of this
                // case is the 404 from RequestVisibility, and an invalid one
                // would 422 in the FormRequest before that check ever runs.
                'file_section' => 'supporting_documents',
            ], ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->actingAs($reviewer, 'sanctum')
            ->get(route('requests.attachments.preview', [
                'requestRecord' => $requestRecord,
                'attachment' => $attachment,
            ]))
            ->assertNotFound();
    }

    /**
     * Stage 83 — the other half of the same rule, stated rather than left
     * implicit, because this stage deliberately changed it.
     *
     * [D] Appendices 30, 31, 53, 60 and 68 all address المقرر, and their
     * records span a file's whole post-قيد life — so once Art. 20's رقم إشاري
     * has been granted, a `meeting_outputs,edit` holder can open the file
     * whether or not they hold a workflow role at its current stage. Before the
     * قيد nothing changes: Art. 15 is explicit that the matter is not the
     * committee's yet, and the test above proves it stays private.
     */
    public function test_the_rapporteur_can_open_a_registered_file_but_not_one_still_in_intake(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rapporteur = $this->userWithRole('R02');
        $owner = $this->userWithRole('R01');
        $owner->manager_id = $this->userWithRole('R05')->id;
        $owner->save();

        $inIntake = $this->request('direct_manager_review', 'in_review', $owner);
        $registered = $this->request('receive_from_committee', 'ready', $owner);
        $registered->reference_number = 'PM-COM/'.now()->format('Y').'/0001';
        $registered->save();

        $this->actingAs($rapporteur, 'sanctum')
            ->getJson("/api/requests/{$inIntake->id}")
            ->assertNotFound();

        $this->actingAs($rapporteur, 'sanctum')
            ->getJson("/api/requests/{$registered->id}")
            ->assertOk();
    }

    /**
     * Stage 83 — the reference number is now deliberately null.
     *
     * Every request in this file sits in the intake half (before Art. 20's
     * قيد), and since Stage 70 such a request genuinely has no رقم إشاري: it
     * is minted only on the `requirements_check → approve` hop. The old
     * fixture minted one anyway, in the `YYYY-DEPT-NNNNNN` scheme Stage 70
     * deleted the generator for — a state real data cannot be in. That matters
     * here because Stage 83 bounds the rapporteur's own reach by exactly that
     * line, so a fixture with an impossible reference would have made this test
     * assert privacy for a request the system would never actually produce.
     */
    private function request(string $stageCode, string $statusCode, User $creator): Request
    {
        return Request::create([
            'reference_number' => null,
            'intake_receipt_number' => 'PM-RCV/'.now()->format('Y').'/'.fake()->unique()->numberBetween(100000, 999999),
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
