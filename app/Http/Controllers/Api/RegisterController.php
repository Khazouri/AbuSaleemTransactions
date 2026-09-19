<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Register\ExportRegisterRequest;
use App\Http\Requests\Register\IndexRegisterRequest;
use App\Services\Registers\Register;
use App\Services\Registers\RegisterCatalog;
use App\Services\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 80 — [D] Art. 98's twelve official registers.
 *
 * Rows are returned as plain register-shaped arrays rather than through an API
 * Resource, because a register row is not a model: register 2's rows are status
 * history entries, register 6's are decisions read alongside three other
 * tables, and register 11's are deferral decisions. Each register declares its
 * own columns, and the payload carries them beside the rows so one generic
 * table on the SPA renders all twelve.
 *
 * The screen and the export call the same `query()`, so a forwarded file can
 * never describe a different population than the table it came from — the rule
 * Stage 24's reports screen and Stage 25's decisions register both follow.
 */
class RegisterController extends Controller
{
    public function __construct(private readonly RegisterCatalog $catalog) {}

    /**
     * The twelve registers themselves — names, numbers and column headings —
     * so the tab strip renders before any row has been fetched.
     */
    public function catalog(): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->describe(),
            'meta' => ['formats' => ReportExporter::FORMATS],
        ]);
    }

    /** One register's rows, paginated, with the columns that describe them. */
    public function index(IndexRegisterRequest $request, string $register): JsonResponse
    {
        $definition = $this->resolve($register);
        $locale = $request->registerLocale();

        $page = $definition->query($request->filters(), $request->user())
            ->paginate($request->validated('per_page') ?? 25)
            ->withQueryString();

        return response()->json([
            'data' => $definition->rows($page->getCollection(), $locale),
            'columns' => $this->columnHeadings($definition, $locale),
            'register' => [
                'code' => $definition->code(),
                'name_ar' => $definition->nameAr(),
                'name_en' => $definition->nameEn(),
                'searchable' => $definition->isSearchable(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** The same register as a file, reusing Stage 24's writers unchanged. */
    public function export(ExportRegisterRequest $request, string $register, ReportExporter $exporter): Response
    {
        $definition = $this->resolve($register);
        $locale = $request->registerLocale();
        $filters = $request->filters();

        // Not paginated: an export that silently stopped at page one would be
        // worse than no export. The filters bound the size.
        $rows = $definition->query($filters, $request->user())->get();

        return $exporter->download(
            $definition->document($rows, $locale, $this->metaLines($filters, $locale)),
            $request->exportFormat(),
        );
    }

    private function resolve(string $code): Register
    {
        $definition = $this->catalog->find($code);

        abort_if($definition === null, 404);

        return $definition;
    }

    /** @return list<array{key: string, label: string}> */
    private function columnHeadings(Register $definition, string $locale): array
    {
        $headings = [];

        foreach ($definition->columns() as $key => $column) {
            $headings[] = ['key' => $key, 'label' => $column[$locale] ?? $column['ar']];
        }

        return $headings;
    }

    /**
     * Context lines printed under the title, so a forwarded register still says
     * what it is a register of and over which period.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function metaLines(array $filters, string $locale): array
    {
        $applied = [];

        if ($from = $filters['date_from'] ?? null) {
            $applied[] = ($locale === 'ar' ? 'من تاريخ' : 'From').': '.$from;
        }
        if ($to = $filters['date_to'] ?? null) {
            $applied[] = ($locale === 'ar' ? 'إلى تاريخ' : 'To').': '.$to;
        }
        if ($term = $filters['search'] ?? null) {
            $applied[] = ($locale === 'ar' ? 'بحث' : 'Search').': '.$term;
        }

        return [
            ($locale === 'ar' ? 'تاريخ الإصدار' : 'Generated').': '.now()->format('Y-m-d H:i'),
            $applied === []
                ? ($locale === 'ar' ? 'بدون تصفية — كامل السجل' : 'No filters — the whole register')
                : ($locale === 'ar' ? 'عوامل التصفية' : 'Filters').' — '.implode(' | ', $applied),
        ];
    }
}
