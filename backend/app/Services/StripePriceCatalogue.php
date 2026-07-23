<?php

declare(strict_types=1);

namespace App\Services;

use Laravel\Cashier\Cashier;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;

/**
 * Reads prices out of Stripe.
 *
 * Stripe is the source of truth for money (see the plans migration): the
 * `*_amount` columns are a display copy, and a copy drifts. This is the one
 * seam that fetches the real thing, so the validation rule and the amount
 * auto-fill work from a single lookup rather than two.
 *
 * Bound as a singleton so a request that validates a price id and then reads
 * its amount makes one API call, not two. Tests swap the whole class out.
 */
class StripePriceCatalogue
{
    /**
     * Prices already fetched this request. null means "asked for, not found",
     * which is cached too — a bad id should not be re-fetched.
     *
     * @var array<string, Price|null>
     */
    private array $memo = [];

    /**
     * Whether Stripe can be reached at all.
     *
     * With no secret key there is nothing to verify against. Verification is
     * then skipped rather than failing, so a fresh install (or a local machine
     * with no Stripe credentials) can still edit the plan catalogue.
     */
    public function configured(): bool
    {
        return filled(config('cashier.secret'));
    }

    /**
     * Fetch a price, or null when Stripe has no such price.
     *
     * @throws RuntimeException when Stripe cannot be reached — the caller must
     *                          decide whether that blocks the operation, rather
     *                          than a network blip silently reading as "valid".
     */
    public function retrieve(string $priceId): ?Price
    {
        if (array_key_exists($priceId, $this->memo)) {
            return $this->memo[$priceId];
        }

        try {
            return $this->memo[$priceId] = Cashier::stripe()->prices->retrieve($priceId);
        } catch (InvalidRequestException $e) {
            // Stripe answered: there is no such price (usually a typo, or a
            // live-mode id being used with test-mode keys).
            if ($e->getHttpStatus() === 404) {
                return $this->memo[$priceId] = null;
            }

            throw new RuntimeException($e->getMessage(), previous: $e);
        } catch (ApiErrorException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }
    }

    /**
     * The recurring interval a price bills on ('month', 'year', …), or null if
     * the price is one-off.
     */
    public function intervalOf(Price $price): ?string
    {
        return $price->recurring?->interval;
    }

    /**
     * Every active recurring price in the account, product expanded.
     *
     * The product comes back expanded because the importer matches on it — one
     * paginated call rather than a product lookup per price.
     *
     * @return array<int, Price>
     *
     * @throws RuntimeException when Stripe cannot be reached.
     */
    public function activeRecurringPrices(): array
    {
        try {
            $prices = [];

            $page = Cashier::stripe()->prices->all([
                'active' => true,
                'type' => 'recurring',
                'limit' => 100,
                'expand' => ['data.product'],
            ]);

            foreach ($page->autoPagingIterator() as $price) {
                $prices[] = $price;
                // Feed the memo, so a later retrieve() of the same id is free.
                $this->memo[$price->id] = $price;
            }

            return $prices;
        } catch (ApiErrorException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }
    }
}
