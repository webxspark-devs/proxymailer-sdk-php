<?php

declare(strict_types=1);

namespace ProxyMailer\Exception;

class ProxyMailerException extends \RuntimeException
{
    /** @param  array<string,mixed>  $context */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
