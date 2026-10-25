<?php

namespace Tests;

use App\Models\Appeal;
use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingRequest;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/**
 * The paper sitting (user decision 2026-10-18): a scheduled meeting whose
 * date has come, its requests waiting at the committee stage, and the مقرر
 * who enters what was decided on paper. Shared by the results test and the
 * authority test, which starts where the results leave off.
 */
trait HoldsPaperMeetings
{
    /** @return array{0: User, 1: Meeting, 2: list<MeetingRequest>} */
    protected function paperMeeting(int $items = 1, string $status = 'scheduled', ?\DateTimeInterface $scheduledAt = null): array
    {
        $rapporteur = $this->userWith('R02');
        $chair = $this->userWith('R03');

        $committee = Committee::create(['name_ar' => 'لجنة شؤون الموظفين']);
        $committee->members()->create(['user_id' => $rapporteur->id, 'seat' => 'rapporteur']);
        $committee->members()->create(['user_id' => $chair->id, 'seat' => 'chair', 'is_head' => true]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اللجنة',
            'scheduled_at' => $scheduledAt ?? now()->subHour(),
            'status' => $status,
            'created_by_user_id' => $rapporteur->id,
        ]);

        $agenda = [];
        for ($order = 1; $order <= $items; $order++) {
            $agenda[] = $meeting->agendaItems()->create([
                'request_id' => $this->committeeFile()->id,
                'agenda_order' => $order,
            ]);
        }

        return [$rapporteur, $meeting, $agenda];
    }

    /** An appeal at legal review, nominated onto this meeting's agenda. */
    protected function appealOnAgenda(Meeting $meeting, Appeal $appeal): MeetingRequest
    {
        return $meeting->agendaItems()->create([
            'item_type' => 'appeal',
            'appeal_id' => $appeal->id,
            'agenda_order' => (int) $meeting->agendaItems()->max('agenda_order') + 1,
        ]);
    }

    /**
     * The committee decides an appeal on paper: a meeting with the appeal as
     * its one item, and the مقرر's results for it. approve = قبول التظلم,
     * decline = رفض التظلم. Returns the decided agenda item.
     */
    protected function decideAppealOnPaper(Appeal $appeal, string $recommendation = 'decline', string $note = 'سبب قرار اللجنة'): MeetingRequest
    {
        [$rapporteur, $meeting] = $this->paperMeeting(0);
        $item = $this->appealOnAgenda($meeting, $appeal);

        $this->recordResults($rapporteur, $meeting, [
            $item->id => ['recommendation' => $recommendation, 'note' => $note],
        ])->assertOk();

        return $item->refresh();
    }

    protected function committeeFile(): Request
    {
        $subject = User::factory()->create(['is_active' => true]);

        return Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 9999),
            'title' => 'طلب معروض على اللجنة',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'decision_grade' => 5,
            'created_by_user_id' => $subject->id,
            'subject_user_id' => $subject->id,
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'submitted_at' => now(),
        ]);
    }

    /**
     * The محضر is scanned pages, images only (2026-10-25); one page unless the
     * caller hands its own, in the order the مقرر arranged them.
     *
     * @param  array<int, array<string, mixed>>  $results  agenda item id => its row
     * @param  list<UploadedFile>|null  $pages
     */
    protected function recordResults(User $actor, Meeting $meeting, array $results, bool $withMinutes = true, ?array $pages = null): TestResponse
    {
        $payload = ['items' => $results];
        if ($withMinutes) {
            $payload['minutes_pages'] = $pages ?? [UploadedFile::fake()->image('محضر.jpg')];
        }

        return $this->actingAs($actor, 'sanctum')->post("/api/meetings/{$meeting->id}/results", $payload, ['Accept' => 'application/json']);
    }

    protected function userWith(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
