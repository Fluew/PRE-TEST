<?php

namespace App\Services\Import;

use App\Exceptions\WorkflowException;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Universal document import for Inventory (new products) and Supplier Quotations.
 *
 *   upload → (queued) extraction → preview rows with issues → staff edit / match / skip
 *   → confirm → records saved in one transaction.
 *
 * Nothing reaches inventory or supplier quotes before staff confirm. The original file is kept
 * on the private disk; extracted values, edits (with who edited), and the final import are
 * stored with the batch and in the audit log. OCR results always need staff confirmation.
 */
class ImportService
{
    public const DISK = 'local';

    /** CSV and Excel files this small are read at once; everything else goes to the queue. */
    public const INLINE_BYTES = 2 * 1024 * 1024;

    public function __construct(private DocumentExtractor $extractor) {}

    public function rules(string $module): RowRules
    {
        return $module === ImportBatch::INVENTORY ? new InventoryRowRules : new SupplierQuoteRowRules;
    }

    /**
     * Store the file privately and start extraction.
     *
     * @param  array<string, mixed>  $meta
     */
    public function upload(Team $team, User $user, string $module, UploadedFile $file, array $meta = []): ImportBatch
    {
        if (! in_array($module, ImportBatch::MODULES, true)) {
            throw new WorkflowException('Unknown import type.');
        }
        $path = $file->getRealPath() ?: $file->getPathname();
        $name = $file->getClientOriginalName();
        try {
            // Content check before anything is stored: the real file type, size and readability.
            $type = app(FileReader::class)->forStaffImports()->detectType($path, $name);
        } catch (ExtractionException $e) {
            throw new WorkflowException($e->getMessage(), 'file');
        }
        if ($module === ImportBatch::SUPPLIER_QUOTATION) {
            Supplier::where('team_id', $team->id)->findOrFail($meta['supplier_id'] ?? 0);
        }

        $ext = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $stored = Storage::disk(self::DISK)->putFileAs("imports/{$team->id}", $file, Str::uuid().'.'.$ext);

        $batch = ImportBatch::create([
            'team_id' => $team->id,
            'module' => $module,
            'status' => ImportBatch::QUEUED,
            'original_filename' => mb_substr($name, 0, 255),
            'stored_path' => $stored,
            'mime_type' => $file->getMimeType(),
            'file_type' => $type,
            'file_size' => (int) $file->getSize(),
            'file_hash' => hash_file('sha256', $path),
            'meta' => $meta ?: null,
            'uploaded_by_id' => $user->id,
        ]);
        AuditLogger::record('import.uploaded', "Import of \"{$batch->original_filename}\" ({$module}) uploaded.", $batch, ['size' => $batch->file_size, 'type' => $type], $user);

        if (in_array($type, ['csv', 'xlsx', 'xls'], true) && $batch->file_size <= self::INLINE_BYTES) {
            try {
                ProcessImportBatch::dispatchSync($batch->id);
            } catch (\Throwable $e) {
                report($e);
                $this->markFailed($batch->fresh(), 'The file could not be processed: '.mb_substr($e->getMessage(), 0, 300));
            }
        } else {
            ProcessImportBatch::dispatch($batch->id);
        }

        return $batch->fresh();
    }

