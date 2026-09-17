<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ArtistClaimApplicationIsolationTest extends TestCase
{
    public function test_review_transaction_keeps_artist_and_claim_writes_application_scoped(): void
    {
        $source = file_get_contents(base_path('app/Domain/People/Http/Controllers/ArtistClaimController.php'));

        $this->assertIsString($source);

        $transactionStart = strpos($source, 'DB::transaction(function () use');
        $transactionEnd = strpos($source, '$claim->refresh();', $transactionStart ?: 0);

        $this->assertNotFalse($transactionStart, 'Artist claim review transaction was not found.');
        $this->assertNotFalse($transactionEnd, 'Artist claim review transaction boundary was not found.');

        $transaction = substr($source, $transactionStart, $transactionEnd - $transactionStart);

        $this->assertStringContainsString(
            "Artist::query()->where('app_id', $appId)->lockForUpdate()->findOrFail($claim->artist_id)",
            $transaction,
            'The locked artist reload must remain scoped to the active application.',
        );

        $bulkReject = strstr($transaction, 'ArtistClaim::query()');
        $this->assertIsString($bulkReject, 'Pending claim bulk rejection was not found.');
        $this->assertStringContainsString(
            "->where('app_id', $appId)",
            $bulkReject,
            'Bulk rejection of competing claims must not cross application boundaries.',
        );
    }
}
