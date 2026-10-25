<?php

namespace App\Services;

use App\Models\Appeal;
use App\Models\Request;
use App\Models\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\User;
use App\Services\Lifecycle\SpecialCaseRules;
use Illuminate\Support\Facades\DB;

/**
 * Stage 75 — [D] Art. 37's الإقفال, for ordinary requests.
 *
 * This is the sole writer of Art. 38's code 20 (مغلقة ومؤرشفة). Stage 37/69's
 * meeting-scoped `MeetingOutputService::close()` was removed rather than left
 * beside it: closure is a property of the *request*, not of a meeting — a
 * Stage 54 pre-committee عدم اختصاص request never rode an agenda at all — and
 * two ways to write the same terminal status is the dual-path trap Stage 54's
 * jurisdiction gate and Stage 70's numbering hook each had to design around.
 *
 * Art. 37 names four final paths, not one. Three of them are origin statuses
 * here; the fourth ("انتهاء أي مسار تظلم أو إعادة عرض مرتبط بالمعاملة متى كان
 * إبقاء الملف مفتوحًا لازمًا") is the rule that the file stays open until every
 * تظلم concludes, which is Appendix 48's sixth condition rather than a status
 * of its own — so it gates the other three instead of being invented into one.
 */
class RequestClosureService
{
    /**
     * Art. 37's four final paths, as the statuses that reach them.
     *
     * `executed` (19) is Path 1 — اعتماد + تنفيذ + تحديث الملف + الإشعارات.
     * `not_approved` (13) is Path 2 — نتيجة نهائية بعدم الموافقة، مع استكمال
     * الإخطار. `outside_jurisdiction` (14) is Path 3 — ثبوت عدم الاختصاص مع
     * الإحالة أو الإشعار. Paths 2 and 3 had no closure at all before this
     * stage, which is the gap Stage 69's own note left open here.
     *
     * `final_approved` joined Path 1 on 2026-10-18: with no execution leg (user
     * decision), the authority's approval is the file's final result and HR's
     * service-file archive follows it directly. `executed` stays for files executed
     * before then.
     *
     * `referred_to_other_body` is deliberately absent even though النموذج 18
     * lists "أحيلت نهائيًا لجهة أخرى" among its final states: in this system
     * that status is Stage 35's self-loop asking another body for input, which
     * Art. 26 lists among التأجيل's reasons — a pending state, not a final
     * one. The genuinely final referral is `outside_jurisdiction` carrying
     * Stage 50's `referral_authority`.
     */
    public const CLOSABLE_STATUSES = ['executed', 'final_approved', 'not_approved', 'outside_jurisdiction'];

    /**
     * Appendix 47's قائمة التدقيق النهائية قبل الإقفال, verbatim and in the
     * appendix's own order.
     *
     * Twelve, not the thirteen STAGE_PLAN's paraphrase claims — the verbatim
     * appendix has twelve bullets, and the source text wins (the precedent
     * Stage 68 set when Appendix 22's "دراسة فقط" beat its paraphrase).
     *
     * Three of the twelve are absent from CLOSER_CHECKS below and answered by
     * the server instead: `appeal_path_concluded` (#10) is Appeal::openAgainst(),
     * `archive_location_set` (#12) is Stage 100's archive record (ملف الخدمة by
     * الموارد البشرية, its only owner since 2026-10-25), and
     * `execution_document_attached` (#9), which has no execution leg to come
     * from since 2026-10-18 and so always reads `not_applicable`. Asking a human for an answer the system
     * already holds — and would then have to override — is worse than not
     * asking, the split Stage 60 established for appeal formal verification.
     *
     * Appendix 47's fifth check (هل تم التنفيذ؟) is deliberately left with the
     * closer even though `executed_at` could now answer it: Stage 75 asked this
     * stage to decide the *evidence* question, and a wrong answer on #5 refuses
     * a closure rather than wrongly permitting one.
     *
     * @var array<string, string>
     */
    public const AUDIT_CHECKS = [
        'final_result_issued' => 'هل صدرت نتيجة نهائية؟',
        'minutes_approved' => 'هل المحضر معتمد؟',
        'authority_approval_complete' => 'هل تم استكمال اعتماد السلطة المختصة؟',
        'employee_notified' => 'هل تم إشعار الموظف؟',
        'executed' => 'هل تم التنفيذ؟',
        'service_file_updated' => 'هل تم تحديث ملف الخدمة؟',
        'electronic_record_updated' => 'هل تم تحديث السجل الإلكتروني؟',
        'decision_copy_attached' => 'هل أرفقت نسخة القرار؟',
        'execution_document_attached' => 'هل أرفق مستند التنفيذ؟',
        'appeal_path_concluded' => 'هل انتهى مسار التظلم المفتوح إن وجد؟',
        'no_party_awaiting_action' => 'هل لا توجد جهة تنتظر إجراءً؟',
        'archive_location_set' => 'هل تم تحديد مكان الأرشفة؟',
    ];

