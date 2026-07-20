<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Parameter(
    parameter: 'OrganizationHeader',
    name: 'X-Organization',
    in: 'header',
    required: true,
    description: 'Slug or UUID of the organization to act in.',
    schema: new OA\Schema(type: 'string', example: 'acme-inc'),
)]
class OrganizationHeaderParameter {}
