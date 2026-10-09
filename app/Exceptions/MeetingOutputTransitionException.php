<?php

namespace App\Exceptions;

use DomainException;

/**
 * A meeting output cannot make the requested status-only execution move.
 *
 * Stage 75 removed this class's closure factories along with
 * MeetingOutputService::close(): Art. 37's الإقفال moved to
 * RequestClosureService, which raises its own refusals.
 */
class MeetingOutputTransitionException extends DomainException
{
    public static function outputNotPersisted(): self
    {
        return new self('يجب حفظ مخرج الاجتماع قبل تحديث حالة تنفيذه.');
    }

    public static function actorNotActive(): self
    {
        return new self('لا يمكن لمستخدم غير نشط تحديث حالة تنفيذ مخرج الاجتماع.');
    }

    public static function requestRequired(): self
    {
        return new self('لا يرتبط بند جدول الأعمال بطلب قابل للمتابعة.');
    }

    public static function decisionRequired(): self
    {
        return new self('لا يمكن تحديث حالة التنفيذ قبل تسجيل قرار اللجنة.');
    }

    public static function wrongStage(): self
    {
        return new self('لا يمكن تسجيل التنفيذ قبل بلوغ مرحلة الاعتماد النهائي والأرشفة.');
    }

    /** Stage 69 — Art. 38 code 18 is markExecuted()'s only legal origin. */
    public static function notInExecution(): self
    {
        return new self('يجب أن يكون الطلب قيد التنفيذ قبل تسجيل تنفيذه.');
    }
}