    /** The nine Appendix 47 checks the closer answers; the other three are derived. */
    public const CLOSER_CHECKS = [
        'final_result_issued',
        'minutes_approved',
        'authority_approval_complete',
        'employee_notified',
        'executed',
        'service_file_updated',
        'electronic_record_updated',
        'decision_copy_attached',
        'no_party_awaiting_action',
    ];

    /**
     * Tri-state, not boolean, and that is the judgment call worth knowing.
     *
     * Appendix 47's preamble is "إلا بعد الإجابة بنعم على الآتي", but Art. 37's
     * four paths make some of its questions genuinely inapplicable: a Path 3
     * عدم اختصاص closed before any sitting has no محضر to approve, no قرار to
     * attach and nothing to execute. Demanding "نعم" there would have the
     * system forbid what Art. 37 explicitly permits — the same error Stage 74
     * avoided by not asking for the سند of a resolution nobody has reached.
     * النموذج 18's own final-state list (منفذة ومغلقة · غير موافق عليها ومغلقة
     * · عدم اختصاص ومغلقة) shows the source contemplating closures that are not
     * executions.
     *
     * `not_applicable` is not a loophole: Appendix 48's refusals below are read
     * from real state and cannot be ticked away.
     */
    public const ANSWERS = ['yes', 'no', 'not_applicable'];

    /**
     * Appendix 48's حالات لا يجوز فيها إغلاق المعاملة, by status code. Public
     * so RequestActs can offer closure (blocked, with this reason) on exactly
     * these files.
     *
     * Mechanically redundant against CLOSABLE_STATUSES — a whitelist of three
     * already excludes every one of these — but checked first and by name so a
     * closer reads the appendix's own words ("لا تغلق المعاملة وهي مؤجلة")
     * rather than a generic refusal, the one-message-at-a-time discipline
     * DecisionStructureRules already set.
     *
     * Condition 7 (أعيدت من جهة الاعتماد) was absent until Stage 77, which is
     * what closes Stage 75's own open item (1): nothing in this schema recorded
     * a return from the approving body, so the condition was left as an honest
     * gap rather than proxied off a status that means something else. All eight
     * are enforced now.
     *
     * @var array<string, string>
     */
    public const BLOCKING_STATUSES = [
        // 1 — بانتظار اعتماد
        'awaiting_municipal_approval' => 'لا يجوز إقفال معاملة بانتظار الاعتماد.',
        'approved' => 'لا يجوز إقفال معاملة بانتظار الاعتماد.',
        'awaiting_recommendation_approval' => 'لا يجوز إقفال معاملة بانتظار الاعتماد.',
        // 2 — بانتظار رد الوزارة
        'awaiting_central_approval' => 'لا يجوز إقفال معاملة بانتظار رد الوزارة.',
        // 3 — تحت التنفيذ
        'in_execution' => 'لا يجوز إقفال معاملة تحت التنفيذ؛ يثبت تنفيذ الأثر أولاً.',
        // 4 — مؤجلة
        'deferred' => 'لا يجوز إقفال معاملة مؤجلة.',
        // 5 — بانتظار مستند طلبته اللجنة
        'completion_required' => 'لا يجوز إقفال معاملة بانتظار مستند طلبته اللجنة.',
        'incomplete' => 'لا يجوز إقفال معاملة بانتظار مستند طلبته اللجنة.',
        // 7 — أعيدت من جهة الاعتماد (Stage 77)
        ApprovalReturnService::RETURNED_STATUS => 'لا يجوز إقفال معاملة أعيدت من جهة الاعتماد.',
    ];

