<?php

namespace App\Domain\Commerce\Contracts;

use App\Domain\Commerce\Data\OrderData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrderRepositoryInterface
{
    public function paginateForActor(int $applicationId, int $actorId, int $perPage = 20): LengthAwarePaginator;

    public function findForActor(int $applicationId, string|int $orderId, int $actorId): ?OrderData;

    public function transition(int $applicationId, string|int $orderId, string $status, int $actorId): OrderData;
}
