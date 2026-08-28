<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Request;

/**
 * Allocates the next yearly, department-scoped request reference.
 *
 * Call this only inside the same database transaction that writes the new
 * Request. `lockForUpdate` locks both existing prefix rows and the index
 * gap after them on MySQL, so two simultaneous intakes cannot claim a number.
 */
class RequestReferenceGenerator
{
    public function nextFor(Department $department): string
    {
        $year = now()->format('Y');
        $prefix = sprintf('%s-%s-', $year, $department->code);

        $latest = Request::query()
            ->where('reference_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('reference_number')
            ->value('reference_number');

        $lastSequence = $latest === null ? 0 : (int) substr($latest, strlen($prefix));

        return sprintf('%s%06d', $prefix, $lastSequence + 1);
    }
}
