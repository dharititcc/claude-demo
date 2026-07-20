<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Expands recurring events into concrete occurrences within a date window.
 *
 * Occurrences are computed on demand rather than stored: a "forever" series has
 * no finite set of rows, and materialising one would make "edit all future" mean
 * rewriting thousands of records. Only *exceptions* — a single occurrence moved,
 * edited, or cancelled — are stored, and this class weaves them in.
 *
 * The window is bounded and each series is capped, so a malformed rule cannot
 * spin forever.
 */
class RecurrenceExpander
{
    /** Hard ceiling on occurrences generated per series in one window. */
    private const MAX_OCCURRENCES = 1000;

    /**
     * Expand a set of series and one-off events into occurrences overlapping
     * [$from, $to].
     *
     * @param Collection<int, Event> $events Series masters and one-offs (not exception rows).
     * @param Collection<int, Event> $exceptions Materialised overrides, keyed by parent+original time.
     * @return array<int, array<string, mixed>>
     */
    public function expand(Collection $events, Collection $exceptions, Carbon $from, Carbon $to): array
    {
        // Index exceptions by "parent id @ original start" for O(1) lookup while
        // walking a series.
        $overrides = $exceptions->keyBy(
            fn (Event $e) => $e->parent_id.'@'.$e->original_starts_at?->toIso8601String(),
        );

        $occurrences = [];

        foreach ($events as $event) {
            if ($event->recurrence_frequency === null) {
                // One-off: include it if it touches the window at all.
                if ($this->overlaps($event->starts_at, $event->effectiveEnd(), $from, $to)) {
                    $occurrences[] = $this->materialise($event, $event->starts_at);
                }

                continue;
            }

            foreach ($this->occurrenceStarts($event, $from, $to) as $start) {
                $key = $event->id.'@'.$start->toIso8601String();
                $override = $overrides->get($key);

                if ($override !== null) {
                    // A cancelled occurrence leaves a gap; an edited one appears
                    // at its new time (and is emitted from the exceptions pass,
                    // so it is skipped here to avoid duplication).
                    continue;
                }

                $occurrences[] = $this->materialise($event, $start);
            }
        }

        // Non-cancelled exception rows are real events in their own right.
        foreach ($exceptions as $exception) {
            if (! $exception->is_cancelled && $this->overlaps($exception->starts_at, $exception->effectiveEnd(), $from, $to)) {
                $occurrences[] = $this->materialise($exception, $exception->starts_at, isException: true);
            }
        }

        usort($occurrences, fn ($a, $b) => $a['starts_at'] <=> $b['starts_at']);

        return $occurrences;
    }

    /**
     * The start times of a series that fall within the window.
     *
     * @return array<int, Carbon>
     */
    private function occurrenceStarts(Event $event, Carbon $from, Carbon $to): array
    {
        $starts = [];
        $cursor = $event->starts_at->copy();
        $duration = $event->durationSeconds();
        $interval = max(1, $event->recurrence_interval);
        $byDay = $event->recurrence_by_day ?? [];

        $seriesEnd = $event->recurrence_until;
        $countLimit = $event->recurrence_count;
        $generated = 0;
        $emitted = 0;

        while ($generated < self::MAX_OCCURRENCES) {
            // Stop conditions: past the requested window, past the series end, or
            // the "after N occurrences" limit.
            if ($cursor->greaterThan($to)) {
                break;
            }
            if ($seriesEnd !== null && $cursor->greaterThan($seriesEnd)) {
                break;
            }
            if ($countLimit !== null && $emitted >= $countLimit) {
                break;
            }

            $matchesDay = $byDay === [] || in_array($cursor->format('D'), $this->mapDays($byDay), true);

            if ($matchesDay) {
                $end = $cursor->copy()->addSeconds($duration);
                if ($this->overlaps($cursor, $end, $from, $to)) {
                    $starts[] = $cursor->copy();
                }
                $emitted++;
            }

            $cursor = $this->advance($cursor, $event->recurrence_frequency, $interval, $byDay);
            $generated++;
        }

        return $starts;
    }

    /**
     * Move the cursor to the next candidate occurrence.
     *
     * Weekly-by-day advances a day at a time so it can land on each selected
     * weekday; the others jump by their unit.
     *
     * @param array<int, string> $byDay
     */
    private function advance(Carbon $cursor, string $frequency, int $interval, array $byDay): Carbon
    {
        return match ($frequency) {
            'daily' => $cursor->copy()->addDays($interval),
            'weekly' => $byDay === []
                ? $cursor->copy()->addWeeks($interval)
                : $cursor->copy()->addDay(),
            'monthly' => $cursor->copy()->addMonthsNoOverflow($interval),
            'yearly' => $cursor->copy()->addYears($interval),
            default => $cursor->copy()->addDays($interval),
        };
    }

    /**
     * Map RRULE two-letter day codes to Carbon's three-letter format() codes.
     *
     * @param array<int, string> $byDay
     * @return array<int, string>
     */
    private function mapDays(array $byDay): array
    {
        $map = ['MO' => 'Mon', 'TU' => 'Tue', 'WE' => 'Wed', 'TH' => 'Thu', 'FR' => 'Fri', 'SA' => 'Sat', 'SU' => 'Sun'];

        return array_values(array_filter(array_map(fn ($d) => $map[$d] ?? null, $byDay)));
    }

    private function overlaps(Carbon $startA, Carbon $endA, Carbon $startB, Carbon $endB): bool
    {
        return $startA->lessThanOrEqualTo($endB) && $endA->greaterThanOrEqualTo($startB);
    }

    /**
     * @return array<string, mixed>
     */
    private function materialise(Event $event, Carbon $start, bool $isException = false): array
    {
        $end = $start->copy()->addSeconds($event->durationSeconds());

        return [
            'event_id' => $event->id,
            'series_id' => $isException ? $event->parent_id : ($event->recurrence_frequency ? $event->id : null),
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'type' => $event->type,
            'color' => $event->color,
            'all_day' => $event->all_day,
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $end->toIso8601String(),
            'is_recurring' => $event->recurrence_frequency !== null || $isException,
            'is_exception' => $isException,
        ];
    }
}
