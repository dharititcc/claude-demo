<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Customer',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Wayne Enterprises'),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'company', type: 'string', nullable: true),
        new OA\Property(property: 'website', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['lead', 'active', 'inactive', 'churned']),
        new OA\Property(property: 'lifetime_value', type: 'number', format: 'float'),
        new OA\Property(property: 'owner_id', type: 'integer', nullable: true),
        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(ref: '#/components/schemas/Tag')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
class CustomerSchema {}
