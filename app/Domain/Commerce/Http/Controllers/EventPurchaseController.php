<?php

namespace App\Domain\Commerce\Http\Controllers;

use App\Domain\Commerce\Services\EventPurchaseOptionsService;
use App\Http\Controllers\Controller;

final class EventPurchaseController extends Controller
{
    public function __construct(private readonly EventPurchaseOptionsService $options) {}

    public function show(string $slug)
    {
        return response()->json($this->options->forSlug($slug));
    }
}
