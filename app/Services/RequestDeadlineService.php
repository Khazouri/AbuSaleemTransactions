<?php

namespace App\Services;

use App\Models\RequestType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Centralises SLA date calculation so intake and maintenance work cannot
 * silently disagree on the date a type's deadline expires.
 */
class RequestDeadlineService
{
    public function dueDateFor(RequestType $type, CarbonInterface $submittedAt): ?Carbon
    {
        if ($type->default_sla_days === null) {
            return null;
        }

        return $submittedAt->copy()->startOfDay()->addDays($type->default_sla_days);
    }
}
