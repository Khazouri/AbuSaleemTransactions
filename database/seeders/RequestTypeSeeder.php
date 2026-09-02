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
 * (stage 9) rather than being settled inside the municipality. It is a
 * per-type column so a category could later be given a different bar without
 * touching code. Stage 18 enforces it.
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
 * required_documents (Stage 53) is a soft, informational intake checklist —
 * never validated or enforced server-side, just surfaced on the intake
 * screen once a type is chosen. `official-procedures-manual-index.md` §7 is
 * a structured index of [D]'s 142 pages, not a verbatim transcription, and
 * does not reproduce Arts. 48–79's literal per-type document appendices —
 * so every list below is derived from what that index *does* say each
 * chapter requires (e.g. Arts. 75–79's grievance intake fields are described
 * almost verbatim there and are used directly for GRIV/PEVG), not copied
 * from an appendix this session could not read. Treat this as a reasonable
 * starting checklist, not a sourced transcription — replace outright, don't
 * layer on top, once a future session has the actual appendix pages in hand.
 */
class RequestTypeSeeder extends Seeder
{
    public function run(): void
    {
        // [code, Arabic name, English name, SLA in days, has financial impact by default, required_documents]
        $types = [
            ['PROM', 'ترقية', 'Promotion', 15, true, [
                ['ar' => 'ملف استحقاق الترقية (شروط قانونية ووظيفية ومالية)', 'en' => 'Promotion entitlement file (legal, employment, and financial conditions)'],
                ['ar' => 'آخر تقرير تقييم أداء', 'en' => 'Latest performance evaluation report'],
                ['ar' => 'قرار التعيين أو آخر ترقية سابقة', 'en' => 'Appointment decision or most recent prior promotion decision'],
                ['ar' => 'تأكيد وجود شاغر ضمن قائمة الأقدمية الوظيفية للمجموعة', 'en' => "Confirmation of a vacancy within the functional group's seniority list"],
            ]],
            ['LEAV', 'إجازة', 'Leave', 7, false, [
                ['ar' => 'طلب الإجازة موضحًا نوعها ومدتها', 'en' => 'Leave request stating its type and duration'],
                ['ar' => 'مسوغ الإجازة (تقرير طبي أو مستند داعم آخر)', 'en' => 'Supporting justification (medical report or other supporting document)'],
                ['ar' => 'موافقة الرئيس المباشر', 'en' => "Direct manager's approval"],
            ]],
            ['ALLW', 'علاوة', 'Allowance', 10, true, [
                ['ar' => 'طلب صرف العلاوة موضحًا نوعها وسندها', 'en' => 'Allowance request stating its type and its legal basis'],
                ['ar' => 'كشف الوضع المالي والوظيفي الحالي للموظف', 'en' => 'Current financial and employment status statement'],
                ['ar' => 'المستند القانوني أو التنظيمي المثبت للاستحقاق', 'en' => 'The legal or regulatory document establishing entitlement'],
            ]],
            ['SECD', 'انتداب', 'Secondment', 15, false, [
                ['ar' => 'طلب الندب موضحًا الجهة المستفيدة ومدته (كليًا أو جزئيًا)', 'en' => 'Secondment request stating the receiving body and duration (whole or partial)'],
                ['ar' => 'موافقة الجهة المُعار إليها', 'en' => "The receiving body's consent"],
                ['ar' => 'تقرير الرئيس المباشر عن أثر الندب على سير العمل', 'en' => "Direct manager's report on the secondment's impact on work continuity"],
            ]],
            ['GRIV', 'تظلم', 'Grievance', 20, false, [
                ['ar' => 'بيان التظلم مقابل القرار الأصلي محل التظلم', 'en' => 'The grievance statement against the original decision'],
                ['ar' => 'تاريخ العلم بالقرار', 'en' => 'Date of knowledge of the decision'],
                ['ar' => 'أسباب التظلم', 'en' => 'Grounds for the grievance'],
                ['ar' => 'الطلب المحدد (ما يريده المتظلم)', 'en' => 'The specific request/ask'],
                ['ar' => 'المستندات المؤيدة', 'en' => 'Supporting documents'],
            ]],
            ['TRNS', 'نقل', 'Transfer', 15, false, [
                ['ar' => 'طلب النقل موضحًا ما إذا كان داخل التقسيمات التنظيمية للبلدية أو بين جهات مختلفة', 'en' => "Transfer request stating whether it is within the municipality's organizational units or between different bodies"],
                ['ar' => 'موافقة الجهة المستقبلة', 'en' => "The receiving unit's consent"],
                ['ar' => 'تقرير الرئيس المباشر الحالي', 'en' => "Current direct manager's report"],
            ]],
            ['EOSV', 'إنهاء خدمة', 'End of Service', 20, false, [
                ['ar' => 'طلب إنهاء الخدمة موضحًا السبب', 'en' => 'End-of-service request stating the reason'],
                ['ar' => 'كشف الوضع الوظيفي والمالي النهائي', 'en' => 'Final employment and financial status statement'],
                ['ar' => 'إخلاء الطرف من العهد والمستحقات', 'en' => 'Clearance of custody items and dues'],
            ]],
            ['CONF', 'تثبيت بعد الاختبار', 'Confirmation after probation', 15, false, [
                ['ar' => 'تقرير تقييم أداء فترة الاختبار', 'en' => 'Probation-period performance evaluation report'],
                ['ar' => 'توصية الرئيس المباشر (استمرار / نقل / اقتراح إنهاء الخدمة)', 'en' => "Direct manager's recommendation (continuation / transfer / proposed termination)"],
                ['ar' => 'بيان مدة الاختبار الفعلية (365 يومًا افتراضيًا)', 'en' => 'Statement of the actual probation duration (365 days by default)'],
            ]],
            ['APPT', 'تعيين', 'Appointment', 15, false, [
                ['ar' => 'طلب التعيين وملف المرشح الوظيفي', 'en' => "Appointment request and the candidate's employment file"],
                ['ar' => 'تأكيد توفر الشاغر الوظيفي', 'en' => 'Confirmation of an available vacant position'],
                ['ar' => 'المستندات المؤهلة (الشهادات والخبرات)', 'en' => 'Qualifying documents (certificates and experience)'],
            ]],
            ['CTRC', 'تعاقد', 'Contracting', 15, false, [
                ['ar' => 'طلب التعاقد وشروطه', 'en' => 'Contracting request and its terms'],
                ['ar' => 'نسخة من العقد المقترح', 'en' => 'Copy of the proposed contract'],
                ['ar' => 'المستندات المؤهلة للمتعاقد', 'en' => "The contractor's qualifying documents"],
            ]],
            ['SETL', 'تسوية وضع وظيفي', 'Employment status settlement', 15, true, [
                ['ar' => 'بيان محدد لموضوع التسوية (لا يُقبل طلب عام)', 'en' => 'A specific statement of what is being settled (a generic request is not accepted)'],
                ['ar' => 'جدول موثق ومؤرخ للمدد أو الأقدمية محل التسوية', 'en' => 'A documented, dated schedule of the durations/seniority being settled'],
                ['ar' => 'المستندات الداعمة لصحة البيانات', 'en' => "Supporting documents verifying the data's accuracy"],
            ]],
            ['PEVG', 'تظلم من تقييم أداء', 'Performance evaluation grievance', 20, false, [
                ['ar' => 'نسخة من تقرير تقييم الأداء محل التظلم', 'en' => 'Copy of the performance evaluation report being grieved'],
                ['ar' => 'بيان الأثر القانوني أو الوظيفي المترتب على التقرير', 'en' => "Statement of the report's legal or employment effect"],
                ['ar' => 'أسباب التظلم والمستندات المؤيدة', 'en' => 'Grounds for the grievance and supporting documents'],
            ]],
        ];

        foreach ($types as [$code, $nameAr, $nameEn, $sla, $hasFinancialImpact, $requiredDocuments]) {
            RequestType::updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'default_sla_days' => $sla,
                    'decision_grade_threshold' => 10,
                    'is_active' => true,
                    'default_has_financial_impact' => $hasFinancialImpact,
                    'required_documents' => $requiredDocuments,
                ],
            );
        }
    }
}
