<?php

namespace App\Domain\Creative\Services;

use App\Domain\Creative\Models\CreativePromptTemplate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class CreativePromptTemplateService
{
    public const EVENT_FLYER_BACKGROUND = 'event_flyer_background';

    private const LABELS = [
        self::EVENT_FLYER_BACKGROUND => 'Imagem de fundo de evento',
    ];

    private const EVENT_VARIABLES = [
        'subject' => 'Nome/título do evento',
        'description' => 'Descrição do evento sem prefixo',
        'category' => 'Categoria do evento',
        'production_name' => 'Nome da produção/estabelecimento',
        'venue' => 'Local do evento',
        'city' => 'Cidade',
        'uf' => 'UF',
        'artist' => 'Artista, DJ ou atração principal',
        'brand_context' => 'Contexto visual da marca/estabelecimento',
        'style' => 'Direção visual expandida pelo Creative Director',
        'intensity' => 'Intensidade visual expandida pelo Creative Director',
        'format' => 'Composição/formato expandido pelo Creative Director',
        'description_clause' => 'Frase pronta com categoria e conceito do evento',
        'context_clause' => 'Frase pronta com produção, local, cidade e UF',
        'promotions_clause' => 'Resumo de promoções enviado pela aplicação',
        'items_clause' => 'Resumo de itens/experiências enviado pela aplicação',
    ];

    public function __construct(
        private readonly CreativeDirectorService $director,
    ) {}

    public function supports(string $key): bool
    {
        return array_key_exists($key, self::LABELS);
    }

    public function eventStyleKeys(): array
    {
        return $this->director->styleKeys();
    }

    public function eventIntensityKeys(): array
    {
        return $this->director->intensityKeys();
    }

    public function eventFormatKeys(): array
    {
        return $this->director->formatKeys();
    }

    public function eventPresets(): array
    {
        return $this->director->presets();
    }

    public function eventDirection(array $data): array
    {
        return $this->director->resolveEventDirection($data);
    }

    public function definition(string $key): array
    {
        $this->assertSupported($key);
        $record = $this->customRecord($key);
        $default = $this->defaultTemplate($key);

        return [
            'key' => $key,
            'label' => self::LABELS[$key],
            'template' => $record?->template ?: $default,
            'default_template' => $default,
            'is_custom' => (bool) $record,
            'version' => $record?->version ?? 0,
            'updated_at' => $record?->updated_at?->toIso8601String(),
            'updated_by' => $record?->updated_by,
            'variables' => $this->variables($key),
        ];
    }

    public function save(string $key, string $template, int $actorId): CreativePromptTemplate
    {
        $this->assertSupported($key);
        $this->assertStorageAvailable();

        $template = $this->normalizeTemplate($template);
        $this->validateTemplate($key, $template);

        $record = CreativePromptTemplate::query()->firstOrNew(['template_key' => $key]);
        $record->label = self::LABELS[$key];
        $record->template = $template;
        $record->version = $record->exists ? ((int) $record->version + 1) : 1;
        $record->is_active = true;
        $record->updated_by = $actorId;
        $record->save();

        return $record->fresh();
    }

    public function reset(string $key): ?CreativePromptTemplate
    {
        $this->assertSupported($key);
        if (! Schema::hasTable('creative_prompt_templates')) {
            return null;
        }

        $record = CreativePromptTemplate::query()->where('template_key', $key)->first();
        if ($record) {
            $record->delete();
        }

        return $record;
    }

    public function renderEventFlyer(array $data): string
    {
        $definition = $this->definition(self::EVENT_FLYER_BACKGROUND);
        $direction = $this->director->resolveEventDirection($data);

        $descriptionParts = array_filter([
            ! empty($data['category']) ? 'Event category: '.trim((string) $data['category']) : null,
            ! empty($data['description']) ? 'Event concept: '.trim((string) $data['description']) : null,
        ]);

        $context = array_filter([
            $data['production_name'] ?? null,
            $data['venue'] ?? null,
            trim(($data['city'] ?? '').' '.($data['uf'] ?? '')) ?: null,
        ]);

        $promotions = $this->compactList($data['promotions'] ?? []);
        $items = $this->compactList($data['featured_items'] ?? []);

        $variables = [
            'subject' => trim((string) ($data['subject'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'category' => trim((string) ($data['category'] ?? '')),
            'production_name' => trim((string) ($data['production_name'] ?? '')),
            'venue' => trim((string) ($data['venue'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'uf' => strtoupper(trim((string) ($data['uf'] ?? ''))),
            'artist' => trim((string) ($data['artist'] ?? '')),
            'brand_context' => trim((string) ($data['brand_context'] ?? '')),
            'style' => $direction['style'],
            'intensity' => $direction['intensity'],
            'format' => $direction['format'],
            'description_clause' => implode('. ', $descriptionParts),
            'context_clause' => $context ? 'Venue/producer context: '.implode(', ', $context) : '',
            'promotions_clause' => $promotions !== '' ? 'Promotion context: '.$promotions : '',
            'items_clause' => $items !== '' ? 'Available items/experiences: '.$items : '',
        ];

        $replacements = [];
        foreach ($variables as $name => $value) {
            $replacements['{{'.$name.'}}'] = $value;
        }

        $rendered = strtr((string) $definition['template'], $replacements);
        $rendered = preg_replace('/\s+/u', ' ', $rendered) ?: $rendered;

        return $this->director->composeEventPrompt(trim($rendered), $data);
    }

    public function variables(string $key): array
    {
        $this->assertSupported($key);

        return collect(self::EVENT_VARIABLES)
            ->map(fn (string $description, string $name): array => [
                'key' => $name,
                'token' => '{{'.$name.'}}',
                'description' => $description,
            ])
            ->values()
            ->all();
    }

    private function defaultTemplate(string $key): string
    {
        $this->assertSupported($key);

        return <<<'PROMPT'
Real event: "{{subject}}".
{{description_clause}}
{{context_clause}}
{{promotions_clause}}
{{items_clause}}
Use the real event context to choose meaningful visual motifs and atmosphere. Style direction: {{style}}. Intensity: {{intensity}}. Composition: {{format}}.
PROMPT;
    }

    private function customRecord(string $key): ?CreativePromptTemplate
    {
        if (! Schema::hasTable('creative_prompt_templates')) {
            return null;
        }

        return CreativePromptTemplate::query()
            ->where('template_key', $key)
            ->where('is_active', true)
            ->first();
    }

    private function normalizeTemplate(string $template): string
    {
        $template = trim(str_replace(["\r\n", "\r"], "\n", $template));

        return preg_replace_callback(
            '/{{\s*([a-zA-Z0-9_]+)\s*}}/',
            static fn (array $matches): string => '{{'.$matches[1].'}}',
            $template
        ) ?: $template;
    }

    private function validateTemplate(string $key, string $template): void
    {
        if (mb_strlen($template) < 120) {
            throw ValidationException::withMessages([
                'template' => ['O prompt precisa ter pelo menos 120 caracteres para manter contexto e qualidade visual.'],
            ]);
        }

        if (mb_strlen($template) > 1600) {
            throw ValidationException::withMessages([
                'template' => ['O prompt pode ter no máximo 1600 caracteres para preservar espaço para os dados dinâmicos do evento.'],
            ]);
        }

        preg_match_all('/{{([a-zA-Z0-9_]+)}}/', $template, $matches);
        $allowed = array_keys(self::EVENT_VARIABLES);
        $unknown = array_values(array_diff(array_unique($matches[1] ?? []), $allowed));

        if ($unknown) {
            throw ValidationException::withMessages([
                'template' => ['Variáveis não reconhecidas: '.implode(', ', $unknown).'.'],
            ]);
        }

        if (! str_contains($template, '{{subject}}')) {
            throw ValidationException::withMessages([
                'template' => ['O prompt precisa manter a variável {{subject}} para identificar o evento.'],
            ]);
        }
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

    private function assertSupported(string $key): void
    {
        if (! $this->supports($key)) {
            throw new RuntimeException('Template de prompt não suportado.');
        }
    }

    private function assertStorageAvailable(): void
    {
        if (! Schema::hasTable('creative_prompt_templates')) {
            throw new RuntimeException('A estrutura de prompts ainda não foi migrada no banco de dados.');
        }
    }
}
