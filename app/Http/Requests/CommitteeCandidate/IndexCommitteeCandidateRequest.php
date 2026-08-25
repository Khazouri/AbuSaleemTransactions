<?php

namespace App\Http\Requests\CommitteeCandidate;

use App\Services\CommitteeStatusService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates the candidate-worklist filters — status is restricted to the committee's candidate-pool statuses, not any status in the system. */
class IndexCommitteeCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(CommitteeStatusService::CANDIDATE_STATUSES)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'type_id' => ['nullable', 'integer', Rule::exists('transaction_types', 'id')],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'حالة المرشح المحددة غير صالحة.',
            'department_id.exists' => 'الإدارة المحددة غير صالحة.',
            'type_id.exists' => 'نوع المعاملة المحدد غير صالح.',
        ];
    }
}
