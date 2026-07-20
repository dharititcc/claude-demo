<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Tag',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string', example: 'vip'),
        new OA\Property(property: 'slug', type: 'string', example: 'vip'),
        new OA\Property(property: 'color', type: 'string', example: '#6366f1'),
    ],
)]
class TagSchema {}
