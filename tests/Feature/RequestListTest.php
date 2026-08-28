<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestListTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_user_can_filter_the_paginated_request_list(): void
    {
        $this->seed(DatabaseSeeder::class);

        $department = Department::where('code', 'ADM')->firstOrFail();
        $type = RequestType::where('code', 'PROM')->firstOrFail();
        $new = RequestStatus::where('code', 'new')->firstOrFail();
        $inReview = RequestStatus::where('code', 'in_review')->firstOrFail();
        $stage = WorkflowStage::where('code', 'receive_from_municipality')->firstOrFail();
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();

        $matching = Request::create([
            'title' => 'طلب ترقية مطابق',
            'reference_number' => '2026-ADM-000001',
            'department_id' => $department->id,
            'request_type_id' => $type->id,
            'status_id' => $new->id,
            'current_stage_id' => $stage->id,
        ]);

        Request::create([
            'title' => 'طلب بحالة أخرى',
            'reference_number' => '2026-ADM-000002',
            'department_id' => $department->id,
            'request_type_id' => $type->id,
            'status_id' => $inReview->id,
            'current_stage_id' => $stage->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/requests?status=new&department_id={$department->id}&type_id={$type->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('data.0.status.code', 'new')
            ->assertJsonPath('data.0.department.code', 'ADM')
            ->assertJsonPath('data.0.request_type.code', 'PROM');
    }
}
