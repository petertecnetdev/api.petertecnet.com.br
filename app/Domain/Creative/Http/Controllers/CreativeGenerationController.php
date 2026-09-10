<?php

namespace App\Domain\Creative\Http\Controllers;

use App\Domain\Creative\Services\CloudflareImageGenerator;
use App\Domain\Creative\Services\CreativeBriefBuilder;
use App\Domain\Creative\Services\CreativeCandidatePlanner;
use App\Domain\Creative\Services\CreativeProfileResolver;
use App\Domain\Creative\Services\CreativePromptTemplateService;
use App\Domain\Creative\Services\CreativeQualityEvaluator;
use App\Domain\Creative\Services\CreativeRegenerationService;
use App\Domain\Creative\Services\CreativeSafeZonePlanner;
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
        private readonly CreativeBriefBuilder $briefs,
        private readonly CreativeSafeZonePlanner $safeZones,
        private readonly CreativeCandidatePlanner $candidates,
        private readonly CreativeQualityEvaluator $quality,
        private readonly CreativeProfileResolver $profiles,
        private readonly CreativeRegenerationService $regeneration,
    ) {}

    public function presets()
    {
        $this->context->requireCapability('events');

        return response()->json([
            'purpose' => CreativePromptTemplateService::EVENT_FLYER_BACKGROUND,
            ...$this->templates->eventPresets(),
            'candidate_variations' => $this->candidates->variationKeys(),
            'regeneration_modes' => $this->regeneration->keys(),
            'generation_modes' => ['preview', 'final'],
            'max_reference_images' => (int) config('creative.event_flyer.max_reference_images', 4),
        ]);
    }

    public function image(Request $request)
    {
        $maxReferences = (int) config('creative.event_flyer.max_reference_images', 4);
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
            'brand_colors' => 'nullable|array|max:5',
            'brand_colors.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'reference_notes' => 'nullable|string|max:500',
            'reference_images' => 'nullable|array|max:'.$maxReferences,
            'reference_images.*' => 'string|max:2000000',
            'creative_memory' => 'nullable|array|max:8',
            'creative_memory.*' => 'string|max:120',
            'promotions' => 'nullable|array|max:8',
            'promotions.*' => 'string|max:140',
            'featured_items' => 'nullable|array|max:8',
            'featured_items.*' => 'string|max:140',
            'generation_mode' => ['nullable', Rule::in(['preview', 'final'])],
            'candidate_count' => 'nullable|integer|min:1|max:4',
            'candidate_variation' => ['nullable', Rule::in($this->candidates->variationKeys())],
            'regeneration_mode' => ['nullable', Rule::in($this->regeneration->keys())],
            'include_candidates' => 'nullable|boolean',
        ]);

        if ($data['purpose'] !== CreativePromptTemplateService::EVENT_FLYER_BACKGROUND) {
            return $this->generateMarketingImage($request, $data);
        }

        $this->context->requireCapability('events');

        $direction = $this->templates->eventDirection($data);
        $zones = $this->safeZones->forFormat($direction['format_key']);
        $brief = $this->briefs->build($data, $direction, $zones);
        $profile = $this->profiles->resolve($data);
        $generationMode = (string) ($data['generation_mode'] ?? 'preview');

        $prompt = implode("\n", array_filter([
            $this->templates->renderEventFlyer($data),
            $this->safeZones->prompt($zones),
            $this->briefs->toPromptContext($brief),
            $this->profiles->prompt($profile),
            ! empty($data['reference_images'])
                ? 'REFERENCE POLICY: use the supplied reference images only for style, venue, product or subject continuity as requested; never copy readable text, logos or typography from them.'
                : null,
        ]));
        $prompt = $this->regeneration->apply($prompt, $data['regeneration_mode'] ?? null);

        [$width, $height] = $generationMode === 'final'
            ? [$direction['width'], $direction['height']]
            : $this->previewDimensions($direction['width'], $direction['height']);

        $generationOptions = [
            'width' => $width,
            'height' => $height,
            'format' => $direction['format_key'],
            'model' => $generationMode === 'final'
                ? config('creative.cloudflare.event_quality_model')
                : config('creative.cloudflare.event_preview_model'),
            'steps' => $generationMode === 'final'
                ? config('creative.cloudflare.event_quality_steps')
                : config('creative.cloudflare.event_preview_steps', 4),
            'reference_images' => $data['reference_images'] ?? [],
        ];

        $candidateCount = $generationMode === 'final'
            ? 1
            : (int) ($data['candidate_count'] ?? config('creative.event_flyer.candidate_count', 3));
        $planned = $this->candidates->prompts(
            $prompt,
            $candidateCount,
            $data['candidate_variation'] ?? null,
        );

        $generated = [];
        try {
            foreach ($planned as $index => $candidate) {
                $candidatePrompt = mb_substr((string) $candidate['prompt'], 0, 2600);
                $result = $this->generator->generate(
                    $candidatePrompt,
                    (int) $request->user()->id,
                    $this->context->id(),
                    $generationOptions,
                );
                $evaluation = $this->quality->evaluate($result, $brief, $candidatePrompt);

                $generated[] = [
                    'index' => $index,
                    'variation' => $candidate['variation'],
                    'result' => $result,
                    'evaluation' => $evaluation,
                ];
            }
        } catch (RuntimeException $exception) {
            report($exception);

            if (! $generated) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'fallback_available' => true,
                ], 503);
            }
        }

        usort($generated, static function (array $left, array $right): int {
            $score = ($right['evaluation']['score'] ?? 0) <=> ($left['evaluation']['score'] ?? 0);
            if ($score !== 0) {
                return $score;
            }

            return ($right['evaluation']['bytes_estimate'] ?? 0) <=> ($left['evaluation']['bytes_estimate'] ?? 0);
        });

        $winner = $generated[0];
        $result = $winner['result'];
        $includeCandidates = $generationMode === 'preview'
            && (bool) ($data['include_candidates'] ?? config('creative.event_flyer.return_candidates', false));

        return response()->json([
            'image' => $this->imagePayload($result),
            'creative' => [
                'brief' => $brief,
                'safe_zones' => $zones,
                'profile' => $profile,
                'generation_mode' => $generationMode,
                'selected_candidate' => [
                    'variation' => $winner['variation'],
                    'quality' => $winner['evaluation'],
                ],
                'candidates' => collect($generated)->map(function (array $candidate) use ($includeCandidates): array {
                    return [
                        'variation' => $candidate['variation'],
                        'quality' => $candidate['evaluation'],
                        'image' => $includeCandidates ? $this->imagePayload($candidate['result']) : null,
                    ];
                })->values()->all(),
            ],
            'usage' => [
                'plan' => 'free_guarded',
                'purpose' => $data['purpose'],
                'generation_mode' => $generationMode,
                'text_rendering' => 'client_canonical_overlay',
                'prompt_version' => $this->templates->definition(CreativePromptTemplateService::EVENT_FLYER_BACKGROUND)['version'],
                'candidate_count' => count($generated),
                'reference_count' => $result['reference_count'] ?? 0,
                'creative_direction' => [
                    'style' => $direction['style_key'],
                    'intensity' => $direction['intensity_key'],
                    'format' => $direction['format_key'],
                    'ratio' => $direction['ratio'],
                ],
                'generation_profile' => [
                    'model' => $result['model'],
                    'steps' => $result['requested_steps'] ?? null,
                    'width' => $result['requested_width'] ?? $width,
                    'height' => $result['requested_height'] ?? $height,
                ],
            ],
        ]);
    }

    private function previewDimensions(int $width, int $height): array
    {
        $limit = (int) config('creative.event_flyer.preview_long_edge', 960);
        $longEdge = max($width, $height);
        if ($longEdge <= $limit) {
            return [$width, $height];
        }

        $scale = $limit / $longEdge;

        return [
            max(256, (int) round($width * $scale)),
            max(256, (int) round($height * $scale)),
        ];
    }

    private function generateMarketingImage(Request $request, array $data)
    {
        abort_unless(
            $this->context->slug() === 'peter-tecnet'
                && strtolower((string) $request->user()?->email) === 'petertecnet@gmail.com',
            403,
            'A criação de peças comerciais da Peter Tecnet é restrita ao administrador principal.'
        );

        try {
            $result = $this->generator->generate(
                $this->buildMarketingPrompt($data),
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
            'image' => $this->imagePayload($result),
            'usage' => [
                'plan' => 'free_guarded',
                'purpose' => $data['purpose'],
                'text_rendering' => 'client_canonical_overlay',
            ],
        ]);
    }

    private function imagePayload(array $result): array
    {
        return [
            'data_uri' => 'data:'.$result['mime_type'].';base64,'.$result['image'],
            'mime_type' => $result['mime_type'],
            'provider' => $result['provider'],
            'model' => $result['model'],
        ];
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
