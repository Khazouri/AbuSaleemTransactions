<?php

namespace App\Services\Lifecycle;

use App\Models\Attachment;
use App\Models\Request;
use App\Models\RequestDocumentConflict;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stage 83 — [D] Appendix 30's إدارة حالات التعارض في المستندات.
 *
 * The appendix's six steps are this record's own fields rather than a
 * checklist beside it, and the three resolution fields are **required
 * together**, which is what makes its closing prohibition enforceable:
 * "**ولا يجوز للجنة اختيار أحد المستندين بناءً على تقدير شخصي**". A resolution
 * cannot be recorded without naming the body that was addressed (step 2), the
 * document that body determined to be authoritative (step 3) and the
 * correction made to the employee's record (step 4) — so choosing between two
 * documents on nobody's authority is simply not a recordable act.
 *
 * The gate is the appendix's own "**فلا تعرض المعاملة قبل معالجة التعارض**":
 * an unresolved row refuses agenda insertion. It lives in
 * MeetingController::addAgendaItem() rather than in
 * CommitteeStatusService::place_on_agenda for the reason Stage 44 established
 * and Stage 68 reused — that action has no caller, and an item is inserted
 * through the controller without it ever firing.
 */
class DocumentConflictService
{
    public const AGENDA_BLOCK_MESSAGE = 'لا تعرض المعاملة على اللجنة قبل معالجة التعارض بين مستنداتها.';

    public function openConflicts(Request $requestRecord): Collection
    {
        return $requestRecord->documentConflicts()->whereNull('resolved_at')->get();
    }

    public function hasOpenConflict(Request $requestRecord): bool
    {
        return $requestRecord->documentConflicts()->whereNull('resolved_at')->exists();
    }

    /**
     * Step 1 — تحديد المستندات المتعارضة.
     *
     * Named attachments must belong to this request: a conflict pointing at
     * another file's document would describe a difference nobody can inspect.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(Request $requestRecord, array $data, User $actor): RequestDocumentConflict
    {
        $attachmentIds = array_values(array_unique(array_map('intval', $data['attachment_ids'] ?? [])));

        if ($attachmentIds !== []) {
            $owned = Attachment::query()
                ->where('request_id', $requestRecord->getKey())
                ->whereIn('id', $attachmentIds)
                ->count();

            abort_unless($owned === count($attachmentIds), 422, 'أحد المستندات المحددة لا يخص هذه المعاملة.');
        }

        return $requestRecord->documentConflicts()->create([
            'conflict_kind' => $data['conflict_kind'],
            'detail' => $data['detail'],
            'attachment_ids' => $attachmentIds === [] ? null : $attachmentIds,
            'recorded_by_user_id' => $actor->getKey(),
        ]);
    }

    /**
     * Steps 2-4, then 5 (تسجيل الإجراء) and 6 (إعادة الملف للفحص — the gate
     * lifting) as consequences of the row being resolved.
     *
     * @param  array<string, mixed>  $data
     */
    public function resolve(RequestDocumentConflict $conflict, array $data, User $actor): RequestDocumentConflict
    {
        return DB::transaction(function () use ($conflict, $data, $actor) {
            $locked = RequestDocumentConflict::query()->lockForUpdate()->findOrFail($conflict->getKey());

            abort_if($locked->resolved_at !== null, 422, 'سبق معالجة هذا التعارض.');

            $locked->update([
                'authority_consulted' => $data['authority_consulted'],
                'authoritative_document' => $data['authoritative_document'],
                'correction_note' => $data['correction_note'],
                'resolved_by_user_id' => $actor->getKey(),
                'resolved_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
