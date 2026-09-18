<?php

namespace App\Http\Requests\RequestType;

use Illuminate\Validation\Rule;

/**
 * Validates edits to an existing request type.
 *
 * Extends the store request so the two rule sets cannot drift — the
 * required_documents rules alone are seven lines, and a matrix that validated
 * differently on edit than on create is exactly how a malformed row gets in.
 * Only the uniqueness check differs: a type keeping its own code is not a
 * collision. Same shape as UpdateGuideArticleRequest.
 */
class UpdateRequestTypeRequest extends StoreRequestTypeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:50',
                Rule::unique('request_types', 'code')->ignore($this->route('requestType')),
            ],
        ]);
    }
}
