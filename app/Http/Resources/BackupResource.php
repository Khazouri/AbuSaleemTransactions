<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One snapshot as the backup screen sees it — Stage 26. */
class BackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'size_bytes' => $this->size_bytes,
            'includes_files' => $this->includes_files,
            'status' => $this->status,
            'error' => $this->error,
            // Resolved server-side: only the API knows whether the archive is
            // still on the disk, and the screen needs it to decide whether the
            // download button means anything.
            'file_exists' => $this->fileExists(),
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
        ];
    }
}
