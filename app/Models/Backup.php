<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One snapshot of the system — Stage 26.
 *
 * @property string $filename
 * @property string $disk
 * @property string $path
 * @property int|null $size_bytes
 * @property bool $includes_files
 * @property string $status completed|failed
 * @property string|null $error
 */
class Backup extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'filename',
        'disk',
        'path',
        'size_bytes',
        'includes_files',
        'status',
        'error',
        'created_by_user_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'includes_files' => 'boolean',
            'completed_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Whether the archive is actually still there.
     *
     * The row and the file can drift apart — someone clears the disk, a restore
     * drill moves things — and offering a download button for a file that has
     * gone is worse than greying it out.
     */
    public function fileExists(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && Storage::disk($this->disk)->exists($this->path);
    }

    /** Removes the archive, tolerating one that is already gone. */
    public function deleteFile(): void
    {
        if ($this->path && Storage::disk($this->disk)->exists($this->path)) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
