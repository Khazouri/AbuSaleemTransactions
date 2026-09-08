<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MeetingsDashboardMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;

/** Stage 32 — the meetings-unit command dashboard's live numbers. */
class MeetingsDashboardController extends Controller
{
    public function __construct(private readonly MeetingsDashboardMetrics $metrics) {}

    public function index(HttpRequest $request): JsonResponse
    {
        // Stage 81 — the locale is the reader's, not the server's: the board's
        // bucket names and the warning labels are rendered here rather than in
        // the SPA, so the screen and any forwarded copy say the same words.
        $locale = in_array($request->query('locale'), ['ar', 'en'], true)
            ? (string) $request->query('locale')
            : 'ar';

        return response()->json([
            'data' => [
                'kpis' => $this->metrics->kpis(),
                // Stage 81 — [D] Appendix 11's ten buckets, which replaced
                // Stage 32's own six-bucket funnel. See MeetingsDashboardMetrics.
                'board' => $this->metrics->board([], $locale),
                'early_warnings' => $this->metrics->earlyWarnings([], $locale),
                'next_meeting' => $this->metrics->nextMeeting(),
            ],
        ]);
    }
}
