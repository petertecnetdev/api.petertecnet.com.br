<?php

namespace App\Domain\People\Http\Controllers;

use App\Domain\People\Services\UserActivityProfileService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class UserActivityProfileController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly UserActivityProfileService $service,
    ) {}

    public function overview(Request $request)
    {
        return response()->json($this->service->overview($request->user(), $this->context->id()));
    }
}
