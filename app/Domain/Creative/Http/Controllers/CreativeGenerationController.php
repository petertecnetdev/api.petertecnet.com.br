<?php

namespace App\Domain\Creative\Http\Controllers;

use App\Domain\Creative\Services\CloudflareImageGenerator;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

final class CreativeGenerationController extends Controller
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CloudflareImageGenerator $generator,
    ) {}

    public function image(Request $request)
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in(['event_flyer_background'])],
            'subject' => 'required|string|min:2|max:180',
            'description' => 'nullable|string|max:700',
            'style' => ['nullable', Rule::in(['neon', 'premium', 'sunset', 'clean'])],
            'production_name' => 'nullable|string|max:180',
            'venue' => 'nullable|string|max:180',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|max:2',
            'format' => ['nullable', Rule::in(['cover', 'post', 'story'])],
        ]);

        $prompt = $this->buildEventFlyerBackgroundPrompt($data);

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
            'image' => [
                'data_uri' => 'data:'.$result['mime_type'].';base64,'.$result['image'],
                'mime_type' => $result['mime_type'],
                'provider' => $result['provider'],
                'model' => $result['model'],
            ],
            'usage' => [
                'plan' => 'free_guarded',
                'text_rendering' => 'client_canonical_overlay',
            ],
        ]);
    }

    private function buildEventFlyerBackgroundPrompt(array $data): string
    {
        $style = match ($data['style'] ?? 'neon') {
            'premium' => 'luxury nightlife, refined cinematic lighting, elegant dark atmosphere, premium gold highlights',
            'sunset' => 'energetic nightlife, warm magenta and orange lights, vibrant festival atmosphere, high energy',
            'clean' => 'modern minimal event branding, sophisticated dark blue atmosphere, clean geometric lighting, editorial look',
            default => 'futuristic nightlife, electric neon magenta and cyan lighting, immersive club atmosphere, cinematic depth',
        };

        $format = match ($data['format'] ?? 'cover') {
            'story' => 'vertical composition with strong depth and clean negative space in the center and lower third',
            'post' => 'portrait social media composition with strong focal depth and generous clean space for typography',
            default => 'wide cinematic composition with generous clean negative space for typography',
        };

        $context = array_filter([
            $data['production_name'] ?? null,
            $data['venue'] ?? null,
            trim(($data['city'] ?? '').' '.($data['uf'] ?? '')) ?: null,
        ]);

        $description = trim((string) ($data['description'] ?? ''));

        return implode('. ', array_filter([
            'Create a professional promotional background artwork for a real event called "'.$data['subject'].'"',
            $description !== '' ? 'Event concept: '.$description : null,
            $context ? 'Venue/producer context: '.implode(', ', $context) : null,
            'Visual direction: '.$style,
            'Composition: '.$format,
            'High-end commercial event advertising aesthetic, realistic lighting, visually striking, polished, no borders',
            'IMPORTANT: background artwork only. Do not render any words, letters, dates, prices, logos, watermarks, signs or readable text. Leave safe areas for the application to add exact event information afterward',
        ]));
    }
}
