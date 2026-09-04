<?php

namespace App\Domain\Documents\DTOs;

final class DocumentAuditContext
{
    public function __construct(
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly string $source = 'internal',
        public readonly ?string $requestId = null,
    ) {}

    public function userAgentForStorage(): string
    {
        return mb_substr((string) ($this->userAgent ?? ''), 0, 2000);
    }
}
