<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Department;
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

class TransactionIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_user_can_load_intake_options_before_the_transaction_wildcard_route(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/transactions/intake-options')
            ->assertOk()
            ->assertJsonPath('data.departments.0.code', 'ADM')
            ->assertJsonFragment(['code' => 'PROM']);
    }

    public function test_an_authorized_user_can_intake_a_transaction_with_attachments(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = TransactionType::where('code', 'PROM')->firstOrFail();

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/transactions', [
                'title' => 'طلب ترقية جديد',
                'description' => 'وصف طلب الترقية.',
                'department_id' => $department->id,
                'transaction_type_id' => $type->id,
                'decision_grade' => 11,
                'attachments' => [[
                    'file' => UploadedFile::fake()->create('promotion.pdf', 120, 'application/pdf'),
                    'label' => 'قرار الترقية',
                ]],
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.reference_number', now()->format('Y').'-ADM-000001')
            ->assertJsonPath('data.status.code', 'new')
            ->assertJsonPath('data.current_stage.order_no', 1);

        $transaction = Transaction::firstOrFail();
        $this->assertSame($admin->id, $transaction->created_by_user_id);
        $this->assertSame(11, $transaction->decision_grade);
        $this->assertNotNull($transaction->submitted_at);
        $this->assertSame(
            $transaction->submitted_at->copy()->startOfDay()->addDays($type->default_sla_days)->toDateString(),
            $transaction->due_date?->toDateString(),
        );
        $this->assertDatabaseHas('transaction_stage_logs', [
            'transaction_id' => $transaction->id,
            'to_stage_id' => WorkflowStage::where('order_no', 1)->value('id'),
            'action' => 'intake',
        ]);
        $this->assertDatabaseHas('transaction_status_history', [
            'transaction_id' => $transaction->id,
            'to_status_id' => TransactionStatus::where('code', 'new')->value('id'),
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
        $type = TransactionType::where('code', 'PROM')->firstOrFail();

        foreach ([1, 2] as $sequence) {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/transactions', [
                    'title' => "معاملة {$sequence}",
                    'department_id' => $department->id,
                    'transaction_type_id' => $type->id,
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
            ->postJson('/api/transactions', [
                'title' => 'معاملة بلا درجة',
                'department_id' => Department::where('code', 'ADM')->value('id'),
                'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision_grade');

        $this->assertDatabaseCount('transactions', 0);
    }
}
