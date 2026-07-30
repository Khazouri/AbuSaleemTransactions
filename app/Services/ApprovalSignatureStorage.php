<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Keeps handwritten approval evidence on the application's private disk. */
class ApprovalSignatureStorage
{
    private const DISK = 'local';

    // Stage 19 — private handwritten-signature persistence.
    public function store(UploadedFile $signature, Transaction $transaction): string
    {
        return $signature->store("signatures/{$transaction->getKey()}", self::DISK);
    }

    /**
     * Database transactions cannot undo disk writes, so callers compensate
     * failed workflow moves by removing only the path created for that attempt.
     */
    public function delete(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
