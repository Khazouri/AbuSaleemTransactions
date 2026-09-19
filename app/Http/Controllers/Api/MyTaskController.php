<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Tasks\PendingTaskCollector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;

/** Everything awaiting the signed-in user, across every queue they work. */
class MyTaskController extends Controller
{
    public function __construct(private readonly PendingTaskCollector $tasks) {}

    public function index(HttpRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->tasks->collect($request->user())]);
    }
}
