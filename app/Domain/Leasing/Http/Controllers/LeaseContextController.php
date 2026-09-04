<?php

namespace App\Domain\Leasing\Http\Controllers;

use App\Domain\Leasing\Services\LeaseContextService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class LeaseContextController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly LeaseContextService $contexts,
    ) {}

    public function show(Request $request)
    {
        $contexts = $this->contexts->contexts(
            $this->context->id(),
            (int) $request->user()->id,
            (string) ($request->user()->email ?? ''),
        );

        return response()->json([
            'contexts' => $contexts,
            'default_context' => $this->contexts->defaultRole($contexts),
            'multiple_contexts' => count($contexts) > 1,
        ]);
    }

    public function dashboard(Request $request)
    {
        return response()->json($this->contexts->dashboard(
            $this->context->id(),
            (int) $request->user()->id,
            (string) ($request->user()->email ?? ''),
            $this->requestedRole($request),
        ));
    }

    private function requestedRole(Request $request): string
    {
        $role = strtolower(trim((string) ($request->query('role') ?: $request->header('X-Peter-Context-Role', ''))));

        return in_array($role, ['landlord', 'tenant'], true) ? $role : 'landlord';
    }
}
