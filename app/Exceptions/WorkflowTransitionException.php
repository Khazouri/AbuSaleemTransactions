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
    public static function deadlineNotExpired(): self
    {
        return new self('لا يمكن تصعيد الطلب قبل انتهاء مهلة الإنجاز.');
    }

    public static function requestNotPersisted(): self
    {
        return new self('يجب حفظ الطلب قبل تنفيذ إجراء سير العمل.');
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
        return new self('الطلب غير مرتبط بمرحلة سير عمل حالية.');
    }

    public static function transitionNotConfigured(): self
    {
        return new self('هذا الإجراء غير متاح في المرحلة الحالية للطلب.');
    }

    public static function roleNotAllowed(): self
    {
        return new self('لا يملك المستخدم الدور المطلوب لتنفيذ هذا الإجراء.');
    }

    public static function cannotApproveOwnRequest(): self
    {
        return new self('لا يجوز للمستخدم اعتماد طلبه الخاص.');
    }

    public static function commentRequired(): self
    {
        return new self('يجب إدخال سبب لتنفيذ هذا الإجراء.');
    }

    public static function requestClosed(): self
    {
        return new self('لا يمكن تنفيذ إجراء سير عمل على طلب ملغى أو خرج إلى التنفيذ أو أُغلق.');
    }

    public static function ambiguousConfiguration(): self
    {
        return new self('يوجد أكثر من مسار مطابق لهذا الإجراء؛ يرجى مراجعة إعدادات سير العمل.');
    }

    /** Stage 64, Track J — an appeal's `appeal_redo` outcome named a stage WorkflowService::reopenAtStage() refuses to reopen at. */
    public static function invalidRedoStage(): self
    {
        return new self('لا يمكن إعادة الإجراءات إلى هذه المرحلة.');
    }

    public static function redoStageMustPrecedeCurrent(): self
    {
        return new self('يجب أن تكون مرحلة إعادة الإجراءات سابقة لمرحلة الطلب الحالية أو مساوية لها.');
    }
}
