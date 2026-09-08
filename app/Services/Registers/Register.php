<?php

namespace App\Services\Registers;

use App\Services\Reports\ReportDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Stage 80 — one of [D] Art. 98's twelve official registers.
 *
 * **A register here is a named, exportable view, not a table.** Art. 98's own
 * preamble is "تعتمد اللجنة **على الأقل** السجلات التالية" — it lists the
 * records the committee must be able to produce, not storage the system must
 * duplicate. Eleven of the twelve read data prior stages already write, and
 * giving each of those a table of its own would hand the same fact two sources
 * of truth that can disagree, which is exactly what Art. 100's own "غير قابل
 * للطمس" exists to prevent. Only register 7 (سجل الإحالات للاعتماد) needed
 * storage, because Art. 30's fields had nowhere to live at all.
 *
 * A subclass declares its own query, its own columns and how one model becomes
 * one row. Everything shared — the three filters, pagination and the export
 * document — lives here, so a register is a small file about its own source.
 *
 * The same `rows()` feeds the screen and the export, deliberately: a forwarded
 * file can then never describe a different population than the table it came
 * from. That is the rule Stage 25's decisions register already follows.
 */
abstract class Register
{
    /** Stable code, used in the route, the tab strip and the export filename. */
    abstract public function code(): string;

    /** The register's own name, in [D]'s wording. */
    abstract public function nameAr(): string;

    abstract public function nameEn(): string;

    /**
     * Column headings, keyed by the array key each row uses.
     *
     * @return array<string, array{ar: string, en: string}>
     */
    abstract public function columns(): array;

    /** @return Builder<Model> */
    abstract protected function baseQuery(): Builder;

    /**
     * One model becomes one row: a flat map of column key => scalar.
     *
     * @return array<string, string|int|float|null>
     */
    abstract protected function row(Model $model, string $locale): array;

    /**
     * The column the `date_from`/`date_to` filters bound, qualified with its
     * table so a register that joins can still order and filter unambiguously.
     */
    abstract protected function dateColumn(): string;

    /**
     * Columns the free-text `search` filter matches, qualified the same way.
     * A register with nothing sensible to search returns an empty list and the
     * filter is simply not offered for it.
     *
     * @return list<string>
     */
    protected function searchColumns(): array
    {
        return [];
    }

    /** @return Builder<Model> */
    public function query(array $filters): Builder
    {
        $query = $this->baseQuery();

        if ($from = $filters['date_from'] ?? null) {
            $query->whereDate($this->dateColumn(), '>=', $from);
        }

        if ($to = $filters['date_to'] ?? null) {
            $query->whereDate($this->dateColumn(), '<=', $to);
        }

        $term = $filters['search'] ?? null;
        $searchable = $this->searchColumns();

        if ($term !== null && $searchable !== []) {
            $query->where(function (Builder $matches) use ($searchable, $term) {
                foreach ($searchable as $column) {
                    $matches->orWhere($column, 'like', "%{$term}%");
                }
            });
        }

        return $query->orderByDesc($this->dateColumn())->orderByDesc($this->keyColumn());
    }

    /**
     * Tiebreaker for rows sharing a date — a register whose date column is a
     * plain date would otherwise order non-deterministically across pages.
     */
    protected function keyColumn(): string
    {
        return $this->baseQuery()->getModel()->getQualifiedKeyName();
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return list<array<string, string|int|float|null>>
     */
    public function rows(Collection $models, string $locale): array
    {
        return $models->map(fn (Model $model) => $this->row($model, $locale))->values()->all();
    }

    /** Whether this register offers the free-text search box. */
    public function isSearchable(): bool
    {
        return $this->searchColumns() !== [];
    }

    /**
     * @param  Collection<int, Model>  $models
     * @param  list<string>  $meta
     */
    public function document(Collection $models, string $locale, array $meta): ReportDocument
    {
        $columns = $this->columns();
        $rows = $this->rows($models, $locale);

        return new ReportDocument(
            slug: 'register-'.$this->code(),
            title: $locale === 'ar' ? $this->nameAr() : $this->nameEn(),
            columns: array_map(fn (array $column) => $column[$locale] ?? $column['ar'], array_values($columns)),
            // Column-aligned by key rather than by insertion order, so a row
            // that omits an optional value cannot silently shift every cell
            // after it into the wrong column.
            rows: array_map(
                fn (array $row) => array_map(fn (string $key) => $row[$key] ?? null, array_keys($columns)),
                $rows,
            ),
            meta: $meta,
            summary: [[
                'label' => $locale === 'ar' ? 'عدد السجلات' : 'Records',
                'value' => (string) count($rows),
            ]],
            rtl: $locale === 'ar',
        );
    }

    /** Shared helper: every lookup in this schema carries name_ar/name_en. */
    protected function localName(mixed $model, string $locale): ?string
    {
        if ($model === null) {
            return null;
        }

        $preferred = $locale === 'ar' ? $model->name_ar : $model->name_en;
        $fallback = $locale === 'ar' ? $model->name_en : $model->name_ar;

        return ($preferred ?: $fallback) ?: null;
    }

    protected function date(mixed $value): ?string
    {
        return $value?->format('Y-m-d');
    }
}
