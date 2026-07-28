<?php

declare(strict_types=1);

use App\Support\SequentialNumber;
use Illuminate\Database\QueryException;

/**
 * The retry that turns a concurrent number collision into a fresh number.
 *
 * Real concurrency is not reproducible in a unit test, so the collision is
 * simulated: the closure throws the QueryException MySQL would throw on a
 * duplicate key, and the test asserts what retryOnCollision does with it.
 */

/** A QueryException shaped like MySQL's duplicate-key error on $key. */
function duplicateKeyException(string $key): QueryException
{
    $previous = new PDOException(
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'X-000005' for key '{$key}'",
    );

    return new QueryException('tenant', 'insert into t (...) values (...)', [], $previous);
}

/** A QueryException that is not a duplicate key at all. */
function otherQueryException(): QueryException
{
    $previous = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');

    return new QueryException('tenant', 'insert into t (...) values (...)', [], $previous);
}

it('returns the first attempt when there is no collision', function () {
    $calls = 0;

    $result = SequentialNumber::retryOnCollision(function () use (&$calls) {
        $calls++;

        return 'ok';
    }, 'customer_number');

    expect($result)->toBe('ok')->and($calls)->toBe(1);
});

it('retries past a collision and succeeds', function () {
    $calls = 0;

    $result = SequentialNumber::retryOnCollision(function () use (&$calls) {
        $calls++;

        // Collide once, then succeed — what the losing request sees.
        if ($calls === 1) {
            throw duplicateKeyException('customers_customer_number_unique');
        }

        return 'C-000006';
    }, 'customer_number');

    expect($result)->toBe('C-000006')->and($calls)->toBe(2);
});

it('gives up after the attempt limit and rethrows the collision', function () {
    $calls = 0;

    // Full closure, not an arrow fn: the arrow fn would capture $calls by value,
    // so the inner &$calls would bind to a copy and the count never move.
    expect(function () use (&$calls) {
        SequentialNumber::retryOnCollision(function () use (&$calls) {
            $calls++;

            throw duplicateKeyException('customers_customer_number_unique');
        }, 'customer_number', attempts: 3);
    })->toThrow(QueryException::class);

    // Tried exactly the ceiling, no more.
    expect($calls)->toBe(3);
});

it('does not retry a duplicate on a different column', function () {
    $calls = 0;

    // A duplicate email is a real validation problem, not a number race — it
    // must surface at once rather than being retried into a different failure.
    expect(function () use (&$calls) {
        SequentialNumber::retryOnCollision(function () use (&$calls) {
            $calls++;

            throw duplicateKeyException('customers_email_unique');
        }, 'customer_number');
    })->toThrow(QueryException::class);

    expect($calls)->toBe(1);
});

it('does not swallow an unrelated database error', function () {
    $calls = 0;

    expect(function () use (&$calls) {
        SequentialNumber::retryOnCollision(function () use (&$calls) {
            $calls++;

            throw otherQueryException();
        }, 'customer_number');
    })->toThrow(QueryException::class);

    // A lock-timeout is not a number collision; no retry.
    expect($calls)->toBe(1);
});
