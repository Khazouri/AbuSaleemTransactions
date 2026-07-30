<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Template\StoreTemplateRequest;
use App\Http\Requests\Template\UpdateTemplateRequest;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Stage 10 CRUD for reusable text templates; nothing renders them yet. */
class TemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TemplateResource::collection(Template::query()->orderBy('name_ar')->get());
    }

    public function store(StoreTemplateRequest $request): JsonResponse
    {
        return (new TemplateResource(Template::create($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateTemplateRequest $request, Template $template): TemplateResource
    {
        $template->update($request->validated());

        return new TemplateResource($template);
    }

    public function destroy(Template $template): JsonResponse
    {
        $template->delete();

        return response()->json(null, 204);
    }
}