    /**
     * Why this request may not be closed, or null if it may.
     *
     * Returns the message rather than a boolean so a refusal reads as an
     * explanation — the DecisionEligibility/AppealEligibility convention — and
     * so the detail screen's "why not" and the endpoint's refusal are the same
     * sentence, computed once.
     *
     * @param  array<string, string>|null  $audit  the closer's answers, when one is being submitted
     */
    public function refusalReason(Request $requestRecord, ?array $audit = null): ?string
    {
        if ($requestRecord->closed_at !== null) {
            return 'المعاملة مقفلة بالفعل.';
        }

        $statusCode = $requestRecord->status?->code;

        if ($statusCode !== null && isset(self::BLOCKING_STATUSES[$statusCode])) {
            return self::BLOCKING_STATUSES[$statusCode];
        }

        if (! in_array($statusCode, self::CLOSABLE_STATUSES, true)) {
            return 'لا تعتبر المعاملة مقفلة إلا بعد تحقق أحد المسارات النهائية: اعتماد النتيجة، أو عدم الموافقة، أو عدم الاختصاص.';
        }

        // Appendix 48 condition 6 / Art. 37's fourth path — the file stays open
        // until every تظلم against it has concluded. Stage 65's close() is what
        // releases this hold.
        if (Appeal::openAgainst($requestRecord->id)) {
            return 'لا يجوز إقفال معاملة مرتبطة بتظلم مفتوح.';
        }

        // Stage 83 — [D] Appendix 60's first two special cases, which are the
        // only two of its six that forbid a closure: "**لا تغلق المعاملة
        // تلقائيًا**" on a death, and "**ولا يغلق الملف دون تحديد الأثر
        // القانوني**" on a service ending. Both lift when the case is resolved,
        // which is when that legal effect has been determined.
        if (($specialCase = app(SpecialCaseRules::class)->closureRefusal($requestRecord)) !== null) {
            return $specialCase;
        }

        // Stage 100 — Art. 38's code 20 is مغلقة **ومؤرشفة**. Since 2026-10-25
        // HR alone archives (user decision): the مقرر's ملف اللجنة half went,
        // and ملف الخدمة is HR's own act, so the closer cannot stand in for it.
        // A file no decision touched has no service file to archive.
        if ($requestRecord->service_file_archived_at === null && $this->hasRecordedDecision($requestRecord)) {
            return 'لا تغلق المعاملة قبل أرشفة ملف الخدمة من قبل الموارد البشرية.';
        }

        if ($audit === null) {
            return null;
        }

        return $this->auditRefusal($requestRecord, $audit);
    }

    /**
     * Appendix 47's own rule ("إلا بعد الإجابة بنعم على الآتي"), plus Appendix
     * 48's eighth condition, which is the reason these answers are stored and
     * not merely displayed.
     *
     * @param  array<string, string>  $audit
     */
    private function auditRefusal(Request $requestRecord, array $audit): ?string
    {
        foreach (self::CLOSER_CHECKS as $key) {
            if (($audit[$key] ?? null) === 'no') {
                return 'لا يغلق المقرر المعاملة قبل استيفاء قائمة التدقيق النهائية: '.self::AUDIT_CHECKS[$key];
            }
        }

        // Appendix 48 condition 8 — "صدر قرارها ولم يتم تحديث ملف الموظف".
        // Enforced against the audit's own answer because Track K's scope
        // decision (1) puts ملف الخدمة outside this application, so there is no
        // system fact to read. "لا ينطبق" is refused here specifically: once a
        // decision exists the appendix names this case, so it applies.
        if ($audit['service_file_updated'] !== 'yes' && $this->hasRecordedDecision($requestRecord)) {
            return 'لا يجوز إقفال معاملة صدر قرارها ولم يتم تحديث ملف الموظف.';
        }

        return null;
    }

