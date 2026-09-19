<?php

declare(strict_types=1);

namespace ProxyMailer;

final class SendResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly ?string $providerMessageId,
        public readonly int $attachmentCount,
        public readonly int $httpStatus,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromResponse(array $data, int $httpStatus): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['status'] ?? 'unknown'),
            isset($data['provider_message_id']) ? (string) $data['provider_message_id'] : null,
            (int) ($data['attachment_count'] ?? 0),
            $httpStatus,
        );
    }

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
