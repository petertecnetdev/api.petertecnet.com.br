<?php

namespace App\Domain\Creative\Services;

final class CreativeDirectorService
{
    private const STYLES = [
        'automatic' => [
            'label' => 'Automático',
            'prompt' => 'infer the strongest commercially appropriate visual language from the event category, description, venue and audience',
        ],
        'neon' => [
            'label' => 'Balada / Neon',
            'prompt' => 'premium nightlife advertising, electric cyan and hot-magenta neon, volumetric haze, laser beams, glossy reflections, energetic crowd silhouettes, cinematic club depth',
        ],
        'premium' => [
            'label' => 'Premium / Luxo',
            'prompt' => 'luxury event advertising, sophisticated dark atmosphere, refined cinematic lighting, elegant highlights, premium materials, controlled contrast and exclusive mood',
        ],
        'festival' => [
            'label' => 'Festival',
            'prompt' => 'large-scale festival energy, dramatic stage lighting, atmospheric crowd depth, beams, haze, vibrant color accents and a powerful headline-safe central composition',
        ],
        'sertanejo' => [
            'label' => 'Sertanejo',
            'prompt' => 'contemporary Brazilian sertanejo event campaign, warm cinematic stage lighting, premium rustic details, concert atmosphere, crowd energy and polished nightlife photography',
        ],
        'pagode' => [
            'label' => 'Pagode',
            'prompt' => 'Brazilian pagode celebration, warm social atmosphere, live-music energy, elegant bar ambience, rhythmic visual movement, inviting crowd scene and premium commercial finish',
        ],
        'funk' => [
            'label' => 'Funk',
            'prompt' => 'bold urban Brazilian nightlife campaign, high-energy lighting, dramatic silhouettes, street-luxury visual language, saturated highlights, strong rhythm and modern club atmosphere',
        ],
        'electronic' => [
            'label' => 'Eletrônico',
            'prompt' => 'high-end electronic music campaign, futuristic stage geometry, laser beams, volumetric smoke, electric blue and magenta light, immersive DJ-club atmosphere and cinematic depth',
        ],
        'pub' => [
            'label' => 'Bar / Pub',
            'prompt' => 'premium pub nightlife campaign combining food, music and drinks, stylish bar ambience, warm practical lights mixed with vibrant nightlife accents, social energy and appetizing hospitality cues',
        ],
        'minimal' => [
            'label' => 'Minimalista',
            'prompt' => 'minimal contemporary event art direction, strong negative space, sophisticated geometry, restrained lighting, editorial composition and premium modern branding aesthetic',
        ],
        'urban' => [
            'label' => 'Urbano',
            'prompt' => 'modern urban nightlife campaign, architectural city textures, dramatic contrast, contemporary fashion energy, cinematic street lighting and polished commercial photography',
        ],
        'open_bar' => [
            'label' => 'Open Bar',
            'prompt' => 'high-energy open-bar party advertising, premium bottles and cocktail atmosphere used as secondary visual cues, vibrant club lighting, celebratory crowd depth and polished nightlife finish',
        ],
        'sunset' => [
            'label' => 'Sunset',
            'prompt' => 'premium sunset party atmosphere, warm magenta, orange and golden-hour light, open-air energy, cinematic silhouettes, elegant social mood and vibrant festival polish',
        ],
        'clean' => [
            'label' => 'Clean',
            'prompt' => 'clean modern event advertising, dark refined atmosphere, subtle geometric lighting, uncluttered visual hierarchy, polished editorial photography and generous negative space',
        ],
    ];

    private const INTENSITIES = [
        'clean' => [
            'label' => 'Clean',
            'prompt' => 'restrained composition, fewer decorative elements, generous breathing room, premium simplicity',
        ],
        'balanced' => [
            'label' => 'Equilibrado',
            'prompt' => 'balanced commercial composition, strong focal hierarchy, rich atmosphere without visual clutter',
        ],
        'impactful' => [
            'label' => 'Impactante',
            'prompt' => 'maximum visual impact, dramatic depth, bold lighting, energetic foreground and background layers, campaign-level presence while preserving readable safe areas',
        ],
    ];

