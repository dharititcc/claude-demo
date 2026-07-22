<?php

declare(strict_types=1);

/*
 * Webhook endpoints accept a user-supplied delivery URL. Without SSRF guarding,
 * a tenant admin could point one at an internal host (cloud metadata, loopback,
 * a private service) and read the delivery log back as a reachability oracle.
 * PublicHttpUrl blocks internal/reserved hosts and non-http schemes at the edge.
 */

it('rejects a webhook URL that resolves to an internal or non-http address', function (string $url) {
    [, $tenant, $token] = registerUser('webhook-ssrf@example.test', 'Webhook SSRF Org');

    apiAs($token, $tenant)->postJson('/api/v1/webhooks', [
        'url' => $url,
        'events' => ['customer.created'],
    ])->assertStatus(422)->assertJsonValidationErrors('url');
})->with([
    'loopback' => 'http://127.0.0.1/hook',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/',
    'private 10/8' => 'http://10.0.0.5:6379/',
    'private 192.168' => 'http://192.168.1.10/hook',
    'ipv6 loopback' => 'http://[::1]/hook',
    'file scheme' => 'file:///etc/passwd',
    'ftp scheme' => 'ftp://example.com/x',
    'not a url' => 'definitely-not-a-url',
]);

it('accepts a public webhook URL', function () {
    [, $tenant, $token] = registerUser('webhook-ok@example.test', 'Webhook OK Org');

    // A public IP literal, so the rule needs no DNS resolution under test.
    apiAs($token, $tenant)->postJson('/api/v1/webhooks', [
        'url' => 'https://93.184.216.34/webhook',
        'events' => ['customer.created'],
    ])->assertCreated()->assertJsonPath('data.url', 'https://93.184.216.34/webhook');
});

it('blocks changing an existing webhook to an internal URL', function () {
    [, $tenant, $token] = registerUser('webhook-update@example.test', 'Webhook Update Org');

    $id = apiAs($token, $tenant)->postJson('/api/v1/webhooks', [
        'url' => 'https://93.184.216.34/webhook',
        'events' => ['customer.created'],
    ])->assertCreated()->json('data.id');

    apiAs($token, $tenant)->putJson("/api/v1/webhooks/{$id}", [
        'url' => 'http://169.254.169.254/latest/meta-data/',
    ])->assertStatus(422)->assertJsonValidationErrors('url');
});
