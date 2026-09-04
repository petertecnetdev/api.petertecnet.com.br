<?php

namespace App\Domain\Leasing\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class LeaseLifecycleService
{
    public function evaluate(object|array $lease, CarbonImmutable|DateTimeInterface|string|null $referenceDate = null): array
    {
        $today = $referenceDate instanceof CarbonImmutable
            ? $referenceDate->startOfDay()
            : ($referenceDate instanceof DateTimeInterface
                ? CarbonImmutable::instance($referenceDate)->startOfDay()
                : ($referenceDate ? CarbonImmutable::parse($referenceDate)->startOfDay() : CarbonImmutable::today(config('app.timezone'))));

        $status = (string) ($this->value($lease, 'status') ?? 'draft');
        $startsOn = $this->date($this->value($lease, 'starts_on'));
        $endsOn = $this->date($this->value($lease, 'ends_on'));
        $metadata = $this->metadata($this->value($lease, 'metadata'));
        $wasExplicitlyTerminated = $status === 'ended' && ! empty($metadata['termination']['effective_on']);

        $isInForce = $status === 'active'
            && ($startsOn === null || $startsOn->lte($today))
            && ($endsOn === null || $endsOn->gte($today));

        $vigencyStatus = match (true) {
            $status === 'cancelled' => 'cancelled',
            $wasExplicitlyTerminated => 'terminated_early',
            $status === 'ended' => 'ended',
            $endsOn !== null && $endsOn->lt($today) => 'expired',
            $startsOn !== null && $startsOn->gt($today) => 'future',
            $isInForce => 'in_force',
            $status === 'awaiting_signature' => 'awaiting_signature',
            $status === 'awaiting_documents' => 'awaiting_documents',
            $status === 'draft' => 'draft',
            default => 'not_in_force',
        };

        $daysUntilStart = $startsOn && $startsOn->gt($today) ? (int) $today->diffInDays($startsOn) : null;
        $daysUntilEnd = $endsOn && $endsOn->gte($today) ? (int) $today->diffInDays($endsOn) : null;
        $daysSinceEnd = $endsOn && $endsOn->lt($today) ? (int) $endsOn->diffInDays($today) : null;
        $renewalDue = $isInForce && $daysUntilEnd !== null && $daysUntilEnd <= 90;

        $attentionLevel = match (true) {
            $status === 'active' && $vigencyStatus === 'expired' => 'critical',
            $renewalDue && $daysUntilEnd <= 7 => 'critical',
            $renewalDue && $daysUntilEnd <= 30 => 'high',
            $renewalDue => 'medium',
            $vigencyStatus === 'awaiting_signature' => 'medium',
            default => 'none',
        };

        return [
            'is_in_force' => $isInForce,
            'vigency_status' => $vigencyStatus,
            'vigency_label' => $this->label($vigencyStatus),
            'days_until_start' => $daysUntilStart,
            'days_until_end' => $daysUntilEnd,
            'days_since_end' => $daysSinceEnd,
            'renewal_due' => $renewalDue,
            'attention_level' => $attentionLevel,
            'requires_attention' => $attentionLevel !== 'none',
        ];
    }

    public function periodsOverlap(string|DateTimeInterface $startA, string|DateTimeInterface $endA, string|DateTimeInterface $startB, string|DateTimeInterface $endB): bool
    {
        $aStart = $this->date($startA);
        $aEnd = $this->date($endA);
        $bStart = $this->date($startB);
        $bEnd = $this->date($endB);

        return $aStart !== null && $aEnd !== null && $bStart !== null && $bEnd !== null
            && $aStart->lte($bEnd)
            && $aEnd->gte($bStart);
    }

    public function effectivePropertyState(object|array $property, iterable $leases): array
    {
        $lifecycles = [];
        foreach ($leases as $lease) {
            $lifecycles[] = array_merge((array) $lease, $this->evaluate($lease));
        }

        $inForce = $this->firstByStatus($lifecycles, 'in_force');
        $awaitingSignature = $this->firstByStatus($lifecycles, 'awaiting_signature');
        $future = $this->firstByStatus($lifecycles, 'future');
        $expiredActive = collect($lifecycles)->first(fn (array $lease) => ($lease['status'] ?? null) === 'active' && ($lease['vigency_status'] ?? null) === 'expired');
        $storedStatus = (string) ($this->value($property, 'status') ?? 'available');

        if ($inForce) {
            $effectiveStatus = 'occupied';
            $leaseState = 'in_force';
            $headline = 'Contrato vigente até '.($inForce['ends_on'] ?? 'prazo indeterminado');
        } elseif ($expiredActive) {
            $effectiveStatus = $storedStatus === 'inactive' ? 'inactive' : 'available';
            $leaseState = 'expired_needs_closure';
            $headline = 'Contrato expirado aguardando encerramento';
        } elseif ($awaitingSignature) {
            $effectiveStatus = $storedStatus;
            $leaseState = 'awaiting_signature';
            $headline = 'Contrato aguardando assinatura';
        } elseif ($future) {
            $effectiveStatus = $storedStatus;
            $leaseState = 'future';
            $headline = 'Contrato futuro a partir de '.($future['starts_on'] ?? 'data definida');
        } else {
            $effectiveStatus = in_array($storedStatus, ['maintenance', 'inactive'], true) ? $storedStatus : 'available';
            $leaseState = 'none';
            $headline = $effectiveStatus === 'maintenance' ? 'Em manutenção' : ($effectiveStatus === 'inactive' ? 'Inativo' : 'Sem locação vigente');
        }

        $blocking = collect($lifecycles)->contains(fn (array $lease) => in_array($lease['vigency_status'] ?? null, ['in_force', 'awaiting_signature', 'future'], true)
            || (($lease['status'] ?? null) === 'active' && ($lease['vigency_status'] ?? null) === 'expired'));

        return [
            'effective_status' => $effectiveStatus,
            'lease_state' => $leaseState,
            'lease_headline' => $headline,
            'can_archive' => ! $blocking,
            'current_lease_id' => $inForce['id'] ?? $awaitingSignature['id'] ?? $future['id'] ?? $expiredActive['id'] ?? null,
            'lease_history_count' => count($lifecycles),
        ];
    }

    private function label(string $status): string
    {
        return match ($status) {
            'in_force' => 'Contrato vigente',
            'future' => 'Contrato futuro',
            'expired' => 'Contrato expirado',
            'ended' => 'Contrato encerrado',
            'terminated_early' => 'Contrato encerrado antecipadamente',
            'cancelled' => 'Contrato cancelado',
            'awaiting_signature' => 'Aguardando assinatura',
            'awaiting_documents' => 'Aguardando documentos',
            'draft' => 'Rascunho',
            default => 'Contrato não vigente',
        };
    }

    private function firstByStatus(array $leases, string $status): ?array
    {
        foreach ($leases as $lease) {
            if (($lease['vigency_status'] ?? null) === $status) return $lease;
        }

        return null;
    }

    private function value(object|array $source, string $key): mixed
    {
        return is_array($source) ? ($source[$key] ?? null) : ($source->{$key} ?? null);
    }

    private function metadata(mixed $value): array
    {
        if (is_array($value)) return $value;
        if ($value === null || $value === '') return [];
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof CarbonImmutable) return $value->startOfDay();
        if ($value instanceof DateTimeInterface) return CarbonImmutable::instance($value)->startOfDay();

        return CarbonImmutable::parse((string) $value)->startOfDay();
    }
}
