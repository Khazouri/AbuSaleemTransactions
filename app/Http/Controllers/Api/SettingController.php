<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\StoreSettingRequest;
use App\Http\Requests\Setting\UpdateSettingRequest;
use App\Http\Resources\SettingResource;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Stage 10 settings CRUD.
 *
 * The index endpoint is intentionally the backend-readable settings surface:
 * future services can use the same model/table without requiring a frontend
 * concern to be involved.
 */
class SettingController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SettingResource::collection(Setting::query()->orderBy('key')->get());
    }

    public function store(StoreSettingRequest $request): JsonResponse
    {
        return (new SettingResource(Setting::create($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateSettingRequest $request, Setting $setting): SettingResource
    {
        $setting->update($request->validated());

        return new SettingResource($setting);
    }

    public function destroy(Setting $setting): JsonResponse
    {
        $setting->delete();

        return response()->json(null, 204);
    }
}
