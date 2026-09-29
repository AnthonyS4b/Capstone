<?php
/**
 * Quantities for products sold by weight.
 *
 * "Per Kilo" products keep stock and sell in kilograms with up to 2 decimals
 * (25.50 kg, 0.75 kg). Every other unit (Per Piece, Per Pack, Per Box, ...)
 * stays a whole number. Stock and quantity columns are DECIMAL(10,2) so both fit.
 *
 * Arithmetic is done in hundredths (integers) so 25.50 - 2.50 is exactly 23.00.
 */

function unit_is_per_kilo($unit): bool
{
    return strtolower(trim((string)$unit)) === 'per kilo';
}

/**
 * Validate a quantity typed by a person or sent by the browser.
 * Returns it as a float, or null when it is not allowed for this unit:
 * Per Kilo takes 0.01 or more with up to 2 decimals, other units a whole number.
 * $allowZero is for stock levels (a product may be saved with 0 in stock).
 */
function parse_unit_quantity($raw, bool $perKilo, bool $allowZero = false): ?float
{
    $text = trim((string)$raw);
    $pattern = $perKilo ? '/^\d{1,8}(\.\d{1,2})?$/' : '/^\d{1,8}$/';
    if (!preg_match($pattern, $text)) return null;
    $qty = round((float)$text, 2);
    if ($qty < 0 || (!$allowZero && $qty <= 0)) return null;
    return $qty;
}

function qty_to_hundredths($qty): int
{
    return (int)round((float)$qty * 100);
}

/** Hundredths back to the "12.50" form bound to DECIMAL columns. */
function hundredths_to_qty(int $hundredths): string
{
    return number_format($hundredths / 100, 2, '.', '');
}

/**
 * Quantity as people read it: "2.5 kg" / "0.75 kg" for Per Kilo, "4" otherwise.
 * $withUnit=false drops the " kg" suffix.
 */
function format_unit_quantity($qty, $unit, bool $withUnit = true): string
{
    if (!unit_is_per_kilo($unit)) {
        return (string)(int)round((float)$qty);
    }
    $text = rtrim(rtrim(number_format((float)$qty, 2, '.', ''), '0'), '.');
    return $withUnit ? $text . ' kg' : $text;
}

/** "4 units" / "1 unit" / "2.5 kg", for sentences such as low-stock alerts. */
function format_unit_amount($qty, $unit): string
{
    if (unit_is_per_kilo($unit)) return format_unit_quantity($qty, $unit);
    $n = (int)round((float)$qty);
    return $n . ' unit' . ($n === 1 ? '' : 's');
}
