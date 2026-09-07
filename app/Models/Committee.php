<?php

namespace App\Models;

use App\Services\CommitteeVotingRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Committee (اللجنة) — a standing body that reviews requests in meetings.
 *
 * @property string $name_ar
 * @property string|null $name_en
 * @property string|null $description
 * @property bool $is_active
 * @property bool $rapporteur_votes Stage 48 — this committee's own tashkil
 *                                  decision grants its rapporteur a substantive vote, not just the seat.
 *
 * Stage 73 — the rest of [D] Appendix 65's بطاقة تعريف اللجنة, transcribed
 * from the committee's قرار التشكيل. The quorum/majority columns are
 * deliberately nullable with no default: Appendix 64 forbids the system
 * inventing either figure, so an untranscribed committee has no quorum and
 * `CommitteeVotingRules` reports that rather than computing one.
 */
class Committee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name_ar',
        'name_en',
        'description',
        'is_active',
        'rapporteur_votes',
        // Stage 73 — Appendix 65's identity card.
        'formation_decision_number',
        'formation_decision_date',
        'term_note',
        'legal_basis',
        'minutes_approval_body',
        'voting_rights_note',
        'minutes_signature_rule',
        'recusal_rules',
        'quorum_type',
        'quorum_count',
        'quorum_numerator',
        'quorum_denominator',
        'quorum_comparator',
        'quorum_text',
        'majority_type',
        'majority_basis',
        'majority_numerator',
        'majority_denominator',
        'majority_comparator',
        'majority_text',
        'tie_break',
        'tie_break_text',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rapporteur_votes' => 'boolean',
            'formation_decision_date' => 'date',
            'quorum_count' => 'integer',
            'quorum_numerator' => 'integer',
            'quorum_denominator' => 'integer',
            'majority_numerator' => 'integer',
            'majority_denominator' => 'integer',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommitteeMember::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    /**
     * Stage 73 — the transcribed quorum/majority/tie rules, or an empty set
     * when this committee's قرار التشكيل has not been recorded yet.
     */
    public function votingRules(): CommitteeVotingRules
    {
        return CommitteeVotingRules::fromCommittee($this);
    }

    /** Members currently eligible to be invited to a new meeting. */
    public function activeMembers(): HasMany
    {
        return $this->members()->whereHas('user', fn ($query) => $query->where('is_active', true));
    }
}
