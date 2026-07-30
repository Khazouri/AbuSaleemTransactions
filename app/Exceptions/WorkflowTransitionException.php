<?php

namespace App\Exceptions;

use DomainException;

/**
 * A requested workflow move cannot be performed against the current state.
 *
 * Keeping workflow failures distinct from database/runtime failures lets the
 * Stage 15 API translate them into a validation response without accidentally
 * hiding an infrastructure error behind a user-facing 422.
 */
class WorkflowTransitionException extends DomainException
{
    public static function transactionNotPersisted(): self
    {
        return new self('يجب حفظ المعاملة قبل تنفيذ إجراء سير العمل.');
    }

    public static function actorNotActive(): self
    {
        return new self('لا يمكن لمستخدم غير نشط تنفيذ إجراء سير العمل.');
    }

    public static function actionRequired(): self
    {
        return new self('إجراء سير العمل مطلوب.');
    }

    public static function currentStageRequired(): self
    {
        return new self('المعاملة غير مرتبطة بمرحلة سير عمل حالية.');
    }

    public static function transitionNotConfigured(): self
    {
        return new self('هذا الإجراء غير متاح في المرحلة الحالية للمعاملة.');
    }

    public static function roleNotAllowed(): self
    {
        return new self('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.');
    }

    public static function commentRequired(): self
    {
        return new self('يجب إدخال سبب لتنفيذ هذا الإجراء.');
    }

    public static function ambiguousConfiguration(): self
    {
        return new self('يوجد أكثر من مسار مطابق لهذا الإجراء؛ يرجى مراجعة إعدادات سير العمل.');
    }
}
