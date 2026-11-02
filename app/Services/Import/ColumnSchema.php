<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use App\Services\MaterialList\HeaderMapper;

/**
 * The columns each import understands, with the header wordings recognised for each
 * (normalised: lowercase, no punctuation). A row counts as a header only when it names the
 * module's key column plus at least one other known column, so data rows are never taken
 * for headers. Every repeated header (next sheet, next PDF page) starts a new table.
 */
class ColumnSchema
{
    public const FIELDS = [
        ImportBatch::INVENTORY => [
            'sku' => ['sku', 'code', 'item code', 'product code', 'part no', 'part number', 'catalog no', 'catalogue no', 'stock code', 'item no'],
            'name' => ['product name', 'name', 'item name', 'description', 'item description', 'product', 'item', 'particulars', 'product description', 'article', 'items', 'products'],
            'category' => ['category', 'cat', 'group', 'product category', 'type', 'class'],
            'brand' => ['brand', 'brand name', 'manufacturer', 'make', 'mfr', 'maker'],
            'unit' => ['unit', 'uom', 'unit of measure', 'unit of measurement', 'um', 'measure', 'units'],
            'cost_price' => ['cost price', 'cost', 'unit cost', 'purchase price', 'buying price', 'acquisition cost'],
            'price' => ['selling price', 'price', 'srp', 'retail price', 'unit price', 'sale price', 'sales price'],
            'stock' => ['stock', 'qty', 'quantity', 'on hand', 'current stock', 'stock on hand', 'soh'],
        ],
        ImportBatch::SUPPLIER_QUOTATION => [
            'description' => ['description', 'item', 'items', 'particulars', 'product', 'products', 'product name', 'item description', 'material', 'materials', 'name', 'article', 'goods', 'product description', 'item name', 'item details'],
            'item_code' => ['code', 'item code', 'sku', 'part no', 'part number', 'product code', 'model', 'model no', 'catalog no', 'ref'],
            'unit' => ['unit', 'uom', 'units', 'unit of measure', 'unit of measurement', 'um', 'measure'],
            'quantity' => ['qty', 'quantity', 'quantities', 'nos', 'order qty', 'qty ordered', 'no of units'],
            'unit_price' => ['unit price', 'price', 'rate', 'unit cost', 'cost', 'price per unit', 'u price', 'unit amount', 'unit rate', 'net price'],
            'amount' => ['amount', 'total', 'total price', 'line total', 'total amount', 'ext price', 'extended price', 'net amount', 'total cost'],
        ],
    ];

    /** The column a row must have for it to be a header. */
    public const KEY = [
        ImportBatch::INVENTORY => 'name',
        ImportBatch::SUPPLIER_QUOTATION => 'description',
    ];

    /** Header text with currency words and bracketed notes removed: "Unit Price (PHP)" → "unit price". */
    public static function normalise(mixed $header): string
    {
        $h = HeaderMapper::normalise(preg_replace('/\(.*?\)|\[.*?\]/u', ' ', (string) $header) ?? (string) $header);
        $h = preg_replace('/\b(php|usd|peso|pesos|in|vat inc|vat incl|vat ex)\b/u', ' ', $h) ?? $h;

        return trim(preg_replace('/\s+/', ' ', $h) ?? $h);
    }

    public static function fieldFor(string $module, mixed $header): ?string
    {
        $h = self::normalise($header);
        if ($h === '' || mb_strlen($h) > 40) {
            return null;
        }
        foreach (self::FIELDS[$module] as $field => $words) {
            if (in_array($h, $words, true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Column index per field for a header row, or null when the row is not a header.
     *
     * @param  array<int, string>  $cells
     * @return array<string, int>|null
     */
    public static function headerMap(string $module, array $cells): ?array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $field = self::fieldFor($module, $cell);
            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $i;
            }
        }

        return isset($map[self::KEY[$module]]) && count($map) >= 2 ? $map : null;
    }
}
