<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RequestType (نوع الطلب) — promotion, leave, grievance, etc.
 *
 * Beyond labelling, type carries two rules:
 *   - default_sla_days          how long the request may take (Stage 17)
 *   - decision_grade_threshold  the decision grade at/above which the case must
 *                               go to وزارة الحكم المحلي (Stage 18)
 *
 * @property string|null $code
 * @property int|null $default_sla_days
 * @property int|null $decision_grade_threshold Typically 10
 * @property bool $is_active
 * @property bool $default_has_financial_impact Stage 47 — starting value for a
 *                                              new request's own has_financial_impact flag; overridable per request.
 * @property array|null $required_documents Stage 72 — [D] Appendix 57's
 *                                          مصفوفة المستندات الإلزامية for this type, as
 *                                          {ar, en, group, condition} entries: `group` is `basic`
 *                                          (the appendix's shared table, merged into every type) or
 *                                          `specific` (this type's own ملف), and `condition` is a
 *                                          nullable {ar, en} pair carrying the source's own inline
 *                                          qualifier, which is what makes an entry Appendix 57's
 *                                          third group (المشروطة). Soft and informational — never
 *                                          enforced server-side. See RequestTypeSeeder's docblock
 *                                          for the exclusion rules and the four types [D] does not
 *                                          cover.
 * @property string|null $default_administrative_route Stage 56 — a soft,
 *                                                     advisory suggestion (hr|diwan|committee_secretary) for which
 *                                                     of administrative_routing's 3 manual routes fits this type;
 *                                                     never enforced, all 3 stay freely selectable.
 * @property string|null $legal_basis_ar Stage 68 — [D] Appendix 21's السند
 *                                       الأساسي for this subject. Pre-fills Appendix 22's بطاقة السند
 *                                       القانوني on the legal-review form; never enforced. Null for the
 *                                       six types Appendix 21 does not cover — an honest gap, not a
 *                                       missing seed. Arabic-only: these cite Libyan statute.
 * @property string|null $legal_basis_note_ar Appendix 21's ملاحظة إجرائية for the same row.
 */
class RequestType extends Model
{
    /**
     * Stage 56's three administrative_routing targets.
     *
     * Named here rather than inline in the FormRequest so the validator and
     * the admin screen's picker read one list — the value is advisory (all
     * three routes stay freely selectable whatever a type suggests), so a
     * drifted fourth value would mislead silently rather than fail.
     */
    public const ADMINISTRATIVE_ROUTES = ['hr', 'diwan', 'committee_secretary'];

    /**
     * The two groups a required_documents entry may belong to — [D] Appendix
     * 57's أساسية مشتركة and الخاصة بالنوع.
     *
     * The appendix's third group (المشروطة) is not a value here: the source
     * expresses it as a per-row qualifier, which is what the entry's own
     * `condition` carries. Its fourth (الناتجة عن دورة اللجنة) is never
     * stored at all — those documents only exist after the file reaches the
     * committee, so they cannot be an intake checklist item. Mirrored on the
     * frontend by lib/requiredDocuments.js's DOCUMENT_GROUPS.
     */
    public const DOCUMENT_GROUPS = ['basic', 'specific'];

    /**
     * Column defaults restated in PHP.
     *
     * The database applies these on INSERT, but the model create() hands
     * back does not re-read the row — so without this a freshly created
     * type answers `is_active: null` for a row the database has as true,
     * and the admin screen would render a brand-new type as retired. Same
     * fix GuideArticle and MeetingRequest already carry.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'default_has_financial_impact' => false,
    ];

    protected $fillable = [
        'code',
        'name_ar',
        'name_en',
        'default_sla_days',
        'decision_grade_threshold',
        'is_active',
        'default_has_financial_impact',
        'required_documents',
        'default_administrative_route',
        'legal_basis_ar',
        'legal_basis_note_ar',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_has_financial_impact' => 'boolean',
            'required_documents' => 'array',
        ];
    }

    /**
     * Requests filed under this type.
     *
     * Read as a count by the delete guard, and that guard exists because the
     * FK on the other side is nullOnDelete: removing a referenced type would
     * silently strip the type off every historical request instead of failing.
     */
    public function requests(): HasMany
    {
        return $this->hasMany(Request::class);
    }

    /**
     * Workflow rules scoped to this type alone — the per-type overrides
     * workflow_transitions allows.
     *
     * Also read only as a count, for the opposite reason to the relation
     * above: that FK is cascadeOnDelete, so deleting the type would take its
     * own workflow overrides with it without anyone being told.
     */
    public function workflowTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class);
    }
}
