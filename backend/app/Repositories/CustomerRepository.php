<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Customer;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query construction for customers. Keeping it here means the controller never
 * builds queries, and the sort/filter allow-lists live next to the schema they
 * describe.
 *
 * No tenant scoping appears in this class: the model already resolves to the
 * active tenant's database.
 */
class CustomerRepository
{
    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * Cursor pagination for large exports/infinite scroll: it stays O(1) as the
     * offset grows, at the cost of not knowing the total count.
     *
     * @param array<string, mixed> $filters
     * @return CursorPaginator<int, Customer>
     */
    public function cursorPaginate(array $filters, int $perPage = 15): CursorPaginator
    {
        return $this->query($filters)->cursorPaginate($perPage)->withQueryString();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Builder<Customer>
     */
    public function query(array $filters): Builder
    {
        $query = Customer::query()
            // Eager load to keep the list view at a fixed query count; lazy
            // loading is disabled outside production and would throw here.
            ->with('tags');

        $query->search($filters['q'] ?? null);

        if (! empty($filters['status'])) {
            $statuses = (array) $filters['status'];
            $query->whereIn('status', $statuses);
        }

        if (! empty($filters['owner_id'])) {
            $query->where('owner_id', $filters['owner_id']);
        }

        if (! empty($filters['tag'])) {
            $slug = $filters['tag'];
            $query->whereHas('tags', fn (Builder $q) => $q->where('slug', $slug));
        }

        if (! empty($filters['created_after'])) {
            $query->whereDate('created_at', '>=', $filters['created_after']);
        }

        if (! empty($filters['created_before'])) {
            $query->whereDate('created_at', '<=', $filters['created_before']);
        }

        if (! empty($filters['trashed'])) {
            $query->onlyTrashed();
        }

        return $this->applySort($query, $filters);
    }

    /**
     * Sorting is restricted to an allow-list so a crafted `sort` parameter
     * cannot reference arbitrary columns or inject SQL.
     *
     * @param Builder<Customer> $query
     * @param array<string, mixed> $filters
     * @return Builder<Customer>
     */
    private function applySort(Builder $query, array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'created_at';
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sort, Customer::SORTABLE, true)) {
            $sort = 'created_at';
        }

        return $query->orderBy($sort, $direction)
            // Deterministic tiebreak: without it, rows sharing a sort value can
            // reorder between pages and appear duplicated or missing.
            ->orderBy('id', 'desc');
    }

    /**
     * Counts per status for the dashboard, in a single grouped query.
     *
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        return Customer::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
