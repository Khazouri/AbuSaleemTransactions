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
}
