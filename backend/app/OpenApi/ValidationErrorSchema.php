<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * OpenAPI component schema. Never instantiated — a swagger-php annotation holder.
 * Each reusable schema needs its own class: a class-level #[OA\Schema] means
 * "this class is the schema", so stacking several on one class registers none.
 */
#[OA\Schema(
    schema: 'ValidationError',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
        ),
    ],
)]
class ValidationErrorSchema {}
