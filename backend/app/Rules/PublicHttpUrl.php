<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only a public http(s) URL and rejects anything that resolves to an
 * internal address.
 *
 * This guards outbound requests (webhook delivery) against SSRF. Without it a
 * tenant admin could point a webhook at `http://169.254.169.254/…` (cloud
 * metadata), `http://127.0.0.1:…`, or an internal service, and read the delivery
 * log's status/error back as a network-reachability oracle.
 *
 * This is validation-time defence: it blocks obviously-internal destinations and
 * non-http schemes when the endpoint is created or updated. The delivery job adds
 * the runtime half — it refuses redirects and re-checks the socket IP at connect
 * time, closing the DNS-rebinding gap between validation and delivery.
 */
class PublicHttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            $fail('The :attribute must be a valid http or https URL.');

            return;
        }

        // Strip IPv6 brackets so an `[::1]` literal is checked as the address it is.
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if ($host === '') {
            $fail('The :attribute must include a host.');

            return;
        }

        // An IP literal is checked directly; a hostname is resolved to every
        // address it points at, so a name that maps to an internal IP is caught.
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);

        if ($ips === []) {
            $fail('The :attribute host could not be resolved.');

            return;
        }

        foreach ($ips as $ip) {
            // NO_PRIV_RANGE blocks 10/8, 172.16/12, 192.168/16, fc00::/7;
            // NO_RES_RANGE blocks loopback, link-local (169.254/16, incl. the
            // cloud metadata IP), and other reserved ranges.
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $fail('The :attribute must not resolve to a private or reserved address.');

                return;
            }
        }
    }
}
