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

class TransactionListTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_user_can_filter_the_paginated_transaction_list(): void
    {
        $this->seed(DatabaseSeeder::class);

        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = TransactionType::where('code', 'PROM')->firstOrFail();
        $new = TransactionStatus::where('code', 'new')->firstOrFail();
        $inReview = TransactionStatus::where('code', 'in_review')->firstOrFail();
        $stage = WorkflowStage::where('order_no', 1)->firstOrFail();
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $matching = Transaction::create([
            'title' => 'طلب ترقية مطابق',
            'reference_number' => '2026-ADM-000001',
            'department_id' => $department->id,
            'transaction_type_id' => $type->id,
            'status_id' => $new->id,
            'current_stage_id' => $stage->id,
        ]);

        Transaction::create([
            'title' => 'معاملة بحالة أخرى',
            'reference_number' => '2026-ADM-000002',
            'department_id' => $department->id,
            'transaction_type_id' => $type->id,
            'status_id' => $inReview->id,
            'current_stage_id' => $stage->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/transactions?status=new&department_id={$department->id}&type_id={$type->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.status.code', 'new')
            ->assertJsonPath('data.0.department.code', 'ADM')
            ->assertJsonPath('data.0.transaction_type.code', 'PROM');
    }
}
