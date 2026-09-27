<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decision wizard, sub-project 2 — the committee's own moves on a file, as the
 * request payload offers them to the wizard. A move is offered only when its
 * endpoint would take it, so the list and the endpoint cannot disagree.
 */
class CommitteeRequestActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_rapporteur_is_offered_the_committee_moves_of_a_pending_file(): void
    {
        $file = $this->fileAt('registered');

        $this->assertSame(
            ['require_completion' => true, 'send_to_legal_review' => false],
            $this->committeeActions($this->userWithRole('R02'), $file),
        );
    }

    public function test_a_move_the_status_does_not_allow_is_omitted_and_the_endpoint_refuses_it(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $file = $this->fileAt('completion_required');

        $this->assertArrayNotHasKey('require_completion', $this->committeeActions($rapporteur, $file));

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/committee-candidates/{$file->id}/request-completion", ['comment' => 'ناقص'])
            ->assertStatus(422)
            ->assertJsonPath('errors.action.0', 'هذا الإجراء غير متاح في الحالة الراهنة للطلب.');
    }

    public function test_the_legal_member_is_offered_the_opinion_once_the_file_is_handed_over(): void
    {
        $rapporteur = $this->userWithRole('R02');
        $legal = $this->userWithRole('R11');
        $file = $this->fileAt('registered');

        // Stage 68 bounds the legal member's reach to files actually handed to
        // them (under_legal_review/execution_suspended or an existing review
        // record); before hand-over the file is still the rapporteur's alone.
        $this->actingAs($legal, 'sanctum')
            ->getJson("/api/requests/{$file->id}")
            ->assertNotFound();

        $this->actingAs($rapporteur, 'sanctum')
            ->postJson("/api/requests/{$file->id}/legal-reviews/request")
            ->assertOk();

        $this->assertSame(['record_legal_review' => false], $this->committeeActions($legal, $file));
        $this->assertArrayNotHasKey('send_to_legal_review', $this->committeeActions($rapporteur, $file));
    }

    public function test_committee_moves_are_not_offered_before_the_file_reaches_the_committee(): void
    {
        $file = $this->fileAt('in_review', 'requirements_check');

        $this->assertSame([], $this->committeeActions($this->userWithRole('R02'), $file));
    }

    /** @return array<string, bool> action => requires_comment */
    private function committeeActions(User $actor, Request $file): array
    {
        return collect($this->actingAs($actor->fresh(), 'sanctum')
            ->getJson("/api/requests/{$file->id}")
            ->assertOk()
            ->json('data.committee_actions'))
            ->mapWithKeys(fn (array $action) => [$action['action'] => $action['requires_comment']])
            ->all();
    }

    private function fileAt(string $statusCode, string $stageCode = 'receive_from_committee'): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب ترقية',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
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