    /**
     * Decision wizard — sub-project 3. Why an archive record may not be
     * written now, or null. archive() throws exactly this, so the wizard's
     * blocked line and the endpoint's 422 are one sentence.
     */
    public function archiveRefusal(Request $requestRecord): ?string
    {
        if ($requestRecord->closed_at !== null) {
            return 'المعاملة مقفلة بالفعل.';
        }

        if (! in_array($requestRecord->status?->code, self::CLOSABLE_STATUSES, true)) {
            return 'لا يؤرشف الملف قبل بلوغ المعاملة أحد مساراتها النهائية.';
        }

        return null;
    }

    /**
     * Stage 100 — record where ملف الخدمة went (Appendix 6 row 15; the
     * committee-file half was dropped on 2026-10-25).
     *
     * Only while the file stands on one of Art. 37's final paths and is not
     * yet closed: before that there is nothing final to archive, and after it
     * the record is part of what closure attested. Overwrites, so a mistyped
     * location is corrected by recording it again (the Stage 78 precedent).
     */
    public function archive(Request $requestRecord, User $actor, string $location): Request
    {
        return DB::transaction(function () use ($requestRecord, $actor, $location) {
            $locked = $this->lockForClosure($requestRecord);

            if (($reason = $this->archiveRefusal($locked)) !== null) {
                throw new \DomainException($reason);
            }

            $locked->update([
                'service_file_location' => $location,
                'service_file_archived_by_user_id' => $actor->id,
                'service_file_archived_at' => now(),
            ]);

            // 2026-10-18 — the archive closes the file (user decision; HR's
            // service file is the only one since 2026-10-25):
            // the authority's decision is the final result, and archiving is
            // the last thing Art. 38's code 20 waits for. A hold Appendix 48
            // names (an open appeal, a special case) still stops it, and the
            // manual close() then remains for once it lifts.
            if ($this->refusalReason($locked) !== null) {
                return $locked;
            }

            return $this->writeClosure($locked, $actor, $this->derivedCard($locked), null, 'اكتملت أرشفة ملف الخدمة فأقفلت المعاملة.');
        });
    }

    /** Whether the service-file archive is owed at all. */
    public function requiresServiceFileArchive(Request $requestRecord): bool
    {
        return $this->hasRecordedDecision($requestRecord);
    }

    private function hasRecordedDecision(Request $requestRecord): bool
    {
        return $requestRecord->meetingRequests()
            ->whereHas('decision')
            ->exists();
    }

    /**
     * Write Art. 37's closure record and move the request to code 20, for a
     * file a hold kept open past its archive, or one that owed none.
     *
     * @param  array<string, mixed>  $card  Art. 37's human-supplied fields
     * @param  array<string, string>  $audit  Appendix 47's closer answers
     */
    public function close(Request $requestRecord, User $actor, array $card, array $audit): Request
    {
        return DB::transaction(function () use ($requestRecord, $actor, $card, $audit) {
            $locked = $this->lockForClosure($requestRecord);

            // Re-checked inside the lock: the controller's own check ran before
            // it, and an appeal could have been filed in between.
            if (($reason = $this->refusalReason($locked, $audit)) !== null) {
                throw new \DomainException($reason);
            }

            return $this->writeClosure($locked, $actor, $card, $this->auditRecord($audit), 'تم استيفاء قائمة التدقيق النهائية وإقفال المعاملة وأرشفتها.');
        });
    }

