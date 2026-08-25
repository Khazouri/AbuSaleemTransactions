<?php

namespace App\Http\Requests\CommitteeCandidate;

use Illuminate\Foundation\Http\FormRequest;

/** The small shared shape behind every candidate status/stage move — a comment, required or not depending on which service rule receives it. */
class CommitteeCandidateActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'comment.max' => 'لا يمكن أن يتجاوز التعليق 5000 حرف.',
        ];
    }
}
