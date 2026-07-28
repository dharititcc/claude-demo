<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Generates the next prefixed, zero-padded number in a sequence (C-000001,
 * INV-000001, …).
 *
 * Extracted because customer numbers and invoice numbers were generating with
 * the same algorithm in two places, and the details that make it correct — sort
 * by length before value so 7-digit numbers outrank 6-digit ones, strip the
 * prefix to read the integer, include soft-deleted rows so a number is never
 * reused — are exactly the kind that drift apart when copied. One
 * implementation, one place to fix.
 */
class SequentialNumber
{
    /**
     * The next number for a sequence.
     *
     * The caller passes an already-scoped query, so it decides what counts
     * toward "highest" (typically withTrashed(), so a deleted row's number is
     * not handed out again).
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $scope A query already narrowed to the sequence.
     * @param string $column The number column. A trusted identifier from the
     *                       caller — never user input — because it is
     *                       interpolated into the ORDER BY.
     * @param string $prefix Prepended to the padded number (e.g. 'C-').
     * @param int $pad Minimum digit width.
     */
    public static function next(Builder $scope, string $column, string $prefix, int $pad = 6): string
    {
        $highest = $scope
            ->whereNotNull($column)
            // Length first, then value: '1000000' is longer than '999999', so it
            // must sort higher even though it is lexically smaller.
            ->orderByRaw("LENGTH({$column}) DESC, {$column} DESC")
            ->value($column);

        // Strip the prefix to read the integer, then advance. ltrim guards the
        // all-zeros case ('000000' → '' → 0).
        $next = $highest === null
            ? 1
            : ((int) ltrim((string) preg_replace('/\D/', '', (string) $highest), '0')) + 1;

        return $prefix.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }
}
