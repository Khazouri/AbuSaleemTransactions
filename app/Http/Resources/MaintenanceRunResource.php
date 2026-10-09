<?php

namespace App\Http\Resources;

use App\Services\Maintenance\MaintenanceCommandCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One command run as the maintenance screen sees it. */
class MaintenanceRunResource extends JsonResource
{
    /**
     * Whether to send the whole captured output.
     *
     * Off by default because a history page of twenty rows, each holding up to
     * the 64 KB output cap, is a megabyte of JSON to render a table nobody has
     * expanded yet. The list sends a tail preview; the run and detail endpoints
     * send everything.
     */
    private bool $full = false;

    public function withFullOutput(): static
    {
        $this->full = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $definition = MaintenanceCommandCatalog::find($this->command);

        return [
            'id' => $this->id,
            'command' => $this->command,
            'kind' => $this->kind,
            // Resolved from the catalogue rather than stored on the row: the
            // catalogue is the authority on what a code means, so a label
            // reworded later reads correctly in history too. A code removed
            // from the catalogue falls back to itself rather than to a blank.
            'label_ar' => $definition['label_ar'] ?? $this->command,
            'label_en' => $definition['label_en'] ?? $this->command,
            'preview' => MaintenanceCommandCatalog::preview($this->command),
            'status' => $this->status,
            // A run still marked `running` well past the lock window was almost
            // certainly killed by max_execution_time; saying so beats a row
            // that appears to be working forever.
            'is_stale' => $this->isStale(),
            'exit_code' => $this->exit_code,
            'error' => $this->error,
            'output' => $this->when($this->full, fn () => $this->output),
            'output_preview' => $this->preview($this->output),
            'has_output' => $this->output !== null && $this->output !== '',
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at,
            'finished_at' => $this->finished_at,
            'ran_by' => $this->whenLoaded('ranBy', fn () => $this->ranBy ? [
                'id' => $this->ranBy->id,
                'name' => $this->ranBy->name,
            ] : null),
        ];
    }

    /** The last few lines, which is where a command says why it failed. */
    private function preview(?string $output): ?string
    {
        if ($output === null || $output === '') {
            return null;
        }

        $lines = array_slice(preg_split('/\r?\n/', rtrim($output)) ?: [], -6);

        return implode("\n", $lines);
    }
}
