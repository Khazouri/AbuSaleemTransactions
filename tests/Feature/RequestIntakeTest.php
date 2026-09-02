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

class RequestIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_user_can_load_intake_options_before_the_request_wildcard_route(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/requests/intake-options')
            ->assertOk()
            ->assertJsonPath('data.departments.0.code', 'ADM')
            ->assertJsonFragment(['code' => 'PROM']);
    }

    public function test_intake_options_expose_the_stage_53_document_checklist_per_type(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/requests/intake-options')
            ->assertOk();

        $types = collect($response->json('data.types'));
        // Stage 53 — the 5 new types plus the 7 pre-existing ones, all active.
        $this->assertSame(12, $types->count());
        $this->assertEqualsCanonicalizing(
            ['PROM', 'LEAV', 'ALLW', 'SECD', 'GRIV', 'TRNS', 'EOSV', 'CONF', 'APPT', 'CTRC', 'SETL', 'PEVG'],
            $types->pluck('code')->all(),
        );

        $promotion = $types->firstWhere('code', 'PROM');
        $this->assertNotEmpty($promotion['required_documents']);
        $this->assertArrayHasKey('ar', $promotion['required_documents'][0]);
        $this->assertArrayHasKey('en', $promotion['required_documents'][0]);
    }

    public function test_an_authorized_user_can_intake_a_request_with_attachments(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/requests', [
                'title' => 'طلب ترقية جديد',
                'description' => 'وصف طلب الترقية.',
                'department_id' => $department->id,
                'request_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('promotion.pdf', 120, 'application/pdf'),
                    'label' => 'قرار الترقية',
                ]],
            ], ['Accept' => 'application/json']);

        // Diagram-alignment redesign (see AGENT_NOTES.md): intake now performs
        // one additional system hop into direct_manager_review in the same
        // request, so the request created here is never actually left
        // sitting at receive_from_municipality.
        $response->assertCreated()
            ->assertJsonPath('data.reference_number', now()->format('Y').'-ADM-000001')
            ->assertJsonPath('data.status.code', 'in_review')
            ->assertJsonPath('data.current_stage.code', 'direct_manager_review');

        $requestRecord = Request::firstOrFail();
        $this->assertSame($admin->id, $requestRecord->created_by_user_id);
        $this->assertSame(11, $requestRecord->decision_grade);
        $this->assertNotNull($requestRecord->submitted_at);
        $this->assertSame(
            $requestRecord->submitted_at->copy()->startOfDay()->addDays($type->default_sla_days)->toDateString(),
            $requestRecord->due_date?->toDateString(),
        );
        // The intake stage log at receive_from_municipality is still written
        // first — this proves the truthful origin point is preserved even
        // though the request has already moved on by the time this
        // response is read.
        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $requestRecord->id,
            'to_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'action' => 'intake',
        ]);
        $this->assertDatabaseHas('request_stage_logs', [
            'request_id' => $requestRecord->id,
            'from_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
            'to_stage_id' => WorkflowStage::where('code', 'direct_manager_review')->value('id'),
            'action' => 'submit',
            'acted_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'to_status_id' => RequestStatus::where('code', 'new')->value('id'),
        ]);
        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $requestRecord->id,
            'to_status_id' => RequestStatus::where('code', 'in_review')->value('id'),
        ]);

        $attachment = Attachment::firstOrFail();
        $this->assertSame('قرار الترقية', $attachment->label);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_references_increment_per_department_and_year(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'PROM')->firstOrFail();

        foreach ([1, 2] as $sequence) {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/requests', [
                    'title' => "طلب {$sequence}",
                    'department_id' => $department->id,
                    'request_type_id' => $type->id,
                    'decision_grade' => 9,
                ])
                ->assertCreated()
                ->assertJsonPath('data.reference_number', now()->format('Y').sprintf('-ADM-%06d', $sequence));
        }
    }

    public function test_decision_grade_is_required_when_the_type_has_a_ministry_threshold(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/requests', [
                'title' => 'طلب بلا درجة',
                'department_id' => Department::where('code', 'ADM')->value('id'),
                'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision_grade');

        $this->assertDatabaseCount('requests', 0);
    }
}
