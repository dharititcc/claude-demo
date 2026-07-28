<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Throwable;

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

    /**
     * Run an insert that assigns a generated number, retrying if a concurrent
     * insert took the same one first.
     *
     * next() derives the number from the current maximum, so two requests that
     * read the same maximum at the same instant generate the same number — one
     * inserts, the other hits the unique index. The index is what guarantees
     * uniqueness; this is what turns the loser's collision into a fresh number
     * instead of a 500. Each retry re-runs the caller's insert, which regenerates
     * the number against the now-higher maximum.
     *
     * Only a duplicate on the number column is retried. Any other failure — a
     * different unique constraint, a real error — is rethrown at once. MySQL
     * rolls back just the failed statement on a duplicate key, not the whole
     * transaction, so retrying inside one is safe.
     *
     * @template TValue
     *
     * @param callable(): TValue $insert Creates the row; must regenerate the number each call.
     * @param string $numberColumn The number column, so a collision elsewhere is not swallowed.
     * @return TValue
     *
     * @throws QueryException when the collision persists past $attempts, or the
     *                        failure is not a number collision.
     */
    public static function retryOnCollision(callable $insert, string $numberColumn, int $attempts = 3)
    {
        $attempts = max(1, $attempts);
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $insert();
            } catch (QueryException $e) {
                if (! self::isCollisionOn($e, $numberColumn)) {
                    throw $e;
                }

                $last = $e;
            }
        }

        // Exhausted the retries on a genuine run of collisions. The loop ran at
        // least once and every path that reaches here set $last.
        throw $last;
    }

    /**
     * Whether the failure is a duplicate-key violation on the number column.
     *
     * Matches on the driver's message rather than the SQLSTATE alone: the index
     * name embeds the column (…_number_unique), which is what distinguishes a
     * number collision from a duplicate on some other unique column that must
     * not be retried.
     */
    private static function isCollisionOn(Throwable $e, string $numberColumn): bool
    {
        $message = $e->getMessage();

        $isDuplicate = $e->getCode() === '23000' || str_contains($message, 'Duplicate entry');

        return $isDuplicate && str_contains($message, $numberColumn);
    }
}
