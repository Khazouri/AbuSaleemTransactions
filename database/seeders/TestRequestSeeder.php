<?php

namespace Database\Seeders;

use App\Models\Approval;
use App\Models\Attachment;
use App\Models\AttachmentPreview;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\Decision;
use App\Models\Meeting;
use App\Models\Request;
use App\Models\RequestLegalReview;
use App\Models\RequestStageLog;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Services\ArtifactNumberGenerator;
use App\Services\DocumentReview;
use App\Services\EmploymentFilePreparationService;
use App\Services\IntakeGateService;
use App\Services\RequestDeadlineService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Test data: five random requests on every stage a file can reach. Behind the
 * maintenance console's debug-only «توليد طلبات تجريبية» button.
 *
 *     php artisan db:seed --class=TestRequestSeeder
 *
 * Each file's history is WRITTEN, step by step, rather than walked through the
 * endpoints (user request 2026-10-24), so it reads like a real one:
 *
 *   - a stage log and status history row per step, a day apart;
 *   - a stored PDF for every Appendix 57 row: the filer's at intake, the
 *     service-file rows uploaded by HR once the file reaches it;
 *   - HR's employment-file card and the مقرر's بوابة 1, every file previewed and
 *     approved, and the Approval ledger row of each approve;
 *   - past the committee: the legal member's recommendation, one completed
 *     meeting per run (its محضر on disk) holding every such file with its
 *     decision, and the authority's approve, with قرار الوزير on a minister file.
 *
 * No notification is sent: forty fake "action required" prompts would only
 * bury the real ones. Each run adds another batch; nothing is deduplicated.
 */
class TestRequestSeeder extends Seeder
{
    private const PER_STAGE = 5;

    /**
     * stage code => the status a file carries while it sits there, read off
     * the WorkflowTransitionSeeder rule that lands on it. administrative_routing
     * and stages 6–8 are absent because no rule reaches them any more (the
     * one-click manager approval, and Stage 102's single committee path).
     */
    public const STAGES = [
        'receive_from_municipality' => 'returned',
        'direct_manager_review' => 'in_review',
        'receive_and_register' => 'routed_to_hr',
        'requirements_check' => 'in_review',
        'receive_from_committee' => 'registered',
        'approval_by_authority' => 'awaiting_municipal_approval',
        'local_governance_ministry' => 'awaiting_central_approval',
        'final_approval_archiving' => 'final_approved',
    ];

    /** The steps that brought each stage's files there, oldest first. */
    private const STEPS = [
        'receive_from_municipality' => ['intake', 'submit', 'return'],
        'direct_manager_review' => ['intake', 'submit'],
        'receive_and_register' => ['intake', 'submit', 'forward'],
        'requirements_check' => ['intake', 'submit', 'forward', 'register'],
        'receive_from_committee' => ['intake', 'submit', 'forward', 'register', 'enlist'],
        'approval_by_authority' => ['intake', 'submit', 'forward', 'register', 'enlist', 'to_legal', 'legal', 'committee'],
        'local_governance_ministry' => ['intake', 'submit', 'forward', 'register', 'enlist', 'to_legal', 'legal', 'committee'],
        'final_approval_archiving' => ['intake', 'submit', 'forward', 'register', 'enlist', 'to_legal', 'legal', 'committee', 'authority'],
    ];

    /**
     * step => [action, to stage, status it stamps, who acts]. A null action is
     * a status-only move inside the committee stage (CommitteeStatusService's
     * legal review). A minister file's committee step lands on the ministry.
     */
    private const RULES = [
        'intake' => ['intake', 'receive_from_municipality', 'new', 'filer'],
        'submit' => ['submit', 'direct_manager_review', 'in_review', 'filer'],
        'return' => ['return_to_employee', 'receive_from_municipality', 'returned', 'manager'],
        'forward' => ['forward', 'receive_and_register', 'routed_to_hr', 'manager'],
        'register' => ['register', 'requirements_check', 'in_review', 'hr_director'],
        'enlist' => ['approve', 'receive_from_committee', 'registered', 'rapporteur'],
        'to_legal' => [null, null, 'under_legal_review', 'rapporteur'],
        'legal' => [null, null, 'ready', 'legal'],
        'committee' => ['approve', 'approval_by_authority', 'awaiting_municipal_approval', 'rapporteur'],
        'authority' => ['approve', 'final_approval_archiving', 'final_approved', 'authority'],
    ];

