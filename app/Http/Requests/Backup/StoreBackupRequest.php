<?php

namespace App\Http\Requests\Backup;

use Illuminate\Foundation\Http\FormRequest;

/** Stage 26 — the one choice available when taking a snapshot. */
class StoreBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'include_files' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'include_files.boolean' => 'قيمة تضمين الملفات غير صالحة.',
        ];
    }

    /**
     * Null rather than false when the caller says nothing, so BackupService
     * falls back to the configured default instead of the request silently
     * deciding that attachments don't matter.
     */
    public function includeFiles(): ?bool
    {
        $value = $this->validated('include_files');

        return $value === null ? null : (bool) $value;
    }
}
