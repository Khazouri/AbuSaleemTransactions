<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Note\StoreNoteRequest;
use App\Http\Resources\NoteResource;
use App\Models\Request;
use App\Services\RequestVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Stage 13 discussion API, ready for composition in the Stage 15 detail screen. */
class NoteController extends Controller
{
    public function index(HttpRequest $request, Request $requestRecord, RequestVisibility $visibility): AnonymousResourceCollection
    {
        abort_unless($visibility->canView($request->user(), $requestRecord), 404);

        return NoteResource::collection(
            $requestRecord->notes()->with('createdBy:id,name')->oldest()->get(),
        );
    }

    public function store(StoreNoteRequest $request, Request $requestRecord, RequestVisibility $visibility): JsonResponse
    {
        abort_unless($visibility->canView($request->user(), $requestRecord), 404);

        $note = $requestRecord->notes()->create([
            ...$request->validated(),
            'is_internal' => $request->boolean('is_internal', true),
            'created_by_user_id' => $request->user()->id,
        ]);

        return (new NoteResource($note->load('createdBy:id,name')))
            ->response()
            ->setStatusCode(201);
    }
}