    /** WorkflowService::APPROVAL_LEVELS, for the approves written here. */
    private const APPROVAL_LEVELS = [
        'requirements_check' => 1,
        'receive_from_committee' => 2,
        'approval_by_authority' => 3,
        'local_governance_ministry' => 4,
    ];

    public const TITLES = [
        'طلب ترقية',
        'طلب إجازة',
        'طلب نقل',
        'طلب تسوية وضع وظيفي',
        'تظلم من تقييم الأداء',
        'طلب ندب',
        'طلب صرف علاوة',
        'طلب تثبيت',
    ];

    private Collection $holders;

    private Collection $stages;

    private Collection $statuses;

    private Collection $roleIds;

    private Meeting $meeting;

    private int $agendaOrder = 0;

    private ?string $pdf = null;

    public function __construct(
        private readonly ArtifactNumberGenerator $numbers,
        private readonly RequestDeadlineService $deadlines,
        private readonly IntakeGateService $gate,
        private readonly EmploymentFilePreparationService $preparation,
        private readonly DocumentReview $review,
    ) {}

    public function run(): void
    {
        // Every committee seat, العميد and الوزير act somewhere on the path.
        $this->holders = collect(CommitteeMember::SOLE_ROLES)->map(fn (string $role, string $post) => User::activeSeatHolder($post));
        if ($this->holders->contains(null)) {
            $this->command?->warn('A committee seat, the dean or the minister has no active holder. Run "Seed test users" first.');

            return;
        }

        // A filer with a manager, so the manager-gated stage-2 files have
        // someone who can act on them; an employee number, which intake
        // demands; and no sole post, so no one checks or decides their own file.
        $filers = User::query()
            ->where('is_active', true)
            ->whereNotNull('manager_id')
            ->whereNotNull('employee_number')
            ->whereHas('roles', fn ($q) => $q->where('code', 'R01'))
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('code', CommitteeMember::SOLE_ROLES))
            ->get();
        if ($filers->isEmpty()) {
            $this->command?->warn('No active R01 user with a manager. Run "Seed test users" first.');

            return;
        }

        $types = RequestType::query()->where('is_active', true)->get();
        // Codes repeat across versions, so an unscoped lookup would park files
        // on whichever version's row came last (a draft, once one exists).
        $this->stages = Workflow::standard()->publishedVersion->stages()->pluck('id', 'code');
        $this->statuses = RequestStatus::query()->pluck('id', 'code');
        $this->roleIds = Role::query()->whereIn('code', CommitteeMember::SOLE_ROLES)->pluck('id', 'code');

        // The number series lock their latest row, so the run is one transaction.
        DB::transaction(function () use ($filers, $types) {
            $this->meeting = $this->completedMeeting(now()->subDays(3));

            foreach (array_keys(self::STAGES) as $stageCode) {
                for ($i = 1; $i <= self::PER_STAGE; $i++) {
                    $this->file($stageCode, $i, $filers->random(), $types->random());
                }
            }
        });

