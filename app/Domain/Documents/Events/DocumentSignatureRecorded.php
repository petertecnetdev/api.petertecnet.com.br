<?php

namespace App\Domain\Documents\Events;

final readonly class DocumentSignatureRecorded
{
    public function __construct(
        public int $documentId,
        public int $documentPartyId,
    ) {}
}
