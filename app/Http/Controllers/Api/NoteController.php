<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Note\StoreNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Transaction;
use App\Services\TransactionVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Stage 13 discussion API, ready for composition in the Stage 15 detail screen. */
class NoteController extends Controller
{
    public function index(Request $request, Transaction $transaction, TransactionVisibility $visibility): AnonymousResourceCollection
    {
        abort_unless($visibility->canView($request->user(), $transaction), 404);

        return NoteResource::collection(
            $transaction->notes()->with('createdBy:id,name')->oldest()->get(),
        );
    }

    public function store(StoreNoteRequest $request, Transaction $transaction, TransactionVisibility $visibility): JsonResponse
    {
        abort_unless($visibility->canView($request->user(), $transaction), 404);

        $note = $transaction->notes()->create([
            ...$request->validated(),
            'is_internal' => $request->boolean('is_internal', true),
            'created_by_user_id' => $request->user()->id,
        ]);

        return (new NoteResource($note->load('createdBy:id,name')))
            ->response()
            ->setStatusCode(201);
    }
}
