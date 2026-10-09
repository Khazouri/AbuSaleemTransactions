<?php

namespace Tests\Feature;

use App\Models\Request;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TestRequestSeeder;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The maintenance console's «توليد طلبات تجريبية» button: five requests parked
 * at each stage a file can actually reach, each on the status it would carry
 * there, numbered the way the real path numbers it.
 */
class TestRequestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parks_five_requests_on_every_reachable_stage(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(TestUserSeeder::class);
        $this->seed(TestRequestSeeder::class);

        $this->assertSame(count(TestRequestSeeder::STAGES) * 5, Request::count());

        $registered = false;
        foreach (TestRequestSeeder::STAGES as $stageCode => $statusCode) {
            $registered = $registered || $stageCode === 'receive_from_committee';
            $requests = Request::query()
                ->with('status', 'stageLogs', 'statusHistory')
                ->where('current_stage_id', WorkflowStage::where('code', $stageCode)->value('id'))
                ->get();

            $this->assertCount(5, $requests, $stageCode);
            foreach ($requests as $requestRecord) {
                $this->assertSame($statusCode, $requestRecord->status->code, $stageCode);
                $this->assertNotNull($requestRecord->intake_receipt_number);
                // The قيد number exists only once the file is registered.
                $this->assertSame($registered, $requestRecord->reference_number !== null, $stageCode);
                $this->assertCount(1, $requestRecord->stageLogs);
                $this->assertCount(1, $requestRecord->statusHistory);
            }
        }
    }

    public function test_the_retired_stages_get_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(TestUserSeeder::class);
        $this->seed(TestRequestSeeder::class);

        $retired = WorkflowStage::query()
            ->whereIn('code', ['administrative_routing', 'reviewer_review', 'observations', 'forward_to_committee'])
            ->pluck('id');

        $this->assertSame(0, Request::whereIn('current_stage_id', $retired)->count());
    }
}
