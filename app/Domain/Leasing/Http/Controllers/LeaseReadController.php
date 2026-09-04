<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseReadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class LeaseReadController extends Controller
{
    public function __construct(
        private readonly LeaseReadService $reads,
    ) {}

    public function properties(Request $request)
    {
        return response()->json(
            $this->reads->properties((int) $request->user()->id),
        );
    }

    public function leases(Request $request)
    {
        $user = $request->user();
        $role = $this->reads->normalizeContextRole(
            $request->header('X-Peter-Context-Role') ?: $request->query('role'),
        );

        return response()->json(
            $this->reads->leases(
                (int) $user->id,
                (string) ($user->email ?? ''),
                $role,
            ),
        );
    }
}
