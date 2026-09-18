/**
 * Stage 80 — [D] Appendix 14's هيكل الملف الإلكتروني.
 *
 * Mirrors `Attachment::FILE_SECTIONS` once, so the upload picker, the
 * attachment list and the Art. 100 timeline all name the same folders. The
 * server is the enforcement (StoreAttachmentRequest requires one of these);
 * this list only decides what the form offers.
 *
 * Folder 12 (التظلمات) is absent on purpose: an appeal's own documents live on
 * their own parent, so the appendix already classifies them by where they are
 * stored.
 */
export const FILE_SECTIONS = [
  'request',
  'referrals',
  'service_file',
  'supporting_documents',
  'legal_review',
  'presentation_memo',
  'meeting_agenda',
  'minutes_decision',
  'approval',
  'execution',
  'notices',
  'closure',
]

/** The appendix's own folder name, or the "unclassified" label for a legacy row. */
export function fileSectionLabel(t, code) {
  return code && FILE_SECTIONS.includes(code)
    ? t(`fileSections.${code}`)
    : t('fileSections.unclassified')
}
