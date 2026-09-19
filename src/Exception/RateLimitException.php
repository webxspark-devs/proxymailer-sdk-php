<?php

declare(strict_types=1);

namespace ProxyMailer\Exception;

final class RateLimitException extends ProxyMailerException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        int $statusCode = 429,
    ) {
        parent::__construct($message, $statusCode, [
            'retry_after' => $retryAfterSeconds,
        ]);
    }
}
