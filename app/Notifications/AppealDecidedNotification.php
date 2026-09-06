<?php

namespace App\Notifications;

use App\Models\Appeal;

/**
 * Stage 65, Track J — Art. 75 point 6: written notice of the appeal's final
 * result and the body that approved it, once AppealController::close() has
 * recorded the closure. Distinct from Stage 64's outcome-execution step
 * (which mutates the *original* Request) — this one only tells the
 * appellant it is over and what happened.
 *
 * `RESULTS` deliberately covers both vocabularies a closed appeal can end
 * on: Stage 63's five committee-decision outcomes, and the two terminal
 * branch statuses (`rejected`, `outside_jurisdiction`) an appeal can close
 * from without ever reaching a committee vote — see AppealController::close().
 */
class AppealDecidedNotification extends SystemNotification
{
    private const RESULTS = [
        'appeal_accept' => ['ar' => 'قبول التظلم وسحب أو تعديل القرار', 'en' => 'accepted, withdrawing or amending the decision'],
        'appeal_partial_accept' => ['ar' => 'قبول التظلم جزئياً', 'en' => 'partially accepted'],
        'appeal_reject' => ['ar' => 'رفض التظلم', 'en' => 'rejected'],
        'appeal_refer' => ['ar' => 'إحالة التظلم لجهة أخرى', 'en' => 'referred to another body'],
        'appeal_redo' => ['ar' => 'إعادة الإجراءات من المرحلة التي وقع فيها العيب', 'en' => 'sent back to redo the procedures'],
        'rejected' => ['ar' => 'رفض التظلم شكلياً', 'en' => 'formally rejected'],
        'outside_jurisdiction' => ['ar' => 'خارج اختصاص اللجنة', 'en' => 'outside the committee\'s jurisdiction'],
    ];

    private readonly int $appealId;

    private readonly string $requestReference;

    private readonly string $finalResultCode;

    private readonly string $approvingBody;

    public function __construct(Appeal $appeal)
    {
        $this->appealId = $appeal->id;
        $this->requestReference = (string) ($appeal->originalRequest?->reference_number ?? $appeal->id);
        $this->finalResultCode = (string) ($appeal->closure['final_result_code'] ?? '');
        $this->approvingBody = (string) ($appeal->closure['approving_body'] ?? '');
    }

    public function eventType(): string
    {
        return 'appeal_decided';
    }

    protected function payload(): array
    {
        $labels = self::RESULTS[$this->finalResultCode]
            ?? ['ar' => $this->finalResultCode, 'en' => $this->finalResultCode];

        return [
            'appeal_id' => $this->appealId,
            'request_reference' => $this->requestReference,
            'final_result' => $this->finalResultCode,
            'title_ar' => 'نتيجة التظلم',
            'title_en' => 'Appeal result',
            'body_ar' => "انتهى النظر في التظلم رقم {$this->appealId} بشأن الطلب {$this->requestReference}: {$labels['ar']} (الجهة المعتمِدة: {$this->approvingBody}).",
            'body_en' => "Your appeal #{$this->appealId} regarding request {$this->requestReference} has concluded: {$labels['en']} (approving body: {$this->approvingBody}).",
        ];
    }
}
