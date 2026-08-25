<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MeetingsDashboardMetrics;
use Illuminate\Http\JsonResponse;

/** Stage 32 — the meetings-unit command dashboard's live numbers. */
class MeetingsDashboardController extends Controller
{
    public function __construct(private readonly MeetingsDashboardMetrics $metrics) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'kpis' => $this->metrics->kpis(),
                'funnel' => $this->metrics->funnel(),
                'next_meeting' => $this->metrics->nextMeeting(),
            ],
        ]);
    }
}
