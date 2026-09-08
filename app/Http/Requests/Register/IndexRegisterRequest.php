<?php

namespace App\Http\Requests\Register;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Stage 80 — the three filters every one of Art. 98's twelve registers offers.
 *
 * Deliberately uniform: each register declares which column the dates bound and
 * which columns the search matches (see Register), so twelve screens share one
 * filter bar rather than each inventing its own. A register with nothing
 * sensible to search simply reports `searchable: false` and the box is hidden.
 */
class IndexRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_from.date' => 'صيغة تاريخ البداية غير صحيحة.',
            'date_to.date' => 'صيغة تاريخ النهاية غير صحيحة.',
            'date_to.after_or_equal' => 'يجب أن يكون تاريخ النهاية بعد تاريخ البداية أو مساوياً له.',
            'search.max' => 'لا يمكن أن يتجاوز نص البحث 255 حرفاً.',
            'per_page.integer' => 'عدد السجلات في الصفحة يجب أن يكون رقماً.',
            'locale.in' => 'اللغة المحددة غير مدعومة.',
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return [
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
            'search' => $this->validated('search'),
        ];
    }

    /**
     * The rows are rendered server-side in the language the user is reading,
     * not the server default — the same rule Stage 24's export follows.
     */
    public function registerLocale(): string
    {
        return $this->validated('locale') ?? 'ar';
    }
}
