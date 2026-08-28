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

class RequestNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_user_can_add_and_read_request_notes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@abusaleem.test')->firstOrFail();
        $requestRecord = Request::create([
            'title' => 'طلب للملاحظات',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'new')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_municipality')->value('id'),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/requests/{$requestRecord->id}/notes", [
                'body' => 'تمت مراجعة المستندات المرفقة.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.request_id', $requestRecord->id)
            ->assertJsonPath('data.body', 'تمت مراجعة المستندات المرفقة.')
            ->assertJsonPath('data.created_by.id', $admin->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/requests/{$requestRecord->id}/notes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'تمت مراجعة المستندات المرفقة.');
    }
}