    /**
     * Extract the document into preview rows. Safe to run again (a retry replaces the rows
     * it made before), so a failed or repeated attempt never leaves duplicates.
     */
    public function process(ImportBatch $batch): void
    {
        $batch->update(['status' => ImportBatch::PROCESSING, 'error' => null, 'attempts' => $batch->attempts + 1]);
        $full = Storage::disk(self::DISK)->path($batch->stored_path);

        $result = $this->extractor->extract($full, $batch->original_filename, $batch->module);
        $mapped = RowMapper::map($batch->module, $result['rows']);

        DB::transaction(function () use ($batch, $result, $mapped) {
            $batch->rows()->delete();
            $batch->forceFill(['extraction_method' => $result['method'], 'page_count' => $result['pages']]);

            $rows = collect($mapped['rows'])->values()->map(fn ($r) => new ImportRow([
                'import_batch_id' => $batch->id,
                'row_number' => $r['row_number'],
                'raw' => mb_substr($r['raw'], 0, 2000),
                'extracted' => $r['extracted'] + array_filter(['_total_line' => $r['total_line'], '_no_header' => $r['no_header']]),
                'values' => [],
                'skipped' => $r['total_line'],
                'status' => ImportRow::READY,
            ]));
            $this->rules($batch->module)->analyze($batch, $rows, true);
            foreach ($rows->chunk(500) as $chunk) {
                foreach ($chunk as $row) {
                    $row->save();
                }
            }

            $batch->forceFill([
                'status' => ImportBatch::READY,
                'processed_at' => now(),
                'summary' => $this->summary($batch) + ['header_found' => $mapped['header_found'], 'unparsed_lines' => $mapped['unparsed']],
            ])->save();
        });

        AuditLogger::record('import.extracted', "\"{$batch->original_filename}\" read by {$batch->extraction_method}: {$batch->rows()->count()} rows for review.", $batch, ['pages' => $batch->page_count], null);
    }

    public function markFailed(ImportBatch $batch, string $message): void
    {
        DB::transaction(function () use ($batch, $message) {
            $batch->rows()->delete();
            $batch->update(['status' => ImportBatch::FAILED, 'error' => mb_substr($message, 0, 2000)]);
        });
        AuditLogger::record('import.failed', "Import of \"{$batch->original_filename}\" failed: {$message}", $batch, [], null, 'failure', 'warning');
    }

    /**
     * Staff edit of one row: changed fields, product match, or skip/restore. The other rows are
     * checked again because duplicates depend on them.
     *
     * @param  array<string, mixed>  $changes
     */
    public function updateRow(ImportRow $row, User $user, array $changes, bool $matchChanged = false, ?int $productId = null, ?bool $skip = null): ImportRow
    {
        return DB::transaction(function () use ($row, $user, $changes, $matchChanged, $productId, $skip) {
            $batch = ImportBatch::lockForUpdate()->findOrFail($row->import_batch_id);
            $this->assertEditable($batch);
            $row = ImportRow::lockForUpdate()->findOrFail($row->id);
            if ($row->status === ImportRow::IMPORTED) {
                throw new WorkflowException('This row was already imported.');
            }

            $values = $row->values ?? [];
            $sources = $row->sources ?? [];
            foreach ($changes as $field => $value) {
                $values[$field] = is_string($value) ? trim($value) : $value;
                $sources[$field] = 'edited';
            }
            if ($matchChanged) {
                if ($productId !== null) {
                    Product::where('team_id', $batch->team_id)->findOrFail($productId);
                }
                $row->matched_product_id = $productId;
                $sources['product'] = 'edited';
            }
            if ($skip !== null) {
                $row->skipped = $skip;
            }
            $row->values = $values;
            $row->sources = $sources;
            $row->edited_by_id = $user->id;
            $row->save();

            $this->reanalyze($batch);

            return $row->fresh();
        });
    }

    /** Skip every row with errors (or restore all skipped rows). */
    public function bulkSkip(ImportBatch $batch, User $user, string $which): int
    {
        return DB::transaction(function () use ($batch, $which, $user) {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            $this->assertEditable($batch);
            $n = $which === 'restore'
                ? $batch->rows()->where('skipped', true)->where('status', '!=', ImportRow::IMPORTED)->update(['skipped' => false, 'edited_by_id' => $user->id])
                : $batch->rows()->whereIn('status', [ImportRow::NEEDS_REVIEW, ImportRow::DUPLICATE])->update(['skipped' => true, 'edited_by_id' => $user->id]);
            $this->reanalyze($batch);

            return $n;
        });
    }

