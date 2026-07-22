<?php

declare(strict_types=1);

/*
 * The OpenAPI docs (Swagger UI at /api/documentation, raw spec at /docs) describe
 * the entire API surface, including the Super Admin and impersonation endpoints.
 * The vendor default serves them with no middleware; config/l5-swagger.php gates
 * them behind auth:sanctum + super-admin outside local, so an anonymous visitor
 * cannot enumerate the API. The test environment is non-local, so the gate is on.
 */

it('does not expose the raw OpenAPI spec to anonymous users', function () {
    $this->getJson('/docs')->assertStatus(401);
});

it('does not expose the Swagger UI to anonymous users', function () {
    $this->getJson('/api/documentation')->assertStatus(401);
});
