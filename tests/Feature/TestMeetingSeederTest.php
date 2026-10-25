<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Request;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\TestMeetingSeeder;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The maintenance console's «إنشاء اجتماعات تجريبية» button: three meetings
 * the testers can pick up straight away, each confirmed by every seat and
 * dated earlier that day, with 5, 1 and 0 registered files on their agendas.
 */
class TestMeetingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_up_three_confirmed_meetings_dated_today(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(TestUserSeeder::class);
        $this->seed(TestMeetingSeeder::class);

        $meetings = Meeting::query()->with('attendees', 'agendaItems')->orderBy('id')->get();

        $this->assertSame([5, 1, 0], $meetings->map(fn (Meeting $m) => $m->agendaItems->count())->all());
        foreach ($meetings as $meeting) {
            $this->assertSame('scheduled', $meeting->status);
            $this->assertTrue($meeting->scheduled_at->isToday());
            $this->assertFalse($meeting->scheduled_at->isFuture());
            $this->assertNotNull($meeting->meeting_number);
            $this->assertCount(5, $meeting->attendees);
            $this->assertSame(['confirmed'], $meeting->attendees->pluck('invitation_status')->unique()->values()->all());
        }

        $requests = Request::query()->with('status', 'currentStage', 'stageLogs', 'statusHistory', 'legalReviews')->get();
        $this->assertCount(6, $requests);
        foreach ($requests as $requestRecord) {
            $this->assertSame('receive_from_committee', $requestRecord->currentStage->code);
            $this->assertSame('ready', $requestRecord->status->code);
            $this->assertNotNull($requestRecord->reference_number);
            $this->assertSame(['submit', 'forward', 'register', 'approve'], $requestRecord->stageLogs->sortBy('id')->pluck('action')->values()->all());
            $this->assertCount(6, $requestRecord->statusHistory);
            $this->assertSame('approve', $requestRecord->legalReviews->first()?->recommendation);
        }
    }

    public function test_the_rapporteur_can_enter_the_first_meetings_results_at_once(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(TestUserSeeder::class);
        $this->seed(TestMeetingSeeder::class);

        $meeting = Meeting::query()->orderBy('id')->firstOrFail();
        $rapporteur = User::where('email', 'r02.reviewer@abusaleem.test')->firstOrFail();

        $this->actingAs($rapporteur, 'sanctum')->post("/api/meetings/{$meeting->id}/results", [
            'items' => $meeting->agendaItems()->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['recommendation' => 'approve', 'approving_authority' => 'dean']])
                ->all(),
            'minutes_pages' => [UploadedFile::fake()->image('محضر.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_it_writes_nothing_while_a_seat_has_no_holder(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(TestUserSeeder::class);
        User::where('email', 'r02.reviewer@abusaleem.test')->firstOrFail()
            ->roles()->detach(Role::where('code', 'R02')->value('id'));

        $this->seed(TestMeetingSeeder::class);

        $this->assertSame(0, Meeting::count());
        $this->assertSame(0, Request::count());
    }
}
