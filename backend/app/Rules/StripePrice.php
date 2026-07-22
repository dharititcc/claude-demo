<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\StripePriceCatalogue;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use RuntimeException;

/**
 * A Stripe price id that actually exists and matches the slot it is being put in.
 *
 * Without this, a typo or a mismatched price is only discovered at checkout —
 * or worse, never: a plan advertising $29 whose Stripe price charges $39 looks
 * completely fine until a customer's card is billed. The checks are therefore
 * about agreement, not just existence:
 *
 *   - the price exists, in the same Stripe mode as the configured keys
 *   - it is not archived
 *   - it is recurring, not one-off
 *   - it bills on the interval of the field it was typed into
 *   - it is in the plan's currency
 *
 * Network I/O inside a rule follows the precedent set by PublicHttpUrl, which
 * resolves DNS to block SSRF. Verification is skipped entirely when no Stripe
 * secret is configured — see StripePriceCatalogue::configured().
 */
class StripePrice implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param string $interval The interval this field represents: 'month' or 'year'.
     * @param string|null $fallbackCurrency The plan's stored currency, used when a
     *                                      partial edit does not resend it.
     */
    public function __construct(
        private readonly string $interval,
        private readonly ?string $fallbackCurrency = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // nullable handles absence
        }

        $catalogue = app(StripePriceCatalogue::class);

        if (! $catalogue->configured()) {
            return; // nothing to verify against
        }

        try {
            $price = $catalogue->retrieve($value);
        } catch (RuntimeException) {
            // Fail closed. Accepting an unverified price id is the exact bug
            // this rule exists to prevent, and the admin can retry.
            $fail('Could not reach Stripe to verify this price id. Try again in a moment.');

            return;
        }

        if ($price === null) {
            $fail('No such price in Stripe. Check the id, and that it belongs to the same mode (test or live) as the configured keys.');

            return;
        }

        if ($price->active === false) {
            $fail('That Stripe price is archived and cannot be sold.');

            return;
        }

        $priceInterval = $price->recurring?->interval;

        if ($priceInterval === null) {
            $fail('That Stripe price is one-off. A plan needs a recurring price.');

            return;
        }

        if ($priceInterval !== $this->interval) {
            $fail("That price bills every {$priceInterval}, but this is the ".$this->humanInterval().' price.');

            return;
        }

        // "Every 3 months" is a month interval too, but it is not a monthly plan.
        if (($price->recurring->interval_count ?? 1) !== 1) {
            $fail("That price bills every {$price->recurring->interval_count} {$priceInterval}s, which does not match this field.");

            return;
        }

        $expectedCurrency = $this->data['currency'] ?? $this->fallbackCurrency;

        if (filled($expectedCurrency) && strcasecmp((string) $price->currency, (string) $expectedCurrency) !== 0) {
            $fail('That price is in '.strtoupper((string) $price->currency).', but this plan is priced in '.strtoupper((string) $expectedCurrency).'.');
        }
    }

    private function humanInterval(): string
    {
        return $this->interval === 'year' ? 'annual' : 'monthly';
    }
}