    private const FORMATS = [
        'cover' => [
            'label' => 'Capa do evento',
            'ratio' => '16:9',
            'width' => 1600,
            'height' => 900,
            'prompt' => 'wide 16:9 event-page hero composition; keep the main visual interest in the central 70% and preserve crop-safe margins for responsive desktop and mobile presentation',
        ],
        'post' => [
            'label' => 'Post / feed',
            'ratio' => '4:5',
            'width' => 1080,
            'height' => 1350,
            'prompt' => 'portrait 4:5 social campaign composition with a strong upper/middle focal area and clean typography zones',
        ],
        'story' => [
            'label' => 'Story',
            'ratio' => '9:16',
            'width' => 1080,
            'height' => 1920,
            'prompt' => 'vertical 9:16 story composition with cinematic depth and safe top, center and lower-third areas for interface overlays',
        ],
        'square' => [
            'label' => 'Quadrado',
            'ratio' => '1:1',
            'width' => 1080,
            'height' => 1080,
            'prompt' => 'square 1:1 social composition with a powerful central focal point and generous safe edges',
        ],
        'landscape' => [
            'label' => 'Horizontal',
            'ratio' => '16:9',
            'width' => 1600,
            'height' => 900,
            'prompt' => 'wide 16:9 cinematic advertising composition with strong horizontal depth and safe typography areas',
        ],
        'portrait' => [
            'label' => 'Vertical',
            'ratio' => '4:5',
            'width' => 1080,
            'height' => 1350,
            'prompt' => 'portrait 4:5 promotional composition with controlled depth and clean safe zones for copy',
        ],
        'og' => [
            'label' => 'WhatsApp / Open Graph',
            'ratio' => '1.91:1',
            'width' => 1200,
            'height' => 630,
            'prompt' => 'wide 1.91:1 social-sharing preview composition; keep essential visual information centered and crop-safe',
        ],
    ];

    public function styleKeys(): array
    {
        return array_keys(self::STYLES);
    }

    public function intensityKeys(): array
    {
        return array_keys(self::INTENSITIES);
    }

    public function formatKeys(): array
    {
        return array_keys(self::FORMATS);
    }

    public function presets(): array
    {
        return [
            'styles' => $this->publicDefinitions(self::STYLES),
            'intensities' => $this->publicDefinitions(self::INTENSITIES),
            'formats' => collect(self::FORMATS)->map(fn (array $definition, string $key): array => [
                'key' => $key,
                'label' => $definition['label'],
                'ratio' => $definition['ratio'],
                'width' => $definition['width'],
                'height' => $definition['height'],
            ])->values()->all(),
        ];
    }

    public function resolveEventDirection(array $data): array
    {
        $requestedStyle = (string) ($data['style'] ?? 'automatic');
        $styleKey = $requestedStyle === 'automatic'
            ? $this->inferEventStyle($data)
            : (array_key_exists($requestedStyle, self::STYLES) ? $requestedStyle : 'neon');

        $intensityKey = (string) ($data['intensity'] ?? 'balanced');
        if (! array_key_exists($intensityKey, self::INTENSITIES)) {
            $intensityKey = 'balanced';
        }

        $formatKey = (string) ($data['format'] ?? 'cover');
        if (! array_key_exists($formatKey, self::FORMATS)) {
            $formatKey = 'cover';
        }

        return [
            'style_key' => $styleKey,
            'style' => self::STYLES[$styleKey]['prompt'],
            'intensity_key' => $intensityKey,
            'intensity' => self::INTENSITIES[$intensityKey]['prompt'],
            'format_key' => $formatKey,
            'format' => self::FORMATS[$formatKey]['prompt'],
            'width' => self::FORMATS[$formatKey]['width'],
            'height' => self::FORMATS[$formatKey]['height'],
            'ratio' => self::FORMATS[$formatKey]['ratio'],
        ];
    }

