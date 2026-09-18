<?php

namespace Database\Seeders;

use App\Models\RequestType;
use Illuminate\Database\Seeder;

/**
 * Seeds the staff-affairs request types handled by the committee.
 *
 * Each type sets its own SLA — a leave request is expected to clear in a week,
 * a grievance gets twenty days. Stage 17 turns that into a due_date and flags
 * anything that overruns.
 *
 * decision_grade_threshold is 10 for every type: per the workflow spec, a
 * decision of grade 10 or above must be escalated to وزارة الحكم المحلي
 * rather than being settled inside the municipality. It is a per-type column
 * so a category could later be given a different bar without touching code.
 * Stage 18 enforces it.
 *
 * default_has_financial_impact (Stage 47) is true for PROM/ALLW/SETL — the
 * three of Stage 47's own example list ("promotion, settlement, allowance,
 * back-pay, grade change") that now have a matching RequestType row; every
 * other type defaults false and is still overridable per request.
 *
 * Stage 53 — CONF/APPT/CTRC/SETL/PEVG are new rows closing named gaps against
 * [D] Art. 46's six رئيسية functional groups (التعيين، التعاقد، الترقية،
 * الندب، الإعارة، النقل) and Art. 47's secondary categories: تثبيت بعد
 * الاختبار (Arts. 48–51), التعيين/التعاقد as first-class types, تسوية وضع
 * وظيفي (Arts. 72–74), and تظلم من تقييم أداء (Arts. 70–71 + 75–79) kept
 * distinct from the generic GRIV grievance since a grievance against a
 * performance report is its own legal track. الإعارة (Arts. 65–66,
 * loan-to-another-body) is deliberately NOT added as a further separate row
 * — SECD already occupies the "temporary assignment elsewhere" space closely
 * enough that a second, near-synonymous type wasn't named in the stage's own
 * scope.
 *
 * ---
 *
 * required_documents — STAGE 72 REPLACED STAGE 53'S LISTS OUTRIGHT, per that
 * stage's own instruction ("replace outright, don't layer on top, once a
 * future session has the actual appendix pages in hand"). Every entry below
 * is now traceable to [D] **Appendix 57** (مصفوفة المستندات الإلزامية حسب نوع
 * المعاملة) or to the type's own chapter in Arts. 48–79, which is consulted
 * only where the appendix omits something.
 *
 * [D] **Appendix 4** is deliberately NOT a source here. It is the committee's
 * *internal* file-completeness checklist and its entries include internal
 * actions (الرأي القانوني، مذكرة الموارد البشرية) an employee would never
 * attach; this column is the checklist shown to whoever files the request and
 * to whoever performs Art. 18's فحص اكتمال الملف, so it carries
 * client-submittable file contents only.
 *
 * SHAPE. Appendix 57's own rule is that documents fall into four groups
 * (أساسية مشتركة / خاصة بالنوع / مشروطة / ناتجة عن دورة اللجنة), so each
 * entry is `{ar, en, group, condition}`:
 *   - `group` is `basic` (Appendix 57's shared 12-row table, merged into every
 *     type below) or `specific` (that type's own ملف in the appendix).
 *   - `condition` is a nullable bilingual pair carrying the source's own
 *     inline qualifier ("بحسب الموضوع"، "عند الحاجة"، "متى كان ذلك شرطًا").
 *     An entry that has one IS Appendix 57's third group, المشروطة — the
 *     appendix expresses that as a per-row qualifier, not as a separate list.
 *   - Appendix 57's fourth group, الناتجة عن دورة اللجنة, is **never seeded**:
 *     those documents come into being after the file reaches the committee,
 *     so they cannot be an intake checklist item.
 * `ar`/`en` stay the first two keys so existing readers keep working.
 *
 * FOUR EXCLUSION RULES, applied uniformly:
 *   1. الرأي القانوني — Stage 68's `request_legal_reviews` record, produced by
 *      the committee's own legal member, not supplied with the file.
 *   2. مذكرة/إفادة/رأي إدارة الموارد البشرية and الرأي الإداري — same
 *      category: internal opinions the committee cycle produces.
 *   3. السند القانوني — Appendix 22's بطاقة السند القانوني is a field Stage 68
 *      already captures from the legal officer; asking for it again as an
 *      attachment would duplicate a record the system holds.
 *   4. إحالة الرئيس المباشر / إحالة الجهة الإدارية المعنية (Appendix 57's
 *      basics rows 2–3) — this system records both as workflow transitions
 *      (`direct_manager_review → forward`, `administrative_routing →
 *      route_to_*`), so listing them would ask for a paper duplicate of a step
 *      the state machine already performs. A *substantive* opinion from the
 *      same people (TRNS's إفادة الرئيس المباشر، رأي الجهة المنقول منها) is a
 *      different artifact and is kept.
 * A per-type item that is literally the same document as a basics row is not
 * repeated; a type-specific variant of one (CONF's "تقارير الأداء أو المتابعة
 * خلال فترة الاختبار", mandatory where the basics row is conditional) is kept.
 *
 * ALLW, EOSV, APPT and CTRC carry the shared basics and **no specific list,
 * and that is the honest answer rather than an oversight**: Arts. 48–79 have
 * chapters for probation, promotion, transfer, secondment, loan, leave,
 * performance, durations/settlement and grievances, and none for those four —
 * the only علاوة mentions in [D] are Art. 69's effect-on-allowance clause, and
 * إنهاء الخدمة appears only as a probation *outcome*. Same honest-gap
 * convention Stage 68 used when only six of twelve types had an Appendix 21
 * citation. Nothing is validated or enforced server-side either way.
 *
 * ---
 *
 * default_administrative_route (Stage 56) is a soft, advisory suggestion for
 * which of administrative_routing's 3 manual routes (hr/diwan/
 * committee_secretary) fits this type — surfaced as a badge on the SPA's
 * matching action button, never enforced; all 3 routes stay freely
 * selectable regardless. [D] Arts. 11/16 and [E] stage 03 only say routing
 * is "بحسب الموضوع" (by subject matter) without naming which subject maps
 * to which of the three, so this split is a documented judgment call, not a
 * sourced mapping. Replace outright, don't layer on top, once a future
 * session has the actual per-subject mapping.
 */
