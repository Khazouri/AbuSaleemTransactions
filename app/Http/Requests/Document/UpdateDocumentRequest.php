<?php

namespace App\Http\Requests\Document;

use Illuminate\Validation\Rule;

/**
 * Only the uniqueness check differs: an entry keeping its own name is not a
 * collision.
 */
class UpdateDocumentRequest extends StoreDocumentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name_ar' => ['required', 'string', 'max:500', Rule::unique('documents', 'name_ar')->ignore($this->route('document'))],
        ]);
    }
}
