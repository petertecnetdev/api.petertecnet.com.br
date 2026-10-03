<?php

namespace App\Domain\Media\Library\Http\Controllers;

use App\Domain\Media\Library\Services\MediaLibraryService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;

final class PublicMediaLibraryController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly MediaLibraryService $library,
    ) {
    }

    public function marketing(Request $request)
    {
        $filters = $request->validate([
            'kind' => 'nullable|string|in:image,video,document',
            'category' => 'nullable|string|max:80',
            'purpose' => 'nullable|string|max:80',
            'is_official' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:60',
        ]);

        return response()->json([
            'assets' => $this->library->marketing($this->context->id(), $filters),
        ]);
    }
}
