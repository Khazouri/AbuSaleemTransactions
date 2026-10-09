<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\AttachmentPreview;
use App\Models\Request;
use App\Models\RequestType;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The per-file half of both document gates — HR's تجهيز الملف الوظيفي and
 * المقرر's بوابة 1 (user decision 2026-10-09). Each gate judges the files
 * actually attached, one verdict per file, given only after the reviewer
 * opened that file's full preview.
 *
 * Attachments are never deleted, so a rejected file could hold a gate forever.
 * A newer upload of the same document type replaces it in the list instead;
 * the rejected copy stays on the file as history. Untyped files and «مستند آخر»
 * have no type to be replaced under, so each is its own row.
 */
class DocumentReview
{
    public const ANSWERS = ['approved', 'not_approved'];

    public const NOT_REVIEWED = 'لم يتم بعد التحقق من المستند: ';

    public const NOT_APPROVED = 'المستند غير معتمد، ويلزم رفع بديل عنه: ';

    public const NOT_PREVIEWED = 'يجب معاينة المستند قبل الحكم عليه: ';

    /**
     * The files under review, oldest first. Execution evidence is left out:
     * it arrives long after both gates and is Appendix 70's to judge.
     *
     * @return Collection<int, Attachment>
     */
    public function attachments(Request $requestRecord): Collection
    {
        $files = $requestRecord->attachments()
            ->whereNull('execution_evidence_type')
            ->orderBy('id')
            ->get(['id', 'request_id', 'original_name', 'required_document_key']);

        $latestPerType = $files
            ->filter(fn (Attachment $file): bool => $this->isTyped($file))
            ->groupBy('required_document_key')
            ->map(fn (Collection $group): int => $group->max('id'));

        return $files
            ->filter(fn (Attachment $file): bool => ! $this->isTyped($file)
                || $latestPerType[$file->required_document_key] === $file->id)
            ->values();
    }

    /**
     * Why the stored verdicts do not clear the files, or null. One message at
     * a time, in file order.
     *
     * @param  array<int|string, string>  $verdicts
     */
    public function refusal(Request $requestRecord, array $verdicts): ?string
    {
        foreach ($this->attachments($requestRecord) as $file) {
            $verdict = $verdicts[$file->id] ?? null;

            if ($verdict === null) {
                return self::NOT_REVIEWED.$file->original_name;
            }

            if ($verdict !== 'approved') {
                return self::NOT_APPROVED.$file->original_name;
            }
        }

        return null;
    }

    /**
     * The first file given a verdict that this reviewer never opened, or null.
     *
     * @param  array<int|string, string>  $verdicts
     */
    public function unpreviewed(Request $requestRecord, array $verdicts, User $reviewer): ?string
    {
        $opened = AttachmentPreview::where('user_id', $reviewer->id)
            ->whereIn('attachment_id', array_keys($verdicts))
            ->pluck('attachment_id')
            ->all();

        foreach ($this->attachments($requestRecord) as $file) {
            if (isset($verdicts[$file->id]) && ! in_array($file->id, $opened, true)) {
                return self::NOT_PREVIEWED.$file->original_name;
            }
        }

        return null;
    }

    /**
     * The stored card: only files under review, with each name frozen beside
     * its verdict so the card still reads after a later upload.
     *
     * @param  array<int|string, string>  $verdicts
     * @return array{documents: array<int, string>, items: list<array{attachment_id: int, name: string, verdict: string}>}
     */
    public function record(Request $requestRecord, array $verdicts): array
    {
        $documents = [];
        $items = [];
        foreach ($this->attachments($requestRecord) as $file) {
            if (! isset($verdicts[$file->id])) {
                continue;
            }

            $documents[$file->id] = $verdicts[$file->id];
            $items[] = ['attachment_id' => $file->id, 'name' => $file->original_name, 'verdict' => $verdicts[$file->id]];
        }

        return ['documents' => $documents, 'items' => $items];
    }

    /**
     * What the panel needs per file: its id and whether this viewer opened it.
     *
     * @return list<array{id: int, previewed: bool}>
     */
    public function forViewer(Request $requestRecord, ?User $viewer): array
    {
        $files = $this->attachments($requestRecord);
        $opened = $viewer === null ? [] : AttachmentPreview::where('user_id', $viewer->id)
            ->whereIn('attachment_id', $files->pluck('id'))
            ->pluck('attachment_id')
            ->all();

        return $files->map(fn (Attachment $file): array => [
            'id' => $file->id,
            'previewed' => in_array($file->id, $opened, true),
        ])->all();
    }

    private function isTyped(Attachment $file): bool
    {
        return $file->required_document_key !== null
            && $file->required_document_key !== RequestType::OTHER_DOCUMENT;
    }
}
