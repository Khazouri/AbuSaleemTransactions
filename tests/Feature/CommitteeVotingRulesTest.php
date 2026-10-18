<?php

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 73 — [D] Appendix 65's بطاقة تعريف اللجنة. Since the paper sitting
 * (2026-10-18) the system no longer counts a quorum or a majority, so the
 * card's rules are transcribed and shown, and what is left to pin is that
 * they round-trip as written and that Appendix 64 still forbids defaulting
 * one the reader must choose.
 */
class CommitteeVotingRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_identity_card_round_trips_through_the_committee_api(): void
    {
        $this->seed(DatabaseSeeder::class);
        // Stage 102 — `meetings,add` (which also forms committees) is the
        // مقرر's alone.
        $head = $this->userWithRole('R02');

        // Membership gate — `meetings,add` sits on a gated screen, so forming a
        // committee needs a seat on one already. That is the real bootstrap
        // rule: R08 (exempt) creates the first committee and seats people, and
        // a seated chair can create further ones from there.
        Committee::create(['name_ar' => 'لجنة التشكيل الأولى'])
            ->members()->create(['user_id' => $head->id]);

        $created = $this->actingAs($head, 'sanctum')
            ->postJson('/api/committees', [
                'name_ar' => 'لجنة شؤون الموظفين',
                'formation_decision_number' => '44',
                'formation_decision_date' => '2026-01-15',
                'term_note' => 'سنة قابلة للتجديد',
                'legal_basis' => 'قانون علاقات العمل ولائحته التنفيذية',
                'minutes_approval_body' => 'عميد البلدية',
                'voting_rights_note' => 'لكل عضو صوت واحد عدا المقرر',
                'minutes_signature_rule' => 'يوقع المحضر الرئيس والمقرر وجميع الحاضرين',
                'recusal_rules' => 'يتنحى العضو في موضوع يخص قريبًا حتى الدرجة الرابعة',
                'quorum_type' => 'fraction',
                'quorum_numerator' => 2,
                'quorum_denominator' => 3,
                'quorum_comparator' => 'at_least',
                'quorum_text' => 'ثلثا الأعضاء',
                'majority_type' => 'fraction',
                'majority_basis' => 'present',
                'majority_numerator' => 1,
                'majority_denominator' => 2,
                'majority_comparator' => 'more_than',
                'majority_text' => 'أغلبية الحاضرين',
                'tie_break' => 'chair_casting_vote',
                'tie_break_text' => 'عند التساوي يرجح الجانب الذي فيه الرئيس',
            ])
            ->assertCreated()
            ->assertJsonPath('data.quorum_text', 'ثلثا الأعضاء')
            ->assertJsonPath('data.formation_decision_number', '44')
            ->assertJsonPath('data.formation_decision_date', '2026-01-15')
            ->assertJsonPath('data.tie_break', 'chair_casting_vote')
            ->assertJsonPath('data.rules_recorded', true);

        $id = $created->json('data.id');

        // Found by id rather than by position: the chair now sits on the
        // bootstrap committee above as well, so the list holds more than one.
        $listed = collect(
            $this->actingAs($head, 'sanctum')->getJson('/api/committees')->assertOk()->json('data')
        )->firstWhere('id', $id);

        $this->assertSame('عميد البلدية', $listed['minutes_approval_body']);

        // A fraction with no comparator is refused rather than defaulted:
        // "لا يقل عن النصف" and "أكثر من النصف" are different numbers, and
        // picking one for the reader is exactly what Appendix 64 forbids.
        $this->actingAs($head, 'sanctum')
            ->putJson("/api/committees/{$id}", [
                'name_ar' => 'لجنة شؤون الموظفين',
                'quorum_type' => 'fraction',
                'quorum_numerator' => 1,
                'quorum_denominator' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('quorum_comparator');
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('code', $roleCode)->value('id'));

        return $user;
    }
}
