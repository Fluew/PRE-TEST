<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Product;
use App\Services\MaterialList\ProductMatcher;
use Illuminate\Support\Collection;

/**
 * Inventory import: new product records only. Creating products, receiving stock and changing
 * prices stay separate operations, so a row whose SKU already exists is not imported, and a
 * stock column is shown but never imported. Missing values are left empty (never invented):
 * such a product is saved inactive and listed for completion. A missing SKU is generated.
 */
class InventoryRowRules extends RowRules
{
    protected const NUMERIC = ['cost_price', 'price', 'stock'];

    /** A product without these cannot be sold, so it stays inactive until they are filled in. */
    public const REQUIRED_TO_ACTIVATE = ['unit', 'price'];

    /** Fields tracked for completion when missing. */
    public const COMPLETION_FIELDS = ['unit', 'cost_price', 'price', 'category'];

    private const LABELS = ['sku' => 'SKU', 'name' => 'Product name', 'category' => 'Category', 'brand' => 'Brand', 'unit' => 'Unit', 'cost_price' => 'Cost price', 'price' => 'Selling price', 'stock' => 'Stock'];

    public function label(string $field): string
    {
        return self::LABELS[$field] ?? $field;
    }

    public function analyze(ImportBatch $batch, Collection $rows, bool $withMatching = false): void
    {
        $ocr = $batch->usedOcr();
        $existing = Product::withTrashed()->where('team_id', $batch->team_id)->get(['id', 'sku', 'name', 'deleted_at'])
            ->keyBy(fn ($p) => mb_strtoupper($p->sku));
        $matcher = $withMatching ? new ProductMatcher($batch->team_id) : null;
        $seen = [];

        foreach ($rows->sortBy('row_number') as $row) {
            $issues = [];
            $values = [];
            $sources = array_intersect_key($row->sources ?? [], array_flip(['sku', 'name', 'category', 'brand', 'unit', 'cost_price', 'price']));

            foreach (['sku', 'name', 'category', 'brand', 'unit', 'cost_price', 'price', 'stock'] as $f) {
                $r = $this->field($row, $f, $ocr);
                $values[$f] = $r['value'];
                $sources[$f] = $r['source'];
                if ($r['problem']) {
                    $issues[] = self::issue($f === 'stock' ? 'warning' : 'error', $f, "{$this->label($f)}: {$r['problem']}.");
                }
                if ($r['corrected_from'] !== null) {
                    $issues[] = self::issue('warning', $f, "{$this->label($f)} read by OCR as \"{$r['corrected_from']}\" and taken as {$r['value']}. Check it against the original.");
                }
            }
            $values['sku'] = $values['sku'] !== null ? mb_strtoupper(mb_substr($values['sku'], 0, 100)) : null;
            $values['name'] = $values['name'] !== null ? mb_substr($values['name'], 0, 255) : null;
            $values['brand'] = $values['brand'] !== null ? mb_substr($values['brand'], 0, 100) : null;
            $values['unit'] = $values['unit'] !== null ? mb_substr($values['unit'], 0, 50) : null;

            if ($values['name'] === null) {
                $issues[] = self::issue('error', 'name', 'Product name is required.');
            }
            foreach (['cost_price', 'price'] as $f) {
                if ($values[$f] !== null && $values[$f] < 0) {
                    $issues[] = self::issue('error', $f, "{$this->label($f)} cannot be negative.");
                }
            }
            $missing = array_values(array_filter(self::COMPLETION_FIELDS, fn ($f) => $values[$f] === null));
            if (array_intersect($missing, self::REQUIRED_TO_ACTIVATE)) {
                $issues[] = self::issue('warning', null, 'Missing '.implode(', ', array_map(fn ($f) => mb_strtolower($this->label($f)), $missing)).': the product will be saved inactive until these are completed.');
            } elseif ($missing) {
                $issues[] = self::issue('info', null, 'Missing '.implode(', ', array_map(fn ($f) => mb_strtolower($this->label($f)), $missing)).': it can be completed later.');
            }
            if ($values['sku'] === null) {
                $issues[] = self::issue('info', 'sku', 'No SKU in the document: an internal SKU will be generated on import.');
            }
            if ($values['stock'] !== null) {
                $issues[] = self::issue('info', 'stock', "Stock in the file ({$values['stock']}) is not imported. Receive stock through Stock In or a purchase order.");
            }
            if ($row->extracted['_total_line'] ?? false) {
                $issues[] = self::issue('info', null, 'Looks like a total or summary line, so it was skipped. Restore it if it is a product.');
            }
            if ($row->extracted['_no_header'] ?? false) {
                $issues[] = self::issue('warning', null, 'No column headers were recognised: the whole line was put in the product name. Split it into the right fields.');
            }

            $forced = null;
            $match = $values['sku'] !== null ? $existing->get($values['sku']) : null;
            if ($match) {
                $issues[] = self::issue('info', 'sku', "SKU already used by \"{$match->name}\"".($match->deleted_at ? ' (archived)' : '').'. Not imported: change existing products from the product form.');
                $forced = ImportRow::EXISTING;
                $row->matched_product_id = $match->deleted_at ? null : $match->id;
            } elseif ($values['sku'] !== null && isset($seen[$values['sku']])) {
                $issues[] = self::issue('info', 'sku', "Same SKU as row {$seen[$values['sku']]} of this file.");
                $forced = ImportRow::DUPLICATE;
            } else {
                $row->matched_product_id = null;
                if ($values['sku'] !== null) {
                    $seen[$values['sku']] = $row->row_number;
                }
            }

            if ($matcher && $values['name'] !== null && ! $match) {
                $row->suggestions = array_values(array_filter($matcher->match(['description' => $values['name']])['suggestions'], fn ($s) => $s['level'] === 'high'));
            }
            if (! $match && ! empty($row->suggestions)) {
                $issues[] = self::issue('warning', 'name', 'Similar to existing product "'.$row->suggestions[0]['name'].'" ('.$row->suggestions[0]['sku'].'). Skip this row if it is the same product.');
            }

            $row->values = $values;
            $row->sources = $sources;
            $row->issues = $issues;
            $row->status = $this->status($row, $issues, $forced);
        }
    }
}
