<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\NotificationSetting;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Notifications\RequestReferenceAssignedNotification;
use App\Services\WorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The submitter is told when the قيد (المقرر's approve out of the completeness
 * check, Art. 20) replaces their intake receipt with the committee's رقم إشاري.
 *
 * The notice is driven by the ALLOCATION, not by a stage or an action — see
 * WorkflowService::transition() — so these tests pin the property that
 * actually matters: it fires on whichever hop mints the number, and exactly
 * once in a request's life.
 */
class ReferenceAssignedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * The قيد itself: المقرر passes the completeness check, and the submitter
     * is told their receipt number has been superseded — with both numbers
     * named, since the receipt is the only one they were holding.
     */
    public function test_the_registration_tells_the_submitter_the_reference_changed(): void
    {
        Notification::fake();

        $employee = $this->userWithRole('R01');
        $registrar = $this->userWithRole('R02');
        $requestRecord = $this->requestAt('requirements_check', 'in_review', $employee, 'PM-RCV/2026/000042');

        app(WorkflowService::class)->transition($requestRecord, 'approve', $registrar);
        $requestRecord->refresh();

        $this->assertNotNull($requestRecord->reference_number);

        Notification::assertSentTo(
            $employee,
            RequestReferenceAssignedNotification::class,
            function ($notification, array $channels) use ($requestRecord, $employee) {
                $payload = $notification->toArray($employee);

                return $channels === ['database', 'mail']
                    && $payload['reference_number'] === $requestRecord->reference_number
                    && $payload['intake_receipt_number'] === 'PM-RCV/2026/000042'
                    // Both numbers in the body: without the old one the reader
                    // cannot connect the notice to the slip they hold.
                    && str_contains($payload['body_ar'], 'PM-RCV/2026/000042')
                    && str_contains($payload['body_ar'], $requestRecord->reference_number)
                    && str_contains($payload['body_en'], 'PM-RCV/2026/000042')
                    // Not one of Art. 101's twelve moments.
                    && $payload['moment'] === null;
            },
        );

        // The registrar acted; nobody tells them what they just did.
        Notification::assertNotSentTo($registrar, RequestReferenceAssignedNotification::class);
    }

    /**
     * Art. 99 gives a request one number for life, so this is news exactly
     * once. The receiving body's acceptance is NOT the قيد and announces
     * nothing; only the completeness hop does.
     */
    public function test_the_notice_fires_once_and_not_on_the_receiving_bodys_acceptance(): void
    {
        Notification::fake();

        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestAt('receive_and_register', 'routed_to_hr', $employee);

        $service = app(WorkflowService::class);
        $service->transition($requestRecord, 'register', $this->userWithRole('R12'));
        Notification::assertNotSentTo($employee, RequestReferenceAssignedNotification::class);

        $service->transition($requestRecord->refresh(), 'approve', $this->userWithRole('R02'));

        Notification::assertSentToTimes($employee, RequestReferenceAssignedNotification::class, 1);
    }

    /**
     * A hop that mints nothing announces nothing — which is what keeps this
     * from becoming a second "your request moved" notice on every transition.
     * The creator still gets the ordinary stage-changed message, so this
     * asserts on the class rather than on silence.
     */
    public function test_a_hop_that_mints_no_number_announces_nothing(): void
    {
        Notification::fake();

        $employee = $this->userWithRole('R01');
        $manager = User::factory()->create(['is_active' => true]);
        $employee->manager_id = $manager->id;
        $employee->save();

        $requestRecord = $this->requestAt('direct_manager_review', 'in_review', $employee);

        app(WorkflowService::class)->transition($requestRecord, 'forward', $manager);

        $this->assertNull($requestRecord->refresh()->reference_number);
        Notification::assertNotSentTo($employee, RequestReferenceAssignedNotification::class);
    }

    /** Muting the event delivers nothing, like every other Stage 23 event. */
    public function test_a_muted_event_delivers_nothing(): void
    {
        Notification::fake();

        $employee = $this->userWithRole('R01');
        NotificationSetting::create([
            'user_id' => $employee->id,
            'event_type' => 'reference_assigned',
            'in_app' => false,
            'email' => false,
            'sms' => false,
        ]);

        $requestRecord = $this->requestAt('requirements_check', 'in_review', $employee);

        app(WorkflowService::class)->transition($requestRecord, 'approve', $this->userWithRole('R02'));

        $this->assertNotNull($requestRecord->refresh()->reference_number);
        Notification::assertNotSentTo($employee, RequestReferenceAssignedNotification::class);
    }

    /**
     * The request's own notice register answers "what was this employee told",
     * so it lists this alongside Art. 101's moments — driven through real
     * delivery, not a fake, since the register reads stored `notifications`.
     */
    public function test_the_notice_reaches_the_requests_own_notice_register(): void
    {
        $employee = $this->userWithRole('R01');
        $requestRecord = $this->requestAt('requirements_check', 'in_review', $employee, 'PM-RCV/2026/000007');

        app(WorkflowService::class)->transition($requestRecord, 'approve', $this->userWithRole('R02'));

        $response = $this->actingAs($employee, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}")
            ->assertOk();

        $notices = collect($response->json('data.employee_notices'));
        $assigned = $notices->firstWhere('event_type', 'reference_assigned');

        $this->assertNotNull($assigned, 'the reference-assigned notice is missing from the register');
        $this->assertNull($assigned['moment_number']);
        $this->assertStringContainsString('PM-RCV/2026/000007', $assigned['body_ar']);
        // Art. 101's own moment 1 is in there too, and deliberately separate.
        $this->assertNotNull($notices->firstWhere('event_type', 'request_notice'));
    }

    private function requestAt(string $stageCode, string $statusCode, User $creator, ?string $receipt = null): Request
    {
        return Request::create([
            'title' => 'طلب اختبار الإشعار بالرقم المرجعي',
            'intake_receipt_number' => $receipt,
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', $statusCode)->value('id'),
            'current_stage_id' => WorkflowStage::where('code', $stageCode)->value('id'),
            'created_by_user_id' => $creator->id,
            'submitted_at' => now(),
            'decision_grade' => 9,
            'jurisdiction_test' => [
                'has_legal_basis' => true,
                'employee_covered' => true,
                'within_municipal_jurisdiction' => true,
                'committee_decides' => true,
                'final_approval_authority' => 'عميد البلدية',
                'requires_central_approval' => false,
            ],
        ]);
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
