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
     * Art. 98's twelve, in order.
     *
     * @var list<class-string<Register>>
     */
    private const REGISTERS = [
        IncomingRequestsRegister::class,       // 1. سجل المعاملات الواردة
        IncompleteRequestsRegister::class,     // 2. سجل المعاملات الناقصة
        MeetingsRegister::class,               // 3. سجل الاجتماعات
        AgendaRegister::class,                 // 4. سجل جدول الأعمال
        MinutesRegister::class,                // 5. سجل المحاضر
        DecisionsRegister::class,              // 6. سجل القرارات والتوصيات
        ApprovalReferralsRegister::class,      // 7. سجل الإحالات للاعتماد
        ApprovalReturnsRegister::class,        // 8. سجل القرارات المعادة من جهة الاعتماد
        ExecutionRegister::class,              // 9. سجل التنفيذ
        AppealsRegister::class,                // 10. سجل التظلمات
        DeferredRequestsRegister::class,       // 11. سجل المعاملات المؤجلة
        ClosureRegister::class,                // 12. سجل الإقفال والأرشفة
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
        $index = 0;

        return array_values(array_map(function (Register $register) use (&$index) {
            $index++;

            return [
                // The article's own numbering, so a printed register can cite
                // "سجل رقم 7" and mean what Art. 98 means by it.
                'number' => $index,
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
