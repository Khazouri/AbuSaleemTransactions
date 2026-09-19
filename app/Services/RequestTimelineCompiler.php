<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Decision;
use App\Models\Request;
use App\Models\RequestStageLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stage 80 — [D] Art. 100's السجل الزمني, completed.
 *
 * The article names six things the timeline must prove: "**التاريخ — الإجراء —
 * المسؤول — الجهة — الملاحظة — المستند المرتبط**", so that a reader can answer
 * "أين توجد المعاملة؟ · منذ متى؟ · لدى من؟ · وما الإجراء المطلوب التالي؟".
 * Four of the six were already there from Stage 14 (`acted_at`, `action`,
 * `acted_by`, `comment`); this service adds the other two.
 *
 * **الجهة** is the acting user's own department, falling back to the stage's
 * seeded responsible role when there is no actor at all — a system move such
 * as Stage 70's intake auto-hop. Both are recorded facts; neither is guessed.
 *
 * **المستند المرتبط is derived, and deliberately not a new column on
 * `request_stage_logs`.** Setting one would mean threading optional metadata
 * through WorkflowService::transition(), which Stage 77 already declined to do
 * to the application's highest-consequence write path — and a column nothing
 * ever populates would be worse than a derivation anyone can check. Two
 * kinds are linked, each because the link is a fact rather than a guess:
 *
 *   - **decision** — a `Decision` recorded against this request in the window
 *     this entry opened; the committee's own recorded outcome for the step.
 *   - **attachment** — every document uploaded to the file while it stood
 *     where this entry put it. That is the literal answer to "which document
 *     belongs to this step": the window is bounded by the next entry, so a
 *     document that arrived under the next holder is attributed to them.
 *
 * Each item carries its own `kind`, so nothing is presented as a document of a
 * type it is not, and an entry with no document says so with an empty list
 * rather than borrowing one from its neighbour.
 */
class RequestTimelineCompiler
{
    /**
     * Art. 100's six columns, one row per recorded move, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function compile(Request $requestRecord): array
    {
        $logs = $requestRecord->stageLogs
            ->sortBy([['acted_at', 'asc'], ['id', 'asc']])
            ->values();

        $attachments = $this->attachments($requestRecord);
        $decisions = $this->decisions($requestRecord);

        return $logs->map(function (RequestStageLog $log, int $index) use ($logs, $attachments, $decisions) {
            $from = $log->acted_at;
            // The window this entry opened, closed by the next move. The last
            // entry's window is still open, so it has no upper bound.
            $until = $logs->get($index + 1)?->acted_at;

            return [
                'id' => $log->id,
                'action' => $log->action,
                'comment' => $log->comment,
                'from_stage' => $this->stage($log->fromStage),
                'to_stage' => $this->stage($log->toStage),
                'acted_by' => $log->actedBy ? [
                    'id' => $log->actedBy->id,
                    'name' => $log->actedBy->name,
                ] : null,
                // Art. 100's الجهة.
                'body' => $this->body($log),
                'acted_at' => $from?->toIso8601String(),
                // Art. 100's المستند المرتبط.
                'documents' => $this->documentsFor($from, $until, $attachments, $decisions),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, Attachment>  $attachments
     * @param  Collection<int, Decision>  $decisions
     * @return list<array<string, mixed>>
     */
    private function documentsFor(
        ?Carbon $from,
        ?Carbon $until,
        Collection $attachments,
        Collection $decisions,
    ): array {
        $documents = [];

        foreach ($decisions as $decision) {
            if (! $this->within($decision->decided_at, $from, $until)) {
                continue;
            }

            $documents[] = [
                'kind' => 'decision',
                'label' => 'قرار اللجنة',
                'reference' => $decision->decision_number,
                'section' => 'minutes_decision',
                'url' => null,
            ];
        }

        foreach ($attachments as $attachment) {
            if (! $this->within($attachment->created_at, $from, $until)) {
                continue;
            }

            $documents[] = [
                'kind' => 'attachment',
                'label' => $attachment->label ?: $attachment->original_name,
                'reference' => $attachment->original_name,
                // Appendix 14's folder, so a linked document says which part
                // of the file it belongs to rather than only its filename.
                'section' => $attachment->file_section,
                'url' => route('requests.attachments.preview', [
                    'requestRecord' => $attachment->request_id,
                    'attachment' => $attachment->id,
                ]),
            ];
        }

        return $documents;
    }

    /**
     * A moment belongs to the entry that opened the window containing it.
     *
     * The lower bound is inclusive and the upper exclusive, so an artifact
     * written in the same transaction as a move is attributed to that move and
     * never to both it and the next one.
     */
    private function within(?Carbon $moment, ?Carbon $from, ?Carbon $until): bool
    {
        if ($moment === null || $from === null) {
            return false;
        }

        if ($moment->lt($from)) {
            return false;
        }

        return $until === null || $moment->lt($until);
    }

    /** Art. 100's الجهة — see the class docblock for why these two sources. */
    private function body(RequestStageLog $log): ?array
    {
        $department = $log->actedBy?->department;

        if ($department !== null) {
            return [
                'kind' => 'department',
                'name_ar' => $department->name_ar,
                'name_en' => $department->name_en,
            ];
        }

        $role = $log->fromStage?->responsibleRole ?? $log->toStage?->responsibleRole;

        if ($role !== null) {
            return [
                'kind' => 'role',
                'name_ar' => $role->name_ar,
                'name_en' => $role->name_en,
            ];
        }

        return null;
    }

    private function stage(mixed $stage): ?array
    {
        return $stage === null ? null : [
            'order_no' => $stage->order_no,
            'code' => $stage->code,
            'name_ar' => $stage->name_ar,
            'name_en' => $stage->name_en,
        ];
    }

    /** @return Collection<int, Attachment> */
    private function attachments(Request $requestRecord): Collection
    {
        return Attachment::query()
            ->where('request_id', $requestRecord->id)
            ->orderBy('created_at')
            ->get();
    }

    /** @return Collection<int, Decision> */
    private function decisions(Request $requestRecord): Collection
    {
        return Decision::query()
            ->whereHas('meetingRequest', fn ($query) => $query->where('request_id', $requestRecord->id))
            ->orderBy('decided_at')
            ->get();
    }
}
