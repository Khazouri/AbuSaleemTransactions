<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Document\StoreDocumentRequest;
use App\Http\Requests\Document\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use Illuminate\Http\JsonResponse;

/**
 * Writes to the managed documents list (قائمة المستندات). It is read through
 * RequestTypeController::index(), the one screen that uses it.
 *
 * No delete: an entry is master data that type rows point at, so it is
 * deactivated instead, which takes it out of the picker and leaves every type
 * already listing it intact.
 */
class DocumentController extends Controller
{
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        return (new DocumentResource(Document::create($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Changes the entry only. Types already listing it keep the wording they
     * copied, so their files stay under the same keys.
     */
    public function update(UpdateDocumentRequest $request, Document $document): DocumentResource
    {
        $document->update($request->validated());

        return new DocumentResource($document);
    }

    public function toggleActive(Document $document): DocumentResource
    {
        $document->update(['is_active' => ! $document->is_active]);

        return new DocumentResource($document);
    }
}
