<?php

namespace App\Http\Requests\Maintenance;

use App\Services\Maintenance\MaintenanceCommandCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The only thing a caller may send: which allowlisted command to run.
 *
 * Note what is absent — there is no arguments field, no flags field, no path.
 * `command` is validated against the catalogue's own key list, so an unknown
 * code is a 422 before any code that could run something is reached. Keep it
 * that way: adding a free-text parameter here is what would turn this screen
 * into a remote shell.
 *
 * The destructive tier's two extra requirements (the `maintenance,approve`
 * grant and the confirmation phrase) are checked in the controller, not here:
 * both depend on a catalogue lookup and the caller's permissions, which is the
 * same split StoreDecisionRequest already documents for its own conditional
 * requirements.
 */
class RunMaintenanceCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'command' => ['required', 'string', Rule::in(MaintenanceCommandCatalog::codes())],
            'confirmation' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'command.required' => 'يجب تحديد الأمر المطلوب تنفيذه.',
            'command.in' => 'الأمر المحدد غير موجود في قائمة الأوامر المسموح بها.',
        ];
    }

    public function command(): string
    {
        return (string) $this->validated('command');
    }

    public function confirmation(): string
    {
        return trim((string) ($this->validated('confirmation') ?? ''));
    }
}
