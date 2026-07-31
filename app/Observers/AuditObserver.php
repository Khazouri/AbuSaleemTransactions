<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Stage 22 — the single observer behind every audit_logs row.
 *
 * One generic class registered against many models (see
 * AppServiceProvider::AUDITED_MODELS) rather than one observer per model: the
 * payload written is identical in every case, and a per-model class would be
 * an empty subclass that nobody remembers to create for the next resource.
 */
class AuditObserver
{
    /**
     * Never stored, in either the old or the new snapshot. An audit trail that
     * records password hashes is a second copy of the credential store.
     */
    private const SENSITIVE = ['password', 'remember_token'];

    /**
     * Excluded from the diff because they carry no decision-relevant
     * information — every write touches updated_at, which would otherwise make
     * even a no-op save look like a change.
     */
    private const NOISE = ['created_at', 'updated_at'];

    public function created(Model $model): void
    {
        $this->record($model, 'created', null, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean(Arr::except($model->getChanges(), self::NOISE));

        // A save that changed nothing meaningful (a touch, or a re-save of
        // identical values) is not an event worth a row.
        if ($changes === []) {
            return;
        }

        $before = $this->clean(array_intersect_key($model->getRawOriginal(), $changes));

        $this->record($model, 'updated', $before, $changes);
    }

    public function deleted(Model $model): void
    {
        // Fires for soft deletes too, which is intended: to a reader of the
        // log, a soft-deleted record has disappeared from the system either way.
        $this->record($model, 'deleted', $this->clean($model->getRawOriginal()), null);
    }

    public function restored(Model $model): void
    {
        $this->record($model, 'restored', null, $this->clean($model->getAttributes()));
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function record(Model $model, string $action, ?array $old, ?array $new): void
    {
        if (! AuditLog::auditingEnabled()) {
            return;
        }

        AuditLog::create([
            'user_id' => Auth::id(),
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            // Null under console commands and queued jobs, where there is no
            // originating HTTP request to attribute the change to.
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 512) ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function clean(array $attributes): array
    {
        foreach (self::SENSITIVE as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = '********';
            }
        }

        return $attributes;
    }
}
