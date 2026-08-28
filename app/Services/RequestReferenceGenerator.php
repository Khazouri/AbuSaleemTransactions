<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Transaction;

/**
 * Allocates the next yearly, department-scoped transaction reference.
 *
 * Call this only inside the same database transaction that writes the new
 * Transaction. `lockForUpdate` locks both existing prefix rows and the index
 * gap after them on MySQL, so two simultaneous intakes cannot claim a number.
 */
class TransactionReferenceGenerator
{
    public function nextFor(Department $department): string
    {
        $year = now()->format('Y');
        $prefix = sprintf('%s-%s-', $year, $department->code);

        $latest = Transaction::query()
            ->where('reference_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('reference_number')
            ->value('reference_number');

        $lastSequence = $latest === null ? 0 : (int) substr($latest, strlen($prefix));

        return sprintf('%s%06d', $prefix, $lastSequence + 1);
    }
}
