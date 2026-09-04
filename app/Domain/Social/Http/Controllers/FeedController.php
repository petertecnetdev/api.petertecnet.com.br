<?php

namespace App\Domain\Social\Http\Controllers;

use App\Domain\Social\Services\FeedService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class FeedController extends Controller
{
    public function __construct(private readonly FeedService $service) {}

    public function index(Request $request)
    {
        return $this->service->index($request);
    }
}
