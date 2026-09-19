<?php

declare(strict_types=1);

namespace ProxyMailer\Http;

final class RetryPolicy
{
    public function __construct(
        public readonly int $maxRetries = 3,
        public readonly float $baseDelaySeconds = 0.25,
        public readonly float $maxDelaySeconds = 8.0,
    ) {}

    public function shouldRetry(int $attempt, int $statusCode): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        return in_array($statusCode, [408, 425, 429, 500, 502, 503, 504], true);
    }

    public function delaySeconds(int $attempt): float
    {
        $exp = $this->baseDelaySeconds * (2 ** max(0, $attempt));
        $jitter = $exp * (mt_rand(0, 1000) / 1000) * 0.25;

        return min($this->maxDelaySeconds, $exp + $jitter);
    }
}
