<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\RequestStageChangedNotification;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Only the filer attaches documents (Request::attachmentRight()), the direct
 * manager returns a request with a note, and the filer — whatever their role —
 * re-submits it.
 */
class FilerAttachmentsAndReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    public function test_only_the_filer_attaches_and_nobody_stands_in_for_them(): void
    {
        $filer = $this->userWithRole('R01');
        $requestRecord = $this->requestAt('receive_from_municipality', 'returned', $filer);

        $this->upload($filer, $requestRecord)->assertCreated();

        // The admin can see the file but, with no override, cannot add to it.
        $this->upload($this->userWithRole('R08'), $requestRecord)
            ->assertForbidden()
            ->assertJsonPath('message', 'إرفاق المستندات مقصور على مقدّم الطلب.');

        $this->assertSame(1, $requestRecord->attachments()->count());
    }

    public function test_the_filer_attaches_only_at_stage_one(): void
    {
        $filer = $this->userWithRole('R01');

        // Once the file is with the manager, the filer's documents are closed.
        $atManager = $this->requestAt('direct_manager_review', 'in_review', $filer);
        $this->upload($filer, $atManager)->assertForbidden();

        $this->actingAs($filer, 'sanctum')
            ->getJson("/api/requests/{$atManager->id}")
            ->assertOk()
            ->assertJsonPath('data.can_attach', false);
    }

    public function test_the_executing_body_attaches_proof_only_while_the_file_is_in_execution(): void
    {
        $filer = $this->userWithRole('R01');
        $executor = $this->userWithRole('R02');

        $executing = $this->requestAt('final_approval_archiving', 'in_execution', $filer);
        $this->upload($executor, $executing)->assertCreated();

        // Once executed, the window has closed.
        $executed = $this->requestAt('final_approval_archiving', 'executed', $filer);
        $this->upload($executor, $executed)->assertForbidden();
    }

    public function test_hr_attaches_service_file_rows_only_at_registration(): void
    {
        $filer = $this->userWithRole('R01');
        $hr = $this->userWithRole('R12');

        // At requirements_check HR co-owns the study (Stages 87/101; Stage 102
        // took observations off the path) and can open the file, but the
        // service-file exception is registration's alone.
        $requestRecord = $this->requestAt('requirements_check', 'in_review', $filer);

        $this->upload($hr, $requestRecord, $this->serviceFileKey($requestRecord))->assertForbidden();
    }

    public function test_the_manager_returns_with_a_note_and_the_on_behalf_filer_resubmits(): void
    {
        Notification::fake();

        $manager = $this->userWithRole('R03');
        $employee = $this->userWithRole('R01');
        $employee->update(['manager_id' => $manager->id]);
        // Filed on the employee's behalf (Stage 95): the clerk is the filer.
        $clerk = $this->userWithRole('R02');
        $bystander = $this->userWithRole('R01');

        $requestRecord = $this->requestAt('direct_manager_review', 'in_review', $clerk, $employee);

        // The manager can open the files under review...
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        // ...and must say why when sending it back.
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'return_to_employee'])
            ->assertUnprocessable();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", [
                'action' => 'return_to_employee',
                'comment' => 'يرجى إرفاق كشف الخدمة المحدّث.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'receive_from_municipality')
            ->assertJsonPath('data.status.code', 'returned');

        // The returned file is the filer's — not every employee's. The filer
        // hears of it as its owner; no other R01 is prompted to act on it.
        Notification::assertSentTo($clerk, RequestStageChangedNotification::class);
        Notification::assertNotSentTo($bystander, ActionRequiredNotification::class);
        $this->actingAs($bystander, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertNotFound();

        // The filer reads the manager's note, attaches the document and
        // re-submits, although they are not an R01.
        $this->actingAs($clerk, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk()
            ->assertJsonPath('data.can_attach', true)
            ->assertJsonFragment(['comment' => 'يرجى إرفاق كشف الخدمة المحدّث.'])
            ->assertJsonFragment(['available_actions' => ['submit', 'cancel']]);

        $this->upload($clerk, $requestRecord)->assertCreated();

        $this->actingAs($clerk, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'submit'])
            ->assertOk()
            ->assertJsonPath('data.current_stage.code', 'direct_manager_review');
    }

    public function test_nobody_but_the_filer_may_resubmit_a_returned_request(): void
    {
        $filer = $this->userWithRole('R01');
        $otherEmployee = $this->userWithRole('R01');
        $requestRecord = $this->requestAt('receive_from_municipality', 'returned', $filer);

        $this->actingAs($otherEmployee, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/transition", ['action' => 'submit'])
            ->assertNotFound();

        $this->assertSame('receive_from_municipality', $requestRecord->fresh()->currentStage->code);
    }

    private function upload(User $actor, Request $requestRecord, string $documentKey = 'other')
    {
        return $this->actingAs($actor, 'sanctum')
            ->post("/api/requests/{$requestRecord->id}/attachments", [
                'file' => UploadedFile::fake()->create('document.pdf', 20, 'application/pdf'),
                'required_document_key' => $documentKey,
                'file_section' => 'supporting_documents',
            ], ['Accept' => 'application/json']);
    }

    private function serviceFileKey(Request $requestRecord): string
    {
        return collect($requestRecord->requestType->documentOptions())
            ->filter(fn (array $document) => ($document['section'] ?? null) === 'service_file')
            ->keys()
            ->first();
    }

    private function requestAt(string $stageCode, string $statusCode, User $filer, ?User $subject = null): Request
    {
        return Request::create([
            'title' => 'طلب اختبار مقدّم الطلب',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $filer->id,
            'subject_user_id' => ($subject ?? $filer)->id,
            'submitted_at' => now(),
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
