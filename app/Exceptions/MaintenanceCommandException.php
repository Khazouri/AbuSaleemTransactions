<?php

namespace App\Exceptions;

use DomainException;

/**
 * A refusal from the maintenance console, in the same shape every other domain
 * exception in this application uses: static factories carrying the Arabic
 * message the screen shows, so the wording lives with the rule rather than
 * being reinvented at each call site.
 */
class MaintenanceCommandException extends DomainException
{
    public static function unknownCommand(string $code): self
    {
        return new self("الأمر [{$code}] غير موجود في قائمة الأوامر المسموح بها.");
    }

    public static function consoleDisabled(): self
    {
        return new self('لوحة الصيانة معطَّلة من الإعدادات (MAINTENANCE_CONSOLE_ENABLED).');
    }

    public static function shellUnavailable(): self
    {
        return new self('لا يمكن تشغيل الأوامر الخارجية على هذا الخادم: الدالة proc_open معطَّلة أو الأوامر الخارجية موقوفة من الإعدادات. أوامر artisan تعمل رغم ذلك لأنها تُنفَّذ داخل نفس عملية PHP.');
    }

    public static function binaryNotConfigured(string $binary): self
    {
        return new self("لا يوجد مسار مهيَّأ للأداة [{$binary}].");
    }

    public static function alreadyRunning(): self
    {
        return new self('هناك أمر صيانة قيد التنفيذ بالفعل. انتظر انتهاءه قبل تشغيل أمر آخر.');
    }

    public static function confirmationRequired(string $phrase): self
    {
        return new self("هذا أمر مدمِّر. اكتب [{$phrase}] للتأكيد قبل التنفيذ.");
    }

    public static function approvalRequired(): self
    {
        return new self('لا تملك صلاحية تنفيذ الأوامر المدمِّرة.');
    }
}