        $this->command?->info(count(self::STAGES) * self::PER_STAGE.' test requests created.');
    }

    /** The paper sitting that decided every file past the committee in this run. */
    private function completedMeeting(Carbon $resultsAt): Meeting
    {
        $committee = Committee::query()->where('is_active', true)->orderBy('id')->first()
            ?? Committee::create(['name_ar' => 'لجنة شؤون الموظفين', 'name_en' => 'Employee Affairs Committee']);
        $committee->seatRoleHolders();

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع تجريبي منعقد',
            'scheduled_at' => $resultsAt->copy()->subHours(2),
            'location' => 'قاعة الاجتماعات',
            'status' => 'completed',
            'meeting_type' => 'regular',
            'chairman_user_id' => $this->holders['chair']->id,
            'rapporteur_user_id' => $this->holders['rapporteur']->id,
            'meeting_number' => $this->numbers->nextMeetingNumber(),
            'created_by_user_id' => $this->holders['rapporteur']->id,
            'results_recorded_at' => $resultsAt,
            'results_recorded_by_user_id' => $this->holders['rapporteur']->id,
        ]);
        $meeting->update([
            'minutes_file_path' => $this->storePdf("meeting-minutes/{$meeting->id}"),
            'minutes_file_name' => 'محضر الاجتماع.pdf',
            'minutes_file_mime' => 'application/pdf',
        ]);

        foreach ($this->holders->only(CommitteeMember::SEATS) as $holder) {
            $meeting->attendees()->create(['user_id' => $holder->id, 'invitation_status' => 'confirmed', 'responded_at' => $resultsAt]);
        }

        return $meeting;
    }

    private function file(string $stageCode, int $i, User $filer, RequestType $type): void
    {
        $steps = self::STEPS[$stageCode];
        $authority = match (true) {
            $stageCode === 'local_governance_ministry', $stageCode === 'final_approval_archiving' && $i % 2 === 1 => 'minister',
            in_array('committee', $steps, true) => 'dean',
            default => null,
        };

        // Steps are a day apart. A file the committee decided has its decision
        // on the meeting's results moment; any other ends a few hours ago.
        $anchorIndex = array_search('committee', $steps, true);
        $anchor = $anchorIndex === false ? now()->subHours(random_int(2, 48)) : $this->meeting->results_recorded_at;
        $anchorIndex = $anchorIndex === false ? count($steps) - 1 : $anchorIndex;
        $grade = random_int(5, 12);
        $submittedAt = $anchor->copy()->subDays($anchorIndex);

        $requestRecord = Request::create([
            'intake_receipt_number' => $this->numbers->nextIntakeReceipt(),
            'title' => self::TITLES[array_rand(self::TITLES)]." (تجريبي {$i})",
            'description' => "طلب تجريبي مولَّد آليًا في مرحلة {$stageCode}.",
            'department_id' => $filer->department_id,
            'request_type_id' => $type->id,
            'status_id' => $this->statuses[self::STAGES[$stageCode]],
            'current_stage_id' => $this->stages[$stageCode],
            'created_by_user_id' => $filer->id,
            'submitted_at' => $submittedAt,
            'due_date' => $this->deadlines->dueDateFor($type, $submittedAt),
            'decision_grade' => $grade,
            'entitled_grade' => $grade,
            'grade_date' => $submittedAt->copy()->subYears(random_int(1, 4))->toDateString(),
            'approving_authority' => $authority,
        ]);

        $actors = [...$this->holders->all(), 'filer' => $filer, 'manager' => User::find($filer->manager_id), 'authority' => $this->holders[$authority ?? 'dean']];
        $fromStage = null;
        $fromStatus = null;

        foreach ($steps as $k => $step) {
            $at = $anchor->copy()->addDays($k - $anchorIndex);
            [$action, $toStage, $status, $actor] = self::RULES[$step];
            if ($step === 'committee' && $authority === 'minister') {
                [$toStage, $status] = ['local_governance_ministry', 'awaiting_central_approval'];
            }

            $this->sideEffects($step, $requestRecord, $type, $actors, $at);

            if ($action !== null) {
                RequestStageLog::create([
                    'request_id' => $requestRecord->id,
                    'from_stage_id' => $fromStage === null ? null : $this->stages[$fromStage],
                    'to_stage_id' => $this->stages[$toStage],
                    'action' => $action,
                    'comment' => $step === 'return' ? 'يرجى استكمال البيانات (ملاحظة تجريبية).' : null,
                    'acted_by_user_id' => $actors[$actor]->id,
                    'acted_at' => $at,
                ]);

                if ($action === 'approve' && isset(self::APPROVAL_LEVELS[$fromStage])) {
                    $post = $actor === 'authority' ? $authority : $actor;
                    Approval::create([
                        'request_id' => $requestRecord->id,
                        'level' => self::APPROVAL_LEVELS[$fromStage],
                        'role_id' => $this->roleIds[CommitteeMember::SOLE_ROLES[$post]],
                        'approved_by_user_id' => $actors[$actor]->id,
                        'action' => $action,
                        'approved_at' => $at,
                    ]);
                }
                $fromStage = $toStage;
            }

            RequestStatusHistory::create([
                'request_id' => $requestRecord->id,
                'from_status_id' => $fromStatus,
                'to_status_id' => $this->statuses[$status],
                'changed_by_user_id' => $actors[$actor]->id,
                'changed_at' => $at,
            ]);
            $fromStatus = $this->statuses[$status];
        }
    }

    /** What the real actor records on the file before taking `$step`. */
    private function sideEffects(string $step, Request $requestRecord, RequestType $type, array $actors, Carbon $at): void
    {
        $serviceFile = fn (string $key): bool => $type->sectionForDocument($key) === EmploymentFilePreparationService::SECTION;

        match ($step) {
            'intake' => $this->attachDocuments($requestRecord, $type, $actors['filer'], $at, fn (string $key) => ! $serviceFile($key)),
            // Stage 98 — the service file is HR's to assemble, once it has the file.
            'forward' => $this->attachDocuments($requestRecord, $type, $actors['hr_director'], $at->copy()->addHours(2), $serviceFile),
            'register' => $requestRecord->update([
                'employment_file' => $this->preparation->record($requestRecord, $this->approveAll($this->preparation->review($requestRecord), $requestRecord, $actors['hr_director']), true, true),
                'employment_file_prepared_by_user_id' => $actors['hr_director']->id,
                'employment_file_prepared_at' => $at->copy()->subHour(),
            ]),
            'enlist' => $requestRecord->update([
                'intake_gate' => $this->gate->record($requestRecord, $this->approveAll($this->review, $requestRecord, $actors['rapporteur']), true),
                'intake_gate_checked_by_user_id' => $actors['rapporteur']->id,
                'intake_gate_checked_at' => $at->copy()->subHour(),
                // The قيد mints the PM-COM number (Stage 97).
                'reference_number' => $this->numbers->nextRequestReference(),
            ]),
            'legal' => RequestLegalReview::create([
                'request_id' => $requestRecord->id,
                'recommendation' => 'approve',
                'approving_authority' => $requestRecord->approving_authority,
                'legal_note' => 'توصية تجريبية.',
                'reviewed_by_user_id' => $actors['legal']->id,
                'reviewed_at' => $at,
            ]),
            'committee' => $this->decide($requestRecord, $actors['rapporteur'], $at),
            'authority' => $requestRecord->approving_authority === 'minister'
                ? $this->attach($requestRecord, [
                    'original_name' => 'قرار الوزير - '.$requestRecord->reference_number.'.pdf',
                    'label' => 'قرار الوزير',
                    'file_section' => 'approval',
                ], $actors['minister'], $at)
                : null,
            default => null,
        };
    }

    /** One stored file for each of the type's Appendix 57 rows `$which` keeps. */
    private function attachDocuments(Request $requestRecord, RequestType $type, User $uploader, Carbon $at, callable $which): void
    {
        foreach (array_keys($type->documentOptions()) as $key) {
            if ($which($key)) {
                $this->attach($requestRecord, [
                    'original_name' => Attachment::displayName($type, $key, null, $requestRecord->subject, 'document.pdf'),
                    'required_document_key' => $key,
                    'file_section' => $type->sectionForDocument($key),
                ], $uploader, $at);
            }
        }
    }

    /** @param  array{original_name: string, file_section: string, label?: string, required_document_key?: string}  $fields */
    private function attach(Request $requestRecord, array $fields, User $uploader, Carbon $at): void
    {
        Attachment::create([
            ...$fields,
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => $this->storePdf("attachments/{$requestRecord->id}"),
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($this->pdf()),
            'uploaded_by_user_id' => $uploader->id,
            // The timeline files a document under the step it arrived in.
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * Every file under `$review` previewed by `$reviewer` and approved, as the
     * gate endpoints require (RequestController::refuseUnpreviewed()).
     *
     * @return array<int, string>
     */
    private function approveAll(DocumentReview $review, Request $requestRecord, User $reviewer): array
    {
        $verdicts = [];
        foreach ($review->attachments($requestRecord) as $file) {
            AttachmentPreview::create(['attachment_id' => $file->id, 'user_id' => $reviewer->id]);
            $verdicts[$file->id] = 'approved';
        }

        return $verdicts;
    }

    /** The file's agenda item and توصية on this run's meeting (MeetingResultsController::store()). */
    private function decide(Request $requestRecord, User $rapporteur, Carbon $at): void
    {
        $item = $this->meeting->agendaItems()->create([
            'request_id' => $requestRecord->id,
            'item_type' => 'employee_request',
            'agenda_order' => ++$this->agendaOrder,
            'item_state' => 'complete',
            'state_changed_at' => $at,
        ]);

        Decision::create([
            'meeting_request_id' => $item->id,
            'decision_number' => $this->numbers->nextDecisionNumber(),
            'outcome' => 'approve',
            'instrument' => 'recommendation',
            'decided_by_user_id' => $rapporteur->id,
            'decided_at' => $at,
        ]);
    }

    /** A random name in `$directory`, as an upload's `store()` would give. */
    private function storePdf(string $directory): string
    {
        $path = $directory.'/'.Str::random(40).'.pdf';
        Storage::disk('local')->put($path, $this->pdf());

        return $path;
    }

    /** A one-page PDF, so every preview opens on a real document. */
    private function pdf(): string
    {
        if ($this->pdf !== null) {
            return $this->pdf;
        }

        $text = 'BT /F1 24 Tf 72 760 Td (Test document) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $this->pdf = $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