    private function lockForClosure(Request $requestRecord): Request
    {
        return Request::query()
            ->with(['status:id,code', 'subject:id,is_active'])
            ->lockForUpdate()
            ->findOrFail($requestRecord->id);
    }

    /**
     * Art. 37's card for a file its archive closes: everything it asks
     * is already on the file, so nobody is asked to retype it.
     *
     * @return array<string, string|null>
     */
    private function derivedCard(Request $requestRecord): array
    {
        return [
            'final_decision_number' => $requestRecord->meetingRequests()
                ->join('decisions', 'decisions.meeting_request_id', '=', 'meeting_requests.id')
                ->latest('decisions.id')
                ->value('decisions.decision_number'),
            'approving_body' => match ($requestRecord->approving_authority) {
                'minister' => 'الوزير',
                'dean' => 'العميد',
                default => null,
            },
        ];
    }

    /**
     * Status-only, like every other post-decision act since Stage 37:
     * WorkflowService stays the sole owner of stage movement, and closure
     * records that the life cycle has ended rather than inventing a stage.
     *
     * @param  array<string, mixed>  $card  Art. 37's human-supplied (or derived) fields
     * @param  array<string, string>|null  $auditRecord  null when no closer answered Appendix 47
     */
    private function writeClosure(Request $locked, User $actor, array $card, ?array $auditRecord, string $reason): Request
    {
        $target = RequestStatus::query()->where('code', 'completed_closed')->firstOrFail();
        $fromStatusId = $locked->status_id;
        $finalResultCode = $locked->status?->code;

        $locked->update([
            'status_id' => $target->id,
            // Art. 37's eight fields, less the closure date and the closer,
            // which are columns of their own. `final_result_code` and
            // `notice_status` are computed, never client-supplied — Stage
            // 65's own reasoning: the first is already known from the
            // request's state, and asking the closer to retype it risks the
            // record disagreeing with what actually happened; the second is
            // a plain fact about the requester's account, not an attestation.
            'closure' => [
                'final_result_code' => $finalResultCode,
                'final_decision_number' => $card['final_decision_number'] ?? null,
                'approving_body' => $card['approving_body'] ?? null,
                'execution_date' => $card['execution_date'] ?? null,
                'executing_body' => $card['executing_body'] ?? null,
                // Stage 95 — reachability of صاحب العلاقة, the person
                // Art. 101's notices are actually addressed to.
                'notice_status' => $locked->subject?->is_active
                    ? 'notified'
                    : 'requester_unreachable',
            ],
            'closure_audit' => $auditRecord,
            'closed_by_user_id' => $actor->id,
            'closed_at' => now(),
        ]);

        RequestStatusHistory::create([
            'request_id' => $locked->id,
            'from_status_id' => $fromStatusId,
            'to_status_id' => $target->id,
            'reason' => $reason,
            'changed_by_user_id' => $actor->id,
            'changed_at' => now(),
        ]);

        return $locked->refresh();
    }

    /**
     * The stored audit is all twelve of Appendix 47's checks — the closer's
     * nine answers plus the three the server derives — so the record reads as
     * the appendix's own list rather than a subset a reader has to reassemble.
     *
     * @param  array<string, string>  $audit
     * @return array<string, string>
     */
    private function auditRecord(array $audit): array
    {
        $record = [];

        foreach (array_keys(self::AUDIT_CHECKS) as $key) {
            $record[$key] = match ($key) {
                // #10 — the same predicate the refusal above enforces; reaching
                // here means it passed.
                'appeal_path_concluded' => 'yes',
                // #9 — the paper sitting (2026-10-18) has no execution leg, so
                // no file carries an execution document to attach.
                'execution_document_attached' => 'not_applicable',
                // #12 — Stage 100: the archive record refusalReason()
                // demands; reaching here means it exists, or none was owed.
                'archive_location_set' => 'yes',
                default => $audit[$key],
            };
        }

        return $record;
    }
}
