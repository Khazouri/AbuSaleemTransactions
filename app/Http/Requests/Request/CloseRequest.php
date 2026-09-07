<?php

namespace App\Http\Requests\Request;

use App\Services\RequestClosureService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 75 — [D] Art. 37's closure card plus Appendix 47's audit answers.
 *
 * Named without the doubled word, per AGENTS.md's own convention for this one
 * resource (app/Http/Requests/Request/StoreRequest.php sets the precedent).
 *
 * Requiredness mirrors Stage 65's CloseAppealRequest literally, which is what
 * this stage's Build bullet asks for. Art. 37 qualifies only رقم القرار النهائي
 * with "إن وجد", but two of its four final paths involve no execution at all —
 * a عدم موافقة or عدم اختصاص closure has no تاريخ تنفيذ and no جهة منفذة — so
 * those two cannot bind either. `approving_body` stays required because every
 * path has some body that settled the matter, the committee itself at minimum.
 *
 * `final_result_code` and `notice_status` are absent by design: the service
 * computes both (see RequestClosureService::close).
 */
class CloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'final_decision_number' => ['nullable', 'string', 'max:255'],
            'approving_body' => ['required', 'string', 'max:255'],
            'execution_date' => ['nullable', 'date'],
            'executing_body' => ['nullable', 'string', 'max:255'],
            'file_storage_location' => ['required', 'string', 'max:255'],
            'audit' => ['required', 'array'],
        ];

        // Every one of Appendix 47's closer-answered checks must carry an
        // answer — a partially-filled card would misrepresent an unanswered
        // question as a passed one, the same reasoning Stage 54 used for
        // Art. 45's six-question test.
        foreach (RequestClosureService::CLOSER_CHECKS as $check) {
            $rules["audit.{$check}"] = ['required', Rule::in(RequestClosureService::ANSWERS)];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'final_decision_number.max' => 'لا يمكن أن يتجاوز رقم القرار النهائي 255 حرفاً.',
            'approving_body.required' => 'يجب إدخال جهة الاعتماد.',
            'approving_body.max' => 'لا يمكن أن يتجاوز اسم جهة الاعتماد 255 حرفاً.',
            'execution_date.date' => 'صيغة تاريخ التنفيذ غير صحيحة.',
            'executing_body.max' => 'لا يمكن أن يتجاوز اسم الجهة المنفذة 255 حرفاً.',
            'file_storage_location.required' => 'يجب تحديد موقع حفظ الملف.',
            'file_storage_location.max' => 'لا يمكن أن يتجاوز موقع الحفظ 255 حرفاً.',
            'audit.required' => 'يجب استيفاء قائمة التدقيق النهائية قبل الإقفال.',
        ];

        foreach (RequestClosureService::CLOSER_CHECKS as $check) {
            $question = RequestClosureService::AUDIT_CHECKS[$check];
            $messages["audit.{$check}.required"] = "يجب الإجابة على: {$question}";
            $messages["audit.{$check}.in"] = "إجابة غير صالحة على: {$question}";
        }

        return $messages;
    }
}
