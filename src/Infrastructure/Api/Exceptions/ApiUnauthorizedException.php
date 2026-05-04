<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Api\Exceptions;

final class ApiUnauthorizedException extends ApiException
{
    public function __construct()
    {
        parent::__construct(
            'D Express API: neispravno korisničko ime ili lozinka (HTTP 401).',
            401,
        );
    }
}
