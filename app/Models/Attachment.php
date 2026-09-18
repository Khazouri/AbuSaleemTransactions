<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadata for a file stored by the upload endpoint added in Stage 12. */
class Attachment extends Model
{
    /**
     * Stage 80 — [D] Appendix 14's هيكل الملف الإلكتروني, transcribed in the
     * appendix's own order: "يقسم الملف الإلكتروني إلى مجلدات ثابتة" followed
     * by thirteen, closing with "**ويمنع حفظ الملفات بصورة عشوائية دون
     * تصنيف**". That last sentence is why StoreAttachmentRequest requires this
     * on every new upload rather than defaulting it — a default is a
     * classification the uploader never made.
     *
     * The column is nullable at the database layer only so rows written before
     * this stage read honestly as غير مصنف instead of being retro-assigned a
     * folder nobody chose.
     *
     * Folder 12 (التظلمات) has no entry here on purpose: an appeal's own
     * documents live on `appeal_attachments` (Stage 59), a different parent,
     * so the appendix already classifies them by where they are stored.
     *
     * @var array<string, string>
     */
    public const FILE_SECTIONS = [
        'request' => 'الطلب',
        'referrals' => 'الإحالات',
        'service_file' => 'الملف الوظيفي',
        'supporting_documents' => 'المستندات المؤيدة',
        'legal_review' => 'المراجعة القانونية',
        'presentation_memo' => 'مذكرة العرض',
        'meeting_agenda' => 'الاجتماع وجدول الأعمال',
        'minutes_decision' => 'المحضر والقرار',
        'approval' => 'الاعتماد',
        'execution' => 'التنفيذ',
        'notices' => 'الإشعارات',
        'closure' => 'مستندات الإقفال',
    ];

    /**
     * The folders a request's own submitter may file into at intake.
     *
     * Appendix 14's twelve span the file's whole life, and most of them name
     * artifacts the committee cycle produces long after the employee has
     * filed — مذكرة العرض، المحضر والقرار، الاعتماد، التنفيذ، الإشعارات.
     * Offering those to a submitter invites a wrong classification, which is
     * worse than a coarse one for a scheme whose purpose is retrieval.
     *
     * الإحالات is excluded for the reason Stage 72 already established: this
     * system records an إحالة as a workflow transition, not as a document, so
     * no type's checklist asks for one. An earlier decision attached under
     * Appendix 16's استكمال لقرار سابق belongs in المستندات المؤيدة — the
     * المحضر والقرار folder holds *this* file's own محضر, not a previous
     * file's.
     *
     * AttachmentController::store() deliberately keeps the full list: R02-R05
     * upload genuine later-cycle documents through it.
     */
    public const SUBMITTER_FILE_SECTIONS = [
        'request',
        'service_file',
        'supporting_documents',
    ];

    /**
     * Where a submitter's file lands when nothing more specific is declared.
     *
     * المستندات المؤيدة is not a placeholder here: Appendix 57's type-specific
     * rows are by definition the documents backing this request, and so is
     * anything a submitter files as مستند آخر. The folders that are NOT this
     * are the ones the seeder declares per row.
     */
    public const DEFAULT_SUBMITTER_SECTION = 'supporting_documents';

    protected $guarded = [];

    /** The appendix's own Arabic folder name, or غير مصنف for a legacy row. */
    public function fileSectionLabel(): string
    {
        return self::FILE_SECTIONS[$this->file_section] ?? 'غير مصنف';
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(Request::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** Stage 83 — who ran [D] Appendix 31's nine checks over this document. */
    public function validityCheckedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validity_checked_by_user_id');
    }

    protected function casts(): array
    {
        return [
            // Stage 83 — [D] Appendix 31's التحقق من صحة المستندات. Null means
            // the document has never been checked, which is a different thing
            // from being checked and found doubtful.
            'validity_checks' => 'array',
            'validity_checked_at' => 'datetime',
        ];
    }
}
