<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowException;
use App\Http\Middleware\EnsureRole;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use App\Services\Import\ImportService;
use App\Services\MaterialList\FileReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document imports (JSON API used by the import preview). Inventory imports need the Inventory
 * module; supplier quotation imports need the Quotations module. Administrators can do both.
 */
class ImportController extends Controller
{
    /** The staff role (module) each import belongs to. */
    private const MODULE_ROLE = [
        ImportBatch::INVENTORY => 'staff_inventory',
        ImportBatch::SUPPLIER_QUOTATION => 'staff_quotations',
    ];

    public function __construct(private ImportService $imports) {}

    public function upload(Request $request, mixed $current_team, string $module): JsonResponse
    {
        $team = $this->team($request);
        abort_unless(isset(self::MODULE_ROLE[$module]), 404);
        $this->authorizeModule($request->user(), $module);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(FileReader::STAFF_MAX_BYTES / 1024), 'extensions:csv,txt,xls,xlsx,pdf,png,jpg,jpeg',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream,application/x-ole-storage,application/CDFV2,application/pdf,image/png,image/jpeg'],
            'supplier_id' => [Rule::requiredIf($module === ImportBatch::SUPPLIER_QUOTATION), 'nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'quoted_on' => [Rule::requiredIf($module === ImportBatch::SUPPLIER_QUOTATION), 'nullable', 'date', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quoted_on'],
            'currency' => ['nullable', 'string', 'size:3'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'reference' => ['nullable', 'string', 'max:100'],
        ], [
            'file.max' => 'The file is larger than 25 MB.',
            'file.extensions' => 'Upload a CSV, Excel (.xls/.xlsx), PDF, PNG or JPG file.',
            'file.mimetypes' => 'The file content does not match a CSV, Excel, PDF, PNG or JPG file.',
        ]);

        $meta = $module === ImportBatch::SUPPLIER_QUOTATION ? [
            'supplier_id' => (int) $data['supplier_id'],
            'quoted_on' => $data['quoted_on'],
            'valid_until' => $data['valid_until'] ?? null,
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'tax_inclusive' => (bool) ($data['tax_inclusive'] ?? false),
            'reference' => $data['reference'] ?? null,
        ] : [];

        try {
            $batch = $this->imports->upload($team, $request->user(), $module, $request->file('file'), $meta);
        } catch (WorkflowException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], 422);
        }

        return response()->json($this->present($batch->fresh()), 201);
    }

    public function index(Request $request, mixed $current_team, string $module): JsonResponse
    {
        $team = $this->team($request);
        abort_unless(isset(self::MODULE_ROLE[$module]), 404);
        $this->authorizeModule($request->user(), $module);

        $batches = ImportBatch::where('team_id', $team->id)->where('module', $module)->with(['uploadedBy:id,name', 'importedBy:id,name'])
            ->latest('id')->paginate(10);

        return response()->json($batches->through(fn ($b) => $this->present($b)));
    }

    public function show(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $data = $request->validate(['status' => ['nullable', Rule::in(['all', 'attention', ImportRow::READY, ImportRow::NEEDS_REVIEW, ImportRow::DUPLICATE, ImportRow::EXISTING, ImportRow::SKIPPED, ImportRow::IMPORTED])], 'per_page' => ['nullable', 'integer', 'min:10', 'max:200']]);

        $rows = $batch->rows()->with('matchedProduct:id,name,sku,unit')
            ->when(($data['status'] ?? 'all') === 'attention', fn ($q) => $q->whereIn('status', [ImportRow::NEEDS_REVIEW, ImportRow::DUPLICATE]))
            ->when(! in_array($data['status'] ?? 'all', ['all', 'attention'], true), fn ($q) => $q->where('status', $data['status']))
            ->orderBy('row_number')->paginate($data['per_page'] ?? 50);

        return response()->json(['batch' => $this->present($batch), 'rows' => $rows]);
    }

    public function updateRow(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $row = ImportRow::where('import_batch_id', $batch->id)->findOrFail((int) $request->route('row'));
        $fields = $batch->module === ImportBatch::INVENTORY
            ? ['sku', 'name', 'category', 'brand', 'unit', 'cost_price', 'price']
            : ['description', 'item_code', 'unit', 'quantity', 'unit_price', 'amount'];
        $data = $request->validate([
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'string', 'max:1000'],
            'product_id' => ['nullable', 'integer'],
            'skipped' => ['nullable', 'boolean'],
        ]);
        $changes = array_intersect_key($data['values'] ?? [], array_flip($fields));

        return $this->json(fn () => $this->imports->updateRow($row, $request->user(), $changes, $request->has('product_id'), $data['product_id'] ?? null, $request->has('skipped') ? (bool) $data['skipped'] : null)->load('matchedProduct:id,name,sku,unit'));
    }

    public function bulk(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $data = $request->validate(['action' => ['required', Rule::in(['skip_problems', 'restore'])]]);

        return $this->json(fn () => ['changed' => $this->imports->bulkSkip($batch, $request->user(), $data['action'] === 'restore' ? 'restore' : 'skip')]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $data = $request->validate(['ocr_checked' => ['nullable', 'boolean']]);

        return $this->json(fn () => $this->imports->confirm($batch, $request->user(), (bool) ($data['ocr_checked'] ?? false)) + ['batch' => $this->present($batch->fresh())]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $batch = $this->batch($request);

        return $this->json(function () use ($batch, $request) {
            $this->imports->cancel($batch, $request->user());

            return ['batch' => $this->present($batch->fresh())];
        });
    }

    /** The original uploaded document, from private storage. */
    public function file(Request $request): StreamedResponse
    {
        $batch = $this->batch($request);
        abort_unless(Storage::disk(ImportService::DISK)->exists($batch->stored_path), 404);

        return Storage::disk(ImportService::DISK)->download($batch->stored_path, $batch->original_filename);
    }

    /** Products for the "match to product" picker of supplier quotation rows. */
    public function products(Request $request): JsonResponse
    {
        $batch = $this->batch($request);
        $q = trim((string) $request->query('q', ''));

        return response()->json(Product::where('team_id', $batch->team_id)->where('is_active', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%")))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'sku', 'unit']));
    }

    private function present(ImportBatch $b): array
    {
        return $b->only(['id', 'module', 'status', 'original_filename', 'file_type', 'file_size', 'extraction_method', 'page_count', 'meta', 'summary', 'error', 'attempts', 'processed_at', 'imported_at', 'created_at']) + [
            'uses_ocr' => $b->usedOcr(),
            'uploaded_by' => $b->uploadedBy?->name,
            'imported_by' => $b->importedBy?->name,
        ];
    }

    private function json(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (WorkflowException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], 422);
        }
    }

    private function batch(Request $request): ImportBatch
    {
        $batch = ImportBatch::where('team_id', $this->team($request)->id)->with(['uploadedBy:id,name', 'importedBy:id,name'])->findOrFail((int) $request->route('batch'));
        $this->authorizeModule($request->user(), $batch->module);

        return $batch;
    }

    private function authorizeModule(User $user, string $module): void
    {
        abort_unless(EnsureRole::allows($user, [self::MODULE_ROLE[$module]]), 403, 'You do not have access to this import.');
    }

    private function team(Request $request): Team
    {
        $param = $request->route('current_team');
        $team = $param instanceof Team ? $param : Team::where('slug', (string) $param)->first();
        abort_if(! $team, 403, 'No active team selected.');

        return $team;
    }
}
