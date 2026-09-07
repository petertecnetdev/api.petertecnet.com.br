<?php

namespace App\Domain\Creative\Http\Controllers;

use App\Domain\Creative\Services\CloudflareTextGenerator;
use App\Domain\Creative\Services\CreativePromptTemplateService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

final class CreativeTextGenerationController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CloudflareTextGenerator $generator,
        private readonly CreativePromptTemplateService $templates,
    ) {}

    public function text(Request $request)
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in([CreativePromptTemplateService::EVENT_DESCRIPTION])],
            'subject' => 'required|string|min:2|max:180',
            'current_description' => 'nullable|string|max:3000',
            'category' => 'nullable|string|max:180',
            'production_name' => 'nullable|string|max:180',
            'venue' => 'nullable|string|max:180',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|max:2',
            'start_date' => 'nullable|string|max:80',
            'end_date' => 'nullable|string|max:80',
            'audience' => 'nullable|string|max:240',
            'tone' => ['nullable', Rule::in(['engaging', 'premium', 'casual', 'family', 'corporate'])],
        ]);

        $this->context->requireCapability('events');
        $prompt = $this->templates->renderEventDescription($data);

        try {
            $result = $this->generator->generate(
                $prompt,
                (int) $request->user()->id,
                $this->context->id(),
            );
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
                'fallback_available' => true,
            ], 503);
        }

        return response()->json([
            'text' => $result['text'],
            'provider' => $result['provider'],
            'model' => $result['model'],
            'usage' => [
                'purpose' => $data['purpose'],
                'prompt_version' => $this->templates->definition(CreativePromptTemplateService::EVENT_DESCRIPTION)['version'],
            ],
        ]);
    }
}
