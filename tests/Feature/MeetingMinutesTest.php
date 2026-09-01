<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Committee;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\MeetingRequest;
use App\Models\PresentationMemo;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowStage;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Stage 36 — generate → review → sign, and the second close gate this stage
 * adds on top of Stage 34's unresolved-agenda-item one. See the AGENT_NOTES
 * Stage 36 entry for why `changes_requested` leaves status at `draft` rather
 * than a dedicated fourth status, and why zero attended attendees means
 * review's `approve` skips straight to `approved`.
 */
class MeetingMinutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_compiles_content_and_is_blocked_once_review_moves_past_draft(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting] = $this->committeeMeetingWithAttendees();

        $response = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->assertSame($meeting->title, $response->json('data.content.meeting.title'));
        $this->assertCount(2, $response->json('data.content.attendance.present'));

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", ['decision' => 'approve'])
            ->assertOk();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertStatus(422);
    }

    public function test_requesting_changes_requires_a_comment_and_leaves_the_draft_in_place(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting] = $this->committeeMeetingWithAttendees();

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", ['decision' => 'changes_requested'])
            ->assertStatus(422);

        $response = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", [
                'decision' => 'changes_requested',
                'comment' => 'الحضور غير مكتمل',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.review_comment', 'الحضور غير مكتمل');

        $this->assertNull($response->json('data.reviewed_by'));
    }

    public function test_approving_creates_one_signature_per_attended_attendee_and_moves_to_pending_signatures(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, , , $meeting] = $this->committeeMeetingWithAttendees();

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();

        $response = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_signatures');

        $this->assertCount(2, $response->json('data.signatures'));
    }

    public function test_a_meeting_with_no_attended_attendees_auto_approves_on_review(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة بلا حضور']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع بلا حضور مسجل',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();

        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonCount(0, 'data.signatures');
    }

    public function test_signing_rejects_a_non_signer_and_a_double_sign_then_the_last_signature_approves(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, , $meeting] = $this->committeeMeetingWithAttendees();
        $outsider = $this->userWithRole('R04');

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();
        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/review", ['decision' => 'approve'])->assertOk();

        $this->actingAs($outsider, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/minutes/sign", ['signature' => UploadedFile::fake()->image('s.png', 10, 10)])
            ->assertStatus(404);

        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/minutes/sign", ['signature' => UploadedFile::fake()->image('s.png', 10, 10)])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_signatures');

        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/minutes/sign", ['signature' => UploadedFile::fake()->image('s.png', 10, 10)])
            ->assertStatus(422);

        $response = $this->actingAs($member, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/minutes/sign", ['signature' => UploadedFile::fake()->image('s.png', 10, 10)])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertNotNull($response->json('data.approved_at'));
        $this->assertSame(MeetingMinutes::STATUS_APPROVED, $meeting->fresh()->meetingMinutes->status);
    }

    public function test_closing_the_meeting_is_blocked_until_minutes_are_approved(): void
    {
        $this->seed(DatabaseSeeder::class);
        $head = $this->userWithRole('R03');
        $committee = Committee::create(['name_ar' => 'لجنة إغلاق الاجتماع']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع لاختبار بوابة الإغلاق',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);

        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/generate")->assertOk();

        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertStatus(422);

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/review", ['decision' => 'approve'])->assertOk();

        $this->actingAs($head, 'sanctum')
            ->putJson("/api/meetings/{$meeting->id}", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_only_the_head_can_review_a_member_can_still_generate_and_sign(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $member, , $meeting] = $this->committeeMeetingWithAttendees();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/review", ['decision' => 'approve'])
            ->assertStatus(403);

        $this->actingAs($head, 'sanctum')->postJson("/api/meetings/{$meeting->id}/minutes/review", ['decision' => 'approve'])->assertOk();

        $this->actingAs($member, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/minutes/sign", ['signature' => UploadedFile::fake()->image('s.png', 10, 10)])
            ->assertOk();
    }

    /**
     * Stage 50 — [D] Art. 28's minutes-content list: attendee seat/role,
     * per-item facts summary + legal basis (read from an existing
     * presentation memo), the documents-reviewed list, a dissenting vote's
     * reason, a referral-authority field distinct from the free-text
     * comment, and the required-signatories roster.
     */
    public function test_generated_minutes_include_the_stage_50_content_gaps(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$head, $approvingMember, $dissentingMember, $meeting, $agendaItem, $requestRecord] =
            $this->committeeMeetingWithDecidedRequestItem();

        Attachment::create([
            'request_id' => $requestRecord->id,
            'disk' => 'local',
            'path' => 'attachments/study-file.pdf',
            'original_name' => 'ملف الدراسة.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'uploaded_by_user_id' => $head->id,
        ]);

        PresentationMemo::create([
            'meeting_request_id' => $agendaItem->id,
            'content' => [
                'derived' => [],
                'authored' => [
                    'facts_summary' => 'ملخص وقائع الموضوع المعروض.',
                    'legal_opinion' => 'الأساس القانوني: المادة العاشرة من اللائحة.',
                    'employment_status_notes' => null,
                    'committee_question' => null,
                ],
            ],
            'generated_by_user_id' => $head->id,
            'generated_at' => now(),
        ]);

        $this->actingAs($approvingMember, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", ['vote' => 'approve'])
            ->assertCreated();
        $this->actingAs($dissentingMember, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/votes", [
                'vote' => 'reject',
                'comment' => 'أتحفظ لعدم استيفاء الملف للمستندات المطلوبة.',
            ])
            ->assertCreated();

        $this->actingAs($head, 'sanctum')
            ->post("/api/meetings/{$meeting->id}/agenda/{$agendaItem->id}/decision", [
                'signature' => UploadedFile::fake()->image('signature.png', 10, 10),
                'referral_authority' => 'ديوان البلدية',
            ])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'approve')
            ->assertJsonPath('data.referral_authority', 'ديوان البلدية');

        $this->assertDatabaseHas('decisions', [
            'meeting_request_id' => $agendaItem->id,
            'referral_authority' => 'ديوان البلدية',
        ]);

        $response = $this->actingAs($head, 'sanctum')
            ->postJson("/api/meetings/{$meeting->id}/minutes/generate")
            ->assertOk();

        $presentAttendees = collect($response->json('data.content.attendance.present'));
        $chairEntry = $presentAttendees->firstWhere('id', $head->id);
        $this->assertSame('chair', $chairEntry['seat']);
        $this->assertTrue($chairEntry['is_head']);
        $unseated = $presentAttendees->firstWhere('id', $approvingMember->id);
        $this->assertNull($unseated['seat']);
        $this->assertFalse($unseated['is_head']);

        $signatories = collect($response->json('data.content.required_signatories'))->pluck('id');
        $this->assertEqualsCanonicalizing(
            [$head->id, $approvingMember->id, $dissentingMember->id],
            $signatories->all(),
        );

        $item = collect($response->json('data.content.agenda_items'))->firstWhere('id', $agendaItem->id);
        $this->assertSame('ملخص وقائع الموضوع المعروض.', $item['facts_summary']);
        $this->assertSame('الأساس القانوني: المادة العاشرة من اللائحة.', $item['legal_basis']);
        $this->assertCount(1, $item['documents_reviewed']);
        $this->assertSame('ملف الدراسة.pdf', $item['documents_reviewed'][0]['original_name']);
        $this->assertSame('ديوان البلدية', $item['decision']['referral_authority']);

        $this->assertCount(1, $item['dissenting_opinions']);
        $this->assertSame($dissentingMember->name, $item['dissenting_opinions'][0]['user']);
        $this->assertSame('reject', $item['dissenting_opinions'][0]['vote']);
        $this->assertSame('أتحفظ لعدم استيفاء الملف للمستندات المطلوبة.', $item['dissenting_opinions'][0]['comment']);
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: Meeting, 4: MeetingRequest, 5: Request}
     */
    private function committeeMeetingWithDecidedRequestItem(): array
    {
        $head = $this->userWithRole('R03');
        $approvingMember = $this->userWithRole('R04');
        $dissentingMember = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة اختبار محتوى المحضر']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true, 'seat' => 'chair']);
        $committee->members()->create(['user_id' => $approvingMember->id]);
        $committee->members()->create(['user_id' => $dissentingMember->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع اختبار محتوى المحضر',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $approvingMember->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $dissentingMember->id, 'attended' => true]);

        $requestRecord = Request::create([
            'reference_number' => now()->format('Y').'-ADM-'.fake()->unique()->numberBetween(1000, 999999),
            'title' => 'طلب معروض لاختبار محتوى المحضر',
            'department_id' => Department::where('code', 'ADM')->value('id'),
            'request_type_id' => RequestType::where('code', 'PROM')->value('id'),
            'status_id' => RequestStatus::where('code', 'in_meeting')->value('id'),
            'current_stage_id' => WorkflowStage::where('code', 'receive_from_committee')->value('id'),
            'created_by_user_id' => $this->userWithRole('R01')->id,
            'submitted_at' => now()->subDays(3),
        ]);
        $agendaItem = $meeting->agendaItems()->create(['request_id' => $requestRecord->id, 'agenda_order' => 1]);

        return [$head, $approvingMember, $dissentingMember, $meeting, $agendaItem, $requestRecord];
    }

    /** @return array{0: User, 1: User, 2: Committee, 3: Meeting} */
    private function committeeMeetingWithAttendees(): array
    {
        $head = $this->userWithRole('R03');
        $member = $this->userWithRole('R04');

        $committee = Committee::create(['name_ar' => 'لجنة محضر الاجتماع']);
        $committee->members()->create(['user_id' => $head->id, 'is_head' => true]);
        $committee->members()->create(['user_id' => $member->id]);

        $meeting = Meeting::create([
            'committee_id' => $committee->id,
            'title' => 'اجتماع لاختبار المحضر',
            'scheduled_at' => now()->addDay(),
            'created_by_user_id' => $head->id,
        ]);
        $meeting->attendees()->create(['user_id' => $head->id, 'attended' => true]);
        $meeting->attendees()->create(['user_id' => $member->id, 'attended' => true]);

        return [$head, $member, $committee, $meeting];
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
