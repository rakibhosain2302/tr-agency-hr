<?php

namespace Nit\AgraniHr\Support;

use Illuminate\Support\Facades\DB;

/**
 * "PR-00001", "ADV-00001" …: the next number after the highest one with the prefix. The rows are locked for
 * the moment, so two documents created at once never get the same number (call inside a transaction).
 */
final class SequenceNumber
{
    public static function next(string $table, string $column, string $prefix, int $padding = 5): string
    {
        $codes = DB::table($table)->lockForUpdate()->where($column, 'like', addcslashes($prefix, '%_\\').'%')->pluck($column);

        $highest = 0;
        foreach ($codes as $code) {
            $number = substr((string) $code, strlen($prefix));
            if (ctype_digit($number)) {
                $highest = max($highest, (int) $number);
            }
        }

        return $prefix.str_pad((string) ($highest + 1), $padding, '0', STR_PAD_LEFT);
    }
}
