<?php

declare(strict_types=1);

namespace S7codedesign\DExpress\Infrastructure\Api\Exceptions;

use S7codedesign\DExpress\Domain\Exceptions\DExpressException;

class ApiException extends DExpressException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
    ) {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
