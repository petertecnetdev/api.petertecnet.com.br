<?php

namespace App\Domain\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Interaction;
use App\Models\Item;
use App\Support\ApplicationContext;
use Illuminate\Database\Eloquent\Builder;
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

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function store(Request $request, string $identifier): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'label' => ['nullable', 'string', 'max:200'],
            'metadata' => ['nullable', 'array', 'max:30'],
        ]);

        $item = Item::query()
            ->where('entity_name', 'establishment')
            ->where('status', true)
            ->whereNull('archived_at')
            ->whereHas('establishment', fn (Builder $builder) => $builder->forApplication($this->context->id()))
            ->when(
                is_numeric($identifier),
                fn (Builder $builder) => $builder->whereKey((int) $identifier),
                fn (Builder $builder) => $builder->where('slug', $identifier)
            )
            ->firstOrFail();

        $metadata = collect($data['metadata'] ?? [])
            ->take(30)
            ->map(function ($value) {
                if (is_scalar($value) || $value === null) {
                    return is_string($value) ? mb_substr($value, 0, 1000) : $value;
                }

                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            })
            ->all();

        Interaction::register(
            $data['type'],
            $item,
            Auth::user(),
            array_merge($metadata, [
                'catalog_item_slug' => $item->slug,
                'catalog_establishment_id' => $item->entity_id,
            ]),
            $data['label'] ?? $item->name
        );

        return response()->json(['success' => true], 202);
    }
}
