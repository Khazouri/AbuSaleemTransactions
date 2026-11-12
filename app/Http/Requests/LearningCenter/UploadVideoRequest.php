<?php

namespace App\Http\Requests\LearningCenter;

use Illuminate\Foundation\Http\FormRequest;

class UploadVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The smaller of PHP's two upload ceilings, in bytes: the server drops a
     * body over either one before validation runs, so a larger rule would
     * promise an upload that can never arrive.
     */
    public static function maxUploadBytes(): int
    {
        return min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size')));
    }

    /** Parses ini shorthand (128M, 2G, 512K, plain bytes); 0 or -1 means unlimited. */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;
        $number *= match (strtoupper(substr($value, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        return $number > 0 ? $number : PHP_INT_MAX;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:video/mp4', 'max:'.intdiv(self::maxUploadBytes(), 1024)],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'ملف الفيديو مطلوب.',
            'file.file' => 'تعذّر رفع الملف.',
            'file.mimetypes' => 'يجب أن يكون الملف بصيغة MP4.',
            'file.max' => 'حجم الملف يتجاوز الحد المسموح ('.round(self::maxUploadBytes() / 1024 ** 2).' ميغابايت).',
            'duration_seconds.required' => 'مدة الفيديو مطلوبة.',
            'duration_seconds.integer' => 'مدة الفيديو يجب أن تكون عددًا صحيحًا من الثواني.',
            'duration_seconds.min' => 'مدة الفيديو يجب ألا تقل عن ثانية واحدة.',
            'duration_seconds.max' => 'مدة الفيديو يجب ألا تتجاوز 3600 ثانية.',
        ];
    }
}