    public function composeEventPrompt(string $editableBrief, array $data): string
    {
        $direction = $this->resolveEventDirection($data);
        $brief = trim((string) preg_replace('/\s+/u', ' ', $editableBrief));
        $brief = mb_substr($brief, 0, 690);

        $artist = trim((string) ($data['artist'] ?? ''));
        $brandContext = trim((string) ($data['brand_context'] ?? ''));
        $promotions = $this->compactList($data['promotions'] ?? []);
        $items = $this->compactList($data['featured_items'] ?? []);

        $context = array_filter([
            $artist !== '' ? 'Featured attraction context: '.$artist : null,
            $brandContext !== '' ? 'Brand identity context: '.mb_substr($brandContext, 0, 240) : null,
            $promotions !== '' ? 'Promotion context to inspire hierarchy only: '.$promotions : null,
            $items !== '' ? 'Secondary visual cues that may appear naturally: '.$items : null,
        ]);

        $prompt = implode("\n", array_filter([
            'Act as a senior advertising art director for a real-world event campaign. Create one cohesive, premium promotional BACKGROUND image, not a generic AI illustration and not a collage.',
            'EVENT BRIEF: '.$brief,
            $context ? implode(' | ', $context) : null,
            'ART DIRECTION: '.$direction['style'].'. '.$direction['intensity'].'.',
            'COMPOSITION: '.$direction['format'].'. Build foreground, midground and background depth; use believable lighting, atmospheric perspective, polished color grading and a clear hero focal hierarchy.',
            'QUALITY BAR: professional nightlife/festival advertising, cinematic photography-level finish, refined details, purposeful visual storytelling, high contrast where useful, no muddy empty image, no random decorative clutter.',
            'LAYOUT SAFETY: leave intentional negative space where the application can overlay the event title, date, location, prices and calls to action. Do not place faces or critical objects underneath those safe zones.',
            'STRICT: artwork only. Do NOT render any readable words, letters, numbers, dates, prices, logos, watermarks, signs, QR codes, fake sponsors or invented brands. The application renders all canonical text afterward.',
        ]));

        return mb_substr($prompt, 0, 2048);
    }

    private function inferEventStyle(array $data): string
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $data['subject'] ?? null,
            $data['description'] ?? null,
            $data['category'] ?? null,
            $data['production_name'] ?? null,
            $data['venue'] ?? null,
        ])));

        $rules = [
            'electronic' => ['eletr', 'techno', 'house', 'edm', 'dj ', ' rave', 'trance'],
            'sertanejo' => ['sertanejo', 'modão', 'modao', 'country'],
            'pagode' => ['pagode', 'samba'],
            'funk' => ['funk', 'baile'],
            'sunset' => ['sunset', 'pôr do sol', 'por do sol', 'fim de tarde'],
            'open_bar' => ['open bar', 'openbar'],
            'festival' => ['festival', 'fest ', 'show', 'concert'],
            'pub' => ['pub', 'bar', 'food', 'drink', 'gastronom', 'cerveja', 'vodka', 'narguil'],
            'premium' => ['premium', 'vip', 'luxo', 'exclusive', 'exclusiv'],
            'urban' => ['urbano', 'urban', 'hip hop', 'trap'],
        ];

        foreach ($rules as $style => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $style;
                }
            }
        }

        return 'neon';
    }

    private function compactList(mixed $items): string
    {
        if (! is_array($items)) {
            return '';
        }

        return collect($items)
            ->filter(fn ($item) => is_scalar($item) && trim((string) $item) !== '')
            ->map(fn ($item) => mb_substr(trim((string) $item), 0, 90))
            ->take(6)
            ->implode(', ');
    }

    private function publicDefinitions(array $definitions): array
    {
        return collect($definitions)
            ->map(fn (array $definition, string $key): array => [
                'key' => $key,
                'label' => $definition['label'],
            ])
            ->values()
            ->all();
    }
}