    /**
     * Save the reviewed rows. Refused while any row still has an error (fix or skip it first),
     * and, for OCR documents, until staff confirm they checked the values against the original.
     *
     * @return array{created: int, skipped: int}
     */
    public function confirm(ImportBatch $batch, User $user, bool $ocrChecked = false): array
    {
        $result = DB::transaction(function () use ($batch, $user, $ocrChecked) {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== ImportBatch::READY) {
                throw new WorkflowException("This import is {$this->statusLabel($batch->status)} and cannot be confirmed.");
            }
            $rows = $batch->rows()->orderBy('row_number')->get();
            $this->rules($batch->module)->analyze($batch, $rows); // check again against current data

            $blocking = $rows->where('status', ImportRow::NEEDS_REVIEW)->count();
            if ($blocking) {
                throw new WorkflowException("{$blocking} row(s) still need review. Fix them or skip them before importing.");
            }
            if ($batch->usedOcr() && ! $ocrChecked) {
                throw new WorkflowException('This document was read with OCR. Confirm that you checked the values against the original before importing.', 'ocr_checked');
            }
            $ready = $rows->where('status', ImportRow::READY);
            if ($ready->isEmpty()) {
                throw new WorkflowException('There are no rows to import.');
            }
            if ($batch->module === ImportBatch::SUPPLIER_QUOTATION) {
                $this->assertQuoteMeta($batch);
            }

            $batch->update(['status' => ImportBatch::IMPORTING]);
            foreach ($ready as $row) {
                [$type, $id] = $batch->module === ImportBatch::INVENTORY ? $this->createProduct($batch, $row) : $this->createQuote($batch, $row, $user);
                $row->forceFill(['status' => ImportRow::IMPORTED, 'created_type' => $type, 'created_id' => $id])->save();
            }
            foreach ($rows->where('status', '!=', ImportRow::IMPORTED) as $row) {
                $row->save();
            }

            $edited = $rows->filter(fn ($r) => collect($r->sources ?? [])->contains('edited'))->count();
            $batch->update([
                'status' => ImportBatch::COMPLETED,
                'imported_by_id' => $user->id,
                'imported_at' => now(),
                'summary' => array_merge($batch->summary ?? [], $this->summary($batch), ['created' => $ready->count(), 'edited_rows' => $edited]),
            ]);
            AuditLogger::record('import.completed', "Imported {$ready->count()} ".($batch->module === ImportBatch::INVENTORY ? 'product(s)' : 'supplier quote(s)')." from \"{$batch->original_filename}\".", $batch,
                ['created' => $ready->count(), 'skipped' => $rows->count() - $ready->count(), 'edited_rows' => $edited, 'method' => $batch->extraction_method, 'ocr_checked' => $ocrChecked], $user);

            return ['created' => $ready->count(), 'skipped' => $rows->count() - $ready->count()];
        });

