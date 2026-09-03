<?php

namespace App\Domain\Identity\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CompromisedPasswordService
{
    public function occurrences(string $password): ?int
    {
        if (! config('identity.password.compromised_check', true)) {
            return 0;
        }

        $sha1 = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $response = Http::withHeaders([
                'Add-Padding' => 'true',
                'User-Agent' => 'PeterTecnet-Identity/1.0',
            ])->timeout(4)->retry(1, 150)->get('https://api.pwnedpasswords.com/range/' . $prefix);

            if (! $response->successful()) {
                return null;
            }

            foreach (preg_split('/\r\n|\r|\n/', $response->body()) ?: [] as $line) {
                [$candidate, $count] = array_pad(explode(':', trim($line), 2), 2, null);
                if ($candidate && hash_equals($suffix, strtoupper($candidate))) {
                    return max((int) $count, 0);
                }
            }

            return 0;
        } catch (\Throwable $e) {
            // Fail open: availability of an external breach corpus must never make
            // account creation or recovery impossible. Local strength policy still applies.
            Log::notice('Verificação de senha comprometida temporariamente indisponível.', [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function isCompromised(string $password): bool
    {
        $occurrences = $this->occurrences($password);
        if ($occurrences === null) {
            return false;
        }

        return $occurrences >= max((int) config('identity.password.compromised_threshold', 1), 1);
    }
}
