<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\EmploymentFilePreparationService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TestRequestSeeder;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The maintenance console's «توليد طلبات تجريبية» button: five requests at
 * each stage a file can actually reach, each with the history, documents and
 * cards the real path would have left behind it.
 */
class TestRequestSeederTest extends TestCase
{
    use RefreshDatabase;

    /** The stage-log actions each stage's files carry, oldest first. */
    private const ACTIONS = [
        'receive_from_municipality' => ['intake', 'submit', 'return_to_employee'],
        'direct_manager_review' => ['intake', 'submit'],
        'receive_and_register' => ['intake', 'submit', 'forward'],
        'requirements_check' => ['intake', 'submit', 'forward', 'register'],
        'receive_from_committee' => ['intake', 'submit', 'forward', 'register', 'approve'],
        'approval_by_authority' => ['intake', 'submit', 'forward', 'register', 'approve', 'approve'],
        'local_governance_ministry' => ['intake', 'submit', 'forward', 'register', 'approve', 'approve'],
        'final_approval_archiving' => ['intake', 'submit', 'forward', 'register', 'approve', 'approve', 'approve'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->seed(TestUserSeeder::class);
    }

    public function test_every_request_carries_the_history_and_documents_of_its_stage(): void
    {
        $this->seed(TestRequestSeeder::class);

        $this->assertSame(count(TestRequestSeeder::STAGES) * 5, Request::count());

        $stageNo = 0;
        foreach (TestRequestSeeder::STAGES as $stageCode => $statusCode) {
            $stageNo++;
            $requests = Request::query()
                ->with('status', 'requestType', 'stageLogs', 'statusHistory', 'attachments', 'legalReviews')
                ->where('current_stage_id', WorkflowStage::where('code', $stageCode)->value('id'))
                ->get();

            $this->assertCount(5, $requests, $stageCode);
            foreach ($requests as $requestRecord) {
                $this->assertSame($statusCode, $requestRecord->status->code, $stageCode);
                $this->assertSame(self::ACTIONS[$stageCode], $requestRecord->stageLogs->sortBy('acted_at')->pluck('action')->values()->all(), $stageCode);
                $this->assertSame($statusCode, $requestRecord->statusHistory->sortBy('changed_at')->last()->toStatus->code, $stageCode);
                $this->assertFalse($requestRecord->stageLogs->max('acted_at')->isFuture(), $stageCode);
                $this->assertNotNull($requestRecord->entitled_grade);

                // The قيد number exists only once the file is registered (stage 5 here).
                $this->assertSame($stageNo >= 5, $requestRecord->reference_number !== null, $stageCode);
                $this->assertSame($stageNo >= 4, $requestRecord->employment_file !== null, $stageCode);
                $this->assertSame($stageNo >= 5, $requestRecord->intake_gate !== null, $stageCode);
                $this->assertSame($stageNo >= 6, $requestRecord->legalReviews->isNotEmpty(), $stageCode);

                // Every Appendix 57 row the file has reached is attached, and its file is on disk.
                $attachedKeys = $requestRecord->attachments->pluck('required_document_key')->filter()->all();
                foreach ($requestRecord->requestType->documentOptions() as $key => $document) {
                    $expected = $document['section'] !== EmploymentFilePreparationService::SECTION || $stageNo >= 3;
                    $this->assertSame($expected, in_array($key, $attachedKeys, true), "{$stageCode} {$document['ar']}");
                }
                foreach ($requestRecord->attachments as $attachment) {
                    Storage::disk('local')->assertExists($attachment->path);
                }
            }
        }

        // One completed meeting decided every file past the committee, with its محضر on disk.
        $meeting = Meeting::query()->with('agendaItems.decision')->sole();
        $this->assertSame('completed', $meeting->status);
        $this->assertCount(15, $meeting->agendaItems);
        $this->assertSame(['approve'], $meeting->agendaItems->pluck('decision.outcome')->unique()->values()->all());
        Storage::disk('local')->assertExists($meeting->minutes_file_path);

        $ministryFinals = Request::query()
            ->where('current_stage_id', WorkflowStage::where('code', 'final_approval_archiving')->value('id'))
            ->where('approving_authority', 'minister')
            ->withCount(['attachments' => fn ($q) => $q->where('label', 'قرار الوزير')])
            ->get();
        $this->assertNotEmpty($ministryFinals);
        $this->assertSame([1], $ministryFinals->pluck('attachments_count')->unique()->values()->all());
    }

    public function test_the_dean_can_decide_a_generated_file_from_its_meeting(): void
    {
        $this->seed(TestRequestSeeder::class);

        $requestRecord = Request::query()
            ->where('current_stage_id', WorkflowStage::where('code', 'approval_by_authority')->value('id'))
            ->firstOrFail();
        $item = MeetingRequest::where('request_id', $requestRecord->id)->sole();
        $dean = User::activeSeatHolder('dean');

        $this->actingAs($dean)
            ->postJson("/api/meetings/{$item->meeting_id}/agenda/{$item->id}/authority-decision", ['decision' => 'approve'])
            ->assertOk();

        $this->assertSame('final_approved', $requestRecord->fresh()->status->code);
    }

    public function test_a_missing_sole_post_writes_nothing(): void
    {
        User::activeSeatHolder('minister')->roles()->detach(Role::where('code', 'R06')->value('id'));

        $this->seed(TestRequestSeeder::class);

        $this->assertSame(0, Request::count());
    }

    public function test_the_retired_stages_get_nothing(): void
    {
        $this->seed(TestRequestSeeder::class);

        $retired = WorkflowStage::query()
            ->whereIn('code', ['administrative_routing', 'reviewer_review', 'observations', 'forward_to_committee'])
            ->pluck('id');

        $this->assertSame(0, Request::whereIn('current_stage_id', $retired)->count());
    }
}
