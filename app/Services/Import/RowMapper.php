<?php

namespace App\Services\Import;

/**
 * Turns extracted table rows into import lines, field by field. Every header row (also a
 * repeated one on the next sheet or page) sets the column positions for the rows below it.
 * Text above the first header (titles, addresses) is kept as "unparsed lines" for display.
 * Total/subtotal/VAT lines are kept but pre-skipped, so nothing disappears silently.
 * When no header exists at all, each line becomes a row for staff to complete by hand.
 */
class RowMapper
{
    private const TOTAL_LINE = '/^(sub\s*-?\s*total|grand\s*total|total(\s+amount)?|vat|tax|less|discount|amount\s+due|net\s+total|balance)\b/iu';

    /**
     * @param  array<int, array<int, string>>  $table
     * @return array{rows: list<array{row_number: int, raw: string, extracted: array<string, ?string>, total_line: bool, no_header: bool}>, unparsed: list<string>, header_found: bool}
     */
    public static function map(string $module, array $table): array
    {
        $fields = array_keys(ColumnSchema::FIELDS[$module]);
        $key = ColumnSchema::KEY[$module];
        $map = null;
        $out = [];
        $unparsed = [];

        foreach ($table as $i => $cells) {
            $raw = trim(implode(' | ', array_filter($cells, fn ($c) => $c !== '')));
            if ($raw === '') {
                continue;
            }
            if ($header = ColumnSchema::headerMap($module, $cells)) {
                $map = $header;

                continue;
            }
            if ($map === null) {
                $unparsed[] = $raw;

                continue;
            }

            $extracted = [];
            foreach ($fields as $f) {
                $v = isset($map[$f]) ? trim((string) ($cells[$map[$f]] ?? '')) : '';
                $extracted[$f] = $v === '' ? null : $v;
            }
            $label = (string) ($extracted[$key] ?? '');
            $numbersOnly = $label === '' && collect($extracted)->filter()->isNotEmpty();
            $out[] = [
                'row_number' => $i + 1,
                'raw' => $raw,
                'extracted' => $extracted,
                'total_line' => (bool) preg_match(self::TOTAL_LINE, $label !== '' ? $label : $raw) || ($numbersOnly && preg_match(self::TOTAL_LINE, $raw)),
                'no_header' => false,
            ];
        }

        // No recognisable header anywhere: offer every line for manual completion.
        if ($map === null) {
            foreach ($unparsed as $n => $line) {
                $extracted = array_fill_keys($fields, null);
                $extracted[$key] = mb_substr($line, 0, 500);
                $out[] = ['row_number' => $n + 1, 'raw' => $line, 'extracted' => $extracted, 'total_line' => (bool) preg_match(self::TOTAL_LINE, $line), 'no_header' => true];
            }
            $unparsed = [];
        }

        return ['rows' => $out, 'unparsed' => array_slice($unparsed, 0, 50), 'header_found' => $map !== null];
    }
}
