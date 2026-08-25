<?php

namespace App\Exceptions;

use DomainException;

/** A meeting output cannot make the requested status-only execution move. */
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
        return new self('لا يرتبط بند جدول الأعمال بمعاملة قابلة للمتابعة.');
    }

    public static function decisionRequired(): self
    {
        return new self('لا يمكن إغلاق التنفيذ قبل تسجيل قرار اللجنة.');
    }

    public static function wrongStage(): self
    {
        return new self('لا يمكن إغلاق التنفيذ قبل بلوغ مرحلة الاعتماد النهائي والأرشفة.');
    }

    public static function transitionNotAllowed(): self
    {
        return new self('يجب أن تكون المعاملة قيد التنفيذ قبل إغلاقها.');
    }
}
