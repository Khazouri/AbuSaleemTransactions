<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Transaction;
use App\Models\TransactionStatus;
use App\Models\TransactionType;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_user_can_add_and_read_transaction_notes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $transaction = Transaction::create([
            'title' => 'معاملة للملاحظات',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'transaction_type_id' => TransactionType::where('code', 'PROM')->value('id'),
            'status_id' => TransactionStatus::where('code', 'new')->value('id'),
            'current_stage_id' => WorkflowStage::where('order_no', 1)->value('id'),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/transactions/{$transaction->id}/notes", [
                'body' => 'تمت مراجعة المستندات المرفقة.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_id', $transaction->id)
            ->assertJsonPath('data.body', 'تمت مراجعة المستندات المرفقة.')
            ->assertJsonPath('data.created_by.id', $admin->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/transactions/{$transaction->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'تمت مراجعة المستندات المرفقة.');
    }
}