        return $result;
    }

    public function cancel(ImportBatch $batch, User $user): void
    {
        DB::transaction(function () use ($batch, $user) {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            if (in_array($batch->status, [ImportBatch::COMPLETED, ImportBatch::IMPORTING, ImportBatch::CANCELLED], true)) {
                throw new WorkflowException("This import is {$this->statusLabel($batch->status)} and cannot be cancelled.");
            }
            $batch->update(['status' => ImportBatch::CANCELLED]);
            AuditLogger::record('import.cancelled', "Import of \"{$batch->original_filename}\" cancelled.", $batch, [], $user);
        });
    }

    /** @return array<string, int> */
    public function summary(ImportBatch $batch): array
    {
        $counts = $batch->rows()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return ['total' => (int) $counts->sum()] + collect([ImportRow::READY, ImportRow::NEEDS_REVIEW, ImportRow::DUPLICATE, ImportRow::EXISTING, ImportRow::SKIPPED, ImportRow::IMPORTED])
            ->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    public function statusLabel(string $status): string
    {
        return str_replace('_', ' ', $status);
    }

    private function reanalyze(ImportBatch $batch): void
    {
        $rows = $batch->rows()->get();
        $this->rules($batch->module)->analyze($batch, $rows);
        foreach ($rows as $r) {
            if ($r->isDirty()) {
                $r->save();
            }
        }
        $batch->update(['summary' => array_merge($batch->summary ?? [], $this->summary($batch))]);
    }

    private function assertEditable(ImportBatch $batch): void
    {
        if ($batch->status !== ImportBatch::READY) {
            throw new WorkflowException("This import is {$this->statusLabel($batch->status)}; rows can be changed only while it is ready for review.");
        }
    }

    private function assertQuoteMeta(ImportBatch $batch): void
    {
        $m = $batch->meta ?? [];
        Supplier::where('team_id', $batch->team_id)->findOrFail($m['supplier_id'] ?? 0);
        if (empty($m['quoted_on']) || empty($m['currency'])) {
            throw new WorkflowException('The quotation date and currency are required.');
        }
    }

    /** @return array{0: string, 1: int} */
    private function createProduct(ImportBatch $batch, ImportRow $row): array
    {
        $v = $row->values;
        $sku = $v['sku'];
        if ($sku === null) {
            do {
                $sku = 'PRD-'.strtoupper(Str::random(6));
            } while (Product::withTrashed()->where('team_id', $batch->team_id)->where('sku', $sku)->exists());
            $row->sources = array_merge($row->sources ?? [], ['sku' => 'generated']);
            $row->values = array_merge($v, ['sku' => $sku]);
        }
        $missing = array_values(array_filter(InventoryRowRules::COMPLETION_FIELDS, fn ($f) => $v[$f] === null));
        $active = ! array_intersect($missing, InventoryRowRules::REQUIRED_TO_ACTIVATE);

        $product = new Product([
            'team_id' => $batch->team_id,
            'sku' => $sku,
            'name' => $v['name'],
            'category' => $v['category'] ?? 'General',
            'brand' => $v['brand'],
            'unit' => $v['unit'],
            'cost_price' => $v['cost_price'],
            'price' => $v['price'],
            'stock' => 0,
            'is_active' => $active,
            'missing_fields' => $missing ?: null,
            'import_batch_id' => $batch->id,
        ]);
        $product->save();
        $product->updateCalculatedStatus();
        if (! $active) {
            $product->forceFill(['status' => 'Incomplete'])->save();
        }

        return ['product', $product->id];
    }

    /** @return array{0: string, 1: int} */
    private function createQuote(ImportBatch $batch, ImportRow $row, User $user): array
    {
        $v = $row->values;
        $m = $batch->meta;
        $quote = SupplierQuote::create([
            'team_id' => $batch->team_id,
            'supplier_id' => $m['supplier_id'],
            'product_id' => $row->matched_product_id,
            'reference' => $m['reference'] ?? null,
            'unit_price' => $v['unit_price'],
            'quantity' => $v['quantity'],
            'amount' => $v['amount'],
            'unit_price_source' => ($row->sources['unit_price'] ?? null) === 'calculated' ? 'calculated' : 'supplier',
            'amount_source' => $v['amount'] === null ? null : (($row->sources['amount'] ?? null) === 'calculated' ? 'calculated' : 'supplier'),
            'description_as_quoted' => $v['description'],
            'currency' => $m['currency'],
            'unit' => $v['unit'],
            'tax_inclusive' => (bool) ($m['tax_inclusive'] ?? false),
            'quoted_on' => $m['quoted_on'],
            'valid_until' => $m['valid_until'] ?? null,
            'notes' => "Imported from {$batch->original_filename}",
            'recorded_by_id' => $user->id,
            'import_batch_id' => $batch->id,
        ]);

        return ['supplier_quote', $quote->id];
    }
}