class RequestTypeSeeder extends Seeder
{
    /**
     * Stage 72 — the recurring qualifiers Appendix 57 writes inline against
     * its own rows, kept as constants so the same wording cannot drift between
     * two types that quote the same condition.
     */
    private const BY_SUBJECT = ['ar' => 'بحسب الموضوع', 'en' => 'Depending on the subject'];

    private const IF_RELATED = ['ar' => 'عند ارتباطه بالموضوع', 'en' => 'When related to the subject'];

    private const WHEN_NEEDED = ['ar' => 'عند الحاجة', 'en' => 'When needed'];

    private const BY_CASE = ['ar' => 'بحسب الحالة', 'en' => 'Depending on the case'];

    private const IF_ANY = ['ar' => 'إن وجدت', 'en' => 'If any'];

    public function run(): void
    {
        $common = $this->commonDocuments();

        // Stage 72 — Appendix 57's per-type ملف, one entry per numbered item in
        // the source. The four types absent from this map carry the shared
        // basics only; see the class docblock for why that is deliberate.
        $specificDocuments = $this->specificDocuments();

        // [code, Arabic name, English name, SLA in days, has financial impact by default]
        $types = [
            ['PROM', 'ترقية', 'Promotion', 15, true],
            ['LEAV', 'إجازة', 'Leave', 7, false],
            ['ALLW', 'علاوة', 'Allowance', 10, true],
            ['SECD', 'انتداب', 'Secondment', 15, false],
            ['GRIV', 'تظلم', 'Grievance', 20, false],
            ['TRNS', 'نقل', 'Transfer', 15, false],
            ['EOSV', 'إنهاء خدمة', 'End of Service', 20, false],
            ['CONF', 'تثبيت بعد الاختبار', 'Confirmation after probation', 15, false],
            ['APPT', 'تعيين', 'Appointment', 15, false],
            ['CTRC', 'تعاقد', 'Contracting', 15, false],
            ['SETL', 'تسوية وضع وظيفي', 'Employment status settlement', 15, true],
            ['PEVG', 'تظلم من تقييم أداء', 'Performance evaluation grievance', 20, false],
        ];

        // Stage 56 — suggested administrative_routing target per type, see
        // the class docblock for the reasoning.
        $administrativeRoutes = [
            'PROM' => 'committee_secretary',
            'LEAV' => 'committee_secretary',
            'ALLW' => 'committee_secretary',
            'SECD' => 'diwan',
            'GRIV' => 'committee_secretary',
            'TRNS' => 'diwan',
            'EOSV' => 'committee_secretary',
            'CONF' => 'committee_secretary',
            'APPT' => 'hr',
            'CTRC' => 'hr',
            'SETL' => 'committee_secretary',
            'PEVG' => 'committee_secretary',
        ];

        // Stage 68 — [D] Appendix 21's مصفوفة السند القانوني للموضوعات
        // الوظيفية, transcribed verbatim from the appendix's own table
        // (source-manual-verbatim.md) as [السند الأساسي, الملاحظة الإجرائية].
        //
        // Only SIX of the twelve types appear there. The appendix's other
        // rows are structural, not request subjects (إنشاء اللجنة، القرارات
        // الوظيفية، سلامة القرار، ملف خدمة الموظف، أعمال قسم شؤون الموظفين)
        // and الإعارة has no matching type here (see the docblock's note on
        // why SECD was not split). LEAV/ALLW/GRIV/EOSV/APPT/CTRC are
        // deliberately absent rather than given an invented citation — the
        // legal officer fills Appendix 22's card by hand for those, which is
        // an honest gap, not a missing seed.
        //
        // Arabic-only, no _en sibling: these cite Libyan statute, and a
        // translated legal citation would be worse than none.
        $legalBases = [
            'CONF' => ['المادة 135 من قانون علاقات العمل', 'تتضمن إحالة حالات الموظف تحت الاختبار للجنة في الحالات التي نص عليها القانون'],
            'PROM' => ['المواد المنظمة لشغل الوظائف والترقية', 'يشترط التحقق من الوظيفة والمجموعة الوظيفية والأقدمية والضوابط المقررة'],
            'TRNS' => ['مواد شغل الوظائف والحركة الوظيفية', 'يحدد المسار حسب نوع النقل والجهة المختصة'],
            'SECD' => ['مواد شغل الوظائف', 'يعالج وفق الضوابط المقررة للندب'],
            'PEVG' => ['المادة 177 وما تنظمه اللائحة التنفيذية', 'لا تحل اللجنة محل الرئيس المباشر في إعداد التقييم'],
            'SETL' => ['المادة 178 وما يتصل بها', 'تعتمد الحسابات على مدد موثقة'],
        ];

        foreach ($types as [$code, $nameAr, $nameEn, $sla, $hasFinancialImpact]) {
            RequestType::updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'default_sla_days' => $sla,
                    'decision_grade_threshold' => 10,
                    'is_active' => true,
                    'default_has_financial_impact' => $hasFinancialImpact,
                    'required_documents' => [...$common, ...($specificDocuments[$code] ?? [])],
                    'default_administrative_route' => $administrativeRoutes[$code],
                    'legal_basis_ar' => $legalBases[$code][0] ?? null,
                    'legal_basis_note_ar' => $legalBases[$code][1] ?? null,
                ],
            );
        }
    }

    /**
     * Stage 72 — [D] Appendix 57's المستندات الأساسية المشتركة table, which
     * the appendix itself says "توجد في أغلب المعاملات", so it is merged into
     * every type rather than referenced. Three of its twelve rows are dropped:
     * إحالة الرئيس المباشر and إحالة الجهة الإدارية المعنية (both recorded as
     * workflow transitions here) and الرأي القانوني (Stage 68's own record).
     * The remaining nine keep the appendix's own الحالة column as `condition`.
     *
     * @return list<array{ar: string, en: string, group: string, condition: array{ar: string, en: string}|null}>
     */
    private function commonDocuments(): array
    {
        return [
            $this->basic(
                'طلب الموظف',
                "The employee's own request",
                ['ar' => 'عند كون المعاملة بطلب منه', 'en' => 'Where the matter originates from the employee'],
                'request',
            ),
            // The seven service-record extracts below are literally الملف
            // الوظيفي; only the first and last of the nine basics are not.
            $this->basic('البيانات الوظيفية', 'Employment data statement', null, 'service_file'),
            $this->basic('قرار التعيين', 'Appointment decision', self::BY_SUBJECT, 'service_file'),
            $this->basic('مباشرة العمل', 'Proof of the date work commenced', self::BY_SUBJECT, 'service_file'),
            $this->basic('آخر قرار وظيفي ذي علاقة', 'Most recent related employment decision', self::BY_SUBJECT, 'service_file'),
            $this->basic('كشف الخدمة', 'Service statement', self::BY_SUBJECT, 'service_file'),
            $this->basic('المؤهل العلمي', 'Academic qualification', self::IF_RELATED, 'service_file'),
            $this->basic('تقارير الأداء', 'Performance reports', self::IF_RELATED, 'service_file'),
            $this->basic('المستندات المؤيدة للطلب', 'Documents supporting the request', self::BY_CASE, 'supporting_documents'),
        ];
    }

    /**
     * Stage 72 — one list per type that [D] actually covers, keyed by code.
     *
     * @return array<string, list<array{ar: string, en: string, group: string, condition: array{ar: string, en: string}|null}>>
     */
    private function specificDocuments(): array
    {
        return [
            // Appendix 57 أولًا — ملف الترقية, plus Art. 54's الإفادة المالية.
            'PROM' => [
                $this->specific(
                    'كشف بأسماء المرشحين',
                    'List of candidate names',
                    ['ar' => 'عند العرض الجماعي', 'en' => 'When presented as a group'],
                ),
                $this->specific('آخر قرار ترقية', 'Most recent promotion decision'),
                $this->specific('بيان الدرجة الحالية', 'Current grade statement'),
                $this->specific('المسمى الوظيفي', 'Job title'),
                $this->specific('المجموعة الوظيفية', 'Functional group'),
                $this->specific('كشف الأقدمية', 'Seniority schedule'),
                $this->specific('بيانات الوظيفة المستهدفة', 'Data on the target position'),
                $this->specific('بيان المالك الوظيفي', 'Staffing-establishment statement', self::WHEN_NEEDED),
                $this->specific(
                    'ما يثبت توفر الوظيفة الشاغرة',
                    'Proof that a vacant position exists',
                    ['ar' => 'متى كان ذلك شرطًا', 'en' => 'Where it is a stated condition'],
                ),
                $this->specific('بيان الجزاءات أو الموانع القانونية المؤثرة', 'Statement of sanctions or legal impediments in effect', self::IF_ANY),
                $this->specific(
                    'الإفادة المالية',
                    'Financial coverage statement',
                    ['ar' => 'عند اشتراط التغطية المالية', 'en' => 'Where financial coverage is a stated condition'],
                ),
            ],

            // Arts. 67–69. Appendix 57 has no leave file at all; Art. 67 says
            // ordinary leave is not brought before the committee merely for
            // being leave, which is why the second item asks why it is here.
            'LEAV' => [
                $this->specific(
                    'نموذج طلب الإجازة متضمنًا نوعها وتاريخ بدئها وانتهائها والرصيد واسم ووظيفة المخول بمنحها',
                    'Leave-request form stating the leave type, its start and end dates, the remaining balance, and the name and position of the officer authorised to grant it',
                ),
                $this->specific(
                    'بيان سبب عرض الإجازة على اللجنة (نص تشريعي، أو أثر جوهري في الوضع الوظيفي، أو خلاف في احتساب المدة، أو أثر في الأقدمية أو الترقية، أو إحالة من جهة الاختصاص بسند قانوني)',
                    'Statement of why the leave is being brought before the committee (an express legal provision, a material change to the employment position, a dispute over how the period is counted, an effect on seniority or promotion, or a referral from the competent body on a legal basis)',
                ),
                $this->specific(
                    'بيان أثر مدة الإجازة على الأقدمية والترقية والعلاوة',
                    "Statement of the leave period's effect on seniority, promotion and the annual allowance",
                    ['ar' => 'عند الإجازة الخاصة بدون مرتب', 'en' => 'For unpaid special leave'],
                ),
                $this->specific('بيان سلطة منح الإجازة', 'Statement of the authority empowered to grant the leave'),
            ],

            // Appendix 57 ثالثًا — ملف الندب, plus Art. 62's determinations.
            'SECD' => [
                $this->specific('طلب أو كتاب الجهة طالبة الندب', "The secondment request or the requesting body's letter"),
                $this->specific(
                    'بيان ما إذا كان الندب داخل البلدية أم إلى جهة أخرى، وكليًا أم جزئيًا',
                    'Statement of whether the secondment is inside the municipality or to another body, and whether it is full or partial',
                ),
                $this->specific('الوظيفة الحالية', 'Current position'),
                $this->specific('الوظيفة المنتدب إليها', 'The position seconded to'),
                $this->specific('الجهة الأصلية', 'The original employing body'),
                $this->specific('الجهة المستفيدة', 'The receiving body'),
                $this->specific('مدة الندب', 'Duration of the secondment'),
                $this->specific('سبب الندب', 'Reason for the secondment'),
                $this->specific('موقف جهة العمل الأصلية من الندب', "The original body's position on the secondment"),
                $this->specific('الأثر المالي', 'Financial effect', ['ar' => 'عند وجوده', 'en' => 'Where there is one']),
                $this->specific('الموافقات التي يستلزمها نوع الندب', 'The approvals this kind of secondment requires'),
            ],

            // Appendix 57 ثانيًا — ملف النقل (Art. 60 adds nothing it omits).
            'TRNS' => [
                $this->specific('طلب النقل أو مذكرة اقتراح النقل', 'Transfer request or a memo proposing the transfer'),
                $this->specific('إفادة الرئيس المباشر', "The direct manager's statement"),
                $this->specific('بيان الوظيفة الحالية', 'Current position statement'),
                $this->specific('الدرجة الوظيفية', 'Grade'),
                $this->specific('المجموعة الوظيفية', 'Functional group'),
                $this->specific('جهة العمل الحالية', 'Current employing unit'),
                $this->specific('الجهة المطلوب النقل إليها', 'The unit to which transfer is requested'),
                $this->specific('الوظيفة المقترحة', 'The proposed position'),
                $this->specific('رأي الجهة المنقول منها', "The releasing unit's opinion"),
                $this->specific('رأي أو موافقة الجهة المنقول إليها', "The receiving unit's opinion or consent", self::BY_CASE),
                $this->specific('بيان المالك الوظيفي', 'Staffing-establishment statement'),
                $this->specific('المستندات المتعلقة بحاجة العمل', 'Documents establishing the operational need'),
                $this->specific('الموافقات الأخرى التي يقررها التشريع بحسب نوع النقل', 'Any further approvals the legislation requires for this kind of transfer'),
            ],

            // Appendix 57 خامسًا — ملف فترة الاختبار, plus Art. 49's
            // الوظيفة والدرجة والمجموعة الوظيفية.
            'CONF' => [
                $this->specific('تاريخ بداية فترة الاختبار', 'The date the probation period began'),
                $this->specific('بيان المدة المنقضية من فترة الاختبار', 'Statement of the probation time elapsed'),
                $this->specific('الوظيفة والدرجة والمجموعة الوظيفية', 'The position, grade and functional group'),
                $this->specific('تقارير الرئيس المباشر', "The direct manager's reports"),
                $this->specific('تقارير الأداء أو المتابعة خلال فترة الاختبار', 'Performance or follow-up reports covering the probation period'),
                $this->specific('بيان الانقطاع والغياب والإجازات المؤثرة', 'Statement of interruptions, absences and leave that affect the period'),
                $this->specific('أي وقائع مثبتة رسميًا مرتبطة بالصلاحية', 'Any formally recorded facts bearing on fitness for the post', self::IF_ANY),
                $this->specific(
                    'بيانات الوظيفة البديلة',
                    'Data on the alternative position',
                    ['ar' => 'إذا كان النقل مطروحًا', 'en' => 'Where a transfer is being considered'],
                ),
            ],

            // Appendix 57 سادسًا — ملف التسوية الوظيفية, plus Art. 74's
            // "لا تقبل عبارة عامة" rule, Art. 72's documented-schedule rule and
            // Art. 73's three period components.
            'SETL' => [
                $this->specific(
                    'تحديد نوع التسوية بدقة — درجة أو مؤهل أو خبرة سابقة أو أقدمية أو مرتب أو تاريخ استحقاق أو وظيفة أو مجموعة وظيفية أو أثر قرار سابق (لا تقبل عبارة عامة مثل «أطلب تسوية وضعي الوظيفي»)',
                    'A precise statement of what is being settled — grade, qualification, prior experience, seniority, salary, entitlement date, position, functional group, or the effect of an earlier decision (a generic phrase such as "I request settlement of my employment status" is not accepted)',
                ),
                $this->specific('طلب محدد ببيان الأثر المطلوب', 'A specific request stating the effect sought'),
                $this->specific('بيان الدرجة الحالية', 'Current grade statement'),
                $this->specific('شهادات الخبرة السابقة', 'Prior experience certificates', self::IF_RELATED),
                $this->specific(
                    'كشف زمني موثق بطريقة احتساب المدد (لا يقبل التقدير التقريبي)',
                    'A documented chronological schedule showing how the periods were counted (an approximate estimate is not accepted)',
                ),
                $this->specific('مدد الانقطاع', 'Periods of interruption', self::WHEN_NEEDED),
                $this->specific('مدد الإجازات المؤثرة', 'Leave periods affecting the count', self::WHEN_NEEDED),
                $this->specific('مدد الندب أو الإعارة ذات العلاقة', 'Related secondment or loan periods', self::WHEN_NEEDED),
                $this->specific('القرارات السابقة ذات العلاقة', 'Earlier related decisions'),
                $this->specific(
                    'البيانات المالية',
                    'Financial data',
                    ['ar' => 'إذا كان للتسوية أثر مالي', 'en' => 'Where the settlement has a financial effect'],
                ),
            ],

            // Appendix 57 سابعًا — ملف التظلم, plus Art. 75's نسخة من القرار.
            // فحص الاختصاص and التحقق من المدة القانونية are dropped: both are
            // internal checks this system already performs (Stage 60's formal
            // verification, Stage 68's legal_deadline).
            'GRIV' => [
                $this->specific('صحيفة التظلم مكتوبة وموقعة', 'The written, signed grievance statement'),
                $this->specific('القرار أو الإجراء محل التظلم، ونسخة منه إن وجدت', 'The decision or action being grieved, with a copy of it if one exists'),
                $this->specific('تاريخ القرار', 'Date of the decision'),
                $this->specific('تاريخ العلم به', 'Date of knowledge of it', ['ar' => 'متى كان مؤثرًا', 'en' => 'Where it has legal effect']),
                $this->specific('أسباب التظلم', 'Grounds for the grievance'),
                $this->specific('الطلب المحدد', 'The specific relief requested'),
                $this->specific('ملف المعاملة الأصلية', "The original request's file"),
                $this->specific(
                    'محضر اللجنة السابق',
                    "The committee's earlier minutes",
                    ['ar' => 'عند ارتباط التظلم بقرار لجنة', 'en' => 'Where the grievance concerns a committee decision'],
                ),
            ],

            // Appendix 57 سابعًا's ملف التظلم, with the decision-under-grievance
            // items replaced by **Art. 71's own seven-item عند العرض list** —
            // which is the whole reason PEVG is a separate type from GRIV.
            'PEVG' => [
                $this->specific('صحيفة التظلم مكتوبة وموقعة', 'The written, signed grievance statement'),
                $this->specific('التقرير الأصلي محل التظلم', 'The original evaluation report being grieved'),
                $this->specific('الفترة التي يغطيها التقرير', 'The period the report covers'),
                $this->specific('الرئيس المسؤول عن التقييم', 'The manager responsible for the evaluation'),
                $this->specific('نتيجة التقييم', 'The evaluation result'),
                $this->specific('تاريخ العلم بالتقرير', 'Date of knowledge of the report', ['ar' => 'متى كان مؤثرًا', 'en' => 'Where it has legal effect']),
                $this->specific('أسباب التظلم', 'Grounds for the grievance'),
                $this->specific('الطلب المحدد', 'The specific relief requested'),
                $this->specific(
                    'ملاحظات الموظف على التقرير',
                    "The employee's observations on the report",
                    ['ar' => 'إن كان النظام يسمح بذلك', 'en' => 'Where the system allows it'],
                ),
                $this->specific('التقارير السابقة ذات العلاقة', 'Earlier related reports'),
                $this->specific('بيان الأثر الوظيفي الناتج عن التقييم', 'Statement of the employment effect arising from the evaluation'),
                $this->specific('ملف المعاملة الأصلية', "The original request's file"),
                $this->specific(
                    'محضر اللجنة السابق',
                    "The committee's earlier minutes",
                    ['ar' => 'عند ارتباط التظلم بقرار لجنة', 'en' => 'Where the grievance concerns a committee decision'],
                ),
            ],
        ];
    }

    /** @return array{ar: string, en: string, group: string, condition: array{ar: string, en: string}|null} */
    /**
     * A shared basic. `$section` is the [D] Appendix 14 folder a file
     * answering this row is stored in, declared here because the person
     * transcribing the matrix is the one who knows which it is — deriving it
     * from the label later would be guesswork. Omitted means المستندات
     * المؤيدة, which is what an unclassified supporting document actually is.
     */
    private function basic(string $ar, string $en, ?array $condition = null, ?string $section = null): array
    {
        return ['ar' => $ar, 'en' => $en, 'group' => 'basic', 'condition' => $condition, 'section' => $section];
    }

    /** @return array{ar: string, en: string, group: string, condition: array{ar: string, en: string}|null} */
    /**
     * A type-specific entry. These carry no `section`: Appendix 57's per-type
     * items are by definition the documents backing *this* request, which is
     * المستندات المؤيدة — the default, not a placeholder for an unmade choice.
     */
    private function specific(string $ar, string $en, ?array $condition = null): array
    {
        return ['ar' => $ar, 'en' => $en, 'group' => 'specific', 'condition' => $condition, 'section' => null];
    }
}
