<?php

namespace App\Domain\People\Http\Controllers;

use App\Domain\People\Services\PublicUserProfileService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;

final class PublicUserProfileController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PublicUserProfileService $service,
    ) {}

    public function __invoke(int $userId)
    {
        return response()->json($this->service->show($userId, $this->context->id()));
    }
}
