<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\LegalReviewNotification;
use App\Services\CommitteeStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 2026-10-19 (user decision) — the legal member hears when the مقرر hands
 * them a file, and the مقرر hears when the legal member's review is in.
 */
class LegalReviewNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legal_member_hears_of_a_hand_over_and_the_rapporteur_of_the_review(): void
    {
        $this->seed(DatabaseSeeder::class);
        Notification::fake();
        $rapporteur = $this->soleHolder('R02');
        $legal = $this->soleHolder('R11');
        $requestRecord = $this->committeeRequest();
        $committeeStatus = app(CommitteeStatusService::class);

        $committeeStatus->move($requestRecord, 'send_to_legal_review', $rapporteur);

        Notification::assertSentTo($legal, LegalReviewNotification::class,
            fn (LegalReviewNotification $n) => $n->toArray($legal)['title_en'] === 'A request awaits your legal recommendation');
        Notification::assertNotSentTo($rapporteur, LegalReviewNotification::class);

        $committeeStatus->move($requestRecord, 'pass_legal_review', $legal);

        Notification::assertSentTo($rapporteur, LegalReviewNotification::class,
            fn (LegalReviewNotification $n) => $n->toArray($rapporteur)['title_en'] === 'The legal member recorded a recommendation');
        Notification::assertSentToTimes($legal, LegalReviewNotification::class, 1);
    }

    /** The holder of a sole post: the seeded one, or a fresh account when none is seeded. */
    private function soleHolder(string $roleCode): User
    {
        $holder = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('code', $roleCode))
            ->orderBy('id')
            ->first();

        if ($holder === null) {
            $holder = User::factory()->create(['is_active' => true]);
            $holder->roles()->attach(Role::where('code', $roleCode)->value('id'));
        }

        return $holder;
    }

    private function committeeRequest(): Request
    {
        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(100000, 999999),
            'title' => 'طلب أمام اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'ready')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
            'decision_grade' => 10,
        ]);
    }
}
