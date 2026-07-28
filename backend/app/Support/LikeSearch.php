<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds a safe LIKE pattern from a user's search term.
 *
 * The same escaping was inlined in every model's search scope. It is
 * security-relevant — an unescaped `%` or `_` turns a search into a
 * match-everything wildcard, and a `\` can escape the escaping — so having one
 * implementation means a correction applies everywhere at once rather than to
 * whichever copies someone remembers.
 */
class LikeSearch
{
    /**
     * Wrap a term as a contains-pattern with its wildcards neutralised.
     *
     * Backslash is escaped first, or it would double-escape the % and _ that
     * follow.
     */
    public static function contains(string $term): string
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);

        return "%{$escaped}%";
    }
}
