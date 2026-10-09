<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\SitsOnCommittee;
use Tests\TestCase;

/**
 * A concluded (completed) meeting is a closed record: every write through the
 * meeting-bound routes is refused, while reading it still works.
 */
class ConcludedMeetingTest extends TestCase
{
    use RefreshDatabase;
    use SitsOnCommittee;

    public function test_a_completed_meeting_refuses_every_write_but_stays_readable(): void
    {
        $this->seed(DatabaseSeeder::class);

        $head = User::factory()->create(['is_active' => true]);
        $head->roles()->attach(Role::where('code', 'R08')->value('id'));

        $committee = Committee::create(['name_ar' => 'لجنة منتهية']);
        $this->seatOn($committee, $head, ['is_head' => true]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع منتهٍ',
            'scheduled_at' => now()->subDay(),
            'status' => 'completed',
        ]);
        $attendee = $meeting->attendees()->create(['user_id' => $head->id]);

        $this->actingAs($head, 'sanctum');

        $this->getJson("/api/meetings/{$meeting->id}")->assertOk();

        $this->putJson("/api/meetings/{$meeting->id}", ['status' => 'scheduled'])
            ->assertUnprocessable()->assertJsonValidationErrors('meeting');
        $this->deleteJson("/api/meetings/{$meeting->id}")->assertUnprocessable();
        $this->patchJson("/api/meetings/{$meeting->id}/attendees/{$attendee->id}", ['attended' => false])
            ->assertUnprocessable();
        $this->postJson("/api/meetings/{$meeting->id}/agenda", ['item_type' => 'administrative', 'subject' => 'بند'])
            ->assertUnprocessable();
        $this->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertUnprocessable();

        $this->assertSame('completed', $meeting->fresh()->status);
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
        $this->assertDatabaseCount('meeting_requests', 0);
    }
}
