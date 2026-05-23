<?php

declare(strict_types=1);

namespace Silo\WhmcsModule;

final class SiloApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $httpStatus = 0,
        private ?array $body = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function body(): ?array
    {
        return $this->body;
    }
}
