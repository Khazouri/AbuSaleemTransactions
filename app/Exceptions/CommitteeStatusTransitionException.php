<?php

namespace App\Exceptions;

use DomainException;

/**
 * A requested committee sub-status move cannot be performed against the
 * current state.
 *
 * Kept distinct from WorkflowTransitionException even though the shape is
 * identical: this exception can only ever mean a status-only move was
 * rejected, never a stage move, which matters once a controller needs to
 * report the two kinds of failure differently.
 */
class CommitteeStatusTransitionException extends DomainException
{
    public static function requestNotPersisted(): self
    {
        return new self('يجب حفظ الطلب قبل تنفيذ إجراء حالة اللجنة.');
    }

    public static function actorNotActive(): self
    {
        return new self('لا يمكن لمستخدم غير نشط تنفيذ إجراء حالة اللجنة.');
    }

    public static function actionNotConfigured(): self
    {
        return new self('إجراء حالة اللجنة غير معروف.');
    }

    public static function wrongStage(): self
    {
        return new self('لا يمكن تنفيذ إجراءات اللجنة إلا على طلب قيد الاستلام من اللجنة.');
    }

    public static function transitionNotAllowedFromCurrentStatus(): self
    {
        return new self('هذا الإجراء غير متاح في الحالة الراهنة للطلب.');
    }

    public static function commentRequired(): self
    {
        return new self('يجب إدخال سبب لتنفيذ هذا الإجراء.');
    }

    public static function requestClosed(): self
    {
        return new self('لا يمكن تنفيذ إجراء على طلب ملغى أو مؤرشف.');
    }
}
