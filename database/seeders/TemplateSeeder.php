<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * Stage 74 — [D] **Appendix 59** (بنك صيغ قرارات لجنة شؤون الموظفين), the
 * seven official Arabic decision formulas.
 *
 * Stage 35 built the template mechanism and Stage 42 made picking one draft
 * real text from the request's own data, but nothing was ever seeded — that
 * stage's own note records the screen as "entirely inert until an R08 admin
 * creates one". This closes that: the seven formulas the manual itself
 * publishes are the starting content, so the drafting rules Appendix 27 lays
 * down have official wording to be drafted from.
 *
 * **Transcribed verbatim, with one deliberate exception.** Where a blank's
 * own surrounding words name exactly what a Stage 42 `{{token}}` supplies —
 * "المعاملة رقم ..............." in the third and sixth formulas —
 * `{{reference_number}}` replaces the blank so DecisionDraftComposer does
 * real work instead of handing back a row of dots the recorder retypes.
 * Every other blank stays the source's own `...............`, because no
 * token exists for what it asks for (the subject of the approval, the body
 * being consulted, the condition that was not met) and inventing one would
 * be filling in the committee's own words.
 *
 * The formulas are single flowing "انتهت اللجنة إلى…" / "قررت اللجنة…"
 * statements, i.e. Appendix 27's **منطوق** — so the recording screen drops a
 * chosen draft into `decision_operative`, not into the notes field.
 *
 * `updateOrCreate` on `code`, so re-running restores a formula an admin
 * edited away from the manual's wording while leaving any template they
 * added of their own alone.
 */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            Template::updateOrCreate(
                ['code' => $template['code']],
                $template + ['category' => Template::CATEGORY_DECISION, 'is_active' => true],
            );
        }
    }

    /**
     * Arabic-only bodies, deliberately: these are the manual's own official
     * wording, and an invented English rendering of a Libyan administrative
     * formula would be worse than none — the same call Stage 68 made for
     * Appendix 21's legal-basis citations. DecisionDraftComposer already
     * falls back to the other language when one side is blank, so an English
     * session drafting from these gets the Arabic formula rather than an
     * empty box.
     *
     * @return list<array<string, string|null>>
     */
    private function templates(): array
    {
        return [
            [
                'code' => 'DEC-A59-01',
                'name_ar' => 'الصيغة الأولى — الموافقة',
                'name_en' => 'Formula 1 — Approval',
                'subject_ar' => 'الموافقة على {{request_title}}',
                'subject_en' => 'Approval of {{request_title}}',
                'body_ar' => 'بعد الاطلاع على المستندات المرفقة ودراسة الوضع الوظيفي والسند القانوني المنظم للموضوع، وبعد المناقشة والمداولة، انتهت اللجنة إلى الموافقة على ...............، وذلك لاستيفاء الشروط والمتطلبات المقررة، على أن يحال الموضوع إلى السلطة المختصة لاستكمال إجراءات الاعتماد والتنفيذ.',
                'body_en' => null,
            ],
            [
                'code' => 'DEC-A59-02',
                'name_ar' => 'الصيغة الثانية — الموافقة المشروطة باستكمال الاعتماد',
                'name_en' => 'Formula 2 — Approval conditional on completing endorsement',
                'subject_ar' => 'الموافقة من حيث الموضوع على {{request_title}}',
                'subject_en' => 'Approval in principle of {{request_title}}',
                'body_ar' => 'انتهت اللجنة إلى الموافقة من حيث الموضوع على ...............، وتحال النتيجة إلى ............... لاستكمال الموافقة أو الاعتماد اللازم وفق التشريعات النافذة، ولا يترتب عليها أثر تنفيذي قبل استكمال ذلك.',
                'body_en' => null,
            ],
            [
                'code' => 'DEC-A59-03',
                'name_ar' => 'الصيغة الثالثة — التأجيل',
                'name_en' => 'Formula 3 — Deferral',
                'subject_ar' => 'تأجيل البت في المعاملة رقم {{reference_number}}',
                'subject_en' => 'Deferral of request {{reference_number}}',
                'body_ar' => 'قررت اللجنة تأجيل البت في المعاملة رقم {{reference_number}} إلى حين استكمال ............... من جهة ...............، وعلى مقرر اللجنة متابعة ورود المطلوب وإعادة إدراج الموضوع في أول اجتماع مناسب بعد اكتماله.',
                'body_en' => null,
            ],
            [
                'code' => 'DEC-A59-04',
                'name_ar' => 'الصيغة الرابعة — عدم الموافقة',
                'name_en' => 'Formula 4 — Refusal',
                'subject_ar' => 'عدم الموافقة على {{request_title}}',
                'subject_en' => 'Refusal of {{request_title}}',
                // The appendix's own closing caution travels with the formula
                // rather than being dropped: it is the same rule Appendix 28
                // states and DecisionStructureRules enforces, and a recorder
                // reading the draft should see it where the manual puts it.
                'body_ar' => 'بعد دراسة المعاملة والمستندات المرفقة والتحقق من الشروط المنظمة للموضوع، انتهت اللجنة إلى عدم الموافقة على الطلب لعدم استيفاء ...............، وذلك وفقًا لـ ............... (ويجب تحديد السبب الحقيقي وعدم الاكتفاء بعبارة عدم الاستحقاق).',
                'body_en' => null,
            ],
            [
                'code' => 'DEC-A59-05',
                'name_ar' => 'الصيغة الخامسة — عدم الاختصاص',
                'name_en' => 'Formula 5 — Outside jurisdiction',
                'subject_ar' => 'عدم اختصاص اللجنة بالبت في {{request_title}}',
                'subject_en' => 'Committee lacks jurisdiction over {{request_title}}',
                'body_ar' => 'بعد الاطلاع على موضوع المعاملة والتحقق من السند المنظم لها، تبين للجنة أن البت فيها لا يدخل ضمن اختصاصها، وعليه تقرر إحالتها إلى ............... / إعادة الملف إلى الجهة المحيلة لاتخاذ الإجراء وفق الاختصاص.',
                'body_en' => null,
            ],
            [
                'code' => 'DEC-A59-06',
                'name_ar' => 'الصيغة السادسة — إعادة الدراسة',
                'name_en' => 'Formula 6 — Re-study',
                'subject_ar' => 'إعادة دراسة المعاملة رقم {{reference_number}}',
                'subject_en' => 'Re-study of request {{reference_number}}',
                'body_ar' => 'في ضوء ورود مستندات / بيانات جديدة ذات أثر في الموضوع، قررت اللجنة إعادة دراسة المعاملة رقم {{reference_number}} وربط المستندات الجديدة بملفها الأصلي.',
                'body_en' => null,
            ],
            [
                'code' => 'DEC-A59-07',
                'name_ar' => 'الصيغة السابعة — طلب رأي جهة مختصة',
                'name_en' => 'Formula 7 — Requesting a competent body\'s opinion',
                'subject_ar' => 'إرجاء البت لحين استطلاع رأي جهة مختصة',
                'subject_en' => 'Deferred pending a competent body\'s opinion',
                'body_ar' => 'قررت اللجنة إرجاء البت في الموضوع إلى حين استطلاع رأي ............... بشأن ...............، لما لذلك من أثر مباشر في تحديد المركز الوظيفي / القانوني للمعاملة.',
                'body_en' => null,
            ],
        ];
    }
}
