<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'email', type: 'string'),
        new OA\Property(property: 'is_super_admin', type: 'boolean'),
        new OA\Property(property: 'email_verified', type: 'boolean'),
        new OA\Property(property: 'two_factor_enabled', type: 'boolean'),
    ],
)]
class UserSchema {}
