<?php

namespace App\Services;

use App\Models\TransactionType;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Centralises SLA date calculation so intake and maintenance work cannot
 * silently disagree on the date a type's deadline expires.
 */
class TransactionDeadlineService
{
    public function dueDateFor(TransactionType $type, CarbonInterface $submittedAt): ?Carbon
    {
        if ($type->default_sla_days === null) {
            return null;
        }

        return $submittedAt->copy()->startOfDay()->addDays($type->default_sla_days);
    }
}
