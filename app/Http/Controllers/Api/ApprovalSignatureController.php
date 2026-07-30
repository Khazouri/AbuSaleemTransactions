<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Transaction;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Authenticated read access to private approval-signature images. */
class ApprovalSignatureController extends Controller
{
    // Stage 19 — signed approval evidence for the transaction trail.
    public function show(Transaction $transaction, Approval $approval): StreamedResponse
    {
        abort_unless($approval->transaction_id === $transaction->id, 404);
        abort_if(blank($approval->signature_path), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($approval->signature_path), 404);

        return $disk->response(
            $approval->signature_path,
            "approval-{$approval->id}-signature.png",
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
