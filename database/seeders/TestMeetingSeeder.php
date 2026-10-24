<?php

namespace Database\Seeders;

use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestLegalReview;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\User;
use App\Models\Workflow;
use App\Services\ArtifactNumberGenerator;
use App\Services\RequestDeadlineService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Test data: three committee meetings a tester can pick up at once. Behind the
 * maintenance console's debug-only «إنشاء اجتماعات تجريبية» button.
 *
 *     php artisan db:seed --class=TestMeetingSeeder
 *
 * Every meeting is dated earlier today and every seat has accepted it, so the
 * مقرر can enter results straight away (MeetingDuties::recordResultsRefusal()
 * refuses a meeting still in the future). Their agendas hold 5, 1 and 0 files.
 *
 * Each file's history is WRITTEN, step by step, rather than walked through the
 * endpoints: filing, the manager's forward, HR's registration, the مقرر's قيد and
 * the legal member's recommendation. No gate card or attachment stands behind
 * it, and no notification is sent. Each run adds another set.
 */
class TestMeetingSeeder extends Seeder
{
    private const AGENDA_SIZES = [5, 1, 0];

    /**
     * The real path to the pending list: [action, from stage, to stage,
     * status it stamps, who acts]. A null action is a status-only move
     * inside the committee stage (CommitteeStatusService's legal review).
     */
    private const PATH = [
        ['submit', 'receive_from_municipality', 'direct_manager_review', 'in_review', 'filer'],
        ['forward', 'direct_manager_review', 'receive_and_register', 'routed_to_hr', 'manager'],
        ['register', 'receive_and_register', 'requirements_check', 'in_review', 'hr_director'],
        ['approve', 'requirements_check', 'receive_from_committee', 'registered', 'rapporteur'],
        [null, null, null, 'under_legal_review', 'rapporteur'],
        [null, null, null, 'ready', 'legal'],
    ];

    public function run(ArtifactNumberGenerator $numbers, RequestDeadlineService $deadlines): void
    {
        $holders = collect(CommitteeMember::SEATS)->mapWithKeys(fn (string $seat) => [$seat => User::activeSeatHolder($seat)]);
        if ($holders->contains(null)) {
            $this->command?->warn('A committee seat has no active holder. Run "Seed test users" first.');

            return;
        }

        $filers = User::query()
            ->where('is_active', true)
            ->whereNotNull('manager_id')
            ->whereHas('roles', fn ($q) => $q->where('code', 'R01'))
            ->get();
        if ($filers->isEmpty()) {
            $this->command?->warn('No active R01 user with a manager. Run "Seed test users" first.');

            return;
        }

        $types = RequestType::query()->where('is_active', true)->get();
        $stages = Workflow::standard()->publishedVersion->stages()->pluck('id', 'code');
        $statuses = RequestStatus::query()->pluck('id', 'code');

        // The number series lock their latest row, so the run is one transaction.
        DB::transaction(function () use ($numbers, $deadlines, $holders, $filers, $types, $stages, $statuses) {
            $committee = Committee::query()->where('is_active', true)->orderBy('id')->first()
                ?? Committee::create(['name_ar' => 'لجنة شؤون الموظفين', 'name_en' => 'Employee Affairs Committee']);
            $committee->seatRoleHolders();

            foreach (self::AGENDA_SIZES as $index => $size) {
                $label = chr(ord('A') + $index);
                $meeting = Meeting::create([
                    'committee_id' => $committee->id,
                    'title' => "اجتماع تجريبي {$label}",
                    // Earlier today, never yesterday, even just after midnight.
                    'scheduled_at' => now()->subMinutes(30 - 10 * $index)->max(today()),
                    'location' => 'قاعة الاجتماعات',
                    'status' => 'scheduled',
                    'meeting_type' => 'regular',
                    'chairman_user_id' => $holders['chair']->id,
                    'rapporteur_user_id' => $holders['rapporteur']->id,
                    'meeting_number' => $numbers->nextMeetingNumber(),
                    'created_by_user_id' => $holders['rapporteur']->id,
                ]);

                foreach ($holders as $holder) {
                    $meeting->attendees()->create([
                        'user_id' => $holder->id,
                        'invitation_status' => 'confirmed',
                        'responded_at' => now(),
                    ]);
                }

                for ($order = 1; $order <= $size; $order++) {
                    $requestRecord = $this->committeeFile($numbers, $deadlines, $holders->all(), $filers->random(), $types->random(), $stages, $statuses);
                    $meeting->agendaItems()->create([
                        'request_id' => $requestRecord->id,
                        'item_type' => 'employee_request',
                        'agenda_order' => $order,
                    ]);
                }
            }
        });

        $this->command?->info('3 test meetings created ('.implode(', ', self::AGENDA_SIZES).' requests).');
    }

    /** A file on the pending list, with the history that brought it there. */
    private function committeeFile(
        ArtifactNumberGenerator $numbers,
        RequestDeadlineService $deadlines,
        array $holders,
        User $filer,
        RequestType $type,
        $stages,
        $statuses,
    ): Request {
        $actors = [...$holders, 'filer' => $filer, 'manager' => User::find($filer->manager_id)];
        $at = now()->subDays(random_int(10, 30));

        $requestRecord = Request::create([
            'intake_receipt_number' => $numbers->nextIntakeReceipt(),
            'title' => TestRequestSeeder::TITLES[array_rand(TestRequestSeeder::TITLES)].' (اجتماع تجريبي)',
            'description' => 'طلب تجريبي مولَّد آليًا ومدرج في جدول أعمال اجتماع تجريبي.',
            'department_id' => $filer->department_id,
            'request_type_id' => $type->id,
            'status_id' => $statuses['in_review'],
            'current_stage_id' => $stages['direct_manager_review'],
            'created_by_user_id' => $filer->id,
            'submitted_at' => $at,
            'due_date' => $deadlines->dueDateFor($type, $at),
        ]);

        $fromStatus = null;
        foreach (self::PATH as [$action, $from, $to, $status, $actor]) {
            $actorId = $actors[$actor]->id;
            if ($action !== null) {
                RequestStageLog::create([
                    'request_id' => $requestRecord->id,
                    'from_stage_id' => $stages[$from],
                    'to_stage_id' => $stages[$to],
                    'action' => $action,
                    'acted_by_user_id' => $actorId,
                    'acted_at' => $at,
                ]);
            }
            RequestStatusHistory::create([
                'request_id' => $requestRecord->id,
                'from_status_id' => $fromStatus,
                'to_status_id' => $statuses[$status],
                'changed_by_user_id' => $actorId,
                'changed_at' => $at,
            ]);
            $fromStatus = $statuses[$status];
            $at = $at->copy()->addDay();
        }

        RequestLegalReview::create([
            'request_id' => $requestRecord->id,
            'recommendation' => 'approve',
            'approving_authority' => random_int(0, 1) ? 'dean' : 'minister',
            'legal_note' => 'توصية تجريبية.',
            'reviewed_by_user_id' => $actors['legal']->id,
            'reviewed_at' => $at,
        ]);

        $requestRecord->update([
            // The قيد mints the PM-COM number (Stage 97).
            'reference_number' => $numbers->nextRequestReference(),
            'status_id' => $statuses['ready'],
            'current_stage_id' => $stages['receive_from_committee'],
        ]);

        return $requestRecord;
    }
}
