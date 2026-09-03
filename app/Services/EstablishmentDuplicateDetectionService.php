<?php

namespace App\Services;

use App\Models\Establishment;
use App\Support\TaxIdentifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EstablishmentDuplicateDetectionService
{
    public function detect(array $input, ?int $excludeId = null, int $limit = 8): Collection
    {
        $countryCode = strtoupper(trim((string) ($input['country_code'] ?? 'BR')));
        $taxId = TaxIdentifier::normalizeForCountry($input['tax_id'] ?? $input['cnpj'] ?? null, $countryCode);
        $phone = $this->digits($input['phone'] ?? null);
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $name = $this->normalizeText($input['name'] ?? $input['fantasy'] ?? null);

        $query = Establishment::query()
            ->with(['user:id,first_name,last_name,email', 'app:id,name,slug'])
            ->where(function ($candidate) use ($taxId, $phone, $email, $name) {
                $hasFilter = false;

                if ($taxId) {
                    $hasFilter = true;
                    $candidate->orWhere('tax_id', $taxId)->orWhere('cnpj', $taxId);
                }
                if ($email !== '') {
                    $hasFilter = true;
                    $candidate->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                if (strlen($phone) >= 8) {
                    $hasFilter = true;
                    $candidate->orWhere('phone', 'like', '%' . substr($phone, -8) . '%');
                }
                if (strlen($name) >= 4) {
                    $hasFilter = true;
                    $needle = collect(explode(' ', $name))->filter(fn ($part) => strlen($part) >= 4)->first();
                    if ($needle) {
                        $candidate->orWhere('name', 'like', '%' . $needle . '%')
                            ->orWhere('fantasy', 'like', '%' . $needle . '%');
                    }
                }

                if (! $hasFilter) {
                    $candidate->whereRaw('1 = 0');
                }
            });

        if ($excludeId) $query->whereKeyNot($excludeId);

        return $query->latest('id')->limit(100)->get()
            ->map(function (Establishment $candidate) use ($taxId, $phone, $email, $name) {
                $reasons = [];
                $score = 0;
                $candidateTaxId = TaxIdentifier::normalizeForCountry($candidate->tax_id ?: $candidate->cnpj, $candidate->country_code ?: 'BR');
                $candidatePhone = $this->digits($candidate->phone);
                $candidateEmail = mb_strtolower(trim((string) $candidate->email));
                $candidateName = $this->normalizeText($candidate->fantasy ?: $candidate->name);

                if ($taxId && $candidateTaxId === $taxId) {
                    $score = 100;
                    $reasons[] = 'same_tax_id';
                }
                if ($email !== '' && $candidateEmail === $email) {
                    $score = max($score, 80);
                    $reasons[] = 'same_email';
                }
                if (strlen($phone) >= 8 && strlen($candidatePhone) >= 8 && substr($candidatePhone, -8) === substr($phone, -8)) {
                    $score = max($score, 78);
                    $reasons[] = 'same_phone';
                }

                $similarity = $this->nameSimilarity($name, $candidateName);
                if ($similarity >= 0.72) {
                    $score = max($score, (int) round(55 + ($similarity * 30)));
                    $reasons[] = 'similar_name';
                }

                if (count($reasons) >= 2 && $score < 100) $score = min(99, $score + 10);

                return [
                    'id' => $candidate->id,
                    'score' => $score,
                    'reasons' => array_values(array_unique($reasons)),
                    'establishment' => $candidate,
                ];
            })
            ->filter(fn ($match) => $match['score'] >= 55)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?: '';
    }

    private function normalizeText(?string $value): string
    {
        return Str::of((string) $value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function nameSimilarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') return 0;
        if ($left === $right) return 1;
        if (str_contains($left, $right) || str_contains($right, $left)) return 0.92;

        $a = collect(explode(' ', $left))->filter(fn ($token) => strlen($token) > 2)->unique();
        $b = collect(explode(' ', $right))->filter(fn ($token) => strlen($token) > 2)->unique();
        if ($a->isEmpty() || $b->isEmpty()) return 0;

        return $a->intersect($b)->count() / max($a->count(), $b->count());
    }
}
