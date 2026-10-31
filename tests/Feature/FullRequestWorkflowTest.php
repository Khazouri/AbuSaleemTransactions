<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\PassesControlGates;
use Tests\TestCase;

/**
 * The whole request workflow, end to end, through the HTTP endpoints only and
 * as the actor each step belongs to (2026-10-30). Every other test starts a
 * file at the stage it is about; this one is what proves the stages still
 * hand over to each other: intake → manager → HR → قيد → legal → paper
 * meeting → جهة الاعتماد → the مقرر's review → HR's archive → closed.
 */
class FullRequestWorkflowTest extends TestCase
{
    use PassesControlGates;
    use RefreshDatabase;

    /** @var array<string, User> sole post => holder */
    private array $posts = [];

    private Committee $committee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $this->organise();
    }

    /** Dean path, with the manager's return, and the results rolled back and entered again. */
    public function test_a_dean_file_walks_from_intake_to_closure(): void
    {
        [$employee, $manager] = $this->employeeWithManager();
        $file = $this->file($employee);
        $this->assertAt($file, 'direct_manager_review', 'in_review');

        // The manager returns it (a note is optional), the employee re-submits.
        $this->transition($manager, $file, 'return_to_employee')->assertOk();
        $this->assertAt($file, 'receive_from_municipality', null);
        $this->transition($employee, $file, 'submit')->assertOk();
        $this->assertAt($file, 'direct_manager_review', 'in_review');

        $this->throughTheQaid($file, $manager);
        $this->assertNotNull($file->fresh()->reference_number);
        $this->legalReview($file, 'dean');

        $meeting = $this->scheduledMeetingWith([$file]);
        $item = $meeting->agendaItems()->sole();
        $this->results($meeting, [$item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean', 'note' => 'توصية بالموافقة']])->assertOk();
        $this->assertAt($file, 'approval_by_authority', null);

        // The مقرر takes the result back before العميد acts, and enters it again.
        $this->actingAs($this->posts['rapporteur'], 'sanctum')
            ->deleteJson("/api/meetings/{$meeting->id}/results")->assertOk();
        $this->assertAt($file, 'receive_from_committee', null);
        $this->assertSame('scheduled', $meeting->fresh()->status);
        $this->results($meeting, [$item->id => ['recommendation' => 'approve', 'approving_authority' => 'dean', 'note' => 'توصية بالموافقة']])->assertOk();

        $this->authorityDecides($this->posts['dean'], $meeting, [$item->id => ['decision' => 'approve', 'note' => 'يعتمد']])->assertOk();
        $this->assertAt($file, 'rapporteur_decision_review', 'authority_approved');

        // From the meeting page, as several files would be.
        $this->actingAs($this->posts['rapporteur'], 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/decision-confirmations", ['items' => [$item->id]])->assertOk();
        $this->assertAt($file, 'final_approval_archiving', 'final_approved');

        $this->archive($file);
        $this->assertAt($file, null, 'completed_closed');
        $this->assertNotNull($file->fresh()->closed_at);

        // The employee sees their own file closed, with العميد's قرار on it.
        $this->actingAs($employee, 'sanctum')->getJson("/api/requests/{$file->id}")
            ->assertOk()
            ->assertJsonPath('data.status.code', 'completed_closed')
            ->assertJsonPath('data.committee_summary.authority_decision.reference', 'ق/2026/1');
    }

    /** Minister path: الوزير approves, the مقرر returns it, الوزير rejects, and the rejection is final. */
    public function test_a_minister_file_returned_to_the_minister_ends_not_approved(): void
    {
        [$employee, $manager] = $this->employeeWithManager();
        $file = $this->file($employee);
        $this->throughTheQaid($file, $manager);
        $this->legalReview($file, 'minister');

        $meeting = $this->scheduledMeetingWith([$file]);
        $item = $meeting->agendaItems()->sole();
        $this->results($meeting, [$item->id => ['recommendation' => 'approve', 'approving_authority' => 'minister', 'note' => 'يرفع للوزير']])->assertOk();
        $this->assertAt($file, 'local_governance_ministry', null);

        // العميد is not this file's authority.
        $this->authorityDecides($this->posts['dean'], $meeting, [$item->id => ['decision' => 'approve']])->assertStatus(404);

        $this->authorityDecides($this->posts['minister'], $meeting, [$item->id => ['decision' => 'approve', 'note' => 'وافق الوزير']])->assertOk();
        $this->assertAt($file, 'rapporteur_decision_review', 'authority_approved');

        $this->transition($this->posts['rapporteur'], $file, 'return_to_authority')->assertStatus(422);
        $this->transition($this->posts['rapporteur'], $file, 'return_to_authority', 'القرار لا يطابق المحضر')->assertOk();
        $this->assertAt($file, 'local_governance_ministry', 'awaiting_central_approval');

        $this->authorityDecides($this->posts['minister'], $meeting, [$item->id => ['decision' => 'reject']], reference: 'ق/2026/2')
            ->assertStatus(422)->assertJsonValidationErrors("items.{$item->id}.note");
        $this->authorityDecides($this->posts['minister'], $meeting, [$item->id => ['decision' => 'reject', 'note' => 'لا تتوفر الدرجة']], reference: 'ق/2026/2')->assertOk();
        $this->assertAt($file, 'rapporteur_decision_review', 'authority_rejected');

        $this->transition($this->posts['rapporteur'], $file, 'confirm_decision')->assertOk();
        $this->assertAt($file, 'final_approval_archiving', 'not_approved');

        $this->archive($file);
        $this->assertAt($file, null, 'completed_closed');
    }

    /** A deferral sends the file back to the pending list and onto the next meeting. */
    public function test_a_deferred_file_is_decided_at_the_next_meeting(): void
    {
        // Two employees: one may not hold two open files of the same type (Appendix 16).
        [$employee, $manager] = $this->employeeWithManager();
        [$colleague] = $this->employeeWithManager($manager);
        $deferred = $this->file($employee);
        $decided = $this->file($colleague, 'طلب ترقية ثان');
        foreach ([$deferred, $decided] as $file) {
            $this->throughTheQaid($file, $manager);
            $this->legalReview($file, 'dean');
        }

        $first = $this->scheduledMeetingWith([$deferred, $decided]);
        [$deferItem, $approveItem] = $first->agendaItems()->orderBy('agenda_order')->get()->all();
        $this->results($first, [
            $deferItem->id => ['recommendation' => 'defer', 'note' => 'تستكمل الإفادة'],
            $approveItem->id => ['recommendation' => 'approve', 'approving_authority' => 'dean', 'note' => 'موافقة'],
        ])->assertOk();
        $this->assertAt($deferred, 'receive_from_committee', 'deferred');
        $this->assertSame('completed', $first->fresh()->status);

        // Deferred files lead the pending list.
        $candidates = $this->actingAs($this->posts['rapporteur'], 'sanctum')
            ->getJson('/api/committee-candidates')->assertOk()->json('data');
        $this->assertSame($deferred->id, $candidates[0]['id']);

        $second = $this->scheduledMeetingWith([$deferred]);
        $item = $second->agendaItems()->sole();
        $this->results($second, [$item->id => ['recommendation' => 'decline', 'approving_authority' => 'dean', 'note' => 'عدم موافقة']])->assertOk();
        $this->assertAt($deferred, 'approval_by_authority', null);

        $this->authorityDecides($this->posts['dean'], $second, [$item->id => ['decision' => 'reject', 'note' => 'وفق توصية اللجنة']])->assertOk();
        $this->transition($this->posts['rapporteur'], $deferred, 'confirm_decision')->assertOk();
        $this->assertAt($deferred, 'final_approval_archiving', 'not_approved');
        $this->archive($deferred);
        $this->assertAt($deferred, null, 'completed_closed');
    }

    // --- the organisation --------------------------------------------------

    /**
     * R08 names the مقرر, العميد and الوزير; the مقرر names the other four
     * seats and forms the committee, which seats them all.
     */
    private function organise(): void
    {
        $admin = $this->member('R08');
        foreach (['rapporteur', 'dean', 'minister'] as $post) {
            $this->posts[$post] = $this->member();
            $this->actingAs($admin, 'sanctum')
                ->putJson("/api/committee-roles/{$post}", ['user_id' => $this->posts[$post]->id])->assertOk();
        }
        foreach (['chair', 'legal', 'hr_director', 'ministry_delegate'] as $seat) {
            $this->posts[$seat] = $this->member();
            $this->actingAs($this->posts['rapporteur'], 'sanctum')
                ->putJson("/api/committee-roles/{$seat}", ['user_id' => $this->posts[$seat]->id])->assertOk();
        }

        $id = $this->actingAs($this->posts['rapporteur'], 'sanctum')
            ->postJson('/api/committees', ['name_ar' => 'لجنة شؤون الموظفين'])
            ->assertCreated()->json('data.id');
        $this->committee = Committee::findOrFail($id);
        $this->assertSame(5, $this->committee->members()->whereNotNull('seat')->count());

        // A User memoises its screen grants, and actingAs() keeps handing the
        // same instance back, so reload the holders now that they sit.
        $this->posts = array_map(fn (User $holder): User => $holder->fresh(), $this->posts);
    }

    /** @return array{0: User, 1: User} */
    private function employeeWithManager(?User $manager = null): array
    {
        $manager ??= $this->member();
        $employee = $this->member();
        $employee->update(['manager_id' => $manager->id]);

        return [$employee->fresh(), $manager];
    }

    private function file(User $employee, string $title = 'طلب ترقية'): Request
    {
        $type = RequestType::where('code', 'PROM')->firstOrFail();
        $id = $this->actingAs($employee, 'sanctum')->post('/api/requests', [
            'title' => $title,
            'request_type_id' => $type->id,
            'decision_grade' => 5,
            'entitled_grade' => 6,
            'grade_date' => '2024-03-01',
            'attachments' => $this->mandatoryAttachments($type),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return Request::findOrFail($id);
    }

    // --- the stages before the committee -------------------------------------

    /** The manager forwards, HR prepares and registers, the مقرر takes the قيد. */
    private function throughTheQaid(Request $file, User $manager): void
    {
        $this->transition($manager, $file, 'forward')->assertOk();
        $this->assertAt($file, 'receive_and_register', 'routed_to_hr');

        // R12 is a sole post (the HR director's seat), so HR's desk is its holder.
        $hr = $this->posts['hr_director'];
        $this->actingAs($hr, 'sanctum')->patchJson("/api/requests/{$file->id}/employment-file", [
            'documents' => $this->previewAll($hr, $file),
            'assembled' => true,
            'grade_verified' => true,
        ])->assertOk()->assertJsonPath('data.control_gates.employment_file.refusal', null);
        $this->transition($hr, $file, 'register')->assertOk();
        $this->assertAt($file, 'requirements_check', 'in_review');

        // One card: Art. 45's test and بوابة 1 together (2026-10-31).
        $rapporteur = $this->posts['rapporteur'];
        $this->actingAs($rapporteur, 'sanctum')->patchJson("/api/requests/{$file->id}/intake-gate", [
            'documents' => $this->previewAll($rapporteur, $file),
            'facts_verified' => true,
            'jurisdiction_test' => [
                'has_legal_basis' => true,
                'employee_covered' => true,
                'within_municipal_jurisdiction' => true,
                'committee_decides' => true,
                'final_approval_authority' => 'عميد البلدية',
                'requires_central_approval' => false,
            ],
        ])->assertOk()->assertJsonPath('data.control_gates.intake.refusal', null);
        $this->transition($rapporteur, $file, 'approve')->assertOk();
        // The قيد hands the file to the legal member itself.
        $this->assertAt($file, 'receive_from_committee', 'under_legal_review');
    }

    private function legalReview(Request $file, string $authority): void
    {
        $this->actingAs($this->posts['legal'], 'sanctum')
            ->postJson("/api/requests/{$file->id}/legal-reviews", [
                'recommendation' => 'approve',
                'approving_authority' => $authority,
                'legal_note' => 'سليم',
            ])->assertSuccessful();
        $this->assertAt($file, 'receive_from_committee', 'ready');
    }

    /** @return array<int, string> attachment id => approved, each one previewed first */
    private function previewAll(User $reviewer, Request $file): array
    {
        $verdicts = [];
        foreach (Attachment::where('request_id', $file->id)->pluck('id') as $id) {
            $this->actingAs($reviewer, 'sanctum')
                ->postJson("/api/requests/{$file->id}/attachments/{$id}/previewed")->assertNoContent();
            $verdicts[$id] = 'approved';
        }

        return $verdicts;
    }

    // --- the paper meeting ---------------------------------------------------

    /**
     * The مقرر proposes a date, two more seats accept (Settings' default of
     * three counts the مقرر's own yes), the files go on the agenda, and the
     * day arrives.
     *
     * @param  list<Request>  $files
     */
    private function scheduledMeetingWith(array $files): Meeting
    {
        $rapporteur = $this->posts['rapporteur'];
        $id = $this->actingAs($rapporteur, 'sanctum')->postJson('/api/meetings', [
            'committee_id' => $this->committee->id,
            'title' => 'الاجتماع الشهري',
            'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('data.status', 'pending_confirmation')->json('data.id');

        $this->actingAs($this->posts['chair'], 'sanctum')
            ->postJson("/api/meetings/{$id}/respond", ['response' => 'accept'])->assertOk();
        $this->actingAs($this->posts['legal'], 'sanctum')
            ->postJson("/api/meetings/{$id}/respond", ['response' => 'accept'])->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        foreach ($files as $file) {
            $this->actingAs($rapporteur, 'sanctum')
                ->postJson("/api/meetings/{$id}/agenda", ['request_id' => $file->id])->assertSuccessful();
        }

        $this->travel(2)->days();

        return Meeting::findOrFail($id);
    }

    /** @param  array<int, array<string, string>>  $items */
    private function results(Meeting $meeting, array $items): TestResponse
    {
        return $this->actingAs($this->posts['rapporteur'], 'sanctum')->post("/api/meetings/{$meeting->id}/results", [
            'items' => $items,
            'minutes_pages' => [UploadedFile::fake()->image('محضر.jpg')],
        ], ['Accept' => 'application/json']);
    }

    /** @param  array<int, array<string, string>>  $items */
    private function authorityDecides(User $authority, Meeting $meeting, array $items, string $reference = 'ق/2026/1'): TestResponse
    {
        return $this->actingAs($authority, 'sanctum')->post("/api/meetings/{$meeting->id}/authority-decisions", [
            'items' => $items,
            'reference' => $reference,
            'decided_on' => now()->toDateString(),
            'pages' => [UploadedFile::fake()->image('قرار.jpg')],
        ], ['Accept' => 'application/json']);
    }

    // --- after the decision ----------------------------------------------------

    private function archive(Request $file): void
    {
        $this->actingAs($this->posts['hr_director'], 'sanctum')
            ->patchJson("/api/requests/{$file->id}/archive/service-file", ['location' => 'ملف الخدمة'])
            ->assertOk();
    }

    // --- helpers -------------------------------------------------------------

    private function transition(User $actor, Request $file, string $action, ?string $comment = null): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson("/api/requests/{$file->id}/transition", array_filter(['action' => $action, 'comment' => $comment]));
    }

    /** A null stage or status is not checked. */
    private function assertAt(Request $file, ?string $stage, ?string $status): void
    {
        $file->refresh();
        if ($stage !== null) {
            $this->assertSame($stage, $file->currentStage?->code, "stage of {$file->title}");
        }
        if ($status !== null) {
            $this->assertSame($status, $file->status?->code, "status of {$file->title}");
        }
    }

    /** Every account starts as R01 and belongs to a unit. */
    private function member(string $roleCode = 'R01'): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'department_id' => Department::where('code', 'ADM')->value('id'),
        ]);
        $user->roles()->attach(Role::whereIn('code', array_unique(['R01', $roleCode]))->pluck('id'));

        return $user;
    }
}
