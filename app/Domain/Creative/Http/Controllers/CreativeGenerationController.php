<?php

namespace App\Domain\Creative\Http\Controllers;

use App\Domain\Creative\Services\CloudflareImageGenerator;
use App\Domain\Creative\Services\CreativePromptTemplateService;
use App\Http\Controllers\Controller;
use App\Support\ApplicationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

final class CreativeGenerationController extends Controller
{
    private const MARKETING_PURPOSES = [
        'blog_cover',
        'application_promo',
        'banner',
        'open_graph',
        'commercial_campaign',
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CloudflareImageGenerator $generator,
        private readonly CreativePromptTemplateService $templates,
    ) {}

    public function presets()
    {
        $this->context->requireCapability('events');

        return response()->json([
            'purpose' => CreativePromptTemplateService::EVENT_FLYER_BACKGROUND,
            ...$this->templates->eventPresets(),
        ]);
    }

    public function image(Request $request)
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in(array_merge([CreativePromptTemplateService::EVENT_FLYER_BACKGROUND], self::MARKETING_PURPOSES))],
            'subject' => 'required|string|min:2|max:180',
            'description' => 'nullable|string|max:1200',
            'category' => 'nullable|string|max:180',
            'style' => ['nullable', Rule::in(array_values(array_unique(array_merge(
                $this->templates->eventStyleKeys(),
                ['editorial', 'technology']
            ))))],
            'intensity' => ['nullable', Rule::in($this->templates->eventIntensityKeys())],
            'production_name' => 'nullable|string|max:180',
            'venue' => 'nullable|string|max:180',
            'city' => 'nullable|string|max:120',
            'uf' => 'nullable|string|max:2',
            'format' => ['nullable', Rule::in($this->templates->eventFormatKeys())],
            'artist' => 'nullable|string|max:240',
            'audience' => 'nullable|string|max:240',
            'cta' => 'nullable|string|max:180',
            'brand_context' => 'nullable|string|max:500',
            'promotions' => 'nullable|array|max:8',
            'promotions.*' => 'string|max:140',
            'featured_items' => 'nullable|array|max:8',
            'featured_items.*' => 'string|max:140',
        ]);

        $direction = null;

        if ($data['purpose'] === CreativePromptTemplateService::EVENT_FLYER_BACKGROUND) {
            $this->context->requireCapability('events');
            $direction = $this->templates->eventDirection($data);
            $prompt = $this->templates->renderEventFlyer($data);
        } else {
            abort_unless(
                $this->context->slug() === 'peter-tecnet'
                    && strtolower((string) $request->user()?->email) === 'petertecnet@gmail.com',
                403,
                'A criação de peças comerciais da Peter Tecnet é restrita ao administrador principal.'
            );
            $prompt = $this->buildMarketingPrompt($data);
        }

        try {
            $result = $this->generator->generate(
                $prompt,
                (int) $request->user()->id,
                $this->context->id(),
                $direction ? [
                    'width' => $direction['width'],
                    'height' => $direction['height'],
                    'format' => $direction['format_key'],
                ] : [],
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
                'plan' => 'guarded',
                'purpose' => $data['purpose'],
                'text_rendering' => 'client_canonical_overlay',
                'prompt_version' => $data['purpose'] === CreativePromptTemplateService::EVENT_FLYER_BACKGROUND
                    ? $this->templates->definition(CreativePromptTemplateService::EVENT_FLYER_BACKGROUND)['version']
                    : null,
                'creative_direction' => $direction ? [
                    'style' => $direction['style_key'],
                    'intensity' => $direction['intensity_key'],
                    'format' => $direction['format_key'],
                    'ratio' => $direction['ratio'],
                ] : null,
            ],
        ]);
    }

    private function buildMarketingPrompt(array $data): string
    {
        $purpose = match ($data['purpose']) {
            'blog_cover' => 'editorial hero artwork for a technology/business blog article',
            'application_promo' => 'premium promotional artwork for a software application launch or feature campaign',
            'banner' => 'high-impact commercial website banner artwork',
            'open_graph' => 'clean social sharing / Open Graph hero artwork with a strong central focal point',
            default => 'high-conversion commercial campaign artwork for a technology company',
        };

        $style = match ($data['style'] ?? 'technology') {
            'premium' => 'premium corporate advertising, sophisticated lighting, elegant depth, refined visual hierarchy',
            'sunset' => 'vibrant magenta and orange gradients, energetic modern campaign mood, strong contrast',
            'clean', 'minimal' => 'minimal contemporary branding, spacious composition, subtle geometry, polished corporate aesthetic',
            'editorial' => 'editorial magazine art direction, conceptual visual metaphor, sophisticated photography aesthetic',
            'neon', 'electronic' => 'futuristic neon cyan and magenta lighting, immersive depth, premium technology aesthetic',
            default => 'advanced technology aesthetic, dark cinematic background, luminous interfaces and abstract digital depth',
        };

        $format = match ($data['format'] ?? 'landscape') {
            'story' => 'vertical 9:16 composition',
            'portrait', 'post' => 'portrait 4:5 social media composition',
            'square' => 'square 1:1 composition',
            'og' => 'wide 1.91:1 Open Graph composition',
            'cover' => 'wide 16:9 hero composition',
            default => 'wide landscape composition',
        };

        return implode('. ', array_filter([
            'Create '.$purpose.' for Peter Tecnet',
            'Campaign subject: '.$data['subject'],
            ! empty($data['description']) ? 'Concept and message: '.trim((string) $data['description']) : null,
            ! empty($data['audience']) ? 'Target audience: '.trim((string) $data['audience']) : null,
            ! empty($data['cta']) ? 'The visual should support this call to action concept: '.trim((string) $data['cta']) : null,
            ! empty($data['brand_context']) ? 'Brand/product context: '.trim((string) $data['brand_context']) : null,
            'Visual direction: '.$style,
            'Composition: '.$format.', strong focal hierarchy and generous safe areas for typography',
            'Professional commercial advertising quality, polished, modern, believable, visually distinctive, no borders',
            'IMPORTANT: generate artwork only. Do not render words, letters, logos, watermarks, UI labels, dates, prices or readable text. The Peter Tecnet application will add exact copy and branding afterward',
        ]));
    }
}
