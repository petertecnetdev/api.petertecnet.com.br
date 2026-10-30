<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domain\Catalog\Services\CatalogEngagementService;
use App\Support\ApplicationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class CatalogEngagementController extends Controller
{
    private const TYPES = [
        'whatsapp_click',
        'contact_click',
        'quote_start',
        'quote_submit',
        'quote_abandon',
        'share',
        'cta_click',
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CatalogEngagementService $engagement,
    )
    {
    }

    public function store(Request $request, string $identifier): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'label' => ['nullable', 'string', 'max:200'],
            'metadata' => ['nullable', 'array', 'max:30'],
        ]);

        $this->engagement->record($this->context->id(), $identifier, $data, Auth::user());

        return response()->json(['success' => true], 202);
    }
}
