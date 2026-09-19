<?php

declare(strict_types=1);

namespace ProxyMailer\Exception;

final class ValidationException extends ProxyMailerException
{
    /** @param  array<string, list<string>>  $errors */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        int $statusCode = 422,
    ) {
        parent::__construct($message, $statusCode, ['errors' => $errors]);
    }
}
