<?php

namespace App\Services\Registers;

/**
 * Stage 80 — [D] Art. 98's twelve registers, in the article's own order.
 *
 * "تعتمد اللجنة **على الأقل** السجلات التالية" followed by twelve names, so
 * this list is a floor rather than a ceiling — a thirteenth register is a new
 * class and one line here, and nothing else has to change.
 *
 * Resolution is by code, and an unknown code returns null rather than throwing:
 * the controller turns that into a 404, which is the honest answer for a URL
 * naming a register that does not exist.
 */
class RegisterCatalog
{
    /**
     * Art. 98's registers, in order. Register 9 (سجل التنفيذ) went with the
     * execution leg on 2026-10-18: nothing records an execution any more.
     *
     * Keyed by the article's own number, so a gap stays a gap.
     *
     * @var array<int, class-string<Register>>
     */
    private const REGISTERS = [
        1 => IncomingRequestsRegister::class,       // سجل المعاملات الواردة
        2 => IncompleteRequestsRegister::class,     // سجل المعاملات الناقصة
        3 => MeetingsRegister::class,               // سجل الاجتماعات
        4 => AgendaRegister::class,                 // سجل جدول الأعمال
        5 => MinutesRegister::class,                // سجل المحاضر
        6 => DecisionsRegister::class,              // سجل القرارات والتوصيات
        7 => ApprovalReferralsRegister::class,      // سجل الإحالات للاعتماد
        8 => ApprovalReturnsRegister::class,        // سجل القرارات المعادة من جهة الاعتماد
        10 => AppealsRegister::class,                // سجل التظلمات
        11 => DeferredRequestsRegister::class,       // سجل المعاملات المؤجلة
        12 => ClosureRegister::class,                // سجل الإقفال والأرشفة
    ];

    /** @var array<string, Register>|null */
    private ?array $resolved = null;

    /** @return array<string, Register> */
    public function all(): array
    {
        if ($this->resolved === null) {
            $this->resolved = [];

            foreach (self::REGISTERS as $class) {
                /** @var Register $register */
                $register = app($class);
                $this->resolved[$register->code()] = $register;
            }
        }

        return $this->resolved;
    }

    public function find(string $code): ?Register
    {
        return $this->all()[$code] ?? null;
    }

    /**
     * The tab strip's own payload: enough to render the twelve before any row
     * has been fetched, including which of them offer a search box.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        $numbers = array_flip(array_map(fn (string $class) => app($class)->code(), self::REGISTERS));

        return array_values(array_map(function (Register $register) use ($numbers) {
            return [
                // The article's own numbering, so a printed register can cite
                // "سجل رقم 7" and mean what Art. 98 means by it.
                'number' => $numbers[$register->code()],
                'code' => $register->code(),
                'name_ar' => $register->nameAr(),
                'name_en' => $register->nameEn(),
                'searchable' => $register->isSearchable(),
                'columns' => array_map(
                    fn (string $key, array $column) => [
                        'key' => $key,
                        'name_ar' => $column['ar'],
                        'name_en' => $column['en'],
                    ],
                    array_keys($register->columns()),
                    array_values($register->columns()),
                ),
            ];
        }, $this->all()));
    }
}
