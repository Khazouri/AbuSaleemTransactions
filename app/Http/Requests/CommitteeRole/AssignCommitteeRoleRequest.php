<?php

namespace App\Http\Requests\CommitteeRole;

use App\Models\CommitteeMember;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stage 103 — naming the holder of one committee seat's role.
 */
class AssignCommitteeRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $seatRole = CommitteeMember::SEAT_ROLES[$this->route('seat')];
        $otherSeatRoles = array_values(array_diff(CommitteeMember::SEAT_ROLES, [$seatRole]));

        return [
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_active', true),
                // A committee keeps one row per person, so one person cannot
                // fill two seats: naming them here would silently move them.
                function (string $attribute, mixed $value, Closure $fail) use ($otherSeatRoles) {
                    if (User::query()->whereKey($value)->whereHas('roles', fn ($roles) => $roles->whereIn('code', $otherSeatRoles))->exists()) {
                        $fail('هذا المستخدم يشغل مقعدًا آخر في اللجنة.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'اختر المستخدم.',
            'user_id.exists' => 'المستخدم المحدد غير موجود أو غير مفعّل.',
        ];
    }
}
