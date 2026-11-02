<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Import\ImportService;
use App\Services\MaterialList\ExtractionException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads one uploaded document into preview rows (OCR can take minutes for long scans).
 * A problem with the file itself (unsupported, damaged, password-protected, no text) fails at
 * once with a message for staff. Other errors are retried; after the last attempt the batch is
 * marked failed. Each attempt replaces the rows of the previous one, so retries never duplicate.
 */
class ProcessImportBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Long scanned PDFs: Tesseract runs page by page. */
    public int $timeout = 1800;

    public function __construct(public int $batchId) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(ImportService $imports): void
    {
        $batch = ImportBatch::find($this->batchId);
        if (! $batch || ! in_array($batch->status, [ImportBatch::QUEUED, ImportBatch::PROCESSING, ImportBatch::FAILED], true)) {
            return; // cancelled, already reviewed or imported
        }

        try {
            $imports->process($batch);
        } catch (ExtractionException $e) {
            // The file cannot be read as it is; trying again would give the same result.
            $imports->markFailed($batch, $e->getMessage());
        }
    }

    public function failed(?Throwable $e): void
    {
        $batch = ImportBatch::find($this->batchId);
        if ($batch && $batch->status !== ImportBatch::COMPLETED) {
            app(ImportService::class)->markFailed($batch, 'Processing failed after '.$this->tries.' attempts. Try uploading the file again. ('.mb_substr((string) $e?->getMessage(), 0, 300).')');
        }
    }
}
