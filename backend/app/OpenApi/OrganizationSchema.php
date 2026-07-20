<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Organization',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', example: 'Acme Inc'),
        new OA\Property(property: 'slug', type: 'string', example: 'acme-inc'),
        new OA\Property(property: 'timezone', type: 'string', example: 'UTC'),
        new OA\Property(property: 'currency', type: 'string', example: 'USD'),
        new OA\Property(property: 'status', type: 'string', enum: ['trial', 'active', 'suspended', 'cancelled']),
        new OA\Property(property: 'on_trial', type: 'boolean'),
    ],
)]
class OrganizationSchema {}
