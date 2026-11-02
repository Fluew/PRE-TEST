<?php

namespace App\Services\Import;

/**
 * Reads numbers the way suppliers and scanners write them: "₱1,250.00", "PHP 75", "1.250,50",
 * "(15.00)". For OCR text, look-alike letters inside a number are corrected (l5 → 15, 1O → 10),
 * and the correction is reported so staff can check it. Anything else that is not a number is
 * reported as unreadable, never guessed.
 */
class ValueParser
{
    /** @return array{value: ?float, problem: ?string, corrected_from: ?string} */
    public static function number(?string $text, bool $ocr = false): array
    {
        $none = ['value' => null, 'problem' => null, 'corrected_from' => null];
        if ($text === null || trim($text) === '') {
            return $none;
        }
        $original = trim($text);
        $s = preg_replace('/(₱|php|usd|us\$|\$|pesos?|\bp(?=\s*\d))/iu', '', $original) ?? $original;
        $s = str_replace([' ', "\u{00A0}"], '', $s);
        $negative = (bool) preg_match('/^\(.*\)$/', $s);
        $s = trim($s, '()');
        if (str_starts_with($s, '-')) {
            $negative = true;
            $s = substr($s, 1);
        }

        $corrected = null;
        if ($ocr && preg_match('/\d/', $s) && preg_match('/^[\d.,OoIl|]+$/u', $s) && preg_match('/[OoIl|]/u', $s)) {
            $corrected = $original;
            $s = strtr($s, ['O' => '0', 'o' => '0', 'I' => '1', 'l' => '1', '|' => '1']);
        }

        if (str_contains($s, ',') && str_contains($s, '.')) {
            // The last separator is the decimal one: 1,250.50 or 1.250,50.
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } elseif (str_contains($s, ',')) {
            $s = preg_match('/^\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        }

        if ($s === '' || ! is_numeric($s)) {
            return ['value' => null, 'problem' => "\"{$original}\" is not a readable number", 'corrected_from' => null];
        }

        return ['value' => ($negative ? -1 : 1) * (float) $s, 'problem' => null, 'corrected_from' => $corrected];
    }

    public static function text(?string $text, int $max = 255): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return $t === '' ? null : mb_substr($t, 0, $max);
    }
}
