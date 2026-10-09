<?php

namespace App\Services\Performance;

use App\Models\Request;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stage 81 — builds Appendix 71 time cards for a whole population at once.
 *
 * **Batched on purpose, and this is the point of the class.** A time card reads
 * a request's entire stage-log and status history; deriving one per request
 * would be two queries each, which is fine for the single card on a detail
 * screen and ruinous for an indicator averaged over every request in a year.
 * Stage 80's own open item (4) flagged exactly this shape ("a register-wide
 * export would need the same derivation batched") — so every lookup here is a
 * whereIn over the population, grouped in PHP, and the number of queries does
 * not grow with the number of requests.
 *
 * Both histories are read as plain rows joined to their lookup table, not as
 * Eloquent models: the card only ever needs a code and a timestamp per row, and
 * hydrating thousands of models to read two attributes each would undo the
 * batching it just bought.
 */
class TimeCardCompiler
{
    /**
     * One request's card — the detail screen's own path.
     */
    public function forRequest(Request $requestRecord): RequestTimeCard
    {
        return $this->forRequests(collect([$requestRecord]))[$requestRecord->getKey()];
    }

    /**
     * Cards for a whole population, keyed by request id.
     *
     * @param  Collection<int, Request>  $requests
     * @return array<int, RequestTimeCard>
     */
    public function forRequests(Collection $requests): array
    {
        $ids = $requests->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $stageLogs = $this->stageLogs($ids);
        $statusHistory = $this->statusHistory($ids);
        $meetingMarks = $this->meetingMarks($ids);
        $referralMarks = $this->referralMarks($ids);

        $cards = [];

        foreach ($requests as $requestRecord) {
            $id = $requestRecord->getKey();

            $cards[$id] = new RequestTimeCard(
                stageLogs: $stageLogs[$id] ?? collect(),
                statusHistory: $statusHistory[$id] ?? collect(),
                marks: [
                    'submitted_at' => $this->moment($requestRecord->submitted_at ?? $requestRecord->created_at),
                    'closed_at' => $this->moment($requestRecord->closed_at),
                    'executed_at' => $this->moment($requestRecord->executed_at),
                ],
                meetingMarks: $meetingMarks[$id] ?? [],
                referralMarks: $referralMarks[$id] ?? [],
            );
        }

        return $cards;
    }

    /**
     * Cards for whatever a query selects, without loading the models twice.
     *
     * @param  Builder<Request>  $query
     * @return array<int, RequestTimeCard>
     */
    public function forQuery(Builder $query): array
    {
        return $this->forRequests(
            $query->get(['requests.id', 'requests.submitted_at', 'requests.created_at', 'requests.closed_at', 'requests.executed_at']),
        );
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Collection<int, object>>
     */
    private function stageLogs(array $ids): array
    {
        return DB::table('request_stage_logs')
            ->leftJoin('workflow_stages as from_stage', 'from_stage.id', '=', 'request_stage_logs.from_stage_id')
            ->leftJoin('workflow_stages as to_stage', 'to_stage.id', '=', 'request_stage_logs.to_stage_id')
            ->whereIn('request_stage_logs.request_id', $ids)
            ->orderBy('request_stage_logs.acted_at')
            ->orderBy('request_stage_logs.id')
            ->get([
                'request_stage_logs.request_id',
                'request_stage_logs.acted_at',
                'from_stage.code as from_stage_code',
                'to_stage.code as to_stage_code',
            ])
            ->map(fn (object $row) => (object) [
                'request_id' => (int) $row->request_id,
                'acted_at' => $this->moment($row->acted_at),
                'from_stage_code' => $row->from_stage_code,
                'to_stage_code' => $row->to_stage_code,
            ])
            ->groupBy('request_id')
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Collection<int, object>>
     */
    private function statusHistory(array $ids): array
    {
        return DB::table('request_status_history')
            ->leftJoin('request_statuses as to_status', 'to_status.id', '=', 'request_status_history.to_status_id')
            ->whereIn('request_status_history.request_id', $ids)
            ->orderBy('request_status_history.changed_at')
            ->orderBy('request_status_history.id')
            ->get([
                'request_status_history.request_id',
                'request_status_history.changed_at',
                'to_status.code as to_status_code',
            ])
            ->map(fn (object $row) => (object) [
                'request_id' => (int) $row->request_id,
                'changed_at' => $this->moment($row->changed_at),
                'to_status_code' => $row->to_status_code,
            ])
            ->groupBy('request_id')
            ->all();
    }

    /**
     * T6/T7's endpoints: the first sitting a request was placed on, and when
     * that sitting's minutes were compiled.
     *
     * The FIRST sitting, not the latest: T6 measures how long a ready file
     * waited for a hearing, and a file deferred to a later meeting waited for
     * the one it actually reached first.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, CarbonImmutable|null>>
     */
    private function meetingMarks(array $ids): array
    {
        $rows = DB::table('meeting_requests')
            ->join('meetings', 'meetings.id', '=', 'meeting_requests.meeting_id')
            ->leftJoin('meeting_minutes', 'meeting_minutes.meeting_id', '=', 'meetings.id')
            ->whereIn('meeting_requests.request_id', $ids)
            ->orderBy('meetings.scheduled_at')
            ->orderBy('meeting_requests.id')
            ->get([
                'meeting_requests.request_id',
                'meetings.scheduled_at',
                'meeting_minutes.generated_at',
            ]);

        $marks = [];

        foreach ($rows as $row) {
            $id = (int) $row->request_id;

            // Ordered by sitting date, so the first row per request is the
            // first sitting; later ones are re-presentations.
            if (isset($marks[$id])) {
                continue;
            }

            $marks[$id] = [
                'held_at' => $this->moment($row->scheduled_at),
                'minutes_generated_at' => $this->moment($row->generated_at),
            ];
        }

        return $marks;
    }

    /**
     * T8's preferred endpoints: Stage 80's Art. 30 referral register.
     *
     * The FIRST referral whose result actually came back — an open one has no
     * duration yet, and a second round is a fresh approval cycle rather than a
     * continuation of the first.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, CarbonImmutable|null>>
     */
    private function referralMarks(array $ids): array
    {
        $rows = DB::table('approval_referrals')
            ->whereIn('request_id', $ids)
            ->whereNotNull('result_received_at')
            ->orderBy('referred_at')
            ->orderBy('id')
            ->get(['request_id', 'referred_at', 'result_received_at']);

        $marks = [];

        foreach ($rows as $row) {
            $id = (int) $row->request_id;

            if (isset($marks[$id])) {
                continue;
            }

            $marks[$id] = [
                'referred_at' => $this->moment($row->referred_at),
                'result_received_at' => $this->moment($row->result_received_at),
            ];
        }

        return $marks;
    }

    /**
     * Raw rows come back as driver-shaped strings (and Eloquent attributes as
     * Carbon), so every timestamp is normalised here rather than each caller
     * guessing which it was handed.
     */
    private function moment(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
